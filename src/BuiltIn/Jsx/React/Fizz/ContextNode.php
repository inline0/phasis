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

use Phasis\Value\JsObject;
use Phasis\Value\JsValue;

/** One entry of the context stack: a provider's value over its parent's. */
final class ContextNode
{
    public function __construct(
        public readonly ?ContextNode $parent,
        public readonly int $depth,
        public readonly JsObject $context,
        public readonly JsValue $parentValue,
        public readonly JsValue $value,
    ) {
    }
}
