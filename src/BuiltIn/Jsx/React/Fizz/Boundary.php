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

/** A Suspense boundary. Status: 0 pending, 1 completed, 4 client-rendered, 5 postponed. */
final class Boundary
{
    public const PENDING = 0;
    public const COMPLETED = 1;
    public const CLIENT_RENDERED = 4;
    public const POSTPONED = 5;

    public int $status = self::PENDING;
    public int $rootSegmentID = -1;
    public bool $parentFlushed = false;
    public int $pendingTasks = 0;

    /** @var list<Segment> */
    public array $completedSegments = [];

    public int $byteSize = 0;
    public ?string $errorDigest = null;
    public HoistableState $contentState;
    public HoistableState $fallbackState;

    public function __construct(
        public TaskSet $fallbackAbortableTasks,
        public ?BoundaryPreamble $preamble,
        public bool $defer,
    ) {
        $this->contentState = new HoistableState();
        $this->fallbackState = new HoistableState();
    }
}
