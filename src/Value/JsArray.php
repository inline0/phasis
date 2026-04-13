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
            'lastIndexOf' => $this->getArrayMethod('lastIndexOf'),
            'includes' => $this->getArrayMethod('includes'),
            'join' => $this->getArrayMethod('join'),
            'slice' => $this->getArrayMethod('slice'),
            'concat' => $this->getArrayMethod('concat'),
            'reverse' => $this->getArrayMethod('reverse'),
            'map' => $this->getArrayMethod('map'),
            'filter' => $this->getArrayMethod('filter'),
            'reduce' => $this->getArrayMethod('reduce'),
            'reduceRight' => $this->getArrayMethod('reduceRight'),
            'forEach' => $this->getArrayMethod('forEach'),
            'find' => $this->getArrayMethod('find'),
            'findIndex' => $this->getArrayMethod('findIndex'),
            'some' => $this->getArrayMethod('some'),
            'every' => $this->getArrayMethod('every'),
            'flat' => $this->getArrayMethod('flat'),
            'flatMap' => $this->getArrayMethod('flatMap'),
            'fill' => $this->getArrayMethod('fill'),
            'copyWithin' => $this->getArrayMethod('copyWithin'),
            'splice' => $this->getArrayMethod('splice'),
            'sort' => $this->getArrayMethod('sort'),
            'at' => $this->getArrayMethod('at'),
            'toString' => $this->getArrayMethod('toString'),
            'keys' => $this->getArrayMethod('keys'),
            'values' => $this->getArrayMethod('values'),
            'entries' => $this->getArrayMethod('entries'),
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
            'reduceRight' => $this->higherOrder('reduceRight'),
            'forEach' => $this->higherOrder('forEach'),
            'find' => $this->higherOrder('find'),
            'findIndex' => $this->higherOrder('findIndex'),
            'some' => $this->higherOrder('some'),
            'every' => $this->higherOrder('every'),
            'flatMap' => $this->higherOrder('flatMap'),
            'sort' => function (JsValue $this_, array $args): JsValue {
                $compareFn = ($args[0] ?? null) instanceof JsFunction ? $args[0] : null;
                $items = $this->toList();
                usort($items, function (JsValue $a, JsValue $b) use ($compareFn): int {
                    // undefined values sort to the end.
                    $aIsUndef = $a instanceof JsUndefined;
                    $bIsUndef = $b instanceof JsUndefined;
                    if ($aIsUndef && $bIsUndef) {
                        return 0;
                    }
                    if ($aIsUndef) {
                        return 1;
                    }
                    if ($bIsUndef) {
                        return -1;
                    }
                    if ($compareFn !== null) {
                        $result = $compareFn->call(JsUndefined::instance(), [$a, $b]);
                        $num = \PhpJs\Spec\TypeConversion::toNumber($result);
                        if (is_nan($num)) {
                            return 0;
                        }
                        if ($num < 0) {
                            return -1;
                        }
                        if ($num > 0) {
                            return 1;
                        }
                        return 0;
                    }
                    // Default: compare as strings.
                    $sa = \PhpJs\Spec\TypeConversion::toString($a);
                    $sb = \PhpJs\Spec\TypeConversion::toString($b);
                    return strcmp($sa, $sb);
                });
                for ($i = 0; $i < $this->length; $i++) {
                    parent::set((string) $i, $items[$i]);
                }
                return $this;
            },
            'flat' => function (JsValue $this_, array $args): JsValue {
                $depthVal = $args[0] ?? JsUndefined::instance();
                $depth = $depthVal instanceof JsUndefined ? 1 : (int) \PhpJs\Spec\TypeConversion::toNumber($depthVal);
                $result = self::flattenArray($this, $depth);
                return self::fromArray($result);
            },
            'fill' => function (JsValue $this_, array $args): JsValue {
                $value = $args[0] ?? JsUndefined::instance();
                $start = isset($args[1]) ? (int) \PhpJs\Spec\TypeConversion::toNumber($args[1]) : 0;
                $end = isset($args[2]) ? (int) \PhpJs\Spec\TypeConversion::toNumber($args[2]) : $this->length;
                if ($start < 0) {
                    $start = max($this->length + $start, 0);
                }
                if ($end < 0) {
                    $end = max($this->length + $end, 0);
                }
                $start = min($start, $this->length);
                $end = min($end, $this->length);
                for ($i = $start; $i < $end; $i++) {
                    parent::set((string) $i, $value);
                }
                return $this;
            },
            'copyWithin' => function (JsValue $this_, array $args): JsValue {
                $target = isset($args[0]) ? (int) \PhpJs\Spec\TypeConversion::toNumber($args[0]) : 0;
                $start = isset($args[1]) ? (int) \PhpJs\Spec\TypeConversion::toNumber($args[1]) : 0;
                $end = isset($args[2]) ? (int) \PhpJs\Spec\TypeConversion::toNumber($args[2]) : $this->length;
                $len = $this->length;
                if ($target < 0) {
                    $target = max($len + $target, 0);
                }
                if ($start < 0) {
                    $start = max($len + $start, 0);
                }
                if ($end < 0) {
                    $end = max($len + $end, 0);
                }
                $target = min($target, $len);
                $start = min($start, $len);
                $end = min($end, $len);
                $count = min($end - $start, $len - $target);
                // Copy in correct direction to handle overlapping ranges.
                if ($start < $target && $target < $start + $count) {
                    for ($i = $count - 1; $i >= 0; $i--) {
                        parent::set((string) ($target + $i), parent::get((string) ($start + $i)));
                    }
                } else {
                    for ($i = 0; $i < $count; $i++) {
                        parent::set((string) ($target + $i), parent::get((string) ($start + $i)));
                    }
                }
                return $this;
            },
            'splice' => function (JsValue $this_, array $args): JsValue {
                $len = $this->length;
                $start = isset($args[0]) ? (int) \PhpJs\Spec\TypeConversion::toNumber($args[0]) : 0;
                if ($start < 0) {
                    $start = max($len + $start, 0);
                } else {
                    $start = min($start, $len);
                }
                $deleteCount = isset($args[1])
                    ? max(0, (int) \PhpJs\Spec\TypeConversion::toNumber($args[1]))
                    : $len - $start;
                $deleteCount = min($deleteCount, $len - $start);
                $insertItems = array_slice($args, 2);

                // Collect removed elements.
                $removed = [];
                for ($i = 0; $i < $deleteCount; $i++) {
                    $removed[] = parent::get((string) ($start + $i));
                }

                $insertCount = count($insertItems);
                $diff = $insertCount - $deleteCount;

                if ($diff > 0) {
                    // Shift elements right.
                    for ($i = $len - 1; $i >= $start + $deleteCount; $i--) {
                        parent::set((string) ($i + $diff), parent::get((string) $i));
                    }
                } elseif ($diff < 0) {
                    // Shift elements left.
                    for ($i = $start + $deleteCount; $i < $len; $i++) {
                        parent::set((string) ($i + $diff), parent::get((string) $i));
                    }
                    // Delete trailing slots.
                    for ($i = $len + $diff; $i < $len; $i++) {
                        $this->delete((string) $i);
                    }
                }

                // Insert new items.
                foreach ($insertItems as $idx => $item) {
                    parent::set((string) ($start + $idx), $item);
                }

                $this->length = $len + $diff;
                return self::fromArray($removed);
            },
            'at' => function (JsValue $this_, array $args): JsValue {
                $index = isset($args[0]) ? (int) \PhpJs\Spec\TypeConversion::toNumber($args[0]) : 0;
                if ($index < 0) {
                    $index = $this->length + $index;
                }
                if ($index < 0 || $index >= $this->length) {
                    return JsUndefined::instance();
                }
                return parent::get((string) $index);
            },
            'toString' => function (): JsValue {
                return new JsString($this->toJsString());
            },
            'lastIndexOf' => function (JsValue $this_, array $args): JsValue {
                $search = $args[0] ?? JsUndefined::instance();
                $from = isset($args[1]) ? (int) $args[1]->toNumber() : $this->length - 1;
                if ($from < 0) {
                    $from = $this->length + $from;
                }
                for ($i = min($from, $this->length - 1); $i >= 0; $i--) {
                    if (\PhpJs\Spec\AbstractOperations::strictEquals(parent::get((string) $i), $search)) {
                        return new JsNumber((float) $i);
                    }
                }
                return new JsNumber(-1.0);
            },
            'keys' => function (): JsValue {
                return $this->createArrayIterator('key');
            },
            'values' => function (): JsValue {
                return $this->createArrayIterator('value');
            },
            'entries' => function (): JsValue {
                return $this->createArrayIterator('key+value');
            },
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
                'reduceRight' => $this->arrayReduceRight($callback, $args[1] ?? null),
                'forEach' => $this->arrayForEach($callback),
                'find' => $this->arrayFind($callback),
                'findIndex' => $this->arrayFindIndex($callback),
                'some' => $this->arraySome($callback),
                'every' => $this->arrayEvery($callback),
                'flatMap' => $this->arrayFlatMap($callback),
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

    private function arrayReduceRight(JsFunction $fn, ?JsValue $initial): JsValue
    {
        $acc = $initial;
        $start = $this->length - 1;
        if ($acc === null) {
            if ($this->length === 0) {
                throw new \PhpJs\Exceptions\TypeError('Reduce of empty array with no initial value');
            }
            $acc = parent::get((string) $start);
            $start--;
        }
        for ($i = $start; $i >= 0; $i--) {
            $val = parent::get((string) $i);
            $idx = new JsNumber((float) $i);
            $acc = $fn->call(JsUndefined::instance(), [$acc, $val, $idx, $this]);
        }
        return $acc;
    }

    private function arrayFlatMap(JsFunction $fn): JsArray
    {
        $result = [];
        for ($i = 0; $i < $this->length; $i++) {
            $val = parent::get((string) $i);
            $mapped = $fn->call($this, [$val, new JsNumber((float) $i), $this]);
            if ($mapped instanceof JsArray) {
                for ($j = 0; $j < $mapped->getLength(); $j++) {
                    $result[] = $mapped->get((string) $j);
                }
            } else {
                $result[] = $mapped;
            }
        }
        return self::fromArray($result);
    }

    /**
     * Recursively flatten an array to the given depth.
     *
     * @return list<JsValue>
     */
    private static function flattenArray(JsArray $array, int $depth): array
    {
        $result = [];
        for ($i = 0; $i < $array->getLength(); $i++) {
            $element = $array->get((string) $i);
            if ($element instanceof JsArray && $depth > 0) {
                $flattened = self::flattenArray($element, $depth - 1);
                foreach ($flattened as $item) {
                    $result[] = $item;
                }
            } else {
                $result[] = $element;
            }
        }
        return $result;
    }

    /** Create an iterator object for keys, values, or entries. */
    private function createArrayIterator(string $kind): JsObject
    {
        $array = $this;
        $index = 0;
        $iterator = new JsObject();
        $nextFn = function () use ($array, &$index, $kind): JsValue {
            $result = new JsObject();
            if ($index < $array->getLength()) {
                $key = new JsNumber((float) $index);
                $value = $array->get((string) $index);
                $index++;
                $result->set('done', new JsBoolean(false));
                $result->set('value', match ($kind) {
                    'key' => $key,
                    'value' => $value,
                    'key+value' => JsArray::fromArray([$key, $value]),
                    default => $value,
                });
            } else {
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
            }
            return $result;
        };
        $iterator->set('next', JsFunction::fromCallable('next', $nextFn));
        // Make the iterator itself iterable.
        $iterSym = \PhpJs\BuiltIn\SymbolConstructor::iterator();
        $iterFn = JsFunction::fromCallable(
            '[Symbol.iterator]',
            function () use ($iterator): JsValue {
                return $iterator;
            },
        );
        $iterator->setBySymbol($iterSym, $iterFn);
        return $iterator;
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
