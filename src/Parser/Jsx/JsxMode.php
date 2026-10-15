<?php

declare(strict_types=1);

namespace Phasis\Parser\Jsx;

/**
 * How the parser turns JSX into JavaScript.
 *
 * Automatic and Classic follow esbuild's `--jsx=automatic` and
 * `--jsx=transform`. Preserve keeps JSX nodes in the AST for tooling.
 * Precompile serializes static HTML into string templates at parse time.
 */
enum JsxMode: string
{
    case Automatic = 'automatic';
    case Classic = 'classic';
    case Preserve = 'preserve';
    case Precompile = 'precompile';
}
