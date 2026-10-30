<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Jsx\Html;

use Phasis\HtmlRenderOptions;

/** Per-render traversal state, retained by asynchronous continuations. */
final readonly class HtmlRenderContext
{
    public function __construct(public ?HtmlRenderOptions $options, public bool $inNoscript = false)
    {
    }

    public function childrenOf(string $type): self
    {
        return $type === 'noscript' && !$this->inNoscript ? new self($this->options, true) : $this;
    }
}
