<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Engine;
use PhpJs\Exceptions\TypeError;
use PhpJs\Object\PropertyDescriptor;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsSymbol;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

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
                $engine = new Engine();
                $engine->setLimit('maxLoopIterations', 2_000_000);
                if ($outerInterp !== null) {
                    Engine::setCurrentInterpreter($outerInterp);
                }
                $realm->setInternalProperty('[[ShadowRealmEngine]]', $engine);
                return $realm;
            },
        );
        $constructor->setConstructable();

        // ShadowRealm.length = 0
        $constructor->defineOwnProperty('length', PropertyDescriptor::data(
            new JsNumber(0.0),
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
        $evaluateFn = JsFunction::fromCallable(
            'evaluate',
            function (JsValue $this_, array $args): JsValue {
                // Validate this is a ShadowRealm instance.
                if (!$this_ instanceof JsObject) {
                    throw new TypeError('ShadowRealm.prototype.evaluate called on non-object');
                }
                $engine = $this_->getInternalProperty('[[ShadowRealmEngine]]');
                if (!$engine instanceof Engine) {
                    throw new TypeError(
                        'ShadowRealm.prototype.evaluate called on incompatible receiver',
                    );
                }

                $sourceText = $args[0] ?? JsUndefined::instance();
                if (!$sourceText instanceof JsString) {
                    throw new TypeError('ShadowRealm.prototype.evaluate requires a string argument');
                }

                try {
                    $result = self::evaluateInRealm($engine, $sourceText->value);
                } catch (\PhpJs\Exceptions\SyntaxError $e) {
                    throw new \PhpJs\Exceptions\SyntaxError($e->getMessage());
                } catch (\PhpJs\Exceptions\RuntimeError $e) {
                    // Per spec: errors from the other realm are wrapped into a TypeError
                    // from the caller's realm.
                    throw new TypeError($e->getMessage());
                } catch (\Throwable $e) {
                    throw new TypeError($e->getMessage());
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
                $specifier = $args[0] ?? \PhpJs\Value\JsUndefined::instance();
                $exportName = $args[1] ?? \PhpJs\Value\JsUndefined::instance();
                $specifierStr = \PhpJs\Spec\TypeConversion::toString($specifier);
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

                $promise = new \PhpJs\Value\JsPromise();
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
                    if ($value instanceof \PhpJs\Value\JsUndefined && !$namespace->has($exportNameStr)) {
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
     */
    private static function evaluateInRealm(Engine $engine, string $sourceText): JsValue
    {
        $parser = new \PhpJs\Parser\Parser($sourceText);
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
            return self::getWrappedValue($rawResult);
        } finally {
            // Restore the caller's interpreter before returning.
            Engine::setCurrentInterpreter($callerInterpreter);
        }
    }

    /**
     * Per spec GetWrappedValue: primitive values pass through,
     * callable objects become wrapped functions, non-callable objects throw TypeError.
     */
    private static function getWrappedValue(JsValue $value): JsValue
    {
        // Primitive values pass through directly.
        if (
            $value instanceof JsUndefined
            || $value instanceof JsNull
            || $value instanceof JsBoolean
            || $value instanceof JsNumber
            || $value instanceof JsString
            || $value instanceof JsSymbol
            || $value instanceof \PhpJs\Value\JsBigInt
        ) {
            return $value;
        }

        // Callable objects: wrap into a function in the caller's realm.
        if ($value instanceof JsFunction) {
            return self::createWrappedFunction($value);
        }

        // Callable Proxy objects are also callable.
        if ($value instanceof \PhpJs\Value\JsProxy && $value->isCallable()) {
            return self::createWrappedCallable($value);
        }

        // Non-callable objects throw TypeError.
        throw new TypeError(
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
    private static function wrapArguments(array $args): array
    {
        $wrappedArgs = [];
        foreach ($args as $arg) {
            if (self::isPrimitive($arg)) {
                $wrappedArgs[] = $arg;
            } elseif ($arg instanceof JsFunction) {
                $wrappedArgs[] = self::createWrappedFunction($arg);
            } elseif ($arg instanceof \PhpJs\Value\JsProxy && $arg->isCallable()) {
                $wrappedArgs[] = self::createWrappedCallable($arg);
            } else {
                throw new TypeError(
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
            || $value instanceof \PhpJs\Value\JsBigInt;
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
     */
    private static function createWrappedFunction(JsFunction $targetFn): JsFunction
    {
        // Snapshot the realm interpreter where the target function was
        // captured so cross-realm calls run with the correct globalEnv
        // (Symbol.prototype lookups, error wrapping, etc.). Without this,
        // the wrapped function uses whatever Engine wrote the static
        // currentInterpreter last, which leaks the outer realm into SR.
        $targetInterp = Engine::getCurrentInterpreter();
        $wrapped = JsFunction::fromCallable(
            '',
            function (JsValue $this_, array $args) use ($targetFn, $targetInterp): JsValue {
                $wrappedArgs = self::wrapArguments($args);
                $interp = $targetInterp ?? Engine::getCurrentInterpreter();
                if ($interp === null) {
                    throw new TypeError('No interpreter available');
                }
                $previous = Engine::getCurrentInterpreter();
                Engine::setCurrentInterpreter($interp);
                try {
                    $result = $interp->callFunction(
                        $targetFn,
                        JsUndefined::instance(),
                        $wrappedArgs,
                    );
                } catch (\PhpJs\Exceptions\RuntimeError $e) {
                    throw new TypeError($e->getMessage());
                } finally {
                    Engine::setCurrentInterpreter($previous);
                }
                return self::getWrappedValue($result);
            },
        );

        // Per spec WrappedFunctionCreate: copy length and name from target.
        // Length defaults to 0, name defaults to "".
        self::copyWrappedProperties($wrapped, $targetFn);

        return $wrapped;
    }

    /**
     * Create a wrapped function for a callable Proxy.
     */
    private static function createWrappedCallable(\PhpJs\Value\JsProxy $target): JsFunction
    {
        $wrapped = JsFunction::fromCallable(
            '',
            function (JsValue $this_, array $args) use ($target): JsValue {
                $wrappedArgs = self::wrapArguments($args);
                try {
                    $result = $target->apply(JsUndefined::instance(), $wrappedArgs);
                } catch (\PhpJs\Exceptions\RuntimeError $e) {
                    throw new TypeError($e->getMessage());
                }
                return self::getWrappedValue($result);
            },
        );

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
        } catch (\PhpJs\Exceptions\JsThrowable) {
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
        } catch (\PhpJs\Exceptions\JsThrowable) {
            throw new TypeError('WrappedFunctionCreate: target length getter threw');
        } catch (\Throwable $e) {
            throw new TypeError('WrappedFunctionCreate: ' . $e->getMessage());
        }
        if ($targetLength instanceof JsNumber) {
            $v = $targetLength->value;
            if (is_nan($v)) {
                $len = new JsNumber(0.0);
            } elseif ($v === INF) {
                $len = new JsNumber(INF);
            } elseif ($v === -INF) {
                $len = new JsNumber(0.0);
            } else {
                $intLen = ($v >= 0 ? 1 : -1) * floor(abs($v));
                $len = new JsNumber(max(0.0, $intLen));
            }
        } else {
            $len = new JsNumber(0.0);
        }
        $wrapped->defineOwnProperty('length', PropertyDescriptor::data($len, false, false, true));

        // Per spec: name = ? Get(target, "name"). A throwing name getter also
        // surfaces as a TypeError in the caller realm (CopyNameAndLength step
        // 7 is ? Get, propagated by WrappedFunctionCreate step 8).
        try {
            $targetName = $target->get('name');
        } catch (\PhpJs\Exceptions\JsThrowable) {
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
