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

/** A list of HTML chunks with identity, for resources React keeps in several sets at once. */
final class Chunks
{
    /** @var list<string> */
    public array $c = [];
}
