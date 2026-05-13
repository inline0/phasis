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
        $env->defineVar('NaN', JsNumber::of(NAN));
        $env->defineVar('Infinity', JsNumber::of(INF));

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
            if (strlen($code->value) > 64 * 1024 * 1024) {
                throw new \PhpJs\Exceptions\SyntaxError('Source too large for eval');
            }
            $parser = new \PhpJs\Parser\Parser($code->value);
            // Allow `super` references at parse time: we can't statically
            // tell whether the eval is direct (inside a method) or indirect
            // (script top-level). The runtime raises a ReferenceError when
            // [[HomeObject]] is missing, so the invalid case is still caught.
            $parser->setInMethodLike(true);
            $program = $parser->parse();
            // Capture any `//# sourceURL=URL` directive comment so Errors
            // thrown (or `new Error().stack` reads) inside the eval'd code
            // surface the advertised URL in their stack trace.
            $sourceUrl = $parser->getSourceURL();
            if ($sourceUrl !== null) {
                \PhpJs\Engine::pushSourceURL($sourceUrl);
            }
            // Per spec: top-level break/continue/return in eval code is a
            // SyntaxError. Delegate to the interpreter's shared validator so
            // indirect and direct eval share the same early-error surface.
            // Save/restore JsFunction's interpreter statics so this sub-
            // interpreter does not clobber the outer one (e.g. its
            // currentModulePath used for relative module resolution).
            $prevInstance = JsFunction::getInterpreterInstance();
            $prevCallback = JsFunction::getInterpreterCallback();
            $interp = new Interpreter($env);
            try {
                $interp->validateEvalProgram($program);
                $interp->setEvalContext(true);
                return $interp->execute($program);
            } finally {
                if ($prevInstance !== null) {
                    JsFunction::setInterpreterInstance($prevInstance);
                }
                if ($prevCallback !== null) {
                    JsFunction::setInterpreterCallback($prevCallback);
                }
                if ($sourceUrl !== null) {
                    \PhpJs\Engine::popSourceURL();
                }
            }
        }, 1);
        $env->defineVar('eval', $evalFn);

        // encodeURIComponent / decodeURIComponent / encodeURI / decodeURI
        // Per spec sections 19.2.6.2-19.2.6.5, these functions operate on
        // UTF-16 code units and must throw URIError for lone surrogates
        // (encode) or malformed percent-encoded UTF-8 sequences (decode).

        // Inline a JsString short-circuit so the hot Sputnik decode sweep
        // (`decodeURI(hexB1_B2_B3_B4)`) skips the full TypeConversion::
        // toString dispatch when the argument is already a primitive
        // string, which it is for any code path that builds the input via
        // string concatenation. Falls back to the spec coercion for any
        // non-string argument.
        $encodeCompFn = JsFunction::fromCallable(
            'encodeURIComponent',
            function (JsValue $this_, array $args) use ($env): JsValue {
                if (!isset($args[0])) {
                    $str = 'undefined';
                } elseif ($args[0] instanceof JsString) {
                    $str = $args[0]->value;
                } else {
                    $str = TypeConversion::toString($args[0]);
                }
                return new JsString(self::specEncode($str, false, $env));
            },
            1,
        );
        $encodeCompFn->builtinKind = 'global.encodeURIComponent';
        $env->defineVar('encodeURIComponent', $encodeCompFn);
        $decodeCompFn = JsFunction::fromCallable(
            'decodeURIComponent',
            function (JsValue $this_, array $args) use ($env): JsValue {
                if (!isset($args[0])) {
                    $str = 'undefined';
                } elseif ($args[0] instanceof JsString) {
                    $str = $args[0]->value;
                } else {
                    $str = TypeConversion::toString($args[0]);
                }
                return new JsString(self::specDecode($str, false, $env));
            },
            1,
        );
        $decodeCompFn->builtinKind = 'global.decodeURIComponent';
        $env->defineVar('decodeURIComponent', $decodeCompFn);
        $encodeUriFn = JsFunction::fromCallable(
            'encodeURI',
            function (JsValue $this_, array $args) use ($env): JsValue {
                if (!isset($args[0])) {
                    $str = 'undefined';
                } elseif ($args[0] instanceof JsString) {
                    $str = $args[0]->value;
                } else {
                    $str = TypeConversion::toString($args[0]);
                }
                return new JsString(self::specEncode($str, true, $env));
            },
            1,
        );
        $encodeUriFn->builtinKind = 'global.encodeURI';
        $env->defineVar('encodeURI', $encodeUriFn);
        $decodeUriFn = JsFunction::fromCallable(
            'decodeURI',
            function (JsValue $this_, array $args) use ($env): JsValue {
                if (!isset($args[0])) {
                    $str = 'undefined';
                } elseif ($args[0] instanceof JsString) {
                    $str = $args[0]->value;
                } else {
                    $str = TypeConversion::toString($args[0]);
                }
                return new JsString(self::specDecode($str, true, $env));
            },
            1,
        );
        $decodeUriFn->builtinKind = 'global.decodeURI';
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
                            $result .= mb_chr($code, 'UTF-8');
                            $k += 6;
                            continue;
                        }
                        // Check for %XX (3 chars total)
                        if (
                            $k + 2 < $length
                            && ctype_xdigit($str[$k + 1])
                            && ctype_xdigit($str[$k + 2])
                        ) {
                            $code = (int) hexdec(substr($str, $k + 1, 2));
                            $result .= mb_chr($code, 'UTF-8');
                            $k += 3;
                            continue;
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
            // Per spec steps 17-18, parse params independently so comment
            // tricks like `new Function("/*", "*/) {")` cannot smuggle the
            // close-paren into the body. ParseText(parameters, FormalParameters)
            // must parse to completion on its own; we approximate that by
            // wrapping in `(function (${params}){})` and asserting it parses.
                $paramProbe = "(function ({$params}\n) {})";
                try {
                    (new \PhpJs\Parser\Parser($paramProbe))->parse();
                } catch (\Throwable $e) {
                    throw new \PhpJs\Exceptions\SyntaxError('Function parameter list is invalid');
                }
            // The body gets line feeds per step 41 so AnnexB HTML comments work.
            // The function is anonymous (no named binding visible in scope) —
            // we set .name = "anonymous" after creation rather than parsing
            // as `function anonymous(){...}`, since the latter would make
            // "anonymous" a self-reference inside the body.
                $source = "(function ({$params}\n) {\n{$body}\n})";
                $parser = new \PhpJs\Parser\Parser($source);
                $program = $parser->parse();

            // Per spec 20.2.1.1 step 20c-d, detect strict mode in the
            // body and validate strict-mode early errors (with statement,
            // duplicate params, etc.).
                self::validateDynamicFunction($program, $params);
                // Per spec step 30: AllPrivateIdentifiersValid fails because
                // there is no enclosing class scope here — any #name reference
                // is a SyntaxError.
                self::rejectPrivateIdentifiersInProgram($program);

            // Use the global environment so the created function can see
            // global variables (per spec: "scope chain consisting of the
            // global object"). Creating a sub-Interpreter would clobber
            // the shared JsFunction interpreter instance/callback statics,
            // so save and restore them around the dynamic execution.
                $prevInstance = JsFunction::getInterpreterInstance();
                $prevCallback = JsFunction::getInterpreterCallback();
                $interp = new Interpreter($env);
                try {
                    $result = $interp->execute($program);
                } finally {
                    if ($prevInstance !== null) {
                        JsFunction::setInterpreterInstance($prevInstance);
                    }
                    if ($prevCallback !== null) {
                        JsFunction::setInterpreterCallback($prevCallback);
                    }
                }
                // Honor new.target.prototype for subclassed Function constructor.
                if ($result instanceof JsFunction && $this_ instanceof \PhpJs\Value\JsObject && $this_->has('[[NewTarget]]')) {
                    $newTarget = $this_->get('[[NewTarget]]');
                    if ($newTarget instanceof JsFunction) {
                        $ntProto = $newTarget->get('prototype');
                        if ($ntProto instanceof \PhpJs\Value\JsObject) {
                            $result->setPrototype($ntProto);
                        }
                    }
                }
                // Spec §20.2.1.1 step 31: SetFunctionName(F, "anonymous").
                // The body never sees "anonymous" as an identifier binding;
                // it is purely the .name property. Set the source text so
                // Function.prototype.toString returns the spec-mandated form
                // (`function anonymous(params) { body }`) even though the
                // function was parsed as an unnamed expression.
                if ($result instanceof JsFunction) {
                    $result->defineOwnProperty('name', \PhpJs\Object\PropertyDescriptor::data(
                        new JsString('anonymous'),
                        false,
                        false,
                        true,
                    ));
                    $result->setSourceText("function anonymous({$params}\n) {\n{$body}\n}");
                }
                return $result;
            },
            1,
        );

        // Function.prototype with call/apply/bind
        $fnProto = JsFunction::fromCallable('', fn() => JsUndefined::instance());

        // Set Function.prototype as [[Prototype]] for all future JsFunction instances.
        // This must happen before any further JsFunction creation so that
        // Object.getPrototypeOf(anyFn) === Function.prototype holds.
        JsFunction::setFunctionPrototype($fnProto);
        // Stash on the env so JsFunction::getPrototype can look up the
        // per-realm Function.prototype via this->realm->getGlobalEnv()
        // even after a sibling Engine has overwritten the static.
        $env->defineVar('__FunctionPrototype__', $fnProto);

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
            \PhpJs\Object\PropertyDescriptor::data(JsNumber::of(0.0), false, false, false),
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
                // Dense JsArray fast path: extract the packed slot
                // array directly. CreateListFromArrayLike normally
                // walks `length` worth of `(string) $i` get()s — for
                // a 10k-element array that's 10k hash lookups plus
                // string allocations. For a 10000-element apply call
                // this dominates the test262 CharacterClassEscapes
                // harness. The dense slot array already has the
                // values in iteration order.
                if (
                    $argsArr instanceof \PhpJs\Value\JsArray
                    && $argsArr->isDenseMode()
                ) {
                    $len = $argsArr->getLength();
                    $dense = $argsArr->getDenseElements();
                    if ($len > 0) {
                        $allDense = true;
                        for ($i = 0; $i < $len; $i++) {
                            if (!isset($dense[$i])) {
                                $allDense = false;
                                break;
                            }
                        }
                        if ($allDense) {
                            // Reuse the slot array when contiguous.
                            $callArgs = $len === count($dense)
                                ? array_values($dense)
                                : array_slice($dense, 0, $len);
                            return $this_->call($thisArg, $callArgs);
                        }
                    } else {
                        return $this_->call($thisArg, []);
                    }
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
            // Spec §20.2.3.2 step 1 only requires IsCallable(Target). A Proxy
            // whose target has [[Call]] qualifies as callable, even though our
            // engine represents it as JsProxy rather than JsFunction. Only
            // throw when the receiver is genuinely non-callable.
            $isCallable = $this_ instanceof JsFunction
                || ($this_ instanceof \PhpJs\Value\JsProxy && $this_->isCallable());
            if (!$isCallable) {
                throw new \PhpJs\Exceptions\TypeError('bind called on non-function');
            }
            if (!$this_ instanceof JsFunction) {
                // Wrap the callable proxy in a JsFunction shim so the rest of
                // the bind path (which manipulates length/name/prototype) can
                // operate uniformly. The shim forwards [[Call]] / [[Construct]]
                // to the proxy.
                $proxy = $this_;
                $shim = JsFunction::fromCallable('', function (JsValue $thisArg, array $cargs) use ($proxy): JsValue {
                    return $proxy->apply($thisArg, $cargs);
                });
                if ($proxy->isConstructable()) {
                    $shim->setConstructable();
                }
                // Mirror length / name from the proxy so bind's spec steps 5-9
                // observe the proxy's own values.
                try {
                    $proxyLen = $proxy->get('length');
                    $shim->defineOwnProperty('length', new \PhpJs\Object\PropertyDescriptor(
                        value: $proxyLen,
                        writable: false,
                        enumerable: false,
                        configurable: true,
                    ));
                } catch (\Throwable) {
                }
                try {
                    $proxyName = $proxy->get('name');
                    $shim->defineOwnProperty('name', new \PhpJs\Object\PropertyDescriptor(
                        value: $proxyName,
                        writable: false,
                        enumerable: false,
                        configurable: true,
                    ));
                } catch (\Throwable) {
                }
                $this_ = $shim;
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
            // Use a box to hold a self-reference so the closure can compare
            // its own JsFunction identity against the active newTarget per
            // §10.4.1.2 step 4 (SameValue(F, newTarget) → use bound target).
            $selfRef = new class () {
                public ?JsFunction $fn = null;
            };
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
                    $isConstructable,
                    $selfRef
                ): JsValue {
                    $mergedArgs = array_merge($boundArgs, $callArgs);
                    // Detect if called as constructor (th has [[NewTarget]]).
                    if (
                        $isConstructable
                        && $th instanceof \PhpJs\Value\JsObject
                        && !($th->get('[[NewTarget]]') instanceof JsUndefined)
                    ) {
                        if ($innerInterp !== null) {
                            // Per §10.4.1.2 [[Construct]] for BoundFunction:
                            // step 4. If SameValue(F, newTarget) is true, set
                            // newTarget to target. This causes `new B()` where
                            // `B = A.bind()` to pass newTarget=A to A's body.
                            $activeNewTarget = $th->get('[[NewTarget]]');
                            if (
                                $selfRef->fn !== null
                                && $activeNewTarget === $selfRef->fn
                            ) {
                                $activeNewTarget = $target;
                            }
                            // Forward the (possibly replaced) newTarget so a
                            // subclass constructor's prototype takes effect
                            // (e.g. `class C extends bound {}`).
                            $ntObj = $activeNewTarget instanceof \PhpJs\Value\JsObject
                                ? $activeNewTarget
                                : $target;
                            // Per GetPrototypeFromConstructor: when
                            // newTarget.prototype is not an Object, fall back
                            // to GetFunctionRealm(newTarget)'s %Object.prototype%.
                            $useProto = \PhpJs\Spec\AbstractOperations::getPrototypeFromConstructor(
                                $ntObj,
                                static fn ($env) => \PhpJs\Spec\AbstractOperations::realmIntrinsicPrototype($env, 'Object'),
                            );
                            if ($useProto === null) {
                                $tp = $target->get('prototype');
                                $useProto = $tp instanceof \PhpJs\Value\JsObject ? $tp : null;
                            }
                            $newObj = new \PhpJs\Value\JsObject($useProto);
                            $newObj->defineOwnProperty(
                                '[[NewTarget]]',
                                \PhpJs\Object\PropertyDescriptor::data($ntObj, false, false, false),
                            );
                            $result = $innerInterp->callFunction($target, $newObj, $mergedArgs);
                            return $result instanceof \PhpJs\Value\JsObject ? $result : $newObj;
                        }
                        // Called via construct path without interpreter (e.g.
                        // proxy forwarding). Construct manually so the user's
                        // newTarget (extracted from $th's [[NewTarget]] slot
                        // above) is honoured rather than collapsing to the
                        // bound target's prototype.
                        $activeNewTarget = $th->get('[[NewTarget]]');
                        if (
                            $selfRef->fn !== null
                            && $activeNewTarget === $selfRef->fn
                        ) {
                            $activeNewTarget = $target;
                        }
                        $ntObj = $activeNewTarget instanceof \PhpJs\Value\JsObject
                            ? $activeNewTarget
                            : $target;
                        // Per GetPrototypeFromConstructor: when
                        // newTarget.prototype is not an Object, fall back
                        // to GetFunctionRealm(newTarget)'s %Object.prototype%.
                        $useProto = \PhpJs\Spec\AbstractOperations::getPrototypeFromConstructor(
                            $ntObj,
                            static fn ($env) => \PhpJs\Spec\AbstractOperations::realmIntrinsicPrototype($env, 'Object'),
                        );
                        if ($useProto === null) {
                            $tp = $target->get('prototype');
                            $useProto = $tp instanceof \PhpJs\Value\JsObject ? $tp : null;
                        }
                        $newObj = new \PhpJs\Value\JsObject($useProto);
                        $newObj->defineOwnProperty(
                            '[[NewTarget]]',
                            \PhpJs\Object\PropertyDescriptor::data($ntObj, false, false, false),
                        );
                        $result = $target->call($newObj, $mergedArgs);
                        if ($result instanceof \PhpJs\Value\JsObject) {
                            $result->forceDelete('[[NewTarget]]');
                            return $result;
                        }
                        $newObj->forceDelete('[[NewTarget]]');
                        return $newObj;
                    }
                    return $target->call($boundThis, $mergedArgs);
                },
                0,
            );
            $selfRef->fn = $boundFn;

            // Always override the length property with the computed bound length.
            $boundFn->defineOwnProperty('length', new \PhpJs\Object\PropertyDescriptor(
                value: JsNumber::of($boundLengthFloat),
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
            // Per spec 10.4.1 BoundFunctionCreate step 2: set the bound
            // function's [[Prototype]] to OrdinaryGetPrototypeOf(Target).
            // Without this `class C extends Function {}; new C(...).bind(...)`
            // produces a bound function whose proto is Function.prototype
            // instead of C.prototype, breaking instanceof.
            $targetProto = $target->getPrototype();
            if ($targetProto !== null) {
                $boundFn->setCustomPrototype($targetProto);
            } else {
                // Target had a null [[Prototype]] (e.g. via setPrototypeOf(fn, null)).
                // Bound function inherits null prototype too.
                $boundFn->setPrototype(null);
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
                $isCallable = $this_ instanceof JsFunction
                    || ($this_ instanceof \PhpJs\Value\JsProxy && $this_->isCallable());
                if (!$isCallable) {
                    return new JsBoolean(false);
                }
                $value = $args[0] ?? JsUndefined::instance();
                if (!$value instanceof \PhpJs\Value\JsObject) {
                    return new JsBoolean(false);
                }
                // Per spec 7.3.22 OrdinaryHasInstance step 2: if F has a
                // [[BoundTargetFunction]], resolve through to the original target.
                // Proxies don't have a bound-target slot.
                $target = $this_;
                if ($target instanceof JsFunction) {
                    while ($target->getBoundTarget() !== null) {
                        $target = $target->getBoundTarget();
                    }
                }
                // Get target.prototype. For a Proxy this fires the `get` trap
                // with key="prototype", as required by the spec test fixture.
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
        $env->defineVar('__IteratorPrototype__', $iteratorPrototype);

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
                $source = "(function* anonymous({$params}\n) {\n{$body}\n})";
                $parser = new \PhpJs\Parser\Parser($source);
                $program = $parser->parse();
                // Per spec step 20: parameters Contains YieldExpression must throw.
                self::rejectYieldAwaitInParams($program);
                $prevInstance = JsFunction::getInterpreterInstance();
                $prevCallback = JsFunction::getInterpreterCallback();
                $interp = new Interpreter($env);
                try {
                    return $interp->execute($program);
                } finally {
                    if ($prevInstance !== null) {
                        JsFunction::setInterpreterInstance($prevInstance);
                    }
                    if ($prevCallback !== null) {
                        JsFunction::setInterpreterCallback($prevCallback);
                    }
                }
            },
            1,
        );
        $genFnConstructor->setConstructable();
        // Per spec 27.3.2: GeneratorFunction.length = 1.
        $genFnConstructor->defineOwnProperty('length', new \PhpJs\Object\PropertyDescriptor(
            value: JsNumber::of(1.0),
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
        $env->defineVar('__GeneratorPrototype__', $generatorPrototype);
        $env->defineVar('__GeneratorFunctionPrototype__', $generatorFunctionProto);
        $env->defineVar('GeneratorFunction', $genFnConstructor);

        // Set up %AsyncIteratorPrototype%, %AsyncGeneratorPrototype%, %AsyncGeneratorFunction%.
        $asyncIteratorPrototype = new \PhpJs\Value\JsObject();
        $asyncIteratorPrototype->definePropertyBySymbol(
            \PhpJs\BuiltIn\SymbolConstructor::asyncIterator(),
            \PhpJs\Object\PropertyDescriptor::data(
                JsFunction::fromCallable('[Symbol.asyncIterator]', static function (JsValue $this_, array $args): JsValue {
                    return $this_;
                }, 0),
                true,
                false,
                true,
            ),
        );
        // %AsyncIteratorPrototype%[@@asyncDispose] per explicit-resource-management.
        // Calls this.return() and wraps the result in a Promise.
        $asyncDisposeSym = \PhpJs\BuiltIn\SymbolConstructor::asyncDispose();
        $asyncIteratorPrototype->definePropertyBySymbol(
            $asyncDisposeSym,
            \PhpJs\Object\PropertyDescriptor::data(
                JsFunction::fromCallable(
                    '[Symbol.asyncDispose]',
                    static function (JsValue $this_, array $args): JsValue {
                        if (!$this_ instanceof \PhpJs\Value\JsObject) {
                            throw new \PhpJs\Exceptions\TypeError(
                                'Symbol.asyncDispose called on non-object'
                            );
                        }
                        $returnFn = $this_->get('return');
                        if (
                            $returnFn instanceof \PhpJs\Value\JsUndefined
                            || $returnFn instanceof \PhpJs\Value\JsNull
                        ) {
                            return \PhpJs\Value\JsPromise::resolved(
                                \PhpJs\Value\JsUndefined::instance()
                            );
                        }
                        if (!$returnFn instanceof JsFunction) {
                            throw new \PhpJs\Exceptions\TypeError(
                                'return is not a function'
                            );
                        }
                        $result = $returnFn->call($this_, []);
                        return \PhpJs\Value\JsPromise::resolved($result);
                    },
                    0,
                ),
                true,
                false,
                true,
            ),
        );
        $env->defineVar('__AsyncIteratorPrototype__', $asyncIteratorPrototype);

        $asyncGenFnProto = new \PhpJs\Value\JsObject($fnProto);
        JsFunction::setAsyncGeneratorFunctionPrototype($asyncGenFnProto);

        $asyncGenProto = new \PhpJs\Value\JsObject($asyncIteratorPrototype);
        $asyncGenProto->definePropertyBySymbol(
            \PhpJs\BuiltIn\SymbolConstructor::toStringTag(),
            \PhpJs\Object\PropertyDescriptor::data(new JsString('AsyncGenerator'), false, false, true),
        );
        $asyncGenProto->defineOwnProperty('next', \PhpJs\Object\PropertyDescriptor::data(
            JsFunction::fromCallable('next', function (JsValue $thisValue, array $args) use ($env): JsValue {
                if (!$thisValue instanceof \PhpJs\Value\JsAsyncGenerator) {
                    return \PhpJs\Value\JsPromise::rejected(
                        \PhpJs\Value\JsAsyncGenerator::makeIncompatibleReceiverError($env, 'next')
                    );
                }
                return $thisValue->next($args[0] ?? JsUndefined::instance());
            }, 1),
            true,
            false,
            true,
        ));
        $asyncGenProto->defineOwnProperty('return', \PhpJs\Object\PropertyDescriptor::data(
            JsFunction::fromCallable('return', function (JsValue $thisValue, array $args) use ($env): JsValue {
                if (!$thisValue instanceof \PhpJs\Value\JsAsyncGenerator) {
                    return \PhpJs\Value\JsPromise::rejected(
                        \PhpJs\Value\JsAsyncGenerator::makeIncompatibleReceiverError($env, 'return')
                    );
                }
                return $thisValue->returnValue($args[0] ?? JsUndefined::instance());
            }, 1),
            true,
            false,
            true,
        ));
        $asyncGenProto->defineOwnProperty('throw', \PhpJs\Object\PropertyDescriptor::data(
            JsFunction::fromCallable('throw', function (JsValue $thisValue, array $args) use ($env): JsValue {
                if (!$thisValue instanceof \PhpJs\Value\JsAsyncGenerator) {
                    return \PhpJs\Value\JsPromise::rejected(
                        \PhpJs\Value\JsAsyncGenerator::makeIncompatibleReceiverError($env, 'throw')
                    );
                }
                return $thisValue->throwValue($args[0] ?? JsUndefined::instance());
            }, 1),
            true,
            false,
            true,
        ));
        $asyncGenFnProto->defineOwnProperty('prototype', \PhpJs\Object\PropertyDescriptor::data($asyncGenProto, false, false, true));
        $asyncGenProto->defineOwnProperty('constructor', \PhpJs\Object\PropertyDescriptor::data($asyncGenFnProto, false, false, true));
        $asyncGenFnProto->definePropertyBySymbol(
            \PhpJs\BuiltIn\SymbolConstructor::toStringTag(),
            \PhpJs\Object\PropertyDescriptor::data(new JsString('AsyncGeneratorFunction'), false, false, true),
        );

        // %AsyncGeneratorFunction% constructor: like Function() but for async generators.
        $asyncGenFnConstructor = JsFunction::fromCallable(
            'AsyncGeneratorFunction',
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
                $source = "(async function* anonymous({$params}\n) {\n{$body}\n})";
                $parser = new \PhpJs\Parser\Parser($source);
                $program = $parser->parse();
                // Per spec: params for async generator cannot contain YieldExpression
                // or AwaitExpression. The main parse already allows yield/await in
                // async-generator params, so reject them with a post-parse scan.
                self::rejectYieldAwaitInParams($program);
                $prevInstance = JsFunction::getInterpreterInstance();
                $prevCallback = JsFunction::getInterpreterCallback();
                $interp = new Interpreter($env);
                try {
                    return $interp->execute($program);
                } finally {
                    if ($prevInstance !== null) {
                        JsFunction::setInterpreterInstance($prevInstance);
                    }
                    if ($prevCallback !== null) {
                        JsFunction::setInterpreterCallback($prevCallback);
                    }
                }
            },
            1,
        );
        $asyncGenFnConstructor->setConstructable();
        $asyncGenFnConstructor->defineOwnProperty('length', new \PhpJs\Object\PropertyDescriptor(
            value: JsNumber::of(1.0),
            writable: false,
            enumerable: false,
            configurable: true,
        ));
        $asyncGenFnConstructor->defineOwnProperty('name', new \PhpJs\Object\PropertyDescriptor(
            value: new JsString('AsyncGeneratorFunction'),
            writable: false,
            enumerable: false,
            configurable: true,
        ));
        $asyncGenFnConstructor->defineOwnProperty('prototype', new \PhpJs\Object\PropertyDescriptor(
            value: $asyncGenFnProto,
            writable: false,
            enumerable: false,
            configurable: false,
        ));
        $asyncGenFnConstructor->setCustomPrototype($fnConstructor);
        // %AsyncGeneratorFunction.prototype%.constructor = %AsyncGeneratorFunction%
        $asyncGenFnProto->defineOwnProperty('constructor', \PhpJs\Object\PropertyDescriptor::data(
            $asyncGenFnConstructor,
            false,
            false,
            true,
        ));

        \PhpJs\Value\JsAsyncGenerator::setAsyncGeneratorPrototype($asyncGenProto);
        $env->defineVar('__AsyncGeneratorPrototype__', $asyncGenProto);
        $env->defineVar('__AsyncGeneratorFunctionPrototype__', $asyncGenFnProto);
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
                return JsNumber::of(NAN);
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
                return JsNumber::of(NAN);
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
                return JsNumber::of(NAN);
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
            return JsNumber::of($negative ? -$value : $value);
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
                return JsNumber::of(NAN);
            }

            // Check for Infinity prefix (not exact match).
            if (
                str_starts_with($string, 'Infinity')
                || str_starts_with($string, '+Infinity')
            ) {
                return JsNumber::of(INF);
            }
            if (str_starts_with($string, '-Infinity')) {
                return JsNumber::of(-INF);
            }

            if (
                preg_match(
                    '/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/',
                    $string,
                    $matches,
                )
            ) {
                return JsNumber::of((float) $matches[0]);
            }

            return JsNumber::of(NAN);
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
            // Per spec 22.1.1.1 step 2: when called as a function (no new.target),
            // a Symbol argument becomes its descriptive string. When called as a
            // constructor (new String(sym)), ToString(sym) still throws TypeError.
            $isConstruct = $this_ instanceof \PhpJs\Value\JsObject && $this_->has('[[NewTarget]]');
            if (!empty($args) && $args[0] instanceof \PhpJs\Value\JsSymbol && !$isConstruct) {
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
                $val = JsNumber::of($num);
                $this_->defineOwnProperty(
                    '[[PrimitiveValue]]',
                    \PhpJs\Object\PropertyDescriptor::data($val, false, false, false),
                );
                return $this_;
            }
            return JsNumber::of($num);
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
     * Lookup table mapping each two-character hex pair (e.g. "F0", "0a")
     * to its byte value (0..255). Built lazily on first decode call so we
     * can replace per-byte hexdec/ctype_xdigit calls with a single isset
     * + array read in the hot path.
     *
     * @var array<string, int>|null
     */
    private static ?array $hexPairToByte = null;

    /**
     * Reserved-byte lookup for decodeURI. Built lazily; decodeURIComponent
     * never consults it.
     *
     * @var array<int, bool>|null
     */
    private static ?array $uriReservedBytes = null;

    /**
     * Spec-compliant Decode(string, reservedSet) per ES2023 section 19.2.6.1.2.
     *
     * Hot path optimisations:
     *   - bulk-copy ASCII runs between `%` markers via strpos rather than
     *     iterating one byte at a time;
     *   - decode hex pairs through a precomputed 256-entry lookup table to
     *     avoid hexdec/ctype_xdigit per byte;
     *   - encode the resulting codepoint to UTF-8 / CESU-8 inline with
     *     chr() rather than calling JsString::utf16CodeUnitToUtf8 (which
     *     dispatches to mb_chr).
     *
     * @param bool $isUri true for decodeURI (preserves reserved), false for decodeURIComponent
     */
    private static function specDecode(string $str, bool $isUri, Environment $env): string
    {
        $hexMap = self::$hexPairToByte;
        if ($hexMap === null) {
            $hexMap = [];
            $digits = '0123456789ABCDEFabcdef';
            for ($hi = 0; $hi < 22; $hi++) {
                for ($lo = 0; $lo < 22; $lo++) {
                    $value = (($hi < 16 ? $hi : $hi - 6) << 4)
                        | ($lo < 16 ? $lo : $lo - 6);
                    $hexMap[$digits[$hi] . $digits[$lo]] = $value;
                }
            }
            self::$hexPairToByte = $hexMap;
        }

        if ($isUri) {
            $reservedBytes = self::$uriReservedBytes;
            if ($reservedBytes === null) {
                $reservedBytes = [];
                foreach ([';', '/', '?', ':', '@', '&', '=', '+', '$', ',', '#'] as $ch) {
                    $reservedBytes[ord($ch)] = true;
                }
                self::$uriReservedBytes = $reservedBytes;
            }
        } else {
            $reservedBytes = null;
        }

        $len = strlen($str);
        if ($len === 0) {
            return '';
        }

        // Fast path: no '%' at all means the input has no escapes to decode.
        // Per spec we still need to reject any byte > 0x7F that isn't part of
        // a percent-encoded sequence? No: the spec accepts non-ASCII input
        // verbatim (Decode walks code units, only %-prefixed triplets are
        // interpreted). We can return as-is.
        $firstPercent = strpos($str, '%');
        if ($firstPercent === false) {
            return $str;
        }

        // Ultra-fast path: exactly 12 characters of the shape `%XX%XX%XX%XX`
        // encoding a single 4-byte UTF-8 sequence in the supplementary plane.
        // Sputnik's S15.1.3.1_A2.5_T1 / S15.1.3.2_A2.5_T1 stress tests fire
        // ~1M of these per run. Skipping the generic walker (hex map
        // indirection, byte-at-a-time validation, six chr() concatenations)
        // drops the inner loop cost dramatically. Falls through to the spec
        // path on any mismatch so semantics never diverge; the spec walker
        // still owns every error case.
        if (
            $len === 12
            && $firstPercent === 0
            && $str[3] === '%'
            && $str[6] === '%'
            && $str[9] === '%'
        ) {
            $bytes = @hex2bin(
                $str[1] . $str[2]
                . $str[4] . $str[5]
                . $str[7] . $str[8]
                . $str[10] . $str[11]
            );
            if ($bytes !== false && strlen($bytes) === 4) {
                $b0 = ord($bytes[0]);
                if (($b0 & 0xF8) === 0xF0) {
                    $b1 = ord($bytes[1]);
                    $b2 = ord($bytes[2]);
                    $b3 = ord($bytes[3]);
                    if (
                        ($b1 & 0xC0) === 0x80
                        && ($b2 & 0xC0) === 0x80
                        && ($b3 & 0xC0) === 0x80
                    ) {
                        $codePoint = (($b0 & 0x07) << 18)
                            | (($b1 & 0x3F) << 12)
                            | (($b2 & 0x3F) << 6)
                            | ($b3 & 0x3F);
                        if ($codePoint >= 0x10000 && $codePoint <= 0x10FFFF) {
                            // Encode as a CESU-8 surrogate pair (two 3-byte
                            // sequences) so JsString sees UTF-16 code units.
                            $cp = $codePoint - 0x10000;
                            $hi = 0xD800 | ($cp >> 10);
                            $lo = 0xDC00 | ($cp & 0x3FF);
                            return chr(0xE0 | ($hi >> 12))
                                . chr(0x80 | (($hi >> 6) & 0x3F))
                                . chr(0x80 | ($hi & 0x3F))
                                . chr(0xE0 | ($lo >> 12))
                                . chr(0x80 | (($lo >> 6) & 0x3F))
                                . chr(0x80 | ($lo & 0x3F));
                        }
                    }
                }
            }
            // Fall through to spec walker for the error / out-of-range branches.
        }

        $result = $firstPercent > 0 ? substr($str, 0, $firstPercent) : '';
        $k = $firstPercent;

        while ($k < $len) {
            // '%' at position $k. Read the percent-encoded byte via hex map.
            if ($k + 2 >= $len) {
                self::throwURIError('URI malformed', $env);
            }
            $start = $k;
            $pair = $str[$k + 1] . $str[$k + 2];
            if (!isset($hexMap[$pair])) {
                self::throwURIError('URI malformed', $env);
            }
            $b = $hexMap[$pair];
            $k += 3;

            if ($b < 0x80) {
                // Single-byte ASCII. decodeURI keeps reserved bytes encoded.
                if ($reservedBytes !== null && isset($reservedBytes[$b])) {
                    $result .= substr($str, $start, 3);
                } else {
                    $result .= chr($b);
                }
            } else {
                // Multi-byte UTF-8 sequence. Determine expected length.
                if (($b & 0xE0) === 0xC0) {
                    $n = 2;
                } elseif (($b & 0xF0) === 0xE0) {
                    $n = 3;
                } elseif (($b & 0xF8) === 0xF0) {
                    $n = 4;
                } else {
                    self::throwURIError('URI malformed', $env);
                }

                // Read the remaining $n-1 continuation bytes, scalar-only.
                $b1 = 0;
                $b2 = 0;
                $b3 = 0;
                for ($j = 1; $j < $n; $j++) {
                    if ($k + 2 >= $len || $str[$k] !== '%') {
                        self::throwURIError('URI malformed', $env);
                    }
                    $cpair = $str[$k + 1] . $str[$k + 2];
                    if (!isset($hexMap[$cpair])) {
                        self::throwURIError('URI malformed', $env);
                    }
                    $cb = $hexMap[$cpair];
                    if (($cb & 0xC0) !== 0x80) {
                        self::throwURIError('URI malformed', $env);
                    }
                    if ($j === 1) {
                        $b1 = $cb;
                    } elseif ($j === 2) {
                        $b2 = $cb;
                    } else {
                        $b3 = $cb;
                    }
                    $k += 3;
                }

                if ($n === 2) {
                    $codePoint = (($b & 0x1F) << 6) | ($b1 & 0x3F);
                    if ($codePoint < 0x80) {
                        self::throwURIError('URI malformed', $env);
                    }
                } elseif ($n === 3) {
                    $codePoint = (($b & 0x0F) << 12) | (($b1 & 0x3F) << 6) | ($b2 & 0x3F);
                    if ($codePoint < 0x800) {
                        self::throwURIError('URI malformed', $env);
                    }
                    if ($codePoint >= 0xD800 && $codePoint <= 0xDFFF) {
                        self::throwURIError('URI malformed', $env);
                    }
                } else {
                    $codePoint = (($b & 0x07) << 18) | (($b1 & 0x3F) << 12)
                        | (($b2 & 0x3F) << 6) | ($b3 & 0x3F);
                    if ($codePoint < 0x10000 || $codePoint > 0x10FFFF) {
                        self::throwURIError('URI malformed', $env);
                    }
                }

                // Inline UTF-8 / CESU-8 encoding so we never call mb_chr in
                // the hot loop (mb_chr dispatches through a slower path even
                // for plain BMP codepoints).
                if ($codePoint <= 0x7FF) {
                    // 2-byte UTF-8 sequence.
                    $result .= chr(0xC0 | ($codePoint >> 6))
                        . chr(0x80 | ($codePoint & 0x3F));
                } elseif ($codePoint <= 0xFFFF) {
                    // 3-byte UTF-8 sequence (BMP non-surrogate).
                    $result .= chr(0xE0 | ($codePoint >> 12))
                        . chr(0x80 | (($codePoint >> 6) & 0x3F))
                        . chr(0x80 | ($codePoint & 0x3F));
                } else {
                    // Supplementary plane: encode as a CESU-8 surrogate pair
                    // (two 3-byte sequences) so JsString sees UTF-16 code
                    // units consistently.
                    $cp = $codePoint - 0x10000;
                    $hi = 0xD800 | ($cp >> 10);
                    $lo = 0xDC00 | ($cp & 0x3FF);
                    $result .= chr(0xE0 | ($hi >> 12))
                        . chr(0x80 | (($hi >> 6) & 0x3F))
                        . chr(0x80 | ($hi & 0x3F))
                        . chr(0xE0 | ($lo >> 12))
                        . chr(0x80 | (($lo >> 6) & 0x3F))
                        . chr(0x80 | ($lo & 0x3F));
                }
            }

            // Bulk-copy the ASCII run between this position and the next '%'.
            if ($k >= $len) {
                break;
            }
            $next = strpos($str, '%', $k);
            if ($next === false) {
                $result .= substr($str, $k);
                break;
            }
            if ($next > $k) {
                $result .= substr($str, $k, $next - $k);
            }
            $k = $next;
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
    /** Public wrapper for Engine.php to reach rejectYieldAwaitInParams. */
    public static function rejectYieldAwaitInParamsPublic(\PhpJs\Ast\Program $program): void
    {
        self::rejectYieldAwaitInParams($program);
    }

    /** Public wrapper for Engine.php to reach rejectPrivateIdentifiersInProgram. */
    public static function rejectPrivateIdentifiersInProgramPublic(\PhpJs\Ast\Program $program): void
    {
        self::rejectPrivateIdentifiersInProgram($program);
    }

    /**
     * Validate AllPrivateIdentifiersValid for a Program: every PrivateName
     * reference must be lexically enclosed by a class that declares (in
     * itself or an enclosing class) that private name.
     */
    private static function rejectPrivateIdentifiersInProgram(\PhpJs\Ast\Program $program): void
    {
        $stack = [];
        self::validatePrivateIdentifiersInNode($program, $stack);
    }

    /**
     * @param array<int,array<string,bool>> $stack
     */
    private static function validatePrivateIdentifiersInNode(?\PhpJs\Ast\Node $node, array $stack): void
    {
        if ($node === null) {
            return;
        }
        if ($node instanceof \PhpJs\Ast\Expression\PrivateIdentifier) {
            // Top-level (outside any class) reference is always invalid.
            if (empty($stack)) {
                throw new \PhpJs\Exceptions\SyntaxError(
                    'Private identifiers are not allowed outside of a class body'
                );
            }
            // Look up the chain of enclosing class private-name sets.
            $name = ltrim($node->name, '#');
            foreach (array_reverse($stack) as $names) {
                if (isset($names[$name])) {
                    return;
                }
            }
            throw new \PhpJs\Exceptions\SyntaxError(
                "Private field '#{$name}' must be declared in an enclosing class"
            );
        }
        if (
            $node instanceof \PhpJs\Ast\Expression\ClassExpression
            || $node instanceof \PhpJs\Ast\Declaration\ClassDeclaration
        ) {
            $declared = self::collectDeclaredPrivateNames($node);
            $stack[] = $declared;
            // Walk superclass with parent stack (it is parsed outside this
            // class body's scope).
            if ($node->superClass !== null) {
                self::validatePrivateIdentifiersInNode($node->superClass, array_slice($stack, 0, -1));
            }
            // Walk class body with this class's names visible.
            foreach ($node->body as $element) {
                self::validatePrivateIdentifiersInNode($element, $stack);
            }
            return;
        }
        $reflection = new \ReflectionObject($node);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof \PhpJs\Ast\Node) {
                self::validatePrivateIdentifiersInNode($value, $stack);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof \PhpJs\Ast\Node) {
                        self::validatePrivateIdentifiersInNode($item, $stack);
                    }
                }
            }
        }
    }

    /**
     * Collect all PrivateName names declared as elements of the given
     * class body (own scope only — does not descend into nested classes).
     *
     * @return array<string,bool>
     */
    private static function collectDeclaredPrivateNames(\PhpJs\Ast\Node $classNode): array
    {
        $names = [];
        $body = $classNode->body ?? [];
        foreach ($body as $element) {
            // MethodDefinition / FieldDefinition / etc. carry a `key` field
            // that can be a PrivateIdentifier when the member is private.
            if (isset($element->key) && $element->key instanceof \PhpJs\Ast\Expression\PrivateIdentifier) {
                $names[ltrim($element->key->name, '#')] = true;
            }
        }
        return $names;
    }

    /**
     * Walk the formal parameters of a parsed dynamic-function Program looking
     * for YieldExpression or AwaitExpression nodes. The dynamic
     * AsyncGeneratorFunction and GeneratorFunction constructors must reject
     * such expressions in their parameter lists per spec CreateDynamicFunction
     * step 28/29.
     */
    private static function rejectYieldAwaitInParams(\PhpJs\Ast\Program $program): void
    {
        foreach ($program->body as $stmt) {
            if (
                !($stmt instanceof \PhpJs\Ast\Statement\ExpressionStatement)
                || !($stmt->expression instanceof \PhpJs\Ast\Expression\FunctionExpression)
            ) {
                continue;
            }
            foreach ($stmt->expression->params as $param) {
                if (self::nodeContainsYieldOrAwait($param)) {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        'YieldExpression or AwaitExpression not permitted in parameters'
                    );
                }
            }
            return;
        }
    }

    private static function nodeContainsYieldOrAwait(?\PhpJs\Ast\Node $node): bool
    {
        if ($node === null) {
            return false;
        }
        if (
            $node instanceof \PhpJs\Ast\Expression\YieldExpression
            || $node instanceof \PhpJs\Ast\Expression\AwaitExpression
        ) {
            return true;
        }
        // Stop descending into nested functions/classes; their own params are
        // their own scope.
        if (
            $node instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $node instanceof \PhpJs\Ast\Declaration\FunctionDeclaration
            || $node instanceof \PhpJs\Ast\Expression\ArrowFunction
            || $node instanceof \PhpJs\Ast\Expression\ClassExpression
            || $node instanceof \PhpJs\Ast\Declaration\ClassDeclaration
        ) {
            return false;
        }
        // Walk public properties of the node looking for Node children/arrays.
        $reflection = new \ReflectionObject($node);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof \PhpJs\Ast\Node) {
                if (self::nodeContainsYieldOrAwait($value)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof \PhpJs\Ast\Node && self::nodeContainsYieldOrAwait($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

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
