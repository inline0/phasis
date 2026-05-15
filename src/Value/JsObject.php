<?php

declare(strict_types=1);

namespace Phasis\Value;

use Phasis\Object\PropertyDescriptor;
use Phasis\Object\PropertyMap;

class JsObject implements JsValue
{
    /**
     * Public so the VM dispatch loop can read/write the dataSlots
     * fast-path inline (Op::LOAD_MEMBER, Op::STORE_MEMBER) without
     * paying the method-dispatch tax. Subclasses (JsArray, JsProxy,
     * JsTypedArray) still own their exotic semantics — the inline
     * path only fires when the slot already exists in dataSlots,
     * which subclasses' overrides honour the same way as JsObject.
     */
    public PropertyMap $properties;
    private ?JsObject $prototype;
    private static ?JsObject $globalPrototype = null;
    private bool $extensible = true;
    private bool $immutablePrototype = false;

    /** @var array<int, PropertyDescriptor> Symbol-keyed properties, indexed by JsSymbol id. */
    protected array $symbolProperties = [];

    /** @var array<int, JsSymbol> Tracks JsSymbol instances by id, in insertion order. */
    protected array $symbolOrder = [];

    /**
     * Private fields and methods storage. Keyed by "#name" strings.
     * Each value is a JsValue for fields, or an array [get, set] for private accessors.
     *
     * @var array<string, JsValue|array{0: ?JsFunction, 1: ?JsFunction}>
     */
    private array $privateFields = [];

    /** @var array<string, bool> Track which private names have been defined on this object. */
    private array $privateFieldBrands = [];

    /** @var array<string, bool> Track which private names are methods (read-only, no set). */
    private array $privateMethods = [];

    /** @var array<int, bool> Track which constructors have initialized fields on this object. */
    private array $fieldsInitialized = [];

    /** @var array<string, mixed> Internal properties not visible to JS. */
    private array $internalSlots = [];

    /**
     * Module namespace exotic mode: when set, [[GetOwnProperty]] materializes
     * data descriptors ({value, writable:true, enumerable:true, configurable:false})
     * for each export name by invoking the underlying accessor getter. Per
     * §10.4.6, module namespace exports appear as data properties even though
     * they are backed by live bindings.
     */
    protected bool $isModuleNamespace = false;

    public function markAsModuleNamespace(): void
    {
        $this->isModuleNamespace = true;
    }

    public function isModuleNamespace(): bool
    {
        return $this->isModuleNamespace;
    }

    /** Mark that a constructor has initialized its fields on this object. */
    public function markFieldsInitialized(int $ctorId): void
    {
        $this->fieldsInitialized[$ctorId] = true;
    }

    /** Check if a constructor has already initialized its fields on this object. */
    public function areFieldsInitialized(int $ctorId): bool
    {
        return isset($this->fieldsInitialized[$ctorId]);
    }

    /** Set an internal property (not visible to JS code). */
    public function setInternalProperty(string $name, mixed $value): void
    {
        $this->internalSlots[$name] = $value;
    }

    /** Get an internal property. Returns null if not set. */
    public function getInternalProperty(string $name): mixed
    {
        return $this->internalSlots[$name] ?? null;
    }

    public static function setGlobalPrototype(JsObject $proto): void
    {
        self::$globalPrototype = $proto;
    }

    public static function getGlobalPrototype(): ?JsObject
    {
        return self::$globalPrototype;
    }

    public static function resetGlobalPrototype(): void
    {
        self::$globalPrototype = null;
    }

    /**
     * Create an object with an explicitly null prototype (no Object.prototype).
     * Needed because the constructor's `null ?? $globalPrototype` falls back to
     * the global prototype when null is passed.
     */
    public static function createNullPrototype(): self
    {
        $obj = new self();
        $obj->prototype = null;
        return $obj;
    }

    public function __construct(?JsObject $prototype = null)
    {
        $this->properties = new PropertyMap();
        $this->prototype = $prototype ?? self::$globalPrototype;
    }

    public function isExtensible(): bool
    {
        return $this->extensible;
    }

    public function preventExtensions(): bool
    {
        $this->extensible = false;
        return true;
    }

    public function setImmutablePrototype(bool $immutable = true): void
    {
        $this->immutablePrototype = $immutable;
    }

    public function hasImmutablePrototype(): bool
    {
        return $this->immutablePrototype;
    }

    public function trySetPrototype(?JsObject $prototype): bool
    {
        $current = $this->getPrototype();
        if ($prototype === $current) {
            return true;
        }

        if ($this->immutablePrototype || !$this->isExtensible()) {
            return false;
        }

        // Per spec 9.1.2.1 OrdinarySetPrototypeOf step 8: walk the prototype
        // chain to detect cycles. If a link is a Proxy (whose [[GetPrototypeOf]]
        // is not the ordinary internal method), stop the loop without calling the
        // proxy's getPrototypeOf trap (spec step 8.c.i).
        $candidate = $prototype;
        while ($candidate !== null) {
            if ($candidate === $this) {
                return false;
            }
            if ($candidate instanceof JsProxy) {
                break;
            }
            $candidate = $candidate->getPrototype();
        }

        $this->setPrototype($prototype);
        return true;
    }

    public function get(string $name): JsValue
    {
        // Inlined hot path: own default-attr data slot. Direct hash hit
        // on the public dataSlots array, no method dispatch and no
        // instanceof check. ~80% of property reads in real workloads
        // land here.
        if (isset($this->properties->dataSlots[$name])) {
            return $this->properties->dataSlots[$name];
        }
        return $this->getWithReceiver($name, $this);
    }

    /**
     * ES spec: [[Get]](P, Receiver).
     *
     * Public entry point for Reflect.get and similar APIs that need to
     * specify a custom receiver for getter invocation.
     */
    public function internalGet(string $name, JsObject $receiver): JsValue
    {
        return $this->getWithReceiver($name, $receiver);
    }

