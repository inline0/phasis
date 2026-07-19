<?php

declare(strict_types=1);

namespace Phasis\Formatter\Css;

use Phasis\Formatter\DocPrinter;
use Phasis\Formatter\FormatOptions;

/**
 * CSS formatting facade: parse the stylesheet structurally, print it through
 * the shared document printer with prettier's stylesheet layout.
 */
final class CssFormatter
{
    public static function format(string $source, FormatOptions $options): string
    {
        $nodes = CssParser::parse($source);
        if ($nodes === []) {
            return '';
        }
        $doc = (new CssPrinter($options))->printStylesheet($nodes);
        return (new DocPrinter($options))->print($doc);
    }
}
