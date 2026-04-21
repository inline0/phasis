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
                // Store a fresh Engine instance as the realm's execution context.
                $engine = new Engine();
                $engine->setLimit('maxLoopIterations', 2_000_000);
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

                // importValue requires ES modules which we don't support.
                // Throw TypeError per spec behavior when import fails.
                throw new TypeError('ShadowRealm importValue is not supported (ES modules required)');
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
        $interpreter = Engine::getCurrentInterpreter();

        // Execute in the shadow realm's engine.
        $innerInterpreter = $engine->getInterpreter();
        $innerEnv = $engine->getGlobalEnv();
        $rawResult = $innerInterpreter->execute($program);

        // Wrap the result per GetWrappedValue semantics.
        return self::getWrappedValue($rawResult);
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

        // Non-callable objects throw TypeError.
        throw new TypeError(
            'ShadowRealm evaluate result is not a primitive or callable object',
        );
    }

    /**
     * Create a wrapped function that proxies calls across realm boundaries.
     * Per spec, arguments are wrapped going in, return values are wrapped coming out.
     */
    private static function createWrappedFunction(JsFunction $targetFn): JsFunction
    {
        $wrapped = JsFunction::fromCallable(
            '',
            function (JsValue $this_, array $args) use ($targetFn): JsValue {
                // Wrap arguments: primitives pass through, callables become wrapped functions.
                $wrappedArgs = [];
                foreach ($args as $arg) {
                    if (
                        $arg instanceof JsUndefined
                        || $arg instanceof JsNull
                        || $arg instanceof JsBoolean
                        || $arg instanceof JsNumber
                        || $arg instanceof JsString
                        || $arg instanceof JsSymbol
                        || $arg instanceof \PhpJs\Value\JsBigInt
                    ) {
                        $wrappedArgs[] = $arg;
                    } elseif ($arg instanceof JsFunction) {
                        // Wrap callable into inner realm.
                        $wrappedArgs[] = self::createWrappedFunction($arg);
                    } else {
                        throw new TypeError(
                            'Arguments of a wrapped function must be primitives or callable objects',
                        );
                    }
                }

                $interp = Engine::getCurrentInterpreter();
                if ($interp === null) {
                    throw new TypeError('No interpreter available');
                }

                try {
                    $result = $interp->callFunction(
                        $targetFn,
                        JsUndefined::instance(),
                        $wrappedArgs,
                    );
                } catch (\PhpJs\Exceptions\RuntimeError $e) {
                    // Per spec: errors from wrapped function are converted to TypeError.
                    throw new TypeError($e->getMessage());
                }

                // Wrap return value.
                return self::getWrappedValue($result);
            },
        );

        // Per spec, wrapped function gets length and name from the target.
        $targetLength = $targetFn->get('length');
        if ($targetLength instanceof JsNumber) {
            $wrapped->defineOwnProperty('length', PropertyDescriptor::data(
                $targetLength,
                false,
                false,
                true,
            ));
        }
        $targetName = $targetFn->get('name');
        if ($targetName instanceof JsString) {
            $wrapped->defineOwnProperty('name', PropertyDescriptor::data(
                $targetName,
                false,
                false,
                true,
            ));
        }

        return $wrapped;
    }
}
