<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\Exceptions\JsThrowable;
use PhpJs\Exceptions\RuntimeError;

/**
 * Represents a JavaScript async generator object returned by calling an async generator function.
 *
 * Like JsGenerator, this uses a PHP Fiber to pause and resume the function body.
 * The key difference is that next(), return(), and throw() return JsPromise
 * instances that resolve to iterator result objects {value, done}.
 *
 * In our synchronous execution model, the promises resolve immediately,
 * matching the behavior of our Promise and async/await implementations.
 */
class JsAsyncGenerator extends JsObject
{
    /** %AsyncGeneratorPrototype%: the intrinsic default [[Prototype]] for async generator instances. */
    private static ?JsObject $asyncGeneratorPrototype = null;

    public static function setAsyncGeneratorPrototype(JsObject $proto): void
    {
        self::$asyncGeneratorPrototype = $proto;
    }

    /** @var \Fiber<mixed, mixed, mixed, mixed> */
    private \Fiber $fiber;
    private bool $done = false;

    /** True while the generator body is actively running (not suspended). */
    private bool $executing = false;

    /**
     * @param JsFunction $generatorFn The async generator function that created this generator.
     * @param JsValue $thisValue The this-value for the generator function call.
     * @param list<JsValue> $args Arguments passed to the generator function.
     * @param \Closure(JsFunction, JsValue, list<JsValue>): JsValue $executor
     *   Closure that executes the generator function body. Provided by the interpreter.
     */
    public function __construct(
        JsFunction $generatorFn,
        JsValue $thisValue,
        array $args,
        \Closure $executor,
    ) {
        // Per spec: the [[Prototype]] is generatorFn.prototype if it's an Object,
        // otherwise falls back to the intrinsic %AsyncGeneratorPrototype%.
        $instanceProto = null;
        $fnProto = $generatorFn->get('prototype');
        if ($fnProto instanceof JsObject) {
            $instanceProto = $fnProto;
        } else {
            $instanceProto = self::$asyncGeneratorPrototype;
        }
        parent::__construct($instanceProto);

        // Ensure the prototype chain is properly wired to Object.prototype.
        if ($instanceProto !== null) {
            $tail = $instanceProto;
            while ($tail->getPrototype() !== null) {
                $tail = $tail->getPrototype();
            }
            $globalProto = JsObject::getGlobalPrototype();
            if ($globalProto !== null && $tail !== $globalProto) {
                $tail->setPrototype($globalProto);
            }
        }

        $fn = $generatorFn;
        $thisVal = $thisValue;
        $fnArgs = $args;

        $this->fiber = new \Fiber(static function () use ($executor, $fn, $thisVal, $fnArgs): mixed {
            return $executor($fn, $thisVal, $fnArgs);
        });
    }

    /**
     * Resume the async generator, optionally passing a value as the result of
     * the yield expression that last paused execution.
     *
     * @return JsPromise A promise that resolves to an iterator result object {value, done}.
     */
    public function next(?JsValue $value = null): JsPromise
    {
        if ($this->done) {
            return JsPromise::resolved($this->makeResult(JsUndefined::instance(), true));
        }

        if ($this->executing) {
            return JsPromise::rejected(
                $this->makeTypeError('Generator is already running')
            );
        }

        $value ??= JsUndefined::instance();
        $suspended = null;

        $this->executing = true;
        try {
            if (!$this->fiber->isStarted()) {
                $suspended = $this->fiber->start();
            } else {
                $suspended = $this->fiber->resume($value);
            }
            $this->executing = false;
        } catch (GeneratorReturnSignal $e) {
            $this->executing = false;
            $this->done = true;
            return JsPromise::resolved($this->makeResult($e->value, true));
        } catch (GeneratorThrowSignal $e) {
            $this->executing = false;
            $this->done = true;
            return JsPromise::rejected($e->jsValue);
        } catch (RuntimeError $e) {
            $this->executing = false;
            $this->done = true;
            if ($e instanceof JsThrowable) {
                return JsPromise::rejected($e->jsValue);
            }
            return JsPromise::rejected(self::errorToJsValue($e));
        } catch (\Throwable $e) {
            $this->executing = false;
            $this->done = true;
            return JsPromise::rejected(self::errorToJsValue($e));
        }

        if ($this->fiber->isTerminated()) {
            $this->done = true;
            $returnValue = $this->fiber->getReturn();
            if ($returnValue instanceof JsValue) {
                return JsPromise::resolved($this->makeResult($returnValue, true));
            }
            return JsPromise::resolved($this->makeResult(JsUndefined::instance(), true));
        }

        // Fiber is suspended. $suspended is the value passed to Fiber::suspend().
        if ($suspended instanceof YieldDelegateResult) {
            return JsPromise::resolved($suspended->result);
        }
        return JsPromise::resolved($this->makeResult(
            $suspended instanceof JsValue ? $suspended : JsUndefined::instance(),
            false,
        ));
    }

