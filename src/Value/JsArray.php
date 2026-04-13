<?php

declare(strict_types=1);

namespace PhpJs\Value;

class JsArray extends JsObject
{
    private int $length = 0;

    /**
     * @param list<JsValue> $elements
     */
    public function __construct(array $elements = [], ?JsObject $prototype = null)
    {
        parent::__construct($prototype);

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
        $factory = function () use ($array): JsValue {
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
            return $iterator;
        };
        $iteratorFn = JsFunction::fromCallable('[Symbol.iterator]', $factory);
        $iterSym = \PhpJs\BuiltIn\SymbolConstructor::iterator();
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

        // Check own properties first (includes built-in methods set on prototype)
        $own = parent::get($name);
        if (!$own instanceof JsUndefined) {
            return $own;
        }

        // Built-in array methods
        return match ($name) {
            'push' => $this->getArrayMethod('push'),
            'pop' => $this->getArrayMethod('pop'),
            'shift' => $this->getArrayMethod('shift'),
            'unshift' => $this->getArrayMethod('unshift'),
            'indexOf' => $this->getArrayMethod('indexOf'),
            'includes' => $this->getArrayMethod('includes'),
            'join' => $this->getArrayMethod('join'),
            'slice' => $this->getArrayMethod('slice'),
            'concat' => $this->getArrayMethod('concat'),
            'reverse' => $this->getArrayMethod('reverse'),
            'map' => $this->getArrayMethod('map'),
            'filter' => $this->getArrayMethod('filter'),
            'reduce' => $this->getArrayMethod('reduce'),
            'forEach' => $this->getArrayMethod('forEach'),
            'find' => $this->getArrayMethod('find'),
            'findIndex' => $this->getArrayMethod('findIndex'),
            'some' => $this->getArrayMethod('some'),
            'every' => $this->getArrayMethod('every'),
            'flat' => $this->getArrayMethod('flat'),
            'fill' => $this->getArrayMethod('fill'),
            'splice' => $this->getArrayMethod('splice'),
            'sort' => $this->getArrayMethod('sort'),
            default => JsUndefined::instance(),
        };
    }

    private function getArrayMethod(string $name): JsFunction
    {
        return JsFunction::fromCallable($name, match ($name) {
            'push' => function (JsValue $this_, array $args): JsValue {
                foreach ($args as $arg) {
                    $this->push($arg);
                }
                return new JsNumber((float) $this->length);
            },
            'pop' => function (): JsValue {
                if ($this->length === 0) {
                    return JsUndefined::instance();
                }
                $this->length--;
                $val = parent::get((string) $this->length);
                $this->delete((string) $this->length);
                return $val;
            },
            'shift' => function (): JsValue {
                if ($this->length === 0) {
                    return JsUndefined::instance();
                }
                $first = parent::get('0');
                for ($i = 1; $i < $this->length; $i++) {
                    parent::set((string) ($i - 1), parent::get((string) $i));
                }
                $this->length--;
                $this->delete((string) $this->length);
                return $first;
            },
            'unshift' => function (JsValue $this_, array $args): JsValue {
                $count = count($args);
                for ($i = $this->length - 1; $i >= 0; $i--) {
                    parent::set((string) ($i + $count), parent::get((string) $i));
                }
                foreach ($args as $i => $arg) {
                    parent::set((string) $i, $arg);
                }
                $this->length += $count;
                return new JsNumber((float) $this->length);
            },
            'indexOf' => function (JsValue $this_, array $args): JsValue {
                $search = $args[0] ?? JsUndefined::instance();
                $from = isset($args[1]) ? (int) $args[1]->toNumber() : 0;
                for ($i = $from; $i < $this->length; $i++) {
                    if (\PhpJs\Spec\AbstractOperations::strictEquals(parent::get((string) $i), $search)) {
                        return new JsNumber((float) $i);
                    }
                }
                return new JsNumber(-1.0);
            },
            'includes' => function (JsValue $this_, array $args): JsValue {
                $search = $args[0] ?? JsUndefined::instance();
                for ($i = 0; $i < $this->length; $i++) {
                    if (\PhpJs\Spec\AbstractOperations::strictEquals(parent::get((string) $i), $search)) {
                        return new JsBoolean(true);
                    }
                }
                return new JsBoolean(false);
            },
            'join' => function (JsValue $this_, array $args): JsValue {
                $sep = isset($args[0]) && !$args[0] instanceof JsUndefined
                    ? \PhpJs\Spec\TypeConversion::toString($args[0]) : ',';
                $parts = [];
                for ($i = 0; $i < $this->length; $i++) {
                    $v = parent::get((string) $i);
                    $parts[] = ($v instanceof JsUndefined || $v instanceof JsNull)
                        ? '' : \PhpJs\Spec\TypeConversion::toString($v);
                }
                return new JsString(implode($sep, $parts));
            },
            'slice' => function (JsValue $this_, array $args): JsValue {
                $start = isset($args[0]) ? (int) $args[0]->toNumber() : 0;
                $end = isset($args[1]) ? (int) $args[1]->toNumber() : $this->length;
                if ($start < 0) {
                    $start = max(0, $this->length + $start);
                }
                if ($end < 0) {
                    $end = max(0, $this->length + $end);
                }
                $result = [];
                for ($i = $start; $i < $end && $i < $this->length; $i++) {
                    $result[] = parent::get((string) $i);
                }
                return self::fromArray($result);
            },
            'concat' => function (JsValue $this_, array $args): JsValue {
                $result = $this->toList();
                foreach ($args as $arg) {
                    if ($arg instanceof JsArray) {
                        for ($i = 0; $i < $arg->getLength(); $i++) {
                            $result[] = $arg->get((string) $i);
                        }
                    } else {
                        $result[] = $arg;
                    }
                }
                return self::fromArray($result);
            },
            'reverse' => function (): JsValue {
                $items = $this->toList();
                $items = array_reverse($items);
                for ($i = 0; $i < $this->length; $i++) {
                    parent::set((string) $i, $items[$i]);
                }
                return $this;
            },
            'map' => $this->higherOrder('map'),
            'filter' => $this->higherOrder('filter'),
            'reduce' => $this->higherOrder('reduce'),
            'forEach' => $this->higherOrder('forEach'),
            'find' => $this->higherOrder('find'),
            'findIndex' => $this->higherOrder('findIndex'),
            'some' => $this->higherOrder('some'),
            'every' => $this->higherOrder('every'),
            default => fn() => JsUndefined::instance(),
        });
    }

