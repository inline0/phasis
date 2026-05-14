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
 * TypedArrayConstructor trait part: TypedArrayHelpers. Composed into
 * TypedArrayConstructor via `use TypedArray\TypedArrayHelpers;`.
 */
trait TypedArrayHelpers
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

    /**
     * Convert a JsBigInt value to a raw PHP int for DataView setBigInt64/setBigUint64.
     */
    private static function bigIntToRawInt(\Phasis\Value\JsBigInt $bigInt): int
    {
        $mod = '18446744073709551616'; // 2^64
        $half = '9223372036854775808'; // 2^63
        $result = bcmod($bigInt->value, $mod);
        if ($result[0] === '-') {
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
                // Resolve "Invoke(element, 'toLocaleString')" against the
                // CURRENT realm's Number.prototype so a redefined
                // toLocaleString on the active realm's Number.prototype
                // wins even when the receiver is a cross-realm typed
                // array (per staging/sm/TypedArray/toLocaleString).
                $currentRealm = \Phasis\Engine::getCurrentRealm();
                $realmNumberProto = null;
                $realmStringProto = null;
                $realmBoolProto = null;
                if ($currentRealm !== null) {
                    $env = $currentRealm->getGlobalEnv();
                    if ($env->has('Number')) {
                        $numCtor = $env->get('Number');
                        if ($numCtor instanceof JsObject) {
                            $p = $numCtor->get('prototype');
                            if ($p instanceof JsObject) {
                                $realmNumberProto = $p;
                            }
                        }
                    }
                    if ($env->has('String')) {
                        $strCtor = $env->get('String');
                        if ($strCtor instanceof JsObject) {
                            $p = $strCtor->get('prototype');
                            if ($p instanceof JsObject) {
                                $realmStringProto = $p;
                            }
                        }
                    }
                    if ($env->has('Boolean')) {
                        $boolCtor = $env->get('Boolean');
                        if ($boolCtor instanceof JsObject) {
                            $p = $boolCtor->get('prototype');
                            if ($p instanceof JsObject) {
                                $realmBoolProto = $p;
                            }
                        }
                    }
                }
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
                        if ($el instanceof JsObject) {
                            $boxed = $el;
                        } elseif ($el instanceof JsNumber && $realmNumberProto !== null) {
                            $boxed = new JsObject($realmNumberProto);
                            $boxed->defineOwnProperty(
                                '[[PrimitiveValue]]',
                                \Phasis\Object\PropertyDescriptor::data($el, false, false, false),
                            );
                        } elseif ($el instanceof JsString && $realmStringProto !== null) {
                            $boxed = new JsObject($realmStringProto);
                            $boxed->defineOwnProperty(
                                '[[PrimitiveValue]]',
                                \Phasis\Object\PropertyDescriptor::data($el, false, false, false),
                            );
                        } elseif ($el instanceof JsBoolean && $realmBoolProto !== null) {
                            $boxed = new JsObject($realmBoolProto);
                            $boxed->defineOwnProperty(
                                '[[PrimitiveValue]]',
                                \Phasis\Object\PropertyDescriptor::data($el, false, false, false),
                            );
                        } else {
                            $boxed = TypeConversion::toObject($el);
                        }
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

        // Use an anonymous class holder so closures observe the assignment
        // after $constructor is created. (A &$ctorRef capture confuses
        // PHPStan's flow analysis, which treats the captured null as
        // never-updated.)
        $ctorBox = new class {
            public ?JsFunction $ref = null;
        };
        $constructor = JsFunction::fromCallable(
            $typeName,
            function (JsValue $this_, array $args) use ($typeName, $bpe, $proto, $ctorBox): JsValue {
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
                $nt = ($ntDesc->value instanceof JsFunction)
                    ? $ntDesc->value
                    : null;
                $defaultProto = $proto;
                $protoResolver = static function () use ($nt, $defaultProto, $ctorBox): JsObject {
                    $ctorRef = $ctorBox->ref;
                    if ($nt !== null && $ctorRef instanceof JsFunction && $nt !== $ctorRef) {
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
        $ctorBox->ref = $constructor;
        $constructor->setConstructable();

        // Each subtype constructor's [[Prototype]] is %TypedArray%.
        $constructor->setCustomPrototype($typedArrayIntrinsic);

        // Static property: BYTES_PER_ELEMENT.
        $constructor->defineOwnProperty(
            'BYTES_PER_ELEMENT',
            PropertyDescriptor::data(JsNumber::of((float) $bpe), false, false, false),
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
            PropertyDescriptor::data(JsNumber::of((float) $bpe), false, false, false),
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

                // ToObject throws on null/undefined per spec; the source must
                // be coerceable before any iteration begins.
                if ($source instanceof JsUndefined || $source instanceof \Phasis\Value\JsNull) {
                    throw new TypeError(
                        'TypedArray.from: source is null or undefined'
                    );
                }

                // Per spec: GetMethod(source, @@iterator). If non-undefined,
                // iterator path; else ArrayLike path with construct-before-read.
                $iterSym = SymbolConstructor::iterator();
                $iterMethod = null;
                if ($source instanceof JsObject) {
                    $iterMethod = $source->getBySymbol($iterSym);
                } elseif ($source instanceof \Phasis\Value\JsString) {
                    $strProto = \Phasis\Value\JsString::getStringPrototype();
                    $iterMethod = $strProto !== null ? $strProto->getBySymbol($iterSym) : null;
                }
                if ($iterMethod instanceof \Phasis\Value\JsHTMLDDA) {
                    throw new TypeError('TypedArray.from: iterator is not an object');
                }
                // GetMethod: null/undefined → undefined (skip iterator path);
                // any other non-callable → TypeError.
                if (
                    $iterMethod !== null
                    && !$iterMethod instanceof JsFunction
                    && !$iterMethod instanceof JsUndefined
                    && !$iterMethod instanceof \Phasis\Value\JsNull
                ) {
                    throw new TypeError(
                        'TypedArray.from: @@iterator is not callable'
                    );
                }

                if ($iterMethod instanceof JsFunction) {
                    // Iterator path: collect → construct(len) → set.
                    $elements = self::consumeIterator($iterMethod, $source);
                    $len = count($elements);
                    $targetObj = $this_->construct([JsNumber::of((float) $len)]);
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
                    for ($k = 0; $k < $len; $k++) {
                        $kValue = $elements[$k];
                        if ($hasMapFn) {
                            /** @var JsFunction $mapFn */
                            $kValue = $mapFn->call($thisArg, [$kValue, JsNumber::of((float) $k)]);
                        }
                        $targetObj->set((string) $k, $kValue);
                    }
                    return $targetObj;
                }

                // ArrayLike path: get length → construct → loop(get + set).
                if ($source instanceof JsTypedArray) {
                    $len = $source->getLength();
                } elseif ($source instanceof JsArray) {
                    $len = $source->getLength();
                } elseif ($source instanceof JsObject) {
                    $len = (int) TypeConversion::toNumber($source->get('length'));
                } elseif ($source instanceof \Phasis\Value\JsString) {
                    // ArrayLike fallback: UTF-16 code unit count.
                    $u16Source = \Phasis\Value\JsString::utf8ToUtf16LE($source->value);
                    $len = (int) (strlen($u16Source) / 2);
                } else {
                    // Number/Boolean/Symbol after ToObject have no length.
                    $len = 0;
                }
                if ($len < 0) {
                    $len = 0;
                }
                $targetObj = $this_->construct([JsNumber::of((float) $len)]);
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
                for ($k = 0; $k < $len; $k++) {
                    if ($source instanceof JsTypedArray) {
                        $kValue = $source->getIndex($k);
                    } elseif ($source instanceof \Phasis\Value\JsString) {
                        $cu = ord($u16Source[$k * 2]) | (ord($u16Source[$k * 2 + 1]) << 8);
                        $kValue = new \Phasis\Value\JsString(
                            \Phasis\Value\JsString::utf16CodeUnitToUtf8($cu),
                        );
                    } elseif ($source instanceof JsObject) {
                        $kValue = $source->get((string) $k);
                    } else {
                        $kValue = JsUndefined::instance();
                    }
                    if ($hasMapFn) {
                        /** @var JsFunction $mapFn */
                        $kValue = $mapFn->call($thisArg, [$kValue, JsNumber::of((float) $k)]);
                    }
                    $targetObj->set((string) $k, $kValue);
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
                $newObj = $this_->construct([JsNumber::of((float) $len)]);
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
     *
     * @param array<mixed> $args
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
            // Per spec §23.2.5.1 InitializeTypedArrayFromArrayBuffer:
            // AllocateTypedArray (which reads `constructor.prototype` via
            // OrdinaryCreateFromConstructor) runs FIRST, before ToIndex on
            // byteOffset / length. Force the proto getter to fire now —
            // staging/sm/TypedArray/constructor-buffer-sequence asserts
            // that a throwing prototype getter wins over a poisoned-value
            // byteOffset or a detached-buffer check.
            $resolvedProto = $getProto();

            // Per spec InitializeTypedArrayFromArrayBuffer §23.2.5.1.2 step ordering:
            //  step 2: offset = ToIndex(byteOffset)
            //  step 3: if offset modulo elementSize ≠ 0 → RangeError
            //  step 4: if length is not undefined, newLength = ToIndex(length)
            //  step 5: if IsDetachedBuffer(buffer) → TypeError
            //  step 6: range checks (offset + newLength * bpe vs bufLen)
            //  step 11.a: if bufLen % bpe ≠ 0 → RangeError (length omitted, fixed buffer)

            // step 2: byteOffset = ToIndex(byteOffset).
            $offsetArg = $args[1] ?? JsUndefined::instance();
            $byteOffset = TypeConversion::toIndex($offsetArg);

            // step 3: offset-divisibility check before IsDetached.
            if ($byteOffset % $bpe !== 0) {
                throw new RangeError("Start offset of {$typeName} should be a multiple of {$bpe}");
            }

            $lengthExplicit = isset($args[2]) && !$args[2] instanceof JsUndefined;

            // step 4: ToIndex(length) before IsDetached. The valueOf()
            // during ToIndex may detach the buffer; we re-check immediately
            // after.
            $length = null;
            if ($lengthExplicit) {
                $length = TypeConversion::toIndex($args[2]);
            }

            // step 5: IsDetachedBuffer check, after both coercions.
            if ($arg0->isDetached()) {
                throw new TypeError(
                    'Cannot construct a typed array on a detached ArrayBuffer'
                );
            }

            $bufLen = $arg0->getByteLength();
            $isResizable = $arg0->isResizable();

            if ($lengthExplicit) {
                $newByteLength = $length * $bpe;
                if ($byteOffset + $newByteLength > $bufLen) {
                    throw new RangeError('Invalid typed array length');
                }
            } else {
                if ($byteOffset > $bufLen) {
                    throw new RangeError("Start offset of {$typeName} is outside the bounds of the buffer");
                }
                if ($isResizable) {
                    // Length-tracking view on a resizable buffer: per spec the
                    // bufferByteLength % elementSize check is skipped; the
                    // current length is computed dynamically from the buffer's
                    // current byte length.
                    $length = intdiv($bufLen - $byteOffset, $bpe);
                } else {
                    $remaining = $bufLen - $byteOffset;
                    if ($remaining % $bpe !== 0) {
                        throw new RangeError("Byte length of {$typeName} should be a multiple of {$bpe}");
                    }
                    $length = (int) ($remaining / $bpe);
                }
            }

            $ta = new JsTypedArray($typeName, $arg0, $byteOffset, $length, $getProto());
            if ($isResizable && !$lengthExplicit) {
                $ta->setAutoLength(true);
            }
            return $ta;
        }

        // new TypedArray(typedArray): copy elements.
        if ($arg0 instanceof JsTypedArray) {
            // Per spec InitializeTypedArrayFromTypedArray, validate the
            // source: throw TypeError if its buffer is detached or the
            // view is now out of bounds.
            if ($arg0->getBuffer()->isDetached()) {
                throw new TypeError(
                    'Cannot construct typed array from a detached buffer'
                );
            }
            if ($arg0->isOutOfBounds()) {
                throw new TypeError(
                    'Cannot construct typed array from an out-of-bounds source'
                );
            }
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

    /**
     * Consume an iterator into a list of values.
     *
     * @return list<JsValue>
     */
    private static function consumeIterator(JsFunction $iterMethod, JsValue $obj): array
    {
        $iterator = $iterMethod->call($obj, []);
        // Per spec GetIteratorFromMethod step 4: if iterator is not an
        // object, throw TypeError.
        if (!$iterator instanceof JsObject) {
            throw new TypeError('Symbol.iterator method must return an object');
        }

        $nextMethod = $iterator->get('next');
        if (!$nextMethod instanceof JsFunction) {
            throw new TypeError('Iterator next is not a function');
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
     * ValidateTypedArray per spec: throws TypeError if buffer is detached.
     */
    private static function validateTypedArray(JsTypedArray $ta): void
    {
        $ta->validateNotDetached();
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
            $constructArgs = $speciesArgs ?? [JsNumber::of((float) $length)];
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
