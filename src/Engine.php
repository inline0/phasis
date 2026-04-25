<?php

declare(strict_types=1);

namespace PhpJs;

use PhpJs\BuiltIn\ConsoleObject;
use PhpJs\BuiltIn\GlobalObject;
use PhpJs\Interop\PhpToJs;
use PhpJs\Parser\Parser;
use PhpJs\Runtime\CallStack;
use PhpJs\Runtime\Environment;
use PhpJs\Runtime\Interpreter;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class Engine
{
    private static ?Interpreter $currentInterpreter = null;

    private Environment $globalEnv;
    private Interpreter $interpreter;
    private ConsoleObject $console;
    private CallStack $callStack;

    public function __construct()
    {
        // Clear any cached intrinsic prototypes from prior Engine instances
        // so each realm gets its own wired object graph.
        self::resetStaticIntrinsics();
        $this->callStack = new CallStack();
        $this->globalEnv = new Environment();
        $this->console = new ConsoleObject();
        $this->interpreter = new Interpreter($this->globalEnv, $this->callStack);
        self::$currentInterpreter = $this->interpreter;

        $this->installBuiltins();

        // Global 'this' should be the global object
        $objProto = $this->globalEnv->has('__ObjectPrototype__')
            ? $this->globalEnv->get('__ObjectPrototype__')
            : null;
        $globalObj = new \PhpJs\Value\JsObject(
            $objProto instanceof \PhpJs\Value\JsObject ? $objProto : null,
        );

        // Install non-writable, non-configurable global value properties on the global object
        // per ES spec 19.1 (Value Properties of the Global Object).
        $globalObj->defineOwnProperty('Infinity', \PhpJs\Object\PropertyDescriptor::data(
            new \PhpJs\Value\JsNumber(INF),
            false,
            false,
            false,
        ));
        $globalObj->defineOwnProperty('NaN', \PhpJs\Object\PropertyDescriptor::data(
            new \PhpJs\Value\JsNumber(NAN),
            false,
            false,
            false,
        ));
        $globalObj->defineOwnProperty('undefined', \PhpJs\Object\PropertyDescriptor::data(
            \PhpJs\Value\JsUndefined::instance(),
            false,
            false,
            false,
        ));

        // Sync all environment bindings onto the global object so that
        // Object.getOwnPropertyDescriptor(this, "parseInt") etc. work.
        // Per ES spec, built-in function properties are writable, non-enumerable, configurable.
        $skipKeys = ['this', 'globalThis', 'Infinity', 'NaN', 'undefined',
            '__ObjectPrototype__', '__FunctionPrototype__', '__ArrayPrototype__',
            '__StringPrototype__', '__NumberPrototype__', '__ErrorPrototype__',
            '__TypeErrorPrototype__', '__RangeErrorPrototype__',
            '__ReferenceErrorPrototype__', '__SyntaxErrorPrototype__',
            '__URIErrorPrototype__', '__EvalErrorPrototype__',
            '__RegExpPrototype__', '__DatePrototype__',
            '__SymbolPrototype__', '__MapPrototype__', '__SetPrototype__',
        ];
        foreach ($this->globalEnv->allBindings() as $name => $value) {
            if (in_array($name, $skipKeys, true)) {
                continue;
            }
            if (str_starts_with($name, '__') && str_ends_with($name, 'Prototype__')) {
                continue;
            }
            if (!$globalObj->hasOwnProperty($name)) {
                $globalObj->defineOwnProperty(
                    $name,
                    \PhpJs\Object\PropertyDescriptor::data($value, true, false, true),
                );
            }
        }

        $this->globalEnv->defineVar('this', $globalObj);
        $this->globalEnv->defineVar('globalThis', $globalObj);

        // Link the global environment to the global object so that
        // top-level var declarations and assignments create properties
        // on globalThis (per ES spec 9.1.1.1 Global Environment Records).
        $this->globalEnv->linkGlobalObject($globalObj);

        // Per spec §19.1: NaN, Infinity, undefined are non-writable
        // on the global object. Must be set AFTER linkGlobalObject.
        $ro = static fn ($v) => \PhpJs\Object\PropertyDescriptor::data(
            $v,
            false,
            false,
            false,
        );
        $globalObj->defineOwnProperty('NaN', $ro(new \PhpJs\Value\JsNumber(NAN)));
        $globalObj->defineOwnProperty('Infinity', $ro(new \PhpJs\Value\JsNumber(INF)));
        $globalObj->defineOwnProperty('undefined', $ro(\PhpJs\Value\JsUndefined::instance()));

        // globalThis: writable, non-enumerable, configurable per spec.
        // Set directly on the global object since the Environment filter
        // skips 'globalThis' for the linked object sync.
        $globalObj->defineOwnProperty(
            'globalThis',
            \PhpJs\Object\PropertyDescriptor::data($globalObj, true, false, true),
        );
    }

    private function installBuiltins(): void
    {
        // Reset global prototype before installing builtins so that objects
        // created during GlobalObject::install (e.g. Boolean.prototype) get a
        // null [[Prototype]]. The wiring step after ObjectConstructor::install
        // will set the correct prototype chain. Without this reset, a stale
        // globalPrototype from a previous Engine instance would leak in.
        JsObject::resetGlobalPrototype();

        GlobalObject::install($this->globalEnv);
        \PhpJs\BuiltIn\ObjectConstructor::install($this->globalEnv);

        // Wire prototype objects created in GlobalObject::install to Object.prototype now
        // that both exist. ObjectConstructor::install set the global prototype, so any
        // new JsObject() will inherit it — but objects created earlier need explicit wiring.
        if ($this->globalEnv->has('__ObjectPrototype__')) {
            $objProto = $this->globalEnv->get('__ObjectPrototype__');
            if ($objProto instanceof JsObject) {
                // Function.prototype -> Object.prototype
                if ($this->globalEnv->has('Function')) {
                    $fnCtor = $this->globalEnv->get('Function');
                    if ($fnCtor instanceof JsFunction) {
                        $fnProto = $fnCtor->get('prototype');
                        if ($fnProto instanceof JsObject && $fnProto->getPrototype() === null) {
                            $fnProto->setPrototype($objProto);
                        }
                    }
                }
                // Boolean.prototype -> Object.prototype
                if ($this->globalEnv->has('Boolean')) {
                    $boolCtor = $this->globalEnv->get('Boolean');
                    if ($boolCtor instanceof JsFunction) {
                        $boolProto = $boolCtor->get('prototype');
                        if ($boolProto instanceof JsObject && $boolProto->getPrototype() === null) {
                            $boolProto->setPrototype($objProto);
                        }
                    }
                }
                // %IteratorPrototype% -> Object.prototype (per spec 27.1.2)
                if ($this->globalEnv->has('__IteratorPrototype__')) {
                    $iterProto = $this->globalEnv->get('__IteratorPrototype__');
                    if ($iterProto instanceof JsObject && $iterProto->getPrototype() === null) {
                        $iterProto->setPrototype($objProto);
                    }
                }
                // %AsyncIteratorPrototype% -> Object.prototype (per spec 27.1.3)
                if ($this->globalEnv->has('__AsyncIteratorPrototype__')) {
                    $aiProto = $this->globalEnv->get('__AsyncIteratorPrototype__');
                    if ($aiProto instanceof JsObject && $aiProto->getPrototype() === null) {
                        $aiProto->setPrototype($objProto);
                    }
                }
            }
        }
        \PhpJs\BuiltIn\ErrorConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\NumberConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\ArrayConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\StringPrototype::install($this->globalEnv);
        \PhpJs\BuiltIn\MathObject::install($this->globalEnv);
        \PhpJs\BuiltIn\JsonObject::install($this->globalEnv);
        \PhpJs\BuiltIn\SymbolConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\IteratorConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\MapConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\SetConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\TypedArrayConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\AtomicsObject::install($this->globalEnv);
        \PhpJs\BuiltIn\PromiseConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\ProxyConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\ReflectObject::install($this->globalEnv);
        $this->globalEnv->defineVar('console', $this->console->create());

        \PhpJs\BuiltIn\IntlObject::install($this->globalEnv);

        \PhpJs\BuiltIn\WeakMapConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\WeakSetConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\WeakRefConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\FinalizationRegistryConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\DisposableStackConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\ShadowRealmConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\TemporalObject::install($this->globalEnv);

        // BigInt constructor: callable but not intended for `new`.
        // Per spec 21.2.1, when called with `new`, throws TypeError.
        // When called as function, converts value to BigInt.
        $bigIntFn = \PhpJs\Value\JsFunction::fromCallable(
            'BigInt',
            function (\PhpJs\Value\JsValue $this_, array $args): \PhpJs\Value\JsValue {
                if ($this_ instanceof \PhpJs\Value\JsObject && $this_->has('[[NewTarget]]')) {
                    throw new \PhpJs\Exceptions\TypeError('BigInt is not a constructor');
                }
                $val = $args[0] ?? \PhpJs\Value\JsUndefined::instance();

                // Per spec BigInt(value) step 2: prim = ToPrimitive(value, number).
                $prim = \PhpJs\Spec\TypeConversion::toPrimitive($val, 'number');

                // Step 3: If Type(prim) is Number, return ? NumberToBigInt(prim).
                if ($prim instanceof \PhpJs\Value\JsNumber) {
                    $n = $prim->value;
                    if (!is_finite($n) || floor($n) !== $n) {
                        throw new \PhpJs\Exceptions\RangeError(
                            'The number ' . $n . ' cannot be converted to a BigInt'
                            . ' because it is not an integer'
                        );
                    }
                    // Use string representation for large integers beyond PHP_INT_MAX.
                    if ($n > PHP_INT_MAX || $n < PHP_INT_MIN) {
                        return new \PhpJs\Value\JsBigInt(number_format($n, 0, '.', ''));
                    }
                    return new \PhpJs\Value\JsBigInt((string) (int) $n);
                }

                // Step 4: Otherwise, return ? ToBigInt(prim).
                return \PhpJs\Spec\TypeConversion::toBigInt($prim);
            },
        );
        // BigInt has [[Construct]] per spec (just throws TypeError when called as constructor).
        $bigIntFn->setConstructable();

        // BigInt.length = 1 per spec (writable: false, enumerable: false, configurable: true).
        $bigIntFn->defineOwnProperty('length', new \PhpJs\Object\PropertyDescriptor(
            value: new \PhpJs\Value\JsNumber(1.0),
            writable: false,
            enumerable: false,
            configurable: true,
        ));

        // BigInt.prototype: allows attaching methods to all BigInt primitives.
        // Per spec 21.2.2, BigInt.prototype is an ordinary object (not a BigInt value).
        $bigIntProto = new \PhpJs\Value\JsObject();

        // BigInt.prototype.toString([radix])
        $bigIntToStr = \PhpJs\Value\JsFunction::fromCallable(
            'toString',
            function (\PhpJs\Value\JsValue $this_, array $args): \PhpJs\Value\JsValue {
                $bigint = $this_ instanceof \PhpJs\Value\JsBigInt ? $this_ : null;
                if ($bigint === null && $this_ instanceof \PhpJs\Value\JsObject) {
                    // BigInt wrapper object.
                    $v = $this_->get('[[BigIntData]]');
                    $bigint = $v instanceof \PhpJs\Value\JsBigInt ? $v : null;
                }
                if ($bigint === null) {
                    throw new \PhpJs\Exceptions\TypeError('BigInt.prototype.toString called on non-BigInt');
                }
                $radix = 10;
                if (isset($args[0]) && !($args[0] instanceof \PhpJs\Value\JsUndefined)) {
                    $radix = (int) $args[0]->toNumber();
                    if ($radix < 2 || $radix > 36) {
                        throw new \PhpJs\Exceptions\RangeError('toString() radix must be between 2 and 36');
                    }
                }
                if ($radix === 10) {
                    return new \PhpJs\Value\JsString($bigint->value);
                }
                // For non-decimal radix, convert the decimal string to the target base.
                $negative = isset($bigint->value[0]) && $bigint->value[0] === '-';
                $abs = $negative ? substr($bigint->value, 1) : $bigint->value;
                if ($abs === '0' || $abs === '') {
                    return new \PhpJs\Value\JsString('0');
                }
                $digitChars = '0123456789abcdefghijklmnopqrstuvwxyz';
                $result = '';
                $radixStr = (string) $radix;
                $n = $abs;
                // Fast path for values that fit in PHP int.
                if (strlen($n) < 18) {
                    $nInt = (int) $n;
                    while ($nInt > 0) {
                        $result = $digitChars[$nInt % $radix] . $result;
                        $nInt = intdiv($nInt, $radix);
                    }
                } else {
                    // Use pure-PHP string division for large values.
                    while ($n !== '0') {
                        $remInt = 0;
                        $q = '';
                        for ($di = 0; $di < strlen($n); $di++) {
                            $cur = $remInt * 10 + (int) $n[$di];
                            $q .= (string) intdiv($cur, $radix);
                            $remInt = $cur % $radix;
                        }
                        $result = $digitChars[$remInt] . $result;
                        $n = ltrim($q, '0') ?: '0';
                    }
                }
                return new \PhpJs\Value\JsString($negative ? '-' . $result : $result);
            },
        );
        $bigIntProto->defineOwnProperty(
            'toString',
            \PhpJs\Object\PropertyDescriptor::data($bigIntToStr, true, false, true),
        );

        // BigInt.prototype.valueOf()
        $bigIntValOf = \PhpJs\Value\JsFunction::fromCallable(
            'valueOf',
            function (\PhpJs\Value\JsValue $this_, array $args): \PhpJs\Value\JsValue {
                if ($this_ instanceof \PhpJs\Value\JsBigInt) {
                    return $this_;
                }
                if ($this_ instanceof \PhpJs\Value\JsObject) {
                    $v = $this_->get('[[BigIntData]]');
                    if ($v instanceof \PhpJs\Value\JsBigInt) {
                        return $v;
                    }
                }
                throw new \PhpJs\Exceptions\TypeError('BigInt.prototype.valueOf called on non-BigInt');
            },
        );
        $bigIntProto->defineOwnProperty(
            'valueOf',
            \PhpJs\Object\PropertyDescriptor::data($bigIntValOf, true, false, true),
        );

        // BigInt.prototype.toLocaleString() - same as toString() per spec.
        $bigIntLocale = \PhpJs\Value\JsFunction::fromCallable(
            'toLocaleString',
            function (\PhpJs\Value\JsValue $this_, array $args): \PhpJs\Value\JsValue {
                if ($this_ instanceof \PhpJs\Value\JsBigInt) {
                    return new \PhpJs\Value\JsString($this_->value);
                }
                throw new \PhpJs\Exceptions\TypeError('BigInt.prototype.toLocaleString called on non-BigInt');
            },
        );
        $bigIntProto->defineOwnProperty(
            'toLocaleString',
            \PhpJs\Object\PropertyDescriptor::data($bigIntLocale, true, false, true),
        );

        // BigInt.prototype[Symbol.toStringTag] = "BigInt"
        $bigIntProto->definePropertyBySymbol(
            \PhpJs\BuiltIn\SymbolConstructor::toStringTag(),
            new \PhpJs\Object\PropertyDescriptor(
                value: new \PhpJs\Value\JsString('BigInt'),
                writable: false,
                enumerable: false,
                configurable: true,
            )
        );

        // BigInt.prototype.constructor = BigInt
        $bigIntProto->defineOwnProperty('constructor', \PhpJs\Object\PropertyDescriptor::data(
            $bigIntFn,
            true,
            false,
            true
        ));

        $bigIntFn->defineOwnProperty('prototype', new \PhpJs\Object\PropertyDescriptor(
            value: $bigIntProto,
            writable: false,
            enumerable: false,
            configurable: false,
        ));

        // Pure-PHP helpers for asIntN/asUintN.
        // Compute 2^n as a decimal string.
        $pow2str = static function (int $n): string {
            if ($n === 0) {
                return '1';
            }
            // Start with 1 and double n times.
            $result = '1';
            for ($i = 0; $i < $n; $i++) {
                // Double the string: multiply each digit by 2 with carry.
                $carry = 0;
                $out = '';
                for ($j = strlen($result) - 1; $j >= 0; $j--) {
                    $d = (int) $result[$j] * 2 + $carry;
                    $carry = intdiv($d, 10);
                    $out = ($d % 10) . $out;
                }
                if ($carry) {
                    $out = $carry . $out;
                }
                $result = $out;
            }
            return $result;
        };
        // Compare two non-negative decimal strings. Returns -1, 0, 1.
        $bigCmpUns = static function (string $a, string $b): int {
            $la = strlen($a);
            $lb = strlen($b);
            if ($la !== $lb) {
                return $la < $lb ? -1 : 1;
            }
            return strcmp($a, $b) <=> 0;
        };
        // Unsigned subtract: a >= b assumed.
        $bigSubUns = static function (string $a, string $b) use (&$bigSubUns): string {
            $result = '';
            $borrow = 0;
            $i = strlen($a) - 1;
            $j = strlen($b) - 1;
            while ($i >= 0) {
                $diff = (int) $a[$i--] - ($j >= 0 ? (int) $b[$j--] : 0) - $borrow;
                if ($diff < 0) {
                    $diff += 10;
                    $borrow = 1;
                } else {
                    $borrow = 0;
                }
                $result = $diff . $result;
            }
            return ltrim($result, '0') ?: '0';
        };
        // Unsigned string division: returns remainder when dividing $a by $d (small int).
        $bigModSmall = static function (string $a, int $d): int {
            $rem = 0;
            for ($i = 0; $i < strlen($a); $i++) {
                $rem = ($rem * 10 + (int) $a[$i]) % $d;
            }
            return $rem;
        };
        // Compute bigint mod 2^width (result is non-negative decimal string).
        // We do this by computing the binary representation of |bigint| mod 2^width,
        // then adjusting for sign.
        $bigUintN = static function (
            string $val,
            int $width,
        ) use (
            $pow2str,
            $bigCmpUns,
            $bigSubUns,
            $bigModSmall,
        ): string {
            if ($width === 0) {
                return '0';
            }
            $neg = isset($val[0]) && $val[0] === '-';
            $abs = ltrim($val, '-');
            if ($abs === '0') {
                return '0';
            }
            // pow2 = 2^width
            $pow2 = $pow2str($width);
            // Compute abs mod pow2 using repeated division by 2 (get binary bits), then reconstruct.
            // Actually easier: compute $abs % $pow2 using digit-by-digit mod.
            // Use fast path if pow2 fits in PHP int.
            if ($width <= 62) {
                $mask = PHP_INT_MAX;
                if ($width < 63) {
                    $mask = (1 << $width) - 1;
                }
                // Compute abs mod 2^width.
                $rem = 0;
                for ($i = 0; $i < strlen($abs); $i++) {
                    $rem = (($rem * 10) + (int) $abs[$i]) & $mask;
                }
                $rem = $rem & $mask;
                if ($neg && $rem !== 0) {
                    $rem = (1 << $width) - $rem;
                }
                return (string) $rem;
            }
            // Large width: use string-based computation.
            // Compute abs mod pow2 using long division.
            // abs mod pow2 = abs - pow2 * floor(abs / pow2)
            // We can compute this as: take last width bits of abs in binary.
            // 1. Convert abs to binary string by repeated halving.
            $bin = '';
            $n = $abs;
            while ($n !== '0') {
                // Last bit = n % 2
                $rem2 = 0;
                $q = '';
                for ($i = 0; $i < strlen($n); $i++) {
                    $cur = $rem2 * 10 + (int) $n[$i];
                    $q .= (string) intdiv($cur, 2);
                    $rem2 = $cur % 2;
                }
                $bin = $rem2 . $bin;
                $n = ltrim($q, '0') ?: '0';
            }
            // Take last $width bits.
            if (strlen($bin) <= $width) {
                $bits = str_pad($bin, $width, '0', STR_PAD_LEFT);
            } else {
                $bits = substr($bin, -$width);
            }
            // If negative, compute two's complement: flip bits and add 1.
            if ($neg) {
                $flipped = '';
                for ($i = 0; $i < strlen($bits); $i++) {
                    $flipped .= $bits[$i] === '0' ? '1' : '0';
                }
                // Add 1 to flipped.
                $carry = 1;
                $result = '';
                for ($i = strlen($flipped) - 1; $i >= 0; $i--) {
                    $d = (int) $flipped[$i] + $carry;
                    $result = ($d % 2) . $result;
                    $carry = intdiv($d, 2);
                }
                // Discard overflow carry (wraps around).
                $bits = $result;
            }
            // Convert bits back to decimal.
            $dec = '0';
            for ($i = 0; $i < strlen($bits); $i++) {
                // dec = dec * 2 + bit
                $carry2 = (int) $bits[$i];
                $out2 = '';
                for ($j = strlen($dec) - 1; $j >= 0; $j--) {
                    $d = (int) $dec[$j] * 2 + $carry2;
                    $carry2 = intdiv($d, 10);
                    $out2 = ($d % 10) . $out2;
                }
                while ($carry2 > 0) {
                    $out2 = ($carry2 % 10) . $out2;
                    $carry2 = intdiv($carry2, 10);
                }
                $dec = $out2 !== '' ? $out2 : '0';
            }
            return $dec;
        };

        // BigInt.asUintN(width, bigint): modulo 2^width, unsigned.
        $asUintNFn = \PhpJs\Value\JsFunction::fromCallable(
            'asUintN',
            function (\PhpJs\Value\JsValue $this_, array $args) use ($bigUintN): \PhpJs\Value\JsValue {
                $width = isset($args[0]) ? \PhpJs\Spec\TypeConversion::toIndex($args[0]) : 0;
                $bigint = \PhpJs\Spec\TypeConversion::toBigInt(
                    $args[1] ?? \PhpJs\Value\JsUndefined::instance(),
                );
                $mod = $bigUintN($bigint->value, $width);
                return new \PhpJs\Value\JsBigInt($mod);
            },
            2,
        );
        $bigIntFn->defineOwnProperty(
            'asUintN',
            \PhpJs\Object\PropertyDescriptor::data($asUintNFn, true, false, true),
        );

        // BigInt.asIntN(width, bigint): modulo 2^width, signed.
        $asIntNCb = function (
            \PhpJs\Value\JsValue $this_,
            array $args,
        ) use (
            $bigUintN,
            $pow2str,
            $bigCmpUns,
            $bigSubUns,
        ): \PhpJs\Value\JsValue {
            $width = isset($args[0]) ? \PhpJs\Spec\TypeConversion::toIndex($args[0]) : 0;
            $bigint = \PhpJs\Spec\TypeConversion::toBigInt(
                $args[1] ?? \PhpJs\Value\JsUndefined::instance(),
            );
            if ($width === 0) {
                return new \PhpJs\Value\JsBigInt('0');
            }
            $mod = $bigUintN($bigint->value, $width);
            // If mod >= 2^(width-1), result = mod - 2^width (i.e. negative).
            $half = $pow2str($width - 1);
            if ($bigCmpUns($mod, $half) >= 0) {
                $pow2 = $pow2str($width);
                $diff = $bigSubUns($pow2, $mod);
                return new \PhpJs\Value\JsBigInt($diff === '0' ? '0' : '-' . $diff);
            }
            return new \PhpJs\Value\JsBigInt($mod);
        };
        $asIntNFn = \PhpJs\Value\JsFunction::fromCallable('asIntN', $asIntNCb, 2);
        $bigIntFn->defineOwnProperty(
            'asIntN',
            \PhpJs\Object\PropertyDescriptor::data($asIntNFn, true, false, true),
        );

        // Store the prototype so JsBigInt primitive lookups can find it.
        \PhpJs\Value\JsBigInt::setPrototype($bigIntProto);

        $this->globalEnv->defineVar('BigInt', $bigIntFn);

        \PhpJs\BuiltIn\DateConstructor::install($this->globalEnv);

        $interp = $this->interpreter;
        $globalEnv = $this->globalEnv;
        $regExpCb = function (
            \PhpJs\Value\JsValue $this_,
            array $args,
        ) use (
            $interp,
            $globalEnv
): \PhpJs\Value\JsValue {
            $arg0 = $args[0] ?? \PhpJs\Value\JsUndefined::instance();
            $arg1 = $args[1] ?? \PhpJs\Value\JsUndefined::instance();

            $calledAsNew = $this_ instanceof \PhpJs\Value\JsObject && $this_->has('[[NewTarget]]');

            // Per spec 22.2.3.1: IsRegExp check using @@match. If matcher is
            // undefined, fall back to whether the argument has the
            // [[RegExpMatcher]] internal slot (we use [[PCREPattern]] for
            // that purpose).
            $patternIsRegExp = false;
            if ($arg0 instanceof \PhpJs\Value\JsObject) {
                $matchSymbol = \PhpJs\BuiltIn\SymbolConstructor::match();
                $matchProp = $arg0->getBySymbol($matchSymbol);
                if ($matchProp instanceof \PhpJs\Value\JsUndefined) {
                    $patternIsRegExp = $arg0->getOwnPropertyDescriptor('[[PCREPattern]]') !== null;
                } else {
                    $patternIsRegExp = \PhpJs\Spec\TypeConversion::toBoolean($matchProp);
                }
            }

            // Spec step 4: When called as function (not new), if pattern is regexp-like
            // with flags undefined and pattern.constructor === RegExp, return pattern as-is.
            if (!$calledAsNew && $patternIsRegExp && $arg1 instanceof \PhpJs\Value\JsUndefined) {
                if ($arg0 instanceof \PhpJs\Value\JsObject) {
                    $patternCtor = $arg0->get('constructor');
                    if ($globalEnv->has('RegExp') && $patternCtor === $globalEnv->get('RegExp')) {
                        return $arg0;
                    }
                }
            }

            // Detect subclass: if [[NewTarget]] is not the base RegExp constructor.
            $isSubclass = false;
            $newTarget = null;
            if ($calledAsNew && $this_ instanceof \PhpJs\Value\JsObject) {
                $ntd = $this_->getOwnPropertyDescriptor("[[NewTarget]]");
                $baseRegExp = $globalEnv->has("RegExp") ? $globalEnv->get("RegExp") : null;
                if ($ntd !== null && $ntd->value !== null && $ntd->value !== $baseRegExp) {
                    $isSubclass = true;
                    $newTarget = $ntd->value;
                }
            }

            // Per spec 22.2.3.1 the order is: RegExpAlloc(newTarget) — which
            // calls GetPrototypeFromConstructor(newTarget) — happens BEFORE
            // any ToString of the arguments. Resolve the subclass prototype
            // up front so a `prototype` accessor on newTarget runs before
            // the flags ToString.
            $subProto = null;
            if ($isSubclass && $newTarget instanceof JsFunction) {
                $maybeProto = $newTarget->get('prototype');
                if ($maybeProto instanceof \PhpJs\Value\JsObject) {
                    $subProto = $maybeProto;
                }
            }

            // Per spec 22.2.3.1 step 4-5: only treat the argument as a
            // RegExp source/flags pair when IsRegExp(pattern) was true. An
            // arbitrary object with `source`/`flags` properties but a falsy
            // @@match must be coerced via ToString instead.
            if ($patternIsRegExp && $arg0 instanceof \PhpJs\Value\JsObject) {
                $pattern = \PhpJs\Spec\TypeConversion::toString($arg0->get('source'));
                // Empty source is stored as (?:) on the object, but we need the raw pattern for PCRE.
                if ($pattern === '(?:)') {
                    $pattern = '';
                }
                $flags = $arg1 instanceof \PhpJs\Value\JsUndefined
                    ? \PhpJs\Spec\TypeConversion::toString($arg0->get('flags'))
                    : \PhpJs\Spec\TypeConversion::toString($arg1);
                $result = $interp->createRegExpFromConstructor($pattern, $flags, $isSubclass);
                if ($subProto !== null) {
                    $result->setPrototype($subProto);
                }
                return $result;
            }

            $pattern = $arg0 instanceof \PhpJs\Value\JsUndefined
                ? ''
                : \PhpJs\Spec\TypeConversion::toString($arg0);
            $flags = $arg1 instanceof \PhpJs\Value\JsUndefined
                ? ''
                : \PhpJs\Spec\TypeConversion::toString($arg1);
            $result = $interp->createRegExpFromConstructor($pattern, $flags, $isSubclass);
            if ($subProto !== null) {
                $result->setPrototype($subProto);
            }
            return $result;
        };
        $this->installStubConstructor('RegExp', $regExpCb, 2);

        // Install Symbol methods on RegExp.prototype.
        /** @var \PhpJs\Value\JsObject $regexpProto */
        $regexpProto = $this->globalEnv->get('__RegExpPrototype__');
        \PhpJs\BuiltIn\RegExpPrototype::install($regexpProto);

        // Annex B: Legacy RegExp static properties.
        $regExpCtor = $this->globalEnv->get('RegExp');
        if ($regExpCtor instanceof JsFunction) {
            $this->installLegacyRegExpStatics($regExpCtor);

            // RegExp[@@species] per spec: accessor property, getter returns `this`.
            $speciesGetter = JsFunction::fromCallable(
                'get [Symbol.species]',
                function (\PhpJs\Value\JsValue $this_): \PhpJs\Value\JsValue {
                    return $this_;
                },
                0,
            );
            $regExpCtor->definePropertyBySymbol(
                \PhpJs\BuiltIn\SymbolConstructor::species(),
                \PhpJs\Object\PropertyDescriptor::accessor(
                    get: $speciesGetter,
                    set: null,
                    enumerable: false,
                    configurable: true,
                ),
            );

            // RegExp.escape(string) per spec proposal.
            \PhpJs\BuiltIn\RegExpEscape::install($regExpCtor);
        }

        // %AsyncFunction% intrinsic: the constructor for async functions.
        // Not exposed as a global, but accessible via (async function(){}).constructor.
        $this->installAsyncFunctionIntrinsic();
    }

    /**
     * Set up the %AsyncFunction% constructor and %AsyncFunction.prototype%.
     * Per spec 25.7: AsyncFunction is not directly exposed as a global property,
     * but is reachable via the constructor property of any async function instance.
     */
    private function installAsyncFunctionIntrinsic(): void
    {
        $fnProto = null;
        if ($this->globalEnv->has('Function')) {
            $fnCtor = $this->globalEnv->get('Function');
            if ($fnCtor instanceof JsFunction) {
                $fnProto = $fnCtor->get('prototype');
            }
        }

        // %AsyncFunction.prototype% is a non-callable ordinary object.
        // Its [[Prototype]] is Function.prototype.
        $asyncFuncProto = new JsObject($fnProto instanceof JsObject ? $fnProto : null);

        // Symbol.toStringTag = "AsyncFunction", non-writable, non-enumerable, configurable.
        $toStringTagSym = \PhpJs\BuiltIn\SymbolConstructor::toStringTag();
        if ($toStringTagSym !== null) {
            $asyncFuncProto->definePropertyBySymbol(
                $toStringTagSym,
                \PhpJs\Object\PropertyDescriptor::data(new JsString('AsyncFunction'), false, false, true),
            );
        }

        // Create the %AsyncFunction% constructor itself.
        // Per spec 25.7.1, AsyncFunction can be called with or without new.
        // It creates a new async function from source text, like Function() for regular functions.
        $interp = $this->interpreter;
        $globalEnv = $this->globalEnv;

        $asyncFuncCtor = JsFunction::fromCallable('AsyncFunction', static function (
            JsValue $this_,
            array $args,
        ) use (
            $interp,
        ): JsValue {
            // Build async function source from arguments, same as Function constructor.
            $bodyArg = count($args) > 0 ? array_pop($args) : JsUndefined::instance();
            $paramParts = [];
            foreach ($args as $a) {
                $paramParts[] = TypeConversion::toString($a);
            }
            $paramStr = implode(',', $paramParts);
            $bodyStr = TypeConversion::toString($bodyArg);
            // Per Function.prototype.toString spec, dynamic-function source is
            // formatted exactly as: `async function anonymous(<params>\n) {\n<body>\n}`.
            $source = "async function anonymous({$paramStr}\n) {\n{$bodyStr}\n}";
            // Parse by wrapping in parentheses so the function expression
            // is the whole program result.
            $parseSource = "(" . $source . ")";
            $parser = new Parser($parseSource);
            $ast = $parser->parse();
            // Per spec 25.7.1 step 29: params for async functions must not
            // contain AwaitExpression. YieldExpression is also rejected since
            // it's never a valid binding initializer.
            \PhpJs\BuiltIn\GlobalObject::rejectYieldAwaitInParamsPublic($ast);

            $fn = $interp->execute($ast);
            if ($fn instanceof JsFunction) {
                $fn->setSourceText($source);
            }
            return $fn;
        }, 1);
        $asyncFuncCtor->setConstructable();

        // %AsyncFunction%.prototype = %AsyncFunction.prototype%
        $asyncFuncCtor->defineOwnProperty('prototype', \PhpJs\Object\PropertyDescriptor::data(
            $asyncFuncProto,
            false,
            false,
            false,
        ));

        // %AsyncFunction.prototype%.constructor = %AsyncFunction%
        $asyncFuncProto->defineOwnProperty('constructor', \PhpJs\Object\PropertyDescriptor::data(
            $asyncFuncCtor,
            true,
            false,
            true,
        ));

        // Per spec, AsyncFunction.__proto__ === Function (the Function constructor).
        if ($this->globalEnv->has('Function')) {
            $fnCtorVal = $this->globalEnv->get('Function');
            if ($fnCtorVal instanceof JsFunction) {
                $asyncFuncCtor->setCustomPrototype($fnCtorVal);
            }
        }

        // Store AsyncFunction.prototype so async function instances can find their constructor.
        JsFunction::setAsyncFunctionPrototype($asyncFuncProto);
    }

    /**
     * Install Annex B legacy static properties on the RegExp constructor.
     * These track the state of the last successful regexp match.
     */
    private function installLegacyRegExpStatics(JsFunction $ctor): void
    {
        $state = [
            'input' => '',
            'lastMatch' => '',
            'lastParen' => '',
            'leftContext' => '',
            'rightContext' => '',
            'groups' => [],
        ];

        $makeGetter = function (string $stateKey) use ($ctor, &$state): JsFunction {
            return JsFunction::fromCallable(
                'get ' . $stateKey,
                function (JsValue $this_) use ($ctor, $stateKey, &$state): JsValue {
                    if ($this_ !== $ctor) {
                        throw new \PhpJs\Exceptions\TypeError(
                            'Method get RegExp.' . $stateKey . ' called on incompatible receiver',
                        );
                    }
                    return new JsString($state[$stateKey]);
                },
                0,
            );
        };

        $makeSetter = function (string $stateKey) use ($ctor, &$state): JsFunction {
            return JsFunction::fromCallable(
                'set ' . $stateKey,
                function (JsValue $this_, array $args) use ($ctor, $stateKey, &$state): JsValue {
                    if ($this_ !== $ctor) {
                        throw new \PhpJs\Exceptions\TypeError(
                            'Method set RegExp.' . $stateKey . ' called on incompatible receiver',
                        );
                    }
                    $state[$stateKey] = isset($args[0])
                        ? TypeConversion::toString($args[0])
                        : '';
                    return JsUndefined::instance();
                },
                1,
            );
        };

        $accessor = function (
            string $prop,
            string $alias,
            string $key,
            bool $hasSetter
        ) use (
            $ctor,
            $makeGetter,
            $makeSetter,
        ): void {
            $getter = $makeGetter($key);
            $setter = $hasSetter ? $makeSetter($key) : null;
            $desc = \PhpJs\Object\PropertyDescriptor::accessor(
                get: $getter,
                set: $setter,
                enumerable: false,
                configurable: true,
            );
            $ctor->defineOwnProperty($prop, $desc);
            $ctor->defineOwnProperty($alias, $desc);
        };

        $accessor('input', '$_', 'input', true);
        $accessor('lastMatch', '$&', 'lastMatch', false);
        $accessor('rightContext', "\$'", 'rightContext', false);
        $accessor('leftContext', '$`', 'leftContext', false);
        $accessor('lastParen', '$+', 'lastParen', false);

        for ($i = 1; $i <= 9; $i++) {
            $idx = $i;
            $getter = JsFunction::fromCallable(
                'get $' . $i,
                function (JsValue $this_) use ($ctor, $idx, &$state): JsValue {
                    if ($this_ !== $ctor) {
                        throw new \PhpJs\Exceptions\TypeError(
                            'Method get RegExp.$' . $idx . ' called on incompatible receiver',
                        );
                    }
                    return new JsString($state['groups'][$idx - 1] ?? '');
                },
                0,
            );
            $ctor->defineOwnProperty(
                '$' . $i,
                \PhpJs\Object\PropertyDescriptor::accessor(
                    get: $getter,
                    set: null,
                    enumerable: false,
                    configurable: true,
                ),
            );
        }
    }

    private function installStubConstructor(string $name, callable $fn, int $length = 0): void
    {
        $constructor = \PhpJs\Value\JsFunction::fromCallable($name, $fn, $length);
        $constructor->setConstructable();
        $proto = new \PhpJs\Value\JsObject();
        // Per spec, constructor is writable, non-enumerable, configurable.
        $proto->defineOwnProperty(
            'constructor',
            \PhpJs\Object\PropertyDescriptor::data($constructor, true, false, true),
        );
        // Per spec, built-in constructor .prototype is non-writable, non-enumerable, non-configurable.
        $constructor->defineOwnProperty('prototype', \PhpJs\Object\PropertyDescriptor::data(
            $proto,
            false,
            false,
            false,
        ));
        $this->globalEnv->defineVar($name, $constructor);
        // Store prototype for internal use (e.g. Interpreter::createRegExpObject).
        $this->globalEnv->defineVar("__{$name}Prototype__", $proto);
    }

    public function eval(string $source): mixed
    {
        $parser = new Parser($source);
        $program = $parser->parse();
        $result = $this->interpreter->execute($program);
        // Drain any microtasks (deferred .then() handlers) scheduled during evaluation.
        \PhpJs\Value\JsPromise::drainMicrotasks();
        return $this->toPhp($result);
    }

    /**
     * Evaluate source text as an ES module.
     *
     * Modules are always strict. Import/export declarations are processed.
     * For file-based imports, the current module path must be set beforehand
     * so that relative specifiers resolve correctly.
     *
     * @param string $source The module source text.
     * @param string|null $path Optional absolute path for this module (used for import resolution).
     */
    public function evalAsModule(string $source, ?string $path = null): mixed
    {
        $modulePath = $path ?? $this->interpreter->getCurrentModulePath() ?? '/virtual-module.mjs';
        $loader = $this->interpreter->getModuleLoader();
        $loader->evaluateModule($modulePath, $source);
        // Drain any microtasks (deferred .then() handlers) scheduled during evaluation.
        \PhpJs\Value\JsPromise::drainMicrotasks();
        // Module namespace objects can be self-referential (export * from self),
        // so converting to PHP would cause infinite recursion. Return null instead.
        // Callers that need the namespace should use the ModuleLoader directly.
        return null;
    }

    public function execFile(string $path): mixed
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("Cannot read file: {$path}");
        }
        $realPath = realpath($path);
        if ($realPath !== false) {
            $this->interpreter->setCurrentModulePath($realPath);
        }
        return $this->eval($source);
    }

    /**
     * Set the current module path for resolving relative import specifiers.
     * Used by the test262 runner to make import() resolve relative to the test file.
     */
    public function setCurrentModulePath(string $path): void
    {
        $this->interpreter->setCurrentModulePath($path);
    }

    public function setGlobal(string $name, mixed $value): void
    {
        $jsValue = PhpToJs::convert($value);
        $this->globalEnv->defineVar($name, $jsValue);
    }

    /**
     * Set a global variable to a raw JsValue without PHP-to-JS conversion.
     * Used by test harness to inject special values like IsHTMLDDA.
     */
    public function setGlobalJsValue(string $name, JsValue $value): void
    {
        $this->globalEnv->defineVar($name, $value);
    }

    public function call(string $name, mixed ...$args): mixed
    {
        $fn = $this->globalEnv->get($name);
        if (!$fn instanceof JsFunction) {
            throw new Exceptions\TypeError("{$name} is not a function");
        }

        $jsArgs = array_map(fn($a) => PhpToJs::convert($a), $args);
        $result = $this->interpreter->callFunction($fn, JsUndefined::instance(), $jsArgs);
        return $this->toPhp($result);
    }

    public function getConsoleOutput(): string
    {
        return $this->console->getOutputString();
    }

    /** @return list<string> */
    public function getConsoleLines(): array
    {
        return $this->console->getOutput();
    }

    public function clearConsole(): void
    {
        $this->console->clear();
    }

    /**
     * Get the interpreter for ShadowRealm evaluation.
     */
    public function getInterpreter(): Interpreter
    {
        return $this->interpreter;
    }

    /**
     * Get the global environment for ShadowRealm evaluation.
     */
    public function getGlobalEnv(): Environment
    {
        return $this->globalEnv;
    }

    public function reset(): void
    {
        \PhpJs\Value\JsPromise::clearMicrotasks();
        self::resetStaticIntrinsics();
        $this->globalEnv = new Environment();
        $this->console = new ConsoleObject();
        $this->callStack = new CallStack();
        $this->interpreter = new Interpreter($this->globalEnv, $this->callStack);
        self::$currentInterpreter = $this->interpreter;

        $this->installBuiltins();
    }

    /**
     * Reset cached intrinsic prototypes shared across BuiltIn classes.
     * Each new Engine instance must rebuild its own prototype graph so
     * test262 cross-realm tests see fresh object identities.
     */
    private static function resetStaticIntrinsics(): void
    {
        \PhpJs\BuiltIn\RegExpPrototype::resetStringIteratorProto();
    }

    /**
     * Create a RegExp object using the current interpreter.
     * Used by String.prototype methods to create RegExp from string arguments per spec.
     */
    public static function createRegExp(string $pattern, string $flags): ?\PhpJs\Value\JsObject
    {
        if (self::$currentInterpreter === null) {
            return null;
        }
        try {
            return self::$currentInterpreter->createRegExpFromConstructor($pattern, $flags);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get the current interpreter for built-in methods.
     */
    public static function getCurrentInterpreter(): ?\PhpJs\Runtime\Interpreter
    {
        return self::$currentInterpreter;
    }

    /**
     * Create a RegExp object, propagating exceptions.
     * Used by RegExp.prototype.compile where errors must be visible to JS code.
     */
    public static function createRegExpOrThrow(string $pattern, string $flags): JsObject
    {
        if (self::$currentInterpreter === null) {
            throw new \PhpJs\Exceptions\TypeError('Cannot compile RegExp');
        }
        return self::$currentInterpreter->createRegExpFromConstructor($pattern, $flags);
    }

    public function setLimit(string $name, int $value): void
    {
        if ($name === 'maxLoopIterations') {
            $this->interpreter->setMaxLoopIterations($value);
        }
    }

    private function toPhp(JsValue $value): mixed
    {
        if ($value instanceof JsUndefined || $value instanceof \PhpJs\Value\JsNull) {
            return null;
        }
        if ($value instanceof \PhpJs\Value\JsBoolean) {
            return $value->toBoolean();
        }
        if ($value instanceof \PhpJs\Value\JsNumber) {
            $num = $value->value;
            if (is_nan($num)) {
                return NAN;
            }
            if ($num === INF) {
                return INF;
            }
            if ($num === -INF) {
                return -INF;
            }
            if ($num == (int) $num && abs($num) < PHP_INT_MAX) {
                return (int) $num;
            }
            return $num;
        }
        if ($value instanceof \PhpJs\Value\JsString) {
            return $value->value;
        }
        if ($value instanceof \PhpJs\Value\JsFunction) {
            return null; // Functions don't convert to PHP values
        }
        if ($value instanceof \PhpJs\Value\JsArray) {
            $result = [];
            $len = $value->getLength();
            for ($i = 0; $i < $len; $i++) {
                $result[] = $this->toPhp($value->get((string) $i));
            }
            return $result;
        }
        if ($value instanceof JsObject) {
            $result = [];
            foreach ($value->getOwnPropertyNames() as $key) {
                $val = $value->get($key);
                if ($val instanceof \PhpJs\Value\JsFunction) {
                    continue; // Skip function properties
                }
                $result[$key] = $this->toPhp($val);
            }
            return $result;
        }
        return null;
    }
}