    /**
     * Internal property lookup that preserves the original receiver.
     *
     * When a getter accessor is found on a prototype, it must be called
     * with the original receiver (the object property access started on),
     * not the prototype where the descriptor lives.
     */
    protected function getWithReceiver(string $name, JsObject $receiver): JsValue
    {
        return $this->getWithValueReceiver($name, $receiver);
    }

    /**
     * Like getWithReceiver but accepts any JsValue as receiver.
     * Implements the spec GetV(V, P) where V can be a primitive.
     * Getter accessors are invoked with the receiver as their this value.
     */
    public function getWithValueReceiver(string $name, JsValue $receiver): JsValue
    {
        if (isset($this->properties->dataSlots[$name])) {
            return $this->properties->dataSlots[$name];
        }
        $desc = $this->properties->descriptors[$name] ?? null;
        if ($desc !== null) {
            if ($desc->get !== null) {
                return $desc->get->call($receiver, []);
            }
            return $desc->value ?? JsUndefined::instance();
        }

        $proto = $this->getPrototype();
        if ($proto !== null) {
            return $proto->getWithValueReceiver($name, $receiver);
        }

        return JsUndefined::instance();
    }

    public function set(string $name, JsValue $value, bool $strict = false): void
    {
        $success = $this->internalSet($name, $value, $this);
        if (!$success && $strict) {
            // Determine error message based on why it failed.
            $desc = $this->properties->get($name);
            if ($desc !== null) {
                if ($desc->get !== null && $desc->set === null) {
                    throw new \Phasis\Exceptions\TypeError(
                        "Cannot set property {$name} of #<Object> which has only a getter"
                    );
                }
                if ($desc->writable === false) {
                    throw new \Phasis\Exceptions\TypeError(
                        "Cannot assign to read only property '{$name}' of object '#<Object>'"
                    );
                }
            }
            if (!$this->extensible) {
                throw new \Phasis\Exceptions\TypeError(
                    "Cannot add property {$name}, object is not extensible"
                );
            }
            throw new \Phasis\Exceptions\TypeError(
                "Cannot assign to read only property '{$name}' of object '#<Object>'"
            );
        }
    }

    /**
     * ES spec: OrdinarySet ( O, P, V, Receiver ).
     *
     * Performs [[Set]] with a receiver parameter, returning a boolean
     * indicating success or failure. The receiver is the original object
     * that the property assignment was initiated on.
     */
    public function internalSet(string $name, JsValue $value, JsObject $receiver): bool
    {
        // Module namespace exotic [[Set]] always returns false (per §10.4.6.6).
        if ($this->isModuleNamespace && !self::isInternalSlot($name)) {
            return false;
        }
        // Fast path: receiver === this with an own default-attr data
        // slot. Direct array set on dataSlots, no method dispatch and
        // no descriptor allocation. ~95% of property writes — repeated
        // updates to existing fields on the same object.
        if ($receiver === $this) {
            if (isset($this->properties->dataSlots[$name])) {
                $this->properties->dataSlots[$name] = $value;
                $this->properties->version++;
                return true;
            }
            $ownDesc = $this->properties->descriptors[$name] ?? null;
            if ($ownDesc === null) {
                return $this->ordinarySetWithOwnDescriptor($name, $value, $receiver, null);
            }
            // Slow descriptor: writable data property → in-place update.
            // Reject explicit {get: undefined, set: undefined} accessor
            // descriptors via isDataDescriptor().
            if (
                $ownDesc->get === null
                && $ownDesc->set === null
                && $ownDesc->writable !== false
                && $ownDesc->isDataDescriptor()
            ) {
                $ownDesc->value = $value;
                return true;
            }
            return $this->ordinarySetWithOwnDescriptor($name, $value, $receiver, $ownDesc);
        }
        $ownDesc = $this->getOwnPropertyDescriptor($name);
        return $this->ordinarySetWithOwnDescriptor($name, $value, $receiver, $ownDesc);
    }

    /**
     * ES spec: OrdinarySetWithOwnDescriptor ( O, P, V, Receiver, ownDesc ).
     *
     * Implements the core set logic per spec. Returns true on success, false on failure.
     */
    protected function ordinarySetWithOwnDescriptor(
        string $name,
        JsValue $value,
        JsObject $receiver,
        ?PropertyDescriptor $ownDesc,
    ): bool {
        if ($ownDesc === null) {
            // Walk to the parent in the prototype chain.
            $parent = $this->getPrototype();
            if ($parent !== null) {
                return $parent->internalSet($name, $value, $receiver);
            }
            // No own descriptor, no parent: treat as writable data descriptor.
            $ownDesc = PropertyDescriptor::data(JsUndefined::instance());
        }

        if ($ownDesc->isDataDescriptor()) {
            if ($ownDesc->writable === false) {
                return false;
            }
            // Check the receiver for its own property.
            $existingDesc = $receiver->getOwnPropertyDescriptor($name);
            if ($existingDesc !== null) {
                if ($existingDesc->isAccessorDescriptor()) {
                    return false;
                }
                if ($existingDesc->writable === false) {
                    return false;
                }
                // Per spec §10.1.9.2 OrdinarySetWithOwnDescriptor step 3.c-d:
                // let valueDesc be the PropertyDescriptor { [[Value]]: V }
                // and return ? Receiver.[[DefineOwnProperty]](P, valueDesc).
                // The descriptor passed to [[DefineOwnProperty]] must contain
                // ONLY [[Value]]; other fields are absent so that ordinary
                // [[DefineOwnProperty]] merges with the existing flags and
                // Proxy traps observe the minimal {value} shape.
                return $receiver->defineOwnProperty(
                    $name,
                    new PropertyDescriptor(value: $value),
                );
            }
            // Receiver does not have property P. Per spec
            // OrdinarySetWithOwnDescriptor step 2.e: return the result of
            // CreateDataProperty(Receiver, P, V), which is just
            // Receiver.[[DefineOwnProperty]] with default attrs. Do NOT
            // pre-check IsExtensible here: that check belongs inside
            // [[DefineOwnProperty]] (OrdinaryDefineOwnProperty enforces it
            // for ordinary objects), and calling it eagerly fires the
            // proxy's [[IsExtensible]] trap when receiver is a Proxy,
            // which the spec does not.
            return $receiver->defineOwnProperty($name, PropertyDescriptor::data($value));
        }

        // Accessor descriptor.
        if ($ownDesc->set !== null) {
            $ownDesc->set->call($receiver, [$value]);
            return true;
        }
        return false;
    }

