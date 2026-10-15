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

/** A `<link rel="stylesheet" precedence>` resource. State: 0 pending, 1 preloaded, 2 flushed, 3 written in JS. */
final class StylesheetResource
{
    /** @param array<string, JsValue> $props */
    public function __construct(public int $state, public array $props)
    {
    }
}
