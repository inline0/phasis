<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\RangeError;
use PhpJs\Exceptions\TypeError;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsArray;
use PhpJs\Value\JsArrayBuffer;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsDataView;
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
                if (!$this_ instanceof JsArrayBuffer) {
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
                        // Step 10: Throw a TypeError exception.
                        if (!($species instanceof JsFunction && $species->isConstructable())) {
                            throw new TypeError(
                                'ArrayBuffer.prototype.slice: species constructor is not a constructor'
                            );
                        }
                        $ctor = $species;
                    }
                }

                // Step 15: Construct(ctor, newLen).
                if ($ctor instanceof JsFunction && $ctor->isConstructable()) {
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
                if (!$this_ instanceof JsArrayBuffer) {
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
                if (!$this_ instanceof JsArrayBuffer) {
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
                if (!$this_ instanceof JsArrayBuffer) {
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
                if (!$this_ instanceof JsArrayBuffer) {
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
                        'Method SharedArrayBuffer.prototype.slice called on incompatible receiver'
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
                $newBuf = new JsSharedArrayBuffer($newLen, $this_->getPrototype());
                if ($newLen > 0) {
                    $existingData = $newBuf->getData();
                    $newData = substr_replace($existingData, $slicedData, 0, $newLen);
                    $newBuf->setData($newData);
                }
                return $newBuf;
            },
            2,
        );
        $proto->defineOwnProperty('slice', PropertyDescriptor::data($sliceFn, true, false, true));

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
        $constructor->set('prototype', $proto);

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

                return new JsDataView($buffer, $byteOffset, $byteLength, $proto);
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
        $constructor->set('prototype', $proto);

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
                // Per spec (AllocateTypedArray): use GetPrototypeFromConstructor
                // to determine the prototype. When called via Reflect.construct
                // with a custom newTarget, use newTarget's prototype.
                $actualProto = $proto;
                $ntDesc = $this_->getOwnPropertyDescriptor('[[NewTarget]]');
                if ($ntDesc !== null && $ntDesc->value instanceof JsFunction) {
                    $nt = $ntDesc->value;
                    // Only resolve custom proto when newTarget differs from the
                    // typed array constructor itself.
                    if ($ctorRef !== null && $nt !== $ctorRef) {
                        $ntProto = $nt->get('prototype');
                        if ($ntProto instanceof JsObject) {
                            $actualProto = $ntProto;
                        }
                    }
                }
                return self::constructTypedArray($typeName, $bpe, $actualProto, $args);
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

        // Static methods: from(), of() on each subtype constructor.
        self::installTypedArrayStaticMethods($constructor, $typeName, $bpe, $proto);

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
                    } else {
                        $len = (int) TypeConversion::toNumber($source->get('length'));
                        for ($i = 0; $i < $len; $i++) {
                            $elements[] = $source->get((string) $i);
                        }
                    }
                }

                $len = count($elements);

                // TypedArrayCreate(C, [len]): construct via C.
                $targetObj = $this_->construct([new JsNumber((float) $len)]);

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
                $arr = JsArray::fromArray($args);
                // Use Construct to create the new typed array.
                return $this_->construct([$arr]);
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
        JsObject $proto,
        array $args,
    ): JsTypedArray {
        if (empty($args) || $args[0] instanceof JsUndefined) {
            return JsTypedArray::fromLength($typeName, 0, $proto);
        }

        $arg0 = $args[0];

        // new TypedArray(length): non-object first arg uses ToIndex.
        if (!$arg0 instanceof JsObject) {
            $len = TypeConversion::toIndex($arg0);
            return JsTypedArray::fromLength($typeName, $len, $proto);
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

            $ta = new JsTypedArray($typeName, $arg0, $byteOffset, $length, $proto);
            if ($isResizable && !$lengthExplicit) {
                $ta->setAutoLength(true);
            }
            return $ta;
        }

        // new TypedArray(typedArray): copy elements.
        if ($arg0 instanceof JsTypedArray) {
            $srcLen = $arg0->getLength();
            $result = JsTypedArray::fromLength($typeName, $srcLen, $proto);
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
                return JsTypedArray::fromArray($typeName, $elements, $proto);
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
                $result = JsTypedArray::fromLength($typeName, $len, $proto);
                for ($i = 0; $i < $len; $i++) {
                    $result->setIndex($i, $arg0->get((string) $i));
                }
                return $result;
            }

            return JsTypedArray::fromLength($typeName, 0, $proto);
        }

        // Single primitive value: treat as length via ToIndex.
        $len = TypeConversion::toIndex($arg0);
        return JsTypedArray::fromLength($typeName, $len, $proto);
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
            if (!$result instanceof JsObject) {
                break;
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
                $count = max(0, $end - $begin);

                // TypedArraySpeciesCreate via SpeciesConstructor.
                $result = self::typedArraySpeciesCreate($this_, $count);

                // Per spec: if count > 0, check if buffer was detached
                // (may happen during species constructor or start/end coercion).
                if ($count > 0) {
                    $this_->validateNotDetached();
                }

                for ($i = 0; $i < $count; $i++) {
                    $result->setIndex($i, $this_->getIndex($begin + $i));
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
                $this_->validateNotDetached();
                $value = $args[0] ?? JsUndefined::instance();

            // Per spec: coerce value to numeric type ONCE before start/end evaluation.
            // If ContentType is BigInt, set value to ToBigInt(value).
            // Otherwise, set value to ToNumber(value).
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
                return $this_->fillTyped($coerced, $start, $end);
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
                // Per spec: if length is 0, return false before ToInteger(fromIndex).
                if ($this_->getLength() === 0) {
                    return new JsBoolean(false);
                }
                $search = $args[0] ?? JsUndefined::instance();
                $fromIndex = isset($args[1]) ? self::toInteger($args[1]) : 0;
                return new JsBoolean($this_->includesTyped($search, $fromIndex));
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
                // Per spec: ValidateTypedArray first (throws if detached).
                self::validateTypedArray($this_);
                // Capture length before separator toString (which may detach buffer).
                $len = $this_->getLength();
                $separator = isset($args[0]) && !$args[0] instanceof JsUndefined
                ? TypeConversion::toString($args[0])
                : ',';
                // If buffer was detached during separator toString, return commas.
                if ($this_->getBuffer()->isDetached()) {
                    return new JsString($len > 0 ? str_repeat($separator, $len - 1) : '');
                }
                return new JsString($this_->joinTyped($separator));
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
                $len = $this_->getLength();
                $relativeIndex = isset($args[0])
                    ? self::toInteger($args[0])
                    : 0;
                $value = $args[1] ?? JsUndefined::instance();

                // Per spec: coerce value to the typed array's numeric type
                // BEFORE resolving the index, so type errors are thrown first.
                if ($this_->isBigIntArray()) {
                    $coerced = TypeConversion::toBigInt($value);
                } else {
                    $numVal = TypeConversion::toNumber($value);
                    $coerced = new JsNumber($numVal);
                }

                // Resolve relative index.
                $actualIndex = $relativeIndex >= 0
                    ? $relativeIndex
                    : $len + $relativeIndex;

                if ($actualIndex < 0 || $actualIndex >= $len) {
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
                    } else {
                        $result->setIndex($i, $this_->getIndex($i));
                    }
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
