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
        // Per spec §19.1, these are value properties of the global object.
        // Use defineVar so they're accessible by name, but they will be
        // overridden as non-writable on the global object in Engine::setupGlobal.
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
        $boolProto->defineOwnProperty(
            '[[PrimitiveValue]]',
            \PhpJs\Object\PropertyDescriptor::data(new JsBoolean(false), false, false, false),
        );
        $boolProto->defineOwnProperty(
            'constructor',
            \PhpJs\Object\PropertyDescriptor::data($booleanFn, true, false, true),
        );
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
        $booleanFn->defineOwnProperty(
            'prototype',
            \PhpJs\Object\PropertyDescriptor::data($boolProto, false, false, false),
        );
        $env->defineVar('Boolean', $booleanFn);

        // Register the prototype so TypeConversion::toObject can link Boolean wrapper objects.
        JsBoolean::resetBooleanPrototype();
        JsBoolean::setBooleanPrototype($boolProto);

        // eval
        $evalFn = JsFunction::fromCallable('eval', function (JsValue $this_, array $args) use ($env): JsValue {
            $code = $args[0] ?? JsUndefined::instance();
            if (!$code instanceof JsString) {
                return $code;
            }
            if (strlen($code->value) > 1024 * 1024) {
                throw new \PhpJs\Exceptions\SyntaxError('Source too large for eval');
            }
            $parser = new \PhpJs\Parser\Parser($code->value);
            $program = $parser->parse();
            $interp = new Interpreter($env);
            $interp->setEvalContext(true);
            return $interp->execute($program);
        }, 1);
        $env->defineVar('eval', $evalFn);

        // encodeURIComponent / decodeURIComponent / encodeURI / decodeURI
        // Per spec sections 19.2.6.2-19.2.6.5, these functions operate on
        // UTF-16 code units and must throw URIError for lone surrogates
        // (encode) or malformed percent-encoded UTF-8 sequences (decode).

        $encodeCompFn = JsFunction::fromCallable(
            'encodeURIComponent',
            function (JsValue $this_, array $args) use ($env): JsValue {
                $str = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
                return new JsString(self::specEncode($str, false, $env));
            },
            1,
        );
        $env->defineVar('encodeURIComponent', $encodeCompFn);
        $decodeCompFn = JsFunction::fromCallable(
            'decodeURIComponent',
            function (JsValue $this_, array $args) use ($env): JsValue {
                $str = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
                return new JsString(self::specDecode($str, false, $env));
            },
            1,
        );
        $env->defineVar('decodeURIComponent', $decodeCompFn);
        $encodeUriFn = JsFunction::fromCallable(
            'encodeURI',
            function (JsValue $this_, array $args) use ($env): JsValue {
                $str = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
                return new JsString(self::specEncode($str, true, $env));
            },
            1,
        );
        $env->defineVar('encodeURI', $encodeUriFn);
        $decodeUriFn = JsFunction::fromCallable(
            'decodeURI',
            function (JsValue $this_, array $args) use ($env): JsValue {
                $str = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
                return new JsString(self::specDecode($str, true, $env));
            },
            1,
        );
        $env->defineVar('decodeURI', $decodeUriFn);

        // escape/unescape (AnnexB)
        // ES spec B.2.1.1: escape operates on UTF-16 code units.
        $escapeFn = JsFunction::fromCallable('escape', function (JsValue $this_, array $args): JsValue {
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
        }, 1);
        $env->defineVar('escape', $escapeFn);
        // ES spec B.2.1.2: unescape converts %uXXXX and %XX back to characters.
        $unescapeFn = JsFunction::fromCallable(
            'unescape',
            function (JsValue $this_, array $args): JsValue {
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
            },
            1,
        );
        $env->defineVar('unescape', $unescapeFn);

        // Function constructor: per spec, the created function's scope chain
        // consists of the global environment only (not the calling scope).
        // We capture $env here so the Function constructor always uses the
        // engine's global environment for the created function.
        $fnConstructor = JsFunction::fromCallable(
            'Function',
            function (JsValue $this_, array $args) use ($env): JsValue {
                $body = '';
                $params = '';
                if (count($args) > 0) {
                    // Per spec 20.2.1.1 step 5-9, ToString is called on each
                    // argument left-to-right: first all parameter args, then body.
                    // If any ToString throws, propagate before reaching the next.
                    $stringArgs = [];
                    foreach ($args as $arg) {
                        $stringArgs[] = TypeConversion::toString($arg);
                    }
                    $body = array_pop($stringArgs);
                    $params = implode(',', $stringArgs);
                }
            // Per spec steps 17-18, params are parsed first as FormalParameters
            // (no preceding line terminator, so --> in params is a SyntaxError).
            // The body gets line feeds per step 41 so AnnexB HTML comments work.
                $source = "(function anonymous({$params}\n) {\n{$body}\n})";
                $parser = new \PhpJs\Parser\Parser($source);
                $program = $parser->parse();

            // Per spec 20.2.1.1 step 20c-d, detect strict mode in the
            // body and validate strict-mode early errors (with statement,
            // duplicate params, etc.).
                self::validateDynamicFunction($program, $params);

            // Use the global environment so the created function can see
            // global variables (per spec: "scope chain consisting of the
            // global object").
                $interp = new Interpreter($env);
                return $interp->execute($program);
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
        // %ThrowTypeError% per spec 10.2.4: frozen, name="", length=0
        $thrower = JsFunction::fromCallable('', function (): never {
            throw new \PhpJs\Exceptions\TypeError(
                "'caller', 'callee', and 'arguments' properties may not be accessed"
                . ' on strict mode functions or the arguments objects for calls to them'
            );
        }, 0);
        $thrower->setNonConstructable();
        $thrower->forceDelete('prototype');
        // Freeze %ThrowTypeError%: non-extensible, all props non-configurable
        $thrower->defineOwnProperty(
            'length',
            \PhpJs\Object\PropertyDescriptor::data(new JsNumber(0.0), false, false, false),
        );
        $thrower->defineOwnProperty(
            'name',
            \PhpJs\Object\PropertyDescriptor::data(new JsString(''), false, false, false),
        );
        $thrower->preventExtensions();

        // caller/arguments accessor: configurable per ES2024+ spec 10.2.4.
        $throwerDesc = new \PhpJs\Object\PropertyDescriptor(
            enumerable: false,
            configurable: true,
            get: $thrower,
            set: $thrower,
        );
        $fnProto->defineOwnProperty('caller', $throwerDesc);
        $fnProto->defineOwnProperty('arguments', $throwerDesc);

        // Store %ThrowTypeError% so the arguments object creator can reuse
        // the same function identity per spec requirement.
        $env->defineInternal('__ThrowTypeError__', $thrower);

        // Per spec §19.2.3.3, Function.prototype.call passes thisArg as-is.
        // Sloppy-mode this-wrapping happens inside the function body, not here.
        $callFn = JsFunction::fromCallable('call', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsFunction) {
                throw new \PhpJs\Exceptions\TypeError('call called on non-function');
            }
            $thisArg = $args[0] ?? JsUndefined::instance();
            return $this_->call($thisArg, array_slice($args, 1));
        }, 1);
        $fnProto->defineOwnProperty(
            'call',
            \PhpJs\Object\PropertyDescriptor::data($callFn, true, false, true),
        );
        $applyFn = JsFunction::fromCallable('apply', function (JsValue $this_, array $args): JsValue {
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
        }, 2);
        $fnProto->defineOwnProperty(
            'apply',
            \PhpJs\Object\PropertyDescriptor::data($applyFn, true, false, true),
        );
        $bindCb = function (
            JsValue $this_,
            array $args,
            ?\PhpJs\Runtime\Interpreter $interp = null,
        ): JsValue {
            if (!$this_ instanceof JsFunction) {
                throw new \PhpJs\Exceptions\TypeError('bind called on non-function');
            }
            $boundThis = $args[0] ?? JsUndefined::instance();
            $boundArgs = array_slice($args, 1);
            $target = $this_;

            // Per spec 20.2.3.2 steps 5-7:
            // 5. Let targetHasLength be ? HasOwnProperty(Target, "length").
            // 6. If targetHasLength, get Target.length; if type is Number, apply ToIntegerOrInfinity.
            // 7. Else L = 0.
            $boundLengthFloat = 0.0;
            if ($target->hasOwnProperty('length')) {
                $targetLengthVal = $target->get('length');
                if ($targetLengthVal instanceof JsNumber) {
                    $tl = $targetLengthVal->value;
                    if (is_nan($tl) || $tl === -0.0) {
                        $tl = 0.0;
                    } elseif ($tl === INF) {
                        $boundLengthFloat = INF;
                        $tl = null; // skip further calculation
                    } elseif ($tl === -INF) {
                        $tl = 0.0;
                    } else {
                        // ToIntegerOrInfinity: truncate toward zero.
                        $tl = $tl >= 0 ? floor($tl) : ceil($tl);
                    }
                    if ($tl !== null) {
                        $boundLengthFloat = max(0.0, $tl - count($boundArgs));
                    }
                }
                // If type is not Number, L stays 0.
            }
            // Determine target name for the bound function's name.
            // Per spec step 12-13: Get(Target, "name"), ReturnIfAbrupt.
            $targetNameVal = $target->get('name');
            $targetName = $targetNameVal instanceof JsString
                ? $targetNameVal->value
                : '';
            $boundName = 'bound ' . $targetName;

            $isConstructable = $target->isConstructable();

            // Always pass 0 to fromCallable to avoid array_fill crash for large lengths;
            // the length property is always overridden explicitly below.
            $boundFn = JsFunction::fromCallable(
                $boundName,
                function (
                    JsValue $th,
                    array $callArgs,
                    ?\PhpJs\Runtime\Interpreter $innerInterp = null,
                ) use (
                    $target,
                    $boundThis,
                    $boundArgs,
                    $isConstructable
                ): JsValue {
                    $mergedArgs = array_merge($boundArgs, $callArgs);
                    // Detect if called as constructor (th has [[NewTarget]]).
                    if (
                        $isConstructable
                        && $th instanceof \PhpJs\Value\JsObject
                        && !($th->get('[[NewTarget]]') instanceof JsUndefined)
                    ) {
                        if ($innerInterp !== null) {
                            // Called via new with interpreter: construct the target with merged args.
                            $proto = $target->get('prototype');
                            $protoObj = $proto instanceof \PhpJs\Value\JsObject ? $proto : null;
                            $newObj = new \PhpJs\Value\JsObject($protoObj);
                            $newObj->defineOwnProperty(
                                '[[NewTarget]]',
                                \PhpJs\Object\PropertyDescriptor::data($target, false, false, false),
                            );
                            $result = $innerInterp->callFunction($target, $newObj, $mergedArgs);
                            return $result instanceof \PhpJs\Value\JsObject ? $result : $newObj;
                        }
                        // Called via construct path without interpreter (e.g. proxy forwarding).
                        // Delegate to target's construct method directly.
                        return $target->construct($mergedArgs);
                    }
                    return $target->call($boundThis, $mergedArgs);
                },
                0,
            );

            // Always override the length property with the computed bound length.
            $boundFn->defineOwnProperty('length', new \PhpJs\Object\PropertyDescriptor(
                value: new JsNumber($boundLengthFloat),
                writable: false,
                enumerable: false,
                configurable: true,
            ));

            // Set name property per spec: "bound " + targetName.
            $boundFn->defineOwnProperty('name', new \PhpJs\Object\PropertyDescriptor(
                value: new JsString($boundName),
                writable: false,
                enumerable: false,
                configurable: true,
            ));

            // Mark constructable if the target is constructable.
            if ($isConstructable) {
                $boundFn->setConstructable();
            }

            // Per spec 20.2.3.2, bound functions do NOT have an own "prototype"
            // property. Store the [[BoundTargetFunction]] so OrdinaryHasInstance
            // can walk through to the target's prototype.
            $boundFn->forceDelete('prototype');
            $boundFn->setBoundTarget($target);
            return $boundFn;
        };
        $bindFn = JsFunction::fromCallable('bind', $bindCb, 1);
        $fnProto->defineOwnProperty(
            'bind',
            \PhpJs\Object\PropertyDescriptor::data($bindFn, true, false, true),
        );

        // Function.prototype.toString: per spec, returns source text for
        // user-defined functions and NativeFunction syntax for built-ins.
        // Proxy exotic objects wrapping a callable also return NativeFunction syntax.
        $toStringFn = JsFunction::fromCallable('toString', function (JsValue $this_): JsValue {
            if ($this_ instanceof JsFunction) {
                return new JsString($this_->toJsString());
            }
            // Proxy wrapping a callable: return NativeFunction syntax per spec.
            if ($this_ instanceof \PhpJs\Value\JsProxy && $this_->isCallable()) {
                return new JsString('function () { [native code] }');
            }
            throw new \PhpJs\Exceptions\TypeError(
                'Function.prototype.toString requires that \'this\' be a Function'
            );
        }, 0);
        $fnProto->defineOwnProperty(
            'toString',
            \PhpJs\Object\PropertyDescriptor::data($toStringFn, true, false, true),
        );

        // Function.prototype[Symbol.hasInstance] per spec 19.2.3.6.
        // OrdinaryHasInstance: check if the left operand's prototype chain
        // includes the function's .prototype property.
        $hasInstanceFn = JsFunction::fromCallable(
            '[Symbol.hasInstance]',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsFunction) {
                    return new JsBoolean(false);
                }
                $value = $args[0] ?? JsUndefined::instance();
                if (!$value instanceof \PhpJs\Value\JsObject) {
                    return new JsBoolean(false);
                }
                // Per spec 7.3.22 OrdinaryHasInstance step 2: if F has a
                // [[BoundTargetFunction]], resolve through to the original target.
                $target = $this_;
                while ($target->getBoundTarget() !== null) {
                    $target = $target->getBoundTarget();
                }
                $proto = $target->get('prototype');
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
            },
            1,
        );
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
        $env->defineVar('Function', $fnConstructor);

        // %IteratorPrototype%: the common prototype for all built-in iterators.
        // Per spec 27.1.2, its [[Prototype]] is Object.prototype.
        $iteratorPrototype = new \PhpJs\Value\JsObject();
        $iteratorPrototype->definePropertyBySymbol(
            \PhpJs\BuiltIn\SymbolConstructor::iterator(),
            \PhpJs\Object\PropertyDescriptor::data(
                JsFunction::fromCallable('[Symbol.iterator]', static function (JsValue $this_, array $args): JsValue {
                    return $this_;
                }, 0),
                true,
                false,
                true,
            ),
        );
        $env->defineInternal('__IteratorPrototype__', $iteratorPrototype);

        // Set up %GeneratorFunction%, %GeneratorFunction.prototype%, and %GeneratorPrototype%.
        // Per spec 27.3: GeneratorFunction.prototype is an ordinary non-callable object
        // whose [[Prototype]] is Function.prototype.
        // Per spec 27.1.2: %GeneratorPrototype% [[Prototype]] = %IteratorPrototype%.

        // %GeneratorFunction.prototype%: ordinary object (NOT callable), [[Prototype]] = Function.prototype.
        $generatorFunctionProto = new \PhpJs\Value\JsObject($fnProto);
        JsFunction::setGeneratorFunctionPrototype($generatorFunctionProto);

        // %GeneratorPrototype%: the prototype of all generator instances.
        // Per spec its [[Prototype]] is %IteratorPrototype%.
        $generatorPrototype = new \PhpJs\Value\JsObject($iteratorPrototype);
        // Symbol.toStringTag = "Generator" per spec 27.5.1.
        $generatorPrototype->definePropertyBySymbol(
            \PhpJs\BuiltIn\SymbolConstructor::toStringTag(),
            \PhpJs\Object\PropertyDescriptor::data(
                new JsString('Generator'),
                false,
                false,
                true,
            ),
        );
        // Install next/return/throw on %GeneratorPrototype% per spec 27.5.1.
        $nextFn = JsFunction::fromCallable('next', static function (
            JsValue $thisValue,
            array $args,
        ): JsValue {
            if (!$thisValue instanceof \PhpJs\Value\JsGenerator) {
                throw new \PhpJs\Exceptions\TypeError(
                    'Method Generator.prototype.next called on incompatible receiver',
                );
            }
            $value = $args[0] ?? JsUndefined::instance();
            return $thisValue->next($value);
        }, 1);
        $nextFn->setNonConstructable();
        $generatorPrototype->defineOwnProperty('next', \PhpJs\Object\PropertyDescriptor::data(
            $nextFn,
            true,
            false,
            true,
        ));
        $returnFn = JsFunction::fromCallable('return', static function (
            JsValue $thisValue,
            array $args,
        ): JsValue {
            if (!$thisValue instanceof \PhpJs\Value\JsGenerator) {
                throw new \PhpJs\Exceptions\TypeError(
                    'Method Generator.prototype.return called on incompatible receiver',
                );
            }
            $value = $args[0] ?? JsUndefined::instance();
            return $thisValue->returnValue($value);
        }, 1);
        $returnFn->setNonConstructable();
        $generatorPrototype->defineOwnProperty('return', \PhpJs\Object\PropertyDescriptor::data(
            $returnFn,
            true,
            false,
            true,
        ));
        $throwFn = JsFunction::fromCallable('throw', static function (
            JsValue $thisValue,
            array $args,
        ): JsValue {
            if (!$thisValue instanceof \PhpJs\Value\JsGenerator) {
                throw new \PhpJs\Exceptions\TypeError(
                    'Method Generator.prototype.throw called on incompatible receiver',
                );
            }
            $value = $args[0] ?? JsUndefined::instance();
            return $thisValue->throwValue($value);
        }, 1);
        $throwFn->setNonConstructable();
        $generatorPrototype->defineOwnProperty('throw', \PhpJs\Object\PropertyDescriptor::data(
            $throwFn,
            true,
            false,
            true,
        ));

        // Wire: GeneratorFunction.prototype.prototype = %GeneratorPrototype%
        // Per spec 27.3.3.2: {writable: false, enumerable: false, configurable: true}.
        $generatorFunctionProto->defineOwnProperty('prototype', \PhpJs\Object\PropertyDescriptor::data(
            $generatorPrototype,
            false,
            false,
            true,
        ));

        // constructor on %GeneratorPrototype%: points to %GeneratorFunction.prototype%.
        // Per spec 27.5.1.1: {writable: false, enumerable: false, configurable: true}.
        $generatorPrototype->defineOwnProperty('constructor', \PhpJs\Object\PropertyDescriptor::data(
            $generatorFunctionProto,
            false,
            false,
            true,
        ));

        // %GeneratorFunction% constructor: like Function() but for generators.
        // Per spec 27.3.1.1: GeneratorFunction(p1, p2, ..., pn, body).
        $genFnConstructor = JsFunction::fromCallable(
            'GeneratorFunction',
            function (JsValue $this_, array $args) use ($env): JsValue {
                $body = '';
                $params = '';
                if (count($args) > 0) {
                    $stringArgs = [];
                    foreach ($args as $arg) {
                        $stringArgs[] = TypeConversion::toString($arg);
                    }
                    $body = array_pop($stringArgs);
                    $params = implode(',', $stringArgs);
                }
                // Per spec step 20: if parameters Contains YieldExpression, throw SyntaxError.
                // Detect yield in parameters by parsing as a generator and checking params.
                if ($params !== '') {
                    // Parse as a regular function to detect yield in params.
                    // If `yield` appears in params and is not inside the body,
                    // it should throw SyntaxError.
                    try {
                        $testSource = "(function({$params}\n) {})";
                        $testParser = new \PhpJs\Parser\Parser($testSource);
                        $testParser->parse();
                    } catch (\Throwable $e) {
                        throw new \PhpJs\Exceptions\SyntaxError($e->getMessage());
                    }
                    // Check if params contain `yield` as keyword (not inside a string).
                    // Parse as generator to see if yield is treated as expression in params.
                    if (preg_match('/\byield\b/', $params)) {
                        throw new \PhpJs\Exceptions\SyntaxError(
                            'Yield expression is not allowed in formal parameters'
                        );
                    }
                }
                $source = "(function* anonymous({$params}\n) {\n{$body}\n})";
                $parser = new \PhpJs\Parser\Parser($source);
                $program = $parser->parse();
                $interp = new Interpreter($env);
                $fn = $interp->execute($program);
                return $fn;
            },
            1,
        );
        $genFnConstructor->setConstructable();
        // Per spec 27.3.2: GeneratorFunction.length = 1.
        $genFnConstructor->defineOwnProperty('length', new \PhpJs\Object\PropertyDescriptor(
            value: new JsNumber(1.0),
            writable: false,
            enumerable: false,
            configurable: true,
        ));
        // Per spec: GeneratorFunction.name = "GeneratorFunction".
        $genFnConstructor->defineOwnProperty('name', new \PhpJs\Object\PropertyDescriptor(
            value: new JsString('GeneratorFunction'),
            writable: false,
            enumerable: false,
            configurable: true,
        ));
        // Per spec 27.3.2.1: GeneratorFunction.prototype = %GeneratorFunction.prototype%.
        // {writable: false, enumerable: false, configurable: false}.
        $genFnConstructor->defineOwnProperty('prototype', new \PhpJs\Object\PropertyDescriptor(
            value: $generatorFunctionProto,
            writable: false,
            enumerable: false,
            configurable: false,
        ));
        // GeneratorFunction [[Prototype]] is Function per spec 27.3.2.
        $genFnConstructor->setCustomPrototype($fnConstructor);

        // %GeneratorFunction.prototype%.constructor = %GeneratorFunction%
        // Per spec 27.3.3.1: {writable: false, enumerable: false, configurable: true}.
        $generatorFunctionProto->defineOwnProperty('constructor', \PhpJs\Object\PropertyDescriptor::data(
            $genFnConstructor,
            false,
            false,
            true,
        ));

        // Symbol.toStringTag = "GeneratorFunction" per spec 27.3.3.3.
        $generatorFunctionProto->definePropertyBySymbol(
            \PhpJs\BuiltIn\SymbolConstructor::toStringTag(),
            new \PhpJs\Object\PropertyDescriptor(
                value: new JsString('GeneratorFunction'),
                writable: false,
                enumerable: false,
                configurable: true,
            ),
        );

        // Register the intrinsic so JsGenerator can use it as fallback when fn.prototype is not an Object.
        \PhpJs\Value\JsGenerator::setGeneratorPrototype($generatorPrototype);
        // Store for interpreter access.
        $env->defineInternal('__GeneratorPrototype__', $generatorPrototype);
        $env->defineInternal('__GeneratorFunctionPrototype__', $generatorFunctionProto);
        $env->defineVar('GeneratorFunction', $genFnConstructor);
    }

    private static function parseInt(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $string = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            $radixArg = $args[1] ?? JsUndefined::instance();
            $radix = $radixArg instanceof JsUndefined
                ? 0
                : TypeConversion::toInt32($radixArg);

            // Strip leading/trailing ECMAScript whitespace per spec.
            // Do NOT use \s — PCRE2 \s includes U+180E which ES removed.
            $ws = '[\x09\x0A\x0B\x0C\x0D\x20'
                . '\x{00A0}\x{FEFF}\x{1680}'
                . '\x{2000}-\x{200A}'
                . '\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';
            $replaced = preg_replace(
                '/^' . $ws . '+|' . $ws . '+$/u',
                '',
                $string,
            );
            // preg_replace returns null on invalid UTF-8; fall back to ASCII trim.
            $string = $replaced ?? trim($string, " \t\n\r\x0B\x0C");
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

            // Use float arithmetic to avoid PHP_INT overflow.
            $value = 0.0;
            for ($j = 0; $j < strlen($result); $j++) {
                $digit = strpos(
                    '0123456789abcdefghijklmnopqrstuvwxyz',
                    $result[$j],
                );
                $value = $value * $radix + $digit;
            }
            return new JsNumber($negative ? -$value : $value);
        };
    }

    private static function parseFloat(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $string = isset($args[0])
                ? TypeConversion::toString($args[0])
                : 'undefined';
            // Strip leading ECMAScript whitespace per spec (no \s — excludes U+180E).
            $ws = '[\x09\x0A\x0B\x0C\x0D\x20'
                . '\x{00A0}\x{FEFF}\x{1680}'
                . '\x{2000}-\x{200A}'
                . '\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';
            $replaced = preg_replace(
                '/^' . $ws . '+/u',
                '',
                $string,
            );
            // preg_replace returns null on invalid UTF-8; fall back to ASCII ltrim.
            $string = $replaced ?? ltrim($string, " \t\n\r\x0B\x0C");

            if ($string === '') {
                return new JsNumber(NAN);
            }

            // Check for Infinity prefix (not exact match).
            if (
                str_starts_with($string, 'Infinity')
                || str_starts_with($string, '+Infinity')
            ) {
                return new JsNumber(INF);
            }
            if (str_starts_with($string, '-Infinity')) {
                return new JsNumber(-INF);
            }

            if (
                preg_match(
                    '/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/',
                    $string,
                    $matches,
                )
            ) {
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
                $this_->defineOwnProperty(
                    '[[PrimitiveValue]]',
                    \PhpJs\Object\PropertyDescriptor::data($val, false, false, false),
                );
                // valueOf/toString come from String.prototype, not own properties.
                // Set indexed character properties and length per spec.
                // JS strings use UTF-16 code units, so use the UTF-16 length.
                $u16Len = $val->length();
                $u16 = JsString::utf8ToUtf16LE($str);
                for ($i = 0; $i < $u16Len; $i++) {
                    $codeUnit = ord($u16[$i * 2]) | (ord($u16[$i * 2 + 1]) << 8);
                    $ch = JsString::utf16CodeUnitToUtf8($codeUnit);
                    $this_->defineOwnProperty((string) $i, \PhpJs\Object\PropertyDescriptor::data(
                        new JsString($ch),
                        false,
                        true,
                        false,
                    ));
                }
                $this_->defineOwnProperty('length', \PhpJs\Object\PropertyDescriptor::data(
                    new \PhpJs\Value\JsNumber((float) $u16Len),
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
            if (empty($args)) {
                $num = 0.0;
            } else {
                // Per spec: use ToNumeric first, then convert BigInt to float.
                $prim = TypeConversion::toNumeric($args[0]);
                if ($prim instanceof \PhpJs\Value\JsBigInt) {
                    // 𝔽(ℝ(bigint)) - BigInt → Number (may lose precision).
                    $num = (float) $prim->value;
                } else {
                    $num = TypeConversion::toNumber($prim);
                }
            }
            // When called as constructor (new Number(x)), set up wrapper.
            // valueOf/toString come from Number.prototype, not own properties.
            if ($this_ instanceof \PhpJs\Value\JsObject && $this_->has('[[NewTarget]]')) {
                $val = new JsNumber($num);
                $this_->defineOwnProperty(
                    '[[PrimitiveValue]]',
                    \PhpJs\Object\PropertyDescriptor::data($val, false, false, false),
                );
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
                $this_->defineOwnProperty(
                    '[[PrimitiveValue]]',
                    \PhpJs\Object\PropertyDescriptor::data(new JsBoolean($bool), false, false, false),
                );
                return $this_;
            }
            return new JsBoolean($bool);
        };
    }

    /**
     * Throw a JS URIError. Constructs a proper URIError object with the
     * correct prototype chain so that `e instanceof URIError` works.
     *
     * @return never
     */
    private static function throwURIError(string $message, Environment $env): void
    {
        $errorObj = new \PhpJs\Value\JsObject();
        $errorObj->set('message', new JsString($message));
        $errorObj->set('name', new JsString('URIError'));
        $errorObj->set('stack', new JsString('URIError: ' . $message));
        if ($env->has('URIError')) {
            $constructor = $env->get('URIError');
            if ($constructor instanceof JsFunction) {
                $errorObj->set('constructor', $constructor);
                $proto = $constructor->get('prototype');
                if ($proto instanceof \PhpJs\Value\JsObject) {
                    $errorObj->setPrototype($proto);
                }
            }
        }
        throw new \PhpJs\Exceptions\JsThrowable($errorObj, 'URIError: ' . $message);
    }

    /**
     * Spec-compliant Encode(string, unescapedSet) per ES2023 section 19.2.6.1.1.
     * Operates on UTF-16 code units of the input string.
     *
     * @param bool $isUri true for encodeURI, false for encodeURIComponent
     */
    private static function specEncode(string $str, bool $isUri, Environment $env): string
    {
        // Unescaped characters for encodeURIComponent: uriUnescaped
        //   uriAlpha (A-Z, a-z) + DecimalDigit (0-9) + uriMark (- _ . ! ~ * ' ( ))
        // Unescaped characters for encodeURI: uriReserved + uriUnescaped + "#"
        //   uriReserved: ; / ? : @ & = + $ ,
        //   "#"

        // Build a lookup set of ASCII code points that are unescaped.
        $unescaped = [];
        // uriUnescaped: A-Z, a-z, 0-9
        for ($c = ord('A'); $c <= ord('Z'); $c++) {
            $unescaped[$c] = true;
        }
        for ($c = ord('a'); $c <= ord('z'); $c++) {
            $unescaped[$c] = true;
        }
        for ($c = ord('0'); $c <= ord('9'); $c++) {
            $unescaped[$c] = true;
        }
        // uriMark: - _ . ! ~ * ' ( )
        foreach (['-', '_', '.', '!', '~', '*', "'", '(', ')'] as $ch) {
            $unescaped[ord($ch)] = true;
        }
        if ($isUri) {
            // uriReserved: ; / ? : @ & = + $ ,
            foreach ([';', '/', '?', ':', '@', '&', '=', '+', '$', ','] as $ch) {
                $unescaped[ord($ch)] = true;
            }
            // "#"
            $unescaped[ord('#')] = true;
        }

        // Get the UTF-16 code units from the internal string representation.
        $u16 = JsString::utf8ToUtf16LE($str);
        $u16Len = (int) (strlen($u16) / 2);

        $result = '';
        for ($k = 0; $k < $u16Len; $k++) {
            $codeUnit = ord($u16[$k * 2]) | (ord($u16[$k * 2 + 1]) << 8);

            // Check if the code unit is in the unescaped set (only BMP non-surrogate).
            if ($codeUnit < 0x80 && isset($unescaped[$codeUnit])) {
                $result .= chr($codeUnit);
                continue;
            }

            // Lone low surrogate: throw URIError.
            if ($codeUnit >= 0xDC00 && $codeUnit <= 0xDFFF) {
                self::throwURIError('URI malformed', $env);
            }

            $codePoint = $codeUnit;
            if ($codeUnit >= 0xD800 && $codeUnit <= 0xDBFF) {
                // High surrogate: must be followed by a low surrogate.
                if ($k + 1 >= $u16Len) {
                    self::throwURIError('URI malformed', $env);
                }
                $next = ord($u16[($k + 1) * 2]) | (ord($u16[($k + 1) * 2 + 1]) << 8);
                if ($next < 0xDC00 || $next > 0xDFFF) {
                    self::throwURIError('URI malformed', $env);
                }
                // Decode surrogate pair to codepoint.
                $codePoint = ($codeUnit - 0xD800) * 0x400 + ($next - 0xDC00) + 0x10000;
                $k++; // skip the low surrogate
            }

            // Encode the codepoint as UTF-8 bytes, then percent-encode each byte.
            $utf8Bytes = self::codePointToUtf8Bytes($codePoint);
            foreach ($utf8Bytes as $byte) {
                $result .= '%' . strtoupper(str_pad(dechex($byte), 2, '0', STR_PAD_LEFT));
            }
        }

        return $result;
    }

    /**
     * Spec-compliant Decode(string, reservedSet) per ES2023 section 19.2.6.1.2.
     *
     * @param bool $isUri true for decodeURI (preserves reserved), false for decodeURIComponent
     */
    private static function specDecode(string $str, bool $isUri, Environment $env): string
    {
        // Reserved set for decodeURI: uriReserved + "#"
        // Characters: ; / ? : @ & = + $ , #
        // Their percent-encoded forms should NOT be decoded.
        $reservedBytes = [];
        if ($isUri) {
            foreach ([';', '/', '?', ':', '@', '&', '=', '+', '$', ',', '#'] as $ch) {
                $reservedBytes[ord($ch)] = true;
            }
        }

        $len = strlen($str);
        $result = '';
        $k = 0;

        while ($k < $len) {
            $ch = $str[$k];
            if ($ch !== '%') {
                $result .= $ch;
                $k++;
                continue;
            }

            // '%' found at position $k.
            $start = $k;

            // Step: k + 2 must be < len, and the two chars after % must be hex digits.
            if ($k + 2 >= $len) {
                self::throwURIError('URI malformed', $env);
            }
            if (!ctype_xdigit($str[$k + 1]) || !ctype_xdigit($str[$k + 2])) {
                self::throwURIError('URI malformed', $env);
            }

            $b = (int) hexdec($str[$k + 1] . $str[$k + 2]);
            $k += 3;

            if ($b < 0x80) {
                // Single-byte: ASCII character.
                // For decodeURI, check if this byte is in the reserved set.
                if ($isUri && isset($reservedBytes[$b])) {
                    // Keep the percent-encoded form exactly as it appeared in the input.
                    $result .= substr($str, $start, 3);
                } else {
                    $result .= chr($b);
                }
                continue;
            }

            // Multi-byte UTF-8 sequence. Determine the expected byte count from
            // the leading byte.
            if (($b & 0xE0) === 0xC0) {
                $n = 2;
            } elseif (($b & 0xF0) === 0xE0) {
                $n = 3;
            } elseif (($b & 0xF8) === 0xF0) {
                $n = 4;
            } else {
                // Invalid leading byte (0x80-0xBF, or 0xF8-0xFF).
                self::throwURIError('URI malformed', $env);
            }

            $octets = [$b];

            // Read the remaining n-1 continuation bytes, each as %XX.
            for ($j = 1; $j < $n; $j++) {
                if ($k >= $len || $str[$k] !== '%') {
                    self::throwURIError('URI malformed', $env);
                }
                if ($k + 2 >= $len) {
                    self::throwURIError('URI malformed', $env);
                }
                if (!ctype_xdigit($str[$k + 1]) || !ctype_xdigit($str[$k + 2])) {
                    self::throwURIError('URI malformed', $env);
                }
                $cb = (int) hexdec($str[$k + 1] . $str[$k + 2]);
                // Continuation bytes must be 10xxxxxx (0x80-0xBF).
                if (($cb & 0xC0) !== 0x80) {
                    self::throwURIError('URI malformed', $env);
                }
                $octets[] = $cb;
                $k += 3;
            }

            // Decode the UTF-8 byte sequence to a Unicode codepoint.
            $codePoint = match ($n) {
                2 => (($octets[0] & 0x1F) << 6) | ($octets[1] & 0x3F),
                3 => (($octets[0] & 0x0F) << 12) | (($octets[1] & 0x3F) << 6) | ($octets[2] & 0x3F),
                default => (($octets[0] & 0x07) << 18) | (($octets[1] & 0x3F) << 12)
                     | (($octets[2] & 0x3F) << 6) | ($octets[3] & 0x3F),
            };

            // Validate: reject overlong encodings and out-of-range codepoints.
            if ($n === 2 && $codePoint < 0x80) {
                self::throwURIError('URI malformed', $env);
            }
            if ($n === 3 && $codePoint < 0x800) {
                self::throwURIError('URI malformed', $env);
            }
            if ($n === 4 && $codePoint < 0x10000) {
                self::throwURIError('URI malformed', $env);
            }
            if ($codePoint > 0x10FFFF) {
                self::throwURIError('URI malformed', $env);
            }
            // Surrogates (U+D800-U+DFFF) are not valid Unicode codepoints in UTF-8.
            if ($codePoint >= 0xD800 && $codePoint <= 0xDFFF) {
                self::throwURIError('URI malformed', $env);
            }

            // Convert the codepoint to the internal string representation.
            // Codepoints > U+FFFF are stored as CESU-8 surrogate pairs internally.
            if ($codePoint <= 0xFFFF) {
                $result .= JsString::utf16CodeUnitToUtf8($codePoint);
            } else {
                // Supplementary plane: encode as surrogate pair in CESU-8.
                $cp = $codePoint - 0x10000;
                $hi = 0xD800 + ($cp >> 10);
                $lo = 0xDC00 + ($cp & 0x3FF);
                $result .= JsString::utf16CodeUnitToUtf8($hi);
                $result .= JsString::utf16CodeUnitToUtf8($lo);
            }
        }

        return $result;
    }

    /**
     * Encode a Unicode codepoint to its UTF-8 byte sequence.
     *
     * @return int[] array of byte values
     */
    private static function codePointToUtf8Bytes(int $cp): array
    {
        if ($cp <= 0x7F) {
            return [$cp];
        }
        if ($cp <= 0x7FF) {
            return [
                0xC0 | ($cp >> 6),
                0x80 | ($cp & 0x3F),
            ];
        }
        if ($cp <= 0xFFFF) {
            return [
                0xE0 | ($cp >> 12),
                0x80 | (($cp >> 6) & 0x3F),
                0x80 | ($cp & 0x3F),
            ];
        }
        return [
            0xF0 | ($cp >> 18),
            0x80 | (($cp >> 12) & 0x3F),
            0x80 | (($cp >> 6) & 0x3F),
            0x80 | ($cp & 0x3F),
        ];
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
        // Also check for restricted names: 'eval' and 'arguments'.
        if ($params !== '') {
            $names = array_map('trim', explode(',', $params));
            $seen = [];
            foreach ($names as $name) {
                if ($name === '') {
                    continue;
                }
                if ($name === 'eval' || $name === 'arguments') {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Unexpected eval or arguments in strict mode",
                    );
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
            self::checkNodeForWith($stmt);
        }
    }

    private static function checkNodeForWith(\PhpJs\Ast\Node $node): void
    {
        if ($node instanceof \PhpJs\Ast\Statement\WithStatement) {
            throw new \PhpJs\Exceptions\SyntaxError(
                'Strict mode code may not include a with statement',
            );
        }
        if ($node instanceof \PhpJs\Ast\Statement\BlockStatement) {
            foreach ($node->body as $child) {
                self::checkNodeForWith($child);
            }
        } elseif ($node instanceof \PhpJs\Ast\Statement\IfStatement) {
            self::checkNodeForWith($node->consequent);
            if ($node->alternate !== null) {
                self::checkNodeForWith($node->alternate);
            }
        } elseif ($node instanceof \PhpJs\Ast\Statement\ForStatement
            || $node instanceof \PhpJs\Ast\Statement\WhileStatement
            || $node instanceof \PhpJs\Ast\Statement\DoWhileStatement
            || $node instanceof \PhpJs\Ast\Statement\ForInStatement
            || $node instanceof \PhpJs\Ast\Statement\ForOfStatement
        ) {
            self::checkNodeForWith($node->body);
        } elseif ($node instanceof \PhpJs\Ast\Statement\TryStatement) {
            self::checkNodeForWith($node->block);
            if ($node->handler !== null) {
                self::checkNodeForWith($node->handler->body);
            }
            if ($node->finalizer !== null) {
                self::checkNodeForWith($node->finalizer);
            }
        } elseif ($node instanceof \PhpJs\Ast\Statement\SwitchStatement) {
            foreach ($node->cases as $case) {
                foreach ($case->consequent as $child) {
                    self::checkNodeForWith($child);
                }
            }
        } elseif ($node instanceof \PhpJs\Ast\Statement\LabeledStatement) {
            self::checkNodeForWith($node->body);
        } elseif ($node instanceof \PhpJs\Ast\Declaration\VariableDeclaration) {
            foreach ($node->declarations as $decl) {
                if ($decl->init !== null) {
                    self::checkExprForWith($decl->init);
                }
            }
        } elseif ($node instanceof \PhpJs\Ast\Statement\ExpressionStatement) {
            self::checkExprForWith($node->expression);
        } elseif ($node instanceof \PhpJs\Ast\Declaration\FunctionDeclaration) {
            if ($node->body instanceof \PhpJs\Ast\Statement\BlockStatement) {
                foreach ($node->body->body as $child) {
                    self::checkNodeForWith($child);
                }
            }
        }
    }

    private static function checkExprForWith(\PhpJs\Ast\Node $expr): void
    {
        if (
            $expr instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $expr instanceof \PhpJs\Ast\Expression\ArrowFunction
        ) {
            if ($expr->body instanceof \PhpJs\Ast\Statement\BlockStatement) {
                foreach ($expr->body->body as $child) {
                    self::checkNodeForWith($child);
                }
            }
        } elseif ($expr instanceof \PhpJs\Ast\Expression\AssignmentExpression) {
            self::checkExprForWith($expr->right);
        }
    }
}
