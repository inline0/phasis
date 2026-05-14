<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\TypedArray;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Exceptions\SyntaxError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\AbstractOperations;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsBigInt;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsDataView;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsSharedArrayBuffer;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\BuiltIn\SymbolConstructor;
use Phasis\BuiltIn\ArrayConstructor;
use Phasis\Value\JsProxy;

/**
 * TypedArrayConstructor trait part: PrototypeMethods. Composed into
 * TypedArrayConstructor via `use TypedArray\PrototypeMethods;`.
 */
trait PrototypeMethods
{
    /**
     * Install prototype methods for a typed array type.
     */
    private static function installTypedArrayPrototypeMethods(JsObject $proto, string $typeName): void
    {
        // set(source, offset).
        $setFn = JsFunction::fromCallable(
            'set',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.set called on incompatible receiver");
                }

                $source = $args[0] ?? JsUndefined::instance();
                // Per spec step 7: ToIntegerOrInfinity(offset), may detach buffer via valueOf.
                $offset = isset($args[1]) ? self::toInteger($args[1]) : 0;

                // Per spec: throw RangeError if offset < 0.
                if ($offset < 0) {
                    throw new RangeError('Offset is out of bounds');
                }

                // Per spec step 9: check detached AFTER offset coercion.
                $this_->validateNotDetached();
                // Capture the target length before any user-supplied coercion
                // can detach the buffer. Per current spec
                // (SetTypedArrayFromArrayLike step 3), size checks use this
                // captured value; once the source's length getter detaches the
                // buffer, the inner copy loop silently no-ops via
                // TypedArraySetElement.
                $capturedTargetLen = $this_->getLength();

                $isBigTarget = $this_->isBigIntArray();

                if ($source instanceof JsTypedArray) {
                    // Per spec step 12: check source buffer is not detached.
                    $source->validateNotDetached();
                    $srcLen = $source->getLength();
                    $targetLen = $this_->getLength();
                    // Per spec: if one is BigInt and the other is not, throw TypeError.
                    $isBigSrc = $source->isBigIntArray();
                    if ($isBigSrc !== $isBigTarget) {
                        throw new \Phasis\Exceptions\TypeError(
                            'Cannot mix BigInt and other types, use explicit conversions'
                        );
                    }
                    // Per spec: if srcLength + targetOffset > targetLength, throw RangeError.
                    if ($srcLen + $offset > $targetLen) {
                        throw new RangeError('Source is too large');
                    }
                    // Per spec: if same buffer, clone srcBuffer first.
                    if ($source->getBuffer() === $this_->getBuffer()) {
                        $cached = [];
                        for ($i = 0; $i < $srcLen; $i++) {
                            $cached[] = $source->getIndex($i);
                        }
                        for ($i = 0; $i < $srcLen; $i++) {
                            $this_->setIndex($offset + $i, $cached[$i]);
                        }
                    } else {
                        for ($i = 0; $i < $srcLen; $i++) {
                            $this_->setIndex($offset + $i, $source->getIndex($i));
                        }
                    }
                } elseif ($source instanceof JsUndefined || $source instanceof JsNull) {
                    // Per spec: ToObject(source) throws TypeError for undefined/null.
                    throw new \Phasis\Exceptions\TypeError('Cannot convert undefined or null to object');
                } else {
                    // Array-like or primitive source path.
                    // Per spec: src = ToObject(source), srcLength = LengthOfArrayLike(src).
                    $srcLen = 0;
                    $srcObj = null;
                    if ($source instanceof JsArray) {
                        $srcLen = $source->getLength();
                        $srcObj = $source;
                    } elseif ($source instanceof JsObject) {
                        $srcLen = (int) TypeConversion::toNumber($source->get('length'));
                        $srcObj = $source;
                    } elseif ($source instanceof JsString) {
                        $str = $source->value;
                        $srcLen = mb_strlen($str, 'UTF-8');
                        // Build a temporary array-like for string chars.
                        $srcObj = new JsObject();
                        for ($i = 0; $i < $srcLen; $i++) {
                            $srcObj->set(
                                (string) $i,
                                new JsString(mb_substr($str, $i, 1, 'UTF-8')),
                            );
                        }
                        $srcObj->set('length', JsNumber::of((float) $srcLen));
                    } else {
                        // Number, boolean, symbol: ToObject wraps to a primitive
                        // wrapper whose prototype may carry length/indexed
                        // properties (test262 monkey-patches Number.prototype).
                        $srcObj = TypeConversion::toObject($source);
                        $srcLen = (int) TypeConversion::toNumber($srcObj->get('length'));
                        if ($srcLen < 0) {
                            $srcLen = 0;
                        }
                    }

                    // Per current spec, the size check uses the target length
                    // captured before the source-length getter ran. If that
                    // getter detached the buffer, the loop's
                    // TypedArraySetElement silently no-ops; we don't throw.
                    if ($srcLen + $offset > $capturedTargetLen) {
                        throw new RangeError('Source is too large');
                    }

                    for ($i = 0; $i < $srcLen; $i++) {
                        $val = $srcObj->get((string) $i);
                        // Per spec: for BigInt arrays, use ToBigInt; for others, ToNumber.
                        if ($isBigTarget) {
                            $coerced = TypeConversion::toBigInt($val);
                            $this_->setIndex($offset + $i, $coerced);
                        } else {
                            $num = TypeConversion::toNumber($val);
                            $this_->setIndex($offset + $i, JsNumber::of($num));
                        }
                    }
                }

                return JsUndefined::instance();
            },
            1
        );
        $proto->defineOwnProperty('set', PropertyDescriptor::data($setFn, true, false, true));

        // subarray(begin, end): uses SpeciesConstructor per spec.
        $subarrayFn = JsFunction::fromCallable(
            'subarray',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.subarray called on incompatible receiver");
                }
                // Per spec: srcLength is 0 if the source view is currently OOB.
                $srcLength = $this_->isOutOfBounds() ? 0 : $this_->getLength();
                $begin = isset($args[0]) ? self::toInteger($args[0]) : 0;
                $end = isset($args[1]) && !$args[1] instanceof JsUndefined
                    ? self::toInteger($args[1])
                    : null;

                // Resolve begin against srcLength.
                if ($begin < 0) {
                    $begin = max(0, $srcLength + $begin);
                }
                $begin = min($begin, $srcLength);

                $bpe = $this_->getBytesPerElement();
                $beginByteOffset = $this_->getByteOffset() + $begin * $bpe;
                $buffer = $this_->getBuffer();

                // Per spec: if the receiver is auto-length and end is
                // undefined, build the new view as length-tracking by passing
                // only [buffer, beginByteOffset]; otherwise pass the explicit
                // newLength.
                if ($this_->isAutoLength() && $end === null) {
                    return self::typedArraySpeciesCreate(
                        $this_,
                        0,
                        [
                            $buffer,
                            JsNumber::of((float) $beginByteOffset),
                        ],
                    );
                }

                // Resolve end against srcLength.
                if ($end === null) {
                    $end = $srcLength;
                } elseif ($end < 0) {
                    $end = max(0, $srcLength + $end);
                }
                $end = min($end, $srcLength);

                $newLength = max(0, $end - $begin);

                return self::typedArraySpeciesCreate(
                    $this_,
                    $newLength,
                    [
                        $buffer,
                        JsNumber::of((float) $beginByteOffset),
                        JsNumber::of((float) $newLength),
                    ],
                );
            },
            2
        );
        $proto->defineOwnProperty('subarray', PropertyDescriptor::data($subarrayFn, true, false, true));

        // slice(begin, end): uses SpeciesConstructor per spec.
        $sliceFn = JsFunction::fromCallable(
            'slice',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError(
                        "Method {$typeName}.prototype.slice called on incompatible receiver"
                    );
                }
                self::validateTypedArray($this_);
                // Per spec, capture len BEFORE argument coercion.
                $len = $this_->getLength();
                $begin = isset($args[0]) ? self::toInteger($args[0]) : 0;
                $end = isset($args[1]) && !$args[1] instanceof JsUndefined
                    ? self::toInteger($args[1])
                    : null;

                // Resolve begin/end per spec using the captured len.
                if ($begin < 0) {
                    $begin = max(0, $len + $begin);
                }
                $begin = min($begin, $len);
                if ($end === null) {
                    $end = $len;
                } elseif ($end < 0) {
                    $end = max(0, $len + $end);
                }
                $end = min($end, $len);
                $count = max(0, $end - $begin);

                // TypedArraySpeciesCreate via SpeciesConstructor.
                $result = self::typedArraySpeciesCreate($this_, $count);

                if ($count > 0) {
                    // Per spec: re-validate after species ctor, recapture len.
                    self::validateTypedArray($this_);
                    $currentLen = $this_->getLength();
                    // Clamp final to currentLen so we never read beyond the
                    // (possibly resized) source buffer.
                    $effectiveEnd = min($end, $currentLen);
                    $copyCount = max(0, $effectiveEnd - $begin);
                    // Per spec 23.2.3.27 step 18: when source and result
                    // have the same element type, use CopyDataBlockBytes
                    // (byte-for-byte). For float arrays this preserves
                    // exact NaN bit patterns; setIndex's ToNumber path
                    // would otherwise canonicalise SNaN to QNaN.
                    if ($result->getTypeName() === $this_->getTypeName()) {
                        $bpe = $this_->getBytesPerElement();
                        $srcBuffer = $this_->getBuffer();
                        $dstBuffer = $result->getBuffer();
                        $srcOffset = $this_->getByteOffset() + $begin * $bpe;
                        $dstOffset = $result->getByteOffset();
                        $byteCount = $copyCount * $bpe;
                        if (
                            !$srcBuffer->isDetached()
                            && !$dstBuffer->isDetached()
                        ) {
                            // Per spec §23.2.3.27 step 18.d: copy bytes
                            // FORWARD one at a time so overlapping src/dst
                            // regions in the same buffer (species-returned
                            // view aliasing the source) match the spec's
                            // "while targetByteIndex < limit" iteration
                            // exactly. When buffers differ, no overlap is
                            // possible — fall back to the bulk copy.
                            if ($srcBuffer === $dstBuffer) {
                                $data = $srcBuffer->getData();
                                for ($k = 0; $k < $byteCount; $k++) {
                                    $data[$dstOffset + $k] = $data[$srcOffset + $k];
                                }
                                $srcBuffer->setData($data);
                            } else {
                                $srcData = $srcBuffer->getData();
                                $bytes = substr($srcData, $srcOffset, $byteCount);
                                $dstBuffer->writeBytes($dstOffset, $bytes);
                            }
                        }
                    } else {
                        for ($i = 0; $i < $copyCount; $i++) {
                            $result->setIndex($i, $this_->getIndex($begin + $i));
                        }
                    }
                }
                return $result;
            },
            2,
        );
        $proto->defineOwnProperty(
            'slice',
            PropertyDescriptor::data($sliceFn, true, false, true),
        );

        // copyWithin(target, start, end).
        $copyWithinFn = JsFunction::fromCallable(
            'copyWithin',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.copyWithin called on incompatible receiver");
                }
                self::validateTypedArray($this_);
                // Per spec, capture length BEFORE argument coercion.
                $capturedLen = $this_->getLength();
                $target = isset($args[0]) ? self::toInteger($args[0]) : 0;
                $start = isset($args[1]) ? self::toInteger($args[1]) : 0;
                $end = isset($args[2]) && !$args[2] instanceof JsUndefined
                ? self::toInteger($args[2])
                : null;
                // Per spec: check detached AGAIN after argument coercion.
                $this_->validateNotDetached();
                return $this_->copyWithinTyped($target, $start, $end, $capturedLen);
            },
            2
        );
        $proto->defineOwnProperty('copyWithin', PropertyDescriptor::data($copyWithinFn, true, false, true));

        // fill(value, start, end).
        $fillFn = JsFunction::fromCallable(
            'fill',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.fill called on incompatible receiver");
                }
                // Per spec %TypedArray%.prototype.fill (ES2024+):
                //   2. ValidateTypedArray; 3. len = TypedArrayLength(taRecord).
                //   4. Coerce value (ToBigInt / ToNumber) — may resize buffer.
                //   6-13. Start/end resolved against captured len.
                //   14-17. IntegerIndexedElementSet silently no-ops on invalid indices.
                $this_->validateNotDetached();
                $initialLen = $this_->getLength();
                $value = $args[0] ?? JsUndefined::instance();

                if ($this_->isBigIntArray()) {
                    if ($value instanceof \Phasis\Value\JsBigInt) {
                        $coerced = $value;
                    } else {
                        $coerced = TypeConversion::toBigInt($value);
                    }
                } else {
                    $numVal = TypeConversion::toNumber($value);
                    $coerced = JsNumber::of($numVal);
                }

                $start = isset($args[1]) ? self::toInteger($args[1]) : 0;
                $end = isset($args[2]) && !$args[2] instanceof JsUndefined
                    ? self::toInteger($args[2])
                    : null;
                // Per spec: check detached AGAIN after value/start/end coercion.
                $this_->validateNotDetached();
                return $this_->fillTyped($coerced, $start, $end, $initialLen);
            },
            1
        );
        $proto->defineOwnProperty('fill', PropertyDescriptor::data($fillFn, true, false, true));

        // find(predicate, thisArg).
        $findFn = JsFunction::fromCallable(
            'find',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.find called on incompatible receiver");
                }
                self::validateTypedArray($this_);
                $len = $this_->getLength();
                $predicate = $args[0] ?? JsUndefined::instance();
                if (!$predicate instanceof JsFunction) {
                    throw new TypeError('predicate is not a function');
                }
                $thisArg = $args[1] ?? JsUndefined::instance();
                for ($i = 0; $i < $len; $i++) {
                    $el = $this_->getIndex($i);
                    $result = $predicate->call($thisArg, [$el, JsNumber::of((float) $i), $this_]);
                    if (TypeConversion::toBoolean($result)) {
                        return $el;
                    }
                }
                return JsUndefined::instance();
            },
            1
        );
        $proto->defineOwnProperty('find', PropertyDescriptor::data($findFn, true, false, true));

        // findIndex(predicate, thisArg).
        $findIndexFn = JsFunction::fromCallable(
            'findIndex',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.findIndex called on incompatible receiver");
                }
                // Per spec: ValidateTypedArray first, then capture length.
                self::validateTypedArray($this_);
                $len = $this_->getLength();
                $predicate = $args[0] ?? JsUndefined::instance();
                if (!$predicate instanceof JsFunction) {
                    throw new TypeError('predicate is not a function');
                }
                $thisArg = $args[1] ?? JsUndefined::instance();
                for ($i = 0; $i < $len; $i++) {
                    $el = $this_->getIndex($i);
                    $result = $predicate->call($thisArg, [$el, JsNumber::of((float) $i), $this_]);
                    if (TypeConversion::toBoolean($result)) {
                        return JsNumber::of((float) $i);
                    }
                }
                return JsNumber::of(-1.0);
            },
            1
        );
        $proto->defineOwnProperty('findIndex', PropertyDescriptor::data($findIndexFn, true, false, true));

        // forEach(callback, thisArg).
        $forEachFn = JsFunction::fromCallable(
            'forEach',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.forEach called on incompatible receiver");
                }
                // Per spec: ValidateTypedArray first, then capture length.
                self::validateTypedArray($this_);
                $len = $this_->getLength();
                $callback = $args[0] ?? JsUndefined::instance();
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('callback is not a function');
                }
                $thisArg = $args[1] ?? JsUndefined::instance();
                for ($i = 0; $i < $len; $i++) {
                    $callback->call($thisArg, [$this_->getIndex($i), JsNumber::of((float) $i), $this_]);
                }
                return JsUndefined::instance();
            },
            1
        );
        $proto->defineOwnProperty('forEach', PropertyDescriptor::data($forEachFn, true, false, true));

        // map(callback, thisArg): uses SpeciesConstructor per spec.
        $mapFn = JsFunction::fromCallable(
            'map',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.map called on incompatible receiver");
                }
                $this_->validateNotDetached();
                $callback = $args[0] ?? JsUndefined::instance();
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('callback is not a function');
                }
                $thisArg = $args[1] ?? JsUndefined::instance();
                $len = $this_->getLength();
                $result = self::typedArraySpeciesCreate($this_, $len);
                for ($i = 0; $i < $len; $i++) {
                    $mapped = $callback->call(
                        $thisArg,
                        [$this_->getIndex($i), JsNumber::of((float) $i), $this_],
                    );
                    $result->setIndex($i, $mapped);
                }
                return $result;
            },
            1
        );
        $proto->defineOwnProperty('map', PropertyDescriptor::data($mapFn, true, false, true));

        // filter(callback, thisArg): uses SpeciesConstructor per spec.
        $filterFn = JsFunction::fromCallable(
            'filter',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.filter called on incompatible receiver");
                }
                $this_->validateNotDetached();
                $callback = $args[0] ?? JsUndefined::instance();
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('callback is not a function');
                }
                $thisArg = $args[1] ?? JsUndefined::instance();
                $kept = [];
                // Capture length before the loop per spec step 3.
                $len = $this_->getLength();
                for ($i = 0; $i < $len; $i++) {
                    $el = $this_->getIndex($i);
                    $result = $callback->call(
                        $thisArg,
                        [$el, JsNumber::of((float) $i), $this_],
                    );
                    if (TypeConversion::toBoolean($result)) {
                        $kept[] = $el;
                    }
                }
                $filtered = self::typedArraySpeciesCreate($this_, count($kept));
                foreach ($kept as $i => $el) {
                    $filtered->setIndex($i, $el);
                }
                return $filtered;
            },
            1
        );
        $proto->defineOwnProperty('filter', PropertyDescriptor::data($filterFn, true, false, true));

        // reduce(callback, initialValue).
        $reduceFn = JsFunction::fromCallable(
            'reduce',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.reduce called on incompatible receiver");
                }
                self::validateTypedArray($this_);
                $callback = $args[0] ?? JsUndefined::instance();
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('callback is not a function');
                }
                $len = $this_->getLength();
                $k = 0;
                if (isset($args[1])) {
                    $accumulator = $args[1];
                } else {
                    if ($len === 0) {
                        throw new TypeError('Reduce of empty array with no initial value');
                    }
                    $accumulator = $this_->getIndex(0);
                    $k = 1;
                }
                for (; $k < $len; $k++) {
                    $accumulator = $callback->call(
                        JsUndefined::instance(),
                        [$accumulator, $this_->getIndex($k), JsNumber::of((float) $k), $this_],
                    );
                }
                return $accumulator;
            },
            1
        );
        $proto->defineOwnProperty('reduce', PropertyDescriptor::data($reduceFn, true, false, true));

        // reduceRight(callback, initialValue).
        $reduceRightFn = JsFunction::fromCallable(
            'reduceRight',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.reduceRight called on incompatible receiver");
                }
                self::validateTypedArray($this_);
                $callback = $args[0] ?? JsUndefined::instance();
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('callback is not a function');
                }
                $len = $this_->getLength();
                $k = $len - 1;
                if (isset($args[1])) {
                    $accumulator = $args[1];
                } else {
                    if ($len === 0) {
                        throw new TypeError('Reduce of empty array with no initial value');
                    }
                    $accumulator = $this_->getIndex($k);
                    $k--;
                }
                for (; $k >= 0; $k--) {
                    $accumulator = $callback->call(
                        JsUndefined::instance(),
                        [$accumulator, $this_->getIndex($k), JsNumber::of((float) $k), $this_],
                    );
                }
                return $accumulator;
            },
            1
        );
        $proto->defineOwnProperty('reduceRight', PropertyDescriptor::data($reduceRightFn, true, false, true));

        // some(callback, thisArg).
        $someFn = JsFunction::fromCallable(
            'some',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.some called on incompatible receiver");
                }
                // Per spec: ValidateTypedArray first, then capture length.
                self::validateTypedArray($this_);
                $len = $this_->getLength();
                $callback = $args[0] ?? JsUndefined::instance();
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('callback is not a function');
                }
                $thisArg = $args[1] ?? JsUndefined::instance();
                for ($i = 0; $i < $len; $i++) {
                    $result = $callback->call($thisArg, [$this_->getIndex($i), JsNumber::of((float) $i), $this_]);
                    if (TypeConversion::toBoolean($result)) {
                        return new JsBoolean(true);
                    }
                }
                return new JsBoolean(false);
            },
            1
        );
        $proto->defineOwnProperty('some', PropertyDescriptor::data($someFn, true, false, true));

        // every(callback, thisArg).
        $everyFn = JsFunction::fromCallable(
            'every',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.every called on incompatible receiver");
                }
                // Per spec: ValidateTypedArray first, then capture length.
                self::validateTypedArray($this_);
                $len = $this_->getLength();
                $callback = $args[0] ?? JsUndefined::instance();
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('callback is not a function');
                }
                $thisArg = $args[1] ?? JsUndefined::instance();
                for ($i = 0; $i < $len; $i++) {
                    $result = $callback->call($thisArg, [$this_->getIndex($i), JsNumber::of((float) $i), $this_]);
                    if (!TypeConversion::toBoolean($result)) {
                        return new JsBoolean(false);
                    }
                }
                return new JsBoolean(true);
            },
            1
        );
        $proto->defineOwnProperty('every', PropertyDescriptor::data($everyFn, true, false, true));

        // indexOf(searchElement, fromIndex).
        $indexOfFn = JsFunction::fromCallable(
            'indexOf',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.indexOf called on incompatible receiver");
                }
                self::validateTypedArray($this_);
                // Per spec, capture length BEFORE fromIndex coercion.
                $capturedLen = $this_->getLength();
                if ($capturedLen === 0) {
                    return JsNumber::of(-1.0);
                }
                $search = $args[0] ?? JsUndefined::instance();
                $fromIndex = isset($args[1]) ? self::toInteger($args[1]) : 0;
                return JsNumber::of((float) $this_->indexOfTyped($search, $fromIndex, $capturedLen));
            },
            1
        );
        $proto->defineOwnProperty('indexOf', PropertyDescriptor::data($indexOfFn, true, false, true));

        // lastIndexOf(searchElement, fromIndex).
        $lastIndexOfFn = JsFunction::fromCallable(
            'lastIndexOf',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.lastIndexOf called on incompatible receiver");
                }
                $this_->validateNotDetached();
                $len = $this_->getLength();
                // Per spec: if length is 0, return -1 before ToInteger(fromIndex).
                if ($len === 0) {
                    return JsNumber::of(-1.0);
                }
                $search = $args[0] ?? JsUndefined::instance();
                // Per spec: n defaults to len - 1.
                $n = isset($args[1]) ? self::toInteger($args[1]) : $len - 1;
                // Per spec: if n >= 0, k = min(n, len-1); else k = len + n.
                if ($n >= 0) {
                    $k = min($n, $len - 1);
                } else {
                    $k = $len + $n;
                }
                for ($i = $k; $i >= 0; $i--) {
                    $el = $this_->getIndex($i);
                    // Strict equality: NaN !== NaN.
                    if ($el instanceof JsNumber && $search instanceof JsNumber) {
                        if (!is_nan($el->value) && !is_nan($search->value) && $el->value === $search->value) {
                            return JsNumber::of((float) $i);
                        }
                    }
                    if ($el instanceof \Phasis\Value\JsBigInt && $search instanceof \Phasis\Value\JsBigInt) {
                        if ($el->value === $search->value) {
                            return JsNumber::of((float) $i);
                        }
                    }
                }
                return JsNumber::of(-1.0);
            },
            1
        );
        $proto->defineOwnProperty('lastIndexOf', PropertyDescriptor::data($lastIndexOfFn, true, false, true));

        // includes(searchElement, fromIndex).
        $includesFn = JsFunction::fromCallable(
            'includes',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.includes called on incompatible receiver");
                }
                $this_->validateNotDetached();
                // Per spec: len is captured BEFORE fromIndex coercion. If
                // the buffer is detached (or shrunk) during coercion, the
                // loop that follows observes Get returning undefined for
                // out-of-bounds indices, so a search for `undefined` still
                // returns true at those positions.
                $len = $this_->getLength();
                if ($len === 0) {
                    return new JsBoolean(false);
                }
                $search = $args[0] ?? JsUndefined::instance();
                $fromIndex = isset($args[1]) ? self::toInteger($args[1]) : 0;
                if ($fromIndex < 0) {
                    $fromIndex = max(0, $len + $fromIndex);
                }
                $isUndefined = $search instanceof JsUndefined;
                for ($k = $fromIndex; $k < $len; $k++) {
                    if ($this_->getBuffer()->isDetached() || $k >= $this_->getLength()) {
                        // Get returns undefined for OOB; matches only if
                        // the searchElement is undefined.
                        if ($isUndefined) {
                            return new JsBoolean(true);
                        }
                        continue;
                    }
                    $element = $this_->getIndex($k);
                    if (\Phasis\Spec\AbstractOperations::sameValueZero($search, $element)) {
                        return new JsBoolean(true);
                    }
                }
                return new JsBoolean(false);
            },
            1
        );
        $proto->defineOwnProperty('includes', PropertyDescriptor::data($includesFn, true, false, true));

        // join(separator).
        $joinFn = JsFunction::fromCallable(
            'join',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.join called on incompatible receiver");
                }
                // Per spec %TypedArray%.prototype.join:
                //   2. ValidateTypedArray, 3. Let len = TypedArrayLength.
                //   4. Coerce separator (may resize/detach buffer).
                //   5-8. For k in 0..len-1: if Get(O,k) is undefined, next = ""
                //        else next = ToString(element).
                // Coercion can resize the underlying buffer, so the captured
                // len is used to iterate but each element Get is subject to
                // the current (possibly shrunk) buffer bounds.
                self::validateTypedArray($this_);
                $len = $this_->getLength();
                $separator = isset($args[0]) && !$args[0] instanceof JsUndefined
                    ? TypeConversion::toString($args[0])
                    : ',';
                $parts = [];
                $currentLen = $this_->getLength();
                for ($i = 0; $i < $len; $i++) {
                    if ($this_->getBuffer()->isDetached() || $i >= $currentLen) {
                        $parts[] = '';
                    } else {
                        $parts[] = $this_->getIndex($i)->toJsString();
                    }
                }
                return new JsString(implode($separator, $parts));
            },
            1
        );
        $proto->defineOwnProperty('join', PropertyDescriptor::data($joinFn, true, false, true));

        // toString() - delegates to join.
        $toStringFn = JsFunction::fromCallable(
            'toString',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.toString called on incompatible receiver");
                }
                return new JsString($this_->joinTyped(','));
            },
            0
        );
        $proto->defineOwnProperty('toString', PropertyDescriptor::data($toStringFn, true, false, true));

        // reverse().
        $reverseFn = JsFunction::fromCallable(
            'reverse',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.reverse called on incompatible receiver");
                }
                $this_->validateNotDetached();
                return $this_->reverseTyped();
            },
            0
        );
        $proto->defineOwnProperty('reverse', PropertyDescriptor::data($reverseFn, true, false, true));

        // sort(comparefn).
        $sortFn = JsFunction::fromCallable(
            'sort',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.sort called on incompatible receiver");
                }
                $this_->validateNotDetached();
                $arg0 = $args[0] ?? JsUndefined::instance();
                $isCallable = $arg0 instanceof JsFunction
                    || ($arg0 instanceof \Phasis\Value\JsProxy && $arg0->isCallable());
                if (!$arg0 instanceof JsUndefined && !$isCallable) {
                    throw new TypeError('The comparison function must be either a function or undefined');
                }
                $comparefn = $isCallable ? $arg0 : null;

                // Fast path: no comparefn, non-BigInt, non-Float16. Avoids
                // per-element JsNumber allocation and the O(n) buffer-string
                // copy that setIndex() incurs on every write.
                if ($comparefn === null) {
                    $fast = $this_->sortNumericFast();
                    if ($fast !== null) {
                        return $fast;
                    }
                }

                $elements = $this_->toList();

                usort($elements, function (JsValue $a, JsValue $b) use ($comparefn): int {
                    if ($comparefn !== null) {
                        $result = $comparefn->call(JsUndefined::instance(), [$a, $b]);
                        return (int) TypeConversion::toNumber($result);
                    }
                    // Default numeric sort for typed arrays per spec.
                    // BigInt comparisons: compare as integers.
                    if ($a instanceof \Phasis\Value\JsBigInt && $b instanceof \Phasis\Value\JsBigInt) {
                        return \Phasis\Spec\AbstractOperations::bigStrCompPublic($a->value, $b->value);
                    }
                    $an = $a->toNumber();
                    $bn = $b->toNumber();
                    // NaN sorts to end.
                    if (is_nan($an) && is_nan($bn)) {
                        return 0;
                    }
                    if (is_nan($an)) {
                        return 1;
                    }
                    if (is_nan($bn)) {
                        return -1;
                    }
                    // -0 sorts before +0. Use IEEE 754 sign-bit
                    // detection to avoid division by zero on PHP 8+.
                    if ($an === 0.0 && $bn === 0.0) {
                        $aNegZero = JsNumber::isNegativeZero($an);
                        $bNegZero = JsNumber::isNegativeZero($bn);
                        if ($aNegZero && !$bNegZero) {
                            return -1;
                        }
                        if (!$aNegZero && $bNegZero) {
                            return 1;
                        }
                        return 0;
                    }
                    return $an <=> $bn;
                });

                for ($i = 0; $i < count($elements); $i++) {
                    $this_->setIndex($i, $elements[$i]);
                }
                return $this_;
            },
            1
        );
        $proto->defineOwnProperty('sort', PropertyDescriptor::data($sortFn, true, false, true));

        // entries(): per spec uses CreateArrayIterator (shared %ArrayIteratorPrototype%).
        $entriesFn = JsFunction::fromCallable(
            'entries',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.entries called on incompatible receiver");
                }
                self::validateTypedArray($this_);
                return ArrayConstructor::createArrayIteratorFromSymbol($this_, 'key+value');
            },
            0
        );
        $proto->defineOwnProperty('entries', PropertyDescriptor::data($entriesFn, true, false, true));

        // keys(): per spec uses CreateArrayIterator.
        $keysFn = JsFunction::fromCallable(
            'keys',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.keys called on incompatible receiver");
                }
                self::validateTypedArray($this_);
                return ArrayConstructor::createArrayIteratorFromSymbol($this_, 'key');
            },
            0
        );
        $proto->defineOwnProperty('keys', PropertyDescriptor::data($keysFn, true, false, true));

        // values(): per spec uses CreateArrayIterator.
        $valuesFn = JsFunction::fromCallable(
            'values',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.values called on incompatible receiver");
                }
                self::validateTypedArray($this_);
                return ArrayConstructor::createArrayIteratorFromSymbol($this_, 'value');
            },
            0
        );
        $proto->defineOwnProperty('values', PropertyDescriptor::data($valuesFn, true, false, true));

        // findLast(predicate, thisArg).
        $findLastFn = JsFunction::fromCallable(
            'findLast',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.findLast called on incompatible receiver");
                }
                $this_->validateNotDetached();
                $predicate = $args[0] ?? JsUndefined::instance();
                if (!$predicate instanceof JsFunction) {
                    throw new TypeError('predicate is not a function');
                }
                $thisArg = $args[1] ?? JsUndefined::instance();
                for ($i = $this_->getLength() - 1; $i >= 0; $i--) {
                    $el = $this_->getIndex($i);
                    $result = $predicate->call($thisArg, [$el, JsNumber::of((float) $i), $this_]);
                    if (TypeConversion::toBoolean($result)) {
                        return $el;
                    }
                }
                return JsUndefined::instance();
            },
            1
        );
        $proto->defineOwnProperty('findLast', PropertyDescriptor::data($findLastFn, true, false, true));

        // findLastIndex(predicate, thisArg).
        $findLastIndexFn = JsFunction::fromCallable(
            'findLastIndex',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.findLastIndex called on incompatible receiver");
                }
                $this_->validateNotDetached();
                $predicate = $args[0] ?? JsUndefined::instance();
                if (!$predicate instanceof JsFunction) {
                    throw new TypeError('predicate is not a function');
                }
                $thisArg = $args[1] ?? JsUndefined::instance();
                for ($i = $this_->getLength() - 1; $i >= 0; $i--) {
                    $el = $this_->getIndex($i);
                    $result = $predicate->call($thisArg, [$el, JsNumber::of((float) $i), $this_]);
                    if (TypeConversion::toBoolean($result)) {
                        return JsNumber::of((float) $i);
                    }
                }
                return JsNumber::of(-1.0);
            },
            1
        );
        $proto->defineOwnProperty('findLastIndex', PropertyDescriptor::data($findLastIndexFn, true, false, true));

        // at(index).
        $atFn = JsFunction::fromCallable(
            'at',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError("Method {$typeName}.prototype.at called on incompatible receiver");
                }
                self::validateTypedArray($this_);
                $len = $this_->getLength();
                $index = isset($args[0]) ? self::toInteger($args[0]) : 0;
                if ($index < 0) {
                    $index = $len + $index;
                }
                if ($index < 0 || $index >= $len) {
                    return JsUndefined::instance();
                }
                return $this_->getIndex($index);
            },
            1
        );
        $proto->defineOwnProperty('at', PropertyDescriptor::data($atFn, true, false, true));

        // toReversed(): returns a new TypedArray with elements in reverse order.
        // Per spec, ignores Symbol.species and always creates the same type.
        $toReversedFn = JsFunction::fromCallable(
            'toReversed',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError(
                        "Method {$typeName}.prototype.toReversed called on incompatible receiver"
                    );
                }
                $this_->validateNotDetached();
                $len = $this_->getLength();
                $result = JsTypedArray::fromLength(
                    $this_->getTypeName(),
                    $len,
                    $this_->getPrototype(),
                );
                for ($i = 0; $i < $len; $i++) {
                    $result->setIndex($i, $this_->getIndex($len - 1 - $i));
                }
                return $result;
            },
            0,
        );
        $toReversedFn->setNonConstructable();
        $proto->defineOwnProperty(
            'toReversed',
            PropertyDescriptor::data($toReversedFn, true, false, true),
        );

        // toSorted(comparefn): returns a new sorted TypedArray.
        // Per spec, ignores Symbol.species and always creates the same type.
        $toSortedFn = JsFunction::fromCallable(
            'toSorted',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError(
                        "Method {$typeName}.prototype.toSorted called on incompatible receiver"
                    );
                }
                $this_->validateNotDetached();
                $arg0 = $args[0] ?? JsUndefined::instance();
                $isCallable = $arg0 instanceof JsFunction
                    || ($arg0 instanceof \Phasis\Value\JsProxy && $arg0->isCallable());
                if (!$arg0 instanceof JsUndefined && !$isCallable) {
                    throw new TypeError(
                        'The comparison function must be either a function or undefined'
                    );
                }
                $comparefn = $isCallable ? $arg0 : null;
                $elements = $this_->toList();

                usort($elements, function (JsValue $a, JsValue $b) use ($comparefn): int {
                    if ($comparefn !== null) {
                        if ($comparefn instanceof JsFunction) {
                            $result = $comparefn->call(JsUndefined::instance(), [$a, $b]);
                        } else {
                            $result = $comparefn->apply(JsUndefined::instance(), [$a, $b]);
                        }
                        return (int) TypeConversion::toNumber($result);
                    }
                    // Default numeric sort for typed arrays per spec.
                    if (
                        $a instanceof \Phasis\Value\JsBigInt
                        && $b instanceof \Phasis\Value\JsBigInt
                    ) {
                        return \Phasis\Spec\AbstractOperations::bigStrCompPublic(
                            $a->value,
                            $b->value,
                        );
                    }
                    $an = $a->toNumber();
                    $bn = $b->toNumber();
                    if (is_nan($an) && is_nan($bn)) {
                        return 0;
                    }
                    if (is_nan($an)) {
                        return 1;
                    }
                    if (is_nan($bn)) {
                        return -1;
                    }
                    if ($an === 0.0 && $bn === 0.0) {
                        $aNegZero = JsNumber::isNegativeZero($an);
                        $bNegZero = JsNumber::isNegativeZero($bn);
                        if ($aNegZero && !$bNegZero) {
                            return -1;
                        }
                        if (!$aNegZero && $bNegZero) {
                            return 1;
                        }
                        return 0;
                    }
                    return $an <=> $bn;
                });

                $result = JsTypedArray::fromLength(
                    $this_->getTypeName(),
                    count($elements),
                    $this_->getPrototype(),
                );
                for ($i = 0; $i < count($elements); $i++) {
                    $result->setIndex($i, $elements[$i]);
                }
                return $result;
            },
            1,
        );
        $toSortedFn->setNonConstructable();
        $proto->defineOwnProperty(
            'toSorted',
            PropertyDescriptor::data($toSortedFn, true, false, true),
        );

        // with(index, value): returns a new TypedArray with the element at
        // `index` replaced by `value`. Per spec, ignores Symbol.species.
        $withFn = JsFunction::fromCallable(
            'with',
            function (JsValue $this_, array $args) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError(
                        "Method {$typeName}.prototype.with called on incompatible receiver"
                    );
                }
                // Per spec step 3: ValidateTypedArray throws when buffer is detached.
                if ($this_->getBuffer()->isDetached()) {
                    throw new TypeError(
                        "Cannot perform {$typeName}.prototype.with on a detached ArrayBuffer"
                    );
                }
                // Per spec %TypedArray%.prototype.with (ES2023+):
                //   4. Let len be TypedArrayLength(taRecord).
                //   5. Let relativeIndex be ? ToIntegerOrInfinity(index).
                //   ...
                //   8-9. numericValue = ToBigInt(value) or ToNumber(value).
                //   10. If IsValidIntegerIndex(O, F(actualIndex)) is false, throw.
                //   11. Let A be ? TypedArrayCreateSameType(O, « F(len) »).
                // Coercion of index/value may resize a resizable buffer; the
                // IsValidIntegerIndex check at step 10 uses the current
                // buffer length, while A is still sized by the captured len.
                $len = $this_->getLength();
                $relativeIndex = isset($args[0])
                    ? self::toInteger($args[0])
                    : 0;
                $value = $args[1] ?? JsUndefined::instance();

                if ($this_->isBigIntArray()) {
                    $coerced = TypeConversion::toBigInt($value);
                } else {
                    $numVal = TypeConversion::toNumber($value);
                    $coerced = JsNumber::of($numVal);
                }

                // Resolve relative index (against the captured len, per spec).
                $actualIndex = $relativeIndex >= 0
                    ? $relativeIndex
                    : $len + $relativeIndex;

                // IsValidIntegerIndex is checked against the current buffer
                // length (post any resize triggered by coercion above).
                $currentLen = $this_->getLength();
                if ($actualIndex < 0 || $actualIndex >= $currentLen) {
                    throw new RangeError('Invalid index');
                }

                $result = JsTypedArray::fromLength(
                    $this_->getTypeName(),
                    $len,
                    $this_->getPrototype(),
                );
                for ($i = 0; $i < $len; $i++) {
                    if ($i === $actualIndex) {
                        $result->setIndex($i, $coerced);
                    } elseif ($i < $currentLen) {
                        $result->setIndex($i, $this_->getIndex($i));
                    }
                    // else: out-of-bounds read from resized buffer → leave
                    // new element at its default (zero) value.
                }
                return $result;
            },
            2,
        );
        $withFn->setNonConstructable();
        $proto->defineOwnProperty(
            'with',
            PropertyDescriptor::data($withFn, true, false, true),
        );
    }

    /** Install accessor properties on the typed array prototype. */
    private static function installTypedArrayAccessors(JsObject $proto, string $typeName): void
    {
        // length getter.
        $lengthGetter = JsFunction::fromCallable(
            'get length',
            function (JsValue $this_) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError(
                        "Method get {$typeName}.prototype.length called on incompatible receiver"
                    );
                }
                return JsNumber::of((float) $this_->getLength());
            },
            0
        );
        $proto->defineOwnProperty(
            'length',
            PropertyDescriptor::accessor($lengthGetter, null, false, true),
        );

        // buffer getter.
        $bufferGetter = JsFunction::fromCallable(
            'get buffer',
            function (JsValue $this_) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError(
                        "Method get {$typeName}.prototype.buffer called on incompatible receiver"
                    );
                }
                return $this_->getBuffer();
            },
            0
        );
        $proto->defineOwnProperty(
            'buffer',
            PropertyDescriptor::accessor($bufferGetter, null, false, true),
        );

        // byteLength getter.
        $byteLengthGetter = JsFunction::fromCallable(
            'get byteLength',
            function (JsValue $this_) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError(
                        "Method get {$typeName}.prototype.byteLength called on incompatible receiver"
                    );
                }
                return JsNumber::of((float) ($this_->getLength() * $this_->getBytesPerElement()));
            },
            0
        );
        $proto->defineOwnProperty(
            'byteLength',
            PropertyDescriptor::accessor($byteLengthGetter, null, false, true),
        );

        // byteOffset getter.
        $byteOffsetGetter = JsFunction::fromCallable(
            'get byteOffset',
            function (JsValue $this_) use ($typeName): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError(
                        "Method get {$typeName}.prototype.byteOffset called on incompatible receiver"
                    );
                }
                return JsNumber::of((float) $this_->getByteOffset());
            },
            0
        );
        $proto->defineOwnProperty(
            'byteOffset',
            PropertyDescriptor::accessor($byteOffsetGetter, null, false, true),
        );
    }
}