    /** Get a property value by symbol key. */
    public function getBySymbol(JsSymbol $symbol): JsValue
    {
        return $this->getBySymbolWithReceiver($symbol, $this);
    }

    /**
     * Symbol property lookup with explicit receiver for getter invocation.
     * Used by super references to pass the correct `this` to getters.
     */
    public function getBySymbolWithReceiver(JsSymbol $symbol, JsValue $receiver): JsValue
    {
        $id = $symbol->getId();
        if (isset($this->symbolProperties[$id])) {
            $desc = $this->symbolProperties[$id];
            if ($desc->get !== null) {
                // Per spec OrdinaryGet, the getter is called with the original
                // receiver (may be a primitive for primitive-base refs).
                return $desc->get->call($receiver, []);
            }
            return $desc->value ?? JsUndefined::instance();
        }

        $proto = $this->getPrototype();
        if ($proto !== null) {
            return $proto->getBySymbolWithReceiver($symbol, $receiver);
        }

        return JsUndefined::instance();
    }

    /** Set a property value by symbol key. */
    public function setBySymbol(JsSymbol $symbol, JsValue $value, bool $strict = false): void
    {
        $id = $symbol->getId();
        if (isset($this->symbolProperties[$id])) {
            $desc = $this->symbolProperties[$id];
            if ($desc->set !== null) {
                $desc->set->call($this, [$value]);
                return;
            }
            if ($desc->isAccessorDescriptor()) {
                // Accessor without setter: fail silently (or throw in strict mode).
                if ($strict) {
                    throw new \Phasis\Exceptions\TypeError(
                        "Cannot set property Symbol() which has only a getter"
                    );
                }
                return;
            }
            if ($desc->writable === false) {
                if ($strict) {
                    throw new \Phasis\Exceptions\TypeError(
                        "Cannot assign to read only property 'Symbol()' of object '#<Object>'"
                    );
                }
                return;
            }
            $desc->value = $value;
            return;
        }

        // Adding a new symbol property: check extensibility.
        if (!$this->extensible) {
            if ($strict) {
                throw new \Phasis\Exceptions\TypeError(
                    "Cannot add property Symbol(), object is not extensible"
                );
            }
            return;
        }

        $this->symbolProperties[$id] = PropertyDescriptor::data($value);
        $this->symbolOrder[$id] = $symbol;
    }

    /**
     * Define a symbol property with a specific property descriptor.
     * Analogous to defineOwnProperty but for symbol-keyed properties.
     */
    public function defineOwnSymbolProperty(JsSymbol $symbol, PropertyDescriptor $desc): bool
    {
        $id = $symbol->getId();
        $this->symbolProperties[$id] = $desc;
        $this->symbolOrder[$id] = $symbol;
        return true;
    }

    /**
     * Symbol [[Set]] with explicit receiver. Used by super references
     * so that setters are invoked with the correct `this`.
     * Returns false if the set failed (non-configurable/non-writable).
     */
    public function internalSetBySymbol(JsSymbol $symbol, JsValue $value, JsObject $receiver): bool
    {
        $id = $symbol->getId();
        if (isset($this->symbolProperties[$id])) {
            $desc = $this->symbolProperties[$id];
            if ($desc->set !== null) {
                $desc->set->call($receiver, [$value]);
                return true;
            }
            if ($desc->isAccessorDescriptor()) {
                return false;
            }
            if ($desc->writable === false) {
                return false;
            }
            // Per OrdinarySet: if the property is found on the receiver itself,
            // update it in place. If found on a prototype, create a new own
            // property on the receiver (do not mutate the prototype descriptor).
            if ($this === $receiver) {
                $desc->value = $value;
                return true;
            }
            // Found writable data on prototype: create own property on receiver.
            if (!$receiver->extensible) {
                return false;
            }
            // Check if receiver already has its own symbol property.
            if (isset($receiver->symbolProperties[$id])) {
                $receiverDesc = $receiver->symbolProperties[$id];
                if ($receiverDesc->isAccessorDescriptor()) {
                    return false;
                }
                if ($receiverDesc->writable === false) {
                    return false;
                }
                $receiverDesc->value = $value;
                return true;
            }
            $receiver->symbolProperties[$id] = PropertyDescriptor::data($value);
            $receiver->symbolOrder[$id] = $symbol;
            return true;
        }
        $proto = $this->getPrototype();
        if ($proto !== null) {
            return $proto->internalSetBySymbol($symbol, $value, $receiver);
        }
        if (!$receiver->extensible) {
            return false;
        }
        $receiver->symbolProperties[$id] = PropertyDescriptor::data($value);
        $receiver->symbolOrder[$id] = $symbol;
        return true;
    }

    /** Check if the object has a symbol-keyed property. */
    public function hasBySymbol(JsSymbol $symbol): bool
    {
        if (isset($this->symbolProperties[$symbol->getId()])) {
            return true;
        }

        $proto = $this->getPrototype();
        if ($proto !== null) {
            return $proto->hasBySymbol($symbol);
        }

        return false;
    }

    /** Get the property descriptor for a symbol key, or null if not found. */
    public function getPropertyDescriptorBySymbol(JsSymbol $symbol): ?PropertyDescriptor
    {
        return $this->symbolProperties[$symbol->getId()] ?? null;
    }

