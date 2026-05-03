<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\Object\PropertyDescriptor;

/**
 * JS Array exotic object with packed-element fast storage.
 *
 * Integer-indexed elements with default attributes (writable, enumerable,
 * configurable; no accessor) live in $denseElements as a plain PHP list
 * so that hot paths — push, [i] read, [i] write, length tracking — skip
 * the property-map hash and PropertyDescriptor allocation entirely.
 *
 * The instance flips to dictionary mode (denseMode = false) the first
 * time defineOwnProperty installs a non-default attribute set or an
 * accessor on an array-index slot. At that point every dense slot is
 * migrated into PropertyMap and the dense storage is dropped.
 *
 * Length and 'length' descriptor remain exotic owned by JsArray; only
 * actual element values move to dense storage. Symbol-keyed and named
 * properties (including 'length') always go through PropertyMap.
 */
class JsArray extends JsObject
{
    private int $length = 0;
    private bool $lengthWritable = true;
    private static ?JsObject $globalPrototype = null;

    /**
     * Packed integer-indexed elements when in dense mode. NULL slots
     * are holes (the index has no own property). The raw list lets
     * arr-push / arr[i]= / arr[i] all dispatch as a single array
     * indexing in PHP, no descriptor or hash involved.
     *
     * @var array<int, ?JsValue>
     */
    private array $denseElements = [];

    /**
     * When true, integer-indexed elements live in $denseElements and
     * carry the default {writable, enumerable, configurable} attribute
     * set. Flipping to false migrates everything to PropertyMap and
     * stays there for the lifetime of the instance.
     */
    private bool $denseMode = true;

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
     * @param list<JsValue> $elements
     */
    public function __construct(array $elements = [], ?JsObject $prototype = null)
    {
        parent::__construct($prototype ?? self::$globalPrototype);

        // Dense init: skip defineOwnProperty entirely so seeded elements
        // never touch PropertyMap.
        foreach ($elements as $index => $element) {
            $this->denseElements[$index] = $element;
        }
        $this->length = count($elements);
    }

