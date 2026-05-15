<?php

declare(strict_types=1);

namespace Phasis\Runtime;

use Phasis\Engine;
use Phasis\Exceptions\JsThrowable;
use Phasis\Value\JsFunction;
use Phasis\Value\JsPromise;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * JavaScript event-loop driver — the macrotask side. Microtasks
 * (Promise resolutions, queueMicrotask) continue to live on
 * `JsPromise` and flow through `JsPromise::drainMicrotasks()`. The
 * EventLoop layers timers (setTimeout / setInterval) on top:
 *
 *   1. The owning Engine registers `tickDueTasks()` as JsPromise's
 *      post-drain hook. So every time the microtask queue empties,
 *      the loop checks the timer queue for tasks whose deadline has
 *      already passed and fires them. Each fired task usually
 *      schedules more microtasks, which JsPromise then drains before
 *      checking the hook again. This matches the HTML spec's
 *      "event loop iteration" shape.
 *
 *   2. `runUntilEmpty()` is the embedder-facing "drain everything"
 *      call. It loops: drain microtasks → fire all due timers → if
 *      any timer remains in the queue, advance the virtual clock to
 *      that timer's deadline and repeat. This is what test runners
 *      want — `setTimeout(cb, 5000)` resolves instantly because the
 *      virtual clock jumps. Real-time embedders use `tick()` instead
 *      and drive their own scheduling.
 *
 * Time model: the loop holds a `nowMs` cursor. By default it tracks
 * wall-clock (microtime); `runUntilEmpty()` may advance it past wall
 * time to drain pending timers. Tasks are stored in an array indexed
 * by id; firing scans for the earliest due task. The array is small
 * in practice (almost no JS code schedules more than a handful of
 * timers at once), so a heap is unnecessary.
 */
final class EventLoop
{
    /** @var array<int, EventLoopTask> */
    private array $tasks = [];

    private int $nextId = 1;

    /**
     * Current virtual time in milliseconds. Initialized to wall time
     * and re-synced on each tick() so wall-clock deadlines are
     * meaningful; advanced past wall time only during runUntilEmpty()
     * when the queue would otherwise stall.
     */
    private float $nowMs;

    public function __construct(private readonly Engine $engine)
    {
        $this->nowMs = self::wallTimeMs();
        JsPromise::setPostDrainHook(function (): bool {
            return $this->fireDueTasks();
        });
    }

    /** Detach our post-drain hook. Called on Engine::reset(). */
    public function detach(): void
    {
        JsPromise::setPostDrainHook(null);
    }

    /** Schedule a one-shot task. Returns an opaque id usable with clear(). */
    public function setTimeout(JsFunction $cb, float $delayMs): int
    {
        $this->advanceClock();
        // Per spec WindowOrWorkerGlobalScope §timers: negative or NaN
        // delays clamp to zero. Sub-millisecond floats are honored.
        $delay = $delayMs > 0 ? $delayMs : 0.0;
        $id = $this->nextId++;
        $this->tasks[$id] = new EventLoopTask(
            id: $id,
            deadlineMs: $this->nowMs + $delay,
            callback: $cb,
            repeating: false,
            intervalMs: 0.0,
        );
        return $id;
    }

    /** Schedule a repeating task. */
    public function setInterval(JsFunction $cb, float $intervalMs): int
    {
        $this->advanceClock();
        // Per HTML spec, intervals < 4ms clamp to 4ms once the same
        // page has nested 5 deep, but for a non-browser engine the
        // minimum-of-1ms rule from Node is the practical guard. We
        // keep it permissive — sub-ms intervals just fire every tick.
        $interval = $intervalMs > 0 ? $intervalMs : 0.0;
        $id = $this->nextId++;
        $this->tasks[$id] = new EventLoopTask(
            id: $id,
            deadlineMs: $this->nowMs + $interval,
            callback: $cb,
            repeating: true,
            intervalMs: $interval,
        );
        return $id;
    }

    /**
     * Cancel a scheduled task. Returns true if the task was pending,
     * false if the id was unknown or the task already cancelled.
     */
    public function clear(int $id): bool
    {
        $task = $this->tasks[$id] ?? null;
        if ($task === null || $task->cancelled) {
            return false;
        }
        $task->cancelled = true;
        unset($this->tasks[$id]);
        return true;
    }