    /** Define a property descriptor by symbol key, merging with existing descriptor. */
    public function definePropertyBySymbol(JsSymbol $symbol, PropertyDescriptor $desc): bool
    {
        $id = $symbol->getId();
        if (!isset($this->symbolOrder[$id])) {
            $this->symbolOrder[$id] = $symbol;
        }

        $current = $this->symbolProperties[$id] ?? null;
        if ($current === null) {
            if (!$this->extensible) {
                return false;
            }
            // New property: defaults for absent fields.
            if ($desc->isAccessorDescriptor()) {
                $this->symbolProperties[$id] = PropertyDescriptor::accessor(
                    get: $desc->get,
                    set: $desc->set,
                    enumerable: $desc->enumerable ?? false,
                    configurable: $desc->configurable ?? false,
                );
            } else {
                $this->symbolProperties[$id] = new PropertyDescriptor(
                    value: $desc->value ?? JsUndefined::instance(),
                    writable: $desc->writable ?? false,
                    enumerable: $desc->enumerable ?? false,
                    configurable: $desc->configurable ?? false,
                );
            }
            return true;
        }

        // Merge with existing descriptor (same logic as defineOwnProperty).
        if ($current->configurable === false) {
            if ($desc->configurable === true) {
                return false;
            }
            if ($desc->enumerable !== null && $desc->enumerable !== $current->enumerable) {
                return false;
            }
        }

        if ($current->isDataDescriptor()) {
            if ($current->configurable === false) {
                if ($current->writable === false) {
                    if ($desc->writable === true) {
                        return false;
                    }
                    if (
                        $desc->value !== null && !\Phasis\Spec\AbstractOperations::sameValue(
                            $desc->value,
                            $current->value ?? JsUndefined::instance(),
                        )
                    ) {
                        return false;
                    }
                }
            }
            // Per spec: if current is data and new is accessor, convert to accessor
            // (only allowed when current is configurable).
            if ($desc->isAccessorDescriptor()) {
                $this->symbolProperties[$id] = PropertyDescriptor::accessor(
                    get: $desc->get,
                    set: $desc->set,
                    enumerable: $desc->enumerable ?? $current->enumerable ?? true,
                    configurable: $desc->configurable ?? $current->configurable ?? true,
                );
                return true;
            }
            $this->symbolProperties[$id] = new PropertyDescriptor(
                value: $desc->value ?? $current->value,
                writable: $desc->writable ?? $current->writable,
                enumerable: $desc->enumerable ?? $current->enumerable,
                configurable: $desc->configurable ?? $current->configurable,
            );
            return true;
        }

        if ($current->isAccessorDescriptor()) {
            if ($current->configurable === false) {
                if ($desc->hasSet && $desc->set !== $current->set) {
                    return false;
                }
                if ($desc->hasGet && $desc->get !== $current->get) {
                    return false;
                }
            }
            // Per spec: if current is accessor and new is data, convert to data.
            if ($desc->isDataDescriptor()) {
                $this->symbolProperties[$id] = new PropertyDescriptor(
                    value: $desc->value ?? JsUndefined::instance(),
                    writable: $desc->writable ?? false,
                    enumerable: $desc->enumerable ?? $current->enumerable ?? true,
                    configurable: $desc->configurable ?? $current->configurable ?? true,
                );
                return true;
            }
            $this->symbolProperties[$id] = PropertyDescriptor::accessor(
                get: $desc->hasGet ? $desc->get : $current->get,
                set: $desc->hasSet ? $desc->set : $current->set,
                enumerable: $desc->enumerable ?? $current->enumerable ?? true,
                configurable: $desc->configurable ?? $current->configurable ?? true,
            );
            return true;
        }

        // Generic merge.
        $this->symbolProperties[$id] = new PropertyDescriptor(
            value: $desc->value ?? $current->value,
            writable: $desc->writable ?? $current->writable,
            enumerable: $desc->enumerable ?? $current->enumerable,
            configurable: $desc->configurable ?? $current->configurable,
            get: $desc->hasGet ? $desc->get : ($desc->get ?? $current->get),
            set: $desc->hasSet ? $desc->set : ($desc->set ?? $current->set),
        );
        return true;
    }

    /** Get own property descriptor by symbol key (does not walk prototype chain). */
    public function getSymbolPropertyDescriptor(JsSymbol $symbol): ?PropertyDescriptor
    {
        return $this->symbolProperties[$symbol->getId()] ?? null;
    }

    // -------------------------------------------------------------------------
    // Private fields and methods
    // -------------------------------------------------------------------------

    /**
     * Add a private field binding on this object (PrivateFieldAdd).
     * Throws TypeError if the object already has the private name, per spec.
     */
    public function setPrivateField(string $name, JsValue $value): void
    {
        if (isset($this->privateFieldBrands[$name])) {
            // Strip the internal brand suffix (@N) from the name for the error.
            $displayName = preg_replace('/@\d+$/', '', $name);
            throw new \Phasis\Exceptions\TypeError(
                "Cannot initialize {$displayName} twice on the same object",
            );
        }
        $this->privateFields[$name] = $value;
        $this->privateFieldBrands[$name] = true;
    }

    /** Get a private field value from this object. */
    public function getPrivateField(string $name): JsValue
    {
        if (!isset($this->privateFieldBrands[$name])) {
            throw new \Phasis\Exceptions\TypeError(
                "Cannot read private member {$name} from an object whose class did not declare it",
            );
        }
        $val = $this->privateFields[$name] ?? null;
        if (is_array($val)) {
            // Accessor: $val = [getter, setter]
            if ($val[0] instanceof JsFunction) {
                $interp = JsFunction::getInterpreterInstance();
                if ($interp !== null) {
                    return $interp->callFunction($val[0], $this, []);
                }
            }
            throw new \Phasis\Exceptions\TypeError(
                "'{$name}' was defined without a getter",
            );
        }
        return $val ?? JsUndefined::instance();
    }

