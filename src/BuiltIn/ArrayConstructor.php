<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\TypeError;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\AbstractOperations;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsArray;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsSymbol;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;
use PhpJs\Object\PropertyDescriptor;

class ArrayConstructor
{
    public static function install(Environment $env): void
    {
        $constructor = JsFunction::fromCallable('Array', function (JsValue $this_, array $args): JsValue {
            if (count($args) === 1 && $args[0] instanceof JsNumber) {
                $len = (int) $args[0]->value;
                $arr = new JsArray();
                $arr->setLength($len);
                return $arr;
            }
            return JsArray::fromArray($args);
        });

        // Static methods.
        $constructor->set('isArray', JsFunction::fromCallable('isArray', self::isArray(), 1));
        $constructor->set('from', JsFunction::fromCallable('from', self::from(), 1));
        $constructor->set('of', JsFunction::fromCallable('of', self::of(), 0));

        // Array.prototype with all standard methods.
        $proto = new JsArray();
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));
        self::installPrototypeMethods($proto);

        $constructor->set('prototype', $proto);
        JsArray::setGlobalPrototype($proto);

        $env->defineVar('Array', $constructor);
    }

    /**
     * Get length from array or array-like object.
     */
    private static function getLen(JsValue $obj): int
    {
        if ($obj instanceof JsArray) {
            return $obj->getLength();
        }
        if ($obj instanceof JsObject) {
            return (int) TypeConversion::toNumber($obj->get('length'));
        }
        return 0;
    }

    /**
     * Ensure $this_ is an object (per spec: ToObject).
     */
    private static function toObj(JsValue $this_): JsObject
    {
        if ($this_ instanceof JsObject) {
            return $this_;
        }
        return TypeConversion::toObject($this_);
    }

    /** @return list<JsValue> */
    private static function objToList(JsObject $obj): array
    {
        $result = [];
        $len = self::getLen($obj);
        for ($i = 0; $i < $len; $i++) {
            $result[] = $obj->get((string) $i);
        }
        return $result;
    }

    private static function installPrototypeMethods(JsArray $proto): void
    {
        $proto->defineOwnProperty('push', PropertyDescriptor::data(JsFunction::fromCallable(
            'push',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                foreach ($args as $arg) {
                    if ($this_ instanceof JsArray) {
                        $this_->push($arg);
                    } else {
                        $idx = self::getLen($this_);
                        $this_->set((string) $idx, $arg);
                        $this_->set("length", new JsNumber((float) ($idx + 1)));
                    }
                }
                return new JsNumber((float) self::getLen($this_));
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('pop', PropertyDescriptor::data(JsFunction::fromCallable(
            'pop',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                if (self::getLen($this_) === 0) {
                    return JsUndefined::instance();
                }
                $newLen = self::getLen($this_) - 1;
                $val = $this_->get((string) $newLen);
                $this_->delete((string) $newLen);
                if ($this_ instanceof JsArray) {
                    $this_->setLength($newLen);
                } else {
                    $this_->set("length", new JsNumber((float) ($newLen)));
                }
                return $val;
            },
            0
        ), true, false, true));

        $proto->defineOwnProperty('shift', PropertyDescriptor::data(JsFunction::fromCallable(
            'shift',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $len = self::getLen($this_);
                if ($len === 0) {
                    return JsUndefined::instance();
                }
                $first = $this_->get('0');
                for ($i = 1; $i < $len; $i++) {
                    $this_->set((string) ($i - 1), $this_->get((string) $i));
                }
                $this_->delete((string) ($len - 1));
                if ($this_ instanceof JsArray) {
                    $this_->setLength($len - 1);
                } else {
                    $this_->set("length", new JsNumber((float) ($len - 1)));
                }
                return $first;
            },
            0
        ), true, false, true));

        $proto->defineOwnProperty('unshift', PropertyDescriptor::data(JsFunction::fromCallable(
            'unshift',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $len = self::getLen($this_);
                $count = count($args);
                for ($i = $len - 1; $i >= 0; $i--) {
                    $this_->set((string) ($i + $count), $this_->get((string) $i));
                }
                foreach ($args as $i => $arg) {
                    $this_->set((string) $i, $arg);
                }
                if ($this_ instanceof JsArray) {
                    $this_->setLength($len + $count);
                } else {
                    $this_->set("length", new JsNumber((float) ($len + $count)));
                }
                return new JsNumber((float) ($len + $count));
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('indexOf', PropertyDescriptor::data(JsFunction::fromCallable(
            'indexOf',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $search = $args[0] ?? JsUndefined::instance();
                $from = isset($args[1]) ? (int) $args[1]->toNumber() : 0;
                $len = self::getLen($this_);
                for ($i = $from; $i < $len; $i++) {
                    if (AbstractOperations::strictEquals($this_->get((string) $i), $search)) {
                        return new JsNumber((float) $i);
                    }
                }
                return new JsNumber(-1.0);
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('lastIndexOf', PropertyDescriptor::data(JsFunction::fromCallable(
            'lastIndexOf',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $search = $args[0] ?? JsUndefined::instance();
                $len = self::getLen($this_);
                $from = isset($args[1]) ? (int) $args[1]->toNumber() : $len - 1;
                if ($from < 0) {
                    $from = $len + $from;
                }
                for ($i = min($from, $len - 1); $i >= 0; $i--) {
                    if (AbstractOperations::strictEquals($this_->get((string) $i), $search)) {
                        return new JsNumber((float) $i);
                    }
                }
                return new JsNumber(-1.0);
            },
            1,
        ), true, false, true));

        $proto->defineOwnProperty('includes', PropertyDescriptor::data(JsFunction::fromCallable(
            'includes',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $search = $args[0] ?? JsUndefined::instance();
                $len = self::getLen($this_);
                for ($i = 0; $i < $len; $i++) {
                    if (AbstractOperations::strictEquals($this_->get((string) $i), $search)) {
                        return new JsBoolean(true);
                    }
                }
                return new JsBoolean(false);
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('join', PropertyDescriptor::data(JsFunction::fromCallable(
            'join',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return new JsString('');
                }
                $sep = isset($args[0]) && !$args[0] instanceof JsUndefined
                ? TypeConversion::toString($args[0]) : ',';
                $parts = [];
                $len = self::getLen($this_);
                for ($i = 0; $i < $len; $i++) {
                    $v = $this_->get((string) $i);
                    $parts[] = ($v instanceof JsUndefined || $v instanceof JsNull)
                    ? '' : TypeConversion::toString($v);
                }
                return new JsString(implode($sep, $parts));
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('slice', PropertyDescriptor::data(JsFunction::fromCallable(
            'slice',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $len = self::getLen($this_);
                $start = isset($args[0]) ? (int) $args[0]->toNumber() : 0;
                $end = isset($args[1]) ? (int) $args[1]->toNumber() : $len;
                if ($start < 0) {
                    $start = max(0, $len + $start);
                }
                if ($end < 0) {
                    $end = max(0, $len + $end);
                }
                $result = [];
                for ($i = $start; $i < $end && $i < $len; $i++) {
                    $result[] = $this_->get((string) $i);
                }
                return JsArray::fromArray($result);
            },
            2
        ), true, false, true));

        $proto->defineOwnProperty('concat', PropertyDescriptor::data(JsFunction::fromCallable(
            'concat',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $result = ($this_ instanceof JsArray ? $this_->toList() : self::objToList($this_));
                foreach ($args as $arg) {
                    if ($arg instanceof JsArray) {
                        for ($i = 0; $i < $arg->getLength(); $i++) {
                            $result[] = $arg->get((string) $i);
                        }
                    } else {
                        $result[] = $arg;
                    }
                }
                return JsArray::fromArray($result);
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('reverse', PropertyDescriptor::data(JsFunction::fromCallable(
            'reverse',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $items = ($this_ instanceof JsArray ? $this_->toList() : self::objToList($this_));
                $items = array_reverse($items);
                $len = self::getLen($this_);
                for ($i = 0; $i < $len; $i++) {
                    $this_->set((string) $i, $items[$i]);
                }
                return $this_;
            },
            0
        ), true, false, true));

        $proto->defineOwnProperty('map', PropertyDescriptor::data(JsFunction::fromCallable(
            'map',
            function (JsValue $this_, array $args): JsValue {
                $obj = self::toObj($this_);
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('map callback is not a function');
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined) ? $args[1] : JsUndefined::instance();
                $result = [];
                $len = self::getLen($obj);
                for ($i = 0; $i < $len; $i++) {
                    $val = $obj->get((string) $i);
                    $result[] = $callback->call($thisArg, [$val, new JsNumber((float) $i), $obj]);
                }
                return JsArray::fromArray($result);
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('filter', PropertyDescriptor::data(JsFunction::fromCallable(
            'filter',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('filter callback is not a function');
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined) ? $args[1] : JsUndefined::instance();
                $result = [];
                $len = self::getLen($this_);
                for ($i = 0; $i < $len; $i++) {
                    $val = $this_->get((string) $i);
                    $keep = $callback->call($thisArg, [$val, new JsNumber((float) $i), $this_]);
                    if (TypeConversion::toBoolean($keep)) {
                        $result[] = $val;
                    }
                }
                return JsArray::fromArray($result);
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('reduce', PropertyDescriptor::data(JsFunction::fromCallable(
            'reduce',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('reduce callback is not a function');
                }
                $len = self::getLen($this_);
                $initial = $args[1] ?? null;
                $acc = $initial;
                $start = 0;
                if ($acc === null) {
                    if ($len === 0) {
                        throw new TypeError('Reduce of empty array with no initial value');
                    }
                    $acc = $this_->get('0');
                    $start = 1;
                }
                for ($i = $start; $i < $len; $i++) {
                    $acc = $callback->call(
                        JsUndefined::instance(),
                        [$acc, $this_->get((string) $i), new JsNumber((float) $i), $this_],
                    );
                }
                return $acc;
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('reduceRight', PropertyDescriptor::data(JsFunction::fromCallable(
            'reduceRight',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('reduceRight callback is not a function');
                }
                $len = self::getLen($this_);
                $initial = $args[1] ?? null;
                $acc = $initial;
                $start = $len - 1;
                if ($acc === null) {
                    if ($len === 0) {
                        throw new TypeError('Reduce of empty array with no initial value');
                    }
                    $acc = $this_->get((string) $start);
                    $start--;
                }
                for ($i = $start; $i >= 0; $i--) {
                    $val = $this_->get((string) $i);
                    $idx = new JsNumber((float) $i);
                    $acc = $callback->call(JsUndefined::instance(), [$acc, $val, $idx, $this_]);
                }
                return $acc;
            },
            1,
        ), true, false, true));

        $proto->defineOwnProperty('forEach', PropertyDescriptor::data(JsFunction::fromCallable(
            'forEach',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('forEach callback is not a function');
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined) ? $args[1] : JsUndefined::instance();
                $len = self::getLen($this_);
                for ($i = 0; $i < $len; $i++) {
                    $callback->call($thisArg, [$this_->get((string) $i), new JsNumber((float) $i), $this_]);
                }
                return JsUndefined::instance();
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('find', PropertyDescriptor::data(JsFunction::fromCallable(
            'find',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('find callback is not a function');
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined) ? $args[1] : JsUndefined::instance();
                $len = self::getLen($this_);
                for ($i = 0; $i < $len; $i++) {
                    $val = $this_->get((string) $i);
                    $result = $callback->call($thisArg, [$val, new JsNumber((float) $i), $this_]);
                    if (TypeConversion::toBoolean($result)) {
                        return $val;
                    }
                }
                return JsUndefined::instance();
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('findIndex', PropertyDescriptor::data(JsFunction::fromCallable(
            'findIndex',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('findIndex callback is not a function');
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined) ? $args[1] : JsUndefined::instance();
                $len = self::getLen($this_);
                for ($i = 0; $i < $len; $i++) {
                    $val = $this_->get((string) $i);
                    $result = $callback->call($thisArg, [$val, new JsNumber((float) $i), $this_]);
                    if (TypeConversion::toBoolean($result)) {
                        return new JsNumber((float) $i);
                    }
                }
                return new JsNumber(-1.0);
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('some', PropertyDescriptor::data(JsFunction::fromCallable(
            'some',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('some callback is not a function');
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined) ? $args[1] : JsUndefined::instance();
                $len = self::getLen($this_);
                for ($i = 0; $i < $len; $i++) {
                    $result = $callback->call($thisArg, [$this_->get((string) $i), new JsNumber((float) $i), $this_]);
                    if (TypeConversion::toBoolean($result)) {
                        return new JsBoolean(true);
                    }
                }
                return new JsBoolean(false);
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('every', PropertyDescriptor::data(JsFunction::fromCallable(
            'every',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('every callback is not a function');
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined) ? $args[1] : JsUndefined::instance();
                $len = self::getLen($this_);
                for ($i = 0; $i < $len; $i++) {
                    $result = $callback->call($thisArg, [$this_->get((string) $i), new JsNumber((float) $i), $this_]);
                    if (!TypeConversion::toBoolean($result)) {
                        return new JsBoolean(false);
                    }
                }
                return new JsBoolean(true);
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('flat', PropertyDescriptor::data(JsFunction::fromCallable(
            'flat',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $depthVal = $args[0] ?? JsUndefined::instance();
                $depth = $depthVal instanceof JsUndefined ? 1 : (int) TypeConversion::toNumber($depthVal);
                $result = self::flattenArray($this_, $depth);
                return JsArray::fromArray($result);
            },
            0
        ), true, false, true));

        $proto->defineOwnProperty('flatMap', PropertyDescriptor::data(JsFunction::fromCallable(
            'flatMap',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('flatMap callback is not a function');
                }
                $result = [];
                $len = self::getLen($this_);
                for ($i = 0; $i < $len; $i++) {
                    $val = $this_->get((string) $i);
                    $mapped = $callback->call($this_, [$val, new JsNumber((float) $i), $this_]);
                    if ($mapped instanceof JsArray) {
                        for ($j = 0; $j < $mapped->getLength(); $j++) {
                            $result[] = $mapped->get((string) $j);
                        }
                    } else {
                        $result[] = $mapped;
                    }
                }
                return JsArray::fromArray($result);
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('fill', PropertyDescriptor::data(JsFunction::fromCallable(
            'fill',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $len = self::getLen($this_);
                $value = $args[0] ?? JsUndefined::instance();
                $start = isset($args[1]) ? (int) TypeConversion::toNumber($args[1]) : 0;
                $end = isset($args[2]) ? (int) TypeConversion::toNumber($args[2]) : $len;
                if ($start < 0) {
                    $start = max($len + $start, 0);
                }
                if ($end < 0) {
                    $end = max($len + $end, 0);
                }
                $start = min($start, $len);
                $end = min($end, $len);
                for ($i = $start; $i < $end; $i++) {
                    $this_->set((string) $i, $value);
                }
                return $this_;
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('copyWithin', PropertyDescriptor::data(JsFunction::fromCallable(
            'copyWithin',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $len = self::getLen($this_);
                $target = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
                $start = isset($args[1]) ? (int) TypeConversion::toNumber($args[1]) : 0;
                $end = isset($args[2]) ? (int) TypeConversion::toNumber($args[2]) : $len;
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
                        $this_->set(
                            (string) ($target + $i),
                            $this_->get((string) ($start + $i)),
                        );
                    }
                } else {
                    for ($i = 0; $i < $count; $i++) {
                        $this_->set(
                            (string) ($target + $i),
                            $this_->get((string) ($start + $i)),
                        );
                    }
                }
                return $this_;
            },
            2,
        ), true, false, true));

        $proto->defineOwnProperty('splice', PropertyDescriptor::data(JsFunction::fromCallable(
            'splice',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $len = self::getLen($this_);
                $start = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
                if ($start < 0) {
                    $start = max($len + $start, 0);
                } else {
                    $start = min($start, $len);
                }
                $deleteCount = isset($args[1])
                ? max(0, (int) TypeConversion::toNumber($args[1]))
                : $len - $start;
                $deleteCount = min($deleteCount, $len - $start);
                $insertItems = array_slice($args, 2);

            // Collect removed elements.
                $removed = [];
                for ($i = 0; $i < $deleteCount; $i++) {
                    $removed[] = $this_->get((string) ($start + $i));
                }

                $insertCount = count($insertItems);
                $diff = $insertCount - $deleteCount;

                if ($diff > 0) {
                    // Shift elements right.
                    for ($i = $len - 1; $i >= $start + $deleteCount; $i--) {
                        $this_->set((string) ($i + $diff), $this_->get((string) $i));
                    }
                } elseif ($diff < 0) {
                    // Shift elements left.
                    for ($i = $start + $deleteCount; $i < $len; $i++) {
                        $this_->set((string) ($i + $diff), $this_->get((string) $i));
                    }
                    // Delete trailing slots.
                    for ($i = $len + $diff; $i < $len; $i++) {
                        $this_->delete((string) $i);
                    }
                }

            // Insert new items.
                foreach ($insertItems as $idx => $item) {
                    $this_->set((string) ($start + $idx), $item);
                }

                $newLen = $len + $diff;
                if ($this_ instanceof JsArray) {
                    $this_->setLength($newLen);
                } else {
                    $this_->set("length", new JsNumber((float) ($newLen)));
                }
                return JsArray::fromArray($removed);
            },
            2
        ), true, false, true));

        $proto->defineOwnProperty('at', PropertyDescriptor::data(JsFunction::fromCallable(
            'at',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $len = self::getLen($this_);
                $index = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
                if ($index < 0) {
                    $index = $len + $index;
                }
                if ($index < 0 || $index >= $len) {
                    return JsUndefined::instance();
                }
                return $this_->get((string) $index);
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('sort', PropertyDescriptor::data(JsFunction::fromCallable(
            'sort',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $compareFn = ($args[0] ?? null) instanceof JsFunction ? $args[0] : null;
                $items = ($this_ instanceof JsArray ? $this_->toList() : self::objToList($this_));
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
                        $num = TypeConversion::toNumber($result);
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
                    $sa = TypeConversion::toString($a);
                    $sb = TypeConversion::toString($b);
                    return strcmp($sa, $sb);
                });
                $len = self::getLen($this_);
                for ($i = 0; $i < $len; $i++) {
                    $this_->set((string) $i, $items[$i]);
                }
                return $this_;
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('toString', PropertyDescriptor::data(JsFunction::fromCallable(
            'toString',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                return new JsString($this_->toJsString());
            },
            0
        ), true, false, true));

        $proto->defineOwnProperty('keys', PropertyDescriptor::data(JsFunction::fromCallable(
            'keys',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                return self::createArrayIterator($this_, 'key');
            },
            0
        ), true, false, true));

        $proto->defineOwnProperty('values', PropertyDescriptor::data(JsFunction::fromCallable(
            'values',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                return self::createArrayIterator($this_, 'value');
            },
            0
        ), true, false, true));

        $proto->defineOwnProperty('entries', PropertyDescriptor::data(JsFunction::fromCallable(
            'entries',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                return self::createArrayIterator($this_, 'key+value');
            },
            0
        ), true, false, true));
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
    private static function createArrayIterator(JsArray $array, string $kind): JsObject
    {
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
        $iterSym = SymbolConstructor::iterator();
        $iterFn = JsFunction::fromCallable(
            '[Symbol.iterator]',
            function () use ($iterator): JsValue {
                return $iterator;
            },
        );
        $iterator->setBySymbol($iterSym, $iterFn);
        return $iterator;
    }

    private static function isArray(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $arg = $args[0] ?? JsUndefined::instance();
            return new JsBoolean($arg instanceof JsArray);
        };
    }

    private static function from(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $arrayLike = $args[0] ?? JsUndefined::instance();
            $mapFn = ($args[1] ?? null) instanceof JsFunction ? $args[1] : null;

            // Handle JsArray.
            if ($arrayLike instanceof JsArray) {
                $items = [];
                $len = $arrayLike->getLength();
                for ($i = 0; $i < $len; $i++) {
                    $val = $arrayLike->get((string) $i);
                    if ($mapFn !== null) {
                        $val = $mapFn->call(JsUndefined::instance(), [$val, new JsNumber((float) $i)]);
                    }
                    $items[] = $val;
                }
                return JsArray::fromArray($items);
            }

            // Handle JsString: split into characters.
            if ($arrayLike instanceof JsString) {
                $items = [];
                $str = $arrayLike->value;
                $len = mb_strlen($str, 'UTF-8');
                for ($i = 0; $i < $len; $i++) {
                    $char = mb_substr($str, $i, 1, 'UTF-8');
                    $val = new JsString($char);
                    if ($mapFn !== null) {
                        $val = $mapFn->call(JsUndefined::instance(), [$val, new JsNumber((float) $i)]);
                    }
                    $items[] = $val;
                }
                return JsArray::fromArray($items);
            }

            // Handle array-like objects with a length property.
            if ($arrayLike instanceof JsObject) {
                $lenVal = $arrayLike->get('length');
                $len = (int) TypeConversion::toNumber($lenVal);
                $items = [];
                for ($i = 0; $i < $len; $i++) {
                    $val = $arrayLike->get((string) $i);
                    if ($mapFn !== null) {
                        $val = $mapFn->call(JsUndefined::instance(), [$val, new JsNumber((float) $i)]);
                    }
                    $items[] = $val;
                }
                return JsArray::fromArray($items);
            }

            return new JsArray();
        };
    }

    private static function of(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            return JsArray::fromArray($args);
        };
    }
}
