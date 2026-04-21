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
    private const SPARSE_SCAN_THRESHOLD = 1000000;

    public static function install(Environment $env): void
    {
        // Reset global prototype so a new engine instance does not inherit stale prototype.
        JsArray::resetGlobalPrototype();
        self::resetArrayIteratorPrototype();

        // Initialize %ArrayIteratorPrototype% with %IteratorPrototype% as parent.
        $iteratorPrototype = $env->has('__IteratorPrototype__')
            ? $env->get('__IteratorPrototype__')
            : null;
        self::getArrayIteratorPrototype(
            $iteratorPrototype instanceof JsObject ? $iteratorPrototype : null,
        );
        $constructor = JsFunction::fromCallable('Array', function (JsValue $this_, array $args): JsValue {
            if (count($args) === 1 && $args[0] instanceof JsNumber) {
                $n = $args[0]->value;
                $len = (int) $n;
                // Array length must be a valid uint32 (0 to 4294967295)
                if ((float) $len !== $n || $len < 0 || $len > 0xFFFFFFFF) {
                    throw new \PhpJs\Exceptions\RangeError('Invalid array length');
                }
                $arr = new JsArray();
                $arr->setLength($len);
                return $arr;
            }
            return JsArray::fromArray($args);
        }, 1);
        $constructor->setConstructable();

        // Static methods (non-enumerable per spec).
        $isArrayFn = JsFunction::fromCallable('isArray', self::isArray(), 1);
        $isArrayFn->setNonConstructable();
        $constructor->defineOwnProperty(
            'isArray',
            \PhpJs\Object\PropertyDescriptor::data($isArrayFn, true, false, true),
        );
        $fromFn = JsFunction::fromCallable('from', self::from(), 1);
        $fromFn->setNonConstructable();
        $constructor->defineOwnProperty('from', \PhpJs\Object\PropertyDescriptor::data($fromFn, true, false, true));
        $ofFn = JsFunction::fromCallable('of', self::of(), 0);
        $ofFn->setNonConstructable();
        $constructor->defineOwnProperty('of', \PhpJs\Object\PropertyDescriptor::data($ofFn, true, false, true));

        // Array.prototype with all standard methods.
        // Array.prototype's [[Prototype]] must be Object.prototype, not a previous engine's
        // Array.prototype (which JsArray::$globalPrototype might point to between engines).
        // Explicitly pass the current Object.prototype to avoid the static leakage.
        $proto = new JsArray([], \PhpJs\Value\JsObject::getGlobalPrototype());
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));
        self::installPrototypeMethods($proto);
        // Symbol.iterator on Array.prototype, not on each instance.
        JsArray::installSymbolIteratorOnPrototype($proto);

        // Array.prototype[@@unscopables]: null-prototype object, non-writable, non-enumerable, configurable.
        $unscopablesList = new JsObject();
        $unscopablesList->setPrototype(null);
        $trueVal = new JsBoolean(true);
        foreach (
            ['at', 'copyWithin', 'entries', 'fill', 'find', 'findIndex',
            'findLast', 'findLastIndex', 'flat', 'flatMap', 'includes',
            'keys', 'toReversed', 'toSorted', 'toSpliced', 'values'] as $name
        ) {
            $unscopablesList->defineOwnProperty(
                $name,
                PropertyDescriptor::data($trueVal, true, true, true),
            );
        }
        $proto->defineOwnSymbolProperty(
            SymbolConstructor::unscopables(),
            PropertyDescriptor::data($unscopablesList, false, false, true),
        );

        // Array[@@species] per spec: accessor property, getter returns `this`.
        $speciesGetter = JsFunction::fromCallable('get [Symbol.species]', function (JsValue $this_): JsValue {
            return $this_;
        }, 0);
        $constructor->definePropertyBySymbol(
            SymbolConstructor::species(),
            PropertyDescriptor::accessor(
                get: $speciesGetter,
                set: null,
                enumerable: false,
                configurable: true,
            ),
        );

        $constructor->defineOwnProperty(
            'prototype',
            \PhpJs\Object\PropertyDescriptor::data($proto, false, false, false),
        );
        JsArray::setGlobalPrototype($proto);

        $env->defineVar('Array', $constructor);
    }

    /**
     * Get length from array or array-like object.
     */
    /**
     * Per spec, Array prototype methods call ToObject(this) and throw
     * TypeError for null/undefined. For primitive values like booleans,
     * numbers, strings, ToObject wraps them.
     */
    private static function toObject(JsValue $this_): JsObject
    {
        if ($this_ instanceof JsNull || $this_ instanceof JsUndefined) {
            throw new TypeError('Array.prototype method called on null or undefined');
        }
        if ($this_ instanceof JsObject) {
            return $this_;
        }
        // Wrap primitive values
        return TypeConversion::toObject($this_);
    }

    /**
     * ArraySpeciesCreate(originalArray, length) per spec 7.3.20.
     *
     * Uses the @@species pattern to create the result array. Falls back to
     * a plain Array when the species is undefined or the original is not an array.
     */
    private static function arraySpeciesCreate(JsObject $originalArray, int $length): JsObject
    {
        // Per spec 7.3.20 step 3: IsArray(originalArray), walks through Proxy.
        if (!self::isArrayValue($originalArray)) {
            $arr = new JsArray();
            $arr->setLength($length);
            return $arr;
        }
        $c = $originalArray->get('constructor');
        if ($c instanceof JsUndefined) {
            $arr = new JsArray();
            $arr->setLength($length);
            return $arr;
        }
        if ($c instanceof JsObject) {
            $speciesSym = SymbolConstructor::species();
            $species = $c->getBySymbol($speciesSym);
            if ($species instanceof JsNull || $species instanceof JsUndefined) {
                $arr = new JsArray();
                $arr->setLength($length);
                return $arr;
            }
            $c = $species;
        }
        if (!$c instanceof JsFunction || !$c->isConstructable()) {
            throw new TypeError('Species constructor is not a valid constructor');
        }
        $result = $c->construct([new JsNumber((float) $length)]);
        if (!$result instanceof JsObject) {
            throw new TypeError('Species constructor did not return an object');
        }
        return $result;
    }

    private static function getLen(JsValue $obj): int
    {
        if ($obj instanceof JsArray) {
            return $obj->getLength();
        }
        if ($obj instanceof JsObject) {
            $lenVal = $obj->get('length');
            $n = TypeConversion::toNumber($lenVal);
            if (is_nan($n) || $n < 0) {
                return 0;
            }
            return (int) min($n, 4294967295);
        }
        return 0;
    }

    /**
     * Get length as float to preserve large values above PHP_INT_MAX (for overflow checks).
     */
    private static function getLenFloat(JsValue $obj): float
    {
        if ($obj instanceof JsArray) {
            return (float) $obj->getLength();
        }
        if ($obj instanceof JsObject) {
            $lenVal = $obj->get('length');
            $n = TypeConversion::toNumber($lenVal);
            if (is_nan($n) || $n < 0) {
                return 0.0;
            }
            return min($n, 9007199254740991.0); // 2^53 - 1
        }
        return 0.0;
    }

    /**


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
                $o = self::toObject($this_);
                $len = self::lengthOfArrayLike($o);
                $argCount = count($args);
                if (($len + $argCount) > 9007199254740991) {
                    throw new TypeError('Array.prototype.push: length would exceed 2^53 - 1');
                }
                foreach ($args as $arg) {
                    $o->set((string) $len, $arg);
                    $len++;
                }
                // Always go through the property set path so JsArray validates the
                // new length (e.g. throws RangeError when exceeding 2^32 - 1).
                $o->set('length', new JsNumber((float) $len));
                return new JsNumber((float) $len);
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('pop', PropertyDescriptor::data(JsFunction::fromCallable(
            'pop',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $len = self::lengthOfArrayLike($o);
                if ($len === 0) {
                    // Per spec, Set(O, "length", +0F, true): throws on failure.
                    $o->set('length', new JsNumber(0.0), true);
                    return JsUndefined::instance();
                }
                $newLen = $len - 1;
                $index = (string) $newLen;
                $val = $o->get($index);
                $o->delete($index);
                // Per spec, Set(O, "length", newLen, true): throw on failure.
                $o->set('length', new JsNumber((float) $newLen), true);
                return $val;
            },
            0
        ), true, false, true));

        $proto->defineOwnProperty('shift', PropertyDescriptor::data(JsFunction::fromCallable(
            'shift',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $len = self::lengthOfArrayLike($o);
                if ($len === 0) {
                    // Per spec, Set(O, "length", +0F, true).
                    $o->set('length', new JsNumber(0.0), true);
                    return JsUndefined::instance();
                }
                $first = $o->get('0');
                for ($i = 1; $i < $len; $i++) {
                    $from = (string) $i;
                    $to = (string) ($i - 1);
                    if ($o->has($from)) {
                        $o->set($to, $o->get($from));
                    } else {
                        $o->delete($to);
                    }
                }
                $o->delete((string) ($len - 1));
                // Per spec, Set(O, "length", len - 1, true).
                $o->set('length', new JsNumber((float) ($len - 1)), true);
                return $first;
            },
            0
        ), true, false, true));

        $proto->defineOwnProperty('unshift', PropertyDescriptor::data(JsFunction::fromCallable(
            'unshift',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $len = self::getLen($o);
                $count = count($args);
                for ($i = $len - 1; $i >= 0; $i--) {
                    $from = (string) $i;
                    $to = (string) ($i + $count);
                    if ($o->has($from)) {
                        $o->set($to, $o->get($from));
                    } else {
                        $o->delete($to);
                    }
                }
                foreach ($args as $i => $arg) {
                    $o->set((string) $i, $arg);
                }
                // Per spec, Set(O, "length", len + argCount, true).
                $newLen = $len + $count;
                $o->set('length', new JsNumber((float) $newLen), true);
                return new JsNumber((float) $newLen);
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('indexOf', PropertyDescriptor::data(JsFunction::fromCallable(
            'indexOf',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $len = self::lengthOfArrayLike($o);
                if ($len === 0) {
                    return new JsNumber(-1.0);
                }
                $search = $args[0] ?? JsUndefined::instance();
                $nNum = isset($args[1]) ? TypeConversion::toNumber($args[1]) : 0.0;
                if (is_nan($nNum)) {
                    $nNum = 0.0;
                }
                if ($nNum === INF || $nNum >= $len) {
                    return new JsNumber(-1.0);
                }
                if ($nNum >= 0) {
                    $k = (int) min($nNum, (float) ($len - 1));
                } else {
                    $k = $nNum === -INF ? 0 : max($len + (int) $nNum, 0);
                }

                if (self::shouldUseSparseIndexScan($o, $len)) {
                    foreach (self::numericPropertyIndicesInRange($o, $k, $len - 1) as $i) {
                        $key = (string) $i;
                        if (AbstractOperations::strictEquals($o->get($key), $search)) {
                            return new JsNumber((float) $i);
                        }
                    }
                    return new JsNumber(-1.0);
                }

                for ($i = $k; $i < $len; $i++) {
                    $key = (string) $i;
                    if ($o->has($key) && AbstractOperations::strictEquals($o->get($key), $search)) {
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
                $o = self::toObject($this_);
                $len = self::lengthOfArrayLike($o);
                if ($len === 0) {
                    return new JsNumber(-1.0);
                }
                $search = $args[0] ?? JsUndefined::instance();
                $nNum = isset($args[1]) ? TypeConversion::toNumber($args[1]) : (float) ($len - 1);
                if (is_nan($nNum)) {
                    $nNum = 0.0;
                }
                if ($nNum >= 0) {
                    $k = $nNum === INF ? $len - 1 : (int) min($nNum, (float) ($len - 1));
                } else {
                    if ($nNum === -INF) {
                        return new JsNumber(-1.0);
                    }
                    $k = $len + (int) $nNum;
                }

                if (self::shouldUseSparseIndexScan($o, $len)) {
                    foreach (self::numericPropertyIndicesInRange($o, 0, $k, true) as $i) {
                        $key = (string) $i;
                        if (AbstractOperations::strictEquals($o->get($key), $search)) {
                            return new JsNumber((float) $i);
                        }
                    }
                    return new JsNumber(-1.0);
                }

                for ($i = $k; $i >= 0; $i--) {
                    $key = (string) $i;
                    if ($o->has($key) && AbstractOperations::strictEquals($o->get($key), $search)) {
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
                $o = self::toObject($this_);
                $len = self::getLen($o);
                if ($len === 0) {
                    return new JsBoolean(false);
                }
                $search = $args[0] ?? JsUndefined::instance();
                $nNum = isset($args[1]) ? TypeConversion::toNumber($args[1]) : 0.0;
                if (is_nan($nNum)) {
                    $nNum = 0.0;
                }
                // Handle Infinity: PHP (int)INF = 0, but spec says k = n when n >= 0.
                if ($nNum === INF) {
                    return new JsBoolean(false);
                }
                if ($nNum >= 0) {
                    $k = (int) min($nNum, (float) $len);
                } else {
                    $k = $nNum === -INF ? 0 : max($len + (int) $nNum, 0);
                }
                for ($i = $k; $i < $len; $i++) {
                    if (AbstractOperations::sameValueZero($o->get((string) $i), $search)) {
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
                $this_ = self::toObject($this_);
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
                $this_ = self::toObject($this_);
                $len = TypeConversion::toLength($this_->get('length'));
                $relativeStart = isset($args[0]) ? TypeConversion::toIntegerOrInfinity($args[0]) : 0.0;
                $relativeEnd = isset($args[1]) && !($args[1] instanceof JsUndefined)
                    ? TypeConversion::toIntegerOrInfinity($args[1])
                    : (float) $len;

                $start = self::normalizeRelativeIndex($relativeStart, $len);
                $end = self::normalizeRelativeIndex($relativeEnd, $len);
                $count = max(0, $end - $start);

                if ($count > 4294967295) {
                    throw new \PhpJs\Exceptions\RangeError('Invalid array length');
                }

                // ArraySpeciesCreate(O, count) per spec.
                $a = self::arraySpeciesCreate($this_, $count);
                $n = 0;
                for ($i = $start; $i < $end; $i++, $n++) {
                    $from = (string) $i;
                    if ($this_->has($from)) {
                        $a->defineOwnProperty(
                            (string) $n,
                            PropertyDescriptor::data($this_->get($from), true, true, true),
                        );
                    }
                }
                if ($a instanceof JsArray) {
                    $a->setLength($count);
                } else {
                    $a->set('length', new JsNumber((float) $count));
                }
                return $a;
            },
            2
        ), true, false, true));

        $proto->defineOwnProperty('concat', PropertyDescriptor::data(JsFunction::fromCallable(
            'concat',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                // Step 2: ArraySpeciesCreate(O, 0).
                $result = self::arraySpeciesCreate($o, 0);
                $n = 0;
                // Elements to process: this first, then each argument.
                $items = array_merge([$o], $args);
                $isConcatSym = SymbolConstructor::isConcatSpreadable();
                foreach ($items as $e) {
                    $spreadable = false;
                    if ($e instanceof JsObject) {
                        $spreadVal = $e->getBySymbol($isConcatSym);
                        if (!$spreadVal instanceof JsUndefined) {
                            $spreadable = TypeConversion::toBoolean($spreadVal);
                        } else {
                            // Per spec: IsArray(E) walks through Proxy targets.
                            $spreadable = self::isArrayValue($e);
                        }
                    }
                    if ($spreadable) {
                        /** @var JsObject $e */
                        $len = self::getLenFloat($e);
                        if ($n + $len > 9007199254740991) { // 2^53 - 1
                            throw new TypeError('Array.prototype.concat: length exceeded');
                        }
                        $intLen = (int) min($len, PHP_INT_MAX);
                        for ($k = 0; $k < $intLen; $k++) {
                            $key = (string) $k;
                            if ($e->has($key)) {
                                // CreateDataPropertyOrThrow per spec.
                                if (
                                    !$result->defineOwnProperty(
                                        (string) $n,
                                        PropertyDescriptor::data($e->get($key), true, true, true),
                                    )
                                ) {
                                    throw new TypeError(
                                        'Cannot create property \'' . $n . '\' on result'
                                    );
                                }
                            }
                            $n++;
                        }
                    } else {
                        // CreateDataPropertyOrThrow per spec.
                        if (
                            !$result->defineOwnProperty(
                                (string) $n,
                                PropertyDescriptor::data($e, true, true, true),
                            )
                        ) {
                            throw new TypeError(
                                'Cannot create property \'' . $n . '\' on result'
                            );
                        }
                        $n++;
                    }
                }
                if ($result instanceof JsArray) {
                    $result->setLength($n);
                } else {
                    $result->set('length', new JsNumber((float) $n));
                }
                return $result;
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('reverse', PropertyDescriptor::data(JsFunction::fromCallable(
            'reverse',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $len = self::getLen($o);
                $middle = (int) floor($len / 2);
                for ($lower = 0; $lower < $middle; $lower++) {
                    $upper = $len - $lower - 1;
                    $lowerKey = (string) $lower;
                    $upperKey = (string) $upper;
                    $lowerExists = $o->has($lowerKey);
                    $upperExists = $o->has($upperKey);
                    if ($lowerExists && $upperExists) {
                        $lowerVal = $o->get($lowerKey);
                        $upperVal = $o->get($upperKey);
                        $o->set($lowerKey, $upperVal);
                        $o->set($upperKey, $lowerVal);
                    } elseif ($upperExists) {
                        $o->set($lowerKey, $o->get($upperKey));
                        $o->delete($upperKey);
                    } elseif ($lowerExists) {
                        $o->set($upperKey, $o->get($lowerKey));
                        $o->delete($lowerKey);
                    }
                }
                return $o;
            },
            0
        ), true, false, true));

        $proto->defineOwnProperty('map', PropertyDescriptor::data(JsFunction::fromCallable(
            'map',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $len = self::getLen($o);
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('map callback is not a function');
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined) ? $args[1] : JsUndefined::instance();
                $result = self::arraySpeciesCreate($o, $len);
                for ($i = 0; $i < $len; $i++) {
                    $key = (string) $i;
                    if ($o->has($key)) {
                        $val = $o->get($key);
                        $mapped = $callback->call($thisArg, [$val, new JsNumber((float) $i), $o]);
                        $result->defineOwnProperty($key, PropertyDescriptor::data($mapped, true, true, true));
                    }
                }
                return $result;
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('filter', PropertyDescriptor::data(JsFunction::fromCallable(
            'filter',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('filter callback is not a function');
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined) ? $args[1] : JsUndefined::instance();
                // ArraySpeciesCreate(O, 0) per spec.
                $a = self::arraySpeciesCreate($o, 0);
                $to = 0;
                $len = self::getLen($o);
                for ($i = 0; $i < $len; $i++) {
                    $key = (string) $i;
                    if (!$o->has($key)) {
                        continue;
                    }
                    $val = $o->get($key);
                    $keep = $callback->call($thisArg, [$val, new JsNumber((float) $i), $o]);
                    if (TypeConversion::toBoolean($keep)) {
                        $a->defineOwnProperty(
                            (string) $to,
                            PropertyDescriptor::data($val, true, true, true),
                        );
                        $to++;
                    }
                }
                if ($a instanceof JsArray) {
                    $a->setLength($to);
                } else {
                    $a->set('length', new JsNumber((float) $to));
                }
                return $a;
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('reduce', PropertyDescriptor::data(JsFunction::fromCallable(
            'reduce',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('reduce callback is not a function');
                }
                $len = self::getLen($o);
                $initial = $args[1] ?? null;
                $acc = $initial;
                $start = 0;
                if ($acc === null) {
                    if ($len === 0) {
                        throw new TypeError('Reduce of empty array with no initial value');
                    }
                    // Find first present element for initial value.
                    $found = false;
                    for ($k = 0; $k < $len; $k++) {
                        if ($o->has((string) $k)) {
                            $acc = $o->get((string) $k);
                            $start = $k + 1;
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        throw new TypeError('Reduce of empty array with no initial value');
                    }
                }
                for ($i = $start; $i < $len; $i++) {
                    if ($o->has((string) $i)) {
                        $acc = $callback->call(
                            JsUndefined::instance(),
                            [$acc, $o->get((string) $i), new JsNumber((float) $i), $o],
                        );
                    }
                }
                return $acc;
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('reduceRight', PropertyDescriptor::data(JsFunction::fromCallable(
            'reduceRight',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('reduceRight callback is not a function');
                }
                $len = self::lengthOfArrayLike($o);
                $hasInitial = array_key_exists(1, $args);
                $acc = $hasInitial ? $args[1] : null;
                $start = $len - 1;
                if (self::shouldUseSparseIndexScan($o, $len)) {
                    $indices = self::numericPropertyIndicesInRange($o, 0, $len - 1, true);
                    if (!$hasInitial) {
                        if ($indices === []) {
                            throw new TypeError('Reduce of empty array with no initial value');
                        }
                        $initialIndex = array_shift($indices);
                        $acc = $o->get((string) $initialIndex);
                    }

                    foreach ($indices as $index) {
                        $val = $o->get((string) $index);
                        $idx = new JsNumber((float) $index);
                        $acc = $callback->call(JsUndefined::instance(), [$acc, $val, $idx, $o]);
                    }

                    return $acc;
                }

                if (!$hasInitial) {
                    if ($len === 0) {
                        throw new TypeError('Reduce of empty array with no initial value');
                    }
                    // Find last present element for initial value.
                    $found = false;
                    for ($k = $start; $k >= 0; $k--) {
                        if ($o->has((string) $k)) {
                            $acc = $o->get((string) $k);
                            $start = $k - 1;
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        throw new TypeError('Reduce of empty array with no initial value');
                    }
                }
                for ($i = $start; $i >= 0; $i--) {
                    if ($o->has((string) $i)) {
                        $val = $o->get((string) $i);
                        $idx = new JsNumber((float) $i);
                        $acc = $callback->call(JsUndefined::instance(), [$acc, $val, $idx, $o]);
                    }
                }
                return $acc;
            },
            1,
        ), true, false, true));

        $proto->defineOwnProperty('forEach', PropertyDescriptor::data(JsFunction::fromCallable(
            'forEach',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                // Per spec: LengthOfArrayLike first (step 2), then IsCallable (step 3)
                $len = self::getLen($o);
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('forEach callback is not a function');
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined) ? $args[1] : JsUndefined::instance();
                for ($i = 0; $i < $len; $i++) {
                    if ($o->has((string) $i)) {
                        $callback->call($thisArg, [$o->get((string) $i), new JsNumber((float) $i), $o]);
                    }
                }
                return JsUndefined::instance();
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('find', PropertyDescriptor::data(JsFunction::fromCallable(
            'find',
            function (JsValue $this_, array $args): JsValue {
                $this_ = self::toObject($this_);
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
                $this_ = self::toObject($this_);
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

        $proto->defineOwnProperty('findLast', PropertyDescriptor::data(JsFunction::fromCallable(
            'findLast',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $len = self::lengthOfArrayLike($o);
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('findLast callback is not a function');
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined) ? $args[1] : JsUndefined::instance();
                for ($i = $len - 1; $i >= 0; $i--) {
                    $val = $o->get((string) $i);
                    $result = $callback->call($thisArg, [$val, new JsNumber((float) $i), $o]);
                    if (TypeConversion::toBoolean($result)) {
                        return $val;
                    }
                }
                return JsUndefined::instance();
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('findLastIndex', PropertyDescriptor::data(JsFunction::fromCallable(
            'findLastIndex',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $len = self::lengthOfArrayLike($o);
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('findLastIndex callback is not a function');
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined) ? $args[1] : JsUndefined::instance();
                for ($i = $len - 1; $i >= 0; $i--) {
                    $val = $o->get((string) $i);
                    $result = $callback->call($thisArg, [$val, new JsNumber((float) $i), $o]);
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
                $o = self::toObject($this_);
                $len = self::getLen($o);
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('some callback is not a function');
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined) ? $args[1] : JsUndefined::instance();
                for ($i = 0; $i < $len; $i++) {
                    $key = (string) $i;
                    if ($o->has($key)) {
                        $result = $callback->call($thisArg, [$o->get($key), new JsNumber((float) $i), $o]);
                        if (TypeConversion::toBoolean($result)) {
                            return new JsBoolean(true);
                        }
                    }
                }
                return new JsBoolean(false);
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('every', PropertyDescriptor::data(JsFunction::fromCallable(
            'every',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $len = self::getLen($o);
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('every callback is not a function');
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined) ? $args[1] : JsUndefined::instance();
                for ($i = 0; $i < $len; $i++) {
                    $key = (string) $i;
                    if ($o->has($key)) {
                        $result = $callback->call($thisArg, [$o->get($key), new JsNumber((float) $i), $o]);
                        if (!TypeConversion::toBoolean($result)) {
                            return new JsBoolean(false);
                        }
                    }
                }
                return new JsBoolean(true);
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('flat', PropertyDescriptor::data(JsFunction::fromCallable(
            'flat',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $depthVal = $args[0] ?? JsUndefined::instance();
                // Per spec: ToIntegerOrInfinity(depth). Default is 1.
                if ($depthVal instanceof JsUndefined) {
                    $depth = 1;
                } else {
                    $depthNum = TypeConversion::toIntegerOrInfinity($depthVal);
                    if (is_infinite($depthNum) && $depthNum > 0) {
                        $depth = PHP_INT_MAX;
                    } elseif ($depthNum < 0 || is_nan($depthNum)) {
                        $depth = 0;
                    } else {
                        $depth = (int) $depthNum;
                    }
                }
                $sourceLen = self::lengthOfArrayLike($o);
                // ArraySpeciesCreate(O, 0) per spec.
                $a = self::arraySpeciesCreate($o, 0);
                // FlattenIntoArray(A, O, sourceLen, 0, depthNum) per spec.
                $finalIndex = self::specFlattenIntoArray($a, $o, $sourceLen, 0, $depth);
                if ($a instanceof JsArray) {
                    $a->setLength($finalIndex);
                } else {
                    $a->set('length', new JsNumber((float) $finalIndex));
                }
                return $a;
            },
            0
        ), true, false, true));

        $proto->defineOwnProperty('flatMap', PropertyDescriptor::data(JsFunction::fromCallable(
            'flatMap',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $len = self::lengthOfArrayLike($o);
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('flatMap callback is not a function');
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined) ? $args[1] : JsUndefined::instance();
                // ArraySpeciesCreate(O, 0) per spec.
                $a = self::arraySpeciesCreate($o, 0);
                $to = 0;
                for ($i = 0; $i < $len; $i++) {
                    $key = (string) $i;
                    if (!$o->has($key)) {
                        continue;
                    }
                    $val = $o->get($key);
                    $mapped = $callback->call($thisArg, [$val, new JsNumber((float) $i), $o]);
                    if ($mapped instanceof JsArray) {
                        $innerLen = $mapped->getLength();
                        for ($j = 0; $j < $innerLen; $j++) {
                            $a->defineOwnProperty(
                                (string) $to,
                                PropertyDescriptor::data($mapped->get((string) $j), true, true, true),
                            );
                            $to++;
                        }
                    } else {
                        $a->defineOwnProperty(
                            (string) $to,
                            PropertyDescriptor::data($mapped, true, true, true),
                        );
                        $to++;
                    }
                }
                if ($a instanceof JsArray) {
                    $a->setLength($to);
                } else {
                    $a->set('length', new JsNumber((float) $to));
                }
                return $a;
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('fill', PropertyDescriptor::data(JsFunction::fromCallable(
            'fill',
            function (JsValue $this_, array $args): JsValue {
                $this_ = self::toObject($this_);
                $len = self::lengthOfArrayLike($this_);
                $value = $args[0] ?? JsUndefined::instance();
                $relStart = isset($args[1]) ? TypeConversion::toIntegerOrInfinity($args[1]) : 0.0;
                $relEnd = (isset($args[2]) && !$args[2] instanceof JsUndefined)
                    ? TypeConversion::toIntegerOrInfinity($args[2]) : (float) $len;
                $start = self::normalizeRelativeIndex($relStart, $len);
                $end = self::normalizeRelativeIndex($relEnd, $len);
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
                $this_ = self::toObject($this_);
                $len = self::lengthOfArrayLike($this_);
                $relTarget = isset($args[0]) ? TypeConversion::toIntegerOrInfinity($args[0]) : 0.0;
                $relStart = isset($args[1]) ? TypeConversion::toIntegerOrInfinity($args[1]) : 0.0;
                $relEnd = (isset($args[2]) && !$args[2] instanceof JsUndefined)
                    ? TypeConversion::toIntegerOrInfinity($args[2]) : (float) $len;
                $target = self::normalizeRelativeIndex($relTarget, $len);
                $start = self::normalizeRelativeIndex($relStart, $len);
                $end = self::normalizeRelativeIndex($relEnd, $len);
                $count = min($end - $start, $len - $target);
                // Copy in correct direction to handle overlapping ranges.
                if ($start < $target && $target < $start + $count) {
                    for ($i = $count - 1; $i >= 0; $i--) {
                        $from = (string) ($start + $i);
                        $to = (string) ($target + $i);
                        if ($this_->has($from)) {
                            $this_->set($to, $this_->get($from));
                        } else {
                            $this_->delete($to);
                        }
                    }
                } else {
                    for ($i = 0; $i < $count; $i++) {
                        $from = (string) ($start + $i);
                        $to = (string) ($target + $i);
                        if ($this_->has($from)) {
                            $this_->set($to, $this_->get($from));
                        } else {
                            $this_->delete($to);
                        }
                    }
                }
                return $this_;
            },
            2,
        ), true, false, true));

        $proto->defineOwnProperty('splice', PropertyDescriptor::data(JsFunction::fromCallable(
            'splice',
            function (JsValue $this_, array $args): JsValue {
                $this_ = self::toObject($this_);
                $len = TypeConversion::toLength($this_->get('length'));
                $relativeStart = isset($args[0]) ? TypeConversion::toIntegerOrInfinity($args[0]) : 0.0;
                $start = self::normalizeRelativeIndex($relativeStart, $len);

                if (isset($args[1])) {
                    $relativeDeleteCount = TypeConversion::toIntegerOrInfinity($args[1]);
                    if ($relativeDeleteCount === INF) {
                        $deleteCount = $len - $start;
                    } else {
                        $deleteCount = max(0, (int) $relativeDeleteCount);
                    }
                } else {
                    $deleteCount = $len - $start;
                }
                $deleteCount = min($deleteCount, $len - $start);
                $insertItems = array_slice($args, 2);

                // ArraySpeciesCreate(O, actualDeleteCount) per spec.
                $removed = self::arraySpeciesCreate($this_, $deleteCount);
                for ($i = 0; $i < $deleteCount; $i++) {
                    $from = (string) ($start + $i);
                    if ($this_->has($from)) {
                        $removed->set((string) $i, $this_->get($from));
                    }
                }
                if ($removed instanceof JsArray) {
                    $removed->setLength($deleteCount);
                } else {
                    $removed->set('length', new JsNumber((float) $deleteCount));
                }

                $insertCount = count($insertItems);
                $diff = $insertCount - $deleteCount;
                $newLen = $len + $diff;

                if ($newLen > 9007199254740991) {
                    throw new \PhpJs\Exceptions\TypeError('Array length exceeds the supported limit');
                }

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

                // Per spec, Set(O, "length", newLen, true): throw on failure.
                $this_->set('length', new JsNumber((float) $newLen), true);
                return $removed;
            },
            2
        ), true, false, true));

        $proto->defineOwnProperty('at', PropertyDescriptor::data(JsFunction::fromCallable(
            'at',
            function (JsValue $this_, array $args): JsValue {
                $this_ = self::toObject($this_);
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
                // Per spec, if comparefn is not undefined and not callable, throw TypeError
                // before accessing the object's length.
                $compareFnArg = $args[0] ?? JsUndefined::instance();
                if (!$compareFnArg instanceof JsUndefined && !$compareFnArg instanceof JsFunction) {
                    throw new TypeError($compareFnArg->display() . ' is not a function');
                }
                $this_ = self::toObject($this_);
                $compareFn = $compareFnArg instanceof JsFunction ? $compareFnArg : null;
                $len = self::getLen($this_);
                // Collect only existing (non-hole) elements, per spec SortIndexedProperties.
                $items = [];
                for ($i = 0; $i < $len; $i++) {
                    $key = (string) $i;
                    if ($this_->has($key)) {
                        $items[] = $this_->get($key);
                    }
                }
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
                $itemCount = count($items);
                // Write sorted elements.
                for ($i = 0; $i < $itemCount; $i++) {
                    $this_->set((string) $i, $items[$i]);
                }
                // Delete trailing holes (indices that no longer have values).
                for ($i = $itemCount; $i < $len; $i++) {
                    $this_->delete((string) $i);
                }
                return $this_;
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('toString', PropertyDescriptor::data(JsFunction::fromCallable(
            'toString',
            function (JsValue $this_, array $args): JsValue {
                $array = self::toObject($this_);
                $join = $array->get('join');
                if ($join instanceof JsFunction) {
                    return new JsString(TypeConversion::toString($join->call($array, [])));
                }
                // Fall back to Object.prototype.toString per spec.
                $objProto = JsObject::getGlobalPrototype();
                if ($objProto !== null) {
                    $objToStr = $objProto->get('toString');
                    if ($objToStr instanceof JsFunction) {
                        return new JsString(TypeConversion::toString($objToStr->call($array, [])));
                    }
                }
                return new JsString('[object Array]');
            },
            0
        ), true, false, true));

        // toLocaleString: per spec, calls toLocaleString on each element and joins with ","
        $proto->defineOwnProperty('toLocaleString', PropertyDescriptor::data(JsFunction::fromCallable(
            'toLocaleString',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    throw new \PhpJs\Exceptions\TypeError('Cannot convert undefined or null to object');
                }
                $len = self::getLen($this_);
                $parts = [];
                for ($i = 0; $i < $len; $i++) {
                    $elem = $this_->get((string) $i);
                    if ($elem instanceof JsUndefined || $elem instanceof JsNull) {
                        $parts[] = '';
                    } elseif ($elem instanceof JsObject) {
                        $fn = $elem->get('toLocaleString');
                        if ($fn instanceof JsFunction) {
                            $parts[] = TypeConversion::toString($fn->call($elem, []));
                        } else {
                            $parts[] = TypeConversion::toString($elem);
                        }
                    } else {
                        $parts[] = TypeConversion::toString($elem);
                    }
                }
                return new JsString(implode(',', $parts));
            },
            0
        ), true, false, true));

        $proto->defineOwnProperty('keys', PropertyDescriptor::data(JsFunction::fromCallable(
            'keys',
            function (JsValue $this_, array $args): JsValue {
                $this_ = self::toObject($this_);
                return self::createArrayIterator($this_, 'key');
            },
            0
        ), true, false, true));

        $proto->defineOwnProperty('values', PropertyDescriptor::data(JsFunction::fromCallable(
            'values',
            function (JsValue $this_, array $args): JsValue {
                $this_ = self::toObject($this_);
                return self::createArrayIterator($this_, 'value');
            },
            0
        ), true, false, true));

        $proto->defineOwnProperty('entries', PropertyDescriptor::data(JsFunction::fromCallable(
            'entries',
            function (JsValue $this_, array $args): JsValue {
                $this_ = self::toObject($this_);
                return self::createArrayIterator($this_, 'key+value');
            },
            0
        ), true, false, true));

        $proto->defineOwnProperty('toReversed', PropertyDescriptor::data(JsFunction::fromCallable(
            'toReversed',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $len = self::lengthOfArrayLike($o);
                if ($len > 4294967295) {
                    throw new \PhpJs\Exceptions\RangeError('Invalid array length');
                }
                $result = new JsArray();
                for ($k = 0; $k < $len; $k++) {
                    $from = (string) ($len - $k - 1);
                    $result->set((string) $k, $o->get($from));
                }
                $result->setLength($len);
                return $result;
            },
            0
        ), true, false, true));

        $proto->defineOwnProperty('toSorted', PropertyDescriptor::data(JsFunction::fromCallable(
            'toSorted',
            function (JsValue $this_, array $args): JsValue {
                $compareFnArg = $args[0] ?? JsUndefined::instance();
                if (!$compareFnArg instanceof JsUndefined && !$compareFnArg instanceof JsFunction) {
                    throw new TypeError($compareFnArg->display() . ' is not a function');
                }
                $o = self::toObject($this_);
                $len = self::lengthOfArrayLike($o);
                if ($len > 4294967295) {
                    throw new \PhpJs\Exceptions\RangeError('Invalid array length');
                }
                $compareFn = $compareFnArg instanceof JsFunction ? $compareFnArg : null;
                // Collect all elements (no holes: toSorted reads every index).
                $items = [];
                for ($k = 0; $k < $len; $k++) {
                    $items[] = $o->get((string) $k);
                }
                usort($items, function (JsValue $a, JsValue $b) use ($compareFn): int {
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
                    $sa = TypeConversion::toString($a);
                    $sb = TypeConversion::toString($b);
                    return strcmp($sa, $sb);
                });
                $result = new JsArray();
                for ($k = 0; $k < $len; $k++) {
                    $result->set((string) $k, $items[$k]);
                }
                $result->setLength($len);
                return $result;
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('toSpliced', PropertyDescriptor::data(JsFunction::fromCallable(
            'toSpliced',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $len = self::lengthOfArrayLike($o);
                $relativeStart = isset($args[0]) ? TypeConversion::toIntegerOrInfinity($args[0]) : 0.0;
                $actualStart = self::normalizeRelativeIndex($relativeStart, $len);
                $insertCount = max(0, count($args) - 2);
                $insertItems = count($args) > 2 ? array_slice($args, 2) : [];

                if (!isset($args[0])) {
                    $actualDeleteCount = 0;
                } elseif (!isset($args[1])) {
                    $actualDeleteCount = $len - $actualStart;
                } else {
                    $dc = TypeConversion::toIntegerOrInfinity($args[1]);
                    $actualDeleteCount = max(0, min((int) $dc, $len - $actualStart));
                }

                $newLen = $len + $insertCount - $actualDeleteCount;
                // Spec step 12: newLen > 2^53 - 1 is TypeError.
                if ($newLen > 9007199254740991) {
                    throw new TypeError('Invalid array length');
                }
                // ArrayCreate: newLen > 2^32 - 1 is RangeError.
                if ($newLen > 4294967295) {
                    throw new \PhpJs\Exceptions\RangeError('Invalid array length');
                }

                $result = new JsArray();
                $r = 0;
                // Copy elements before start.
                for ($i = 0; $i < $actualStart; $i++) {
                    $result->set((string) $r, $o->get((string) $i));
                    $r++;
                }
                // Insert new items.
                foreach ($insertItems as $item) {
                    $result->set((string) $r, $item);
                    $r++;
                }
                // Copy elements after the deleted range.
                for ($i = $actualStart + $actualDeleteCount; $i < $len; $i++) {
                    $result->set((string) $r, $o->get((string) $i));
                    $r++;
                }
                $result->setLength($newLen);
                return $result;
            },
            2
        ), true, false, true));

        $proto->defineOwnProperty('with', PropertyDescriptor::data(JsFunction::fromCallable(
            'with',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $len = self::lengthOfArrayLike($o);
                $relativeIndex = TypeConversion::toIntegerOrInfinity($args[0] ?? JsUndefined::instance());
                if ($relativeIndex === INF || $relativeIndex === -INF) {
                    throw new \PhpJs\Exceptions\RangeError('Invalid index');
                }
                $intRelative = (int) $relativeIndex;
                if ($relativeIndex >= 0) {
                    $actualIndex = $intRelative;
                } else {
                    $actualIndex = $len + $intRelative;
                }
                $value = $args[1] ?? JsUndefined::instance();
                if ($actualIndex < 0 || $actualIndex >= $len) {
                    throw new \PhpJs\Exceptions\RangeError('Invalid index');
                }
                if ($len > 4294967295) {
                    throw new \PhpJs\Exceptions\RangeError('Invalid array length');
                }
                $result = new JsArray();
                for ($k = 0; $k < $len; $k++) {
                    if ($k === $actualIndex) {
                        $result->set((string) $k, $value);
                    } else {
                        $result->set((string) $k, $o->get((string) $k));
                    }
                }
                $result->setLength($len);
                return $result;
            },
            2
        ), true, false, true));
    }

    /**
     * Recursively flatten an array to the given depth.
     *
     * @return list<JsValue>
     */
    private static function flattenArray(JsObject $array, int $depth): array
    {
        $len = $array instanceof JsArray
            ? $array->getLength()
            : (int) TypeConversion::toNumber($array->get('length'));
        $result = [];
        for ($i = 0; $i < $len; $i++) {
            $key = (string) $i;
            if (!$array->hasOwnProperty($key) && !$array->has($key)) {
                continue;
            }
            $element = $array->get($key);
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

    /**
     * FlattenIntoArray per spec 23.1.3.11.1.
     *
     * Writes directly to the target using CreateDataPropertyOrThrow,
     * respecting extensibility and configurability of the target object.
     */
    private static function specFlattenIntoArray(
        JsObject $target,
        JsObject $source,
        int $sourceLen,
        int $start,
        int $depth,
        ?JsFunction $mapperFunction = null,
        ?JsValue $thisArg = null,
    ): int {
        $targetIndex = $start;
        for ($sourceIndex = 0; $sourceIndex < $sourceLen; $sourceIndex++) {
            $p = (string) $sourceIndex;
            if ($source->has($p)) {
                $element = $source->get($p);
                if ($mapperFunction !== null) {
                    $element = $mapperFunction->call(
                        $thisArg ?? JsUndefined::instance(),
                        [$element, new JsNumber((float) $sourceIndex), $source],
                    );
                }
                $shouldFlatten = false;
                if ($depth > 0) {
                    $shouldFlatten = self::isArrayValue($element);
                }
                if ($shouldFlatten) {
                    /** @var JsObject $element */
                    $elementLen = self::lengthOfArrayLike($element);
                    $targetIndex = self::specFlattenIntoArray(
                        $target,
                        $element,
                        $elementLen,
                        $targetIndex,
                        $depth - 1,
                    );
                } else {
                    if ($targetIndex >= 9007199254740991) {
                        throw new TypeError('FlattenIntoArray: target index exceeded');
                    }
                    // CreateDataPropertyOrThrow per spec.
                    $success = $target->defineOwnProperty(
                        (string) $targetIndex,
                        PropertyDescriptor::data($element, true, true, true),
                    );
                    if (!$success) {
                        throw new TypeError(
                            'Cannot define property '
                            . $targetIndex . ' on result object'
                        );
                    }
                    $targetIndex++;
                }
            }
        }
        return $targetIndex;
    }

    /** %ArrayIteratorPrototype%: shared prototype for all array iterators. */
    private static ?JsObject $arrayIteratorPrototype = null;

    /**
     * Get or create the %ArrayIteratorPrototype% intrinsic.
     * Its [[Prototype]] is %IteratorPrototype%.
     */
    public static function getArrayIteratorPrototype(?JsObject $iteratorPrototype = null): JsObject
    {
        if (self::$arrayIteratorPrototype !== null) {
            return self::$arrayIteratorPrototype;
        }

        $proto = new JsObject($iteratorPrototype);

        // next method on the prototype. Validates internal slots via hidden property.
        $nextFn = JsFunction::fromCallable('next', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new \PhpJs\Exceptions\TypeError(
                    'Method Array Iterator.prototype.next called on incompatible receiver',
                );
            }
            $slotDesc = $this_->getOwnPropertyDescriptor('[[ArrayIteratorData]]');
            if ($slotDesc === null) {
                throw new \PhpJs\Exceptions\TypeError(
                    'Method Array Iterator.prototype.next called on incompatible receiver',
                );
            }
            $data = $slotDesc->value;
            $isExhausted = $data instanceof JsObject
                && $data->get('exhausted') instanceof JsBoolean
                && $data->get('exhausted')->value;
            if (!$data instanceof JsObject || $isExhausted) {
                $result = new JsObject();
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
                return $result;
            }
            $array = $data->get('array');
            if (!$array instanceof JsObject) {
                $result = new JsObject();
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
                return $result;
            }
            $kind = ($data->get('kind') instanceof JsString) ? $data->get('kind')->value : 'value';
            $indexVal = $data->get('index');
            $index = ($indexVal instanceof JsNumber) ? (int) $indexVal->value : 0;

            // Per spec 23.1.5.2.1 step 8: if the array is a TypedArray
            // with a detached buffer, throw TypeError.
            if ($array instanceof \PhpJs\Value\JsTypedArray) {
                $buffer = $array->getBuffer();
                if ($buffer->isDetached()) {
                    throw new \PhpJs\Exceptions\TypeError(
                        'Cannot perform Array Iterator.prototype.next on a detached ArrayBuffer',
                    );
                }
            }

            // Re-read length each time for mutable iteration.
            $len = self::getLen($array);

            $result = new JsObject();
            if ($index < $len) {
                $data->set('index', new JsNumber((float) ($index + 1)));
                $key = new JsNumber((float) $index);
                $value = $array->get((string) $index);
                $result->set('done', new JsBoolean(false));
                $result->set('value', match ($kind) {
                    'key' => $key,
                    'value' => $value,
                    'key+value' => JsArray::fromArray([$key, $value]),
                    default => $value,
                });
            } else {
                // Mark as exhausted so subsequent calls stay done.
                $data->set('exhausted', new JsBoolean(true));
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
            }
            return $result;
        }, 0);
        $nextFn->setNonConstructable();
        $proto->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));

        // Symbol.toStringTag = "Array Iterator" per spec 23.1.5.2.2.
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Array Iterator'), false, false, true),
        );

        self::$arrayIteratorPrototype = $proto;
        return $proto;
    }

    /** Reset the shared array iterator prototype (for engine reset). */
    public static function resetArrayIteratorPrototype(): void
    {
        self::$arrayIteratorPrototype = null;
    }

    /** Public entry point for creating array iterators (used by JsArray Symbol.iterator). */
    public static function createArrayIteratorFromSymbol(JsObject $array, string $kind): JsObject
    {
        return self::createArrayIterator($array, $kind);
    }

    /** Create an iterator object for keys, values, or entries. */
    private static function createArrayIterator(JsObject $array, string $kind): JsObject
    {
        $proto = self::$arrayIteratorPrototype;
        $iterator = new JsObject($proto);

        // Store iteration state as internal data.
        $data = new JsObject();
        $data->set('array', $array);
        $data->set('kind', new JsString($kind));
        $data->set('index', new JsNumber(0.0));
        $iterator->defineOwnProperty(
            '[[ArrayIteratorData]]',
            PropertyDescriptor::data($data, false, false, false),
        );

        return $iterator;
    }

    private static function isArray(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $arg = $args[0] ?? JsUndefined::instance();
            return new JsBoolean(self::isArrayValue($arg));
        };
    }

    /**
     * Per spec 7.2.2 IsArray: unwrap proxy targets recursively.
     */
    private static function isArrayValue(JsValue $arg): bool
    {
        if ($arg instanceof JsArray) {
            return true;
        }
        if ($arg instanceof \PhpJs\Value\JsProxy) {
            if ($arg->isRevoked()) {
                throw new TypeError('Cannot perform \'IsArray\' on a proxy that has been revoked');
            }
            return self::isArrayValue($arg->getTarget());
        }
        return false;
    }

    /**
     * Construct an object using a constructor function, mimicking `new C(args)`.
     */
    private static function constructWith(JsFunction $ctor, array $args): JsObject
    {
        $proto = $ctor->get('prototype');
        $newObj = new JsObject($proto instanceof JsObject ? $proto : null);
        $newObj->defineOwnProperty('[[NewTarget]]', PropertyDescriptor::data($ctor, false, false, false));
        $result = $ctor->call($newObj, $args);
        return $result instanceof JsObject ? $result : $newObj;
    }

    private static function from(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $c = $this_; // The constructor (this) for Array.from.call(C, items)
            $arrayLike = $args[0] ?? JsUndefined::instance();

            // Null/undefined throw TypeError per spec.
            if ($arrayLike instanceof JsNull || $arrayLike instanceof JsUndefined) {
                throw new TypeError('Array.from called on null or undefined');
            }

            // Validate mapFn if provided.
            $mapFnRaw = $args[1] ?? JsUndefined::instance();
            $mapFn = null;
            if (!$mapFnRaw instanceof JsUndefined) {
                if (!$mapFnRaw instanceof JsFunction) {
                    throw new TypeError('Array.from: when provided, the second argument must be a function');
                }
                $mapFn = $mapFnRaw;
            }
            $mapThisArg = $args[2] ?? JsUndefined::instance();

            // Determine if C is a constructor.
            $isConstructor = ($c instanceof JsFunction && $c->isConstructable());

            // Check for Symbol.iterator first (iterables take precedence over array-like).
            if ($arrayLike instanceof JsObject || $arrayLike instanceof JsString) {
                $iterSym = SymbolConstructor::iterator();
                $iteratorMethod = null;

                if ($arrayLike instanceof JsObject) {
                    $iteratorMethod = $arrayLike->getBySymbol($iterSym);
                }

                if ($iteratorMethod instanceof JsFunction || $arrayLike instanceof JsString) {
                    // Create the result object.
                    if ($isConstructor) {
                        /** @var JsFunction $c */
                        $a = self::constructWith($c, []);
                    } else {
                        $a = new JsArray();
                    }
                    $index = 0;

                    if ($arrayLike instanceof JsString) {
                        // Iterate string code points.
                        $str = $arrayLike->value;
                        $len = mb_strlen($str, 'UTF-8');
                        for ($i = 0; $i < $len; $i++) {
                            $val = new JsString(mb_substr($str, $i, 1, 'UTF-8'));
                            if ($mapFn !== null) {
                                $val = $mapFn->call($mapThisArg, [$val, new JsNumber((float) $index)]);
                            }
                            // CreateDataPropertyOrThrow per spec.
                            $success = $a->defineOwnProperty(
                                (string) $index,
                                PropertyDescriptor::data($val, true, true, true),
                            );
                            if (!$success) {
                                throw new TypeError(
                                    'Cannot define property ' . $index . ' on result object'
                                );
                            }
                            $index++;
                        }
                    } else {
                        // Use the iterator protocol.
                        /** @var JsFunction $iteratorMethod */
                        $iterator = $iteratorMethod->call($arrayLike, []);
                        if (!$iterator instanceof JsObject) {
                            throw new TypeError('Array.from: iterator is not an object');
                        }
                        while (true) {
                            $nextMethod = $iterator->get('next');
                            if (!$nextMethod instanceof JsFunction) {
                                break;
                            }
                            $result = $nextMethod->call($iterator, []);
                            if (!$result instanceof JsObject) {
                                throw new TypeError('Array.from: iterator result is not an object');
                            }
                            $done = TypeConversion::toBoolean($result->get('done'));
                            if ($done) {
                                break;
                            }
                            $val = $result->get('value');
                            if ($mapFn !== null) {
                                try {
                                    $val = $mapFn->call(
                                        $mapThisArg,
                                        [$val, new JsNumber((float) $index)]
                                    );
                                } catch (\Throwable $mapErr) {
                                    // Per spec: IteratorClose(iterator, mappedValue).
                                    $returnMethod = $iterator->get('return');
                                    if ($returnMethod instanceof JsFunction) {
                                        try {
                                            $returnMethod->call($iterator, []);
                                        } catch (\Throwable $e) {
                                            // Ignore close errors, re-throw original.
                                        }
                                    }
                                    throw $mapErr;
                                }
                            }
                            // CreateDataPropertyOrThrow per spec.
                            $success = $a->defineOwnProperty(
                                (string) $index,
                                PropertyDescriptor::data($val, true, true, true),
                            );
                            if (!$success) {
                                // Per spec: IteratorClose(iterator, defineStatus).
                                $returnMethod = $iterator->get('return');
                                if ($returnMethod instanceof JsFunction) {
                                    try {
                                        $returnMethod->call($iterator, []);
                                    } catch (\Throwable $e) {
                                        // Ignore errors from iterator close.
                                    }
                                }
                                throw new TypeError(
                                    'Cannot define property ' . $index . ' on result object'
                                );
                            }
                            $index++;
                        }
                    }

                    $a->set('length', new JsNumber((float) $index));
                    if ($a instanceof JsArray) {
                        $a->setLength($index);
                    }
                    return $a;
                }
            }

            // Fall back to array-like handling (length property).
            $lenVal = ($arrayLike instanceof JsObject)
                ? $arrayLike->get('length')
                : JsUndefined::instance();
            $lenNum = TypeConversion::toNumber($lenVal);
            $len = is_nan($lenNum) || $lenNum < 0 ? 0 : (int) $lenNum;

            // Create result: use constructor if available.
            if ($isConstructor) {
                /** @var JsFunction $c */
                $a = self::constructWith($c, [new JsNumber((float) $len)]);
            } else {
                $a = new JsArray();
            }

            for ($i = 0; $i < $len; $i++) {
                $val = ($arrayLike instanceof JsObject)
                    ? $arrayLike->get((string) $i)
                    : JsUndefined::instance();
                if ($mapFn !== null) {
                    $val = $mapFn->call($mapThisArg, [$val, new JsNumber((float) $i)]);
                }
                // CreateDataPropertyOrThrow per spec.
                $success = $a->defineOwnProperty(
                    (string) $i,
                    PropertyDescriptor::data($val, true, true, true),
                );
                if (!$success) {
                    throw new TypeError(
                        'Cannot define property ' . $i . ' on result object'
                    );
                }
            }

            $a->set('length', new JsNumber((float) $len));
            if ($a instanceof JsArray) {
                $a->setLength($len);
            }
            return $a;
        };
    }

    /**
     * Array.fromAsync(asyncItems, mapFn?, thisArg?).
     *
     * Per spec, returns a Promise that resolves to an Array.
     * Since php-js is synchronous, thenables and promises are resolved eagerly.
     */
    private static function fromAsync(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $c = $this_;
            $asyncItems = $args[0] ?? JsUndefined::instance();
            $mapFnRaw = $args[1] ?? JsUndefined::instance();
            $thisArg = $args[2] ?? JsUndefined::instance();

            // Step 3: validate mapFn early.
            $mapFn = null;
            if (!$mapFnRaw instanceof JsUndefined) {
                if (!$mapFnRaw instanceof JsFunction) {
                    $promise = new JsPromise();
                    $promise->reject(
                        self::createTypeErrorObject(
                            TypeConversion::toString($mapFnRaw) . ' is not a function'
                        )
                    );
                    return $promise;
                }
                $mapFn = $mapFnRaw;
            }

            $promise = new JsPromise();
            try {
                $isConstructor = ($c instanceof JsFunction && $c->isConstructable());

                // Per spec: check Symbol.asyncIterator first, then Symbol.iterator.
                $usingAsyncIterator = null;
                $usingSyncIterator = null;
                if ($asyncItems instanceof JsObject) {
                    $asyncIterSym = SymbolConstructor::asyncIterator();
                    $asyncIterMethod = $asyncItems->getBySymbol($asyncIterSym);
                    if ($asyncIterMethod instanceof JsFunction) {
                        $usingAsyncIterator = $asyncIterMethod;
                    } elseif (
                        !$asyncIterMethod instanceof JsUndefined
                        && !$asyncIterMethod instanceof JsNull
                    ) {
                        throw new TypeError('object is not iterable');
                    }
                }
                if ($usingAsyncIterator === null) {
                    if ($asyncItems instanceof JsObject || $asyncItems instanceof JsString) {
                        $iterSym = SymbolConstructor::iterator();
                        if ($asyncItems instanceof JsObject) {
                            $syncIterMethod = $asyncItems->getBySymbol($iterSym);
                            if ($syncIterMethod instanceof JsFunction) {
                                $usingSyncIterator = $syncIterMethod;
                            } elseif (
                                !$syncIterMethod instanceof JsUndefined
                                && !$syncIterMethod instanceof JsNull
                            ) {
                                throw new TypeError('object is not iterable');
                            }
                        }
                        if ($usingSyncIterator === null && $asyncItems instanceof JsString) {
                            $usingSyncIterator = true;
                        }
                    }
                }

                if ($usingAsyncIterator !== null || $usingSyncIterator !== null) {
                    if ($isConstructor) {
                        /** @var JsFunction $c */
                        $a = self::constructWith($c, []);
                    } else {
                        $a = new JsArray();
                    }
                    $index = 0;

                    if ($usingSyncIterator === true && $asyncItems instanceof JsString) {
                        $str = $asyncItems->value;
                        $len = mb_strlen($str, 'UTF-8');
                        for ($i = 0; $i < $len; $i++) {
                            $val = new JsString(mb_substr($str, $i, 1, 'UTF-8'));
                            $val = self::awaitValue($val);
                            if ($mapFn !== null) {
                                $val = $mapFn->call($thisArg, [$val, new JsNumber((float) $index)]);
                                $val = self::awaitValue($val);
                            }
                            $a->defineOwnProperty(
                                (string) $index,
                                PropertyDescriptor::data($val, true, true, true),
                            );
                            $index++;
                        }
                    } else {
                        $iteratorMethod = $usingAsyncIterator ?? $usingSyncIterator;
                        /** @var JsFunction $iteratorMethod */
                        $iterator = $iteratorMethod->call($asyncItems, []);
                        if (!$iterator instanceof JsObject) {
                            throw new TypeError('Result of the Symbol.iterator method is not an object');
                        }
                        while (true) {
                            $nextMethod = $iterator->get('next');
                            if (!$nextMethod instanceof JsFunction) {
                                break;
                            }
                            $result = $nextMethod->call($iterator, []);
                            $result = self::awaitValue($result);
                            if (!$result instanceof JsObject) {
                                throw new TypeError('Iterator result is not an object');
                            }
                            $done = TypeConversion::toBoolean($result->get('done'));
                            if ($done) {
                                break;
                            }
                            $val = $result->get('value');
                            $val = self::awaitValue($val);
                            if ($mapFn !== null) {
                                try {
                                    $val = $mapFn->call(
                                        $thisArg,
                                        [$val, new JsNumber((float) $index)],
                                    );
                                    $val = self::awaitValue($val);
                                } catch (\Throwable $mapErr) {
                                    $returnMethod = $iterator->get('return');
                                    if ($returnMethod instanceof JsFunction) {
                                        try {
                                            $returnMethod->call($iterator, []);
                                        } catch (\Throwable) {
                                        }
                                    }
                                    throw $mapErr;
                                }
                            }
                            $success = $a->defineOwnProperty(
                                (string) $index,
                                PropertyDescriptor::data($val, true, true, true),
                            );
                            if (!$success) {
                                $returnMethod = $iterator->get('return');
                                if ($returnMethod instanceof JsFunction) {
                                    try {
                                        $returnMethod->call($iterator, []);
                                    } catch (\Throwable) {
                                    }
                                }
                                throw new TypeError(
                                    'Cannot define property ' . $index . ' on result object'
                                );
                            }
                            $index++;
                        }
                    }
                    $a->set('length', new JsNumber((float) $index));
                    if ($a instanceof JsArray) {
                        $a->setLength($index);
                    }
                    $promise->resolve($a);
                    return $promise;
                }

                // Non-iterable: array-like path.
                if ($asyncItems instanceof JsNull || $asyncItems instanceof JsUndefined) {
                    throw new TypeError('Cannot read properties of '
                        . ($asyncItems instanceof JsNull ? 'null' : 'undefined'));
                }

                $arrayLike = ($asyncItems instanceof JsObject)
                    ? $asyncItems
                    : TypeConversion::toObject($asyncItems);

                $lenVal = $arrayLike->get('length');
                $lenNum = TypeConversion::toLength(TypeConversion::toNumber($lenVal));

                if ($isConstructor) {
                    /** @var JsFunction $c */
                    $a = self::constructWith($c, [new JsNumber((float) $lenNum)]);
                } else {
                    $a = new JsArray();
                }

                for ($i = 0; $i < $lenNum; $i++) {
                    $val = $arrayLike->get((string) $i);
                    $val = self::awaitValue($val);
                    if ($mapFn !== null) {
                        $val = $mapFn->call($thisArg, [$val, new JsNumber((float) $i)]);
                        $val = self::awaitValue($val);
                    }
                    $a->defineOwnProperty(
                        (string) $i,
                        PropertyDescriptor::data($val, true, true, true),
                    );
                }
                $a->set('length', new JsNumber((float) $lenNum));
                if ($a instanceof JsArray) {
                    $a->setLength($lenNum);
                }
                $promise->resolve($a);
            } catch (\Throwable $e) {
                if ($e instanceof \PhpJs\Exceptions\RuntimeError) {
                    $errVal = $e->getJsValue();
                    $promise->reject($errVal ?? new JsString($e->getMessage()));
                } elseif ($e instanceof TypeError) {
                    $promise->reject(self::createTypeErrorObject($e->getMessage()));
                } else {
                    $promise->reject(new JsString($e->getMessage()));
                }
            }
            return $promise;
        };
    }

    /**
     * Synchronously resolve a value that may be a Promise or thenable.
     * Since php-js is synchronous, Promises are eagerly resolved.
     */
    private static function awaitValue(JsValue $value): JsValue
    {
        if ($value instanceof JsPromise) {
            if ($value->getState() === JsPromise::STATE_REJECTED) {
                $reason = $value->getResolvedValue();
                throw new \PhpJs\Exceptions\JsThrowable($reason);
            }
            return $value->getResolvedValue();
        }
        if ($value instanceof JsObject) {
            $thenMethod = $value->get('then');
            if ($thenMethod instanceof JsFunction) {
                $resolved = JsUndefined::instance();
                $rejected = null;
                $resolveHandler = JsFunction::fromCallable(
                    '',
                    function (JsValue $this_, array $args) use (&$resolved): JsValue {
                        $resolved = $args[0] ?? JsUndefined::instance();
                        return JsUndefined::instance();
                    },
                );
                $rejectHandler = JsFunction::fromCallable(
                    '',
                    function (JsValue $this_, array $args) use (&$rejected): JsValue {
                        $rejected = $args[0] ?? JsUndefined::instance();
                        return JsUndefined::instance();
                    },
                );
                $thenMethod->call($value, [$resolveHandler, $rejectHandler]);
                if ($rejected !== null) {
                    throw new \PhpJs\Exceptions\JsThrowable($rejected);
                }
                return $resolved;
            }
        }
        return $value;
    }

    private static function of(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $len = count($args);
            $isConstructor = ($this_ instanceof JsFunction && $this_->isConstructable());
            if ($isConstructor) {
                /** @var JsFunction $this_ */
                $a = self::constructWith($this_, [new JsNumber((float) $len)]);
            } else {
                $a = new JsArray();
            }
            for ($k = 0; $k < $len; $k++) {
                $a->defineOwnProperty(
                    (string) $k,
                    PropertyDescriptor::data($args[$k], true, true, true),
                );
            }
            if ($a instanceof JsArray) {
                $a->setLength($len);
            } else {
                $a->set('length', new JsNumber((float) $len));
            }
            return $a;
        };
    }

    private static function normalizeRelativeIndex(float $index, int $length): int
    {
        if ($index === INF) {
            return $length;
        }
        if ($index === -INF) {
            return 0;
        }

        $integerIndex = (int) $index;
        if ($integerIndex < 0) {
            return max($length + $integerIndex, 0);
        }

        return min($integerIndex, $length);
    }

    private static function lengthOfArrayLike(JsObject $object): int
    {
        return TypeConversion::toLength($object->get('length'));
    }

    private static function shouldUseSparseIndexScan(JsObject $object, int $length): bool
    {
        return !$object instanceof JsArray && $length > self::SPARSE_SCAN_THRESHOLD;
    }

    /**
     * @return list<int>
     */
    private static function numericPropertyIndicesInRange(
        JsObject $object,
        int $start,
        int $end,
        bool $descending = false,
    ): array {
        if ($end < $start) {
            return [];
        }

        $seen = [];
        $indices = [];
        $current = $object;

        while ($current !== null) {
            foreach ($current->getProperties()->keys() as $key) {
                if (!self::isCanonicalArrayIndexString($key)) {
                    continue;
                }
                if (isset($seen[$key])) {
                    continue;
                }

                $index = (int) $key;
                if ($index < $start || $index > $end) {
                    continue;
                }

                $seen[$key] = true;
                $indices[] = $index;
            }

            $current = $current->getPrototype();
        }

        $descending ? rsort($indices) : sort($indices);

        return $indices;
    }

    private static function isCanonicalArrayIndexString(string $key): bool
    {
        if ($key === '' || !ctype_digit($key)) {
            return false;
        }

        return $key === '0' || $key[0] !== '0';
    }

    /**
     * Create a TypeError object suitable for Promise rejection.
     */
    private static function createTypeErrorObject(string $message): JsObject
    {
        $err = new JsObject();
        $err->set('name', new JsString('TypeError'));
        $err->set('message', new JsString($message));
        return $err;
    }
}
