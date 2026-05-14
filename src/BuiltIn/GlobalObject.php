<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Runtime\Environment;
use Phasis\Runtime\Interpreter;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

class GlobalObject
{
    use Global_\GlobalWrappers;
    use Global_\GlobalParseAndCheck;
    use Global_\GlobalUri;
    use Global_\GlobalEvalValidation;

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
        $boolProto = new \Phasis\Value\JsObject();
        // Boolean.prototype has [[PrimitiveValue]] = false per spec
        $boolProto->defineOwnProperty(
            '[[PrimitiveValue]]',
            \Phasis\Object\PropertyDescriptor::data(new JsBoolean(false), false, false, false),
        );
        $boolProto->defineOwnProperty(
            'constructor',
            \Phasis\Object\PropertyDescriptor::data($booleanFn, true, false, true),
        );
        $boolProto->defineOwnProperty('valueOf', \Phasis\Object\PropertyDescriptor::data(
            JsFunction::fromCallable('valueOf', function (JsValue $this_): JsValue {
                if ($this_ instanceof JsBoolean) {
                    return $this_;
                }
                if ($this_ instanceof \Phasis\Value\JsObject && $this_->has('[[PrimitiveValue]]')) {
                    $prim = $this_->get('[[PrimitiveValue]]');
                    if ($prim instanceof JsBoolean) {
                        return $prim;
                    }
                }
                throw new \Phasis\Exceptions\TypeError('Boolean.prototype.valueOf requires a Boolean');
            }, 0),
            true,
            false,
            true,
        ));
        $boolProto->defineOwnProperty('toString', \Phasis\Object\PropertyDescriptor::data(
            JsFunction::fromCallable('toString', function (JsValue $this_): JsValue {
                if ($this_ instanceof JsBoolean) {
                    return new JsString($this_->toBoolean() ? 'true' : 'false');
                }
                if ($this_ instanceof \Phasis\Value\JsObject && $this_->has('[[PrimitiveValue]]')) {
                    $prim = $this_->get('[[PrimitiveValue]]');
                    if ($prim instanceof JsBoolean) {
                        return new JsString($prim->toBoolean() ? 'true' : 'false');
                    }
                }
                throw new \Phasis\Exceptions\TypeError('Boolean.prototype.toString requires a Boolean');
            }, 0),
            true,
            false,
            true,
        ));
        // Boolean.prototype is non-writable, non-configurable per spec
        $booleanFn->defineOwnProperty(
            'prototype',
            \Phasis\Object\PropertyDescriptor::data($boolProto, false, false, false),
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
                throw new \Phasis\Exceptions\SyntaxError('Source too large for eval');
            }
            $parser = new \Phasis\Parser\Parser($code->value);
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
                \Phasis\Engine::pushSourceURL($sourceUrl);
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
                    \Phasis\Engine::popSourceURL();
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
                    (new \Phasis\Parser\Parser($paramProbe))->parse();
                } catch (\Throwable $e) {
                    throw new \Phasis\Exceptions\SyntaxError('Function parameter list is invalid');
                }
            // The body gets line feeds per step 41 so AnnexB HTML comments work.
            // The function is anonymous (no named binding visible in scope) —
            // we set .name = "anonymous" after creation rather than parsing
            // as `function anonymous(){...}`, since the latter would make
            // "anonymous" a self-reference inside the body.
                $source = "(function ({$params}\n) {\n{$body}\n})";
                $parser = new \Phasis\Parser\Parser($source);
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
                if ($result instanceof JsFunction && $this_ instanceof \Phasis\Value\JsObject && $this_->has('[[NewTarget]]')) {
                    $newTarget = $this_->get('[[NewTarget]]');
                    if ($newTarget instanceof JsFunction) {
                        $ntProto = $newTarget->get('prototype');
                        if ($ntProto instanceof \Phasis\Value\JsObject) {
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
                    $result->defineOwnProperty('name', \Phasis\Object\PropertyDescriptor::data(
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
            throw new \Phasis\Exceptions\TypeError(
                "'caller', 'callee', and 'arguments' properties may not be accessed"
                . ' on strict mode functions or the arguments objects for calls to them'
            );
        }, 0);
        $thrower->setNonConstructable();
        $thrower->forceDelete('prototype');
        // Freeze %ThrowTypeError%: non-extensible, all props non-configurable
        $thrower->defineOwnProperty(
            'length',
            \Phasis\Object\PropertyDescriptor::data(JsNumber::of(0.0), false, false, false),
        );
        $thrower->defineOwnProperty(
            'name',
            \Phasis\Object\PropertyDescriptor::data(new JsString(''), false, false, false),
        );
        $thrower->preventExtensions();

        // caller/arguments accessor: configurable per ES2024+ spec 10.2.4.
        $throwerDesc = new \Phasis\Object\PropertyDescriptor(
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
                throw new \Phasis\Exceptions\TypeError('call called on non-function');
            }
            $thisArg = $args[0] ?? JsUndefined::instance();
            return $this_->call($thisArg, array_slice($args, 1));
        }, 1);
        $fnProto->defineOwnProperty(
            'call',
            \Phasis\Object\PropertyDescriptor::data($callFn, true, false, true),
        );
        $applyFn = JsFunction::fromCallable('apply', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsFunction) {
                throw new \Phasis\Exceptions\TypeError('apply called on non-function');
            }
            $thisArg = $args[0] ?? JsUndefined::instance();
            $argsArr = $args[1] ?? JsUndefined::instance();
            $callArgs = [];
            // Per spec 20.2.3.1 step 3-4: if argArray is null/undefined,
            // call with empty args. Otherwise CreateListFromArrayLike.
            if (!$argsArr instanceof JsUndefined && !$argsArr instanceof \Phasis\Value\JsNull) {
                // CreateListFromArrayLike: argArray must be an object.
                if (!$argsArr instanceof \Phasis\Value\JsObject) {
                    throw new \Phasis\Exceptions\TypeError(
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
                    $argsArr instanceof \Phasis\Value\JsArray
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
            \Phasis\Object\PropertyDescriptor::data($applyFn, true, false, true),
        );
        $bindCb = function (
            JsValue $this_,
            array $args,
            ?\Phasis\Runtime\Interpreter $interp = null,
        ): JsValue {
            // Spec §20.2.3.2 step 1 only requires IsCallable(Target). A Proxy
            // whose target has [[Call]] qualifies as callable, even though our
            // engine represents it as JsProxy rather than JsFunction. Only
            // throw when the receiver is genuinely non-callable.
            $isCallable = $this_ instanceof JsFunction
                || ($this_ instanceof \Phasis\Value\JsProxy && $this_->isCallable());
            if (!$isCallable) {
                throw new \Phasis\Exceptions\TypeError('bind called on non-function');
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
                    $shim->defineOwnProperty('length', new \Phasis\Object\PropertyDescriptor(
                        value: $proxyLen,
                        writable: false,
                        enumerable: false,
                        configurable: true,
                    ));
                } catch (\Throwable) {
                }
                try {
                    $proxyName = $proxy->get('name');
                    $shim->defineOwnProperty('name', new \Phasis\Object\PropertyDescriptor(
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
                    ?\Phasis\Runtime\Interpreter $innerInterp = null,
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
                        && $th instanceof \Phasis\Value\JsObject
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
                            $ntObj = $activeNewTarget instanceof \Phasis\Value\JsObject
                                ? $activeNewTarget
                                : $target;
                            // Per GetPrototypeFromConstructor: when
                            // newTarget.prototype is not an Object, fall back
                            // to GetFunctionRealm(newTarget)'s %Object.prototype%.
                            $useProto = \Phasis\Spec\AbstractOperations::getPrototypeFromConstructor(
                                $ntObj,
                                static fn ($env) => \Phasis\Spec\AbstractOperations::realmIntrinsicPrototype($env, 'Object'),
                            );
                            if ($useProto === null) {
                                $tp = $target->get('prototype');
                                $useProto = $tp instanceof \Phasis\Value\JsObject ? $tp : null;
                            }
                            $newObj = new \Phasis\Value\JsObject($useProto);
                            $newObj->defineOwnProperty(
                                '[[NewTarget]]',
                                \Phasis\Object\PropertyDescriptor::data($ntObj, false, false, false),
                            );
                            $result = $innerInterp->callFunction($target, $newObj, $mergedArgs);
                            return $result instanceof \Phasis\Value\JsObject ? $result : $newObj;
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
                        $ntObj = $activeNewTarget instanceof \Phasis\Value\JsObject
                            ? $activeNewTarget
                            : $target;
                        // Per GetPrototypeFromConstructor: when
                        // newTarget.prototype is not an Object, fall back
                        // to GetFunctionRealm(newTarget)'s %Object.prototype%.
                        $useProto = \Phasis\Spec\AbstractOperations::getPrototypeFromConstructor(
                            $ntObj,
                            static fn ($env) => \Phasis\Spec\AbstractOperations::realmIntrinsicPrototype($env, 'Object'),
                        );
                        if ($useProto === null) {
                            $tp = $target->get('prototype');
                            $useProto = $tp instanceof \Phasis\Value\JsObject ? $tp : null;
                        }
                        $newObj = new \Phasis\Value\JsObject($useProto);
                        $newObj->defineOwnProperty(
                            '[[NewTarget]]',
                            \Phasis\Object\PropertyDescriptor::data($ntObj, false, false, false),
                        );
                        $result = $target->call($newObj, $mergedArgs);
                        if ($result instanceof \Phasis\Value\JsObject) {
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
            $boundFn->defineOwnProperty('length', new \Phasis\Object\PropertyDescriptor(
                value: JsNumber::of($boundLengthFloat),
                writable: false,
                enumerable: false,
                configurable: true,
            ));

            // Set name property per spec: "bound " + targetName.
            $boundFn->defineOwnProperty('name', new \Phasis\Object\PropertyDescriptor(
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
            \Phasis\Object\PropertyDescriptor::data($bindFn, true, false, true),
        );

        // Function.prototype.toString: per spec, returns source text for
        // user-defined functions and NativeFunction syntax for built-ins.
        // Proxy exotic objects wrapping a callable also return NativeFunction syntax.
        $toStringFn = JsFunction::fromCallable('toString', function (JsValue $this_): JsValue {
            if ($this_ instanceof JsFunction) {
                return new JsString($this_->toJsString());
            }
            // Proxy wrapping a callable: return NativeFunction syntax per spec.
            if ($this_ instanceof \Phasis\Value\JsProxy && $this_->isCallable()) {
                return new JsString('function () { [native code] }');
            }
            throw new \Phasis\Exceptions\TypeError(
                'Function.prototype.toString requires that \'this\' be a Function'
            );
        }, 0);
        $fnProto->defineOwnProperty(
            'toString',
            \Phasis\Object\PropertyDescriptor::data($toStringFn, true, false, true),
        );

        // Function.prototype[Symbol.hasInstance] per spec 19.2.3.6.
        // OrdinaryHasInstance: check if the left operand's prototype chain
        // includes the function's .prototype property.
        $hasInstanceFn = JsFunction::fromCallable(
            '[Symbol.hasInstance]',
            function (JsValue $this_, array $args): JsValue {
                $isCallable = $this_ instanceof JsFunction
                    || ($this_ instanceof \Phasis\Value\JsProxy && $this_->isCallable());
                if (!$isCallable) {
                    return new JsBoolean(false);
                }
                $value = $args[0] ?? JsUndefined::instance();
                if (!$value instanceof \Phasis\Value\JsObject) {
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
                if (!$proto instanceof \Phasis\Value\JsObject) {
                    throw new \Phasis\Exceptions\TypeError(
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
            \Phasis\BuiltIn\SymbolConstructor::hasInstance(),
            new \Phasis\Object\PropertyDescriptor(
                value: $hasInstanceFn,
                writable: false,
                enumerable: false,
                configurable: false,
            ),
        );

        // Function.prototype.constructor = Function (per spec 19.2.3.2).
        $fnProto->defineOwnProperty('constructor', \Phasis\Object\PropertyDescriptor::data(
            $fnConstructor,
            true,
            false,
            true,
        ));

        $fnConstructor->setConstructable();
        // Per spec 19.2.2, Function.prototype is non-writable, non-enumerable, non-configurable.
        $fnConstructor->defineOwnProperty('prototype', new \Phasis\Object\PropertyDescriptor(
            value: $fnProto,
            writable: false,
            enumerable: false,
            configurable: false,
        ));
        $env->defineVar('Function', $fnConstructor);

        // %IteratorPrototype%: the common prototype for all built-in iterators.
        // Per spec 27.1.2, its [[Prototype]] is Object.prototype.
        $iteratorPrototype = new \Phasis\Value\JsObject();
        $iteratorPrototype->definePropertyBySymbol(
            \Phasis\BuiltIn\SymbolConstructor::iterator(),
            \Phasis\Object\PropertyDescriptor::data(
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
        $generatorFunctionProto = new \Phasis\Value\JsObject($fnProto);
        JsFunction::setGeneratorFunctionPrototype($generatorFunctionProto);

        // %GeneratorPrototype%: the prototype of all generator instances.
        // Per spec its [[Prototype]] is %IteratorPrototype%.
        $generatorPrototype = new \Phasis\Value\JsObject($iteratorPrototype);
        // Symbol.toStringTag = "Generator" per spec 27.5.1.
        $generatorPrototype->definePropertyBySymbol(
            \Phasis\BuiltIn\SymbolConstructor::toStringTag(),
            \Phasis\Object\PropertyDescriptor::data(
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
            if (!$thisValue instanceof \Phasis\Value\JsGenerator) {
                throw new \Phasis\Exceptions\TypeError(
                    'Method Generator.prototype.next called on incompatible receiver',
                );
            }
            $value = $args[0] ?? JsUndefined::instance();
            return $thisValue->next($value);
        }, 1);
        $nextFn->setNonConstructable();
        $generatorPrototype->defineOwnProperty('next', \Phasis\Object\PropertyDescriptor::data(
            $nextFn,
            true,
            false,
            true,
        ));
        $returnFn = JsFunction::fromCallable('return', static function (
            JsValue $thisValue,
            array $args,
        ): JsValue {
            if (!$thisValue instanceof \Phasis\Value\JsGenerator) {
                throw new \Phasis\Exceptions\TypeError(
                    'Method Generator.prototype.return called on incompatible receiver',
                );
            }
            $value = $args[0] ?? JsUndefined::instance();
            return $thisValue->returnValue($value);
        }, 1);
        $returnFn->setNonConstructable();
        $generatorPrototype->defineOwnProperty('return', \Phasis\Object\PropertyDescriptor::data(
            $returnFn,
            true,
            false,
            true,
        ));
        $throwFn = JsFunction::fromCallable('throw', static function (
            JsValue $thisValue,
            array $args,
        ): JsValue {
            if (!$thisValue instanceof \Phasis\Value\JsGenerator) {
                throw new \Phasis\Exceptions\TypeError(
                    'Method Generator.prototype.throw called on incompatible receiver',
                );
            }
            $value = $args[0] ?? JsUndefined::instance();
            return $thisValue->throwValue($value);
        }, 1);
        $throwFn->setNonConstructable();
        $generatorPrototype->defineOwnProperty('throw', \Phasis\Object\PropertyDescriptor::data(
            $throwFn,
            true,
            false,
            true,
        ));

        // Wire: GeneratorFunction.prototype.prototype = %GeneratorPrototype%
        // Per spec 27.3.3.2: {writable: false, enumerable: false, configurable: true}.
        $generatorFunctionProto->defineOwnProperty('prototype', \Phasis\Object\PropertyDescriptor::data(
            $generatorPrototype,
            false,
            false,
            true,
        ));

        // constructor on %GeneratorPrototype%: points to %GeneratorFunction.prototype%.
        // Per spec 27.5.1.1: {writable: false, enumerable: false, configurable: true}.
        $generatorPrototype->defineOwnProperty('constructor', \Phasis\Object\PropertyDescriptor::data(
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
                $parser = new \Phasis\Parser\Parser($source);
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
        $genFnConstructor->defineOwnProperty('length', new \Phasis\Object\PropertyDescriptor(
            value: JsNumber::of(1.0),
            writable: false,
            enumerable: false,
            configurable: true,
        ));
        // Per spec: GeneratorFunction.name = "GeneratorFunction".
        $genFnConstructor->defineOwnProperty('name', new \Phasis\Object\PropertyDescriptor(
            value: new JsString('GeneratorFunction'),
            writable: false,
            enumerable: false,
            configurable: true,
        ));
        // Per spec 27.3.2.1: GeneratorFunction.prototype = %GeneratorFunction.prototype%.
        // {writable: false, enumerable: false, configurable: false}.
        $genFnConstructor->defineOwnProperty('prototype', new \Phasis\Object\PropertyDescriptor(
            value: $generatorFunctionProto,
            writable: false,
            enumerable: false,
            configurable: false,
        ));
        // GeneratorFunction [[Prototype]] is Function per spec 27.3.2.
        $genFnConstructor->setCustomPrototype($fnConstructor);

        // %GeneratorFunction.prototype%.constructor = %GeneratorFunction%
        // Per spec 27.3.3.1: {writable: false, enumerable: false, configurable: true}.
        $generatorFunctionProto->defineOwnProperty('constructor', \Phasis\Object\PropertyDescriptor::data(
            $genFnConstructor,
            false,
            false,
            true,
        ));

        // Symbol.toStringTag = "GeneratorFunction" per spec 27.3.3.3.
        $generatorFunctionProto->definePropertyBySymbol(
            \Phasis\BuiltIn\SymbolConstructor::toStringTag(),
            new \Phasis\Object\PropertyDescriptor(
                value: new JsString('GeneratorFunction'),
                writable: false,
                enumerable: false,
                configurable: true,
            ),
        );

        // Register the intrinsic so JsGenerator can use it as fallback when fn.prototype is not an Object.
        \Phasis\Value\JsGenerator::setGeneratorPrototype($generatorPrototype);
        // Store for interpreter access.
        $env->defineVar('__GeneratorPrototype__', $generatorPrototype);
        $env->defineVar('__GeneratorFunctionPrototype__', $generatorFunctionProto);
        $env->defineVar('GeneratorFunction', $genFnConstructor);

        // Set up %AsyncIteratorPrototype%, %AsyncGeneratorPrototype%, %AsyncGeneratorFunction%.
        $asyncIteratorPrototype = new \Phasis\Value\JsObject();
        $asyncIteratorPrototype->definePropertyBySymbol(
            \Phasis\BuiltIn\SymbolConstructor::asyncIterator(),
            \Phasis\Object\PropertyDescriptor::data(
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
        $asyncDisposeSym = \Phasis\BuiltIn\SymbolConstructor::asyncDispose();
        $asyncIteratorPrototype->definePropertyBySymbol(
            $asyncDisposeSym,
            \Phasis\Object\PropertyDescriptor::data(
                JsFunction::fromCallable(
                    '[Symbol.asyncDispose]',
                    static function (JsValue $this_, array $args): JsValue {
                        if (!$this_ instanceof \Phasis\Value\JsObject) {
                            throw new \Phasis\Exceptions\TypeError(
                                'Symbol.asyncDispose called on non-object'
                            );
                        }
                        $returnFn = $this_->get('return');
                        if (
                            $returnFn instanceof \Phasis\Value\JsUndefined
                            || $returnFn instanceof \Phasis\Value\JsNull
                        ) {
                            return \Phasis\Value\JsPromise::resolved(
                                \Phasis\Value\JsUndefined::instance()
                            );
                        }
                        if (!$returnFn instanceof JsFunction) {
                            throw new \Phasis\Exceptions\TypeError(
                                'return is not a function'
                            );
                        }
                        $result = $returnFn->call($this_, []);
                        return \Phasis\Value\JsPromise::resolved($result);
                    },
                    0,
                ),
                true,
                false,
                true,
            ),
        );
        $env->defineVar('__AsyncIteratorPrototype__', $asyncIteratorPrototype);

        $asyncGenFnProto = new \Phasis\Value\JsObject($fnProto);
        JsFunction::setAsyncGeneratorFunctionPrototype($asyncGenFnProto);

        $asyncGenProto = new \Phasis\Value\JsObject($asyncIteratorPrototype);
        $asyncGenProto->definePropertyBySymbol(
            \Phasis\BuiltIn\SymbolConstructor::toStringTag(),
            \Phasis\Object\PropertyDescriptor::data(new JsString('AsyncGenerator'), false, false, true),
        );
        $asyncGenProto->defineOwnProperty('next', \Phasis\Object\PropertyDescriptor::data(
            JsFunction::fromCallable('next', function (JsValue $thisValue, array $args) use ($env): JsValue {
                if (!$thisValue instanceof \Phasis\Value\JsAsyncGenerator) {
                    return \Phasis\Value\JsPromise::rejected(
                        \Phasis\Value\JsAsyncGenerator::makeIncompatibleReceiverError($env, 'next')
                    );
                }
                return $thisValue->next($args[0] ?? JsUndefined::instance());
            }, 1),
            true,
            false,
            true,
        ));
        $asyncGenProto->defineOwnProperty('return', \Phasis\Object\PropertyDescriptor::data(
            JsFunction::fromCallable('return', function (JsValue $thisValue, array $args) use ($env): JsValue {
                if (!$thisValue instanceof \Phasis\Value\JsAsyncGenerator) {
                    return \Phasis\Value\JsPromise::rejected(
                        \Phasis\Value\JsAsyncGenerator::makeIncompatibleReceiverError($env, 'return')
                    );
                }
                return $thisValue->returnValue($args[0] ?? JsUndefined::instance());
            }, 1),
            true,
            false,
            true,
        ));
        $asyncGenProto->defineOwnProperty('throw', \Phasis\Object\PropertyDescriptor::data(
            JsFunction::fromCallable('throw', function (JsValue $thisValue, array $args) use ($env): JsValue {
                if (!$thisValue instanceof \Phasis\Value\JsAsyncGenerator) {
                    return \Phasis\Value\JsPromise::rejected(
                        \Phasis\Value\JsAsyncGenerator::makeIncompatibleReceiverError($env, 'throw')
                    );
                }
                return $thisValue->throwValue($args[0] ?? JsUndefined::instance());
            }, 1),
            true,
            false,
            true,
        ));
        $asyncGenFnProto->defineOwnProperty('prototype', \Phasis\Object\PropertyDescriptor::data($asyncGenProto, false, false, true));
        $asyncGenProto->defineOwnProperty('constructor', \Phasis\Object\PropertyDescriptor::data($asyncGenFnProto, false, false, true));
        $asyncGenFnProto->definePropertyBySymbol(
            \Phasis\BuiltIn\SymbolConstructor::toStringTag(),
            \Phasis\Object\PropertyDescriptor::data(new JsString('AsyncGeneratorFunction'), false, false, true),
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
                $parser = new \Phasis\Parser\Parser($source);
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
        $asyncGenFnConstructor->defineOwnProperty('length', new \Phasis\Object\PropertyDescriptor(
            value: JsNumber::of(1.0),
            writable: false,
            enumerable: false,
            configurable: true,
        ));
        $asyncGenFnConstructor->defineOwnProperty('name', new \Phasis\Object\PropertyDescriptor(
            value: new JsString('AsyncGeneratorFunction'),
            writable: false,
            enumerable: false,
            configurable: true,
        ));
        $asyncGenFnConstructor->defineOwnProperty('prototype', new \Phasis\Object\PropertyDescriptor(
            value: $asyncGenFnProto,
            writable: false,
            enumerable: false,
            configurable: false,
        ));
        $asyncGenFnConstructor->setCustomPrototype($fnConstructor);
        // %AsyncGeneratorFunction.prototype%.constructor = %AsyncGeneratorFunction%
        $asyncGenFnProto->defineOwnProperty('constructor', \Phasis\Object\PropertyDescriptor::data(
            $asyncGenFnConstructor,
            false,
            false,
            true,
        ));

        \Phasis\Value\JsAsyncGenerator::setAsyncGeneratorPrototype($asyncGenProto);
        $env->defineVar('__AsyncGeneratorPrototype__', $asyncGenProto);
        $env->defineVar('__AsyncGeneratorFunctionPrototype__', $asyncGenFnProto);
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
}