    /**
     * Force the async generator to return a specific value and mark it as done.
     *
     * @return JsPromise A promise that resolves to an iterator result object {value, done}.
     */
    public function returnValue(JsValue $value): JsPromise
    {
        if ($this->done) {
            return JsPromise::resolved($this->makeResult($value, true));
        }

        if ($this->executing) {
            return JsPromise::rejected(
                $this->makeTypeError('Generator is already running')
            );
        }

        if (!$this->fiber->isStarted() || $this->fiber->isTerminated()) {
            $this->done = true;
            return JsPromise::resolved($this->makeResult($value, true));
        }

        $suspended = null;
        $this->executing = true;
        try {
            $suspended = $this->fiber->throw(new GeneratorReturnSignal($value));
            $this->executing = false;
        } catch (GeneratorReturnSignal $e) {
            $this->executing = false;
            $this->done = true;
            return JsPromise::resolved($this->makeResult($e->value, true));
        } catch (GeneratorThrowSignal $e) {
            $this->executing = false;
            $this->done = true;
            return JsPromise::rejected($e->jsValue);
        } catch (RuntimeError $e) {
            $this->executing = false;
            $this->done = true;
            if ($e instanceof JsThrowable) {
                return JsPromise::rejected($e->jsValue);
            }
            return JsPromise::rejected(self::errorToJsValue($e));
        } catch (\Throwable $e) {
            $this->executing = false;
            $this->done = true;
            return JsPromise::rejected(self::errorToJsValue($e));
        }

        if ($this->fiber->isTerminated()) {
            $this->done = true;
            $returnValue = $this->fiber->getReturn();
            if ($returnValue instanceof JsValue) {
                return JsPromise::resolved($this->makeResult($returnValue, true));
            }
            return JsPromise::resolved($this->makeResult(JsUndefined::instance(), true));
        }

        if ($suspended instanceof YieldDelegateResult) {
            return JsPromise::resolved($suspended->result);
        }
        return JsPromise::resolved($this->makeResult(
            $suspended instanceof JsValue ? $suspended : JsUndefined::instance(),
            false,
        ));
    }

    /**
     * Throw an exception into the async generator at the point where it last
     * yielded, as if the yield expression threw.
     *
     * @return JsPromise A promise that resolves to an iterator result object {value, done}.
     */
    public function throwValue(JsValue $value): JsPromise
    {
        if ($this->done) {
            return JsPromise::rejected($value);
        }

        if ($this->executing) {
            return JsPromise::rejected(
                $this->makeTypeError('Generator is already running')
            );
        }

        if (!$this->fiber->isStarted()) {
            $this->done = true;
            return JsPromise::rejected($value);
        }

        $suspended = null;

        $this->executing = true;
        try {
            $suspended = $this->fiber->throw(new GeneratorThrowSignal($value));
            $this->executing = false;
        } catch (GeneratorThrowSignal $e) {
            $this->executing = false;
            $this->done = true;
            return JsPromise::rejected($e->jsValue);
        } catch (RuntimeError $e) {
            $this->executing = false;
            $this->done = true;
            if ($e instanceof JsThrowable) {
                return JsPromise::rejected($e->jsValue);
            }
            return JsPromise::rejected(self::errorToJsValue($e));
        } catch (\Throwable $e) {
            $this->executing = false;
            $this->done = true;
            return JsPromise::rejected(self::errorToJsValue($e));
        }

        if ($this->fiber->isTerminated()) {
            $this->done = true;
            $returnValue = $this->fiber->getReturn();
            if ($returnValue instanceof JsValue) {
                return JsPromise::resolved($this->makeResult($returnValue, true));
            }
            return JsPromise::resolved($this->makeResult(JsUndefined::instance(), true));
        }

        if ($suspended instanceof YieldDelegateResult) {
            return JsPromise::resolved($suspended->result);
        }
        return JsPromise::resolved($this->makeResult(
            $suspended instanceof JsValue ? $suspended : JsUndefined::instance(),
            false,
        ));
    }

    /**
     * Create an iterator result object: {value: V, done: D}.
     */
    private function makeResult(JsValue $value, bool $done): JsObject
    {
        $result = new JsObject();
        $result->set('value', $value);
        $result->set('done', new JsBoolean($done));
        return $result;
    }

    private function makeTypeError(string $message): JsObject
    {
        $error = new JsObject();
        $error->set('message', new JsString($message));
        $error->set('name', new JsString('TypeError'));
        return $error;
    }

    private static function errorToJsValue(\Throwable $e): JsValue
    {
        $error = new JsObject();
        $error->set('message', new JsString($e->getMessage()));
        $error->set('name', new JsString('Error'));
        return $error;
    }

    public function typeof(): string
    {
        return 'object';
    }

    public function toJsString(): string
    {
        return '[object AsyncGenerator]';
    }

    public function display(): string
    {
        return '[object AsyncGenerator]';
    }
}
