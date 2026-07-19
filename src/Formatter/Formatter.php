<?php

declare(strict_types=1);

namespace Phasis\Formatter;

use Phasis\Parser\Parser;

/**
 * The phasis code formatter: parses ECMAScript source with comment
 * collection, attaches comments to the AST, builds a layout document, and
 * prints it against the configured print width. Output follows prettier's
 * layout semantics for the same options.
 */
final class Formatter
{
    public static function formatSource(
        string $source,
        string $sourceType = 'script',
        ?FormatOptions $options = null,
    ): string {
        if ($sourceType !== 'script' && $sourceType !== 'module') {
            throw new \InvalidArgumentException(
                "sourceType must be \"script\" or \"module\", got \"{$sourceType}\"",
            );
        }
        $options ??= new FormatOptions();

        $parser = new Parser($source);
        $parser->setModuleMode($sourceType === 'module');
        $parser->setCollectComments(true);
        $program = $parser->parse();
        $comments = $parser->comments();

        $index = CommentAttacher::attach($program, $comments, $source);
        $printer = new JsPrinter($options, $index, $source);
        $doc = $printer->printProgram($program);

        return (new DocPrinter($options))->print($doc);
    }
}
