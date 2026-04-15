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
        $factory = function (JsValue $this_) use ($iterSym): JsValue {
            $array = $this_ instanceof JsObject ? $this_ : new JsObject();
            $index = 0;
            $iterator = new JsObject();
            $nextFn = function () use ($array, &$index): JsValue {
                $result = new JsObject();
                $len = ($array instanceof JsArray)
                    ? $array->getLength()
                    : (int) \PhpJs\Spec\TypeConversion::toNumber($array->get('length'));
                if ($index < $len) {
                    $result->set('value', $array->get((string) $index));
                    $result->set('done', new JsBoolean(false));
                    $index++;
                } else {
                    $result->set('value', JsUndefined::instance());
                    $result->set('done', new JsBoolean(true));
                }
                return $result;
            };
            $iterator->set('next', JsFunction::fromCallable('next', $nextFn));
            $selfIterFn = JsFunction::fromCallable(
                '[Symbol.iterator]',
                function (JsValue $self_): JsValue {
                    return $self_;
                },
            );
            $iterator->setBySymbol($iterSym, $selfIterFn);
            return $iterator;
        };
        $iteratorFn = JsFunction::fromCallable('[Symbol.iterator]', $factory);
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
        if ($name === 'length') {
            if (!$this->lengthWritable) {
                if ($strict) {
                    throw new \PhpJs\Exceptions\TypeError(
                        "Cannot assign to read only property 'length' of object '[object Array]'"
                    );
                }
                return;
            }
            $this->setLengthFromValue($value);
            return;
        }

        parent::set($name, $value, $strict);

        // Only update length for valid array indices (0 to 2^32-2).
        if (self::isArrayIndex($name)) {
            $index = (int) $name;
            if ($index >= $this->length) {
                $this->length = $index + 1;
            }
        }
    }

    public function internalSet(string $name, JsValue $value, JsObject $receiver): bool
    {
        // When the receiver is this array itself, handle length and index tracking.
        if ($receiver === $this) {
            if ($name === 'length') {
                $this->setLengthFromValue($value);
                return true;
            }
            $result = parent::internalSet($name, $value, $receiver);
            // Only update length for valid array indices (0 to 2^32-2).
            if ($result && self::isArrayIndex($name)) {
                $index = (int) $name;
                if ($index >= $this->length) {
                    $this->length = $index + 1;
                }
            }
            return $result;
        }
        // When receiver differs (e.g. inherited set through prototype), use standard behavior.
        return parent::internalSet($name, $value, $receiver);
    }

    public function defineOwnProperty(string $name, PropertyDescriptor $desc): bool
    {
        if ($name === 'length') {
            // Handle value change.
            if ($desc->value !== null) {
                $num = \PhpJs\Spec\TypeConversion::toNumber($desc->value);
                $uint32 = (int) ($num >= 0 ? fmod($num, 4294967296) : fmod($num, 4294967296) + 4294967296);
                if ((float) $uint32 !== $num) {
                    throw new \PhpJs\Exceptions\RangeError('Invalid array length');
                }
                if (!$this->lengthWritable && $uint32 !== $this->length) {
                    return false;
                }
                $newLen = $uint32;
                // Delete elements above new length (per ArraySetLength).
                for ($i = $newLen; $i < $this->length; $i++) {
                    $this->delete((string) $i);
                }
                $this->length = $newLen;
            }
            // Handle writable change (e.g. Object.freeze sets writable to false).
            if ($desc->writable !== null) {
                // Cannot go from non-writable to writable.
                if (!$this->lengthWritable && $desc->writable === true) {
                    return false;
                }
                $this->lengthWritable = $desc->writable;
            }
            return true;
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
            $keys[] = 'length';
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
