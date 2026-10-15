<?php

/**
 * Ported from React 19.3.0's Fizz server renderer (react-dom-server).
 *
 * Copyright (c) Meta Platforms, Inc. and affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree
 * (third-party/react/LICENSE in Phasis).
 */

declare(strict_types=1);

namespace Phasis\BuiltIn\Jsx\React\Fizz;

use Phasis\Value\JsValue;

/** One server render: its tasks, boundaries, output queues and callbacks. */
final class Request
{
    public const OPENING = 10;
    public const OPEN = 11;
    public const ABORTING = 12;
    public const CLOSING = 13;
    public const CLOSED = 14;

    public ?Destination $destination = null;
    public bool $flushScheduled = false;
    public int $status = self::OPENING;
    public ?JsValue $fatalError = null;
    public bool $aborted = false;
    public int $nextSegmentId = 0;
    public int $allPendingTasks = 0;
    public int $pendingRootTasks = 0;
    public ?Segment $completedRootSegment = null;

    /** @var list<list<Segment>>|null */
    public ?array $completedPreambleSegments = null;

    public int $byteSize = 0;

    public TaskSet $abortableTasks;

    /** @var list<Task> */
    public array $pingedTasks = [];

    public ?Task $currentTask = null;

    /** @var list<Boundary> */
    public array $clientRenderedBoundaries = [];

    /** @var list<Boundary> */
    public array $completedBoundaries = [];

    /** @var list<Boundary> */
    public array $partialBoundaries = [];

    /** Prerender marker: React tracks postponed work here; Phasis only needs to know it is a prerender. */
    public bool $trackedPostpones = false;

    /** Legacy renderToString/renderToStaticMarkup request (synchronous pings, no byte accounting). */
    public bool $legacy = false;

    /** @var \Closure(JsValue, array<string, mixed>): ?string */
    public \Closure $onError;

    /** @var \Closure(JsValue): void */
    public \Closure $onBrowserBailout;

    /** @var \Closure(): void */
    public \Closure $onAllReady;

    /** @var \Closure(): void */
    public \Closure $onShellReady;

    /** @var \Closure(JsValue): void */
    public \Closure $onShellError;

    /** @var \Closure(JsValue): void */
    public \Closure $onFatalError;

    public function __construct(
        public ResumableState $resumableState,
        public RenderState $renderState,
        public FormatContext $rootFormatContext,
        public float $progressiveChunkSize,
    ) {
        $this->abortableTasks = new TaskSet();
        $noop = static function (): void {
        };
        $this->onError = static fn (): ?string => null;
        $this->onBrowserBailout = $noop;
        $this->onAllReady = $noop;
        $this->onShellReady = $noop;
        $this->onShellError = $noop;
        $this->onFatalError = $noop;
    }
}