    /**
     * Set a private field value, throwing if it does not exist (write to private field).
     */
    public function setPrivateFieldValue(string $name, JsValue $value): void
    {
        if (!isset($this->privateFieldBrands[$name])) {
            throw new \Phasis\Exceptions\TypeError(
                "Cannot write private member {$name} to an object whose class did not declare it",
            );
        }
        // Per spec PrivateFieldSet step 4: writing to a private method throws TypeError.
        if (isset($this->privateMethods[$name])) {
            // Strip the internal brand suffix (@N) from the name for the error message.
            $displayName = preg_replace('/@\d+$/', '', $name);
            throw new \Phasis\Exceptions\TypeError(
                "Cannot assign to private method {$displayName}",
            );
        }
        $existing = $this->privateFields[$name] ?? null;
        if (is_array($existing)) {
            // Accessor: $existing = [getter, setter]
            if ($existing[1] instanceof JsFunction) {
                $interp = JsFunction::getInterpreterInstance();
                if ($interp !== null) {
                    $interp->callFunction($existing[1], $this, [$value]);
                    return;
                }
            }
            throw new \Phasis\Exceptions\TypeError(
                "'{$name}' was defined without a setter",
            );
        }
        $this->privateFields[$name] = $value;
    }

    /** Check if this object has a specific private field. */
    public function hasPrivateField(string $name): bool
    {
        return isset($this->privateFieldBrands[$name]);
    }

    /**
     * Get the raw private field storage without invoking accessors.
     * Returns the stored value directly: a JsValue for fields/methods,
     * or an array [getter, setter] for accessors.
     *
     * @return JsValue|array{0: ?JsFunction, 1: ?JsFunction}|null
     */
    public function getPrivateFieldRaw(string $name): mixed
    {
        return $this->privateFields[$name] ?? null;
    }

    /**
     * Install a private accessor (getter/setter pair).
     *
     * @param array{0: ?JsFunction, 1: ?JsFunction} $accessor [getter, setter]
     */
    public function setPrivateAccessor(string $name, array $accessor): void
    {
        $this->privateFields[$name] = $accessor;
        $this->privateFieldBrands[$name] = true;
    }

    /**
     * Install a private method (read-only, no setter).
     * Per PrivateMethodOrAccessorAdd, throws TypeError if the brand is
     * already present on the object (e.g. when super-returns an already
     * initialized object).
     */
    public function setPrivateMethod(string $name, JsFunction $method): void
    {
        if (isset($this->privateFieldBrands[$name])) {
            $displayName = preg_replace('/@\d+$/', '', $name);
            throw new \Phasis\Exceptions\TypeError(
                "Cannot initialize {$displayName} twice on the same object",
            );
        }
        $this->privateFields[$name] = $method;
        $this->privateFieldBrands[$name] = true;
        $this->privateMethods[$name] = true;
    }

    /**
     * Treat ::has() as impure: subclasses (JsProxy with the `has` trap,
     * JsArguments parameter map, etc.) can produce different results across
     * calls, and even on plain objects the @@unscopables getter mutating
     * the chain between two calls is observable.
     *
     * @phpstan-impure
     */
    public function has(string $name): bool
    {
        if ($this->properties->has($name)) {
            return true;
        }

        $proto = $this->getPrototype();
        if ($proto !== null) {
            return $proto->has($name);
        }

        return false;
    }

    public function delete(string $name, bool $strict = false): bool
    {
        $desc = $this->properties->get($name);
        if ($desc === null) {
            return true;
        }

        if ($desc->configurable === false) {
            if ($strict) {
                throw new \Phasis\Exceptions\TypeError(
                    "Cannot delete property '{$name}' of #<Object>"
                );
            }
            return false;
        }

        return $this->properties->delete($name);
    }

    /**
     * Remove a property regardless of its configurability.
     * For internal engine use only (e.g., removing .prototype from non-constructable functions).
     */
    public function forceDelete(string $name): void
    {
        $this->properties->delete($name);
    }

    public function defineProperty(string $name, PropertyDescriptor $desc): void
    {
        $this->properties->set($name, $desc);
    }

    /**
     * Treat ::getOwnPropertyDescriptor as impure: subclasses (JsProxy
     * with the `getOwnPropertyDescriptor` trap, JsArguments parameter
     * map, JsTypedArray) and the module-namespace exotic path can
     * return different values across calls. Marking impure keeps
     * static analysis from preserving narrowing across spec-mandated
     * intermediate operations (`compile()` inside ToLength can blank
     * out `[[PCREPattern]]`, etc.).
     *
     * @phpstan-impure
     */
    /**
     * Return the raw stored property descriptor without applying any
     * exotic-namespace accessor materialization. Used by callers that
     * want to clone or re-install the underlying live binding (e.g.
     * the deferred-module-namespace mirror) rather than a one-shot
     * snapshot of its current value.
     */
    public function getOwnPropertyDescriptorRaw(string $name): ?PropertyDescriptor
    {
        return $this->properties->get($name);
    }

    public function getOwnPropertyDescriptor(string $name): ?PropertyDescriptor
    {
        $desc = $this->properties->get($name);
        if ($desc === null) {
            return null;
        }
        // Module namespace exotic [[GetOwnProperty]]: materialize data
        // descriptors for string-keyed exports (per spec §10.4.6.4).
        if (
            $this->isModuleNamespace
            && $desc->isAccessorDescriptor()
            && $desc->get !== null
            && !self::isInternalSlot($name)
        ) {
            // Propagate any exception from GetBindingValue (e.g. TDZ
            // ReferenceError for uninitialized exports) — spec requires it.
            $value = $desc->get->call($this, []);
            return new PropertyDescriptor(
                value: $value,
                writable: true,
                enumerable: true,
                configurable: false,
            );
        }
        return $desc;
    }

    public function hasOwnProperty(string $name): bool
    {
        return $this->properties->has($name);
    }

