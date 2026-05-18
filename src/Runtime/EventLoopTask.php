<?php

declare(strict_types=1);

namespace Phasis\Runtime;

use Phasis\Value\JsFunction;
use Phasis\Value\JsValue;

/**
 * One scheduled task on the EventLoop's macrotask (timer) queue.
 *
 * `deadline` is a millisecond timestamp on the same clock as the
 * EventLoop's wall-time reference; `cancelled` lets clearTimeout/
 * clearInterval invalidate a task without expensive removal from the
 * priority structure. Repeating tasks (setInterval) carry their
 * interval so the loop can re-schedule the next firing on drain.
 *
 * `contextSnapshot` is the AsyncContext storage captured at schedule
 * time; the loop restores it around `callback` invocation so context
 * values flow naturally across the setTimeout / setInterval boundary
 * (TC39 Stage 3 AsyncContext proposal).
 */
final class EventLoopTask
{
    public bool $cancelled = false;

    /**
     * @param array{0: array<int, \Phasis\Value\JsObject>, 1: array<int, \Phasis\Value\JsValue>} $contextSnapshot
     * @param list<JsValue> $callbackArgs Extra args from
     *        `setTimeout(cb, delay, ...args)` / `setInterval(cb, delay, ...args)`.
     *        Per WindowOrWorkerGlobalScope §timers these are forwarded
     *        to the callback on every firing.
     */
    public function __construct(
        public readonly int $id,
        public float $deadlineMs,
        public readonly JsFunction $callback,
        public readonly bool $repeating,
        public readonly float $intervalMs,
        public readonly array $contextSnapshot,
        public readonly array $callbackArgs = [],
    ) {
    }
}
