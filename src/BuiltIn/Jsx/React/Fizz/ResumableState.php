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

/**
 * State that survives between a prerender and a resume in React: id
 * prefix, which resources were already emitted, instruction flags.
 */
final class ResumableState
{
    public int $nextFormID = 0;
    public int $instructions = 0;
    public bool $hasBody = false;
    public bool $hasHtml = false;

    /** @var array<string, array<string, ?list<\Phasis\Value\JsValue>>> */
    public array $unknownResources = [];

    /** @var array<string, null> */
    public array $dnsResources = [];

    /** @var array<string, array<string, null>> */
    public array $connectResources = ['default' => [], 'anonymous' => [], 'credentials' => []];

    /** @var array<string, ?list<\Phasis\Value\JsValue>> */
    public array $imageResources = [];

    /** @var array<string, ?list<\Phasis\Value\JsValue>> */
    public array $styleResources = [];

    /** @var array<string, ?list<\Phasis\Value\JsValue>> */
    public array $scriptResources = [];

    /** @var array<string, array<string, ?list<\Phasis\Value\JsValue>>> */
    public array $moduleUnknownResources = [];

    /** @var array<string, ?list<\Phasis\Value\JsValue>> */
    public array $moduleScriptResources = [];

    public function __construct(
        public string $idPrefix,
        public mixed $bootstrapScriptContent = null,
        public mixed $bootstrapScripts = null,
        public mixed $bootstrapModules = null,
    ) {
    }
}
