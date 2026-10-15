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

/** Per-request output state: queued resources, hoisted chunks, prefixes. */
final class RenderState
{
    public string $placeholderPrefix;
    public string $segmentPrefix;
    public string $boundaryPrefix;
    public string $startInlineScript = '<script';
    public string $startInlineStyle = '<style';
    public PreambleState $preamble;

    /** @var list<string> */
    public array $bootstrapChunks = [];

    /** @var list<string> */
    public array $importMapChunks = [];

    /** @var list<string> */
    public array $charsetChunks = [];

    /** @var list<string> */
    public array $viewportChunks = [];

    /** @var list<string> */
    public array $hoistableChunks = [];

    /** @var array<int, Chunks> */
    public array $preconnects = [];

    /** @var array<int, Chunks> */
    public array $fontPreloads = [];

    /** @var array<int, Chunks> */
    public array $highImagePreloads = [];

    /** @var array<string, StyleQueue> */
    public array $styles = [];

    /** @var array<int, Chunks> */
    public array $bootstrapScripts = [];

    /** @var array<int, Chunks> */
    public array $scripts = [];

    /** @var array<int, Chunks> */
    public array $bulkPreloads = [];

    /** @var array<string, Chunks> */
    public array $preloadImages = [];

    /** @var array<string, Chunks> */
    public array $preloadStylesheets = [];

    /** @var array<string, Chunks> */
    public array $preloadScripts = [];

    /** @var array<string, Chunks> */
    public array $preloadModuleScripts = [];

    public ?string $nonceScript = null;
    public ?string $nonceStyle = null;
    public bool $stylesToHoist = false;

    /** Legacy renderer only: renderToStaticMarkup drops hydration markers. */
    public bool $generateStaticMarkup = false;

    /** Legacy renderer (renderToString, renderToStaticMarkup): no doctype, legacy context field. */
    public bool $legacy = false;

    public function __construct(string $idPrefix)
    {
        $this->placeholderPrefix = $idPrefix . 'P:';
        $this->segmentPrefix = $idPrefix . 'S:';
        $this->boundaryPrefix = $idPrefix . 'B:';
        $this->preamble = new PreambleState();
    }
}
