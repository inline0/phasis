<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\BuiltIn\IteratorPrototypes;
use PhpJs\BuiltIn\SymbolConstructor;
use PhpJs\Exceptions\JsThrowable;
use PhpJs\Exceptions\RuntimeError;

/**
 * Represents a JavaScript generator object returned by calling a generator function.
 *
 * Uses a PHP Fiber to pause and resume the generator function body. When the
 * interpreter encounters a yield expression inside a generator, it suspends
 * the Fiber via Fiber::suspend(). Calling next() on the generator resumes
 * the Fiber, passing a value back as the result of the yield expression.
 *
 * Fiber::start() and Fiber::resume() return the value passed to Fiber::suspend().
 * This is how yielded values flow out of the generator.
 */
class JsGenerator extends JsObject
{
    /** @var \Fiber<mixed, mixed, mixed, mixed> */
    private \Fiber $fiber;
    private bool $done = false;

    /**
     * @param JsFunction $generatorFn The generator function that created this generator.
     * @param JsValue $thisValue The this-value for the generator function call.
     * @param list<JsValue> $args Arguments passed to the generator function.
     * @param \Closure(JsFunction, JsValue, list<JsValue>): JsValue $executor
     *   Closure that executes the generator function body. Provided by the interpreter.
     * @param JsObject|null $instanceProto The [[Prototype]] for this generator instance.
     *   Should be the generator function's .prototype property (or %GeneratorPrototype% as fallback).
     */
    public function __construct(
        JsFunction $generatorFn,
        JsValue $thisValue,
        array $args,
        \Closure $executor,
        ?JsObject $instanceProto = null,
    ) {
        parent::__construct($instanceProto ?? IteratorPrototypes::generatorPrototype());

        $fn = $generatorFn;
        $thisVal = $thisValue;
        $fnArgs = $args;

        $this->fiber = new \Fiber(static function () use ($executor, $fn, $thisVal, $fnArgs): mixed {
            return $executor($fn, $thisVal, $fnArgs);
        });

        // Install Symbol.iterator returning this (generators are self-iterable).
        $self = $this;
        $iteratorFn = JsFunction::fromCallable('[Symbol.iterator]', static function () use ($self): JsValue {
            return $self;
        });
        $this->setBySymbol(SymbolConstructor::iterator(), $iteratorFn);
    }

    /**
     * Resume the generator, optionally passing a value as the result of
     * the yield expression that last paused execution.
     *
     * @return JsObject An iterator result object {value, done}.
     */
    public function next(?JsValue $value = null): JsObject
    {
        if ($this->done) {
            return $this->makeResult(JsUndefined::instance(), true);
        }

        $value ??= JsUndefined::instance();
        $suspended = null;

        try {
            if (!$this->fiber->isStarted()) {
                // First call to next(). Start the fiber. The first argument
                // to next() is ignored per spec (the value has nowhere to go).
                $suspended = $this->fiber->start();
            } else {
                // Subsequent calls. Resume the fiber, passing the value
                // as the result of the yield expression.
                $suspended = $this->fiber->resume($value);
            }
        } catch (GeneratorReturnSignal $e) {
            // The generator.return() method forced a return.
            $this->done = true;
            return $this->makeResult($e->value, true);
        } catch (GeneratorThrowSignal $e) {
            // A throw propagated back out without being caught.
            $this->done = true;
            throw $this->toRuntimeError($e->jsValue);
        } catch (RuntimeError $e) {
            $this->done = true;
            throw $e;
        } catch (\Throwable $e) {
            $this->done = true;
            throw $e;
        }

        if ($this->fiber->isTerminated()) {
            $this->done = true;
            $returnValue = $this->fiber->getReturn();
            if ($returnValue instanceof JsValue) {
                return $this->makeResult($returnValue, true);
            }
            return $this->makeResult(JsUndefined::instance(), true);
        }

        // Fiber is suspended. $suspended is the value passed to Fiber::suspend(),
        // which is the yielded value.
        return $this->makeResult(
            $suspended instanceof JsValue ? $suspended : JsUndefined::instance(),
            false,
        );
    }

    /**
     * Force the generator to return a specific value and mark it as done.
     *
     * @return JsObject An iterator result object {value, done: true}.
     */
    public function returnValue(JsValue $value): JsObject
    {
        if ($this->done) {
            return $this->makeResult($value, true);
        }

        $this->done = true;

        if ($this->fiber->isStarted() && !$this->fiber->isTerminated()) {
            try {
                $this->fiber->throw(new GeneratorReturnSignal($value));
            } catch (GeneratorReturnSignal) {
                // Expected: the signal propagated back out because nothing caught it.
            } catch (\Throwable) {
                // The generator's finally blocks may have run and thrown something else.
            }
        }

        return $this->makeResult($value, true);
    }

    /**
     * Throw an exception into the generator at the point where it last
     * yielded, as if the yield expression threw.
     *
     * @return JsObject An iterator result object {value, done}.
     */
    public function throwValue(JsValue $value): JsObject
    {
        if ($this->done) {
            throw $this->toRuntimeError($value);
        }

        if (!$this->fiber->isStarted()) {
            $this->done = true;
            throw $this->toRuntimeError($value);
        }

        $suspended = null;

        try {
            $suspended = $this->fiber->throw(new GeneratorThrowSignal($value));
        } catch (GeneratorThrowSignal $e) {
            $this->done = true;
            throw $this->toRuntimeError($e->jsValue);
        } catch (RuntimeError $e) {
            $this->done = true;
            throw $e;
        } catch (\Throwable $e) {
            $this->done = true;
            throw $e;
        }

        if ($this->fiber->isTerminated()) {
            $this->done = true;
            $returnValue = $this->fiber->getReturn();
            if ($returnValue instanceof JsValue) {
                return $this->makeResult($returnValue, true);
            }
            return $this->makeResult(JsUndefined::instance(), true);
        }

        // Fiber is suspended: the generator caught the thrown exception
        // and yielded a new value.
        return $this->makeResult(
            $suspended instanceof JsValue ? $suspended : JsUndefined::instance(),
            false,
        );
    }

    /**
     * Convert a JS value to a PHP exception that preserves the original value.
     */
    private function toRuntimeError(JsValue $value): RuntimeError
    {
        return new JsThrowable($value);
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

    public function typeof(): string
    {
        return 'object';
    }

    public function toJsString(): string
    {
        return '[object Generator]';
    }

    public function display(): string
    {
        return '[object Generator]';
    }
}
