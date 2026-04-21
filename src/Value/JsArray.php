<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\Object\PropertyDescriptor;

class JsArray extends JsObject
{
    private int $length = 0;
    private bool $lengthWritable = true;
    private static ?JsObject $globalPrototype = null;

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

        foreach ($elements as $index => $element) {
            $this->defineOwnProperty((string) $index, PropertyDescriptor::data($element));
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
        $iterSym = \PhpJs\BuiltIn\SymbolConstructor::iterator();
        $factory = function (JsValue $this_): JsValue {
            $array = $this_ instanceof JsObject ? $this_ : new JsObject();
            return \PhpJs\BuiltIn\ArrayConstructor::createArrayIteratorFromSymbol($array, 'value');
        };
        $iteratorFn = JsFunction::fromCallable('values', $factory);
        $proto->setBySymbol($iterSym, $iteratorFn);
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
     * Validate and set length from a JS value per ES spec 10.4.2.4.
     *
     * Throws RangeError if the value is not a valid array length
     * (negative, > 2^32-1, or non-integer).
     */
    private function setLengthFromValue(JsValue $value): void
    {
        $num = \PhpJs\Spec\TypeConversion::toNumber($value);
        $uint32 = (int) ($num >= 0 ? fmod($num, 4294967296) : fmod($num, 4294967296) + 4294967296);
        if ((float) $uint32 !== $num) {
            throw new \PhpJs\Exceptions\RangeError('Invalid array length');
        }
        $oldLength = $this->length;
        $this->length = $uint32;
        // Delete elements above new length (per ArraySetLength).
        if ($uint32 < $oldLength) {
            for ($i = $uint32; $i < $oldLength; $i++) {
                $this->delete((string) $i);
            }
        }
    }

    public function push(JsValue $value): void
    {
        $index = $this->length;
        parent::set((string) $index, $value);
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
            return new JsNumber((float) $this->length);
        }

        return parent::get($name);
    }

    protected function getWithReceiver(string $name, JsObject $receiver): JsValue
    {
        if ($name === 'length') {
            return new JsNumber((float) $this->length);
        }

        return parent::getWithReceiver($name, $receiver);
    }

    public function set(string $name, JsValue $value, bool $strict = false): void
    {
        // All properties including "length" go through the standard set path,
        // which calls internalSet -> ordinarySetWithOwnDescriptor ->
        // defineOwnProperty -> arraySetLength. This ensures value coercion
        // happens before writable checks per ES spec.
        $success = $this->internalSet($name, $value, $this);
        if (!$success && $strict) {
            throw new \PhpJs\Exceptions\TypeError(
                "Cannot assign to read only property '{$name}' of object '[object Array]'"
            );
        }

        // Only update length for valid array indices (0 to 2^32-2).
        if ($success && $name !== 'length' && self::isArrayIndex($name)) {
            $index = (int) $name;
            if ($index >= $this->length) {
                $this->length = $index + 1;
            }
        }
    }

    public function internalSet(string $name, JsValue $value, JsValue $receiver): bool
    {
        if ($receiver === $this && $name !== 'length') {
            $result = parent::internalSet($name, $value, $receiver);
            if ($result && self::isArrayIndex($name)) {
                $index = (int) $name;
                if ($index >= $this->length) {
                    $this->length = $index + 1;
                }
            }
            return $result;
        }
        // Length and non-self receiver use standard OrdinarySet which
        // calls defineOwnProperty -> arraySetLength for length.
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
            // Delete elements above new length in descending order (step 15).
            // Collect only actually-existing array-index properties >= newLen
            // to avoid iterating billions of empty slots on sparse arrays.
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
            $deleteSucceeded = true;
            foreach ($indicesToDelete as $i) {
                $key = (string) $i;
                $elemDesc = parent::getOwnPropertyDescriptor($key);
                if ($elemDesc !== null && $elemDesc->configurable === false) {
                    $newLen = $i + 1;
                    $deleteSucceeded = false;
                    break;
                }
                $this->delete($key);
            }
            $this->length = $newLen;
            if (!$deleteSucceeded) {
                if (!$newWritable) {
                    $this->lengthWritable = false;
                }
                return false;
            }
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
        return parent::has($name);
    }

    public function hasOwnProperty(string $name): bool
    {
        if ($name === 'length') {
            return true;
        }
        return parent::hasOwnProperty($name);
    }

    public function getOwnPropertyDescriptor(string $name): ?\PhpJs\Object\PropertyDescriptor
    {
        if ($name === 'length') {
            return new \PhpJs\Object\PropertyDescriptor(
                value: new JsNumber((float) $this->length),
                writable: $this->lengthWritable,
                enumerable: false,
                configurable: false,
            );
        }
        return parent::getOwnPropertyDescriptor($name);
    }

    /** @return list<string> */
    public function getOwnPropertyNames(): array
    {
        $keys = parent::getOwnPropertyNames();
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
        $result = parent::ordinaryOwnPropertyKeys();
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

        return parent::delete($name, $strict);
    }
}
