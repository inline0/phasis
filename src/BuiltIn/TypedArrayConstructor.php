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
        self::installDataView($env);
        self::installTypedArrays($env);
    }

    private static function installArrayBuffer(Environment $env): void
    {
        $proto = new JsObject();

        $constructor = JsFunction::fromCallable(
            'ArrayBuffer',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                $arg0 = $args[0] ?? new JsNumber(0.0);
                $length = (int) TypeConversion::toNumber($arg0);
                if ($length < 0) {
                    throw new RangeError('Invalid array buffer length');
                }
                return new JsArrayBuffer($length, $proto);
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
        $sliceFn = JsFunction::fromCallable('slice', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsArrayBuffer) {
                throw new TypeError('Method ArrayBuffer.prototype.slice called on incompatible receiver');
            }
            $begin = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
            $end = isset($args[1]) && !$args[1] instanceof JsUndefined
                ? (int) TypeConversion::toNumber($args[1])
                : $this_->getByteLength();
            return $this_->sliceBuffer($begin, $end);
        }, 2);
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

        $env->defineVar('ArrayBuffer', $constructor);
    }

    private static function installDataView(Environment $env): void
    {
        $proto = new JsObject();

        $constructor = JsFunction::fromCallable(
            'DataView',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                $buffer = $args[0] ?? JsUndefined::instance();
                if (!$buffer instanceof JsArrayBuffer) {
                    throw new TypeError('First argument to DataView constructor must be an ArrayBuffer');
                }

                $byteOffset = isset($args[1]) && !$args[1] instanceof JsUndefined
                    ? (int) TypeConversion::toNumber($args[1])
                    : 0;
                $byteLength = isset($args[2]) && !$args[2] instanceof JsUndefined
                    ? (int) TypeConversion::toNumber($args[2])
                    : null;

                if ($byteOffset < 0 || $byteOffset > $buffer->getByteLength()) {
                    throw new RangeError('Start offset is outside the bounds of the buffer');
                }
                if ($byteLength !== null && ($byteOffset + $byteLength) > $buffer->getByteLength()) {
                    throw new RangeError('Invalid DataView length');
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
     */
    private static function installDataViewMethods(JsObject $proto): void
    {
        $methods = [
            'getInt8' => [1, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new JsNumber((float) $dv->getInt8($offset));
            }],
            'getUint8' => [1, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new JsNumber((float) $dv->getUint8($offset));
            }],
            'getInt16' => [1, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new JsNumber((float) $dv->getInt16($offset, $le));
            }],
            'getUint16' => [1, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new JsNumber((float) $dv->getUint16($offset, $le));
            }],
            'getInt32' => [1, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new JsNumber((float) $dv->getInt32($offset, $le));
            }],
            'getUint32' => [1, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new JsNumber((float) $dv->getUint32($offset, $le));
            }],
            'getFloat32' => [1, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new JsNumber($dv->getFloat32($offset, $le));
            }],
            'getFloat64' => [1, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new JsNumber($dv->getFloat64($offset, $le));
            }],
            'setInt8' => [2, function (JsDataView $dv, int $offset, bool $le, float $val): JsValue {
                $dv->setInt8($offset, (int) $val);
                return JsUndefined::instance();
            }],
            'setUint8' => [2, function (JsDataView $dv, int $offset, bool $le, float $val): JsValue {
                $dv->setUint8($offset, (int) $val);
                return JsUndefined::instance();
            }],
            'setInt16' => [2, function (JsDataView $dv, int $offset, bool $le, float $val): JsValue {
                $dv->setInt16($offset, (int) $val, $le);
                return JsUndefined::instance();
            }],
            'setUint16' => [2, function (JsDataView $dv, int $offset, bool $le, float $val): JsValue {
                $dv->setUint16($offset, (int) $val, $le);
                return JsUndefined::instance();
            }],
            'setInt32' => [2, function (JsDataView $dv, int $offset, bool $le, float $val): JsValue {
                $dv->setInt32($offset, (int) $val, $le);
                return JsUndefined::instance();
            }],
            'setUint32' => [2, function (JsDataView $dv, int $offset, bool $le, float $val): JsValue {
                $dv->setUint32($offset, (int) $val, $le);
                return JsUndefined::instance();
            }],
            'setFloat32' => [2, function (JsDataView $dv, int $offset, bool $le, float $val): JsValue {
                $dv->setFloat32($offset, $val, $le);
                return JsUndefined::instance();
            }],
            'setFloat64' => [2, function (JsDataView $dv, int $offset, bool $le, float $val): JsValue {
                $dv->setFloat64($offset, $val, $le);
                return JsUndefined::instance();
            }],
            'getBigInt64' => [1, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new \PhpJs\Value\JsBigInt((string) $dv->getBigInt64($offset, $le));
            }],
            'getBigUint64' => [1, function (JsDataView $dv, int $offset, bool $le): JsValue {
                return new \PhpJs\Value\JsBigInt((string) $dv->getBigUint64($offset, $le));
            }],
            'setBigInt64' => [2, function (JsDataView $dv, int $offset, bool $le, float $val): JsValue {
                $dv->setBigInt64($offset, (int) $val, $le);
                return JsUndefined::instance();
            }],
            'setBigUint64' => [2, function (JsDataView $dv, int $offset, bool $le, float $val): JsValue {
                $dv->setBigUint64($offset, (int) $val, $le);
                return JsUndefined::instance();
            }],
        ];

        foreach ($methods as $name => [$length, $handler]) {
            $isGetter = str_starts_with($name, 'get');
            $cb = function (JsValue $this_, array $args) use ($name, $handler, $isGetter): JsValue {
                if (!$this_ instanceof JsDataView) {
                    throw new TypeError("Method DataView.prototype.{$name} called on incompatible receiver");
                }

                $offset = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
                $littleEndian = isset($args[$isGetter ? 1 : 2])
                    ? TypeConversion::toBoolean($args[$isGetter ? 1 : 2])
                    : false;

                if ($isGetter) {
                    return $handler($this_, $offset, $littleEndian);
                }

                $value = isset($args[1]) ? TypeConversion::toNumber($args[1]) : 0.0;
                return $handler($this_, $offset, $littleEndian, $value);
            };
            $fn = JsFunction::fromCallable($name, $cb, $length);
            $proto->defineOwnProperty($name, PropertyDescriptor::data($fn, true, false, true));
        }
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

        $constructor = JsFunction::fromCallable(
            $typeName,
            function (JsValue $this_, array $args) use ($typeName, $bpe, $proto): JsValue {
                return self::constructTypedArray($typeName, $bpe, $proto, $args);
            },
            3,
        );
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

                // Step 3: Validate mapfn before accessing source.
                $mapFn = $args[1] ?? JsUndefined::instance();
                if (
                    !$mapFn instanceof JsUndefined
                    && !$mapFn instanceof JsFunction
                ) {
                    throw new TypeError(
                        'TypedArray.from: mapfn is not a function'
                    );
                }

                return $this_->call(
                    $this_,
                    [self::collectFromSource($args)],
                );
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
                return $this_->call($this_, [$arr]);
            },
            0,
        );
        $intrinsic->defineOwnProperty(
            'of',
            PropertyDescriptor::data($ofFn, true, false, true),
        );
    }

    /**
     * Collect elements from source for TypedArray.from.
     * Returns a JsArray for passing to the constructor.
     */
    private static function collectFromSource(array $args): JsArray
    {
        $source = $args[0] ?? JsUndefined::instance();
        $mapFn = $args[1] ?? JsUndefined::instance();
        $thisArg = $args[2] ?? JsUndefined::instance();
        $hasMapFn = $mapFn instanceof JsFunction;

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

        if ($hasMapFn) {
            $mapped = [];
            foreach ($elements as $i => $el) {
                $mapped[] = $mapFn->call($thisArg, [$el, new JsNumber((float) $i)]);
            }
            $elements = $mapped;
        }

        return JsArray::fromArray($elements);
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

        // new TypedArray(length).
        if ($arg0 instanceof JsNumber) {
            $len = (int) $arg0->value;
            if ($len < 0 || $arg0->value !== (float) $len) {
                throw new RangeError('Invalid typed array length: ' . $arg0->toJsString());
            }
            return JsTypedArray::fromLength($typeName, $len, $proto);
        }

        // new TypedArray(buffer, byteOffset, length).
        if ($arg0 instanceof JsArrayBuffer) {
            $byteOffset = isset($args[1]) && !$args[1] instanceof JsUndefined
                ? (int) TypeConversion::toNumber($args[1])
                : 0;

            if ($byteOffset % $bpe !== 0) {
                throw new RangeError("Start offset of {$typeName} should be a multiple of {$bpe}");
            }

            if (isset($args[2]) && !$args[2] instanceof JsUndefined) {
                $length = (int) TypeConversion::toNumber($args[2]);
            } else {
                $remaining = $arg0->getByteLength() - $byteOffset;
                if ($remaining % $bpe !== 0) {
                    throw new RangeError("Byte length of {$typeName} should be a multiple of {$bpe}");
                }
                $length = (int) ($remaining / $bpe);
            }

            if ($length < 0) {
                throw new RangeError('Invalid typed array length');
            }

            return new JsTypedArray($typeName, $arg0, $byteOffset, $length, $proto);
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
        if ($arg0 instanceof JsArray) {
            $srcLen = $arg0->getLength();
            $result = JsTypedArray::fromLength($typeName, $srcLen, $proto);
            for ($i = 0; $i < $srcLen; $i++) {
                $result->setIndex($i, $arg0->get((string) $i));
            }
            return $result;
        }

        // Generic object with length property.
        if ($arg0 instanceof JsObject) {
            // Try iterator protocol first.
            $iterSym = SymbolConstructor::iterator();
            $iterMethod = $arg0->getBySymbol($iterSym);
            if ($iterMethod instanceof JsFunction) {
                $elements = self::consumeIterator($iterMethod, $arg0);
                return JsTypedArray::fromArray($typeName, $elements, $proto);
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

        // Single primitive value: treat as length.
        $len = (int) TypeConversion::toNumber($arg0);
        return JsTypedArray::fromLength($typeName, max(0, $len), $proto);
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
        $fromFn = JsFunction::fromCallable(
            'from',
            function (JsValue $this_, array $args) use ($typeName, $proto): JsValue {
            // Validate this is a constructor.
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

            // Validate mapfn before accessing source.
                if (
                    !$mapFn instanceof JsUndefined
                    && !$mapFn instanceof JsFunction
                ) {
                    throw new TypeError(
                        'TypedArray.from: mapfn is not a function'
                    );
                }

                $hasMapFn = $mapFn instanceof JsFunction;

            // Collect source elements.
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

            // Apply mapFn if provided.
                if ($hasMapFn) {
                    $mapped = [];
                    foreach ($elements as $i => $el) {
                        $mapped[] = $mapFn->call($thisArg, [$el, new JsNumber((float) $i)]);
                    }
                    $elements = $mapped;
                }

                return JsTypedArray::fromArray($typeName, $elements, $proto);
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
        $setFn = JsFunction::fromCallable('set', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.set called on incompatible receiver");
            }

            $source = $args[0] ?? JsUndefined::instance();
            $offset = isset($args[1]) ? self::toInteger($args[1]) : 0;

            if ($source instanceof JsTypedArray) {
                for ($i = 0; $i < $source->getLength(); $i++) {
                    $this_->setIndex($offset + $i, $source->getIndex($i));
                }
            } elseif ($source instanceof JsArray) {
                for ($i = 0; $i < $source->getLength(); $i++) {
                    $this_->setIndex($offset + $i, $source->get((string) $i));
                }
            } elseif ($source instanceof JsObject) {
                $len = (int) TypeConversion::toNumber($source->get('length'));
                for ($i = 0; $i < $len; $i++) {
                    $this_->setIndex($offset + $i, $source->get((string) $i));
                }
            }

            return JsUndefined::instance();
        }, 1);
        $proto->defineOwnProperty('set', PropertyDescriptor::data($setFn, true, false, true));

        // subarray(begin, end).
        $subarrayFn = JsFunction::fromCallable('subarray', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.subarray called on incompatible receiver");
            }
            $begin = isset($args[0]) ? self::toInteger($args[0]) : 0;
            $end = isset($args[1]) && !$args[1] instanceof JsUndefined
                ? self::toInteger($args[1])
                : null;
            return $this_->subarray($begin, $end);
        }, 2);
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
        $copyWithinFn = JsFunction::fromCallable('copyWithin', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.copyWithin called on incompatible receiver");
            }
            $target = isset($args[0]) ? self::toInteger($args[0]) : 0;
            $start = isset($args[1]) ? self::toInteger($args[1]) : 0;
            $end = isset($args[2]) && !$args[2] instanceof JsUndefined
                ? self::toInteger($args[2])
                : null;
            return $this_->copyWithinTyped($target, $start, $end);
        }, 2);
        $proto->defineOwnProperty('copyWithin', PropertyDescriptor::data($copyWithinFn, true, false, true));

        // fill(value, start, end).
        $fillFn = JsFunction::fromCallable('fill', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.fill called on incompatible receiver");
            }
            $value = $args[0] ?? JsUndefined::instance();

            // Per spec: coerce value to numeric type ONCE before start/end evaluation.
            // If ContentType is BigInt, set value to ToBigInt(value).
            // Otherwise, set value to ToNumber(value).
            if ($this_->isBigIntArray()) {
                if ($value instanceof \PhpJs\Value\JsBigInt) {
                    $coerced = $value;
                } else {
                    // ToBigInt: only BigInt and strings that parse as BigInt are valid.
                    // Numbers, null, undefined, booleans, symbols all throw TypeError.
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
            return $this_->fillTyped($coerced, $start, $end);
        }, 1);
        $proto->defineOwnProperty('fill', PropertyDescriptor::data($fillFn, true, false, true));

        // find(predicate, thisArg).
        $findFn = JsFunction::fromCallable('find', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.find called on incompatible receiver");
            }
            $predicate = $args[0] ?? JsUndefined::instance();
            if (!$predicate instanceof JsFunction) {
                throw new TypeError('predicate is not a function');
            }
            $thisArg = $args[1] ?? JsUndefined::instance();
            for ($i = 0; $i < $this_->getLength(); $i++) {
                $el = $this_->getIndex($i);
                $result = $predicate->call($thisArg, [$el, new JsNumber((float) $i), $this_]);
                if (TypeConversion::toBoolean($result)) {
                    return $el;
                }
            }
            return JsUndefined::instance();
        }, 1);
        $proto->defineOwnProperty('find', PropertyDescriptor::data($findFn, true, false, true));

        // findIndex(predicate, thisArg).
        $findIndexFn = JsFunction::fromCallable('findIndex', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.findIndex called on incompatible receiver");
            }
            $predicate = $args[0] ?? JsUndefined::instance();
            if (!$predicate instanceof JsFunction) {
                throw new TypeError('predicate is not a function');
            }
            $thisArg = $args[1] ?? JsUndefined::instance();
            for ($i = 0; $i < $this_->getLength(); $i++) {
                $el = $this_->getIndex($i);
                $result = $predicate->call($thisArg, [$el, new JsNumber((float) $i), $this_]);
                if (TypeConversion::toBoolean($result)) {
                    return new JsNumber((float) $i);
                }
            }
            return new JsNumber(-1.0);
        }, 1);
        $proto->defineOwnProperty('findIndex', PropertyDescriptor::data($findIndexFn, true, false, true));

        // forEach(callback, thisArg).
        $forEachFn = JsFunction::fromCallable('forEach', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.forEach called on incompatible receiver");
            }
            $callback = $args[0] ?? JsUndefined::instance();
            if (!$callback instanceof JsFunction) {
                throw new TypeError('callback is not a function');
            }
            $thisArg = $args[1] ?? JsUndefined::instance();
            for ($i = 0; $i < $this_->getLength(); $i++) {
                $callback->call($thisArg, [$this_->getIndex($i), new JsNumber((float) $i), $this_]);
            }
            return JsUndefined::instance();
        }, 1);
        $proto->defineOwnProperty('forEach', PropertyDescriptor::data($forEachFn, true, false, true));

        // map(callback, thisArg): uses SpeciesConstructor per spec.
        $mapFn = JsFunction::fromCallable('map', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.map called on incompatible receiver");
            }
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
        }, 1);
        $proto->defineOwnProperty('map', PropertyDescriptor::data($mapFn, true, false, true));

        // filter(callback, thisArg): uses SpeciesConstructor per spec.
        $filterFn = JsFunction::fromCallable('filter', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.filter called on incompatible receiver");
            }
            $callback = $args[0] ?? JsUndefined::instance();
            if (!$callback instanceof JsFunction) {
                throw new TypeError('callback is not a function');
            }
            $thisArg = $args[1] ?? JsUndefined::instance();
            $kept = [];
            for ($i = 0; $i < $this_->getLength(); $i++) {
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
        }, 1);
        $proto->defineOwnProperty('filter', PropertyDescriptor::data($filterFn, true, false, true));

        // reduce(callback, initialValue).
        $reduceFn = JsFunction::fromCallable('reduce', function (JsValue $this_, array $args) use ($typeName): JsValue {
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
        }, 1);
        $proto->defineOwnProperty('reduce', PropertyDescriptor::data($reduceFn, true, false, true));

        // reduceRight(callback, initialValue).
        $reduceRightFn = JsFunction::fromCallable('reduceRight', function (JsValue $this_, array $args) use ($typeName): JsValue {
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
        }, 1);
        $proto->defineOwnProperty('reduceRight', PropertyDescriptor::data($reduceRightFn, true, false, true));

        // some(callback, thisArg).
        $someFn = JsFunction::fromCallable('some', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.some called on incompatible receiver");
            }
            $callback = $args[0] ?? JsUndefined::instance();
            if (!$callback instanceof JsFunction) {
                throw new TypeError('callback is not a function');
            }
            $thisArg = $args[1] ?? JsUndefined::instance();
            for ($i = 0; $i < $this_->getLength(); $i++) {
                $result = $callback->call($thisArg, [$this_->getIndex($i), new JsNumber((float) $i), $this_]);
                if (TypeConversion::toBoolean($result)) {
                    return new JsBoolean(true);
                }
            }
            return new JsBoolean(false);
        }, 1);
        $proto->defineOwnProperty('some', PropertyDescriptor::data($someFn, true, false, true));

        // every(callback, thisArg).
        $everyFn = JsFunction::fromCallable('every', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.every called on incompatible receiver");
            }
            $callback = $args[0] ?? JsUndefined::instance();
            if (!$callback instanceof JsFunction) {
                throw new TypeError('callback is not a function');
            }
            $thisArg = $args[1] ?? JsUndefined::instance();
            for ($i = 0; $i < $this_->getLength(); $i++) {
                $result = $callback->call($thisArg, [$this_->getIndex($i), new JsNumber((float) $i), $this_]);
                if (!TypeConversion::toBoolean($result)) {
                    return new JsBoolean(false);
                }
            }
            return new JsBoolean(true);
        }, 1);
        $proto->defineOwnProperty('every', PropertyDescriptor::data($everyFn, true, false, true));

        // indexOf(searchElement, fromIndex).
        $indexOfFn = JsFunction::fromCallable('indexOf', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.indexOf called on incompatible receiver");
            }
            $search = $args[0] ?? JsUndefined::instance();
            $fromIndex = isset($args[1]) ? self::toInteger($args[1]) : 0;
            return new JsNumber((float) $this_->indexOfTyped($search, $fromIndex));
        }, 1);
        $proto->defineOwnProperty('indexOf', PropertyDescriptor::data($indexOfFn, true, false, true));

        // lastIndexOf(searchElement, fromIndex).
        $lastIndexOfFn = JsFunction::fromCallable('lastIndexOf', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.lastIndexOf called on incompatible receiver");
            }
            $search = $args[0] ?? JsUndefined::instance();
            $fromIndex = isset($args[1]) ? self::toInteger($args[1]) : $this_->getLength() - 1;
            if ($fromIndex < 0) {
                $fromIndex = max(0, $this_->getLength() + $fromIndex);
            }
            for ($i = min($fromIndex, $this_->getLength() - 1); $i >= 0; $i--) {
                $el = $this_->getIndex($i);
                if ($el instanceof JsNumber && $search instanceof JsNumber) {
                    if (!is_nan($el->value) && !is_nan($search->value) && $el->value === $search->value) {
                        return new JsNumber((float) $i);
                    }
                }
            }
            return new JsNumber(-1.0);
        }, 1);
        $proto->defineOwnProperty('lastIndexOf', PropertyDescriptor::data($lastIndexOfFn, true, false, true));

        // includes(searchElement, fromIndex).
        $includesFn = JsFunction::fromCallable('includes', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.includes called on incompatible receiver");
            }
            // Per spec: if length is 0, return false before ToInteger(fromIndex).
            if ($this_->getLength() === 0) {
                return new JsBoolean(false);
            }
            $search = $args[0] ?? JsUndefined::instance();
            $fromIndex = isset($args[1]) ? self::toInteger($args[1]) : 0;
            return new JsBoolean($this_->includesTyped($search, $fromIndex));
        }, 1);
        $proto->defineOwnProperty('includes', PropertyDescriptor::data($includesFn, true, false, true));

        // join(separator).
        $joinFn = JsFunction::fromCallable('join', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.join called on incompatible receiver");
            }
            $separator = isset($args[0]) && !$args[0] instanceof JsUndefined
                ? TypeConversion::toString($args[0])
                : ',';
            return new JsString($this_->joinTyped($separator));
        }, 1);
        $proto->defineOwnProperty('join', PropertyDescriptor::data($joinFn, true, false, true));

        // toString() - delegates to join.
        $toStringFn = JsFunction::fromCallable('toString', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.toString called on incompatible receiver");
            }
            return new JsString($this_->joinTyped(','));
        }, 0);
        $proto->defineOwnProperty('toString', PropertyDescriptor::data($toStringFn, true, false, true));

        // reverse().
        $reverseFn = JsFunction::fromCallable('reverse', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.reverse called on incompatible receiver");
            }
            return $this_->reverseTyped();
        }, 0);
        $proto->defineOwnProperty('reverse', PropertyDescriptor::data($reverseFn, true, false, true));

        // sort(comparefn).
        $sortFn = JsFunction::fromCallable('sort', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.sort called on incompatible receiver");
            }
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
                    return bccomp($a->value, $b->value);
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
        }, 1);
        $proto->defineOwnProperty('sort', PropertyDescriptor::data($sortFn, true, false, true));

        // entries().
        $entriesFn = JsFunction::fromCallable('entries', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.entries called on incompatible receiver");
            }
            return self::createTypedArrayIterator($this_, 'key+value');
        }, 0);
        $proto->defineOwnProperty('entries', PropertyDescriptor::data($entriesFn, true, false, true));

        // keys().
        $keysFn = JsFunction::fromCallable('keys', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.keys called on incompatible receiver");
            }
            return self::createTypedArrayIterator($this_, 'key');
        }, 0);
        $proto->defineOwnProperty('keys', PropertyDescriptor::data($keysFn, true, false, true));

        // values().
        $valuesFn = JsFunction::fromCallable('values', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.values called on incompatible receiver");
            }
            return self::createTypedArrayIterator($this_, 'value');
        }, 0);
        $proto->defineOwnProperty('values', PropertyDescriptor::data($valuesFn, true, false, true));

        // findLast(predicate, thisArg).
        $findLastFn = JsFunction::fromCallable('findLast', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.findLast called on incompatible receiver");
            }
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
        }, 1);
        $proto->defineOwnProperty('findLast', PropertyDescriptor::data($findLastFn, true, false, true));

        // findLastIndex(predicate, thisArg).
        $findLastIndexFn = JsFunction::fromCallable('findLastIndex', function (JsValue $this_, array $args) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError("Method {$typeName}.prototype.findLastIndex called on incompatible receiver");
            }
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
        }, 1);
        $proto->defineOwnProperty('findLastIndex', PropertyDescriptor::data($findLastIndexFn, true, false, true));

        // at(index).
        $atFn = JsFunction::fromCallable('at', function (JsValue $this_, array $args) use ($typeName): JsValue {
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
        }, 1);
        $proto->defineOwnProperty('at', PropertyDescriptor::data($atFn, true, false, true));
    }

    /** Install accessor properties on the typed array prototype. */
    private static function installTypedArrayAccessors(JsObject $proto, string $typeName): void
    {
        // length getter.
        $lengthGetter = JsFunction::fromCallable('get length', function (JsValue $this_) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError(
                    "Method get {$typeName}.prototype.length called on incompatible receiver"
                );
            }
            return new JsNumber((float) $this_->getLength());
        }, 0);
        $proto->defineOwnProperty(
            'length',
            PropertyDescriptor::accessor($lengthGetter, null, false, true),
        );

        // buffer getter.
        $bufferGetter = JsFunction::fromCallable('get buffer', function (JsValue $this_) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError(
                    "Method get {$typeName}.prototype.buffer called on incompatible receiver"
                );
            }
            return $this_->getBuffer();
        }, 0);
        $proto->defineOwnProperty(
            'buffer',
            PropertyDescriptor::accessor($bufferGetter, null, false, true),
        );

        // byteLength getter.
        $byteLengthGetter = JsFunction::fromCallable('get byteLength', function (JsValue $this_) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError(
                    "Method get {$typeName}.prototype.byteLength called on incompatible receiver"
                );
            }
            return new JsNumber((float) ($this_->getLength() * $this_->getBytesPerElement()));
        }, 0);
        $proto->defineOwnProperty(
            'byteLength',
            PropertyDescriptor::accessor($byteLengthGetter, null, false, true),
        );

        // byteOffset getter.
        $byteOffsetGetter = JsFunction::fromCallable('get byteOffset', function (JsValue $this_) use ($typeName): JsValue {
            if (!$this_ instanceof JsTypedArray) {
                throw new TypeError(
                    "Method get {$typeName}.prototype.byteOffset called on incompatible receiver"
                );
            }
            return new JsNumber((float) $this_->getByteOffset());
        }, 0);
        $proto->defineOwnProperty(
            'byteOffset',
            PropertyDescriptor::accessor($byteOffsetGetter, null, false, true),
        );
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
     * TypedArraySpeciesCreate(exemplar, length).
     *
     * Per spec, looks up exemplar.constructor then constructor[Symbol.species]
     * to determine which constructor to use. Falls back to the default
     * constructor for the exemplar's type.
     */
    private static function typedArraySpeciesCreate(
        JsTypedArray $exemplar,
        int $length,
    ): JsTypedArray {
        $defaultTypeName = $exemplar->getTypeName();
        $proto = $exemplar->getPrototype();

        // Step 2: Let C be ? Get(O, "constructor").
        $ctor = $exemplar->get('constructor');

        // Step 3: If C is undefined, return defaultConstructor result.
        if ($ctor instanceof JsUndefined) {
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
            return JsTypedArray::fromLength(
                $defaultTypeName,
                $length,
                $proto,
            );
        }

        // Step 7: If IsConstructor(S), use it.
        if (
            $species instanceof JsFunction
            && $species->isConstructable()
        ) {
            $result = $species->call(
                $species,
                [new JsNumber((float) $length)],
            );
            if ($result instanceof JsTypedArray) {
                return $result;
            }
            throw new TypeError(
                'Species constructor did not return a TypedArray'
            );
        }

        throw new TypeError('Species constructor is not a constructor');
    }
}
