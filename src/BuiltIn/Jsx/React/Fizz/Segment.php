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

/** A run of output chunks with child segments spliced in at chunk indexes. */
final class Segment
{
    public const PENDING = 0;
    public const COMPLETED = 1;
    public const FLUSHED = 2;
    public const ABORTED = 3;
    public const ERRORED = 4;
    public const POSTPONED = 5;

    public int $status = self::PENDING;
    public bool $parentFlushed = false;
    public int $id = -1;

    /** @var list<string> */
    public array $chunks = [];

    /** @var list<Segment> */
    public array $children = [];

    /** @var list<Segment> */
    public array $preambleChildren = [];

    public function __construct(
        public int $index,
        public ?Boundary $boundary,
        public FormatContext $parentFormatContext,
        public bool $lastPushedText,
        public bool $textEmbedded,
    ) {
    }
}
