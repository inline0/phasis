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

/**
 * TypedArrayConstructor trait part: DataView. Composed into
 * TypedArrayConstructor via `use TypedArray\DataView;`.
 */
trait DataView
{
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
                    // ToIndex on byteLength may detach via valueOf;
                    // re-validate per spec before computing the view.
                    if ($buffer->isDetached()) {
                        throw new TypeError(
                            'Cannot construct DataView on a detached ArrayBuffer'
                        );
                    }
                    if (($byteOffset + $byteLength) > $bufLen) {
                        throw new RangeError('Invalid DataView length');
                    }
                }

                $effectiveProto = $proto;
                if ($this_->has('[[NewTarget]]')) {
                    $newTarget = $this_->get('[[NewTarget]]');
                    if ($newTarget instanceof JsFunction) {
                        // Per spec OrdinaryCreateFromConstructor, the
                        // newTarget.prototype lookup is observable and may
                        // detach or resize the buffer through a getter;
                        // re-validate before instantiation.
                        $ntProto = $newTarget->get('prototype');
                        if ($ntProto instanceof JsObject) {
                            $effectiveProto = $ntProto;
                        }
                        if ($buffer->isDetached()) {
                            throw new TypeError(
                                'Cannot construct DataView on a detached ArrayBuffer'
                            );
                        }
                        // Re-validate ranges against the (possibly resized)
                        // buffer length.
                        $newBufLen = $buffer->getByteLength();
                        if ($byteOffset > $newBufLen) {
                            throw new RangeError(
                                'Start offset is outside the bounds of the buffer'
                            );
                        }
                        if ($byteLength !== null && ($byteOffset + $byteLength) > $newBufLen) {
                            throw new RangeError('Invalid DataView length');
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
                return JsNumber::of((float) $this_->getByteLength());
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
                return JsNumber::of((float) $this_->getByteOffset());
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
                return JsNumber::of((float) $dv->getInt8($offset));
            }],
            'getUint8' => [1, false, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return JsNumber::of((float) $dv->getUint8($offset));
            }],
            'getInt16' => [1, false, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return JsNumber::of((float) $dv->getInt16($offset, $le));
            }],
            'getUint16' => [1, false, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return JsNumber::of((float) $dv->getUint16($offset, $le));
            }],
            'getInt32' => [1, false, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return JsNumber::of((float) $dv->getInt32($offset, $le));
            }],
            'getUint32' => [1, false, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return JsNumber::of((float) $dv->getUint32($offset, $le));
            }],
            'getFloat16' => [1, false, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return JsNumber::of($dv->getFloat16($offset, $le));
            }],
            'getFloat32' => [1, false, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return JsNumber::of($dv->getFloat32($offset, $le));
            }],
            'getFloat64' => [1, false, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return JsNumber::of($dv->getFloat64($offset, $le));
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
                return new \Phasis\Value\JsBigInt((string) $dv->getBigInt64($offset, $le));
            }],
            'getBigUint64' => [1, true, function (JsDataView $dv, int $offset, bool $le): JsValue {
                $raw = $dv->getBigUint64($offset, $le);
                if ($raw < 0) {
                    $str = bcadd((string) $raw, '18446744073709551616');
                } else {
                    $str = (string) $raw;
                }
                return new \Phasis\Value\JsBigInt($str);
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
                $numValue = 0.0;
                $rawInt = 0;
                if ($isBigInt) {
                    $bigIntValue = TypeConversion::toBigInt($valueArg);
                    $rawInt = self::bigIntToRawInt($bigIntValue);
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
                    default => throw new \LogicException("Unexpected DataView method: {$name}"),
                };

                return JsUndefined::instance();
            };
            $fn = JsFunction::fromCallable($name, $cb, $length);
            $proto->defineOwnProperty($name, PropertyDescriptor::data($fn, true, false, true));
        }
    }
}
