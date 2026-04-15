<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\Object\PropertyDescriptor;
use PhpJs\Object\PropertyMap;

class JsObject implements JsValue
{
    protected PropertyMap $properties;
    private ?JsObject $prototype;
    private static ?JsObject $globalPrototype = null;
    private bool $extensible = true;

    /** @var array<int, PropertyDescriptor> Symbol-keyed properties, indexed by JsSymbol id. */
    protected array $symbolProperties = [];

    /** @var array<int, JsSymbol> Tracks JsSymbol instances by id, in insertion order. */
    protected array $symbolOrder = [];

    public static function setGlobalPrototype(JsObject $proto): void
    {
        self::$globalPrototype = $proto;
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

    public function preventExtensions(): void
    {
        $this->extensible = false;
    }

    public function get(string $name): JsValue
    {
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
        $desc = $this->properties->get($name);
        if ($desc !== null) {
            if ($desc->get !== null) {
                return $desc->get->call($receiver, []);
            }

            return $desc->value ?? JsUndefined::instance();
        }

        $proto = $this->getPrototype();
        if ($proto !== null) {
            return $proto->getWithReceiver($name, $receiver);
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
                    throw new \PhpJs\Exceptions\TypeError(
                        "Cannot set property {$name} of #<Object> which has only a getter"
                    );
                }
                if ($desc->writable === false) {
                    throw new \PhpJs\Exceptions\TypeError(
                        "Cannot assign to read only property '{$name}' of object '#<Object>'"
                    );
                }
            }
            if (!$this->extensible) {
                throw new \PhpJs\Exceptions\TypeError(
                    "Cannot add property {$name}, object is not extensible"
                );
            }
            throw new \PhpJs\Exceptions\TypeError(
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
                // Update value on the receiver's existing descriptor.
                $valueDesc = new PropertyDescriptor(value: $value);
                $receiver->defineOwnProperty($name, new PropertyDescriptor(
                    value: $value,
                    writable: $existingDesc->writable,
                    enumerable: $existingDesc->enumerable,
                    configurable: $existingDesc->configurable,
                ));
                return true;
            }
            // Receiver does not have property P. Create it if extensible.
            if (!$receiver->isExtensible()) {
                return false;
            }
            $receiver->defineOwnProperty($name, PropertyDescriptor::data($value));
            return true;
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
     * Internal symbol property lookup that preserves the original receiver.
     */
    protected function getBySymbolWithReceiver(JsSymbol $symbol, JsObject $receiver): JsValue
    {
        $id = $symbol->getId();
        if (isset($this->symbolProperties[$id])) {
            $desc = $this->symbolProperties[$id];
            if ($desc->get !== null) {
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
    public function setBySymbol(JsSymbol $symbol, JsValue $value): void
    {
        $id = $symbol->getId();
        if (isset($this->symbolProperties[$id])) {
            $desc = $this->symbolProperties[$id];
            if ($desc->set !== null) {
                $desc->set->call($this, [$value]);
                return;
            }
            if ($desc->writable === false) {
                return;
            }
            $desc->value = $value;
            return;
        }

        $this->symbolProperties[$id] = PropertyDescriptor::data($value);
        $this->symbolOrder[$id] = $symbol;
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

    /** Define a property descriptor by symbol key. */
    public function definePropertyBySymbol(JsSymbol $symbol, PropertyDescriptor $desc): void
    {
        $id = $symbol->getId();
        if (!isset($this->symbolOrder[$id])) {
            $this->symbolOrder[$id] = $symbol;
        }
        $this->symbolProperties[$id] = $desc;
    }

    /** Get own property descriptor by symbol key (does not walk prototype chain). */
    public function getSymbolPropertyDescriptor(JsSymbol $symbol): ?PropertyDescriptor
    {
        return $this->symbolProperties[$symbol->getId()] ?? null;
    }

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
                throw new \PhpJs\Exceptions\TypeError(
                    "Cannot delete property '{$name}' of #<Object>"
                );
            }
            return false;
        }

        return $this->properties->delete($name);
    }

    public function defineProperty(string $name, PropertyDescriptor $desc): void
    {
        $this->properties->set($name, $desc);
    }

    public function getOwnPropertyDescriptor(string $name): ?PropertyDescriptor
    {
        return $this->properties->get($name);
    }

    public function hasOwnProperty(string $name): bool
    {
        return $this->properties->has($name);
    }

    /** @return list<string> */
    public function ownKeys(): array
    {
        return $this->properties->keys();
    }

    /** @return list<string> */
    public function getOwnPropertyNames(): array
    {
        return $this->properties->keys();
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
            if (self::isArrayIndex($key)) {
                $integerIndices[] = $key;
            } else {
                $nonIndexStrings[] = new JsString($key);
            }
        }

        // Sort integer indices numerically (ascending).
        usort($integerIndices, fn(string $a, string $b) => (int) $a <=> (int) $b);
        $sortedIndices = array_map(fn(string $k) => new JsString($k), $integerIndices);

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

    /** @return list<string> */
    /** @return list<string> Own enumerable keys. */
    public function getOwnEnumerableKeys(): array
    {
        return $this->properties->enumerableKeys();
    }

    /**
     * @return list<string> All enumerable keys including inherited.
     * Used by for-in. Walks prototype chain.
     */
    public function getEnumerableKeys(): array
    {
        $seen = [];
        $keys = [];
        $obj = $this;
        while ($obj !== null) {
            foreach ($obj->properties->enumerableKeys() as $key) {
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $keys[] = $key;
                }
            }
            $obj = $obj->prototype;
        }
        return $keys;
    }

    /**
     * Alias for defineProperty, matching the spec name OrdinaryDefineOwnProperty.
     */
    public function defineOwnProperty(string $name, PropertyDescriptor $desc): void
    {
        $this->properties->set($name, $desc);
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