    /** Check if a property name is an internal slot (e.g. [[NewTarget]], [[PrimitiveValue]]). */
    protected static function isInternalSlot(string $key): bool
    {
        return str_starts_with($key, '[[') && str_ends_with($key, ']]');
    }

    /** @return list<string> */
    public function ownKeys(): array
    {
        return array_values(array_filter(
            $this->properties->keys(),
            fn(string $k) => !self::isInternalSlot($k),
        ));
    }

    /**
     * All own string-keyed property names in spec order (OrdinaryOwnPropertyKeys):
     * 1. Integer indices in ascending numeric order
     * 2. Other string keys in insertion order
     *
     * @return list<string>
     */
    public function getOwnPropertyNames(): array
    {
        $allKeys = $this->properties->keys();

        $integerIndices = [];
        $nonIndexStrings = [];
        foreach ($allKeys as $key) {
            if (self::isInternalSlot($key)) {
                continue;
            }
            if (self::isArrayIndex($key)) {
                $integerIndices[] = $key;
            } else {
                $nonIndexStrings[] = $key;
            }
        }

        usort($integerIndices, fn(string $a, string $b) => (int) $a <=> (int) $b);

        // Per §10.4.6.7, a Module Namespace Object's [[OwnPropertyKeys]]
        // returns its exported names sorted as if by Array.prototype.sort
        // using undefined comparefn (UTF-16 code-unit order), followed by
        // integer indices (none for namespaces) and Symbol-keyed entries.
        if ($this->isModuleNamespace) {
            usort($nonIndexStrings, static function (string $a, string $b): int {
                $ua = \Phasis\Value\JsString::utf8ToUtf16LE($a);
                $ub = \Phasis\Value\JsString::utf8ToUtf16LE($b);
                $len = min(strlen($ua), strlen($ub));
                for ($i = 0; $i < $len; $i += 2) {
                    $ca = ord($ua[$i]) | (ord($ua[$i + 1]) << 8);
                    $cb = ord($ub[$i]) | (ord($ub[$i + 1]) << 8);
                    if ($ca !== $cb) {
                        return $ca - $cb;
                    }
                }
                return strlen($ua) - strlen($ub);
            });
        }

        return array_merge($integerIndices, $nonIndexStrings);
    }

    /** Delete a symbol-keyed own property. Returns true if deleted or absent. */
    public function deleteBySymbol(JsSymbol $symbol): bool
    {
        $id = $symbol->getId();
        if (!isset($this->symbolProperties[$id])) {
            return true;
        }
        $desc = $this->symbolProperties[$id];
        if ($desc->configurable === false) {
            return false;
        }
        unset($this->symbolProperties[$id]);
        unset($this->symbolOrder[$id]);
        return true;
    }

    /**
     * Return own symbol-keyed properties as an array of [JsSymbol, PropertyDescriptor] pairs.
     *
     * @return array<int, PropertyDescriptor>
     */
    public function getOwnSymbolProperties(): array
    {
        return $this->symbolProperties;
    }

    /**
     * Return own symbol-keyed properties as a list of [JsSymbol, PropertyDescriptor] pairs.
     * Useful for CopyDataProperties and object spread.
     *
     * @return list<array{0: JsSymbol, 1: PropertyDescriptor}>
     */
    public function getOwnSymbolsWithDescriptors(): array
    {
        $result = [];
        foreach ($this->symbolOrder as $id => $sym) {
            if (isset($this->symbolProperties[$id])) {
                $result[] = [$sym, $this->symbolProperties[$id]];
            }
        }
        return $result;
    }

    /**
     * [[OwnPropertyKeys]] per spec 9.1.12 (OrdinaryOwnPropertyKeys).
     *
     * Returns keys in the correct order:
     * 1. Integer indices in ascending numeric order
     * 2. String keys in insertion (creation) order
     * 3. Symbol keys in insertion (creation) order
     *
     * @return list<JsValue> Array of JsString and JsSymbol values.
     */
    public function ordinaryOwnPropertyKeys(): array
    {
        $stringKeys = $this->properties->keys();

        $integerIndices = [];
        $nonIndexStrings = [];
        foreach ($stringKeys as $key) {
            if (self::isInternalSlot($key)) {
                continue;
            }
            if (self::isArrayIndex($key)) {
                $integerIndices[] = $key;
            } else {
                $nonIndexStrings[] = new JsString($key);
            }
        }

        // Sort integer indices numerically (ascending).
        usort($integerIndices, fn(string $a, string $b) => (int) $a <=> (int) $b);
        $sortedIndices = array_map(fn(string $k) => new JsString($k), $integerIndices);

        // Per §10.4.6.7, module namespace objects sort their string-keyed
        // exports as if by Array.prototype.sort with undefined comparefn
        // (i.e. lexicographic by UTF-16 code unit).
        if ($this->isModuleNamespace) {
            usort($nonIndexStrings, static function (JsString $a, JsString $b): int {
                $ua = \Phasis\Value\JsString::utf8ToUtf16LE($a->value);
                $ub = \Phasis\Value\JsString::utf8ToUtf16LE($b->value);
                $len = min(strlen($ua), strlen($ub));
                for ($i = 0; $i < $len; $i += 2) {
                    $ca = ord($ua[$i]) | (ord($ua[$i + 1]) << 8);
                    $cb = ord($ub[$i]) | (ord($ub[$i + 1]) << 8);
                    if ($ca !== $cb) {
                        return $ca - $cb;
                    }
                }
                return strlen($ua) - strlen($ub);
            });
        }

        // Symbol keys in insertion order (symbolOrder preserves insertion order).
        $symbolKeys = [];
        foreach ($this->symbolOrder as $id => $sym) {
            if (isset($this->symbolProperties[$id])) {
                $symbolKeys[] = $sym;
            }
        }

        return array_merge($sortedIndices, $nonIndexStrings, $symbolKeys);
    }

