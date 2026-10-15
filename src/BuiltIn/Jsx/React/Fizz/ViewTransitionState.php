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

/** A `<ViewTransition>` in scope, for the vt-* attributes. */
final class ViewTransitionState
{
    public function __construct(
        public ?string $update,
        public ?string $enter,
        public ?string $exit,
        public ?string $share,
        public string $parentEnter,
        public string $parentExit,
        public ?string $name,
        public ?string $autoName,
        public int $nameIdx,
    ) {
    }
}
