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

/**
 * Where in the document output is going: insertion mode (root, html, body,
 * head, svg, math, table parts), the selected value of an enclosing
 * `<select>`, tag-scope flags and the enclosing view transition.
 */
final class FormatContext
{
    public function __construct(
        public readonly int $insertionMode,
        public readonly ?JsValue $selectedValue,
        public readonly int $tagScope,
        public readonly ?ViewTransitionState $viewTransition,
    ) {
    }
}
