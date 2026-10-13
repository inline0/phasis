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

/** The `<html>`, `<head>` and `<body>` chunks of a document render. */
final class PreambleState
{
    /** @var list<string>|null */
    public ?array $htmlChunks = null;

    /** @var list<string>|null */
    public ?array $headChunks = null;

    /** @var list<string>|null */
    public ?array $bodyChunks = null;
}
