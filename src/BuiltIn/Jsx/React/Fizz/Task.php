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

/** A unit of render work: a node to render into a segment, retried when its thenable settles. */
final class Task
{
    public ?JsValue $pingResolve = null;
    public ?JsValue $pingReject = null;

    /**
     * @param list<JsValue>|null $thenableState
     * @param array{0: mixed, 1: ?string, 2: string|int}|null $keyPath
     */
    public function __construct(
        public JsValue $node,
        public int $childIndex,
        public ?Boundary $blockedBoundary,
        public ?Segment $blockedSegment,
        public ?PreambleState $blockedPreamble,
        public ?HoistableState $hoistableState,
        public TaskSet $abortSet,
        public ?array $keyPath,
        public FormatContext $formatContext,
        public ?ContextNode $context,
        public TreeContext $treeContext,
        public ?array $thenableState,
    ) {
    }
}
