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
use Phasis\Value\JsProxy;

/**
 * TypedArrayConstructor trait part: BufferTypes. Composed into
 * TypedArrayConstructor via `use TypedArray\BufferTypes;`.
 */
trait BufferTypes
{
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
                $arg0 = $args[0] ?? JsNumber::of(0.0);
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
                $ntDesc = $this_->getOwnPropertyDescriptor('[[NewTarget]]');
                if ($ntDesc !== null && $ntDesc->value instanceof JsFunction) {
                    $ntProto = $ntDesc->value->get('prototype');
                    if ($ntProto instanceof JsObject) {
                        $useProto = $ntProto;
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
                    $newBuf = $ctor->construct([JsNumber::of((float) $newLen)]);
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

                // Per spec, Construct above runs the species constructor's
                // body, which can detach the source through user code.
                // Re-validate before the slice copy.
                if ($this_->isDetached()) {
                    throw new TypeError(
                        'Cannot perform ArrayBuffer.prototype.slice on a detached ArrayBuffer'
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
                return JsNumber::of((float) $this_->getByteLength());
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
                    return JsNumber::of(0.0);
                }
                return JsNumber::of((float) $this_->getMaxByteLength());
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
                // Per spec: check resizable BEFORE coercion, but defer the
                // detached check until AFTER ToIndex so user-supplied valueOf
                // observably runs even on a detached buffer.
                if (!$this_->isResizable()) {
                    throw new TypeError('ArrayBuffer is not resizable');
                }
                $newLenArg = $args[0] ?? JsUndefined::instance();
                $newByteLength = TypeConversion::toIndex($newLenArg);
                if ($this_->isDetached()) {
                    throw new TypeError('Cannot resize a detached ArrayBuffer');
                }
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
                $arg0 = $args[0] ?? JsNumber::of(0.0);
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
                $ntDesc = $this_->getOwnPropertyDescriptor('[[NewTarget]]');
                if ($ntDesc !== null && $ntDesc->value instanceof JsFunction) {
                    $ntProto = $ntDesc->value->get('prototype');
                    if ($ntProto instanceof JsObject) {
                        $useProto = $ntProto;
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
                return JsNumber::of((float) $this_->getByteLength());
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
                return JsNumber::of((float) $this_->getMaxByteLength());
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
                if ($ctor->isConstructable()) {
                    $newBuf = $ctor->construct([JsNumber::of((float) $newLen)]);
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
}
