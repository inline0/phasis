<?php

declare(strict_types=1);

namespace Phasis\Runtime;

use Phasis\Value\JsFunction;

/**
 * One scheduled task on the EventLoop's macrotask (timer) queue.
 *
 * `deadline` is a millisecond timestamp on the same clock as the
 * EventLoop's wall-time reference; `cancelled` lets clearTimeout/
 * clearInterval invalidate a task without expensive removal from the
 * priority structure. Repeating tasks (setInterval) carry their
 * interval so the loop can re-schedule the next firing on drain.
 */
final class EventLoopTask
{
    public bool $cancelled = false;

    public function __construct(
        public readonly int $id,
        public float $deadlineMs,
        public readonly JsFunction $callback,
        public readonly bool $repeating,
        public readonly float $intervalMs,
    ) {
    }
}
