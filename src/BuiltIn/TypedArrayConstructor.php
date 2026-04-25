<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\RangeError;
use PhpJs\Exceptions\SyntaxError;
use PhpJs\Exceptions\TypeError;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsArray;
use PhpJs\Value\JsArrayBuffer;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsDataView;
use PhpJs\Value\JsProxy;
use PhpJs\Value\JsSharedArrayBuffer;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsTypedArray;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;
use PhpJs\Object\PropertyDescriptor;

/**
 * Installs ArrayBuffer, DataView, and all TypedArray constructors.
 *
 * Each typed array constructor (Int8Array, Uint8Array, etc.) follows the same
 * pattern: accepts a length, another typed array, an ArrayBuffer, or an
 * array-like source.
 */
class TypedArrayConstructor
{
    /**
     * Convert a JS value to an integer, clamping Infinity and converting NaN to 0.
     * PHP's (int) cast on INF/NAN yields 0 on most platforms, which silently
     * corrupts length/offset parameters. This helper makes the conversion explicit.
     */
    private static function toInteger(JsValue $value): int
    {
        $n = TypeConversion::toNumber($value);
        if (is_nan($n)) {
            return 0;
        }
        if ($n === INF) {
            return PHP_INT_MAX;
        }
        if ($n === -INF) {
            return PHP_INT_MIN;
        }
        return (int) $n;
    }

    public static function install(Environment $env): void
    {
        self::installArrayBuffer($env);
        self::installSharedArrayBuffer($env);
        self::installDataView($env);
        self::installTypedArrays($env);
    }

    private static function installArrayBuffer(Environment $env): void
    {
        $proto = new JsObject();
        JsArrayBuffer::setDefaultPrototype($proto);

        $constructor = JsFunction::fromCallable(
            'ArrayBuffer',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                // Step 1: If NewTarget is undefined, throw a TypeError.
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor ArrayBuffer requires \'new\'');
                }
                $arg0 = $args[0] ?? new JsNumber(0.0);
                // Step 2: ToIndex(length).
                $length = TypeConversion::toIndex($arg0);

                // Step 3: Handle options argument for resizable ArrayBuffer.
                $maxByteLength = null;
                $optionsArg = $args[1] ?? JsUndefined::instance();
                if ($optionsArg instanceof JsObject) {
                    $maxByteLengthVal = $optionsArg->get('maxByteLength');
                    if (!$maxByteLengthVal instanceof JsUndefined) {
                        $maxByteLength = TypeConversion::toIndex($maxByteLengthVal);
                        if ($length > $maxByteLength) {
                            throw new RangeError('Invalid array buffer length');
                        }
                    }
                }

