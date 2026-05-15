<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Engine;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * ShadowRealm constructor and prototype methods.
 *
 * Per spec, ShadowRealm creates an isolated execution context.
 * Implemented by creating a fresh Engine instance per realm.
 */
class ShadowRealmConstructor
{
    public static function install(Environment $env): void
    {
        $proto = new JsObject();

        $constructor = JsFunction::fromCallable(
            'ShadowRealm',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor ShadowRealm requires \'new\'');
                }

                $realm = new JsObject($proto);
                // Snapshot the outer realm's current interpreter so the
                // Engine constructor's "current = me" assignment doesn't
                // leave the static pointing at the inner realm after the
                // SR is constructed.
                $outerInterp = Engine::getCurrentInterpreter();
                // The "outer realm" is the realm of the constructor that
                // produced this ShadowRealm, not the currently executing
                // realm. For `new OtherRealm.ShadowRealm()` or
                // `Reflect.construct(OtherShadowRealm, [], …)`, the
                // active realm during the body is still the test runner's
                // primary realm — so we fish out `[[NewTarget]].realm`
                // instead. Falls back to the active realm on the (rare)
                // case where NewTarget isn't a JsFunction.
                $outerRealm = null;
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsFunction && $newTarget->realm !== null) {
                    $outerRealm = $newTarget->realm;
                }
                if ($outerRealm === null) {
                    $outerRealm = Engine::getCurrentRealm();
                }
                $engine = new Engine(eager: $outerRealm?->isEager() ?? false);
                $engine->setLimit('maxLoopIterations', 2_000_000);
                if ($outerInterp !== null) {
                    Engine::setCurrentInterpreter($outerInterp);
                }
                $realm->setInternalProperty('[[ShadowRealmEngine]]', $engine);
                // Remember the realm this ShadowRealm was created in so
                // evaluate() / importValue() can wrap inner-realm errors
                // as TypeError instances from the constructing realm
                // (per spec: errors are reported in the caller realm,
                // which for `evaluate` is the realm whose %ShadowRealm%
                // intrinsic produced this instance).
                if ($outerRealm !== null) {
                    $realm->setInternalProperty('[[ShadowRealmOuterRealm]]', $outerRealm);
                }
                return $realm;
            },
        );
        $constructor->setConstructable();

        // ShadowRealm.length = 0
        $constructor->defineOwnProperty('length', PropertyDescriptor::data(
            JsNumber::of(0.0),
            false,
            false,
            true,
        ));

        // ShadowRealm.name = "ShadowRealm"
        $constructor->defineOwnProperty('name', PropertyDescriptor::data(
            new JsString('ShadowRealm'),
            false,
            false,
            true,
        ));

        // ShadowRealm.prototype
        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data(
            $proto,
            false,
            false,
            false,
        ));

        // ShadowRealm.prototype.constructor
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data(
            $constructor,
            true,
            false,
            true,
        ));

        // ShadowRealm.prototype[Symbol.toStringTag] = "ShadowRealm"
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('ShadowRealm'), false, false, true),
        );

        // ShadowRealm.prototype.evaluate(sourceText)
        self::installEvaluate($proto);

        // ShadowRealm.prototype.importValue(specifier, exportName)
        self::installImportValue($proto);

        $env->defineVar('ShadowRealm', $constructor);
    }

    private static function installEvaluate(JsObject $proto): void
    {
        // Capture the realm where evaluate is being installed. This
        // becomes the "callerRealm" for spec step 4 of
        // ShadowRealm.prototype.evaluate so wrapper-machinery
        // TypeErrors / SyntaxErrors come from the realm of the
        // EVALUATE FUNCTION (not the SR receiver). When
        // OtherShadowRealm.prototype.evaluate.call(thirdRealmInstance, …)
        // crosses two unrelated realms, the spec mandates that the
        // wrapper errors take the realm of `other`, not the realm of
        // the SR receiver.
        $installRealm = Engine::getCurrentRealm();
        $evaluateFn = JsFunction::fromCallable(
            'evaluate',
            function (JsValue $this_, array $args) use ($installRealm): JsValue {
                // Validate this is a ShadowRealm instance. The
                // brand-check itself must throw a TypeError in the
                // realm whose ShadowRealm.prototype.evaluate was
                // invoked (the "outer" realm captured at construction
                // time), so the assertion happens via JsThrowable
                // wrapping a realm-specific TypeError instance.
                $outerRealm = $installRealm;
                if (
                    $outerRealm === null
                    && $this_ instanceof JsObject
                    && $this_->getInternalProperty('[[ShadowRealmOuterRealm]]') instanceof Engine
                ) {
                    $outerRealm = $this_->getInternalProperty('[[ShadowRealmOuterRealm]]');
                }
                if (!$this_ instanceof JsObject) {
                    throw self::makeOuterRealmTypeError(
                        $outerRealm,
                        'ShadowRealm.prototype.evaluate called on non-object',
                    );
                }
                $engine = $this_->getInternalProperty('[[ShadowRealmEngine]]');
                if (!$engine instanceof Engine) {
                    throw self::makeOuterRealmTypeError(
                        $outerRealm,
                        'ShadowRealm.prototype.evaluate called on incompatible receiver',
                    );
                }

                $sourceText = $args[0] ?? JsUndefined::instance();
                if (!$sourceText instanceof JsString) {
                    throw self::makeOuterRealmTypeError(
                        $outerRealm,
                        'ShadowRealm.prototype.evaluate requires a string argument',
                    );
                }

                try {
                    $result = self::evaluateInRealm($engine, $sourceText->value, $outerRealm);
                } catch (\Phasis\Exceptions\SyntaxError $e) {
                    // SyntaxError must surface in the OUTER realm so a
                    // cross-realm ShadowRealm exposes
                    // OtherRealm.SyntaxError, not the inner engine's.
                    throw self::makeOuterRealmError(
                        $outerRealm,
                        'SyntaxError',
                        $e->getMessage(),
                    );
                } catch (\Phasis\Exceptions\RuntimeError $e) {
                    // Per spec: errors from the other realm are wrapped into a TypeError
                    // from the caller's realm.
                    throw self::makeOuterRealmTypeError($outerRealm, $e->getMessage());
                } catch (\Throwable $e) {
                    throw self::makeOuterRealmTypeError($outerRealm, $e->getMessage());
                }

                return $result;
            },
            1,
        );
        // evaluate is not a constructor
        $evaluateFn->setNonConstructable();

        $proto->defineOwnProperty('evaluate', PropertyDescriptor::data(
            $evaluateFn,
            true,
            false,
            true,
        ));
    }

    private static function installImportValue(JsObject $proto): void
    {
        $importValueFn = JsFunction::fromCallable(
            'importValue',
            function (JsValue $this_, array $args): JsValue {
                // Validate this is a ShadowRealm instance.
                if (!$this_ instanceof JsObject) {
                    throw new TypeError('ShadowRealm.prototype.importValue called on non-object');
                }
                $engine = $this_->getInternalProperty('[[ShadowRealmEngine]]');
                if (!$engine instanceof Engine) {
                    throw new TypeError(
                        'ShadowRealm.prototype.importValue called on incompatible receiver',
                    );
                }

                // Per spec ShadowRealm.prototype.importValue:
                //   1. Let specifierString be ? ToString(specifier).
                //   2. If Type(exportName) is not String, throw TypeError.
                // The exportName check is a Type test (not a coercion),
                // so a non-string with a throwing toString must throw
                // TypeError without invoking the toString hook.
                $specifier = $args[0] ?? \Phasis\Value\JsUndefined::instance();
                $exportName = $args[1] ?? \Phasis\Value\JsUndefined::instance();
                $specifierStr = \Phasis\Spec\TypeConversion::toString($specifier);
                if (!$exportName instanceof JsString) {
                    throw new TypeError('ShadowRealm.prototype.importValue exportName must be a string');
                }
                $exportNameStr = $exportName->value;

                // Resolve the specifier relative to the caller's current
                // module path so `./fixture.js` works inside test262.
                $callerInterpreter = Engine::getCurrentInterpreter();
                $callerModulePath = $callerInterpreter !== null
                    ? $callerInterpreter->getCurrentModulePath()
                    : null;

                $promise = new \Phasis\Value\JsPromise();
                try {
                    $innerInterpreter = $engine->getInterpreter();
                    Engine::setCurrentInterpreter($innerInterpreter);
                    try {
                        // Use the inner realm's ModuleLoader so the imported
                        // module's bindings live in the ShadowRealm's globals.
                        $loader = $innerInterpreter->getModuleLoader();
                        $namespace = $loader->loadModule($specifierStr, $callerModulePath);
                    } finally {
                        Engine::setCurrentInterpreter($callerInterpreter);
                    }
                    $value = $namespace->get($exportNameStr);
                    if ($value instanceof \Phasis\Value\JsUndefined && !$namespace->has($exportNameStr)) {
                        $promise->reject(self::buildTypeError(
                            "Export '" . $exportNameStr . "' not found in module"
                        ));
                    } else {
                        $promise->resolve(self::getWrappedValue($value));
                    }
                } catch (\Throwable $e) {
                    // Per spec RealmImportValue, any module-load failure
                    // (parse error, link error, runtime throw) rejects the
                    // returned Promise with TypeError. Wrap the underlying
                    // error so the caller realm sees a TypeError instance.
                    $promise->reject(self::buildTypeError(
                        'ShadowRealm importValue failed: ' . $e->getMessage()
                    ));
                }
                return $promise;
            },
            2,
        );
        // importValue is not a constructor
        $importValueFn->setNonConstructable();

        $proto->defineOwnProperty('importValue', PropertyDescriptor::data(
            $importValueFn,
            true,
            false,
            true,
        ));
    }

    /**
     * Evaluate source text in the shadow realm and wrap the result.
     * Per spec, only primitive values and callable objects can cross realm boundaries.
     * Callable objects are wrapped into WrappedFunction objects.
     *
     * `$callerRealm` is the realm that the surrounding spec algorithm
     * (PerformShadowRealmEval) labels "callerRealm" — the realm of the
     * method invocation that produced this evaluate call (e.g. `other`
     * for `OtherShadowRealm.prototype.evaluate.call(realm, src)`).
     * When non-null, wrapped functions / argument-rejection TypeErrors
     * thrown by the wrapper machinery are taken from that realm so the
     * test262 cross-realm assertions resolve.
     */
    private static function evaluateInRealm(
        Engine $engine,
        string $sourceText,
        ?Engine $callerRealm = null,
    ): JsValue {
        $parser = new \Phasis\Parser\Parser($sourceText);
        $program = $parser->parse();
        $callerInterpreter = Engine::getCurrentInterpreter();

        // Execute in the shadow realm's engine. Switch the static
        // currentInterpreter pointer so realm-sensitive lookups
        // (Symbol.prototype, error wrapping) resolve against SR's
        // globals, then capture SR's interpreter for the result wrap
        // BEFORE restoring the caller realm.
        // Per spec PerformShadowRealmEval, each evaluate call gets a
        // fresh DeclarativeEnvironment for its var/lex declarations
        // (children of the realm's GlobalEnv). Top-level let/const in
        // one evaluate must NOT collide with same-named declarations in
        // a subsequent evaluate. Routing through the interpreter's
        // strict-eval path satisfies that.
        $innerInterpreter = $engine->getInterpreter();
        Engine::setCurrentInterpreter($innerInterpreter);
        $prevEvalContext = $innerInterpreter->isEvalContext();
        $prevStrict = $innerInterpreter->isStrictMode();
        // Per spec PerformShadowRealmEval, ShadowRealm.evaluate behaves
        // like indirect eval at global scope: var/function declarations
        // go to the realm's GlobalEnv (persisting across evaluate calls)
        // while lexical declarations (let/const/class) go to a fresh
        // DeclarativeEnvironment per call. Setting isEvalContext routes
        // through the indirect-eval path; the source's own "use strict"
        // directive still controls strictness.
        $innerInterpreter->setEvalContext(true);
        // Reset strictMode so a previous strict evaluate doesn't poison
        // the current one. The directive prologue check at execute()
        // entry will set strict if the new source asks for it.
        $innerInterpreter->setStrictMode(false);
        try {
            $rawResult = $innerInterpreter->execute($program);
        } finally {
            $innerInterpreter->setEvalContext($prevEvalContext);
            $innerInterpreter->setStrictMode($prevStrict);
            Engine::setCurrentInterpreter($innerInterpreter);
        }
        try {
            return self::getWrappedValue($rawResult, $callerRealm);
        } finally {
            // Restore the caller's interpreter before returning.
            Engine::setCurrentInterpreter($callerInterpreter);
        }
    }

    /**
     * Per spec GetWrappedValue: primitive values pass through,
     * callable objects become wrapped functions, non-callable objects throw TypeError.
     *
     * `$callerRealm` is the realm that should own any TypeError thrown
     * when the value is non-wrappable, and the realm wrapped functions
     * adopt as their [[Realm]] for nested wrapper TypeErrors.
     */
    private static function getWrappedValue(JsValue $value, ?Engine $callerRealm = null): JsValue
    {
        // Primitive values pass through directly.
        if (
            $value instanceof JsUndefined
            || $value instanceof JsNull
            || $value instanceof JsBoolean
            || $value instanceof JsNumber
            || $value instanceof JsString
            || $value instanceof JsSymbol
            || $value instanceof \Phasis\Value\JsBigInt
        ) {
            return $value;
        }

        // Callable objects: wrap into a function in the caller's realm.
        if ($value instanceof JsFunction) {
            return self::createWrappedFunction($value, $callerRealm);
        }

        // Callable Proxy objects are also callable.
        if ($value instanceof \Phasis\Value\JsProxy && $value->isCallable()) {
            return self::createWrappedCallable($value, $callerRealm);
        }

        // Non-callable objects throw TypeError in the caller's realm.
        throw self::makeOuterRealmTypeError(
            $callerRealm,
            'ShadowRealm evaluate result is not a primitive or callable object',
        );
    }

    /**
     * Wrap arguments crossing the realm boundary. Primitives pass through,
     * callable objects become wrapped functions, non-callable objects throw TypeError.
     *
     * @param list<JsValue> $args
     * @return list<JsValue>
     */
    private static function wrapArguments(array $args, ?Engine $callerRealm = null): array
    {
        $wrappedArgs = [];
        foreach ($args as $arg) {
            if (self::isPrimitive($arg)) {
                $wrappedArgs[] = $arg;
            } elseif ($arg instanceof JsFunction) {
                $wrappedArgs[] = self::createWrappedFunction($arg, $callerRealm);
            } elseif ($arg instanceof \Phasis\Value\JsProxy && $arg->isCallable()) {
                $wrappedArgs[] = self::createWrappedCallable($arg, $callerRealm);
            } else {
                throw self::makeOuterRealmTypeError(
                    $callerRealm,
                    'Arguments of a wrapped function must be primitives or callable objects',
                );
            }
        }
        return $wrappedArgs;
    }

    private static function isPrimitive(JsValue $value): bool
    {
        return $value instanceof JsUndefined
            || $value instanceof JsNull
            || $value instanceof JsBoolean
            || $value instanceof JsNumber
            || $value instanceof JsString
            || $value instanceof JsSymbol
            || $value instanceof \Phasis\Value\JsBigInt;
    }

    /**
     * Throw a TypeError instance that belongs to the ShadowRealm's
     * outer realm. When $outerRealm is null (the ShadowRealm has no
     * captured outer realm, or the receiver brand check happened on a
     * non-SR object), fall back to the current realm's TypeError so
     * existing behaviour is preserved.
     */
    private static function makeOuterRealmTypeError(
        ?Engine $outerRealm,
        string $message,
    ): \Phasis\Exceptions\RuntimeError {
        return self::makeOuterRealmError($outerRealm, 'TypeError', $message);
    }

    /**
     * Throw an Error-of-kind-$name belonging to $outerRealm. Used by
     * ShadowRealm.prototype.evaluate to surface SyntaxError /
     * TypeError instances in the realm that *called* evaluate (which
     * per spec is the realm of the ShadowRealm instance's
     * constructor) rather than the realm currently executing.
     */
    private static function makeOuterRealmError(
        ?Engine $outerRealm,
        string $name,
        string $message,
    ): \Phasis\Exceptions\RuntimeError {
        if ($outerRealm === null) {
            // No outer realm captured (legacy SR or non-SR brand-check
            // path): fall back to the current realm's PHP exception
            // class so existing harness expectations still pass.
            return match ($name) {
                'TypeError' => new TypeError($message),
                'SyntaxError' => new \Phasis\Exceptions\SyntaxError($message),
                default => new \Phasis\Exceptions\RuntimeError($message),
            };
        }
        $globalEnv = $outerRealm->getGlobalEnv();
        $err = new JsObject();
        $err->set('message', new JsString($message));
        $err->set('name', new JsString($name));
        $err->set('stack', new JsString($name . ': ' . $message));
        if ($globalEnv->has($name)) {
            $ctor = $globalEnv->get($name);
            if ($ctor instanceof JsFunction) {
                $proto = $ctor->get('prototype');
                if ($proto instanceof JsObject) {
                    $err->setPrototype($proto);
                }
            }
        }
        return new \Phasis\Exceptions\JsThrowable($err, $name . ': ' . $message);
    }

    /**
     * Construct a TypeError JsObject in the caller's realm. Used by
     * ShadowRealm.prototype.importValue to wrap any module-load failure
     * (parse error, link error, runtime throw) as the caller-realm
     * TypeError that the spec mandates for RealmImportValue.
     */
    private static function buildTypeError(string $message): JsObject
    {
        $err = new JsObject();
        $err->set('message', new JsString($message));
        $err->set('name', new JsString('TypeError'));
        $err->set('stack', new JsString('TypeError: ' . $message));
        // Try to set Object.getPrototypeOf(err) === TypeError.prototype.
        $interp = Engine::getCurrentInterpreter();
        if ($interp !== null) {
            try {
                $teCtor = $interp->getGlobalEnv()->get('TypeError');
                if ($teCtor instanceof JsFunction) {
                    $proto = $teCtor->get('prototype');
                    if ($proto instanceof JsObject) {
                        $err->setPrototype($proto);
                    }
                }
            } catch (\Throwable) {
            }
        }
        return $err;
    }

    /**
     * Create a wrapped function that proxies calls across realm boundaries.
     * Per spec, arguments are wrapped going in, return values are wrapped coming out.
     *
     * `$callerRealm` becomes the wrapper's [[Realm]] (spec
     * WrappedFunctionCreate step 5). It's the realm whose TypeError
     * surfaces when arguments fail wrappability, when the target
     * throws, or when the return value isn't wrappable
     * (WrappedFunction.[[Call]] steps 8 and 9.b.iii).
     *
     * The TARGET function's realm (`$targetFn->realm`) is the realm
     * inside which the target itself executes; the spec uses it as
     * the "target realm" passed to GetWrappedValue when re-wrapping
     * arguments and return values for the next crossing.
     */
    private static function createWrappedFunction(
        JsFunction $targetFn,
        ?Engine $callerRealm = null,
    ): JsFunction {
        // Snapshot the realm interpreter where the target function was
        // captured so cross-realm calls run with the correct globalEnv
        // (Symbol.prototype lookups, error wrapping, etc.). Without this,
        // the wrapped function uses whatever Engine wrote the static
        // currentInterpreter last, which leaks the outer realm into SR.
        $targetInterp = Engine::getCurrentInterpreter();
        $targetRealm = $targetFn->realm;
        $wrapped = JsFunction::fromCallable(
            '',
            function (JsValue $this_, array $args) use ($targetFn, $targetInterp, $callerRealm, $targetRealm): JsValue {
                // Spec WrappedFunction.[[Call]]: arguments cross from
                // callerRealm into targetRealm. Argument-wrapping
                // failures (non-callable Object args) are surfaced as
                // TypeError of callerRealm (the wrapper's [[Realm]]),
                // matching V8 / SpiderMonkey. New wrappers minted for
                // callable args still receive targetRealm as their
                // [[Realm]] so that, when the target body invokes
                // them, the further re-wrapping uses targetRealm
                // (which becomes "callerRealm" for the nested call).
                try {
                    $wrappedArgs = self::wrapArguments($args, $targetRealm);
                } catch (\Phasis\Exceptions\JsThrowable $e) {
                    // The non-wrappable TypeError was minted in
                    // targetRealm by the wrap helper. Re-throw it in
                    // callerRealm so the outer assertion sees the
                    // wrapper's [[Realm]] error.
                    throw self::makeOuterRealmTypeError(
                        $callerRealm,
                        $e->getMessage(),
                    );
                }
                $interp = $targetInterp ?? Engine::getCurrentInterpreter();
                if ($interp === null) {
                    throw self::makeOuterRealmTypeError($callerRealm, 'No interpreter available');
                }
                $previous = Engine::getCurrentInterpreter();
                Engine::setCurrentInterpreter($interp);
                try {
                    $result = $interp->callFunction(
                        $targetFn,
                        JsUndefined::instance(),
                        $wrappedArgs,
                    );
                } catch (\Phasis\Exceptions\RuntimeError $e) {
                    // Spec step 8: abrupt completion from Call →
                    // throw a TypeError of callerRealm.
                    throw self::makeOuterRealmTypeError($callerRealm, $e->getMessage());
                } finally {
                    Engine::setCurrentInterpreter($previous);
                }
                // Spec step 9: GetWrappedValue(callerRealm, result).
                // Return-value wrapping uses callerRealm so the new
                // wrapper executes in callerRealm and reports failures
                // there.
                return self::getWrappedValue($result, $callerRealm);
            },
        );
        // Tag the wrapper with [[Realm]] = callerRealm so further
        // wrappings of THIS wrapper (e.g. when it crosses another SR
        // boundary as an argument) propagate the correct realm.
        if ($callerRealm !== null) {
            $wrapped->realm = $callerRealm;
        }

        // Per spec WrappedFunctionCreate: copy length and name from target.
        // Length defaults to 0, name defaults to "".
        self::copyWrappedProperties($wrapped, $targetFn);

        return $wrapped;
    }

    /**
     * Create a wrapped function for a callable Proxy.
     */
    private static function createWrappedCallable(
        \Phasis\Value\JsProxy $target,
        ?Engine $callerRealm = null,
    ): JsFunction {
        // For a callable Proxy we don't have a single resolved
        // [[Realm]] on the target; ProxyTarget could itself be a
        // function in some other realm, but in practice ShadowRealm
        // wrapping of Proxy callables is rare. Fall back to the
        // caller realm for both wrap-in and wrap-out — the realm
        // round-trip is the dominant case (target was just
        // round-tripped through callerRealm anyway).
        $wrapped = JsFunction::fromCallable(
            '',
            function (JsValue $this_, array $args) use ($target, $callerRealm): JsValue {
                $wrappedArgs = self::wrapArguments($args, $callerRealm);
                try {
                    $result = $target->apply(JsUndefined::instance(), $wrappedArgs);
                } catch (\Phasis\Exceptions\RuntimeError $e) {
                    throw self::makeOuterRealmTypeError($callerRealm, $e->getMessage());
                }
                return self::getWrappedValue($result, $callerRealm);
            },
        );
        if ($callerRealm !== null) {
            $wrapped->realm = $callerRealm;
        }

        self::copyWrappedProperties($wrapped, $target);

        return $wrapped;
    }

    /**
     * Copy length and name from target to the wrapped function per spec.
     */
    private static function copyWrappedProperties(JsFunction $wrapped, JsObject $target): void
    {
        // Per spec CopyNameAndLength step 3: HasOwnProperty(Target, "length").
        // For a Proxy this calls the getOwnPropertyDescriptor trap; if that
        // trap throws, we must surface the failure as a TypeError in the
        // caller realm (WrappedFunctionCreate step 8).
        try {
            $target->getOwnPropertyDescriptor('length');
        } catch (\Phasis\Exceptions\JsThrowable) {
            throw new TypeError('WrappedFunctionCreate: target length descriptor threw');
        } catch (\Throwable $e) {
            throw new TypeError('WrappedFunctionCreate: ' . $e->getMessage());
        }
        // Per spec CopyNameAndLength: Get(target, "length") is observable.
        // If it throws, WrappedFunctionCreate must throw a TypeError in the
        // caller realm (step 8). Value handling: +Infinity → +Infinity,
        // -Infinity → 0, otherwise ToIntegerOrInfinity clamped to >= 0.
        try {
            $targetLength = $target->get('length');
        } catch (\Phasis\Exceptions\JsThrowable) {
            throw new TypeError('WrappedFunctionCreate: target length getter threw');
        } catch (\Throwable $e) {
            throw new TypeError('WrappedFunctionCreate: ' . $e->getMessage());
        }
        if ($targetLength instanceof JsNumber) {
            $v = $targetLength->value;
            if (is_nan($v)) {
                $len = JsNumber::of(0.0);
            } elseif ($v === INF) {
                $len = JsNumber::of(INF);
            } elseif ($v === -INF) {
                $len = JsNumber::of(0.0);
            } else {
                $intLen = ($v >= 0 ? 1 : -1) * floor(abs($v));
                $len = JsNumber::of(max(0.0, $intLen));
            }
        } else {
            $len = JsNumber::of(0.0);
        }
        $wrapped->defineOwnProperty('length', PropertyDescriptor::data($len, false, false, true));

        // Per spec: name = ? Get(target, "name"). A throwing name getter also
        // surfaces as a TypeError in the caller realm (CopyNameAndLength step
        // 7 is ? Get, propagated by WrappedFunctionCreate step 8).
        try {
            $targetName = $target->get('name');
        } catch (\Phasis\Exceptions\JsThrowable) {
            throw new TypeError('WrappedFunctionCreate: target name getter threw');
        } catch (\Throwable $e) {
            throw new TypeError('WrappedFunctionCreate: ' . $e->getMessage());
        }
        $name = $targetName instanceof JsString ? $targetName : new JsString('');
        $wrapped->defineOwnProperty('name', PropertyDescriptor::data($name, false, false, true));

        // Wrapped functions should not have a .prototype property.
        $wrapped->setNonConstructable();
    }
}
