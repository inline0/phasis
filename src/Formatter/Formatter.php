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
        if (!in_array($sourceType, ['script', 'module', 'json', 'css'], true)) {
            throw new \InvalidArgumentException(
                "sourceType must be \"script\", \"module\", \"json\", or \"css\", got \"{$sourceType}\"",
            );
        }
        $options ??= new FormatOptions();

        if ($sourceType === 'css') {
            return Css\CssFormatter::format($source, $options);
        }

        if ($sourceType === 'json') {
            return self::formatJson($source, $options);
        }

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

    /**
     * JSON documents parse as one parenthesized expression so the JS parser
     * and printers can be reused; keys stay quoted, strings stay double
     * quoted, and trailing commas never appear.
     */
    private static function formatJson(string $source, FormatOptions $options): string
    {
        $wrapped = '(' . $source . "\n)";

        $parser = new Parser($wrapped);
        $parser->setCollectComments(true);
        $program = $parser->parse();

        $index = CommentAttacher::attach($program, $parser->comments(), $wrapped);
        $printer = new JsPrinter($options, $index, $wrapped, jsonMode: true);
        $doc = $printer->printJsonRoot($program);

        return (new DocPrinter($options))->print($doc);
    }
}
