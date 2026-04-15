<?php

declare(strict_types=1);

namespace PhpJs\Value;

class JsArray extends JsObject
{
    private int $length = 0;
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
            $this->set((string) $index, $element);
        }

        $this->length = count($elements);
        $this->installSymbolIterator();
    }

    /** Install Symbol.iterator so arrays support the iterator protocol. */
    private function installSymbolIterator(): void
    {
        $array = $this;
        $iterSym = \PhpJs\BuiltIn\SymbolConstructor::iterator();
        $factory = function () use ($array, $iterSym): JsValue {
            $index = 0;
            $iterator = new JsObject();
            $nextFn = function () use ($array, &$index): JsValue {
                $result = new JsObject();
                if ($index < $array->getLength()) {
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
            // Iterators are also iterables: [Symbol.iterator]() returns this.
            $iterator->setBySymbol($iterSym, JsFunction::fromCallable('[Symbol.iterator]', function (JsValue $self_): JsValue {
                return $self_;
            }));
            return $iterator;
        };
        $iteratorFn = JsFunction::fromCallable('[Symbol.iterator]', $factory);
        $this->setBySymbol($iterSym, $iteratorFn);
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function setLength(int $length): void
    {
        $this->length = $length;
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
        for ($i = 0; $i < $this->length; $i++) {
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

    public function set(string $name, JsValue $value, bool $strict = false): void
    {
        if ($name === 'length') {
            $this->length = (int) $value->toNumber();
            return;
        }

        parent::set($name, $value, $strict);

        if (ctype_digit($name)) {
            $index = (int) $name;
            if ($index >= $this->length) {
                $this->length = $index + 1;
            }
        }
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
