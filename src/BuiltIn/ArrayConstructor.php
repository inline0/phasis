<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\TypeError;
use Phasis\Runtime\Environment;
use Phasis\Spec\AbstractOperations;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\Object\PropertyDescriptor;

class ArrayConstructor
{
    use Array_\ArrayHelpers;
    use Array_\ArrayIterator;
    use Array_\ArrayStatics;

    private const SPARSE_SCAN_THRESHOLD = 1000000;

    public static function install(Environment $env): void
    {
        // Reset global prototype so a new engine instance does not inherit stale prototype.
        JsArray::resetGlobalPrototype();

        // Initialize %ArrayIteratorPrototype% with %IteratorPrototype% as parent.
        $iteratorPrototype = $env->has('__IteratorPrototype__')
            ? $env->get('__IteratorPrototype__')
            : null;
        $arrayIterProto = self::buildArrayIteratorPrototype(
            $iteratorPrototype instanceof JsObject ? $iteratorPrototype : null,
        );
        // Stash on the realm env so cross-realm iterator creation routes
        // through the *active realm's* %ArrayIteratorPrototype%. iter from
        // main's [].values() must keep walking main's prototype chain even
        // after createRealm built a fresh prototype for the child realm.
        // Per-realm storage replaces the old static cache, which leaked the
        // last-built prototype across realms.
        $env->defineVar('__ArrayIteratorPrototype__', $arrayIterProto);
        $constructor = JsFunction::fromCallable('Array', function (JsValue $this_, array $args): JsValue {
            // Resolve the prototype to install on the new array: subclasses carry a
            // [[NewTarget]] slot whose prototype should be used. Per spec
            // GetPrototypeFromConstructor: when newTarget.prototype is not an
            // Object, fall back to GetFunctionRealm(newTarget).[[%Array.prototype%]].
            $protoToUse = null;
            if ($this_ instanceof JsObject && $this_->has('[[NewTarget]]')) {
                $newTarget = $this_->get('[[NewTarget]]');
                $protoToUse = \Phasis\Spec\AbstractOperations::getPrototypeFromConstructor(
                    $newTarget,
                    static fn ($env) => \Phasis\Spec\AbstractOperations::realmIntrinsicPrototype($env, 'Array'),
                );
            }
            if (count($args) === 1 && $args[0] instanceof JsNumber) {
                $n = $args[0]->value;
                $len = (int) $n;
                // Array length must be a valid uint32 (0 to 4294967295)
                if ((float) $len !== $n || $len < 0 || $len > 0xFFFFFFFF) {
                    throw new \Phasis\Exceptions\RangeError('Invalid array length');
                }
                $arr = new JsArray([], $protoToUse);
                $arr->setLength($len);
                return $arr;
            }
            $arr = JsArray::fromArray($args);
            if ($protoToUse !== null) {
                $arr->setPrototype($protoToUse);
            }
            return $arr;
        }, 1);
        $constructor->setConstructable();

        // Static methods (non-enumerable per spec).
        $isArrayFn = JsFunction::fromCallable('isArray', self::isArray(), 1);
        $isArrayFn->setNonConstructable();
        $constructor->defineOwnProperty(
            'isArray',
            \Phasis\Object\PropertyDescriptor::data($isArrayFn, true, false, true),
        );
        $fromFn = JsFunction::fromCallable('from', self::from(), 1);
        $fromFn->setNonConstructable();
        $constructor->defineOwnProperty('from', PropertyDescriptor::data($fromFn, true, false, true));
        $fromAsyncFn = JsFunction::fromCallable('fromAsync', self::fromAsync($env), 1);
        $fromAsyncFn->setNonConstructable();
        $constructor->defineOwnProperty('fromAsync', PropertyDescriptor::data($fromAsyncFn, true, false, true));
        $ofFn = JsFunction::fromCallable('of', self::of(), 0);
        $ofFn->setNonConstructable();
        $constructor->defineOwnProperty('of', \Phasis\Object\PropertyDescriptor::data($ofFn, true, false, true));

        // Array.prototype with all standard methods.
        // Array.prototype's [[Prototype]] must be Object.prototype, not a previous engine's
        // Array.prototype (which JsArray::$globalPrototype might point to between engines).
        // Explicitly pass the current Object.prototype to avoid the static leakage.
        $proto = new JsArray([], \Phasis\Value\JsObject::getGlobalPrototype());
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
            \Phasis\Object\PropertyDescriptor::data($proto, false, false, false),
        );
        JsArray::setGlobalPrototype($proto);

