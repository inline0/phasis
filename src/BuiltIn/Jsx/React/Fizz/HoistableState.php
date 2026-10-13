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

/** Styles and stylesheets a boundary depends on. */
final class HoistableState
{
    /** @var array<int, StyleQueue> */
    public array $styles = [];

    /** @var array<int, StylesheetResource> */
    public array $stylesheets = [];

    public bool $suspenseyImages = false;
}