    /**
     * Install Symbol.iterator on an Array prototype object.
     *
     * Called by ArrayConstructor::install after the prototype is set up.
     * Symbol.iterator lives on Array.prototype, not on each instance.
     */
    public static function installSymbolIteratorOnPrototype(JsObject $proto): void
    {
        // Per §23.1.3.37: Array.prototype[@@iterator] is the same function
        // object as Array.prototype.values, with descriptor
        // {writable, configurable, not enumerable}.
        $iterSym = \PhpJs\BuiltIn\SymbolConstructor::iterator();
        $valuesFn = $proto->get('values');
        if (!$valuesFn instanceof JsFunction) {
            // Fall back to a fresh function if values isn't installed yet.
            $valuesFn = JsFunction::fromCallable('values', function (JsValue $this_): JsValue {
                $array = $this_ instanceof JsObject ? $this_ : new JsObject();
                return \PhpJs\BuiltIn\ArrayConstructor::createArrayIteratorFromSymbol($array, 'value');
            });
        }
        $proto->definePropertyBySymbol(
            $iterSym,
            \PhpJs\Object\PropertyDescriptor::data($valuesFn, true, false, true),
        );
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function setLength(int $length): void
    {
        $this->length = $length;
    }

    /**
     * Whether integer-indexed elements live in $denseElements with
     * default attributes. VM inline paths for hot Array prototype
     * methods read this to decide whether they can skip the spec
     * descriptor lookups; non-dense arrays drop back to the spec
     * implementation on the host side.
     */
    public function isDenseMode(): bool
    {
        return $this->denseMode;
    }

    /**
     * VM inline guard: pop / push / fill don't go through the spec
     * [[Set]] for length, so they need to bail when the array's
     * length descriptor was made non-writable (Object.freeze /
     * Object.preventExtensions / Object.defineProperty(arr, 'length',
     * {writable: false})). The spec path then surfaces the correct
     * TypeError.
     */
    public function isLengthWritable(): bool
    {
        return $this->lengthWritable;
    }

    /**
     * Snapshot the dense element list for VM inline paths. The
     * returned array is keyed [0..length-1]; holes appear as null
     * entries. Callers must already have checked isDenseMode().
     *
     * @return array<int, ?JsValue>
     */
    public function getDenseElements(): array
    {
        return $this->denseElements;
    }

    /**
     * Direct dense-element write used by VM inline paths (e.g.
     * Array.prototype.map's per-element set). Caller guarantees the
     * index is within [0, length-1] and the array is in dense mode.
     */
    public function setDenseElement(int $index, JsValue $value): void
    {
        $this->denseElements[$index] = $value;
    }

    public function push(JsValue $value): void
    {
        $index = $this->length;
        if ($this->denseMode) {
            $this->denseElements[$index] = $value;
        } else {
            parent::set((string) $index, $value);
        }
        $this->length = $index + 1;
    }

    /**
     * @return list<JsValue>
     */
    public function toList(): array
    {
        $result = [];
        for ($i = 0; $i < $this->length; $i++) {
            $result[] = $this->get((string) $i);
        }
        return $result;
    }

    /**
     * @param list<JsValue> $items
     */
    public static function fromArray(array $items): self
    {
        return new self($items);
    }

    /**
     * @param JsValue ...$values
     */
    public static function fromValues(JsValue ...$values): self
    {
        return new self(array_values($values));
    }

    public function display(): string
    {
        $parts = [];
        for ($i = 0; $i < $this->length; $i++) {
            $value = $this->get((string) $i);
            if ($value instanceof JsUndefined) {
                $parts[] = '';
            } else {
                $parts[] = $value->display();
            }
        }
        return implode(',', $parts);
    }

    public function toJsString(): string
    {
        $parts = [];
        // Cap at 10000 to prevent OOM on sparse arrays with huge length
        $len = min($this->length, 10000);
        for ($i = 0; $i < $len; $i++) {
            $value = $this->get((string) $i);
            if ($value instanceof JsUndefined || $value instanceof JsNull) {
                $parts[] = '';
            } else {
                $parts[] = $value->toJsString();
            }
        }
        return implode(',', $parts);
    }

    public function get(string $name): JsValue
    {
        if ($name === 'length') {
            return JsNumber::of((float) $this->length);
        }
        if ($this->denseMode && self::isArrayIndex($name)) {
            $idx = (int) $name;
            $val = $this->denseElements[$idx] ?? null;
            if ($val !== null) {
                return $val;
            }
            // Hole: defer to prototype chain (Array.prototype lookup).
            return $this->getWithReceiver($name, $this);
        }
        return parent::get($name);
    }

    protected function getWithReceiver(string $name, JsObject $receiver): JsValue
    {
        if ($name === 'length') {
            return JsNumber::of((float) $this->length);
        }
        if ($this->denseMode && self::isArrayIndex($name)) {
            $idx = (int) $name;
            $val = $this->denseElements[$idx] ?? null;
            if ($val !== null) {
                return $val;
            }
            // Hole: walk the prototype chain.
            $proto = $this->getPrototype();
            if ($proto !== null) {
                return $proto->getWithValueReceiver($name, $receiver);
            }
            return JsUndefined::instance();
        }
        return parent::getWithReceiver($name, $receiver);
    }

    public function getWithValueReceiver(string $name, JsValue $receiver): JsValue
    {
        // When this JsArray is encountered while walking another object's
        // prototype chain, the length slot must still be reported or the
        // inherited lookup will miss it (no descriptor sits in $properties).
        if ($name === 'length') {
            return JsNumber::of((float) $this->length);
        }
        if ($this->denseMode && self::isArrayIndex($name)) {
            $idx = (int) $name;
            $val = $this->denseElements[$idx] ?? null;
            if ($val !== null) {
                return $val;
            }
            // Hole: continue walking the chain.
            $proto = $this->getPrototype();
            if ($proto !== null) {
                return $proto->getWithValueReceiver($name, $receiver);
            }
            return JsUndefined::instance();
        }
        return parent::getWithValueReceiver($name, $receiver);
    }

    public function set(string $name, JsValue $value, bool $strict = false): void
    {
        // Hot path: writing an array index in dense mode with the array
        // as receiver. Skips the OrdinarySet → defineOwnProperty →
        // PropertyMap chain entirely and just stores the value, bumping
        // length. Used heavily by push, splice, fill, etc.
        //
        // We can shortcut whenever OrdinarySet would observably do the
        // same thing as the in-place dense write:
        //   1. The array already has an own dense element at this index.
        //      Own writable data descriptor wins over the prototype chain
        //      per spec, so any inherited accessor / readonly slot is
        //      irrelevant and the write must succeed.
        //   2. There is no own dense element AND the prototype chain has
        //      no own property at this name. We're creating a fresh own
        //      data slot and there's nothing inherited to defer to.
        // If neither condition holds (no own slot but proto has one), we
        // fall through to the standard internalSet path so accessors
        // installed on Array.prototype run.
        if (
            $name !== 'length'
            && $this->denseMode
            && self::isArrayIndex($name)
            // Bail when the prototype is (or contains) a JsProxy:
            // proxy set traps need to fire even when the trap target
            // doesn't own the property. The dense fast path's
            // prototypeChainHasOwnProperty check would miss the trap.
            && !$this->prototypeChainHasProxy()
            && !$this->prototypeChainHasTypedArray()
        ) {
            $idx = (int) $name;
            $hasOwnDense = ($this->denseElements[$idx] ?? null) !== null;
            if ($hasOwnDense || !$this->prototypeChainHasOwnProperty($name)) {
                if ($idx >= $this->length && !$this->lengthWritable) {
                    if ($strict) {
                        throw new \PhpJs\Exceptions\TypeError(
                            "Cannot assign to read only property '{$name}' of object '[object Array]'"
                        );
                    }
                    return;
                }
                // Adding a NEW dense slot (no existing own slot,
                // index extends the array) requires the array to be
                // extensible. Object.preventExtensions / freeze /
                // seal flips this — silently dropping the write in
                // sloppy mode and throwing in strict mode.
                if (!$hasOwnDense && !$this->isExtensible()) {
                    if ($strict) {
                        throw new \PhpJs\Exceptions\TypeError(
                            "Cannot add property {$name}, object is not extensible"
                        );
                    }
                    return;
                }
                $this->denseElements[$idx] = $value;
                if ($idx >= $this->length) {
                    $this->length = $idx + 1;
                }
                return;
            }
        }
        // All other properties (length, named, dictionary mode) go
        // through the standard set path, which calls internalSet ->
        // ordinarySetWithOwnDescriptor -> defineOwnProperty ->
        // arraySetLength. This ensures value coercion happens before
        // writable checks per ES spec.
        $success = $this->internalSet($name, $value, $this);
        if (!$success && $strict) {
            throw new \PhpJs\Exceptions\TypeError(
                "Cannot assign to read only property '{$name}' of object '[object Array]'"
            );
        }

        // Only update length for valid array indices (0 to 2^32-2) when an
        // actual own property was created; inherited setters don't add slots.
        if ($success && $name !== 'length' && self::isArrayIndex($name) && $this->hasOwnProperty($name)) {
            $index = (int) $name;
            if ($index >= $this->length) {
                $this->length = $index + 1;
            }
        }
    }

    public function internalSet(string $name, JsValue $value, JsObject $receiver): bool
    {
        if ($name === 'length') {
            // length is an exotic own slot maintained by JsArray (not in
            // $properties). Mirror OrdinarySetWithOwnDescriptor inline so
            // we do not fall through to JsObject::internalSet's "no own
            // descriptor → walk prototype chain" path, which would hand
            // the write to a proxy on Array.prototype and break the
            // length-zeroed-via-valueOf semantics tested by
            // built-ins/Array/prototype/(indexOf|lastIndexOf|reverse).
            // The non-writable-length check still has to fire so frozen
            // arrays surface the TypeError on pop/shift/etc.
            if (!$this->lengthWritable) {
                return false;
            }
            if ($receiver === $this) {
                return $this->defineOwnProperty($name, new PropertyDescriptor(
                    value: $value,
                    writable: $this->lengthWritable,
                    enumerable: false,
                    configurable: false,
                ));
            }
            // Different receiver: per OrdinarySetWithOwnDescriptor, if the
            // receiver has its own length descriptor we update there;
            // otherwise CreateDataProperty on the receiver. Defer to the
            // standard implementation.
            return parent::internalSet($name, $value, $receiver);
        }
        // Dense fast path: array index, this receiver, dense mode, length
        // is writable. Mirrors set()'s shortcut: write inline whenever
        // OrdinarySet would observably do the same thing — either the
        // own dense element exists (own writable data desc wins over
        // proto) or the proto chain is clean. Also bails when the
        // chain has a JsProxy whose set trap has to fire.
        if (
            $receiver === $this
            && $this->denseMode
            && self::isArrayIndex($name)
            && !$this->prototypeChainHasProxy()
            && !$this->prototypeChainHasTypedArray()
        ) {
            $idx = (int) $name;
            $hasOwnDense = ($this->denseElements[$idx] ?? null) !== null;
            if ($hasOwnDense || !$this->prototypeChainHasOwnProperty($name)) {
                if ($idx >= $this->length && !$this->lengthWritable) {
                    return false;
                }
                $this->denseElements[$idx] = $value;
                if ($idx >= $this->length) {
                    $this->length = $idx + 1;
                }
                return true;
            }
        }
        if ($receiver === $this) {
            $result = parent::internalSet($name, $value, $receiver);
            // Only bump length if the set produced an actual own array-index
            // property. When an inherited accessor (setter) handled the write,
            // no own slot is created and length must not advance.
            if ($result && self::isArrayIndex($name) && $this->hasOwnProperty($name)) {
                $index = (int) $name;
                if ($index >= $this->length) {
                    $this->length = $index + 1;
                }
            }
            return $result;
        }
        // Non-self receiver: defer to the standard OrdinarySet path.
        return parent::internalSet($name, $value, $receiver);
    }

    public function defineOwnProperty(string $name, PropertyDescriptor $desc): bool
    {
        if ($name === 'length') {
            // Cannot convert length to an accessor property.
            if ($desc->get !== null || $desc->set !== null) {
                return false;
            }
            // Per spec ArraySetLength: when no [[Value]] field, just validate attributes.
            if ($desc->value === null) {
                if ($desc->configurable === true || $desc->enumerable === true) {
                    return false;
                }
                if ($desc->writable !== null) {
                    if (!$this->lengthWritable && $desc->writable === true) {
                        return false;
                    }
                    $this->lengthWritable = $desc->writable;
                }
                return true;
            }
            // Per spec ArraySetLength steps 3-5: coerce value FIRST.
            // Coercion may have observable side effects (valueOf, Symbol.toPrimitive).
            // Step 3: Let newLen be ToUint32(Desc.[[Value]]). This calls ToNumber internally.
            $numForUint32 = \PhpJs\Spec\TypeConversion::toNumber($desc->value);
            $uint32 = (int) ($numForUint32 >= 0
                ? fmod($numForUint32, 4294967296)
                : fmod($numForUint32, 4294967296) + 4294967296);
            // Step 4: Let numberLen be ToNumber(Desc.[[Value]]).
            // Per spec, this is a second, separate coercion that may trigger
            // valueOf/toPrimitive again with observable side effects.
            $num = \PhpJs\Spec\TypeConversion::toNumber($desc->value);
            // Step 5: RangeError before any configurable/enumerable validation.
            if ((float) $uint32 !== $num) {
                throw new \PhpJs\Exceptions\RangeError('Invalid array length');
            }
            // Now check configurable/enumerable (after coercion per spec).
            if ($desc->configurable === true || $desc->enumerable === true) {
                return false;
            }
            // Step 12: writable check after coercion.
            if (!$this->lengthWritable) {
                if ($uint32 !== $this->length) {
                    return false;
                }
                if ($desc->writable === true) {
                    return false;
                }
                return true;
            }
            // Determine deferred writable change per spec step 14.
            $newWritable = $desc->writable !== false;
            $newLen = $uint32;
            // Only walk own array-index properties when the new length shrinks
            // the array. Growing or no-op length writes (the common case for
            // push/Array.from/etc.) skip the O(n) properties scan.
            $deleteSucceeded = true;
            if ($newLen < $this->length) {
                $deleteSucceeded = $this->shrinkLength($newLen);
                if (!$deleteSucceeded) {
                    // shrinkLength left $this->length at the highest index it
                    // could not delete + 1; commit that and report failure.
                    if (!$newWritable) {
                        $this->lengthWritable = false;
                    }
                    return false;
                }
            }
            $this->length = $newLen;
            if (!$newWritable) {
                $this->lengthWritable = false;
            }
            return true;
        }
        // Per spec 15.4.5.1 step 4.b: if P is an array index >= oldLen and
        // the length property is not writable, reject.
        if (self::isArrayIndex($name)) {
            $index = (int) $name;
            if ($index >= $this->length && !$this->lengthWritable) {
                return false;
            }
            // Dense fast path: default-attr data descriptor on an array
            // index slot. Anything that doesn't fit (accessor, non-default
            // flags, value=null) migrates the array to dictionary mode and
            // re-enters via PropertyMap.
            if ($this->denseMode && self::isDefaultDataDescriptor($desc)) {
                // Per spec ValidateAndApplyPropertyDescriptor step 2:
                // creating a new property on a non-extensible object
                // returns false. Updating an existing slot is fine.
                $hasOwnDense = ($this->denseElements[$index] ?? null) !== null;
                if (!$hasOwnDense && !$this->isExtensible()) {
                    return false;
                }
                $this->denseElements[$index] = $desc->value;
                if ($index >= $this->length) {
                    $this->length = $index + 1;
                }
                return true;
            }
            if ($this->denseMode) {
                $this->migrateToDictionary();
            }
        }
        $result = parent::defineOwnProperty($name, $desc);
        // Only update length for valid array indices (0 to 2^32-2).
        if ($result && self::isArrayIndex($name)) {
            $index = (int) $name;
            if ($index >= $this->length) {
                $this->length = $index + 1;
            }
        }
        return $result;
    }

    public function has(string $name): bool
    {
        if ($name === 'length') {
            return true;
        }
        if ($this->denseMode && self::isArrayIndex($name)) {
            $idx = (int) $name;
            if (($this->denseElements[$idx] ?? null) !== null) {
                return true;
            }
            // Hole on this array; fall through to prototype chain.
            $proto = $this->getPrototype();
            return $proto !== null && $proto->has($name);
        }
        return parent::has($name);
    }

    public function hasOwnProperty(string $name): bool
    {
        if ($name === 'length') {
            return true;
        }
        if ($this->denseMode && self::isArrayIndex($name)) {
            return ($this->denseElements[(int) $name] ?? null) !== null;
        }
        return parent::hasOwnProperty($name);
    }

    public function getOwnPropertyDescriptor(string $name): ?\PhpJs\Object\PropertyDescriptor
    {
        if ($name === 'length') {
            return new \PhpJs\Object\PropertyDescriptor(
                value: JsNumber::of((float) $this->length),
                writable: $this->lengthWritable,
                enumerable: false,
                configurable: false,
            );
        }
        if ($this->denseMode && self::isArrayIndex($name)) {
            $val = $this->denseElements[(int) $name] ?? null;
            if ($val === null) {
                return null;
            }
            return PropertyDescriptor::data($val);
        }
        return parent::getOwnPropertyDescriptor($name);
    }

    /** @return list<string> */
    public function getOwnPropertyNames(): array
    {
        $keys = [];
        if ($this->denseMode) {
            // Dense indices in ascending order (skipping holes).
            for ($i = 0; $i < $this->length; $i++) {
                if (($this->denseElements[$i] ?? null) !== null) {
                    $keys[] = (string) $i;
                }
            }
        }
        $rest = parent::getOwnPropertyNames();
        if ($this->denseMode) {
            // Dictionary path is empty for indices, but PropertyMap may
            // hold non-index named keys.
            foreach ($rest as $k) {
                if (!self::isArrayIndex($k)) {
                    $keys[] = $k;
                }
            }
        } else {
            $keys = $rest;
        }
        if (!in_array('length', $keys, true)) {
            // Insert 'length' after array indices but before non-index string keys,
            // matching OrdinaryOwnPropertyKeys ordering.
            $insertPos = 0;
            foreach ($keys as $i => $key) {
                if (self::isArrayIndex($key)) {
                    $insertPos = $i + 1;
                } else {
                    break;
                }
            }
            array_splice($keys, $insertPos, 0, ['length']);
        }
        return $keys;
    }

    /**
     * @return list<JsValue>
     */
    public function ordinaryOwnPropertyKeys(): array
    {
        $result = [];
        if ($this->denseMode) {
            for ($i = 0; $i < $this->length; $i++) {
                if (($this->denseElements[$i] ?? null) !== null) {
                    $result[] = new JsString((string) $i);
                }
            }
        }
        $parentKeys = parent::ordinaryOwnPropertyKeys();
        if ($this->denseMode) {
            foreach ($parentKeys as $k) {
                if (!($k instanceof JsString && self::isArrayIndex($k->value))) {
                    $result[] = $k;
                }
            }
        } else {
            $result = $parentKeys;
        }
        // Insert 'length' after all integer indices but before non-index string keys.
        // Array.length is a non-enumerable, non-configurable own property.
        $insertPos = 0;
        foreach ($result as $i => $key) {
            if ($key instanceof JsString && self::isArrayIndex($key->toJsString())) {
                $insertPos = $i + 1;
            } else {
                break;
            }
        }
        array_splice($result, $insertPos, 0, [new JsString('length')]);
        return $result;
    }

    /** Array.length is non-configurable: delete must return false (or throw in strict mode). */
    public function delete(string $name, bool $strict = false): bool
    {
        if ($name === 'length') {
            if ($strict) {
                throw new \PhpJs\Exceptions\TypeError(
                    "Cannot delete property 'length' of [object Array]"
                );
            }
            return false;
        }
        if ($this->denseMode && self::isArrayIndex($name)) {
            $idx = (int) $name;
            // Mark the slot as a hole. Dense default-attr elements are
            // always configurable; delete always succeeds.
            unset($this->denseElements[$idx]);
            return true;
        }
        return parent::delete($name, $strict);
    }

    /**
     * Walk the prototype chain looking for an own property at $name.
     * Used by the dense write fast paths to refuse the shortcut when
     * an inherited slot (typically a setter installed on
     * Array.prototype) needs to participate in [[Set]] per spec
     * OrdinarySetWithOwnDescriptor. The common case (clean
     * Array.prototype with no integer-indexed properties) returns
     * false after a single hash miss per chain level.
     */
    private function prototypeChainHasOwnProperty(string $name): bool
    {
        for ($cur = $this->getPrototype(); $cur !== null; $cur = $cur->getPrototype()) {
            if ($cur->hasOwnProperty($name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether any object on the prototype chain is a JsProxy. Proxy
     * set / has / get traps need to fire even when the trap target
     * doesn't own the property — the dense fast path's
     * prototypeChainHasOwnProperty check misses that, so we bail
     * on any prototype proxy and route through internalSet which
     * walks the chain via OrdinarySetWithOwnDescriptor.
     */
    private function prototypeChainHasProxy(): bool
    {
        for ($cur = $this->getPrototype(); $cur !== null; $cur = $cur->getPrototype()) {
            if ($cur instanceof \PhpJs\Value\JsProxy) {
                return true;
            }
        }
        return false;
    }

    /**
     * The dense fast path bypasses OrdinarySetWithOwnDescriptor, but a
     * TypedArray in the proto chain has its own integer-indexed exotic
     * [[Set]] that returns true for invalid indices without creating an
     * own property on the receiver. Detect that case so the slow path
     * runs and the spec semantics are preserved.
     */
    private function prototypeChainHasTypedArray(): bool
    {
        for ($cur = $this->getPrototype(); $cur !== null; $cur = $cur->getPrototype()) {
            if ($cur instanceof \PhpJs\Value\JsTypedArray) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a PropertyDescriptor describes the default array-element
     * data shape: a value, no accessor, and either explicit defaults
     * (writable=true, enumerable=true, configurable=true) or all-null
     * attribute fields (filled by completeDescriptor downstream).
     */
    private static function isDefaultDataDescriptor(PropertyDescriptor $desc): bool
    {
        if ($desc->get !== null || $desc->set !== null) {
            return false;
        }
        if ($desc->value === null) {
            return false;
        }
        // Require all three attribute flags to be explicitly true. A
        // null field means "not specified" — per spec
        // ValidateAndApplyPropertyDescriptor, when there's no existing
        // own property the missing attribute defaults to FALSE, not
        // true. Treating null as default-true here would silently
        // store an Object.defineProperty(arr, "0", {value: x}) (which
        // should produce a non-writable / non-enum / non-configurable
        // index) as a default-attr dense slot, breaking ~50 test262
        // Object/defineProperty array-index tests.
        if ($desc->writable !== true) {
            return false;
        }
        if ($desc->enumerable !== true) {
            return false;
        }
        if ($desc->configurable !== true) {
            return false;
        }
        return true;
    }

    /**
     * Migrate dense element storage to PropertyMap-backed dictionary
     * mode. Called the first time an array-index slot is given a
     * non-default descriptor (accessor or non-default flag combination).
     */
    private function migrateToDictionary(): void
    {
        if (!$this->denseMode) {
            return;
        }
        // Use the low-level defineProperty bypass so a non-extensible
        // array (e.g. one mid-Object.freeze, where preventExtensions
        // already fired before the per-key defineOwnProperty pass) still
        // gets its dense slots faithfully migrated. Their descriptor
        // shape — default attrs — is invariant, so the bypass cannot
        // produce a spec-visible state we couldn't have reached
        // through defineOwnProperty earlier.
        foreach ($this->denseElements as $i => $value) {
            if ($value === null) {
                continue;
            }
            parent::defineProperty(
                (string) $i,
                PropertyDescriptor::data($value, true, true, true),
            );
        }
        $this->denseElements = [];
        $this->denseMode = false;
    }

    /**
     * Per ArraySetLength: walk array-index own properties >= $newLen
     * highest-first, deleting where possible. If a non-configurable
     * index is encountered, length is pinned at index + 1 and false is
     * returned so the caller can also pin the writable bit.
     */
    private function shrinkLength(int $newLen): bool
    {
        if ($this->denseMode) {
            // Dense default-attr elements are always configurable, so the
            // shrink can never fail. Walk only the existing keys instead
            // of [newLen..length); a sparse array with length=1e9 would
            // otherwise iterate a billion empty slots here.
            foreach (array_keys($this->denseElements) as $idx) {
                if ($idx >= $newLen) {
                    unset($this->denseElements[$idx]);
                }
            }
            $this->length = $newLen;
            return true;
        }
        // Dictionary mode: collect array-index keys that exist and are
        // >= newLen, sorted descending so we hit the highest index
        // first (matching the spec walk).
        $indicesToDelete = [];
        foreach ($this->properties->keys() as $key) {
            if (self::isArrayIndex($key)) {
                $idx = (int) $key;
                if ($idx >= $newLen) {
                    $indicesToDelete[] = $idx;
                }
            }
        }
        rsort($indicesToDelete, SORT_NUMERIC);
        foreach ($indicesToDelete as $i) {
            $key = (string) $i;
            $elemDesc = parent::getOwnPropertyDescriptor($key);
            if ($elemDesc !== null && $elemDesc->configurable === false) {
                $this->length = $i + 1;
                return false;
            }
            $this->delete($key);
        }
        $this->length = $newLen;
        return true;
    }
}