                // AllocateArrayBuffer calls OrdinaryCreateFromConstructor
                // which accesses NewTarget.prototype. When called via Reflect.construct
                // with a custom newTarget, this may trigger a getter or throw.
                $useProto = $proto;
                if ($this_ instanceof JsObject) {
                    $ntDesc = $this_->getOwnPropertyDescriptor('[[NewTarget]]');
                    if ($ntDesc !== null && $ntDesc->value instanceof JsFunction) {
                        $ntProto = $ntDesc->value->get('prototype');
                        if ($ntProto instanceof JsObject) {
                            $useProto = $ntProto;
                        }
                    }
                }
                return new JsArrayBuffer($length, $useProto, $maxByteLength);
            },
            1,
        );
        $constructor->setConstructable();

        // ArrayBuffer.isView(arg): returns true if arg is a TypedArray or DataView.
        $isViewFn = JsFunction::fromCallable('isView', function (JsValue $this_, array $args): JsValue {
            $arg = $args[0] ?? JsUndefined::instance();
            $isView = $arg instanceof JsTypedArray || $arg instanceof JsDataView;
            return new JsBoolean($isView);
        }, 1);
        $constructor->defineOwnProperty('isView', PropertyDescriptor::data($isViewFn, true, false, true));

        // ArrayBuffer.prototype.slice(begin, end).
        // Per spec: uses SpeciesConstructor to create the new buffer.
        $sliceFn = JsFunction::fromCallable(
            'slice',
            function (JsValue $this_, array $args) use ($constructor): JsValue {
                if (!$this_ instanceof JsArrayBuffer || $this_ instanceof JsSharedArrayBuffer) {
                    throw new TypeError('Method ArrayBuffer.prototype.slice called on incompatible receiver');
                }
                if ($this_->isDetached()) {
                    throw new TypeError('Cannot perform ArrayBuffer.prototype.slice on a detached ArrayBuffer');
                }
                $len = $this_->getByteLength();

                // Step 4-5: RelativeStart = ToIntegerOrInfinity(start).
                $startArg = $args[0] ?? JsUndefined::instance();
                $begin = TypeConversion::toIntegerOrInfinity($startArg);

                // Step 7-8: RelativeEnd. If end is undefined, use len.
                $endArg = $args[1] ?? JsUndefined::instance();
                $end = $endArg instanceof JsUndefined
                    ? (float) $len
                    : TypeConversion::toIntegerOrInfinity($endArg);

                // Compute the slice parameters.
                [$newLen, , $slicedData] = $this_->computeSlice($begin, $end);

                // Step 13: SpeciesConstructor(O, %ArrayBuffer%).
                $ctor = $this_->get('constructor');
                if ($ctor instanceof JsUndefined) {
                    $ctor = $constructor;
                } elseif (!$ctor instanceof JsObject) {
                    throw new TypeError(
                        TypeConversion::toString($ctor) . ' is not an object'
                    );
                } else {
                    $speciesSym = SymbolConstructor::species();
                    $species = $ctor->getBySymbol($speciesSym);
                    if ($species instanceof JsUndefined || $species instanceof JsNull) {
                        $ctor = $constructor;
                    } else {
                        // Per spec 7.3.20 step 9: If IsConstructor(S) is true, return S.
                        // Step 10: Throw a TypeError exception. IsConstructor
                        // accepts proxies whose target has [[Construct]].
                        $isCtor = ($species instanceof JsFunction && $species->isConstructable())
                            || ($species instanceof JsProxy && $species->isConstructable());
                        if (!$isCtor) {
                            throw new TypeError(
                                'ArrayBuffer.prototype.slice: species constructor is not a constructor'
                            );
                        }
                        $ctor = $species;
                    }
                }

                // Step 15: Construct(ctor, newLen).
                $isCtor = ($ctor instanceof JsFunction && $ctor->isConstructable())
                    || ($ctor instanceof JsProxy && $ctor->isConstructable());
                if ($isCtor) {
                    $newBuf = $ctor->construct([new JsNumber((float) $newLen)]);
                } else {
                    // Default: create a plain ArrayBuffer.
                    $newBuf = new JsArrayBuffer($newLen, $this_->getPrototype());
                }

                // Step 17: If new does not have [[ArrayBufferData]], throw TypeError.
                if (!$newBuf instanceof JsArrayBuffer) {
                    throw new TypeError(
                        'ArrayBuffer.prototype.slice: species constructor did not return an ArrayBuffer'
                    );
                }

                // Step 19: If SameValue(new, O) is true, throw a TypeError.
                if ($newBuf === $this_) {
                    throw new TypeError(
                        'ArrayBuffer.prototype.slice: species constructor returned same buffer'
                    );
                }

                // Step 20: If new.byteLength < newLen, throw TypeError.
                if ($newBuf->getByteLength() < $newLen) {
                    throw new TypeError(
                        'ArrayBuffer.prototype.slice: species constructor returned too small buffer'
                    );
                }

                // Step 24-25: Copy data.
                if ($newLen > 0 && $newBuf->getByteLength() >= $newLen) {
                    // Copy bytes from source into the new buffer.
                    $existingData = $newBuf->getData();
                    $newData = substr_replace($existingData, $slicedData, 0, $newLen);
                    $newBuf->setData($newData);
                }

                return $newBuf;
            },
            2,
        );
        $proto->defineOwnProperty('slice', PropertyDescriptor::data($sliceFn, true, false, true));

        // ArrayBuffer.prototype.byteLength getter.
        $byteLengthGetter = JsFunction::fromCallable(
            'get byteLength',
            function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsArrayBuffer || $this_ instanceof JsSharedArrayBuffer) {
                    throw new TypeError(
                        'Method get ArrayBuffer.prototype.byteLength called on incompatible receiver'
                    );
                }
                return new JsNumber((float) $this_->getByteLength());
            },
            0,
        );
        $proto->defineOwnProperty(
            'byteLength',
            PropertyDescriptor::accessor($byteLengthGetter, null, false, true),
        );

        // ArrayBuffer.prototype.maxByteLength getter.
        $maxByteLengthGetter = JsFunction::fromCallable(
            'get maxByteLength',
            function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsArrayBuffer || $this_ instanceof JsSharedArrayBuffer) {
                    throw new TypeError(
                        'Method get ArrayBuffer.prototype.maxByteLength called on incompatible receiver'
                    );
                }
                if ($this_->isDetached()) {
                    return new JsNumber(0.0);
                }
                return new JsNumber((float) $this_->getMaxByteLength());
            },
            0,
        );
        $proto->defineOwnProperty(
            'maxByteLength',
            PropertyDescriptor::accessor($maxByteLengthGetter, null, false, true),
        );

        // ArrayBuffer.prototype.resizable getter.
        $resizableGetter = JsFunction::fromCallable(
            'get resizable',
            function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsArrayBuffer || $this_ instanceof JsSharedArrayBuffer) {
                    throw new TypeError(
                        'Method get ArrayBuffer.prototype.resizable called on incompatible receiver'
                    );
                }
                return new JsBoolean($this_->isResizable());
            },
            0,
        );
        $proto->defineOwnProperty(
            'resizable',
            PropertyDescriptor::accessor($resizableGetter, null, false, true),
        );

        // ArrayBuffer.prototype.detached getter.
        $detachedGetter = JsFunction::fromCallable(
            'get detached',
            function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsArrayBuffer || $this_ instanceof JsSharedArrayBuffer) {
                    throw new TypeError(
                        'Method get ArrayBuffer.prototype.detached called on incompatible receiver'
                    );
                }
                return new JsBoolean($this_->isDetached());
            },
            0,
        );
        $proto->defineOwnProperty(
            'detached',
            PropertyDescriptor::accessor($detachedGetter, null, false, true),
        );

        // ArrayBuffer.prototype.resize(newByteLength).
        $resizeFn = JsFunction::fromCallable(
            'resize',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsArrayBuffer) {
                    throw new TypeError(
                        'Method ArrayBuffer.prototype.resize called on incompatible receiver'
                    );
                }
                if ($this_->isDetached()) {
                    throw new TypeError('Cannot resize a detached ArrayBuffer');
                }
                if (!$this_->isResizable()) {
                    throw new TypeError('ArrayBuffer is not resizable');
                }
                $newLenArg = $args[0] ?? JsUndefined::instance();
                $newByteLength = TypeConversion::toIndex($newLenArg);
                $this_->resize($newByteLength);
                return JsUndefined::instance();
            },
            1,
        );
        $proto->defineOwnProperty('resize', PropertyDescriptor::data($resizeFn, true, false, true));

        // ArrayBuffer.prototype.transfer(newLength?).
        $transferFn = JsFunction::fromCallable(
            'transfer',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsArrayBuffer) {
                    throw new TypeError(
                        'Method ArrayBuffer.prototype.transfer called on incompatible receiver'
                    );
                }
                if ($this_->isDetached()) {
                    throw new TypeError('Cannot transfer a detached ArrayBuffer');
                }
                $newLenArg = $args[0] ?? JsUndefined::instance();
                $newLen = $newLenArg instanceof JsUndefined
                    ? null
                    : TypeConversion::toIndex($newLenArg);
                return $this_->transfer($newLen);
            },
            0,
        );
        $proto->defineOwnProperty('transfer', PropertyDescriptor::data($transferFn, true, false, true));

        // ArrayBuffer.prototype.transferToFixedLength(newLength?).
        $transferToFixedLengthFn = JsFunction::fromCallable(
            'transferToFixedLength',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsArrayBuffer) {
                    throw new TypeError(
                        'Method ArrayBuffer.prototype.transferToFixedLength called on incompatible receiver'
                    );
                }
                if ($this_->isDetached()) {
                    throw new TypeError('Cannot transfer a detached ArrayBuffer');
                }
                $newLenArg = $args[0] ?? JsUndefined::instance();
                $newLen = $newLenArg instanceof JsUndefined
                    ? null
                    : TypeConversion::toIndex($newLenArg);
                return $this_->transferToFixedLength($newLen);
            },
            0,
        );
        $proto->defineOwnProperty(
            'transferToFixedLength',
            PropertyDescriptor::data($transferToFixedLengthFn, true, false, true),
        );

        // Symbol.toStringTag.
        $toStringTagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('ArrayBuffer'), false, false, true),
        );

        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($constructor, true, false, true),
        );
        $constructor->set('prototype', $proto);

        // Per spec: get ArrayBuffer[@@species] returns `this`.
        $speciesSym = SymbolConstructor::species();
        $speciesGetter = JsFunction::fromCallable(
            'get [Symbol.species]',
            function (JsValue $this_): JsValue {
                return $this_;
            },
            0,
        );
        $constructor->definePropertyBySymbol(
            $speciesSym,
            PropertyDescriptor::accessor($speciesGetter, null, false, true),
        );

        $env->defineVar('ArrayBuffer', $constructor);
    }

    private static function installSharedArrayBuffer(Environment $env): void
    {
        $proto = new JsObject();
        JsSharedArrayBuffer::setSharedDefaultPrototype($proto);

        $constructor = JsFunction::fromCallable(
            'SharedArrayBuffer',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor SharedArrayBuffer requires \'new\'');
                }
                $arg0 = $args[0] ?? new JsNumber(0.0);
                $length = TypeConversion::toIndex($arg0);

                // Handle options argument for growable SharedArrayBuffer.
                $maxByteLength = null;
                $optionsArg = $args[1] ?? JsUndefined::instance();
                if ($optionsArg instanceof JsObject) {
                    $maxByteLengthVal = $optionsArg->get('maxByteLength');
                    if (!$maxByteLengthVal instanceof JsUndefined) {
                        $maxByteLength = TypeConversion::toIndex($maxByteLengthVal);
                        if ($length > $maxByteLength) {
                            throw new RangeError('Invalid array buffer length');
                        }
                    }
                }

                $useProto = $proto;
                if ($this_ instanceof JsObject) {
                    $ntDesc = $this_->getOwnPropertyDescriptor('[[NewTarget]]');
                    if ($ntDesc !== null && $ntDesc->value instanceof JsFunction) {
                        $ntProto = $ntDesc->value->get('prototype');
                        if ($ntProto instanceof JsObject) {
                            $useProto = $ntProto;
                        }
                    }
                }

                return new JsSharedArrayBuffer($length, $useProto, $maxByteLength);
            },
            1,
        );
        $constructor->setConstructable();

        // SharedArrayBuffer.prototype.byteLength getter.
        $byteLengthGetter = JsFunction::fromCallable(
            'get byteLength',
            function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsSharedArrayBuffer) {
                    throw new TypeError(
                        'Method get SharedArrayBuffer.prototype.byteLength called on incompatible receiver'
                    );
                }
                return new JsNumber((float) $this_->getByteLength());
            },
            0,
        );
        $proto->defineOwnProperty(
            'byteLength',
            PropertyDescriptor::accessor($byteLengthGetter, null, false, true),
        );

        // SharedArrayBuffer.prototype.maxByteLength getter.
        $maxByteLengthGetter = JsFunction::fromCallable(
            'get maxByteLength',
            function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsSharedArrayBuffer) {
                    throw new TypeError(
                        'Method get SharedArrayBuffer.prototype.maxByteLength'
                        . ' called on incompatible receiver'
                    );
                }
                return new JsNumber((float) $this_->getMaxByteLength());
            },
            0,
        );
        $proto->defineOwnProperty(
            'maxByteLength',
            PropertyDescriptor::accessor($maxByteLengthGetter, null, false, true),
        );

        // SharedArrayBuffer.prototype.growable getter.
        $growableGetter = JsFunction::fromCallable(
            'get growable',
            function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsSharedArrayBuffer) {
                    throw new TypeError(
                        'Method get SharedArrayBuffer.prototype.growable called on incompatible receiver'
                    );
                }
                return new JsBoolean($this_->isResizable());
            },
            0,
        );
        $proto->defineOwnProperty(
            'growable',
            PropertyDescriptor::accessor($growableGetter, null, false, true),
        );

        // SharedArrayBuffer.prototype.grow(newByteLength).
        $growFn = JsFunction::fromCallable(
            'grow',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsSharedArrayBuffer) {
                    throw new TypeError(
                        'Method SharedArrayBuffer.prototype.grow called on incompatible receiver'
                    );
                }
                if (!$this_->isResizable()) {
                    throw new TypeError('SharedArrayBuffer is not growable');
                }
                $newLenArg = $args[0] ?? JsUndefined::instance();
                $newByteLength = TypeConversion::toIndex($newLenArg);
                $this_->resize($newByteLength);
                return JsUndefined::instance();
            },
            1,
        );
        $proto->defineOwnProperty('grow', PropertyDescriptor::data($growFn, true, false, true));

        // SharedArrayBuffer.prototype.slice(begin, end).
        $sliceFn = JsFunction::fromCallable(
            'slice',
            function (JsValue $this_, array $args) use ($constructor): JsValue {
                if (!$this_ instanceof JsSharedArrayBuffer) {
                    throw new TypeError(
                        'Method SharedArrayBuffer.prototype.slice'
                        . ' called on incompatible receiver'
                    );
                }
                $len = $this_->getByteLength();
                $startArg = $args[0] ?? JsUndefined::instance();
                $begin = TypeConversion::toIntegerOrInfinity($startArg);
                $endArg = $args[1] ?? JsUndefined::instance();
                $end = $endArg instanceof JsUndefined
                    ? (float) $len
                    : TypeConversion::toIntegerOrInfinity($endArg);

                [$newLen, , $slicedData] = $this_->computeSlice($begin, $end);

                // SpeciesConstructor(O, %SharedArrayBuffer%).
                $ctor = $this_->get('constructor');
                if ($ctor instanceof JsUndefined) {
                    $ctor = $constructor;
                } elseif (!$ctor instanceof JsObject) {
                    throw new TypeError(
                        TypeConversion::toString($ctor) . ' is not an object'
                    );
                } else {
                    $speciesSym = SymbolConstructor::species();
                    $species = $ctor->getBySymbol($speciesSym);
                    if (
                        $species instanceof JsUndefined
                        || $species instanceof JsNull
                    ) {
                        $ctor = $constructor;
                    } elseif (
                        $species instanceof JsFunction
                        && $species->isConstructable()
                    ) {
                        $ctor = $species;
                    } else {
                        throw new TypeError(
                            'SharedArrayBuffer.prototype.slice:'
                            . ' species constructor is not a constructor'
                        );
                    }
                }

                // Construct(ctor, newLen).
                if (
                    $ctor instanceof JsFunction
                    && $ctor->isConstructable()
                ) {
                    $newBuf = $ctor->construct([new JsNumber((float) $newLen)]);
                } else {
                    $newBuf = new JsSharedArrayBuffer(
                        $newLen,
                        $this_->getPrototype(),
                    );
                }

                if (!$newBuf instanceof JsSharedArrayBuffer) {
                    throw new TypeError(
                        'SharedArrayBuffer.prototype.slice:'
                        . ' species constructor did not return'
                        . ' a SharedArrayBuffer'
                    );
                }
                if ($newBuf === $this_) {
                    throw new TypeError(
                        'SharedArrayBuffer.prototype.slice:'
                        . ' species constructor returned same buffer'
                    );
                }
                if ($newBuf->getByteLength() < $newLen) {
                    throw new TypeError(
                        'SharedArrayBuffer.prototype.slice:'
                        . ' species constructor returned too small buffer'
                    );
                }

                if ($newLen > 0 && $newBuf->getByteLength() >= $newLen) {
                    $existingData = $newBuf->getData();
                    $newData = substr_replace(
                        $existingData,
                        $slicedData,
                        0,
                        $newLen,
                    );
                    $newBuf->setData($newData);
                }

                return $newBuf;
            },
            2,
        );
        $proto->defineOwnProperty(
            'slice',
            PropertyDescriptor::data($sliceFn, true, false, true),
        );

        // Symbol.toStringTag.
        $toStringTagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('SharedArrayBuffer'), false, false, true),
        );

        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($constructor, true, false, true),
        );
        $constructor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($proto, false, false, false),
        );

        // Per spec: get SharedArrayBuffer[@@species] returns `this`.
        $speciesSym = SymbolConstructor::species();
        $speciesGetter = JsFunction::fromCallable(
            'get [Symbol.species]',
            function (JsValue $this_): JsValue {
                return $this_;
            },
            0,
        );
        $constructor->definePropertyBySymbol(
            $speciesSym,
            PropertyDescriptor::accessor($speciesGetter, null, false, true),
        );

        $env->defineVar('SharedArrayBuffer', $constructor);
    }

    private static function installDataView(Environment $env): void
    {
        $proto = new JsObject();

        $constructor = JsFunction::fromCallable(
            'DataView',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                // Step 1: NewTarget undefined → TypeError, checked before any arg coercion.
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError('Constructor DataView requires \'new\'');
                }
                $buffer = $args[0] ?? JsUndefined::instance();
                if (!$buffer instanceof JsArrayBuffer) {
                    throw new TypeError(
                        'First argument to DataView constructor must be an ArrayBuffer'
                    );
                }

                // Step 3: offset = ToIndex(byteOffset).
                $offsetArg = $args[1] ?? JsUndefined::instance();
                $byteOffset = TypeConversion::toIndex($offsetArg);

                // Step 4-5: IsDetachedBuffer check after ToIndex(byteOffset).
                if ($buffer->isDetached()) {
                    throw new TypeError(
                        'Cannot construct DataView on a detached ArrayBuffer'
                    );
                }

                // Step 6: If offset > bufferByteLength, throw RangeError.
                $bufLen = $buffer->getByteLength();
                if ($byteOffset > $bufLen) {
                    throw new RangeError(
                        'Start offset is outside the bounds of the buffer'
                    );
                }

                // Step 8-9: byteLength.
                $lenArg = $args[2] ?? JsUndefined::instance();
                if ($lenArg instanceof JsUndefined) {
                    if ($buffer->isResizable()) {
                        $byteLength = null;
                    } else {
                        $byteLength = $bufLen - $byteOffset;
                    }
                } else {
                    $byteLength = TypeConversion::toIndex($lenArg);
                    if (($byteOffset + $byteLength) > $bufLen) {
                        throw new RangeError('Invalid DataView length');
                    }
                }

                $effectiveProto = $proto;
                if ($this_ instanceof JsObject && $this_->has('[[NewTarget]]')) {
                    $newTarget = $this_->get('[[NewTarget]]');
                    if ($newTarget instanceof JsFunction) {
                        $ntProto = $newTarget->get('prototype');
                        if ($ntProto instanceof JsObject) {
                            $effectiveProto = $ntProto;
                        }
                    }
                }
                return new JsDataView($buffer, $byteOffset, $byteLength, $effectiveProto);
            },
            1,
        );
        $constructor->setConstructable();

        // Install all get/set methods on prototype.
        self::installDataViewMethods($proto);

        // Accessor properties: buffer, byteLength, byteOffset.
        $bufferGetter = JsFunction::fromCallable(
            'get buffer',
            function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsDataView) {
                    throw new TypeError('Method get DataView.prototype.buffer called on incompatible receiver');
                }
                return $this_->getBuffer();
            },
            0,
        );
        $proto->defineOwnProperty(
            'buffer',
            PropertyDescriptor::accessor($bufferGetter, null, false, true),
        );

        $byteLengthGetter = JsFunction::fromCallable(
            'get byteLength',
            function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsDataView) {
                    throw new TypeError(
                        'Method get DataView.prototype.byteLength called on incompatible receiver'
                    );
                }
                // Per spec: throw TypeError if buffer is detached.
                $this_->validateNotDetached();
                return new JsNumber((float) $this_->getByteLength());
            },
            0,
        );
        $proto->defineOwnProperty(
            'byteLength',
            PropertyDescriptor::accessor($byteLengthGetter, null, false, true),
        );

        $byteOffsetGetter = JsFunction::fromCallable(
            'get byteOffset',
            function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsDataView) {
                    throw new TypeError(
                        'Method get DataView.prototype.byteOffset called on incompatible receiver'
                    );
                }
                // Per spec: throw TypeError if buffer is detached.
                $this_->validateNotDetached();
                return new JsNumber((float) $this_->getByteOffset());
            },
            0,
        );
        $proto->defineOwnProperty(
            'byteOffset',
            PropertyDescriptor::accessor($byteOffsetGetter, null, false, true),
        );

        // Symbol.toStringTag.
        $toStringTagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('DataView'), false, false, true),
        );

        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($constructor, true, false, true),
        );
        // Per spec, DataView.prototype is non-writable, non-enumerable, non-configurable.
        $constructor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($proto, false, false, false),
        );

        $env->defineVar('DataView', $constructor);
    }

    /**
     * Install all DataView get/set methods on the prototype.
     *
     * Per spec (GetViewValue / SetViewValue), the operation order is:
     * 1. Validate `this` is a DataView
     * 2. getIndex = ToIndex(requestIndex) -- may throw RangeError/TypeError
     * 3. For setters: numberValue = ToNumber(value) -- may throw TypeError
     * 4. littleEndian = ToBoolean(isLittleEndian)
     * 5. IsDetachedBuffer check -- throws TypeError
     * 6. Bounds check -- throws RangeError
     * 7. Perform the read/write
     */
    private static function installDataViewMethods(JsObject $proto): void
    {
        $methods = [
            'getInt8' => [1, false, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new JsNumber((float) $dv->getInt8($offset));
            }],
            'getUint8' => [1, false, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new JsNumber((float) $dv->getUint8($offset));
            }],
            'getInt16' => [1, false, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new JsNumber((float) $dv->getInt16($offset, $le));
            }],
            'getUint16' => [1, false, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new JsNumber((float) $dv->getUint16($offset, $le));
            }],
            'getInt32' => [1, false, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new JsNumber((float) $dv->getInt32($offset, $le));
            }],
            'getUint32' => [1, false, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new JsNumber((float) $dv->getUint32($offset, $le));
            }],
            'getFloat16' => [1, false, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new JsNumber($dv->getFloat16($offset, $le));
            }],
            'getFloat32' => [1, false, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new JsNumber($dv->getFloat32($offset, $le));
            }],
            'getFloat64' => [1, false, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new JsNumber($dv->getFloat64($offset, $le));
            }],
            'setInt8' => [2, false, null],
            'setUint8' => [2, false, null],
            'setInt16' => [2, false, null],
            'setUint16' => [2, false, null],
            'setInt32' => [2, false, null],
            'setUint32' => [2, false, null],
            'setFloat16' => [2, false, null],
            'setFloat32' => [2, false, null],
            'setFloat64' => [2, false, null],
            'getBigInt64' => [1, true, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new \PhpJs\Value\JsBigInt((string) $dv->getBigInt64($offset, $le));
            }],
            'getBigUint64' => [1, true, function (JsDataView $dv, int $offset, bool $le): JsValue {
                $raw = $dv->getBigUint64($offset, $le);
                if ($raw < 0) {
                    $str = bcadd((string) $raw, '18446744073709551616');
                } else {
                    $str = (string) $raw;
                }
                return new \PhpJs\Value\JsBigInt($str);
            }],
            'setBigInt64' => [2, true, null],
            'setBigUint64' => [2, true, null],
        ];

        foreach ($methods as $name => [$length, $isBigInt, $handler]) {
            $isGetter = str_starts_with($name, 'get');
            $cb = function (JsValue $this_, array $args) use ($name, $handler, $isGetter, $isBigInt): JsValue {
                if (!$this_ instanceof JsDataView) {
                    throw new TypeError("Method DataView.prototype.{$name} called on incompatible receiver");
                }

                // Step 2: getIndex = ToIndex(requestIndex).
                $offsetArg = $args[0] ?? JsUndefined::instance();
                $getIndex = TypeConversion::toIndex($offsetArg);

                if ($isGetter) {
                    // Step 3 (getter): littleEndian = ToBoolean(isLittleEndian).
                    $littleEndian = isset($args[1])
                        ? TypeConversion::toBoolean($args[1])
                        : false;

                    // Step 4: IsDetachedBuffer check.
                    $this_->validateNotDetached();

                    // Step 5-6: bounds check + read (inside handler via checkBounds).
                    return $handler($this_, $getIndex, $littleEndian);
                }

                // Setter path.
                // Step 3: numberValue = ToNumber(value) or ToBigInt(value).
                $valueArg = $args[1] ?? JsUndefined::instance();
                if ($isBigInt) {
                    $numValue = TypeConversion::toBigInt($valueArg);
                    $rawInt = self::bigIntToRawInt($numValue);
                } else {
                    $numValue = TypeConversion::toNumber($valueArg);
                }

                // Step 4: littleEndian = ToBoolean(isLittleEndian).
                $littleEndian = isset($args[2])
                    ? TypeConversion::toBoolean($args[2])
                    : false;

                // Step 5: IsDetachedBuffer check.
                $this_->validateNotDetached();

                // Step 6-7: bounds check + write.
                match ($name) {
                    'setInt8' => $this_->setInt8($getIndex, (int) $numValue),
                    'setUint8' => $this_->setUint8($getIndex, (int) $numValue),
                    'setInt16' => $this_->setInt16($getIndex, (int) $numValue, $littleEndian),
                    'setUint16' => $this_->setUint16($getIndex, (int) $numValue, $littleEndian),
                    'setInt32' => $this_->setInt32($getIndex, (int) $numValue, $littleEndian),
                    'setUint32' => $this_->setUint32($getIndex, (int) $numValue, $littleEndian),
                    'setFloat16' => $this_->setFloat16($getIndex, $numValue, $littleEndian),
                    'setFloat32' => $this_->setFloat32($getIndex, $numValue, $littleEndian),
                    'setFloat64' => $this_->setFloat64($getIndex, $numValue, $littleEndian),
                    'setBigInt64' => $this_->setBigInt64($getIndex, $rawInt, $littleEndian),
                    'setBigUint64' => $this_->setBigUint64($getIndex, $rawInt, $littleEndian),
                };

                return JsUndefined::instance();
            };
            $fn = JsFunction::fromCallable($name, $cb, $length);
            $proto->defineOwnProperty($name, PropertyDescriptor::data($fn, true, false, true));
        }
    }

    /**
     * Convert a JsBigInt value to a raw PHP int for DataView setBigInt64/setBigUint64.
     */
    private static function bigIntToRawInt(\PhpJs\Value\JsBigInt $bigInt): int
    {
        $mod = '18446744073709551616'; // 2^64
        $half = '9223372036854775808'; // 2^63
        $result = bcmod($bigInt->value, $mod);
        if ($result !== '' && $result[0] === '-') {
            $result = bcadd($result, $mod);
        }
        if (bccomp($result, $half) >= 0) {
            $result = bcsub($result, $mod);
        }
        return (int) $result;
    }

    /**
     * Install all 11 typed array constructors and the shared %TypedArray% intrinsic.
     *
     * Per spec, the prototype chain is:
     *   new Int8Array(3) -> Int8Array.prototype -> %TypedArray%.prototype -> Object.prototype
     *   Int8Array -> %TypedArray% -> Function.prototype
     */
    private static function installTypedArrays(Environment $env): void
    {
        // Create %TypedArray%.prototype: the shared prototype for all typed array prototypes.
        $typedArrayProto = new JsObject();

        // Create %TypedArray% intrinsic constructor.
        // Per spec, calling %TypedArray% directly throws a TypeError.
        $typedArrayIntrinsic = JsFunction::fromCallable(
            'TypedArray',
            function (JsValue $this_, array $args): JsValue {
                throw new TypeError('Abstract class TypedArray not directly constructable');
            },
            0,
        );
        $typedArrayIntrinsic->setConstructable();

        // %TypedArray%.prototype property: { writable: false, enumerable: false, configurable: false }.
        $typedArrayIntrinsic->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($typedArrayProto, false, false, false),
        );
        $typedArrayProto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($typedArrayIntrinsic, true, false, true),
        );

        // Install shared prototype methods on %TypedArray%.prototype.
        self::installTypedArrayPrototypeMethods($typedArrayProto, 'TypedArray');

        // Per spec, %TypedArray%.prototype.toString is Array.prototype.toString.
        $arrayConstructor = $env->get('Array');
        if ($arrayConstructor instanceof JsFunction) {
            $arrayProto = $arrayConstructor->get('prototype');
            if ($arrayProto instanceof JsObject) {
                $arrayToString = $arrayProto->get('toString');
                if ($arrayToString instanceof JsFunction) {
                    $typedArrayProto->defineOwnProperty(
                        'toString',
                        PropertyDescriptor::data($arrayToString, true, false, true),
                    );
                }
            }
        }

        // toLocaleString on %TypedArray%.prototype.
        // Per spec, uses the same algorithm as Array.prototype.toLocaleString
        // but reads [[ArrayLength]] instead of "length".
        $toLocaleStringFn = JsFunction::fromCallable(
            'toLocaleString',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    throw new TypeError(
                        'Method %TypedArray%.prototype.toLocaleString called on incompatible receiver'
                    );
                }
                $this_->validateNotDetached();
                $len = $this_->getLength();
                if ($len === 0) {
                    return new JsString('');
                }
                // Determine the separator using the locale list separator.
                $separator = ',';
                $parts = [];
                for ($i = 0; $i < $len; $i++) {
                    $el = $this_->getIndex($i);
                    if ($el instanceof JsUndefined || $el instanceof JsNull) {
                        $parts[] = '';
                    } else {
                        // Per spec: Invoke(element, "toLocaleString").
                        // Auto-box primitives to access the method, but call with
                        // the original primitive as `this` so type checks pass.
                        $boxed = ($el instanceof JsObject)
                            ? $el
                            : TypeConversion::toObject($el);
                        $tlsFn = $boxed->get('toLocaleString');
                        if ($tlsFn instanceof JsFunction) {
                            // Call with original value as `this`, not the wrapper.
                            $parts[] = TypeConversion::toString($tlsFn->call($el, $args));
                        } else {
                            $parts[] = TypeConversion::toString($el);
                        }
                    }
                }
                return new JsString(implode($separator, $parts));
            },
            0,
        );
        $typedArrayProto->defineOwnProperty(
            'toLocaleString',
            PropertyDescriptor::data($toLocaleStringFn, true, false, true),
        );

        // Install shared accessor properties on %TypedArray%.prototype.
        self::installTypedArrayAccessors($typedArrayProto, 'TypedArray');

        // Symbol.toStringTag: getter on %TypedArray%.prototype that returns [[TypedArrayName]].
        $toStringTagSym = SymbolConstructor::toStringTag();
        $toStringTagGetter = JsFunction::fromCallable(
            'get [Symbol.toStringTag]',
            function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsTypedArray) {
                    return JsUndefined::instance();
                }
                return new JsString($this_->getTypeName());
            },
            0,
        );
        $typedArrayProto->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::accessor($toStringTagGetter, null, false, true),
        );

        // Install Symbol.iterator pointing to values method on %TypedArray%.prototype.
        $iterSym = SymbolConstructor::iterator();
        $valuesFn = $typedArrayProto->get('values');
        if ($valuesFn instanceof JsFunction) {
            $typedArrayProto->definePropertyBySymbol(
                $iterSym,
                PropertyDescriptor::data($valuesFn, true, false, true),
            );
        }

        // Install static methods on %TypedArray%: from(), of().
        self::installAbstractTypedArrayStaticMethods($typedArrayIntrinsic);

        // Per spec: get %TypedArray%[@@species] returns `this`.
        $speciesSym = SymbolConstructor::species();
        $speciesGetter = JsFunction::fromCallable(
            'get [Symbol.species]',
            function (JsValue $this_): JsValue {
                return $this_;
            },
            0,
        );
        $typedArrayIntrinsic->definePropertyBySymbol(
            $speciesSym,
            PropertyDescriptor::accessor($speciesGetter, null, false, true),
        );

        // Install each concrete typed array constructor.
        foreach (JsTypedArray::TYPES as $typeName => $_) {
            self::installSingleTypedArray($env, $typeName, $typedArrayIntrinsic, $typedArrayProto);
        }
    }

    private static function installSingleTypedArray(
        Environment $env,
        string $typeName,
        JsFunction $typedArrayIntrinsic,
        JsObject $typedArrayProto,
    ): void {
        // Each subtype prototype inherits from %TypedArray%.prototype.
        $proto = new JsObject($typedArrayProto);
        $bpe = JsTypedArray::TYPES[$typeName][0];

        $ctorRef = null;
        $constructor = JsFunction::fromCallable(
            $typeName,
            function (JsValue $this_, array $args) use ($typeName, $bpe, $proto, &$ctorRef): JsValue {
                // Per spec: if NewTarget is undefined, throw TypeError.
                if (
                    !$this_ instanceof JsObject
                    || $this_->getOwnPropertyDescriptor('[[NewTarget]]') === null
                ) {
                    throw new TypeError(
                        "Constructor {$typeName} requires 'new'"
                    );
                }
                // Per §23.2.5.1 TypedArray ( ...args ): the argument validation
                // (e.g. Symbol/BigInt argument TypeError for `new T(sym)`) must
                // happen BEFORE GetPrototypeFromConstructor is called. Pass a
                // deferred proto resolver so constructTypedArray performs proto
                // access only after args are validated.
                $ntDesc = $this_->getOwnPropertyDescriptor('[[NewTarget]]');
                $nt = ($ntDesc !== null && $ntDesc->value instanceof JsFunction)
                    ? $ntDesc->value
                    : null;
                $defaultProto = $proto;
                $protoResolver = static function () use ($nt, $defaultProto, $ctorRef): JsObject {
                    if ($nt !== null && $ctorRef !== null && $nt !== $ctorRef) {
                        $ntProto = $nt->get('prototype');
                        if ($ntProto instanceof JsObject) {
                            return $ntProto;
                        }
                    }
                    return $defaultProto;
                };
                return self::constructTypedArray($typeName, $bpe, $protoResolver, $args);
            },
            3,
        );
        $ctorRef = $constructor;
        $constructor->setConstructable();

        // Each subtype constructor's [[Prototype]] is %TypedArray%.
        $constructor->setCustomPrototype($typedArrayIntrinsic);

        // Static property: BYTES_PER_ELEMENT.
        $constructor->defineOwnProperty(
            'BYTES_PER_ELEMENT',
            PropertyDescriptor::data(new JsNumber((float) $bpe), false, false, false),
        );

        // Static methods from()/of() live on %TypedArray% and are inherited
        // by subtype constructors via the prototype chain; do not install
        // an own copy on each subtype.

        // Uint8Array gets extra base64/hex methods not present on other typed arrays.
        if ($typeName === 'Uint8Array') {
            self::installUint8ArrayMethods($constructor, $proto);
        }

        // Prototype property: BYTES_PER_ELEMENT (own, not inherited from %TypedArray%.prototype).
        $proto->defineOwnProperty(
            'BYTES_PER_ELEMENT',
            PropertyDescriptor::data(new JsNumber((float) $bpe), false, false, false),
        );

        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($constructor, true, false, true),
        );
        // Use defineOwnProperty instead of set because %TypedArray%.prototype
        // is non-writable on the intrinsic, which blocks ordinary [[Set]].
        $constructor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($proto, false, false, false),
        );

        $env->defineVar($typeName, $constructor);
    }

    /**
     * Install static methods on the abstract %TypedArray% intrinsic.
     * These are inherited by each subtype constructor via [[Prototype]] chain.
     */
    private static function installAbstractTypedArrayStaticMethods(
        JsFunction $intrinsic,
    ): void {
        // %TypedArray%.from(source, mapFn, thisArg).
        // Per spec: uses `this` as the constructor, creates targetObj, then
        // sets each mapped element individually.
        $fromFn = JsFunction::fromCallable(
            'from',
            function (JsValue $this_, array $args): JsValue {
                // Step 1-2: Validate C is a constructor.
                if (
                    !$this_ instanceof JsFunction
                    || !$this_->isConstructable()
                ) {
                    throw new TypeError(
                        'TypedArray.from requires a constructor'
                    );
                }

                $source = $args[0] ?? JsUndefined::instance();
                $mapFn = $args[1] ?? JsUndefined::instance();
                $thisArg = $args[2] ?? JsUndefined::instance();

                // Step 3: Validate mapfn before accessing source.
                if (
                    !$mapFn instanceof JsUndefined
                    && !$mapFn instanceof JsFunction
                ) {
                    throw new TypeError(
                        'TypedArray.from: mapfn is not a function'
                    );
                }

                $hasMapFn = $mapFn instanceof JsFunction;

                // Collect source elements into a list.
                $elements = [];
                if ($source instanceof JsTypedArray) {
                    for ($i = 0; $i < $source->getLength(); $i++) {
                        $elements[] = $source->getIndex($i);
                    }
                } elseif ($source instanceof JsArray) {
                    for ($i = 0; $i < $source->getLength(); $i++) {
                        $elements[] = $source->get((string) $i);
                    }
                } elseif ($source instanceof JsObject) {
                    $iterSym = SymbolConstructor::iterator();
                    $iterMethod = $source->getBySymbol($iterSym);
                    if ($iterMethod instanceof JsFunction) {
                        $elements = self::consumeIterator($iterMethod, $source);
                    } elseif ($iterMethod instanceof \PhpJs\Value\JsHTMLDDA) {
                        throw new TypeError('TypedArray.from: iterator is not an object');
                    } else {
                        $len = (int) TypeConversion::toNumber($source->get('length'));
                        for ($i = 0; $i < $len; $i++) {
                            $elements[] = $source->get((string) $i);
                        }
                    }
                }

                $len = count($elements);

                // TypedArrayCreate(C, [len]): construct via C and then
                // ValidateTypedArray on the result.
                $targetObj = $this_->construct([new JsNumber((float) $len)]);
                if (!$targetObj instanceof JsTypedArray) {
                    throw new TypeError(
                        'TypedArray.from: constructor did not return a TypedArray'
                    );
                }
                if ($targetObj->getLength() < $len) {
                    throw new TypeError(
                        'TypedArray.from: constructor returned a smaller TypedArray'
                    );
                }

                // Per spec step 12: for each element, map and Set individually.
                for ($k = 0; $k < $len; $k++) {
                    $kValue = $elements[$k];
                    if ($hasMapFn) {
                        /** @var JsFunction $mapFn */
                        $mappedValue = $mapFn->call($thisArg, [$kValue, new JsNumber((float) $k)]);
                    } else {
                        $mappedValue = $kValue;
                    }
                    $targetObj->set((string) $k, $mappedValue);
                }

                return $targetObj;
            },
            1,
        );
        $intrinsic->defineOwnProperty(
            'from',
            PropertyDescriptor::data($fromFn, true, false, true),
        );

        // %TypedArray%.of(...items).
        $ofFn = JsFunction::fromCallable(
            'of',
            function (JsValue $this_, array $args): JsValue {
                if (
                    !$this_ instanceof JsFunction
                    || !$this_->isConstructable()
                ) {
                    throw new TypeError(
                        'TypedArray.of requires a constructor'
                    );
                }
                // TypedArrayCreate(C, [len]) + ValidateTypedArray.
                $len = count($args);
                $newObj = $this_->construct([new JsNumber((float) $len)]);
                if (!$newObj instanceof JsTypedArray) {
                    throw new TypeError(
                        'TypedArray.of: constructor did not return a TypedArray'
                    );
                }
                if ($newObj->getLength() < $len) {
                    throw new TypeError(
                        'TypedArray.of: constructor returned a smaller TypedArray'
                    );
                }
                for ($i = 0; $i < $len; $i++) {
                    $newObj->set((string) $i, $args[$i]);
                }
                return $newObj;
            },
            0,
        );
        $intrinsic->defineOwnProperty(
            'of',
            PropertyDescriptor::data($ofFn, true, false, true),
        );
    }


    /**
     * Construct a typed array from arguments. Handles all constructor overloads:
     * (length), (typedArray), (arrayBuffer, byteOffset, length), (arrayLike).
     */
    private static function constructTypedArray(
        string $typeName,
        int $bpe,
        JsObject|\Closure $protoOrResolver,
        array $args,
    ): JsTypedArray {
        $getProto = static function () use (&$protoOrResolver): JsObject {
            if ($protoOrResolver instanceof \Closure) {
                $resolved = ($protoOrResolver)();
                $protoOrResolver = $resolved;
                return $resolved;
            }
            return $protoOrResolver;
        };
        if (empty($args) || $args[0] instanceof JsUndefined) {
            return JsTypedArray::fromLength($typeName, 0, $getProto());
        }

        $arg0 = $args[0];

        // new TypedArray(length): non-object first arg uses ToIndex.
        if (!$arg0 instanceof JsObject) {
            // Per §23.2.5.1 step 5 / §23.2.4.3: ToIndex must run before
            // the constructor prototype is accessed. ToIndex throws a
            // TypeError for Symbol/BigInt arguments; that happens here.
            $len = TypeConversion::toIndex($arg0);
            return JsTypedArray::fromLength($typeName, $len, $getProto());
        }

        // new TypedArray(buffer, byteOffset, length).
        if ($arg0 instanceof JsArrayBuffer) {
            // Per spec: byteOffset = ToIndex(byteOffset).
            $offsetArg = $args[1] ?? JsUndefined::instance();
            $byteOffset = TypeConversion::toIndex($offsetArg);

            // Per spec: IsDetachedBuffer check after ToIndex(byteOffset).
            if ($arg0->isDetached()) {
                throw new TypeError(
                    'Cannot construct a typed array on a detached ArrayBuffer'
                );
            }

            if ($byteOffset % $bpe !== 0) {
                throw new RangeError("Start offset of {$typeName} should be a multiple of {$bpe}");
            }

            $bufLen = $arg0->getByteLength();
            $isResizable = $arg0->isResizable();
            $lengthExplicit = isset($args[2]) && !$args[2] instanceof JsUndefined;

            if ($lengthExplicit) {
                // Per spec step 13a: newLength = ToIndex(length).
                // The valueOf() during ToIndex may detach the buffer.
                $length = TypeConversion::toIndex($args[2]);

                // Per spec step 14: second IsDetachedBuffer check after length coercion.
                if ($arg0->isDetached()) {
                    throw new TypeError(
                        'Cannot construct a typed array on a detached ArrayBuffer'
                    );
                }

                $newByteLength = $length * $bpe;
                if ($byteOffset + $newByteLength > $bufLen) {
                    throw new RangeError('Invalid typed array length');
                }
            } else {
                if ($byteOffset > $bufLen) {
                    throw new RangeError("Start offset of {$typeName} is outside the bounds of the buffer");
                }
                $remaining = $bufLen - $byteOffset;
                if ($remaining % $bpe !== 0) {
                    throw new RangeError("Byte length of {$typeName} should be a multiple of {$bpe}");
                }
                $length = (int) ($remaining / $bpe);
            }

            $ta = new JsTypedArray($typeName, $arg0, $byteOffset, $length, $getProto());
            if ($isResizable && !$lengthExplicit) {
                $ta->setAutoLength(true);
            }
            return $ta;
        }

        // new TypedArray(typedArray): copy elements.
        if ($arg0 instanceof JsTypedArray) {
            $srcLen = $arg0->getLength();
            $result = JsTypedArray::fromLength($typeName, $srcLen, $getProto());
            for ($i = 0; $i < $srcLen; $i++) {
                $result->setIndex($i, $arg0->getIndex($i));
            }
            return $result;
        }

        // new TypedArray(arrayLike or iterable).
        // JsArray goes through the same path as any object: check @@iterator first,
        // then fall back to array-like. This ensures modifications to
        // ArrayIteratorPrototype.next are respected per spec.
        if ($arg0 instanceof JsObject) {
            // Try iterator protocol first.
            $iterSym = SymbolConstructor::iterator();
            $iterMethod = $arg0->getBySymbol($iterSym);
            if ($iterMethod instanceof JsFunction) {
                $elements = self::consumeIterator($iterMethod, $arg0);
                return JsTypedArray::fromArray($typeName, $elements, $getProto());
            }
            // Per spec: if @@iterator is not undefined/null but not callable, throw.
            if (
                !$iterMethod instanceof JsUndefined
                && !$iterMethod instanceof JsNull
            ) {
                throw new TypeError('object is not iterable');
            }

            // Fall back to array-like (has .length).
            $lenVal = $arg0->get('length');
            if (!$lenVal instanceof JsUndefined) {
                $len = (int) TypeConversion::toNumber($lenVal);
                $result = JsTypedArray::fromLength($typeName, $len, $getProto());
                for ($i = 0; $i < $len; $i++) {
                    $result->setIndex($i, $arg0->get((string) $i));
                }
                return $result;
            }

            return JsTypedArray::fromLength($typeName, 0, $getProto());
        }

        // Single primitive value: treat as length via ToIndex.
        $len = TypeConversion::toIndex($arg0);
        return JsTypedArray::fromLength($typeName, $len, $getProto());
    }

    /**
     * Consume an iterator into a list of values.
     *
     * @return list<JsValue>
     */
    private static function consumeIterator(JsFunction $iterMethod, JsObject $obj): array
    {
        $iterator = $iterMethod->call($obj, []);
        if (!$iterator instanceof JsObject) {
            return [];
        }

        $nextMethod = $iterator->get('next');
        if (!$nextMethod instanceof JsFunction) {
            return [];
        }

        $elements = [];
        while (true) {
            $result = $nextMethod->call($iterator, []);
            // Per spec IteratorStep: a non-object next() result is TypeError.
            if (!$result instanceof JsObject) {
                throw new TypeError('Iterator result is not an object');
            }
            if (TypeConversion::toBoolean($result->get('done'))) {
                break;
            }
            $elements[] = $result->get('value');
        }
        return $elements;
    }

    /**
     * Install static methods: from(), of().
     */
    private static function installTypedArrayStaticMethods(
        JsFunction $constructor,
        string $typeName,
        int $bpe,
        JsObject $proto,
    ): void {
        // TypedArray.from(source, mapFn, thisArg).
        // Per spec (%TypedArray%.from): uses `this` as the constructor (C),
        // creates targetObj via C, then sets each mapped element individually.
        $fromFn = JsFunction::fromCallable(
            'from',
            function (JsValue $this_, array $args) use ($typeName, $proto): JsValue {
                // Step 1-2: Validate C is a constructor.
                if (
                    !$this_ instanceof JsFunction
                    || !$this_->isConstructable()
                ) {
                    throw new TypeError(
                        'TypedArray.from requires a constructor'
                    );
                }

                $source = $args[0] ?? JsUndefined::instance();
                $mapFn = $args[1] ?? JsUndefined::instance();
                $thisArg = $args[2] ?? JsUndefined::instance();

                // Step 3-4: Validate mapfn before accessing source.
                if (
                    !$mapFn instanceof JsUndefined
                    && !$mapFn instanceof JsFunction
                ) {
                    throw new TypeError(
                        'TypedArray.from: mapfn is not a function'
                    );
                }

                $hasMapFn = $mapFn instanceof JsFunction;

                // Step 5-8: Collect source elements into a list.
                $elements = [];
                if ($source instanceof JsTypedArray) {
                    for ($i = 0; $i < $source->getLength(); $i++) {
                        $elements[] = $source->getIndex($i);
                    }
                } elseif ($source instanceof JsArray) {
                    for ($i = 0; $i < $source->getLength(); $i++) {
                        $elements[] = $source->get((string) $i);
                    }
                } elseif ($source instanceof JsObject) {
                    $iterSym = SymbolConstructor::iterator();
                    $iterMethod = $source->getBySymbol($iterSym);
                    if ($iterMethod instanceof JsFunction) {
                        $elements = self::consumeIterator($iterMethod, $source);
                    } elseif ($iterMethod instanceof \PhpJs\Value\JsHTMLDDA) {
                        throw new TypeError('TypedArray.from: iterator is not an object');
                    } else {
                        $len = (int) TypeConversion::toNumber($source->get('length'));
                        for ($i = 0; $i < $len; $i++) {
                            $elements[] = $source->get((string) $i);
                        }
                    }
                }

                $len = count($elements);

                // Step 10: targetObj = TypedArrayCreate(C, [len]).
                // Use C (this_) as the constructor.
                $targetObj = $this_->construct([new JsNumber((float) $len)]);

                // Step 12: For each element, map if needed, then Set on targetObj.
                for ($k = 0; $k < $len; $k++) {
                    $kValue = $elements[$k];
                    if ($hasMapFn) {
                        /** @var JsFunction $mapFn */
                        $mappedValue = $mapFn->call($thisArg, [$kValue, new JsNumber((float) $k)]);
                    } else {
                        $mappedValue = $kValue;
                    }
                    // Per spec: Set(targetObj, Pk, mappedValue, true).
                    $targetObj->set((string) $k, $mappedValue);
                }

                return $targetObj;
            },
            1
        );
        $constructor->defineOwnProperty('from', PropertyDescriptor::data($fromFn, true, false, true));

        // TypedArray.of(...items).
        $ofFn = JsFunction::fromCallable('of', function (JsValue $this_, array $args) use ($typeName, $proto): JsValue {
            return JsTypedArray::fromArray($typeName, $args, $proto);
        }, 0);
        $constructor->defineOwnProperty('of', PropertyDescriptor::data($ofFn, true, false, true));
    }

    /**
     * Install Uint8Array-specific base64/hex static and prototype methods.
     *
     * These methods are defined in the ECMAScript proposal for Uint8Array base64/hex
     * encoding and are only available on Uint8Array, not other typed array types.
     */
    private static function installUint8ArrayMethods(JsFunction $constructor, JsObject $proto): void
    {
        // Uint8Array.fromBase64(string, options?).
        // Per spec, the result is always a plain Uint8Array (ignores receiver).
        $fromBase64Fn = JsFunction::fromCallable(
            'fromBase64',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                $strVal = $args[0] ?? JsUndefined::instance();
                if (!$strVal instanceof JsString) {
                    throw new TypeError('Uint8Array.fromBase64: first argument must be a string');
                }
                $str = $strVal->toJsString();
                [$alphabet, $lastChunkHandling] = self::parseBase64Options($args[1] ?? JsUndefined::instance());
                $read = 0;
                $bytes = self::decodeBase64($str, $alphabet, $lastChunkHandling, null, $read);
                $ta = JsTypedArray::fromLength('Uint8Array', count($bytes), $proto);
                foreach ($bytes as $i => $b) {
                    $ta->setIndex($i, new JsNumber((float) $b));
                }
                return $ta;
            },
            1,
        );
        $constructor->defineOwnProperty(
            'fromBase64',
            PropertyDescriptor::data($fromBase64Fn, true, false, true),
        );

        // Uint8Array.fromHex(string).
        // Per spec, the result is always a plain Uint8Array (ignores receiver).
        $fromHexFn = JsFunction::fromCallable(
            'fromHex',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                $strVal = $args[0] ?? JsUndefined::instance();
                if (!$strVal instanceof JsString) {
                    throw new TypeError('Uint8Array.fromHex: first argument must be a string');
                }
                $str = $strVal->toJsString();
                $read = 0;
                $bytes = self::decodeHex($str, null, $read);
                $ta = JsTypedArray::fromLength('Uint8Array', count($bytes), $proto);
                foreach ($bytes as $i => $b) {
                    $ta->setIndex($i, new JsNumber((float) $b));
                }
                return $ta;
            },
            1,
        );
        $constructor->defineOwnProperty(
            'fromHex',
            PropertyDescriptor::data($fromHexFn, true, false, true),
        );

        // Uint8Array.prototype.toBase64(options?).
        $toBase64Fn = JsFunction::fromCallable(
            'toBase64',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsTypedArray || $this_->getTypeName() !== 'Uint8Array') {
                    throw new TypeError(
                        'Uint8Array.prototype.toBase64 called on incompatible receiver'
                    );
                }
                $this_->validateNotDetached();

                $optVal = $args[0] ?? JsUndefined::instance();
                $alphabet = 'base64';
                $omitPadding = false;

                if ($optVal instanceof JsObject) {
                    $alphabetVal = $optVal->get('alphabet');
                    if (!$alphabetVal instanceof JsUndefined) {
                        if (!$alphabetVal instanceof JsString) {
                            throw new TypeError(
                                "Uint8Array.prototype.toBase64: 'alphabet' option must be a string"
                            );
                        }
                        $alphabet = $alphabetVal->toJsString();
                        if ($alphabet !== 'base64' && $alphabet !== 'base64url') {
                            throw new TypeError(
                                "Uint8Array.prototype.toBase64: 'alphabet' must be 'base64' or 'base64url'"
                            );
                        }
                    }
                    $omitPaddingVal = $optVal->get('omitPadding');
                    if (!$omitPaddingVal instanceof JsUndefined) {
                        $omitPadding = TypeConversion::toBoolean($omitPaddingVal);
                    }
                }

                $len = $this_->getLength();
                $bin = '';
                for ($i = 0; $i < $len; $i++) {
                    $bin .= chr((int) TypeConversion::toNumber($this_->getIndex($i)));
                }

                $encoded = base64_encode($bin);

                if ($alphabet === 'base64url') {
                    $encoded = strtr($encoded, '+/', '-_');
                }

                if ($omitPadding) {
                    $encoded = rtrim($encoded, '=');
                }

                return new JsString($encoded);
            },
            0,
        );
        $proto->defineOwnProperty(
            'toBase64',
            PropertyDescriptor::data($toBase64Fn, true, false, true),
        );

        // Uint8Array.prototype.toHex().
        $toHexFn = JsFunction::fromCallable(
            'toHex',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsTypedArray || $this_->getTypeName() !== 'Uint8Array') {
                    throw new TypeError(
                        'Uint8Array.prototype.toHex called on incompatible receiver'
                    );
                }
                $this_->validateNotDetached();
                $len = $this_->getLength();
                $hex = '';
                for ($i = 0; $i < $len; $i++) {
                    $hex .= sprintf('%02x', (int) TypeConversion::toNumber($this_->getIndex($i)));
                }
                return new JsString($hex);
            },
            0,
        );
        $proto->defineOwnProperty(
            'toHex',
            PropertyDescriptor::data($toHexFn, true, false, true),
        );

        // Uint8Array.prototype.setFromBase64(string, options?).
        // Writes decoded bytes directly to the target, chunk by chunk. On error,
        // previously written complete chunks remain; the partial/erroneous chunk
        // is not written. Stops gracefully when the target buffer is full.
        $setFromBase64Fn = JsFunction::fromCallable(
            'setFromBase64',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsTypedArray || $this_->getTypeName() !== 'Uint8Array') {
                    throw new TypeError(
                        'Uint8Array.prototype.setFromBase64 called on incompatible receiver'
                    );
                }
                $this_->validateNotDetached();

                $strVal = $args[0] ?? JsUndefined::instance();
                if (!$strVal instanceof JsString) {
                    throw new TypeError(
                        'Uint8Array.prototype.setFromBase64: first argument must be a string'
                    );
                }
                $str = $strVal->toJsString();
                [$alphabet, $lastChunkHandling] = self::parseBase64Options(
                    $args[1] ?? JsUndefined::instance()
                );
                $this_->validateNotDetached();

                if ($alphabet === 'base64url') {
                    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
                } else {
                    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
                }
                $lookup = array_flip(str_split($chars));

                $targetLen = $this_->getLength();
                $written = 0;
                $readCount = 0;
                $inputLen = strlen($str);
                $i = 0;
                $chunk = [];
                $chunkStartPos = 0;
                $pendingError = null;

                while ($i < $inputLen) {
                    $c = $str[$i];
                    $ord = ord($c);
                    // Skip ASCII whitespace.
                    if ($ord === 0x20 || $ord === 0x09 || $ord === 0x0A || $ord === 0x0C || $ord === 0x0D) {
                        $i++;
                        continue;
                    }
                    if ($c === '=') {
                        break;
                    }
                    if (!isset($lookup[$c])) {
                        $pendingError = new SyntaxError("Invalid character in base64 string: '{$c}'");
                        break;
                    }
                    if (count($chunk) === 0) {
                        $chunkStartPos = $i;
                    }
                    $chunk[] = $lookup[$c];
                    $i++;

                    if (count($chunk) === 4) {
                        $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
                        $b1 = (($chunk[1] & 0x0F) << 4) | ($chunk[2] >> 2);
                        $b2 = (($chunk[2] & 0x03) << 6) | $chunk[3];

                        // Per spec: stop before a full 3-byte chunk that won't fit entirely.
                        if ($written + 3 > $targetLen) {
                            $readCount = $chunkStartPos;
                            $result = new JsObject();
                            $result->set('read', new JsNumber((float) $readCount));
                            $result->set('written', new JsNumber((float) $written));
                            return $result;
                        }

                        $this_->setIndex($written, new JsNumber((float) $b0));
                        $this_->setIndex($written + 1, new JsNumber((float) $b1));
                        $this_->setIndex($written + 2, new JsNumber((float) $b2));
                        $written += 3;
                        $chunk = [];
                        $readCount = $i;
                    }
                }

                if ($pendingError !== null) {
                    throw $pendingError;
                }

                // Handle padding.
                $padStart = $i;
                $padCount = 0;
                while ($i < $inputLen && $str[$i] === '=') {
                    $padCount++;
                    $i++;
                }

                // After padding, only whitespace is allowed.
                while ($i < $inputLen) {
                    $c = $str[$i];
                    $ord = ord($c);
                    if ($ord === 0x20 || $ord === 0x09 || $ord === 0x0A || $ord === 0x0C || $ord === 0x0D) {
                        $i++;
                        continue;
                    }
                    throw new SyntaxError("Unexpected character after base64 data: '{$c}'");
                }

                $chunkLen = count($chunk);

                if ($chunkLen === 0) {
                    $readCount = $inputLen;
                    $result = new JsObject();
                    $result->set('read', new JsNumber((float) $readCount));
                    $result->set('written', new JsNumber((float) $written));
                    return $result;
                }

                if ($chunkLen === 2 && $padCount > 2) {
                    throw new SyntaxError('Invalid base64 padding');
                }
                if ($chunkLen === 3 && $padCount > 1) {
                    throw new SyntaxError('Invalid base64 padding');
                }

                if ($chunkLen === 1) {
                    if ($lastChunkHandling === 'stop-before-partial') {
                        $readCount = $chunkStartPos;
                        $result = new JsObject();
                        $result->set('read', new JsNumber((float) $readCount));
                        $result->set('written', new JsNumber((float) $written));
                        return $result;
                    }
                    throw new SyntaxError('Invalid base64: incomplete final chunk');
                }

                if ($chunkLen === 2) {
                    $hasNonZeroTrailing = ($chunk[1] & 0x0F) !== 0;
                    if ($padCount === 1) {
                        if ($lastChunkHandling === 'stop-before-partial') {
                            $readCount = $chunkStartPos;
                            $result = new JsObject();
                            $result->set('read', new JsNumber((float) $readCount));
                            $result->set('written', new JsNumber((float) $written));
                            return $result;
                        }
                        throw new SyntaxError('Invalid base64: partial padding in final chunk');
                    }
                    if ($padCount === 0) {
                        if ($lastChunkHandling === 'stop-before-partial') {
                            $readCount = $chunkStartPos;
                            $result = new JsObject();
                            $result->set('read', new JsNumber((float) $readCount));
                            $result->set('written', new JsNumber((float) $written));
                            return $result;
                        }
                        if ($lastChunkHandling === 'strict') {
                            throw new SyntaxError('Invalid base64: missing padding in final chunk');
                        }
                        $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
                        if ($written < $targetLen) {
                            $this_->setIndex($written, new JsNumber((float) $b0));
                            $written++;
                        }
                        $readCount = $padStart;
                        $result = new JsObject();
                        $result->set('read', new JsNumber((float) $readCount));
                        $result->set('written', new JsNumber((float) $written));
                        return $result;
                    }
                    // $padCount === 2: correct.
                    if ($lastChunkHandling === 'strict' && $hasNonZeroTrailing) {
                        throw new SyntaxError('Invalid base64: non-zero padding bits in final chunk');
                    }
                    $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
                    if ($written < $targetLen) {
                        $this_->setIndex($written, new JsNumber((float) $b0));
                        $written++;
                    }
                    $readCount = $i;
                    $result = new JsObject();
                    $result->set('read', new JsNumber((float) $readCount));
                    $result->set('written', new JsNumber((float) $written));
                    return $result;
                }

                // $chunkLen === 3: 3 chars encode 2 bytes.
                $hasNonZeroTrailing = ($chunk[2] & 0x03) !== 0;
                if ($padCount === 0) {
                    if ($lastChunkHandling === 'stop-before-partial') {
                        $readCount = $chunkStartPos;
                        $result = new JsObject();
                        $result->set('read', new JsNumber((float) $readCount));
                        $result->set('written', new JsNumber((float) $written));
                        return $result;
                    }
                    if ($lastChunkHandling === 'strict') {
                        throw new SyntaxError('Invalid base64: missing padding in final chunk');
                    }
                    // loose: if not all 2 bytes fit, stop before the whole chunk.
                    if ($written + 2 > $targetLen) {
                        $readCount = $chunkStartPos;
                        $result = new JsObject();
                        $result->set('read', new JsNumber((float) $readCount));
                        $result->set('written', new JsNumber((float) $written));
                        return $result;
                    }
                    $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
                    $b1 = (($chunk[1] & 0x0F) << 4) | ($chunk[2] >> 2);
                    $this_->setIndex($written, new JsNumber((float) $b0));
                    $written++;
                    $this_->setIndex($written, new JsNumber((float) $b1));
                    $written++;
                    $readCount = $padStart;
                    $result = new JsObject();
                    $result->set('read', new JsNumber((float) $readCount));
                    $result->set('written', new JsNumber((float) $written));
                    return $result;
                }
                if ($padCount > 1) {
                    throw new SyntaxError('Invalid base64 padding');
                }
                // $padCount === 1: correct padding.
                if ($lastChunkHandling === 'strict' && $hasNonZeroTrailing) {
                    throw new SyntaxError('Invalid base64: non-zero padding bits in final chunk');
                }
                // If not all 2 bytes fit, stop before the whole chunk.
                if ($written + 2 > $targetLen) {
                    $readCount = $chunkStartPos;
                    $result = new JsObject();
                    $result->set('read', new JsNumber((float) $readCount));
                    $result->set('written', new JsNumber((float) $written));
                    return $result;
                }
                $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
                $b1 = (($chunk[1] & 0x0F) << 4) | ($chunk[2] >> 2);
                $this_->setIndex($written, new JsNumber((float) $b0));
                $written++;
                $this_->setIndex($written, new JsNumber((float) $b1));
                $written++;
                $readCount = $i;
                $result = new JsObject();
                $result->set('read', new JsNumber((float) $readCount));
                $result->set('written', new JsNumber((float) $written));
                return $result;
            },
            1,
        );
        $proto->defineOwnProperty(
            'setFromBase64',
            PropertyDescriptor::data($setFromBase64Fn, true, false, true),
        );

        // Uint8Array.prototype.setFromHex(string).
        // Writes decoded bytes directly to the target pair by pair. On error,
        // previously written valid pairs remain; the erroneous pair is not written.
        // Stops gracefully when the target buffer is full.
        $setFromHexFn = JsFunction::fromCallable(
            'setFromHex',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsTypedArray || $this_->getTypeName() !== 'Uint8Array') {
                    throw new TypeError(
                        'Uint8Array.prototype.setFromHex called on incompatible receiver'
                    );
                }
                $this_->validateNotDetached();

                $strVal = $args[0] ?? JsUndefined::instance();
                if (!$strVal instanceof JsString) {
                    throw new TypeError(
                        'Uint8Array.prototype.setFromHex: first argument must be a string'
                    );
                }
                $str = $strVal->toJsString();
                $inputLen = strlen($str);
                $targetLen = $this_->getLength();
                $written = 0;
                $readCount = 0;

                if ($inputLen % 2 !== 0) {
                    throw new SyntaxError('Uint8Array.setFromHex: input must have even length');
                }

                for ($i = 0; $i < $inputLen; $i += 2) {
                    if ($written >= $targetLen) {
                        $readCount = $i;
                        $result = new JsObject();
                        $result->set('read', new JsNumber((float) $readCount));
                        $result->set('written', new JsNumber((float) $written));
                        return $result;
                    }
                    $hi = $str[$i];
                    $lo = $str[$i + 1];
                    if (!ctype_xdigit($hi) || !ctype_xdigit($lo)) {
                        throw new SyntaxError("Invalid hex character in input: '{$hi}{$lo}'");
                    }
                    $this_->setIndex($written, new JsNumber((float) hexdec($hi . $lo)));
                    $written++;
                    $readCount = $i + 2;
                }

                $result = new JsObject();
                $result->set('read', new JsNumber((float) $readCount));
                $result->set('written', new JsNumber((float) $written));
                return $result;
            },
            1,
        );
        $proto->defineOwnProperty(
            'setFromHex',
            PropertyDescriptor::data($setFromHexFn, true, false, true),
        );
    }

    /**
     * Parse base64 options object, returning [alphabet, lastChunkHandling].
     *
     * Options must be string-typed (not boxed String objects), matching the spec
     * which uses IsString rather than ToString coercion.
     *
     * @return array{string, string}
     */
    private static function parseBase64Options(JsValue $optVal): array
    {
        $alphabet = 'base64';
        $lastChunkHandling = 'loose';

        if ($optVal instanceof JsObject) {
            $alphabetVal = $optVal->get('alphabet');
            if (!$alphabetVal instanceof JsUndefined) {
                if (!$alphabetVal instanceof JsString) {
                    throw new TypeError(
                        "Uint8Array.fromBase64: 'alphabet' option must be a string"
                    );
                }
                $alphabet = $alphabetVal->toJsString();
                if ($alphabet !== 'base64' && $alphabet !== 'base64url') {
                    throw new TypeError(
                        "Uint8Array.fromBase64: 'alphabet' option must be 'base64' or 'base64url'"
                    );
                }
            }
            $lastChunkHandlingVal = $optVal->get('lastChunkHandling');
            if (!$lastChunkHandlingVal instanceof JsUndefined) {
                if (!$lastChunkHandlingVal instanceof JsString) {
                    throw new TypeError(
                        "Uint8Array.fromBase64: 'lastChunkHandling' option must be a string"
                    );
                }
                $lastChunkHandling = $lastChunkHandlingVal->toJsString();
                if (
                    $lastChunkHandling !== 'loose'
                    && $lastChunkHandling !== 'strict'
                    && $lastChunkHandling !== 'stop-before-partial'
                ) {
                    throw new TypeError(
                        "Uint8Array.fromBase64: 'lastChunkHandling' must be 'loose', 'strict',"
                        . " or 'stop-before-partial'"
                    );
                }
            }
        }

        return [$alphabet, $lastChunkHandling];
    }

    /**
     * Decode a base64 string into bytes per the ECMAScript Uint8Array.fromBase64 spec.
     *
     * Processes the input in 4-char base64 chunks. ASCII whitespace (space, tab, LF,
     * FF, CR) is silently skipped. Any other non-base64 character causes a SyntaxError.
     *
     * When $maxBytes is non-null, decoding stops once $maxBytes bytes would be produced,
     * used by setFromBase64 to respect the target array size.
     *
     * $readCount is set to the number of input characters consumed.
     *
     * @param int|null $maxBytes Maximum bytes to write (null = unlimited).
     * @param int $readCount Set to the number of source chars consumed.
     * @return int[] Array of decoded byte values.
     */
    private static function decodeBase64(
        string $input,
        string $alphabet,
        string $lastChunkHandling,
        ?int $maxBytes,
        int &$readCount,
    ): array {
        if ($alphabet === 'base64url') {
            $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        } else {
            $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
        }
        $lookup = array_flip(str_split($chars));

        $bytes = [];
        $chunk = [];
        $readCount = 0;
        $inputLen = strlen($input);
        $i = 0;
        $chunkStartPos = 0;

        while ($i < $inputLen) {
            $c = $input[$i];
            $ord = ord($c);

            // ASCII whitespace: space, tab, LF, FF, CR.
            if ($ord === 0x20 || $ord === 0x09 || $ord === 0x0A || $ord === 0x0C || $ord === 0x0D) {
                $i++;
                continue;
            }

            if ($c === '=') {
                break;
            }

            if (!isset($lookup[$c])) {
                throw new SyntaxError("Invalid character in base64 string: '{$c}'");
            }

            if (count($chunk) === 0) {
                $chunkStartPos = $i;
            }
            $chunk[] = $lookup[$c];
            $i++;

            if (count($chunk) === 4) {
                $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
                $b1 = (($chunk[1] & 0x0F) << 4) | ($chunk[2] >> 2);
                $b2 = (($chunk[2] & 0x03) << 6) | $chunk[3];

                if ($maxBytes !== null && count($bytes) + 3 > $maxBytes) {
                    $remaining = $maxBytes - count($bytes);
                    if ($remaining >= 1) {
                        $bytes[] = $b0;
                    }
                    if ($remaining >= 2) {
                        $bytes[] = $b1;
                    }
                    if ($remaining >= 3) {
                        $bytes[] = $b2;
                    }
                    $readCount = $i;
                    return $bytes;
                }

                $bytes[] = $b0;
                $bytes[] = $b1;
                $bytes[] = $b2;
                $chunk = [];
                $readCount = $i;
            }
        }

        // Handle padding characters at current position.
        $padStart = $i;
        $padCount = 0;
        while ($i < $inputLen && $input[$i] === '=') {
            $padCount++;
            $i++;
        }

        // After padding, only whitespace is allowed.
        while ($i < $inputLen) {
            $c = $input[$i];
            $ord = ord($c);
            if ($ord === 0x20 || $ord === 0x09 || $ord === 0x0A || $ord === 0x0C || $ord === 0x0D) {
                $i++;
                continue;
            }
            throw new SyntaxError("Unexpected character after base64 data: '{$c}'");
        }

        $chunkLen = count($chunk);

        if ($chunkLen === 0) {
            $readCount = $inputLen;
            return $bytes;
        }

        // Excess padding is always invalid.
        if ($chunkLen === 2 && $padCount > 2) {
            throw new SyntaxError('Invalid base64 padding');
        }
        if ($chunkLen === 3 && $padCount > 1) {
            throw new SyntaxError('Invalid base64 padding');
        }

        if ($chunkLen === 1) {
            // A single base64 char cannot represent any complete byte.
            if ($lastChunkHandling === 'stop-before-partial') {
                $readCount = $chunkStartPos;
                return $bytes;
            }
            throw new SyntaxError('Invalid base64: incomplete final chunk');
        }

        if ($chunkLen === 2) {
            // 2 chars encode 1 byte. Correct padding is '=='.
            $hasNonZeroTrailing = ($chunk[1] & 0x0F) !== 0;

            if ($padCount === 1) {
                // Partial padding (only one '='): invalid except stop-before-partial.
                if ($lastChunkHandling === 'stop-before-partial') {
                    $readCount = $chunkStartPos;
                    return $bytes;
                }
                throw new SyntaxError('Invalid base64: partial padding in final chunk');
            }

            if ($padCount === 0) {
                if ($lastChunkHandling === 'stop-before-partial') {
                    $readCount = $chunkStartPos;
                    return $bytes;
                }
                if ($lastChunkHandling === 'strict') {
                    throw new SyntaxError('Invalid base64: missing padding in final chunk');
                }
                // loose: decode ignoring trailing bits.
                $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
                if ($maxBytes === null || count($bytes) < $maxBytes) {
                    $bytes[] = $b0;
                }
                $readCount = $padStart;
                return $bytes;
            }

            // $padCount === 2: correct padding.
            if ($lastChunkHandling === 'strict' && $hasNonZeroTrailing) {
                throw new SyntaxError('Invalid base64: non-zero padding bits in final chunk');
            }
            $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
            if ($maxBytes === null || count($bytes) < $maxBytes) {
                $bytes[] = $b0;
            }
            $readCount = $i;
            return $bytes;
        }

        // $chunkLen === 3: 3 chars encode 2 bytes. Correct padding is '='.
        $hasNonZeroTrailing = ($chunk[2] & 0x03) !== 0;

        if ($padCount === 0) {
            if ($lastChunkHandling === 'stop-before-partial') {
                $readCount = $chunkStartPos;
                return $bytes;
            }
            if ($lastChunkHandling === 'strict') {
                throw new SyntaxError('Invalid base64: missing padding in final chunk');
            }
            // loose: decode ignoring trailing bits.
            $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
            $b1 = (($chunk[1] & 0x0F) << 4) | ($chunk[2] >> 2);
            $canWrite = $maxBytes === null ? 2 : min(2, $maxBytes - count($bytes));
            if ($canWrite >= 1) {
                $bytes[] = $b0;
            }
            if ($canWrite >= 2) {
                $bytes[] = $b1;
            }
            $readCount = $padStart;
            return $bytes;
        }

        // $padCount >= 1.
        if ($padCount > 1) {
            throw new SyntaxError('Invalid base64 padding');
        }

        // $padCount === 1: correct padding.
        if ($lastChunkHandling === 'strict' && $hasNonZeroTrailing) {
            throw new SyntaxError('Invalid base64: non-zero padding bits in final chunk');
        }
        $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
        $b1 = (($chunk[1] & 0x0F) << 4) | ($chunk[2] >> 2);
        $canWrite = $maxBytes === null ? 2 : min(2, $maxBytes - count($bytes));
        if ($canWrite >= 1) {
            $bytes[] = $b0;
        }
        if ($canWrite >= 2) {
            $bytes[] = $b1;
        }
        $readCount = $i;
        return $bytes;
    }

    /**
     * Decode a hex string into bytes per the ECMAScript Uint8Array.fromHex spec.
     *
     * Each pair of hex characters decodes to one byte. Uppercase and lowercase are
     * accepted. Any non-hex character or odd-length input causes a SyntaxError.
     *
     * When $maxBytes is non-null, decoding stops once $maxBytes bytes are produced.
     * $readCount is set to the number of input characters consumed.
     *
     * @param int|null $maxBytes Maximum bytes to write (null = unlimited).
     * @param int $readCount Set to the number of source chars consumed.
     * @return int[] Array of decoded byte values.
     */
    private static function decodeHex(string $input, ?int $maxBytes, int &$readCount): array
    {
        $bytes = [];
        $readCount = 0;
        $inputLen = strlen($input);

        if ($inputLen % 2 !== 0) {
            throw new SyntaxError('Uint8Array.fromHex: input must have even length');
        }

        for ($i = 0; $i < $inputLen; $i += 2) {
            if ($maxBytes !== null && count($bytes) >= $maxBytes) {
                $readCount = $i;
                return $bytes;
            }
            $hi = $input[$i];
            $lo = $input[$i + 1];
            if (!ctype_xdigit($hi) || !ctype_xdigit($lo)) {
                throw new SyntaxError("Invalid hex character in input: '{$hi}{$lo}'");
            }
            $bytes[] = (int) hexdec($hi . $lo);
            $readCount = $i + 2;
        }

        return $bytes;
    }

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

                $isBigTarget = $this_->isBigIntArray();

                if ($source instanceof JsTypedArray) {
                    // Per spec step 12: check source buffer is not detached.
                    $source->validateNotDetached();
                    $srcLen = $source->getLength();
                    $targetLen = $this_->getLength();
                    // Per spec: if one is BigInt and the other is not, throw TypeError.
                    $isBigSrc = $source->isBigIntArray();
                    if ($isBigSrc !== $isBigTarget) {
                        throw new \PhpJs\Exceptions\TypeError(
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
                    throw new \PhpJs\Exceptions\TypeError('Cannot convert undefined or null to object');
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
                        $srcObj->set('length', new JsNumber((float) $srcLen));
                    } else {
                        // Number, boolean, symbol: ToObject wraps them, length 0.
                        $srcLen = 0;
                    }

                    if ($srcLen + $offset > $this_->getLength()) {
                        throw new RangeError('Source is too large');
                    }

                    if ($srcObj !== null) {
                        for ($i = 0; $i < $srcLen; $i++) {
                            $val = $srcObj->get((string) $i);
                            // Per spec: for BigInt arrays, use ToBigInt; for others, ToNumber.
                            if ($isBigTarget) {
                                $coerced = TypeConversion::toBigInt($val);
                                $this_->setIndex($offset + $i, $coerced);
                            } else {
                                $num = TypeConversion::toNumber($val);
                                $this_->setIndex($offset + $i, new JsNumber($num));
                            }
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
                $len = $this_->getLength();
                $begin = isset($args[0]) ? self::toInteger($args[0]) : 0;
                $end = isset($args[1]) && !$args[1] instanceof JsUndefined
                    ? self::toInteger($args[1])
                    : null;

                // Resolve begin.
                if ($begin < 0) {
                    $begin = max(0, $len + $begin);
                }
                $begin = min($begin, $len);

                // Resolve end.
                if ($end === null) {
                    $end = $len;
                } elseif ($end < 0) {
                    $end = max(0, $len + $end);
                }
                $end = min($end, $len);

                $newLength = max(0, $end - $begin);
                $bpe = $this_->getBytesPerElement();
                $beginByteOffset = $this_->getByteOffset() + $begin * $bpe;

                // Per spec: TypedArraySpeciesCreate(O, [buffer, beginByteOffset, newLength]).
                $buffer = $this_->getBuffer();
                return self::typedArraySpeciesCreate(
                    $this_,
                    $newLength,
                    [
                        $buffer,
                        new JsNumber((float) $beginByteOffset),
                        new JsNumber((float) $newLength),
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
                $this_->validateNotDetached();
                $len = $this_->getLength();
                $begin = isset($args[0]) ? self::toInteger($args[0]) : 0;
                $end = isset($args[1]) && !$args[1] instanceof JsUndefined
                    ? self::toInteger($args[1])
                    : null;
                // Per spec: after argument coercion, re-validate that the
                // TypedArray is not detached and is not out of bounds on
                // its (possibly resized) buffer.
                $this_->validateNotDetached();
                if ($this_->isOutOfBounds()) {
                    throw new TypeError(
                        "{$typeName}.prototype.slice: typed array is out of bounds"
                    );
                }

                // Resolve begin/end per spec.
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
                // Re-clamp against the current length in case the buffer was
                // shrunk by argument coercion.
                $currentLen = $this_->getLength();
                $effectiveEnd = min($end, $currentLen);
                $effectiveBegin = min($begin, $currentLen);
                $count = max(0, $end - $begin);
                $copyCount = max(0, $effectiveEnd - $effectiveBegin);

                // TypedArraySpeciesCreate via SpeciesConstructor.
                $result = self::typedArraySpeciesCreate($this_, $count);

                if ($count > 0) {
                    $this_->validateNotDetached();
                }

                for ($i = 0; $i < $copyCount; $i++) {
                    $result->setIndex($i, $this_->getIndex($effectiveBegin + $i));
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
                $this_->validateNotDetached();
                // Coerce arguments (may detach buffer via valueOf).
                $target = isset($args[0]) ? self::toInteger($args[0]) : 0;
                $start = isset($args[1]) ? self::toInteger($args[1]) : 0;
                $end = isset($args[2]) && !$args[2] instanceof JsUndefined
                ? self::toInteger($args[2])
                : null;
                // Per spec: check detached AGAIN after argument coercion.
                $this_->validateNotDetached();
                return $this_->copyWithinTyped($target, $start, $end);
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
                    if ($value instanceof \PhpJs\Value\JsBigInt) {
                        $coerced = $value;
                    } else {
                        $coerced = TypeConversion::toBigInt($value);
                    }
                } else {
                    $numVal = TypeConversion::toNumber($value);
                    $coerced = new JsNumber($numVal);
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
                    $result = $predicate->call($thisArg, [$el, new JsNumber((float) $i), $this_]);
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
                    $result = $predicate->call($thisArg, [$el, new JsNumber((float) $i), $this_]);
                    if (TypeConversion::toBoolean($result)) {
                        return new JsNumber((float) $i);
                    }
                }
                return new JsNumber(-1.0);
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
                    $callback->call($thisArg, [$this_->getIndex($i), new JsNumber((float) $i), $this_]);
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
                        [$this_->getIndex($i), new JsNumber((float) $i), $this_],
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
                        [$el, new JsNumber((float) $i), $this_],
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
                        [$accumulator, $this_->getIndex($k), new JsNumber((float) $k), $this_],
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
                        [$accumulator, $this_->getIndex($k), new JsNumber((float) $k), $this_],
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
                    $result = $callback->call($thisArg, [$this_->getIndex($i), new JsNumber((float) $i), $this_]);
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
                    $result = $callback->call($thisArg, [$this_->getIndex($i), new JsNumber((float) $i), $this_]);
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
                $this_->validateNotDetached();
                // Per spec: if length is 0, return -1 before ToInteger(fromIndex).
                if ($this_->getLength() === 0) {
                    return new JsNumber(-1.0);
                }
                $search = $args[0] ?? JsUndefined::instance();
                $fromIndex = isset($args[1]) ? self::toInteger($args[1]) : 0;
                return new JsNumber((float) $this_->indexOfTyped($search, $fromIndex));
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
                    return new JsNumber(-1.0);
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
                            return new JsNumber((float) $i);
                        }
                    }
                    if ($el instanceof \PhpJs\Value\JsBigInt && $search instanceof \PhpJs\Value\JsBigInt) {
                        if ($el->value === $search->value) {
                            return new JsNumber((float) $i);
                        }
                    }
                }
                return new JsNumber(-1.0);
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
                    if (\PhpJs\Spec\AbstractOperations::sameValueZero($search, $element)) {
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
                if (!$arg0 instanceof JsUndefined && !$arg0 instanceof JsFunction) {
                    throw new TypeError('The comparison function must be either a function or undefined');
                }
                $comparefn = $arg0 instanceof JsFunction ? $arg0 : null;
                $elements = $this_->toList();

                usort($elements, function (JsValue $a, JsValue $b) use ($comparefn): int {
                    if ($comparefn !== null) {
                        $result = $comparefn->call(JsUndefined::instance(), [$a, $b]);
                        return (int) TypeConversion::toNumber($result);
                    }
                    // Default numeric sort for typed arrays per spec.
                    // BigInt comparisons: compare as integers.
                    if ($a instanceof \PhpJs\Value\JsBigInt && $b instanceof \PhpJs\Value\JsBigInt) {
                        return \PhpJs\Spec\AbstractOperations::bigStrCompPublic($a->value, $b->value);
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
                    $result = $predicate->call($thisArg, [$el, new JsNumber((float) $i), $this_]);
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
                    $result = $predicate->call($thisArg, [$el, new JsNumber((float) $i), $this_]);
                    if (TypeConversion::toBoolean($result)) {
                        return new JsNumber((float) $i);
                    }
                }
                return new JsNumber(-1.0);
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
                $index = isset($args[0]) ? self::toInteger($args[0]) : 0;
                if ($index < 0) {
                    $index = $this_->getLength() + $index;
                }
                if ($index < 0 || $index >= $this_->getLength()) {
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
                if (!$arg0 instanceof JsUndefined && !$arg0 instanceof JsFunction) {
                    throw new TypeError(
                        'The comparison function must be either a function or undefined'
                    );
                }
                $comparefn = $arg0 instanceof JsFunction ? $arg0 : null;
                $elements = $this_->toList();

                usort($elements, function (JsValue $a, JsValue $b) use ($comparefn): int {
                    if ($comparefn !== null) {
                        $result = $comparefn->call(JsUndefined::instance(), [$a, $b]);
                        return (int) TypeConversion::toNumber($result);
                    }
                    // Default numeric sort for typed arrays per spec.
                    if (
                        $a instanceof \PhpJs\Value\JsBigInt
                        && $b instanceof \PhpJs\Value\JsBigInt
                    ) {
                        return \PhpJs\Spec\AbstractOperations::bigStrCompPublic(
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
                    $coerced = new JsNumber($numVal);
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
                return new JsNumber((float) $this_->getLength());
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
                return new JsNumber((float) ($this_->getLength() * $this_->getBytesPerElement()));
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
                return new JsNumber((float) $this_->getByteOffset());
            },
            0
        );
        $proto->defineOwnProperty(
            'byteOffset',
            PropertyDescriptor::accessor($byteOffsetGetter, null, false, true),
        );
    }

    /**
     * ValidateTypedArray per spec: throws TypeError if buffer is detached.
     */
    private static function validateTypedArray(JsTypedArray $ta): void
    {
        $ta->validateNotDetached();
    }

    /** Create an iterator for a typed array (entries, keys, or values). */
    private static function createTypedArrayIterator(JsTypedArray $ta, string $kind): JsObject
    {
        $index = 0;
        $iterSym = SymbolConstructor::iterator();
        $iterator = new JsObject();

        $nextFn = function () use ($ta, &$index, $kind): JsValue {
            $result = new JsObject();
            if ($index < $ta->getLength()) {
                $value = match ($kind) {
                    'key' => new JsNumber((float) $index),
                    'value' => $ta->getIndex($index),
                    default => JsArray::fromArray([
                        new JsNumber((float) $index),
                        $ta->getIndex($index),
                    ]),
                };
                $result->set('value', $value);
                $result->set('done', new JsBoolean(false));
                $index++;
            } else {
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
            }
            return $result;
        };

        $iterator->set('next', JsFunction::fromCallable('next', $nextFn));
        $iterator->setBySymbol(
            $iterSym,
            JsFunction::fromCallable('[Symbol.iterator]', function (JsValue $self_): JsValue {
                return $self_;
            }),
        );

        return $iterator;
    }

    /**
     * TypedArraySpeciesCreate(exemplar, argumentList).
     *
     * Per spec, looks up exemplar.constructor then constructor[Symbol.species]
     * to determine which constructor to use. Falls back to the default
     * constructor for the exemplar's type.
     *
     * When called with a single int $length, creates a new TypedArray of that length.
     * When called with a list of JsValue args, passes them directly to the species
     * constructor (used by subarray which passes buffer, byteOffset, length).
     *
     * @param list<JsValue>|null $speciesArgs If provided, passed directly to species Construct.
     */
    private static function typedArraySpeciesCreate(
        JsTypedArray $exemplar,
        int $length,
        ?array $speciesArgs = null,
    ): JsTypedArray {
        $defaultTypeName = $exemplar->getTypeName();
        $proto = $exemplar->getPrototype();

        // Step 2: Let C be ? Get(O, "constructor").
        $ctor = $exemplar->get('constructor');

        // Step 3: If C is undefined, return defaultConstructor result.
        if ($ctor instanceof JsUndefined) {
            if ($speciesArgs !== null) {
                return self::constructDefaultTypedArray($defaultTypeName, $speciesArgs, $proto);
            }
            return JsTypedArray::fromLength($defaultTypeName, $length, $proto);
        }

        // Step 4: If Type(C) is not Object, throw a TypeError.
        if (!$ctor instanceof JsObject) {
            throw new TypeError(
                $ctor->toJsString() . ' is not an object'
            );
        }

        // Step 5: Let S be ? Get(C, @@species).
        $speciesSym = SymbolConstructor::species();
        $species = $ctor->getBySymbol($speciesSym);

        // Step 6: If S is undefined or null, return defaultConstructor result.
        if (
            $species instanceof JsUndefined
            || $species instanceof JsNull
        ) {
            if ($speciesArgs !== null) {
                return self::constructDefaultTypedArray($defaultTypeName, $speciesArgs, $proto);
            }
            return JsTypedArray::fromLength(
                $defaultTypeName,
                $length,
                $proto,
            );
        }

        // Step 7: If IsConstructor(S), use it via Construct.
        if (
            $species instanceof JsFunction
            && $species->isConstructable()
        ) {
            $constructArgs = $speciesArgs ?? [new JsNumber((float) $length)];
            $result = $species->construct($constructArgs);
            if ($result instanceof JsTypedArray) {
                // Per spec: TypedArrayCreate step 3: if argumentList is a single
                // number and result.length < that number, throw TypeError.
                if ($speciesArgs === null && $result->getLength() < $length) {
                    throw new TypeError(
                        'Derived constructor created a TypedArray which was too small'
                    );
                }
                return $result;
            }
            throw new TypeError(
                'Species constructor did not return a TypedArray'
            );
        }

        throw new TypeError('Species constructor is not a constructor');
    }

    /**
     * Construct a typed array using the default constructor for the given type.
     * Used by subarray's species fallback path (buffer, byteOffset, length).
     *
     * @param list<JsValue> $args
     */
    private static function constructDefaultTypedArray(
        string $typeName,
        array $args,
        ?JsObject $proto,
    ): JsTypedArray {
        $bpe = JsTypedArray::TYPES[$typeName][0];
        // subarray passes (buffer, byteOffset, newLength).
        if (
            count($args) === 3
            && $args[0] instanceof JsArrayBuffer
        ) {
            $buffer = $args[0];
            $byteOffset = (int) TypeConversion::toNumber($args[1]);
            $newLength = (int) TypeConversion::toNumber($args[2]);
            return new JsTypedArray($typeName, $buffer, $byteOffset, $newLength, $proto);
        }
        // Single length arg.
        if (count($args) === 1) {
            $len = (int) TypeConversion::toNumber($args[0]);
            return JsTypedArray::fromLength($typeName, $len, $proto);
        }
        return JsTypedArray::fromLength($typeName, 0, $proto);
    }
}