    /**
     * Check if a string property key is an array index (integer in 0..2^32-2 range).
     */
    protected static function isArrayIndex(string $key): bool
    {
        if ($key === '' || $key[0] === '-') {
            return false;
        }
        if (!ctype_digit($key)) {
            return false;
        }
        // Avoid leading zeros (except "0" itself).
        if (strlen($key) > 1 && $key[0] === '0') {
            return false;
        }
        // Array index: 0 <= index <= 2^32 - 2 (4294967294).
        if (strlen($key) > 10) {
            return false;
        }
        $val = (int) $key;
        return $val >= 0 && $val <= 4294967294 && (string) $val === $key;
    }

    /**
     * Own enumerable string keys in spec order (OrdinaryOwnPropertyKeys):
     * 1. Integer indices in ascending numeric order
     * 2. Other string keys in insertion order
     *
     * @return list<string>
     */
    public function getOwnEnumerableKeys(): array
    {
        $enumKeys = $this->properties->enumerableKeys();

        $integerIndices = [];
        $nonIndexStrings = [];
        foreach ($enumKeys as $key) {
            if (self::isInternalSlot($key)) {
                continue;
            }
            if (self::isArrayIndex($key)) {
                $integerIndices[] = $key;
            } else {
                $nonIndexStrings[] = $key;
            }
        }

        usort($integerIndices, fn(string $a, string $b) => (int) $a <=> (int) $b);

        return array_merge($integerIndices, $nonIndexStrings);
    }