    /**
     * Fire every task whose deadline is at or before the current
     * virtual clock. Returns true if at least one task fired (signal
     * to the microtask drain loop that more work may now be pending).
     */
    public function fireDueTasks(): bool
    {
        if ($this->tasks === []) {
            return false;
        }
        $this->advanceClock();
        $fired = false;
        // Iterate while due tasks remain. The task stays IN $tasks
        // while its callback runs so that clearTimeout / clearInterval
        // invoked from inside the callback can find and cancel it.
        // Post-callback we either remove (one-shot or cancelled) or
        // re-arm the deadline (repeating + still live).
        while (true) {
            $task = $this->peekEarliestDue();
            if ($task === null) {
                break;
            }
            $fired = true;
            $this->invoke($task);
            if ($task->cancelled || !$task->repeating) {
                // Cancelled by clearInterval inside the callback, or
                // never repeating in the first place. Remove if still
                // present — clear() may have unset it already.
                unset($this->tasks[$task->id]);
            } else {
                // Still live; re-arm. Use the current virtual clock
                // (which advanceClock may have advanced past the old
                // deadline) so a slow callback doesn't generate
                // multiple back-to-back fires for one missed period.
                $task->deadlineMs = $this->nowMs + $task->intervalMs;
            }
        }
        return $fired;
    }

    /**
     * Drain microtasks + macrotasks until both queues are empty. When
     * timers remain but their deadlines are in the future, advance
     * the virtual clock to the earliest deadline and continue. This
     * is the "run my program to completion" entry point.
     *
     * @param int $maxIters Safety cap on full microtask+timer cycles.
     */
    public function runUntilEmpty(int $maxIters = 100_000): void
    {
        for ($i = 0; $i < $maxIters; $i++) {
            JsPromise::drainMicrotasks();
            if ($this->tasks === []) {
                return;
            }
            // Microtasks emptied + no due timer + queue not empty
            // means every pending task is in the future. Jump the
            // virtual clock so they become due, then loop. The
            // post-drain hook will fire them on the next drain.
            $next = $this->earliestDeadline();
            if ($next === null) {
                return;
            }
            if ($next > $this->nowMs) {
                $this->nowMs = $next;
            }
            // Force one more drain pass; if fireDueTasks() schedules
            // microtasks, drainMicrotasks() on the next iteration
            // processes them.
            $this->fireDueTasks();
        }
        throw new \RuntimeException(
            "EventLoop::runUntilEmpty exceeded {$maxIters} iterations — likely a runaway setInterval"
        );
    }

    /**
     * Run a single tick: drain microtasks, fire any due timers, then
     * return. For real-time embedders driving the loop from PHP — no
     * virtual clock jump. Returns true if any work was done.
     */
    public function tick(): bool
    {
        JsPromise::drainMicrotasks();
        return $this->fireDueTasks();
    }

    /** Number of pending tasks (excluding cancelled). */
    public function pendingTaskCount(): int
    {
        return count($this->tasks);
    }

    /** True when no tasks are pending. */
    public function isEmpty(): bool
    {
        return $this->tasks === [];
    }

    /**
     * Milliseconds until the earliest pending task's deadline. Null
     * when the queue is empty. Negative when at least one task is
     * already overdue.
     */
    public function nextDelayMs(): ?float
    {
        $next = $this->earliestDeadline();
        if ($next === null) {
            return null;
        }
        return $next - self::wallTimeMs();
    }

    private function earliestDeadline(): ?float
    {
        $min = null;
        foreach ($this->tasks as $task) {
            if ($task->cancelled) {
                continue;
            }
            if ($min === null || $task->deadlineMs < $min) {
                $min = $task->deadlineMs;
            }
        }
        return $min;
    }

    private function peekEarliestDue(): ?EventLoopTask
    {
        $bestId = null;
        $bestDeadline = INF;
        foreach ($this->tasks as $id => $task) {
            if ($task->cancelled) {
                continue;
            }
            if ($task->deadlineMs <= $this->nowMs && $task->deadlineMs < $bestDeadline) {
                $bestId = $id;
                $bestDeadline = $task->deadlineMs;
            }
        }
        return $bestId === null ? null : $this->tasks[$bestId];
    }

    private function advanceClock(): void
    {
        $wall = self::wallTimeMs();
        if ($wall > $this->nowMs) {
            $this->nowMs = $wall;
        }
    }

    private function invoke(EventLoopTask $task): void
    {
        try {
            $this->engine->getInterpreter()->callFunction(
                $task->callback,
                JsUndefined::instance(),
                [],
            );
        } catch (\Throwable $e) {
            // Per HTML spec, uncaught errors from a timer callback go
            // to the "report an error" algorithm — which in a host
            // context fires the global error event. Phasis has no
            // such surface; surface via PHP error_log so embedders
            // can see it, then keep the loop running so a single
            // throwing timer doesn't kill the program.
            error_log("Phasis: uncaught in setTimeout/setInterval: " . $e->getMessage());
        }
    }

    private static function wallTimeMs(): float
    {
        return microtime(true) * 1000.0;
    }

    /**
     * Test-only: pretend the clock advanced. Used by tests that want
     * to simulate timer expiry without sleeping. Embedders should use
     * runUntilEmpty().
     *
     * @internal
     */
    public function advanceVirtualClock(float $deltaMs): void
    {
        $this->nowMs += $deltaMs;
    }
}
