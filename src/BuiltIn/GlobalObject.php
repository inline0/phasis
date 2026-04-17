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
            return $interp->execute($program);
        }, 1);
        $env->defineVar('eval', $evalFn);

        // encodeURIComponent / decodeURIComponent
        $encodeCompFn = JsFunction::fromCallable(
            'encodeURIComponent',
            function (JsValue $this_, array $args): JsValue {
                $str = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
                return new JsString(rawurlencode($str));
            },
            1,
        );
        $env->defineVar('encodeURIComponent', $encodeCompFn);
        $decodeCompFn = JsFunction::fromCallable(
            'decodeURIComponent',
            function (JsValue $this_, array $args): JsValue {
                $str = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
                return new JsString(rawurldecode($str));
            },
            1,
        );
        $env->defineVar('decodeURIComponent', $decodeCompFn);
        $encodeUriFn = JsFunction::fromCallable(
            'encodeURI',
            function (JsValue $this_, array $args): JsValue {
                $str = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
                $encoded = [
                    '%3A', '%2F', '%3F', '%23', '%5B', '%5D', '%40', '%21', '%24',
                    '%26', '%27', '%28', '%29', '%2A', '%2B', '%2C', '%3B', '%3D',
                ];
                $decoded = [
                    ':', '/', '?', '#', '[', ']', '@', '!', '$',
                    '&', "'", '(', ')', '*', '+', ',', ';', '=',
                ];
                return new JsString(str_replace($encoded, $decoded, rawurlencode($str)));
            },
            1,
        );
        $env->defineVar('encodeURI', $encodeUriFn);
        $decodeUriFn = JsFunction::fromCallable(
            'decodeURI',
            function (JsValue $this_, array $args): JsValue {
                $str = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            // decodeURI does not decode reserved URI characters.
                $reserved = [
                '%3A', '%2F', '%3F', '%23', '%5B', '%5D', '%40', '%21', '%24',
                '%26', '%27', '%28', '%29', '%2A', '%2B', '%2C', '%3B', '%3D',
                ];
            // Temporarily protect reserved sequences so rawurldecode does not touch them.
                $placeholders = [];
                foreach ($reserved as $i => $seq) {
                    $placeholders[$seq] = "\x00RESERVED{$i}\x00";
                }
                $protected = str_ireplace(array_keys($placeholders), array_values($placeholders), $str);
                $decoded = rawurldecode($protected);
                return new JsString(
                    str_replace(array_values($placeholders), array_keys($placeholders), $decoded),
                );
            },
            1
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

        // caller/arguments accessor: non-configurable per spec
        $throwerDesc = new \PhpJs\Object\PropertyDescriptor(
            enumerable: false,
            configurable: false,
            get: $thrower,
            set: $thrower,
        );
        $fnProto->defineOwnProperty('caller', $throwerDesc);
        $fnProto->defineOwnProperty('arguments', $throwerDesc);

        // Store %ThrowTypeError% so the arguments object creator can reuse
        // the same function identity per spec requirement.
        $env->defineVar('__ThrowTypeError__', $thrower);

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

            // Per spec 20.2.3.2, the bound function's length is max(0, target.length - |boundArgs|).
            // Handle Infinity/NaN correctly: do not cast to int.
            $boundLengthFloat = 0.0;
            $targetLengthVal = $target->get('length');
            if ($targetLengthVal instanceof JsNumber) {
                $tl = $targetLengthVal->value;
                if (!is_nan($tl)) {
                    $boundLengthFloat = max(0.0, $tl - count($boundArgs));
                }
            }
            // Determine target name for the bound function's name.
            $targetNameProp = $target->getOwnPropertyDescriptor('name');
            $targetName = $targetNameProp !== null && $targetNameProp->value instanceof JsString
                ? $targetNameProp->value->value
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
                        && $innerInterp !== null
                    ) {
                        // Called via new: construct the target with merged args.
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

            // Per spec, bound functions don't have their own prototype
            // property. The [[HasInstance]] check walks to the target.
            // Copy the target's prototype so instanceof works.
            $targetProto = $target->get('prototype');
            if ($targetProto instanceof \PhpJs\Value\JsObject) {
                $boundFn->set('prototype', $targetProto);
            }
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
        $env->defineVar('__IteratorPrototype__', $iteratorPrototype);

        // Set up %GeneratorFunction.prototype% and %GeneratorPrototype% intrinsics.
        // Per spec 27.3: GeneratorFunction.prototype [[Prototype]] = Function.prototype.
        // Per spec 27.1.2: %GeneratorPrototype% [[Prototype]] = %IteratorPrototype%.
        $generatorFunctionProto = JsFunction::fromCallable('', fn() => JsUndefined::instance());
        $generatorFunctionProto->setCustomPrototype($fnProto);
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
        // constructor: non-writable, non-enumerable, configurable, pointing to %GeneratorFunction.prototype%.
        $generatorPrototype->defineOwnProperty('constructor', \PhpJs\Object\PropertyDescriptor::data(
            $generatorFunctionProto,
            false,
            false,
            true,
        ));
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
        $generatorFunctionProto->defineOwnProperty('prototype', \PhpJs\Object\PropertyDescriptor::data(
            $generatorPrototype,
            true,
            false,
            false,
        ));
        // Register the intrinsic so JsGenerator can use it as fallback when fn.prototype is not an Object.
        \PhpJs\Value\JsGenerator::setGeneratorPrototype($generatorPrototype);
        // Store for interpreter access.
        $env->defineVar('__GeneratorPrototype__', $generatorPrototype);
        $env->defineVar('__GeneratorFunctionPrototype__', $generatorFunctionProto);
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
            $string = preg_replace(
                '/^' . $ws . '+|' . $ws . '+$/u',
                '',
                $string,
            );
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
            $string = preg_replace(
                '/^' . $ws . '+/u',
                '',
                $string,
            );

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
