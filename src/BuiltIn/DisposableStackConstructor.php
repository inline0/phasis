<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\TypeError;
use PhpJs\Object\PropertyDescriptor;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

/**
 * DisposableStack and AsyncDisposableStack built-in classes.
 *
 * Per the explicit resource management spec proposal.
 */
class DisposableStackConstructor
{
    private static ?Environment $globalEnv = null;

    public static function install(Environment $env): void
    {
        self::$globalEnv = $env;
        self::installDisposableStack($env);
        self::installAsyncDisposableStack($env);
    }

    private static function installDisposableStack(Environment $env): void
    {
        $proto = new JsObject();

        $ctor = JsFunction::fromCallable('DisposableStack', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                throw new TypeError('Constructor DisposableStack requires \'new\'');
            }
            $this_->defineOwnProperty('[[DisposableState]]', PropertyDescriptor::data(
                new JsString('pending'),
                true,
                false,
                false,
            ));
            $this_->defineOwnProperty('[[DisposableStack]]', PropertyDescriptor::data(
                new JsObject(),
                true,
                false,
                false,
            ));
            return $this_;
        }, 0);
        $ctor->setConstructable();

        // DisposableStack.prototype.use(value)
        $proto->defineOwnProperty('use', PropertyDescriptor::data(
            JsFunction::fromCallable('use', function (JsValue $this_, array $args): JsValue {
                $this_ = self::assertNotDisposed($this_);
                $value = $args[0] ?? JsUndefined::instance();
                if ($value instanceof JsNull || $value instanceof JsUndefined) {
                    return $value;
                }
                if (!$value instanceof JsObject) {
                    throw new TypeError('The value must be an object, null, or undefined.');
                }
                $method = $value->getBySymbol(SymbolConstructor::dispose());
                if ($method instanceof JsUndefined || $method instanceof JsNull) {
                    throw new TypeError('The value does not have a Symbol.dispose method.');
                }
                if (!$method instanceof JsFunction) {
                    throw new TypeError('Property [Symbol.dispose] is not a function.');
                }
                self::pushToStack($this_, $value, false);
                return $value;
            }, 1),
            true,
            false,
            true,
        ));

        // DisposableStack.prototype.adopt(value, onDispose)
        $proto->defineOwnProperty('adopt', PropertyDescriptor::data(
            JsFunction::fromCallable('adopt', function (JsValue $this_, array $args): JsValue {
                $this_ = self::assertNotDisposed($this_);
                $value = $args[0] ?? JsUndefined::instance();
                $onDispose = $args[1] ?? JsUndefined::instance();
                if (!$onDispose instanceof JsFunction) {
                    throw new TypeError('onDispose must be a function.');
                }
                // Wrap into a disposable object that calls onDispose(value).
                $wrapper = new JsObject();
                $disposeFn = JsFunction::fromCallable('[Symbol.dispose]', function (JsValue $t, array $a) use ($onDispose, $value): JsValue {
                    return $onDispose->call(JsUndefined::instance(), [$value]);
                }, 0);
                $wrapper->definePropertyBySymbol(
                    SymbolConstructor::dispose(),
                    PropertyDescriptor::data($disposeFn, true, false, true),
                );
                self::pushToStack($this_, $wrapper, false);
                return $value;
            }, 2),
            true,
            false,
            true,
        ));

        // DisposableStack.prototype.defer(onDispose)
        $proto->defineOwnProperty('defer', PropertyDescriptor::data(
            JsFunction::fromCallable('defer', function (JsValue $this_, array $args): JsValue {
                $this_ = self::assertNotDisposed($this_);
                $onDispose = $args[0] ?? JsUndefined::instance();
                if (!$onDispose instanceof JsFunction) {
                    throw new TypeError('onDispose must be a function.');
                }
                $wrapper = new JsObject();
                $disposeFn = JsFunction::fromCallable('[Symbol.dispose]', function (JsValue $t, array $a) use ($onDispose): JsValue {
                    return $onDispose->call(JsUndefined::instance(), []);
                }, 0);
                $wrapper->definePropertyBySymbol(
                    SymbolConstructor::dispose(),
                    PropertyDescriptor::data($disposeFn, true, false, true),
                );
                self::pushToStack($this_, $wrapper, false);
                return JsUndefined::instance();
            }, 1),
            true,
            false,
            true,
        ));

        // DisposableStack.prototype.dispose()
        $proto->defineOwnProperty('dispose', PropertyDescriptor::data(
            JsFunction::fromCallable('dispose', function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsObject) {
                    throw new TypeError('this is not a DisposableStack');
                }
                $state = $this_->get('[[DisposableState]]');
                if ($state instanceof JsString && $state->value === 'disposed') {
                    return JsUndefined::instance();
                }
                $this_->set('[[DisposableState]]', new JsString('disposed'));
                self::disposeStack($this_, false);
                return JsUndefined::instance();
            }, 0),
            true,
            false,
            true,
        ));

        // DisposableStack.prototype.move()
        $proto->defineOwnProperty('move', PropertyDescriptor::data(
            JsFunction::fromCallable('move', function (JsValue $this_): JsValue {
                $this_ = self::assertNotDisposed($this_);
                $newStack = new JsObject($this_->getPrototype());
                $newStack->defineOwnProperty('[[DisposableState]]', PropertyDescriptor::data(
                    new JsString('pending'),
                    true,
                    false,
                    false,
                ));
                $internalStack = $this_->get('[[DisposableStack]]');
                $newStack->defineOwnProperty('[[DisposableStack]]', PropertyDescriptor::data(
                    $internalStack,
                    true,
                    false,
                    false,
                ));
                // Mark old stack as disposed with empty stack.
                $this_->set('[[DisposableState]]', new JsString('disposed'));
                $this_->set('[[DisposableStack]]', new JsObject());
                return $newStack;
            }, 0),
            true,
            false,
            true,
        ));

        // DisposableStack.prototype.disposed (getter)
        $proto->defineOwnProperty('disposed', PropertyDescriptor::accessor(
            JsFunction::fromCallable('get disposed', function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsObject) {
                    throw new TypeError('this is not a DisposableStack');
                }
                $state = $this_->get('[[DisposableState]]');
                return new JsBoolean($state instanceof JsString && $state->value === 'disposed');
            }, 0),
            null,
            false,
            true,
        ));

        // DisposableStack.prototype[Symbol.dispose]()
        $disposeMethod = $proto->get('dispose');
        if ($disposeMethod instanceof JsFunction) {
            $proto->definePropertyBySymbol(
                SymbolConstructor::dispose(),
                PropertyDescriptor::data($disposeMethod, true, false, true),
            );
        }

        // DisposableStack.prototype[Symbol.toStringTag]
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('DisposableStack'), false, false, true),
        );

        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($ctor, true, false, true));
        $ctor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));

        $env->defineVar('DisposableStack', $ctor);
    }

    private static function installAsyncDisposableStack(Environment $env): void
    {
        $proto = new JsObject();

        $ctor = JsFunction::fromCallable('AsyncDisposableStack', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                throw new TypeError('Constructor AsyncDisposableStack requires \'new\'');
            }
            $this_->defineOwnProperty('[[DisposableState]]', PropertyDescriptor::data(
                new JsString('pending'),
                true,
                false,
                false,
            ));
            $this_->defineOwnProperty('[[DisposableStack]]', PropertyDescriptor::data(
                new JsObject(),
                true,
                false,
                false,
            ));
            return $this_;
        }, 0);
        $ctor->setConstructable();

        // AsyncDisposableStack.prototype.use(value)
        $proto->defineOwnProperty('use', PropertyDescriptor::data(
            JsFunction::fromCallable('use', function (JsValue $this_, array $args): JsValue {
                $this_ = self::assertNotDisposed($this_);
                $value = $args[0] ?? JsUndefined::instance();
                if ($value instanceof JsNull || $value instanceof JsUndefined) {
                    return $value;
                }
                if (!$value instanceof JsObject) {
                    throw new TypeError('The value must be an object, null, or undefined.');
                }
                $asyncMethod = $value->getBySymbol(SymbolConstructor::asyncDispose());
                $syncMethod = $value->getBySymbol(SymbolConstructor::dispose());
                $hasAsync = $asyncMethod instanceof JsFunction;
                $hasSync = $syncMethod instanceof JsFunction;
                // Check for non-callable but present symbols.
                if (!$hasAsync && !($asyncMethod instanceof JsUndefined || $asyncMethod instanceof JsNull)) {
                    throw new TypeError('Property [Symbol.asyncDispose] is not a function.');
                }
                if (!$hasAsync && !$hasSync) {
                    if (!($syncMethod instanceof JsUndefined || $syncMethod instanceof JsNull)) {
                        throw new TypeError('Property [Symbol.dispose] is not a function.');
                    }
                    throw new TypeError('The value does not have a dispose method.');
                }
                self::pushToStack($this_, $value, true);
                return $value;
            }, 1),
            true,
            false,
            true,
        ));

        // AsyncDisposableStack.prototype.adopt(value, onDispose)
        $proto->defineOwnProperty('adopt', PropertyDescriptor::data(
            JsFunction::fromCallable('adopt', function (JsValue $this_, array $args): JsValue {
                $this_ = self::assertNotDisposed($this_);
                $value = $args[0] ?? JsUndefined::instance();
                $onDispose = $args[1] ?? JsUndefined::instance();
                if (!$onDispose instanceof JsFunction) {
                    throw new TypeError('onDispose must be a function.');
                }
                $wrapper = new JsObject();
                $disposeFn = JsFunction::fromCallable('[Symbol.asyncDispose]', function (JsValue $t, array $a) use ($onDispose, $value): JsValue {
                    return $onDispose->call(JsUndefined::instance(), [$value]);
                }, 0);
                $wrapper->definePropertyBySymbol(
                    SymbolConstructor::asyncDispose(),
                    PropertyDescriptor::data($disposeFn, true, false, true),
                );
                self::pushToStack($this_, $wrapper, true);
                return $value;
            }, 2),
            true,
            false,
            true,
        ));

        // AsyncDisposableStack.prototype.defer(onDispose)
        $proto->defineOwnProperty('defer', PropertyDescriptor::data(
            JsFunction::fromCallable('defer', function (JsValue $this_, array $args): JsValue {
                $this_ = self::assertNotDisposed($this_);
                $onDispose = $args[0] ?? JsUndefined::instance();
                if (!$onDispose instanceof JsFunction) {
                    throw new TypeError('onDispose must be a function.');
                }
                $wrapper = new JsObject();
                $disposeFn = JsFunction::fromCallable('[Symbol.asyncDispose]', function (JsValue $t, array $a) use ($onDispose): JsValue {
                    return $onDispose->call(JsUndefined::instance(), []);
                }, 0);
                $wrapper->definePropertyBySymbol(
                    SymbolConstructor::asyncDispose(),
                    PropertyDescriptor::data($disposeFn, true, false, true),
                );
                self::pushToStack($this_, $wrapper, true);
                return JsUndefined::instance();
            }, 1),
            true,
            false,
            true,
        ));

        // AsyncDisposableStack.prototype.disposeAsync()
        $proto->defineOwnProperty('disposeAsync', PropertyDescriptor::data(
            JsFunction::fromCallable('disposeAsync', function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsObject) {
                    throw new TypeError('this is not an AsyncDisposableStack');
                }
                $state = $this_->get('[[DisposableState]]');
                if ($state instanceof JsString && $state->value === 'disposed') {
                    return JsUndefined::instance();
                }
                $this_->set('[[DisposableState]]', new JsString('disposed'));
                self::disposeStack($this_, true);
                return JsUndefined::instance();
            }, 0),
            true,
            false,
            true,
        ));

        // AsyncDisposableStack.prototype.move()
        $proto->defineOwnProperty('move', PropertyDescriptor::data(
            JsFunction::fromCallable('move', function (JsValue $this_): JsValue {
                $this_ = self::assertNotDisposed($this_);
                $newStack = new JsObject($this_->getPrototype());
                $newStack->defineOwnProperty('[[DisposableState]]', PropertyDescriptor::data(
                    new JsString('pending'),
                    true,
                    false,
                    false,
                ));
                $internalStack = $this_->get('[[DisposableStack]]');
                $newStack->defineOwnProperty('[[DisposableStack]]', PropertyDescriptor::data(
                    $internalStack,
                    true,
                    false,
                    false,
                ));
                $this_->set('[[DisposableState]]', new JsString('disposed'));
                $this_->set('[[DisposableStack]]', new JsObject());
                return $newStack;
            }, 0),
            true,
            false,
            true,
        ));

        // AsyncDisposableStack.prototype.disposed (getter)
        $proto->defineOwnProperty('disposed', PropertyDescriptor::accessor(
            JsFunction::fromCallable('get disposed', function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsObject) {
                    throw new TypeError('this is not an AsyncDisposableStack');
                }
                $state = $this_->get('[[DisposableState]]');
                return new JsBoolean($state instanceof JsString && $state->value === 'disposed');
            }, 0),
            null,
            false,
            true,
        ));

        // AsyncDisposableStack.prototype[Symbol.asyncDispose]()
        $disposeAsyncMethod = $proto->get('disposeAsync');
        if ($disposeAsyncMethod instanceof JsFunction) {
            $proto->definePropertyBySymbol(
                SymbolConstructor::asyncDispose(),
                PropertyDescriptor::data($disposeAsyncMethod, true, false, true),
            );
        }

        // AsyncDisposableStack.prototype[Symbol.toStringTag]
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('AsyncDisposableStack'), false, false, true),
        );

        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($ctor, true, false, true));
        $ctor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));

        $env->defineVar('AsyncDisposableStack', $ctor);
    }

    /**
     * Throw ReferenceError if the stack is already disposed.
     * Returns the validated JsObject for PHPStan type narrowing.
     */
    private static function assertNotDisposed(JsValue $this_): JsObject
    {
        if (!$this_ instanceof JsObject) {
            throw new TypeError('this is not a DisposableStack');
        }
        $state = $this_->get('[[DisposableState]]');
        if ($state instanceof JsString && $state->value === 'disposed') {
            throw new \PhpJs\Exceptions\ReferenceError(
                'Cannot add resource to a disposed stack.'
            );
        }
        return $this_;
    }

    /**
     * Push a resource onto the internal disposal stack.
     * The stack uses a JsObject with numeric string keys as a simple array.
     */
    private static function pushToStack(JsObject $this_, JsValue $resource, bool $isAsync): void
    {
        $stack = $this_->get('[[DisposableStack]]');
        if (!$stack instanceof JsObject) {
            $stack = new JsObject();
            $this_->set('[[DisposableStack]]', $stack);
        }
        $lengthVal = $stack->get('length');
        $length = ($lengthVal instanceof \PhpJs\Value\JsNumber) ? (int) $lengthVal->value : 0;

        $entry = new JsObject();
        $entry->set('resource', $resource);
        $entry->set('async', new JsBoolean($isAsync));
        $stack->set((string) $length, $entry);
        $stack->set('length', new \PhpJs\Value\JsNumber($length + 1));
    }

    /**
     * Dispose all resources on the internal stack in reverse order.
     * Accumulates errors using SuppressedError.
     */
    private static function disposeStack(JsObject $this_, bool $isAsync): void
    {
        $stack = $this_->get('[[DisposableStack]]');
        if (!$stack instanceof JsObject) {
            return;
        }
        $lengthVal = $stack->get('length');
        $length = ($lengthVal instanceof \PhpJs\Value\JsNumber) ? (int) $lengthVal->value : 0;

        $error = null;
        $hasError = false;

        for ($i = $length - 1; $i >= 0; $i--) {
            $entry = $stack->get((string) $i);
            if (!$entry instanceof JsObject) {
                continue;
            }
            $resource = $entry->get('resource');
            if (!$resource instanceof JsObject) {
                continue;
            }

            try {
                if ($isAsync) {
                    $method = $resource->getBySymbol(SymbolConstructor::asyncDispose());
                    if ($method instanceof JsUndefined || $method instanceof JsNull) {
                        $method = $resource->getBySymbol(SymbolConstructor::dispose());
                    }
                } else {
                    $method = $resource->getBySymbol(SymbolConstructor::dispose());
                }
                if ($method instanceof JsFunction) {
                    $method->call($resource, []);
                }
            } catch (\Throwable $e) {
                $newError = self::exceptionToJsValue($e);
                if ($hasError && $error !== null) {
                    $error = self::buildSuppressedError($newError, $error);
                } else {
                    $error = $newError;
                    $hasError = true;
                }
            }
        }

        if ($hasError && $error !== null) {
            throw new \PhpJs\Exceptions\JsThrowable($error);
        }
    }

    private static function exceptionToJsValue(\Throwable $e): JsValue
    {
        if ($e instanceof \PhpJs\Exceptions\JsThrowable) {
            return $e->jsValue;
        }
        $errObj = new JsObject();
        $errObj->set('message', new JsString($e->getMessage()));
        $errObj->set('name', new JsString((new \ReflectionClass($e))->getShortName()));
        $errObj->defineOwnProperty(
            '[[ErrorData]]',
            PropertyDescriptor::data(JsUndefined::instance(), false, false, false),
        );
        return $errObj;
    }

    private static function buildSuppressedError(JsValue $error, JsValue $suppressed): JsObject
    {
        if (self::$globalEnv !== null) {
            try {
                $ctor = self::$globalEnv->get('SuppressedError');
                if ($ctor instanceof JsFunction) {
                    $obj = new JsObject();
                    $obj->set('[[NewTarget]]', $ctor);
                    $proto = $ctor->get('prototype');
                    if ($proto instanceof JsObject) {
                        $obj->setPrototype($proto);
                    }
                    $result = $ctor->call($obj, [$error, $suppressed]);
                    if ($result instanceof JsObject) {
                        return $result;
                    }
                }
            } catch (\Throwable) {
                // Fall through to manual construction.
            }
        }
        $obj = new JsObject();
        $obj->set('name', new JsString('SuppressedError'));
        $obj->set('message', new JsString(''));
        $obj->set('error', $error);
        $obj->set('suppressed', $suppressed);
        $obj->defineOwnProperty(
            '[[ErrorData]]',
            PropertyDescriptor::data(JsUndefined::instance(), false, false, false),
        );
        return $obj;
    }
}
