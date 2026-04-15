<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Runtime\Environment;
use PhpJs\Runtime\Interpreter;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class GlobalObject
{
    public static function install(Environment $env): void
    {
        $env->defineVar('undefined', JsUndefined::instance());
        $env->defineVar('NaN', new JsNumber(NAN));
        $env->defineVar('Infinity', new JsNumber(INF));

        $env->defineVar('parseInt', JsFunction::fromCallable('parseInt', self::parseInt(), 2));
        $env->defineVar('parseFloat', JsFunction::fromCallable('parseFloat', self::parseFloat(), 1));
        $env->defineVar('isNaN', JsFunction::fromCallable('isNaN', self::isNaN(), 1));
        $env->defineVar('isFinite', JsFunction::fromCallable('isFinite', self::isFinite(), 1));
        $stringFn = JsFunction::fromCallable('String', self::stringConstructor(), 1);
        $stringFn->setConstructable();
        $env->defineVar('String', $stringFn);
        $numberFn = JsFunction::fromCallable('Number', self::numberConstructor(), 1);
        $numberFn->setConstructable();
        $env->defineVar('Number', $numberFn);
        $booleanFn = JsFunction::fromCallable('Boolean', self::booleanConstructor(), 1);
        $booleanFn->setConstructable();
        $boolProto = new \PhpJs\Value\JsObject();
        // Boolean.prototype has [[PrimitiveValue]] = false per spec
        $boolProto->defineOwnProperty('[[PrimitiveValue]]', \PhpJs\Object\PropertyDescriptor::data(new JsBoolean(false), false, false, false));
        $boolProto->defineOwnProperty('constructor', \PhpJs\Object\PropertyDescriptor::data($booleanFn, true, false, true));
        $boolProto->defineOwnProperty('valueOf', \PhpJs\Object\PropertyDescriptor::data(
            JsFunction::fromCallable('valueOf', function (JsValue $this_): JsValue {
                if ($this_ instanceof JsBoolean) {
                    return $this_;
                }
                if ($this_ instanceof \PhpJs\Value\JsObject && $this_->has('[[PrimitiveValue]]')) {
                    $prim = $this_->get('[[PrimitiveValue]]');
                    if ($prim instanceof JsBoolean) {
                        return $prim;
                    }
                }
                throw new \PhpJs\Exceptions\TypeError('Boolean.prototype.valueOf requires a Boolean');
            }, 0),
            true,
            false,
            true,
        ));
        $boolProto->defineOwnProperty('toString', \PhpJs\Object\PropertyDescriptor::data(
            JsFunction::fromCallable('toString', function (JsValue $this_): JsValue {
                if ($this_ instanceof JsBoolean) {
                    return new JsString($this_->toBoolean() ? 'true' : 'false');
                }
                if ($this_ instanceof \PhpJs\Value\JsObject && $this_->has('[[PrimitiveValue]]')) {
                    $prim = $this_->get('[[PrimitiveValue]]');
                    if ($prim instanceof JsBoolean) {
                        return new JsString($prim->toBoolean() ? 'true' : 'false');
                    }
                }
                throw new \PhpJs\Exceptions\TypeError('Boolean.prototype.toString requires a Boolean');
            }, 0),
            true,
            false,
            true,
        ));
        // Boolean.prototype is non-writable, non-configurable per spec
        $booleanFn->defineOwnProperty('prototype', \PhpJs\Object\PropertyDescriptor::data($boolProto, false, false, false));
        $env->defineVar('Boolean', $booleanFn);

        // eval
        $env->defineVar('eval', JsFunction::fromCallable('eval', function (JsValue $this_, array $args) use ($env): JsValue {
            $code = $args[0] ?? JsUndefined::instance();
            if (!$code instanceof JsString) {
                return $code;
            }
            $parser = new \PhpJs\Parser\Parser($code->value);
            $program = $parser->parse();
            $interp = new Interpreter($env);
            return $interp->execute($program);
        }, 1));

        // encodeURIComponent / decodeURIComponent
        $env->defineVar('encodeURIComponent', JsFunction::fromCallable('encodeURIComponent', function (JsValue $this_, array $args): JsValue {
            $str = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            return new JsString(rawurlencode($str));
        }, 1));
        $env->defineVar('decodeURIComponent', JsFunction::fromCallable('decodeURIComponent', function (JsValue $this_, array $args): JsValue {
            $str = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            return new JsString(rawurldecode($str));
        }, 1));
        $env->defineVar('encodeURI', JsFunction::fromCallable('encodeURI', function (JsValue $this_, array $args): JsValue {
            $str = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            return new JsString(str_replace(
                ['%3A', '%2F', '%3F', '%23', '%5B', '%5D', '%40', '%21', '%24', '%26', '%27', '%28', '%29', '%2A', '%2B', '%2C', '%3B', '%3D'],
                [':', '/', '?', '#', '[', ']', '@', '!', '$', '&', "'", '(', ')', '*', '+', ',', ';', '='],
                rawurlencode($str),
            ));
        }, 1));
        $env->defineVar('decodeURI', JsFunction::fromCallable('decodeURI', function (JsValue $this_, array $args): JsValue {
            $str = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            // decodeURI does not decode reserved URI characters.
            $reserved = ['%3A', '%2F', '%3F', '%23', '%5B', '%5D', '%40', '%21', '%24', '%26', '%27', '%28', '%29', '%2A', '%2B', '%2C', '%3B', '%3D'];
            // Temporarily protect reserved sequences so rawurldecode does not touch them.
            $placeholders = [];
            foreach ($reserved as $i => $seq) {
                $placeholders[$seq] = "\x00RESERVED{$i}\x00";
            }
            $protected = str_ireplace(array_keys($placeholders), array_values($placeholders), $str);
            $decoded = rawurldecode($protected);
            return new JsString(str_replace(array_values($placeholders), array_keys($placeholders), $decoded));
        }, 1));

        // escape/unescape (AnnexB)
        // ES spec B.2.1.1: escape operates on UTF-16 code units.
        $env->defineVar('escape', JsFunction::fromCallable('escape', function (JsValue $this_, array $args): JsValue {
            $str = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            // Convert UTF-8 string to array of UTF-16 code units.
            $codeUnits = self::utf8ToUtf16CodeUnits($str);
            $result = '';
            foreach ($codeUnits as $code) {
                if (
                    ($code >= 65 && $code <= 90) || ($code >= 97 && $code <= 122) || ($code >= 48 && $code <= 57)
                    || $code === 64 || $code === 42 || $code === 95 || $code === 43
                    || $code === 45 || $code === 46 || $code === 47
                ) {
                    // A-Z, a-z, 0-9, @, *, _, +, -, ., /
                    $result .= chr($code);
                } elseif ($code < 256) {
                    $result .= '%' . strtoupper(str_pad(dechex($code), 2, '0', STR_PAD_LEFT));
                } else {
                    $result .= '%u' . strtoupper(str_pad(dechex($code), 4, '0', STR_PAD_LEFT));
                }
            }
            return new JsString($result);
        }, 1));
        // ES spec B.2.1.2: unescape converts %uXXXX and %XX back to characters.
        $env->defineVar('unescape', JsFunction::fromCallable('unescape', function (JsValue $this_, array $args): JsValue {
            $str = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            $length = strlen($str);
            $result = '';
            $k = 0;
            while ($k < $length) {
                $c = $str[$k];
                if ($c === '%') {
                    // Check for %uXXXX (6 chars total)
                    if (
                        $k + 5 < $length
                        && $str[$k + 1] === 'u'
                        && ctype_xdigit($str[$k + 2])
                        && ctype_xdigit($str[$k + 3])
                        && ctype_xdigit($str[$k + 4])
                        && ctype_xdigit($str[$k + 5])
                    ) {
                        $code = (int) hexdec(substr($str, $k + 2, 4));
                        $chr = mb_chr($code, 'UTF-8');
                        $result .= $chr !== false ? $chr : $c;
                        if ($chr !== false) {
                            $k += 6;
                            continue;
                        }
                    }
                    // Check for %XX (3 chars total)
                    if (
                        $k + 2 < $length
                        && ctype_xdigit($str[$k + 1])
                        && ctype_xdigit($str[$k + 2])
                    ) {
                        $code = (int) hexdec(substr($str, $k + 1, 2));
                        $chr = mb_chr($code, 'UTF-8');
                        $result .= $chr !== false ? $chr : $c;
                        if ($chr !== false) {
                            $k += 3;
                            continue;
                        }
                    }
                }
                $result .= $c;
                $k++;
            }
            return new JsString($result);
        }, 1));

        // Function constructor: per spec, the created function's scope chain
        // consists of the global environment only (not the calling scope).
        // We capture $env here so the Function constructor always uses the
        // engine's global environment for the created function.
        // Shared helper: parse and evaluate a dynamic function with the given wrapper keyword(s).
        $buildDynamicFn = function (array $args, string $prefix) use ($env): JsValue {
            $body = '';
            $params = '';
            if (count($args) > 0) {
                // Per spec 20.2.1.1 step 5-9, ToString is called on each
                // argument left-to-right: first all parameter args, then body.
                $stringArgs = [];
                foreach ($args as $arg) {
                    $stringArgs[] = TypeConversion::toString($arg);
                }
                $body = array_pop($stringArgs);
                $params = implode(',', $stringArgs);
            }
            // Per spec steps 17-18, params are parsed first as FormalParameters.
            // The body gets line feeds per step 41 so AnnexB HTML comments work.
            $source = "({$prefix} anonymous({$params}\n) {\n{$body}\n})";
            $parser = new \PhpJs\Parser\Parser($source);
            $program = $parser->parse();
            self::validateDynamicFunction($program, $params);
            $interp = new Interpreter($env);
            return $interp->execute($program);
        };

        $fnConstructor = JsFunction::fromCallable(
            'Function',
            function (JsValue $this_, array $args) use ($buildDynamicFn): JsValue {
                return $buildDynamicFn($args, 'function');
            },
            1,
        );

        $genFnConstructor = JsFunction::fromCallable(
            'GeneratorFunction',
            function (JsValue $this_, array $args) use ($buildDynamicFn): JsValue {
                return $buildDynamicFn($args, 'function*');
            },
            1,
        );

        $asyncFnConstructor = JsFunction::fromCallable(
            'AsyncFunction',
            function (JsValue $this_, array $args) use ($buildDynamicFn): JsValue {
                return $buildDynamicFn($args, 'async function');
            },
            1,
        );

        // Function.prototype with call/apply/bind
        $fnProto = JsFunction::fromCallable('', fn() => JsUndefined::instance());

        // Set Function.prototype as [[Prototype]] for all future JsFunction instances.
        // This must happen before any further JsFunction creation so that
        // Object.getPrototypeOf(anyFn) === Function.prototype holds.
        JsFunction::setFunctionPrototype($fnProto);

        // Per ES spec 10.2.4 AddRestrictedFunctionProperties, Function.prototype
        // has "caller" and "arguments" as thrower accessor pairs. Accessing them
        // on any function that inherits from Function.prototype throws TypeError.
        $thrower = JsFunction::fromCallable('', function (): never {
            throw new \PhpJs\Exceptions\TypeError("'caller', 'callee', and 'arguments' properties may not be accessed on strict mode functions or the arguments objects for calls to them");
        });
        $throwerDesc = new \PhpJs\Object\PropertyDescriptor(
            enumerable: false,
            configurable: true,
            get: $thrower,
            set: $thrower,
        );
        $fnProto->defineOwnProperty('caller', $throwerDesc);
        $fnProto->defineOwnProperty('arguments', $throwerDesc);

        // Per spec §19.2.3.3, Function.prototype.call passes thisArg as-is.
        // Sloppy-mode this-wrapping happens inside the function body, not here.
        $fnProto->defineOwnProperty('call', \PhpJs\Object\PropertyDescriptor::data(JsFunction::fromCallable('call', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsFunction) {
                throw new \PhpJs\Exceptions\TypeError('call called on non-function');
            }
            $thisArg = $args[0] ?? JsUndefined::instance();
            return $this_->call($thisArg, array_slice($args, 1));
        }, 1), true, false, true));
        $fnProto->defineOwnProperty('apply', \PhpJs\Object\PropertyDescriptor::data(JsFunction::fromCallable('apply', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsFunction) {
                throw new \PhpJs\Exceptions\TypeError('apply called on non-function');
            }
            $thisArg = $args[0] ?? JsUndefined::instance();
            $argsArr = $args[1] ?? JsUndefined::instance();
            $callArgs = [];
            // Per spec 20.2.3.1 step 3-4: if argArray is null/undefined,
            // call with empty args. Otherwise CreateListFromArrayLike.
            if (!$argsArr instanceof JsUndefined && !$argsArr instanceof \PhpJs\Value\JsNull) {
                // CreateListFromArrayLike: argArray must be an object.
                if (!$argsArr instanceof \PhpJs\Value\JsObject) {
                    throw new \PhpJs\Exceptions\TypeError(
                        'CreateListFromArrayLike called on non-object',
                    );
                }
                // Get length and iterate index properties.
                $lenVal = $argsArr->get('length');
                $len = (int) TypeConversion::toNumber($lenVal);
                for ($i = 0; $i < $len; $i++) {
                    $callArgs[] = $argsArr->get((string) $i);
                }
            }
            return $this_->call($thisArg, $callArgs);
        }, 2), true, false, true));
        $fnProto->defineOwnProperty('bind', \PhpJs\Object\PropertyDescriptor::data(JsFunction::fromCallable('bind', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsFunction) {
                throw new \PhpJs\Exceptions\TypeError('bind called on non-function');
            }
            $boundThis = $args[0] ?? JsUndefined::instance();
            $boundArgs = array_slice($args, 1);
            $target = $this_;

            // Per spec 20.2.3.2 step 12-14: use target's exposed .name (string key).
            $targetNameVal = $target->get('name');
            $targetName = $targetNameVal instanceof JsString ? $targetNameVal->value : '';
            $boundName = 'bound ' . $targetName;

            // Per spec 20.2.3.2 step 5-8: compute bound function length.
            // max(0, targetLen - boundArgs.length). If targetLen is Infinity, result is Infinity.
            $boundLengthFloat = 0.0;
            $targetLengthVal = $target->get('length');
            if ($targetLengthVal instanceof JsNumber) {
                $rawLen = $targetLengthVal->toNumber();
                if (!is_nan($rawLen)) {
                    $boundLengthFloat = max(0.0, $rawLen - (float) count($boundArgs));
                }
            }

            // array_fill cannot handle Infinity — use 0 params and override the length property.
            $safeParamCount = is_finite($boundLengthFloat) ? (int) $boundLengthFloat : 0;
            $boundFn = JsFunction::fromCallable(
                $boundName,
                function (JsValue $th, array $callArgs) use ($target, $boundThis, $boundArgs): JsValue {
                    return $target->call($boundThis, array_merge($boundArgs, $callArgs));
                },
                $safeParamCount,
            );

            // Override length when it differs from safeParamCount (e.g. Infinity case).
            if (!is_finite($boundLengthFloat) || (float) $safeParamCount !== $boundLengthFloat) {
                $boundFn->defineOwnProperty('length', new \PhpJs\Object\PropertyDescriptor(
                    value: new JsNumber($boundLengthFloat),
                    writable: false,
                    enumerable: false,
                    configurable: true,
                ));
            }

            // Per spec, bound functions are constructable iff the target is constructable.
            if ($target->isConstructable()) {
                $boundFn->setConstructable();
            }

            // Per spec, bound functions don't have their own prototype
            // property. The [[HasInstance]] check walks to the target.
            // Copy the target's prototype so instanceof works.
            $targetProto = $target->get('prototype');
            if ($targetProto instanceof \PhpJs\Value\JsObject) {
                $boundFn->set('prototype', $targetProto);
            }
            return $boundFn;
        }, 1), true, false, true));

        // Function.prototype.toString: per spec, returns source text for
        // user-defined functions and NativeFunction syntax for built-ins.
        $fnProto->defineOwnProperty('toString', \PhpJs\Object\PropertyDescriptor::data(JsFunction::fromCallable('toString', function (JsValue $this_): JsValue {
            if (!$this_ instanceof JsFunction) {
                throw new \PhpJs\Exceptions\TypeError('Function.prototype.toString requires that \'this\' be a Function');
            }
            return new JsString($this_->toJsString());
        }, 0), true, false, true));

        // Function.prototype[Symbol.hasInstance] per spec 19.2.3.6.
        // OrdinaryHasInstance: check if the left operand's prototype chain
        // includes the function's .prototype property.
        $hasInstanceFn = JsFunction::fromCallable('[Symbol.hasInstance]', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsFunction) {
                return new JsBoolean(false);
            }
            $value = $args[0] ?? JsUndefined::instance();
            if (!$value instanceof \PhpJs\Value\JsObject) {
                return new JsBoolean(false);
            }
            $proto = $this_->get('prototype');
            if (!$proto instanceof \PhpJs\Value\JsObject) {
                throw new \PhpJs\Exceptions\TypeError(
                    'Function has non-object prototype in instanceof check',
                );
            }
            // Walk the prototype chain of value.
            $current = $value->getPrototype();
            while ($current !== null) {
                if ($current === $proto) {
                    return new JsBoolean(true);
                }
                $current = $current->getPrototype();
            }
            return new JsBoolean(false);
        }, 1);
        $hasInstanceFn->setName('[Symbol.hasInstance]');
        $fnProto->definePropertyBySymbol(
            \PhpJs\BuiltIn\SymbolConstructor::hasInstance(),
            new \PhpJs\Object\PropertyDescriptor(
                value: $hasInstanceFn,
                writable: false,
                enumerable: false,
                configurable: false,
            ),
        );

        // Function.prototype.constructor = Function (per spec 19.2.3.2).
        $fnProto->defineOwnProperty('constructor', \PhpJs\Object\PropertyDescriptor::data(
            $fnConstructor,
            true,
            false,
            true,
        ));

        $fnConstructor->setConstructable();
        // Per spec 19.2.2, Function.prototype is non-writable, non-enumerable, non-configurable.
        $fnConstructor->defineOwnProperty('prototype', new \PhpJs\Object\PropertyDescriptor(
            value: $fnProto,
            writable: false,
            enumerable: false,
            configurable: false,
        ));

        // GeneratorFunction.prototype inherits from Function.prototype (per spec §25.2.2).
        // GeneratorFunction itself is not exposed as a global but is reachable via
        // Object.getPrototypeOf(function*(){}).constructor.
        $genFnProto = JsFunction::fromCallable('', fn() => JsUndefined::instance());
        $genFnProto->setCustomPrototype($fnProto);
        $genFnProto->defineOwnProperty('constructor', \PhpJs\Object\PropertyDescriptor::data(
            $genFnConstructor,
            true,
            false,
            true,
        ));
        $genFnProto->defineOwnProperty('name', new \PhpJs\Object\PropertyDescriptor(
            value: new JsString('GeneratorFunction'),
            writable: false,
            enumerable: false,
            configurable: true,
        ));
        $genFnConstructor->setConstructable();
        $genFnConstructor->defineOwnProperty('prototype', new \PhpJs\Object\PropertyDescriptor(
            value: $genFnProto,
            writable: false,
            enumerable: false,
            configurable: false,
        ));
        JsFunction::setGeneratorFunctionPrototype($genFnProto);

        // AsyncFunction.prototype inherits from Function.prototype (per spec §27.7.3).
        $asyncFnProto = JsFunction::fromCallable('', fn() => JsUndefined::instance());
        $asyncFnProto->setCustomPrototype($fnProto);
        $asyncFnProto->defineOwnProperty('constructor', \PhpJs\Object\PropertyDescriptor::data(
            $asyncFnConstructor,
            true,
            false,
            true,
        ));
        $asyncFnProto->defineOwnProperty('name', new \PhpJs\Object\PropertyDescriptor(
            value: new JsString('AsyncFunction'),
            writable: false,
            enumerable: false,
            configurable: true,
        ));
        $asyncFnConstructor->setConstructable();
        $asyncFnConstructor->defineOwnProperty('prototype', new \PhpJs\Object\PropertyDescriptor(
            value: $asyncFnProto,
            writable: false,
            enumerable: false,
            configurable: false,
        ));
        JsFunction::setAsyncFunctionPrototype($asyncFnProto);

        $env->defineVar('Function', $fnConstructor);
    }

    private static function parseInt(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $string = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            $radix = isset($args[1]) ? (int) TypeConversion::toNumber($args[1]) : 0;

            $string = trim($string);
            if ($string === '') {
                return new JsNumber(NAN);
            }

            $negative = false;
            if ($string[0] === '-') {
                $negative = true;
                $string = substr($string, 1);
            } elseif ($string[0] === '+') {
                $string = substr($string, 1);
            }

            if ($radix === 0) {
                if (str_starts_with($string, '0x') || str_starts_with($string, '0X')) {
                    $radix = 16;
                    $string = substr($string, 2);
                } else {
                    $radix = 10;
                }
            } elseif ($radix === 16) {
                if (str_starts_with($string, '0x') || str_starts_with($string, '0X')) {
                    $string = substr($string, 2);
                }
            }

            if ($radix < 2 || $radix > 36) {
                return new JsNumber(NAN);
            }

            $validChars = substr('0123456789abcdefghijklmnopqrstuvwxyz', 0, $radix);
            $result = '';
            for ($i = 0; $i < strlen($string); $i++) {
                $ch = strtolower($string[$i]);
                if (!str_contains($validChars, $ch)) {
                    break;
                }
                $result .= $ch;
            }

            if ($result === '') {
                return new JsNumber(NAN);
            }

            $value = (float) intval($result, $radix);
            return new JsNumber($negative ? -$value : $value);
        };
    }

    private static function parseFloat(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $string = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            $string = ltrim($string);

            if ($string === '' || $string === 'undefined' || $string === 'null') {
                return new JsNumber(NAN);
            }

            if ($string === 'Infinity' || $string === '+Infinity') {
                return new JsNumber(INF);
            }
            if ($string === '-Infinity') {
                return new JsNumber(-INF);
            }

            if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/', $string, $matches)) {
                return new JsNumber((float) $matches[0]);
            }

            return new JsNumber(NAN);
        };
    }

    private static function isNaN(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $value = isset($args[0]) ? TypeConversion::toNumber($args[0]) : NAN;
            return new JsBoolean(is_nan($value));
        };
    }

    private static function isFinite(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $value = isset($args[0]) ? TypeConversion::toNumber($args[0]) : NAN;
            return new JsBoolean(is_finite($value));
        };
    }

    private static function stringConstructor(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            // Per spec 22.1.1.1 step 2, if the argument is a Symbol,
            // return the symbol's description string (SymbolDescriptiveString).
            // This is special-cased before the abstract ToString operation,
            // which would throw TypeError for symbols.
            if (!empty($args) && $args[0] instanceof \PhpJs\Value\JsSymbol) {
                $str = $args[0]->display();
            } else {
                $str = empty($args) ? '' : TypeConversion::toString($args[0]);
            }
            // When called as constructor (new String(x)), create wrapper object
            if ($this_ instanceof \PhpJs\Value\JsObject && $this_->has('[[NewTarget]]')) {
                $val = new JsString($str);
                $this_->defineOwnProperty('[[PrimitiveValue]]', \PhpJs\Object\PropertyDescriptor::data($val, false, false, false));
                // valueOf/toString come from String.prototype, not own properties.
                // Set indexed character properties and length per spec.
                $len = mb_strlen($str, 'UTF-8');
                for ($i = 0; $i < $len; $i++) {
                    $ch = mb_substr($str, $i, 1, 'UTF-8');
                    $this_->defineOwnProperty((string) $i, \PhpJs\Object\PropertyDescriptor::data(
                        new JsString($ch),
                        false,
                        true,
                        false,
                    ));
                }
                $this_->defineOwnProperty('length', \PhpJs\Object\PropertyDescriptor::data(
                    new \PhpJs\Value\JsNumber((float) $len),
                    false,
                    false,
                    false,
                ));
                return $this_;
            }
            return new JsString($str);
        };
    }

    private static function numberConstructor(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $num = empty($args) ? 0.0 : TypeConversion::toNumber($args[0]);
            // When called as constructor (new Number(x)), set up wrapper.
            // valueOf/toString come from Number.prototype, not own properties.
            if ($this_ instanceof \PhpJs\Value\JsObject && $this_->has('[[NewTarget]]')) {
                $val = new JsNumber($num);
                $this_->defineOwnProperty('[[PrimitiveValue]]', \PhpJs\Object\PropertyDescriptor::data($val, false, false, false));
                return $this_;
            }
            return new JsNumber($num);
        };
    }

    private static function booleanConstructor(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $bool = empty($args) ? false : TypeConversion::toBoolean($args[0]);
            if ($this_ instanceof \PhpJs\Value\JsObject && $this_->has('[[NewTarget]]')) {
                // Only set [[PrimitiveValue]], don't shadow prototype methods
                $this_->defineOwnProperty('[[PrimitiveValue]]', \PhpJs\Object\PropertyDescriptor::data(new JsBoolean($bool), false, false, false));
                return $this_;
            }
            return new JsBoolean($bool);
        };
    }

    /**
     * Convert a PHP UTF-8 string into an array of UTF-16 code unit values.
     * Codepoints above U+FFFF are split into surrogate pairs per UTF-16.
     *
     * @return int[]
     */
    private static function utf8ToUtf16CodeUnits(string $str): array
    {
        $units = [];
        $len = mb_strlen($str, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');
            $cp = mb_ord($char, 'UTF-8');
            if ($cp > 0xFFFF) {
                // Encode as surrogate pair.
                $cp -= 0x10000;
                $units[] = 0xD800 + ($cp >> 10);
                $units[] = 0xDC00 + ($cp & 0x3FF);
            } else {
                $units[] = $cp;
            }
        }
        return $units;
    }

    /**
     * Validate a dynamically created function (via Function constructor).
     *
     * Per spec 20.2.1.1 step 20, if the body contains "use strict":
     * - The body must not contain a WithStatement.
     * - Parameters must not have duplicate names.
     *
     * @throws \PhpJs\Exceptions\SyntaxError
     */
    private static function validateDynamicFunction(\PhpJs\Ast\Program $program, string $params): void
    {
        // The parsed program should contain a single ExpressionStatement
        // wrapping a FunctionExpression. Extract its body.
        $fnBody = null;
        foreach ($program->body as $stmt) {
            if (
                $stmt instanceof \PhpJs\Ast\Statement\ExpressionStatement
                && $stmt->expression instanceof \PhpJs\Ast\Expression\FunctionExpression
            ) {
                $fnBody = $stmt->expression->body;
                break;
            }
        }
        if ($fnBody === null) {
            return;
        }

        // Check for "use strict" directive in the function body.
        $isStrict = false;
        if ($fnBody instanceof \PhpJs\Ast\Statement\BlockStatement) {
            foreach ($fnBody->body as $bodyStmt) {
                if (!$bodyStmt instanceof \PhpJs\Ast\Statement\ExpressionStatement) {
                    break;
                }
                $expr = $bodyStmt->expression;
                if (
                    $expr instanceof \PhpJs\Ast\Expression\Literal
                    && is_string($expr->value)
                    && $expr->value === 'use strict'
                ) {
                    $isStrict = true;
                    break;
                }
                if (!$expr instanceof \PhpJs\Ast\Expression\Literal || !is_string($expr->value)) {
                    break;
                }
            }
        }

        if (!$isStrict) {
            return;
        }

        // Validate: no 'with' statements in the body.
        if ($fnBody instanceof \PhpJs\Ast\Statement\BlockStatement) {
            self::checkNoWithStatements($fnBody->body);
        }

        // Validate: no duplicate parameter names in strict mode.
        if ($params !== '') {
            $names = array_map('trim', explode(',', $params));
            $seen = [];
            foreach ($names as $name) {
                if ($name === '') {
                    continue;
                }
                if (in_array($name, $seen, true)) {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Duplicate parameter name not allowed in this context",
                    );
                }
                $seen[] = $name;
            }
        }
    }

    /**
     * Recursively check that no WithStatement exists in the given statements.
     *
     * @param \PhpJs\Ast\Node[] $statements
     * @throws \PhpJs\Exceptions\SyntaxError
     */
    private static function checkNoWithStatements(array $statements): void
    {
        foreach ($statements as $stmt) {
            if ($stmt instanceof \PhpJs\Ast\Statement\WithStatement) {
                throw new \PhpJs\Exceptions\SyntaxError(
                    'Strict mode code may not include a with statement',
                );
            }
            if ($stmt instanceof \PhpJs\Ast\Statement\BlockStatement) {
                self::checkNoWithStatements($stmt->body);
            } elseif ($stmt instanceof \PhpJs\Ast\Statement\IfStatement) {
                if ($stmt->consequent instanceof \PhpJs\Ast\Statement\BlockStatement) {
                    self::checkNoWithStatements($stmt->consequent->body);
                } elseif ($stmt->consequent instanceof \PhpJs\Ast\Statement\WithStatement) {
                    throw new \PhpJs\Exceptions\SyntaxError('Strict mode code may not include a with statement');
                }
                if ($stmt->alternate !== null) {
                    if ($stmt->alternate instanceof \PhpJs\Ast\Statement\BlockStatement) {
                        self::checkNoWithStatements($stmt->alternate->body);
                    } elseif ($stmt->alternate instanceof \PhpJs\Ast\Statement\WithStatement) {
                        throw new \PhpJs\Exceptions\SyntaxError('Strict mode code may not include a with statement');
                    }
                }
            }
        }
    }
}