        $env->defineVar('Array', $constructor);
    }






    private static function installPrototypeMethods(JsArray $proto): void
    {
        $pushFn = JsFunction::fromCallable(
            'push',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $len = self::lengthOfArrayLike($o);
                $argCount = count($args);
                if (($len + $argCount) > 9007199254740991) {
                    throw new TypeError('Array.prototype.push: length would exceed 2^53 - 1');
                }
                foreach ($args as $arg) {
                    // Per §23.1.3.22 step 5.a: Set(O, ToString(len), E, true).
                    // Throw=true causes a TypeError when the assignment fails
                    // (e.g. frozen receiver, non-writable element).
                    $o->set((string) $len, $arg, true);
                    $len++;
                }
                // Per §23.1.3.22 step 6: Set(O, "length", 𝔽(len), true).
                $o->set('length', JsNumber::of((float) $len), true);
                return JsNumber::of((float) $len);
            },
            1
        );
        // Tag for VM inline path. CALL_METHOD checks the marker and,
        // when the receiver is a plain JsArray, performs the dense
        // append + length bump directly — skipping the spec-faithful
        // native dispatch which is overkill for that shape.
        $pushFn->builtinKind = 'array.push';
        $proto->defineOwnProperty('push', PropertyDescriptor::data($pushFn, true, false, true));

        $popFn = JsFunction::fromCallable(
            'pop',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $len = self::lengthOfArrayLike($o);
                if ($len === 0) {
                    // Per spec, Set(O, "length", +0F, true): throws on failure.
                    $o->set('length', JsNumber::of(0.0), true);
                    return JsUndefined::instance();
                }
                $newLen = $len - 1;
                $index = (string) $newLen;
                $val = $o->get($index);
                // Per §23.1.3.22 step 5: DeletePropertyOrThrow uses
                // throw=true. Fails for non-configurable indices.
                if (!$o->delete($index, true)) {
                    throw new \Phasis\Exceptions\TypeError(
                        "Cannot delete property '{$index}' of '[object Array]'",
                    );
                }
                // Per spec, Set(O, "length", newLen, true): throw on failure.
                $o->set('length', JsNumber::of((float) $newLen), true);
                return $val;
            },
            0
        );
        $popFn->builtinKind = 'array.pop';
        $proto->defineOwnProperty('pop', PropertyDescriptor::data($popFn, true, false, true));

        $proto->defineOwnProperty('shift', PropertyDescriptor::data(JsFunction::fromCallable(
            'shift',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $len = self::lengthOfArrayLike($o);
                if ($len === 0) {
                    // Per spec, Set(O, "length", +0F, true).
                    $o->set('length', JsNumber::of(0.0), true);
                    return JsUndefined::instance();
                }
                $first = $o->get('0');
                // Per §23.1.3.27 every Set/DeletePropertyOrThrow call uses
                // strict=true, so non-writable / non-configurable elements
                // throw rather than silently failing mid-shift.
                for ($i = 1; $i < $len; $i++) {
                    $from = (string) $i;
                    $to = (string) ($i - 1);
                    if ($o->has($from)) {
                        $o->set($to, $o->get($from), true);
                    } else {
                        if (!$o->delete($to, true)) {
                            throw new \Phasis\Exceptions\TypeError("Cannot delete property '{$to}'");
                        }
                    }
                }
                if (!$o->delete((string) ($len - 1), true)) {
                    throw new \Phasis\Exceptions\TypeError("Cannot delete property '" . ($len - 1) . "'");
                }
                // Per spec, Set(O, "length", len - 1, true).
                $o->set('length', JsNumber::of((float) ($len - 1)), true);
                return $first;
            },
            0
        ), true, false, true));

        $proto->defineOwnProperty('unshift', PropertyDescriptor::data(JsFunction::fromCallable(
            'unshift',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                // Per §23.1.3.34 step 2: len = ToLength(Get(O, "length")).
                $len = TypeConversion::toLength($o->get('length'));
                $count = count($args);
                // Per §23.1.3.34: reject if new length would exceed 2^53-1.
                if ($count > 0 && ($len + $count) > 9007199254740991) {
                    throw new TypeError(
                        'Array.prototype.unshift: length would exceed 2^53 - 1',
                    );
                }
                $deleteOrThrow = static function (JsObject $obj, string $key): void {
                    if (!$obj->delete($key, true)) {
                        throw new \Phasis\Exceptions\TypeError(
                            "Cannot delete property '{$key}'",
                        );
                    }
                };
                // Only shift and insert if argCount > 0. With no args, spec
                // skips the loop and just sets length = ToLength(len) back,
                // which clamps values above 2^53-1 to 2^53-1.
                if ($count > 0) {
                    for ($k = $len - 1; $k >= 0; $k--) {
                        $from = self::floatIndexToKey((float) $k);
                        $to = self::floatIndexToKey((float) ($k + $count));
                        if ($o->has($from)) {
                            // Per §23.1.3.34 step 7.c: Set(O, to, fromValue, true).
                            $o->set($to, $o->get($from), true);
                        } else {
                            $deleteOrThrow($o, $to);
                        }
                    }
                    foreach ($args as $i => $arg) {
                        // Per §23.1.3.34 step 7.d.i: Set(O, toString(j), E, true).
                        $o->set((string) $i, $arg, true);
                    }
                }
                $newLen = $len + $count;
                $o->set('length', JsNumber::of((float) $newLen), true);
                return JsNumber::of((float) $newLen);
            },
            1
        ), true, false, true));

        $indexOfFn = JsFunction::fromCallable(
            'indexOf',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $len = self::lengthOfArrayLike($o);
                if ($len === 0) {
                    return JsNumber::of(-1.0);
                }
                $search = $args[0] ?? JsUndefined::instance();
                $nNum = isset($args[1]) ? TypeConversion::toNumber($args[1]) : 0.0;
                if (is_nan($nNum)) {
                    $nNum = 0.0;
                }
                if ($nNum === INF || $nNum >= $len) {
                    return JsNumber::of(-1.0);
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
                            return JsNumber::of((float) $i);
                        }
                    }
                    return JsNumber::of(-1.0);
                }

                for ($i = $k; $i < $len; $i++) {
                    $key = (string) $i;
                    if ($o->has($key) && AbstractOperations::strictEquals($o->get($key), $search)) {
                        return JsNumber::of((float) $i);
                    }
                }
                return JsNumber::of(-1.0);
            },
            1
        );
        $indexOfFn->builtinKind = 'array.indexOf';
        $proto->defineOwnProperty('indexOf', PropertyDescriptor::data($indexOfFn, true, false, true));

        $proto->defineOwnProperty('lastIndexOf', PropertyDescriptor::data(JsFunction::fromCallable(
            'lastIndexOf',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $len = self::lengthOfArrayLike($o);
                if ($len === 0) {
                    return JsNumber::of(-1.0);
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
                        return JsNumber::of(-1.0);
                    }
                    $k = $len + (int) $nNum;
                }

                if (self::shouldUseSparseIndexScan($o, $len)) {
                    foreach (self::numericPropertyIndicesInRange($o, 0, $k, true) as $i) {
                        $key = (string) $i;
                        if (AbstractOperations::strictEquals($o->get($key), $search)) {
                            return JsNumber::of((float) $i);
                        }
                    }
                    return JsNumber::of(-1.0);
                }

                for ($i = $k; $i >= 0; $i--) {
                    $key = (string) $i;
                    if ($o->has($key) && AbstractOperations::strictEquals($o->get($key), $search)) {
                        return JsNumber::of((float) $i);
                    }
                }
                return JsNumber::of(-1.0);
            },
            1,
        ), true, false, true));

        $includesFn = JsFunction::fromCallable(
            'includes',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                // Per §23.1.3.13 step 2: `Let len be ? LengthOfArrayLike(O)`
                // which returns ToLength (up to 2^53-1). Don't clamp to 2^32-1.
                $len = TypeConversion::toLength($o->get('length'));
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
        );
        $includesFn->builtinKind = 'array.includes';
        $proto->defineOwnProperty('includes', PropertyDescriptor::data($includesFn, true, false, true));

        $joinFn = JsFunction::fromCallable(
            'join',
            function (JsValue $this_, array $args): JsValue {
                $this_ = self::toObject($this_);
                // Spec §23.1.3.15 reads length BEFORE coercing separator, so
                // observable length.valueOf() fires before separator.toString().
                $len = self::getLen($this_);
                $sep = isset($args[0]) && !$args[0] instanceof JsUndefined
                    ? TypeConversion::toString($args[0]) : ',';
                $parts = [];
                for ($i = 0; $i < $len; $i++) {
                    $v = $this_->get((string) $i);
                    $parts[] = ($v instanceof JsUndefined || $v instanceof JsNull)
                    ? '' : TypeConversion::toString($v);
                }
                return new JsString(implode($sep, $parts));
            },
            1
        );
        $joinFn->builtinKind = 'array.join';
        $proto->defineOwnProperty('join', PropertyDescriptor::data($joinFn, true, false, true));

        $sliceFn = JsFunction::fromCallable(
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
                    throw new \Phasis\Exceptions\RangeError('Invalid array length');
                }

                // ArraySpeciesCreate(O, count) per spec.
                $a = self::arraySpeciesCreate($this_, $count);
                $n = 0;
                for ($i = $start; $i < $end; $i++, $n++) {
                    $from = (string) $i;
                    if ($this_->has($from)) {
                        // Per §23.1.3.28 step 11.c.iii: CreateDataPropertyOrThrow.
                        $ok = $a->defineOwnProperty(
                            (string) $n,
                            PropertyDescriptor::data($this_->get($from), true, true, true),
                        );
                        if (!$ok) {
                            throw new \Phasis\Exceptions\TypeError(
                                "Cannot define property '{$n}' on target",
                            );
                        }
                    }
                }
                if ($a instanceof JsArray) {
                    $a->setLength($count);
                } else {
                    $a->set('length', JsNumber::of((float) $count));
                }
                return $a;
            },
            2
        );
        $sliceFn->builtinKind = 'array.slice';
        $proto->defineOwnProperty('slice', PropertyDescriptor::data($sliceFn, true, false, true));

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
                    $result->set('length', JsNumber::of((float) $n));
                }
                return $result;
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('reverse', PropertyDescriptor::data(JsFunction::fromCallable(
            'reverse',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                // Per §23.1.3.23: LengthOfArrayLike (ToLength, up to 2^53-1).
                // getLen clamps at 2^32-1 which misses sparse indices beyond
                // that range — breaks tests that probe getters near 2^53.
                $lenF = TypeConversion::toLength($o->get('length'));
                // Swap positions from both ends. Use float to index-string so
                // keys beyond PHP_INT_MAX stringify correctly for HasProperty
                // and Get. Iterate until lower>=upper which handles the
                // middle-exclusive boundary without an explicit floor(len/2).
                // Per §23.1.3.23, each loop iteration performs the steps
                // in strict order: HasProperty(lower), if present Get(lower),
                // HasProperty(upper), if present Get(upper), then swap or
                // delete. Reading lower can mutate the object (e.g. via a
                // side-effectful getter) so the upper HasProperty must be
                // re-evaluated after reading lower.
                $lower = 0.0;
                while ($lower < $lenF - 1 - $lower) {
                    $upper = $lenF - 1 - $lower;
                    $lowerKey = self::floatIndexToKey($lower);
                    $upperKey = self::floatIndexToKey($upper);
                    $lowerExists = $o->has($lowerKey);
                    $lowerVal = $lowerExists ? $o->get($lowerKey) : null;
                    $upperExists = $o->has($upperKey);
                    $upperVal = $upperExists ? $o->get($upperKey) : null;
                    if ($lowerExists && $upperExists) {
                        $o->set($lowerKey, $upperVal, true);
                        $o->set($upperKey, $lowerVal, true);
                    } elseif (!$lowerExists && $upperExists) {
                        $o->set($lowerKey, $upperVal, true);
                        if (!$o->delete($upperKey)) {
                            throw new TypeError(
                                "Cannot delete property '{$upperKey}'",
                            );
                        }
                    } elseif ($lowerExists) {
                        if (!$o->delete($lowerKey)) {
                            throw new TypeError(
                                "Cannot delete property '{$lowerKey}'",
                            );
                        }
                        $o->set($upperKey, $lowerVal, true);
                    }
                    $lower++;
                }
                return $o;
            },
            0
        ), true, false, true));

        $mapFn = JsFunction::fromCallable(
            'map',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                // Per §23.1.3.15 step 2: `Let len be ? LengthOfArrayLike(O)`.
                // LengthOfArrayLike returns ToLength (up to 2^53-1), not the
                // 2^32-1-clamped getLen. arraySpeciesCreate will surface a
                // RangeError for lengths that exceed the Array max.
                $len = TypeConversion::toLength($o->get('length'));
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
                        $mapped = $callback->call($thisArg, [$val, JsNumber::of((float) $i), $o]);
                        // Per §23.1.3.15 step 4.c.iii.3: CreateDataPropertyOrThrow.
                        $ok = $result->defineOwnProperty(
                            $key,
                            PropertyDescriptor::data($mapped, true, true, true),
                        );
                        if (!$ok) {
                            throw new \Phasis\Exceptions\TypeError(
                                "Cannot define property '{$key}' on target",
                            );
                        }
                    }
                }
                return $result;
            },
            1
        );
        // Tag for VM inline path: dense JsArray receiver + JsFunction
        // callback runs the iteration in a tight host loop, dispatching
        // the callback's phpCompiled closure directly when available.
        $mapFn->builtinKind = 'array.map';
        $proto->defineOwnProperty('map', PropertyDescriptor::data($mapFn, true, false, true));

        $filterFn = JsFunction::fromCallable(
            'filter',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                // Per §23.1.3.8 step 2: ToLength(length) runs before the
                // IsCallable(callback) check — observe length-getter side
                // effects even when the callback is invalid.
                $len = TypeConversion::toLength($o->get('length'));
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('filter callback is not a function');
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
                    $keep = $callback->call($thisArg, [$val, JsNumber::of((float) $i), $o]);
                    if (TypeConversion::toBoolean($keep)) {
                        // Per §23.1.3.8 step 4.c.iii.3: CreateDataPropertyOrThrow.
                        $ok = $a->defineOwnProperty(
                            (string) $to,
                            PropertyDescriptor::data($val, true, true, true),
                        );
                        if (!$ok) {
                            throw new \Phasis\Exceptions\TypeError(
                                "Cannot define property '{$to}' on target",
                            );
                        }
                        $to++;
                    }
                }
                // Per spec §23.1.3.8 Array.prototype.filter: no final
                // `Set(A, "length", to)`. CreateDataPropertyOrThrow on
                // sequential integer keys already updates a JsArray's
                // length automatically; a non-Array species result
                // (e.g. Proxy) keeps whatever length the species set.
                return $a;
            },
            1
        );
        $filterFn->builtinKind = 'array.filter';
        $proto->defineOwnProperty('filter', PropertyDescriptor::data($filterFn, true, false, true));

        $reduceFn = JsFunction::fromCallable(
            'reduce',
            function (JsValue $this_, array $args): JsValue {
                // Per spec: read length BEFORE validating the callback so the
                // length getter's side effects run even when the callback check throws.
                $o = self::toObject($this_);
                $len = self::getLen($o);
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('reduce callback is not a function');
                }
                $hasInitial = array_key_exists(1, $args);
                $acc = $hasInitial ? $args[1] : null;
                $start = 0;
                if (!$hasInitial) {
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
                            [$acc, $o->get((string) $i), JsNumber::of((float) $i), $o],
                        );
                    }
                }
                return $acc;
            },
            1
        );
        $reduceFn->builtinKind = 'array.reduce';
        $proto->defineOwnProperty('reduce', PropertyDescriptor::data($reduceFn, true, false, true));

        $proto->defineOwnProperty('reduceRight', PropertyDescriptor::data(JsFunction::fromCallable(
            'reduceRight',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                // Per §23.1.3.24 steps 1-3: ToObject(this) then ToLength(length)
                // is read before IsCallable(callback) — the length getter's
                // side effects must be observable even if the callback isn't
                // a function.
                $len = self::lengthOfArrayLike($o);
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('reduceRight callback is not a function');
                }
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
                        $idx = JsNumber::of((float) $index);
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
                        $idx = JsNumber::of((float) $i);
                        $acc = $callback->call(JsUndefined::instance(), [$acc, $val, $idx, $o]);
                    }
                }
                return $acc;
            },
            1,
        ), true, false, true));

        $forEachFn = JsFunction::fromCallable(
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
                        $callback->call($thisArg, [$o->get((string) $i), JsNumber::of((float) $i), $o]);
                    }
                }
                return JsUndefined::instance();
            },
            1
        );
        $forEachFn->builtinKind = 'array.forEach';
        $proto->defineOwnProperty('forEach', PropertyDescriptor::data($forEachFn, true, false, true));

        $proto->defineOwnProperty('find', PropertyDescriptor::data(JsFunction::fromCallable(
            'find',
            function (JsValue $this_, array $args): JsValue {
                $this_ = self::toObject($this_);
                // Per §23.1.3.9 step 2: LengthOfArrayLike before the
                // IsCallable check, so a throwing length getter or valueOf
                // surfaces before the "not a function" TypeError.
                $len = TypeConversion::toLength($this_->get('length'));
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('find callback is not a function');
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined) ? $args[1] : JsUndefined::instance();
                for ($i = 0; $i < $len; $i++) {
                    $val = $this_->get((string) $i);
                    $result = $callback->call($thisArg, [$val, JsNumber::of((float) $i), $this_]);
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
                    $result = $callback->call($thisArg, [$val, JsNumber::of((float) $i), $this_]);
                    if (TypeConversion::toBoolean($result)) {
                        return JsNumber::of((float) $i);
                    }
                }
                return JsNumber::of(-1.0);
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
                    $result = $callback->call($thisArg, [$val, JsNumber::of((float) $i), $o]);
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
                    $result = $callback->call($thisArg, [$val, JsNumber::of((float) $i), $o]);
                    if (TypeConversion::toBoolean($result)) {
                        return JsNumber::of((float) $i);
                    }
                }
                return JsNumber::of(-1.0);
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
                        $result = $callback->call($thisArg, [$o->get($key), JsNumber::of((float) $i), $o]);
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
                        $result = $callback->call($thisArg, [$o->get($key), JsNumber::of((float) $i), $o]);
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
                    $a->set('length', JsNumber::of((float) $finalIndex));
                }
                return $a;
            },
            0
        ), true, false, true));

        $proto->defineOwnProperty('flatMap', PropertyDescriptor::data(JsFunction::fromCallable(
            'flatMap',
            function (JsValue $this_, array $args): JsValue {
                $o = self::toObject($this_);
                $sourceLen = self::lengthOfArrayLike($o);
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('flatMap callback is not a function');
                }
                $thisArg = (isset($args[1]) && !$args[1] instanceof JsUndefined)
                    ? $args[1]
                    : JsUndefined::instance();
                // Per §23.1.3.12 Array.prototype.flatMap: delegate to
                // FlattenIntoArray with depth=1 and the mapper function.
                // This routes through the shared IsArray check so Proxy
                // wrappers of arrays are flattened like native arrays.
                $a = self::arraySpeciesCreate($o, 0);
                $finalIndex = self::specFlattenIntoArray($a, $o, $sourceLen, 0, 1, $callback, $thisArg);
                if ($a instanceof JsArray) {
                    $a->setLength($finalIndex);
                }
                return $a;
            },
            1
        ), true, false, true));

        $fillFn = JsFunction::fromCallable(
            'fill',
            function (JsValue $this_, array $args): JsValue {
                // Spec §23.1.3.7 uses Set(O, Pk, value, true) — failure throws
                // TypeError (getter-only/read-only/non-extensible).
                if ($this_ instanceof JsNull || $this_ instanceof JsUndefined) {
                    throw new TypeError('Array.prototype.fill called on null or undefined');
                }
                $this_ = self::toObject($this_);
                $len = self::lengthOfArrayLike($this_);
                $value = $args[0] ?? JsUndefined::instance();
                $relStart = isset($args[1]) ? TypeConversion::toIntegerOrInfinity($args[1]) : 0.0;
                $relEnd = (isset($args[2]) && !$args[2] instanceof JsUndefined)
                    ? TypeConversion::toIntegerOrInfinity($args[2]) : (float) $len;
                $start = self::normalizeRelativeIndex($relStart, $len);
                $end = self::normalizeRelativeIndex($relEnd, $len);
                for ($i = $start; $i < $end; $i++) {
                    $this_->set((string) $i, $value, true);
                }
                return $this_;
            },
            1
        );
        $fillFn->builtinKind = 'array.fill';
        $proto->defineOwnProperty('fill', PropertyDescriptor::data($fillFn, true, false, true));

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
                // Per §23.1.3.4 Array.prototype.copyWithin: when the source
                // slot is absent, DeletePropertyOrThrow is used — it throws
                // TypeError if [[Delete]] returns false (e.g. non-configurable).
                $deleteOrThrow = static function (JsObject $o, string $key): void {
                    if (!$o->delete($key, true)) {
                        throw new \Phasis\Exceptions\TypeError(
                            "Cannot delete property '{$key}'",
                        );
                    }
                };
                // Copy in correct direction to handle overlapping ranges.
                if ($start < $target && $target < $start + $count) {
                    for ($i = $count - 1; $i >= 0; $i--) {
                        $from = (string) ($start + $i);
                        $to = (string) ($target + $i);
                        if ($this_->has($from)) {
                            $this_->set($to, $this_->get($from));
                        } else {
                            $deleteOrThrow($this_, $to);
                        }
                    }
                } else {
                    for ($i = 0; $i < $count; $i++) {
                        $from = (string) ($start + $i);
                        $to = (string) ($target + $i);
                        if ($this_->has($from)) {
                            $this_->set($to, $this_->get($from));
                        } else {
                            $deleteOrThrow($this_, $to);
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

                // Per §23.1.3.26 steps 5-7: splice argument count changes
                // actualDeleteCount. Zero args → 0. One arg → len-start.
                // Two+ args → clamp arg[1] to [0, len-start].
                $argCount = count($args);
                if ($argCount === 0) {
                    $deleteCount = 0;
                } elseif ($argCount === 1) {
                    $deleteCount = $len - $start;
                } else {
                    $relativeDeleteCount = TypeConversion::toIntegerOrInfinity($args[1]);
                    if ($relativeDeleteCount === INF) {
                        $deleteCount = $len - $start;
                    } else {
                        $deleteCount = max(0, (int) $relativeDeleteCount);
                    }
                    $deleteCount = min($deleteCount, $len - $start);
                }
                $insertItems = array_slice($args, 2);

                $insertCount = count($insertItems);
                // Per spec step 4: length + insertCount - actualDeleteCount
                // must not exceed 2^53-1.
                if ($len + $insertCount - $deleteCount > 9007199254740991) {
                    throw new \Phasis\Exceptions\TypeError('Array length exceeds the supported limit');
                }

                // ArraySpeciesCreate(O, actualDeleteCount) per spec.
                $removed = self::arraySpeciesCreate($this_, $deleteCount);
                for ($i = 0; $i < $deleteCount; $i++) {
                    $from = (string) ($start + $i);
                    if ($this_->has($from)) {
                        $fromValue = $this_->get($from);
                        // CreateDataPropertyOrThrow(A, ToString(i), fromValue).
                        $ok = $removed->defineOwnProperty(
                            (string) $i,
                            PropertyDescriptor::data($fromValue, true, true, true),
                        );
                        if (!$ok) {
                            throw new TypeError(
                                "Cannot define property '{$i}' on result object",
                            );
                        }
                    }
                }
                // Per spec step 8: Set(A, "length", actualDeleteCount, true).
                $removed->set('length', JsNumber::of((float) $deleteCount), true);

                $diff = $insertCount - $deleteCount;
                $newLen = $len + $diff;

                if ($diff > 0) {
                    // Shift elements right. Iterate from (len-deleteCount)-1
                    // down to start, placing at position k+insertCount-1.
                    for ($k = $len - $deleteCount; $k > $start; $k--) {
                        $from = (string) ($k + $deleteCount - 1);
                        $to = (string) ($k + $insertCount - 1);
                        if ($this_->has($from)) {
                            $this_->set($to, $this_->get($from), true);
                        } else {
                            // DeletePropertyOrThrow.
                            if (!$this_->delete($to)) {
                                throw new TypeError(
                                    "Cannot delete property '{$to}'",
                                );
                            }
                        }
                    }
                } elseif ($diff < 0) {
                    // Shift elements left. Per spec §23.1.3.26 step 11.
                    for ($k = $start; $k < $len - $deleteCount; $k++) {
                        $from = (string) ($k + $deleteCount);
                        $to = (string) ($k + $insertCount);
                        if ($this_->has($from)) {
                            $this_->set($to, $this_->get($from), true);
                        } else {
                            if (!$this_->delete($to)) {
                                throw new TypeError(
                                    "Cannot delete property '{$to}'",
                                );
                            }
                        }
                    }
                    // Delete trailing slots (indices newLen..len-1).
                    for ($k = $len; $k > $newLen; $k--) {
                        $idx = (string) ($k - 1);
                        if (!$this_->delete($idx)) {
                            throw new TypeError(
                                "Cannot delete property '{$idx}'",
                            );
                        }
                    }
                }

                // Insert new items at $start..$start+insertCount-1.
                foreach ($insertItems as $idx => $item) {
                    $this_->set((string) ($start + $idx), $item, true);
                }

                // Per spec, Set(O, "length", newLen, true): throw on failure.
                $this_->set('length', JsNumber::of((float) $newLen), true);
                return $removed;
            },
            2
        ), true, false, true));

        $proto->defineOwnProperty('at', PropertyDescriptor::data(JsFunction::fromCallable(
            'at',
            function (JsValue $this_, array $args): JsValue {
                $this_ = self::toObject($this_);
                $len = self::getLen($this_);
                $relative = isset($args[0])
                    ? TypeConversion::toIntegerOrInfinity($args[0])
                    : 0.0;
                $k = $relative >= 0 ? $relative : $len + $relative;
                if ($k < 0 || $k >= $len) {
                    return JsUndefined::instance();
                }
                return $this_->get((string) (int) $k);
            },
            1
        ), true, false, true));

        $proto->defineOwnProperty('sort', PropertyDescriptor::data(JsFunction::fromCallable(
            'sort',
            function (JsValue $this_, array $args): JsValue {
                // Per spec, if comparefn is not undefined and not callable, throw TypeError
                // before accessing the object's length. Callable includes JsFunction
                // and a JsProxy whose target has a [[Call]] slot.
                $compareFnArg = $args[0] ?? JsUndefined::instance();
                $isCallableCompareFn = $compareFnArg instanceof JsFunction
                    || ($compareFnArg instanceof \Phasis\Value\JsProxy && $compareFnArg->isCallable());
                if (!$compareFnArg instanceof JsUndefined && !$isCallableCompareFn) {
                    throw new TypeError($compareFnArg->display() . ' is not a function');
                }
                $this_ = self::toObject($this_);
                $compareFn = $isCallableCompareFn ? $compareFnArg : null;
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
                        if ($compareFn instanceof \Phasis\Value\JsProxy) {
                            $result = $compareFn->apply(JsUndefined::instance(), [$a, $b]);
                        } else {
                            $result = $compareFn->call(JsUndefined::instance(), [$a, $b]);
                        }
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
                // Write sorted elements. Per §23.1.3.30 step 7, every Set
                // call uses strict=true so non-writable indices throw rather
                // than silently dropping the assignment.
                for ($i = 0; $i < $itemCount; $i++) {
                    $this_->set((string) $i, $items[$i], true);
                }
                // Delete trailing holes (indices that no longer have values).
                // Step 8 uses DeletePropertyOrThrow.
                for ($i = $itemCount; $i < $len; $i++) {
                    if (!$this_->delete((string) $i, true)) {
                        throw new \Phasis\Exceptions\TypeError(
                            "Cannot delete property '{$i}' of '[object Array]'",
                        );
                    }
                }
                return $this_;
            },
            1
        ), true, false, true));

        // Capture a reference to the intrinsic %Object.prototype.toString%
        // at install time. Per §23.1.3.33 step 3, Array.prototype.toString
        // falls back to this intrinsic — not a lookup on the receiver —
        // which means the fallback must survive user deletion of
        // Object.prototype.toString.
        $intrinsicObjectToString = null;
        $objProtoIntrinsic = JsObject::getGlobalPrototype();
        if ($objProtoIntrinsic !== null) {
            $val = $objProtoIntrinsic->get('toString');
            if ($val instanceof JsFunction) {
                $intrinsicObjectToString = $val;
            }
        }
        $proto->defineOwnProperty('toString', PropertyDescriptor::data(JsFunction::fromCallable(
            'toString',
            function (JsValue $this_, array $args) use ($intrinsicObjectToString): JsValue {
                $array = self::toObject($this_);
                $join = $array->get('join');
                if ($join instanceof JsFunction) {
                    return new JsString(TypeConversion::toString($join->call($array, [])));
                }
                // Fall back to the captured %Object.prototype.toString%
                // intrinsic — not a live lookup — per §23.1.3.33.
                if ($intrinsicObjectToString !== null) {
                    return new JsString(TypeConversion::toString(
                        $intrinsicObjectToString->call($array, []),
                    ));
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
                    throw new \Phasis\Exceptions\TypeError('Cannot convert undefined or null to object');
                }
                // Per spec: pass exactly (locales, options) through to each element's
                // toLocaleString. Skip undefined/null elements (they contribute "").
                $locales = $args[0] ?? JsUndefined::instance();
                $options = $args[1] ?? JsUndefined::instance();
                $forward = [$locales, $options];
                $len = self::getLen($this_);
                $parts = [];
                for ($i = 0; $i < $len; $i++) {
                    $elem = $this_->get((string) $i);
                    if ($elem instanceof JsUndefined || $elem instanceof JsNull) {
                        $parts[] = '';
                        continue;
                    }
                    if ($elem instanceof JsObject) {
                        $fn = $elem->get('toLocaleString');
                        if ($fn instanceof JsFunction) {
                            $parts[] = TypeConversion::toString($fn->call($elem, $forward));
                            continue;
                        }
                    }
                    // Per spec Invoke(V, P, args) = Call(GetV(V, P), V, args):
                    // GetV(V, P) does ToObject(V).[[Get]](P, V) so a getter on
                    // the prototype is called with the original primitive as
                    // receiver. The looked-up function is then called with V
                    // as thisArg.
                    $wrapper = TypeConversion::toObject($elem);
                    $fn = $wrapper->getWithValueReceiver('toLocaleString', $elem);
                    if ($fn instanceof JsFunction) {
                        $parts[] = TypeConversion::toString($fn->call($elem, $forward));
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
                    throw new \Phasis\Exceptions\RangeError('Invalid array length');
                }
                $result = new JsArray();
                for ($k = 0; $k < $len; $k++) {
                    $from = (string) ($len - $k - 1);
                    $result->defineOwnProperty((string) $k, PropertyDescriptor::data($o->get($from), true, true, true));
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
                $isCallableCompareFn = $compareFnArg instanceof JsFunction
                    || ($compareFnArg instanceof \Phasis\Value\JsProxy && $compareFnArg->isCallable());
                if (!$compareFnArg instanceof JsUndefined && !$isCallableCompareFn) {
                    throw new TypeError($compareFnArg->display() . ' is not a function');
                }
                $o = self::toObject($this_);
                $len = self::lengthOfArrayLike($o);
                if ($len > 4294967295) {
                    throw new \Phasis\Exceptions\RangeError('Invalid array length');
                }
                $compareFn = $isCallableCompareFn ? $compareFnArg : null;
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
                        $result = $compareFn instanceof \Phasis\Value\JsProxy
                            ? $compareFn->apply(JsUndefined::instance(), [$a, $b])
                            : $compareFn->call(JsUndefined::instance(), [$a, $b]);
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
                    $result->defineOwnProperty((string) $k, PropertyDescriptor::data($items[$k], true, true, true));
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
                    throw new \Phasis\Exceptions\RangeError('Invalid array length');
                }

                // Build the result list densely, then hand it to the JsArray
                // constructor. Avoids defineOwnProperty per element, which
                // dominates this method when called in a tight loop.
                $resultElements = [];
                for ($i = 0; $i < $actualStart; $i++) {
                    $resultElements[] = $o->get((string) $i);
                }
                foreach ($insertItems as $item) {
                    $resultElements[] = $item;
                }
                for ($i = $actualStart + $actualDeleteCount; $i < $len; $i++) {
                    $resultElements[] = $o->get((string) $i);
                }
                $result = new JsArray($resultElements);
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
                    throw new \Phasis\Exceptions\RangeError('Invalid index');
                }
                $intRelative = (int) $relativeIndex;
                if ($relativeIndex >= 0) {
                    $actualIndex = $intRelative;
                } else {
                    $actualIndex = $len + $intRelative;
                }
                $value = $args[1] ?? JsUndefined::instance();
                if ($actualIndex < 0 || $actualIndex >= $len) {
                    throw new \Phasis\Exceptions\RangeError('Invalid index');
                }
                if ($len > 4294967295) {
                    throw new \Phasis\Exceptions\RangeError('Invalid array length');
                }
                $result = new JsArray();
                for ($k = 0; $k < $len; $k++) {
                    $v = ($k === $actualIndex) ? $value : $o->get((string) $k);
                    // CreateDataPropertyOrThrow bypasses [[Set]] traps/setters
                    // on the receiver's prototype chain.
                    $result->defineOwnProperty((string) $k, PropertyDescriptor::data($v, true, true, true));
                }
                $result->setLength($len);
                return $result;
            },
            2
        ), true, false, true));
    }
}