    private function higherOrder(string $name): \Closure
    {
        return function (JsValue $this_, array $args) use ($name): JsValue {
            $callback = $args[0] ?? null;
            if (!$callback instanceof JsFunction) {
                throw new \PhpJs\Exceptions\TypeError("{$name} callback is not a function");
            }

            return match ($name) {
                'map' => $this->arrayMap($callback),
                'filter' => $this->arrayFilter($callback),
                'reduce' => $this->arrayReduce($callback, $args[1] ?? null),
                'forEach' => $this->arrayForEach($callback),
                'find' => $this->arrayFind($callback),
                'findIndex' => $this->arrayFindIndex($callback),
                'some' => $this->arraySome($callback),
                'every' => $this->arrayEvery($callback),
                default => JsUndefined::instance(),
            };
        };
    }

    private function arrayMap(JsFunction $fn): JsArray
    {
        $result = [];
        for ($i = 0; $i < $this->length; $i++) {
            $result[] = $fn->call($this, [parent::get((string) $i), new JsNumber((float) $i), $this]);
        }
        return self::fromArray($result);
    }

    private function arrayFilter(JsFunction $fn): JsArray
    {
        $result = [];
        for ($i = 0; $i < $this->length; $i++) {
            $val = parent::get((string) $i);
            $keep = $fn->call($this, [$val, new JsNumber((float) $i), $this]);
            if (\PhpJs\Spec\TypeConversion::toBoolean($keep)) {
                $result[] = $val;
            }
        }
        return self::fromArray($result);
    }

    private function arrayReduce(JsFunction $fn, ?JsValue $initial): JsValue
    {
        $acc = $initial;
        $start = 0;
        if ($acc === null) {
            if ($this->length === 0) {
                throw new \PhpJs\Exceptions\TypeError('Reduce of empty array with no initial value');
            }
            $acc = parent::get('0');
            $start = 1;
        }
        for ($i = $start; $i < $this->length; $i++) {
            $acc = $fn->call(JsUndefined::instance(), [$acc, parent::get((string) $i), new JsNumber((float) $i), $this]);
        }
        return $acc;
    }

    private function arrayForEach(JsFunction $fn): JsValue
    {
        for ($i = 0; $i < $this->length; $i++) {
            $fn->call($this, [parent::get((string) $i), new JsNumber((float) $i), $this]);
        }
        return JsUndefined::instance();
    }

    private function arrayFind(JsFunction $fn): JsValue
    {
        for ($i = 0; $i < $this->length; $i++) {
            $val = parent::get((string) $i);
            $result = $fn->call($this, [$val, new JsNumber((float) $i), $this]);
            if (\PhpJs\Spec\TypeConversion::toBoolean($result)) {
                return $val;
            }
        }
        return JsUndefined::instance();
    }

    private function arrayFindIndex(JsFunction $fn): JsNumber
    {
        for ($i = 0; $i < $this->length; $i++) {
            $val = parent::get((string) $i);
            $result = $fn->call($this, [$val, new JsNumber((float) $i), $this]);
            if (\PhpJs\Spec\TypeConversion::toBoolean($result)) {
                return new JsNumber((float) $i);
            }
        }
        return new JsNumber(-1.0);
    }

    private function arraySome(JsFunction $fn): JsBoolean
    {
        for ($i = 0; $i < $this->length; $i++) {
            $result = $fn->call($this, [parent::get((string) $i), new JsNumber((float) $i), $this]);
            if (\PhpJs\Spec\TypeConversion::toBoolean($result)) {
                return new JsBoolean(true);
            }
        }
        return new JsBoolean(false);
    }

    private function arrayEvery(JsFunction $fn): JsBoolean
    {
        for ($i = 0; $i < $this->length; $i++) {
            $result = $fn->call($this, [parent::get((string) $i), new JsNumber((float) $i), $this]);
            if (!\PhpJs\Spec\TypeConversion::toBoolean($result)) {
                return new JsBoolean(false);
            }
        }
        return new JsBoolean(true);
    }

    public function set(string $name, JsValue $value): void
    {
        if ($name === 'length') {
            $this->length = (int) $value->toNumber();
            return;
        }

        parent::set($name, $value);

        if (ctype_digit($name)) {
            $index = (int) $name;
            if ($index >= $this->length) {
                $this->length = $index + 1;
            }
        }
    }
}
