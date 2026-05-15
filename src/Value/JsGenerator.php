<?php

declare(strict_types=1);

namespace Phasis\Value;

use Phasis\Exceptions\JsThrowable;
use Phasis\Exceptions\RuntimeError;
use Phasis\Object\PropertyDescriptor;

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
    /** %GeneratorPrototype%: the intrinsic default [[Prototype]] for generator instances. */
    private static ?JsObject $generatorPrototype = null;

    public static function setGeneratorPrototype(JsObject $proto): void
    {
        self::$generatorPrototype = $proto;
    }

    /** @var \Fiber<mixed, mixed, mixed, mixed>|null */
    private ?\Fiber $fiber = null;
    private bool $done = false;

    /** True while the generator body is actively running (not suspended). */
    private bool $executing = false;

    /**
     * Snapshot-mode state. When non-null this generator is driven by
     * the bytecode VM's frame-snapshot path: `next()`, `return()`,
     * and `throw()` call back into `Interpreter::resumeVmGenerator`
     * with the saved frame state and the resume value, instead of
     * threading control through a PHP Fiber.
     */
    private ?\Phasis\Bytecode\GeneratorSnapshot $snapshot = null;

    /** Interpreter handle, used in snapshot mode to invoke the resume. */
    private ?\Phasis\Runtime\Interpreter $interp = null;

    /** Has next() ever been called in snapshot mode? Distinguishes first-call from later resumes. */
    private bool $snapshotStarted = false;

    /**
     * @param JsFunction $generatorFn The generator function that created this generator.
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
        // Per spec §27.5.1 via OrdinaryCreateFromConstructor:
        // The [[Prototype]] is generatorFn.prototype if it's an Object,
        // otherwise falls back to the intrinsic %GeneratorPrototype%.
        $instanceProto = null;
        $fnProto = $generatorFn->get('prototype');
        if ($fnProto instanceof JsObject) {
            $instanceProto = $fnProto;
        } else {
            $instanceProto = self::$generatorPrototype;
        }
        parent::__construct($instanceProto);

        // Ensure the prototype chain is properly wired to Object.prototype.
        // %IteratorPrototype% may have been created before Object.prototype was
        // set as the global prototype. Walk up and fix any broken link.
        if ($instanceProto !== null) {
            $tail = $instanceProto;
            while ($tail->getPrototype() !== null) {
                $tail = $tail->getPrototype();
            }
            // If the tail is not Object.prototype itself (Object.prototype has
            // null [[Prototype]]), wire it up so toString, etc. are reachable.
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
     * Build a snapshot-mode generator. The body has already been
     * compiled to bytecode; calls to next() / return() / throw()
     * drive the VM via `Interpreter::resumeVmGenerator` instead of
     * a PHP Fiber.
     */
    public static function fromSnapshot(
        JsFunction $generatorFn,
        JsValue $thisValue,
        \Phasis\Bytecode\GeneratorSnapshot $snapshot,
        \Phasis\Runtime\Interpreter $interp,
    ): self {
        $g = self::createWithoutFiber($generatorFn, $thisValue);
        $g->snapshot = $snapshot;
        $g->interp = $interp;
        return $g;
    }

    /**
     * Construct a JsGenerator skeleton for snapshot mode without
     * initialising a Fiber. The constructor takes a Closure
     * executor (Fiber-only) so snapshot-mode entries skip it via
     * this factory.
     */
    private static function createWithoutFiber(JsFunction $generatorFn, JsValue $thisValue): self
    {
        // Use ReflectionClass to bypass __construct so we can set
        // the prototype-chain bits without paying for the Fiber
        // allocation the constructor would do.
        $g = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        // Wire up the prototype chain manually, mirroring
        // __construct's setup.
        $instanceProto = null;
        $fnProto = $generatorFn->get('prototype');
        if ($fnProto instanceof JsObject) {
            $instanceProto = $fnProto;
        } else {
            $instanceProto = self::$generatorPrototype;
        }
        $g->properties = new \Phasis\Object\PropertyMap();
        $g->setPrototype($instanceProto);
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
        return $g;
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

        // Per spec 27.5.3.2 GeneratorValidate step 5: if state is "executing", throw TypeError.
        if ($this->executing) {
            throw new \Phasis\Exceptions\TypeError(
                'Generator is already running',
            );
        }

        if ($this->snapshot !== null) {
            return $this->nextSnapshot($value);
        }

        $value ??= JsUndefined::instance();
        $suspended = null;

        $this->executing = true;
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
            $this->executing = false;
        } catch (GeneratorReturnSignal $e) {
            // The generator.return() method forced a return.
            $this->executing = false;
            $this->done = true;
            return $this->makeResult($e->value, true);
        } catch (GeneratorThrowSignal $e) {
            // A throw propagated back out without being caught.
            $this->executing = false;
            $this->done = true;
            throw $this->toRuntimeError($e->jsValue);
        } catch (RuntimeError $e) {
            $this->executing = false;
            $this->done = true;
            throw $e;
        } catch (\Throwable $e) {
            $this->executing = false;
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
        // For yield* delegation, the suspended value is a YieldDelegateResult
        // containing the inner iterator's result object. Return it directly.
        if ($suspended instanceof YieldDelegateResult) {
            return $suspended->result;
        }
        return $this->makeResult(
            $suspended instanceof JsValue ? $suspended : JsUndefined::instance(),
            false,
        );
    }

    /**
     * Force the generator to return a specific value and mark it as done.
     *
     * Per ES spec 27.5.3.4 GeneratorResumeAbrupt for return completions:
     * the return signal is thrown into the fiber. If the generator body is
     * inside a yield* delegation or has a finally block, the fiber may
     * suspend again rather than terminate. In that case the generator is
     * NOT done yet and we return the inner iterator's result.
     *
     * @return JsObject An iterator result object {value, done}.
     */
    public function returnValue(JsValue $value): JsObject
    {
        if ($this->done) {
            return $this->makeResult($value, true);
        }

        // Per spec 27.5.3.2 GeneratorValidate step 5: if state is "executing", throw TypeError.
        if ($this->executing) {
            throw new \Phasis\Exceptions\TypeError(
                'Generator is already running',
            );
        }

        if ($this->snapshot !== null) {
            $this->done = true;
            $this->snapshot->done = true;
            return $this->makeResult($value, true);
        }

        if (!$this->fiber->isStarted() || $this->fiber->isTerminated()) {
            $this->done = true;
            return $this->makeResult($value, true);
        }

        $suspended = null;
        $this->executing = true;
        try {
            $suspended = $this->fiber->throw(new GeneratorReturnSignal($value));
            $this->executing = false;
        } catch (GeneratorReturnSignal $e) {
            // The signal propagated back out (not caught by the body).
            $this->executing = false;
            $this->done = true;
            return $this->makeResult($e->value, true);
        } catch (GeneratorThrowSignal $e) {
            $this->executing = false;
            $this->done = true;
            throw $this->toRuntimeError($e->jsValue);
        } catch (RuntimeError $e) {
            $this->executing = false;
            $this->done = true;
            throw $e;
        } catch (\Throwable $e) {
            $this->executing = false;
            $this->done = true;
            throw $e;
        }

        // After throw, the fiber is either terminated (body returned via
        // finally) or suspended again (body yielded inside finally / yield*).
        // Read the post-throw state through a helper that PHPStan does not
        // narrow across, since the impure stub on Fiber::throw alone is not
        // sufficient to clear the state established by the entry guard above.
        if ($this->isFiberDone()) {
            $this->done = true;
            $returnValue = $this->fiber->getReturn();
            if ($returnValue instanceof JsValue) {
                return $this->makeResult($returnValue, true);
            }
            return $this->makeResult(JsUndefined::instance(), true);
        }

        // Fiber is suspended: the generator body (via yield* or finally) yielded
        // a new value. The generator is not done yet.
        if ($suspended instanceof YieldDelegateResult) {
            return $suspended->result;
        }
        return $this->makeResult(
            $suspended instanceof JsValue ? $suspended : JsUndefined::instance(),
            false,
        );
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

        // Per spec 27.5.3.2 GeneratorValidate step 5: if state is "executing", throw TypeError.
        if ($this->executing) {
            throw new \Phasis\Exceptions\TypeError(
                'Generator is already running',
            );
        }

        if ($this->snapshot !== null) {
            return $this->throwSnapshot($value);
        }

        if (!$this->fiber->isStarted()) {
            $this->done = true;
            throw $this->toRuntimeError($value);
        }

        $suspended = null;

        $this->executing = true;
        try {
            $suspended = $this->fiber->throw(new GeneratorThrowSignal($value));
            $this->executing = false;
        } catch (GeneratorThrowSignal $e) {
            $this->executing = false;
            $this->done = true;
            throw $this->toRuntimeError($e->jsValue);
        } catch (RuntimeError $e) {
            $this->executing = false;
            $this->done = true;
            throw $e;
        } catch (\Throwable $e) {
            $this->executing = false;
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
        if ($suspended instanceof YieldDelegateResult) {
            return $suspended->result;
        }
        return $this->makeResult(
            $suspended instanceof JsValue ? $suspended : JsUndefined::instance(),
            false,
        );
    }

    /**
     * Snapshot-mode `next()`: hand the saved frame state + resume
     * value to the VM, get back either a YieldResult (next suspend)
     * or a JsValue (body returned).
     */
    private function nextSnapshot(?JsValue $value): JsObject
    {
        $value ??= JsUndefined::instance();
        $this->executing = true;
        try {
            $isFirst = !$this->snapshotStarted;
            $this->snapshotStarted = true;
            // First call: ignore the passed value (spec: the
            // first next()'s argument has nowhere to go since no
            // yield expression has run yet). Subsequent calls feed
            // it as the result of the yield that suspended us.
            $result = $this->interp->resumeVmGenerator(
                $this->snapshot,
                $isFirst ? null : $value,
                'next',
            );
        } catch (RuntimeError $e) {
            $this->executing = false;
            $this->done = true;
            $this->snapshot->done = true;
            throw $e;
        } catch (\Throwable $e) {
            $this->executing = false;
            $this->done = true;
            $this->snapshot->done = true;
            throw $e;
        }
        $this->executing = false;
        if ($result instanceof \Phasis\Bytecode\YieldResult) {
            // Body suspended at a YIELD opcode. Replace the
            // captured snapshot with the new one for the next
            // resume; surface the yielded value.
            $this->snapshot = $result->snapshot;
            return $this->makeResult($result->value, false);
        }
        // Body returned (RET at outer frame). Done.
        $this->done = true;
        return $this->makeResult($result, true);
    }

    /**
     * Snapshot-mode `throw()`: resume with the throw signal active.
     * The body's safety scan ruled out any try/catch around yield,
     * so the thrown value escapes the generator body and propagates
     * to the caller. The generator is marked done either way.
     */
    private function throwSnapshot(JsValue $value): JsObject
    {
        $this->executing = true;
        if (!$this->snapshotStarted) {
            // Spec: throw() on a not-yet-started generator throws
            // the value directly at the caller (after marking the
            // generator done).
            $this->snapshotStarted = true;
            $this->executing = false;
            $this->done = true;
            $this->snapshot->done = true;
            throw $this->toRuntimeError($value);
        }
        try {
            $result = $this->interp->resumeVmGenerator(
                $this->snapshot,
                $value,
                'throw',
            );
        } catch (RuntimeError $e) {
            $this->executing = false;
            $this->done = true;
            $this->snapshot->done = true;
            throw $e;
        } catch (\Throwable $e) {
            $this->executing = false;
            $this->done = true;
            $this->snapshot->done = true;
            throw $e;
        }
        $this->executing = false;
        if ($result instanceof \Phasis\Bytecode\YieldResult) {
            // Generator caught the throw and yielded a new value.
            // (Possible in principle if generatorBodyIsSnapshotSafe
            // ever relaxes its "no try-with-yield" rule — defensive
            // for now.)
            $this->snapshot = $result->snapshot;
            return $this->makeResult($result->value, false);
        }
        $this->done = true;
        return $this->makeResult($result, true);
    }

    /**
     * Convert a JS value to a PHP exception that preserves the original value.
     */
    private function toRuntimeError(JsValue $value): RuntimeError
    {
        return new JsThrowable($value);
    }

    /**
     * Read the fiber's post-throw termination state through a helper so
     * static analysis sees a fresh check, not the entry-guard narrowing.
     * Fiber::throw can transition the fiber from suspended to terminated
     * (when the body returns inside a finally) and that transition has to
     * be observable to the caller.
     */
    private function isFiberDone(): bool
    {
        return $this->fiber->isTerminated();
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