    /**
     * @return list<string> All enumerable keys including inherited.
     * Used by for-in. Walks prototype chain. Own keys follow spec order:
     * integer indices ascending, then string keys in insertion order.
     */
    public function getEnumerableKeys(): array
    {
        $seen = [];
        $keys = [];
        $obj = $this;
        while ($obj !== null) {
            // Per spec EnumerateObjectProperties: ALL own property keys must be
            // tracked as "seen", not just enumerable ones. A non-enumerable own
            // property shadows an enumerable prototype property with the same name.
            foreach ($obj->getOwnPropertyNames() as $key) {
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    // Only include the key if it is enumerable.
                    $desc = $obj->getOwnPropertyDescriptor($key);
                    if ($desc !== null && ($desc->enumerable ?? false)) {
                        $keys[] = $key;
                    }
                }
            }
            // Use getPrototype() (not the raw field) so subclasses with a
            // lazily-wired prototype (e.g., JsFunction → Function.prototype)
            // walk the correct chain.
            $obj = $obj->getPrototype();
        }
        return $keys;
    }

    /**
     * Update an existing writable data property's value in-place,
     * mutating the stored PropertyDescriptor directly. Returns true
     * on success, false when the property doesn't exist or isn't a
     * plain writable data descriptor (caller should fall back to
     * defineOwnProperty / set as appropriate).
     *
     * Used by the hot Annex B `caller`/`arguments` slot updates on
     * JsFunction: the descriptor is installed once at function
     * creation and only its value flips per call. Skipping the
     * defineOwnProperty merge avoids two PropertyDescriptor
     * allocations (input wrapper + merged result) per write.
     */
    public function tryUpdateDataValue(string $name, JsValue $value): bool
    {
        if (isset($this->properties->dataSlots[$name])) {
            $this->properties->dataSlots[$name] = $value;
            $this->properties->version++;
            return true;
        }
        $desc = $this->properties->descriptors[$name] ?? null;
        if (
            $desc !== null
            && $desc->get === null
            && $desc->set === null
            && $desc->writable !== false
        ) {
            $desc->value = $value;
            $this->properties->version++;
            return true;
        }
        return false;
    }

    /**
     * Fast path for defining a brand-new enumerable, writable, configurable
     * data property. Used by object literal evaluation where the spec
     * (CreateDataPropertyOrThrow) always produces this exact shape and the
     * full defineOwnProperty merge logic is unnecessary. Skips the existing-
     * descriptor branch and the rebuilt-descriptor allocation that the
     * generic path emits even when the input is already complete.
     *
     * Returns false only if the object is non-extensible.
     */
    public function defineOwnDataPropertyFast(string $name, JsValue $value): bool
    {
        if ($this->isModuleNamespace && !self::isInternalSlot($name)) {
            // Module namespaces have specialised semantics; fall back.
            return $this->defineOwnProperty($name, PropertyDescriptor::data($value));
        }
        if (isset($this->properties->dataSlots[$name])) {
            // Existing fast slot: in-place value swap.
            $this->properties->dataSlots[$name] = $value;
            $this->properties->version++;
            return true;
        }
        if (isset($this->properties->descriptors[$name])) {
            // Existing slow descriptor: take the merge path so
            // configurability / writability / accessor↔data conversion
            // semantics stay correct.
            return $this->defineOwnProperty($name, PropertyDescriptor::data($value));
        }
        if (!$this->extensible) {
            return false;
        }
        // Brand-new fast slot, no descriptor allocation.
        $this->properties->setDataSlot($name, $value);
        return true;
    }

    /**
     * OrdinaryDefineOwnProperty per ES spec 10.1.6.
     *
     * Merges the incoming descriptor with any existing descriptor,
     * respecting configurability and writability constraints. Fields
     * that are absent from $desc keep their current values.
     */
    public function defineOwnProperty(string $name, PropertyDescriptor $desc): bool
    {
        // Module namespace exotic [[DefineOwnProperty]] (§10.4.6.5): once the
        // namespace is fully assembled, string-keyed exports can be only
        // trivially re-defined with matching descriptors and never mutated.
        // Symbol-keyed definitions and internal slot definitions are not
        // subject to this restriction (used internally to install toStringTag
        // and to build up the namespace before freezing).
        if ($this->isModuleNamespace && !self::isInternalSlot($name)) {
            $current = $this->properties->get($name);
            if ($current === null) {
                return false;
            }
            if ($desc->isAccessorDescriptor()) {
                return false;
            }
            if ($desc->configurable === true) {
                return false;
            }
            if ($desc->enumerable === false) {
                return false;
            }
            if ($desc->writable === false) {
                return false;
            }
            if ($desc->value !== null) {
                $currentValue = $current->isAccessorDescriptor() && $current->get !== null
                    ? (function () use ($current): JsValue {
                        try {
                            return $current->get->call($this, []);
                        } catch (\Throwable) {
                            return JsUndefined::instance();
                        }
                    })()
                    : ($current->value ?? JsUndefined::instance());
                if (!\Phasis\Spec\AbstractOperations::sameValue($currentValue, $desc->value)) {
                    return false;
                }
            }
            return true;
        }

        $current = $this->properties->get($name);

        if ($current === null) {
            if (!$this->extensible) {
                return false;
            }
            // Per spec, when creating a new property, absent fields default to false/undefined.
            if ($desc->isAccessorDescriptor()) {
                $this->properties->set($name, PropertyDescriptor::accessor(
                    get: $desc->get,
                    set: $desc->set,
                    enumerable: $desc->enumerable ?? false,
                    configurable: $desc->configurable ?? false,
                ));
            } else {
                $this->properties->set($name, new PropertyDescriptor(
                    value: $desc->value ?? JsUndefined::instance(),
                    writable: $desc->writable ?? false,
                    enumerable: $desc->enumerable ?? false,
                    configurable: $desc->configurable ?? false,
                ));
            }
            return true;
        }

        // If the current property is not configurable, enforce restrictions.
        if ($current->configurable === false) {
            // Cannot make it configurable.
            if ($desc->configurable === true) {
                return false;
            }
            // Cannot change enumerable if not configurable.
            if ($desc->enumerable !== null && $desc->enumerable !== $current->enumerable) {
                return false;
            }
        }

        $convertingToAccessor = $desc->get !== null || $desc->set !== null || $desc->isAccessorDescriptor();
        if ($current->isDataDescriptor() && $convertingToAccessor) {
            // Converting data to accessor.
            if ($current->configurable === false) {
                return false;
            }
            $merged = PropertyDescriptor::accessor(
                get: $desc->get,
                set: $desc->set,
                enumerable: $desc->enumerable ?? $current->enumerable ?? true,
                configurable: $desc->configurable ?? $current->configurable ?? true,
            );
            $this->properties->set($name, $merged);
            return true;
        }

        if ($current->isAccessorDescriptor() && $desc->isDataDescriptor() && !$desc->isAccessorDescriptor()) {
            // Converting accessor to data.
            if ($current->configurable === false) {
                return false;
            }
            $merged = new PropertyDescriptor(
                value: $desc->value,
                writable: $desc->writable ?? false,
                enumerable: $desc->enumerable ?? $current->enumerable ?? true,
                configurable: $desc->configurable ?? $current->configurable ?? true,
            );
            $this->properties->set($name, $merged);
            return true;
        }

        // Both are data descriptors: merge fields.
        if ($current->isDataDescriptor()) {
            if ($current->configurable === false) {
                if ($current->writable === false) {
                    if ($desc->writable === true) {
                        return false;
                    }
                    // If writable is false and configurable is false, reject value change.
                    $currentVal = $current->value ?? JsUndefined::instance();
                    $changed = $desc->value !== null
                        && !\Phasis\Spec\AbstractOperations::sameValue($desc->value, $currentVal);
                    if ($changed) {
                        return false;
                    }
                }
            }
            $merged = new PropertyDescriptor(
                value: $desc->value ?? $current->value,
                writable: $desc->writable ?? $current->writable,
                enumerable: $desc->enumerable ?? $current->enumerable,
                configurable: $desc->configurable ?? $current->configurable,
            );
            $this->properties->set($name, $merged);
            return true;
        }

        // Both are accessor descriptors: merge fields.
        if ($current->isAccessorDescriptor()) {
            if ($current->configurable === false) {
                // Per spec 10.1.6.3 step 10.a: if desc has [[Set]] and it is
                // not the same as current [[Set]], reject.
                if ($desc->hasSet && $desc->set !== $current->set) {
                    return false;
                }
                // Per spec 10.1.6.3 step 10.b: if desc has [[Get]] and it is
                // not the same as current [[Get]], reject.
                if ($desc->hasGet && $desc->get !== $current->get) {
                    return false;
                }
            }
            $merged = PropertyDescriptor::accessor(
                get: $desc->hasGet ? $desc->get : $current->get,
                set: $desc->hasSet ? $desc->set : $current->set,
                enumerable: $desc->enumerable ?? $current->enumerable ?? true,
                configurable: $desc->configurable ?? $current->configurable ?? true,
            );
            $this->properties->set($name, $merged);
            return true;
        }

        // Generic descriptor (no value/writable/get/set): merge.
        $merged = new PropertyDescriptor(
            value: $desc->value ?? $current->value,
            writable: $desc->writable ?? $current->writable,
            enumerable: $desc->enumerable ?? $current->enumerable,
            configurable: $desc->configurable ?? $current->configurable,
            get: $desc->get ?? $current->get,
            set: $desc->set ?? $current->set,
        );
        $this->properties->set($name, $merged);
        return true;
    }

    public function getPrototype(): ?JsObject
    {
        return $this->prototype;
    }

    public function setPrototype(?JsObject $prototype): void
    {
        $this->prototype = $prototype;
    }

    public function getProperties(): PropertyMap
    {
        return $this->properties;
    }

    public function typeof(): string
    {
        return 'object';
    }

    public function toBoolean(): bool
    {
        return true;
    }

    public function toNumber(): float
    {
        return NAN;
    }

    public function toInt32(): int
    {
        return 0;
    }

    public function toUint32(): int
    {
        return 0;
    }

    public function toJsString(): string
    {
        return '[object Object]';
    }

    public function display(): string
    {
        return '[object Object]';
    }
}
