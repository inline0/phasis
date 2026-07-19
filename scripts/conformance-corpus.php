<?php

/**
 * Corpus conformance runner: formats every file passed on the command line
 * (or listed in a directory) and diffs the output against the file itself.
 * Prettier-formatted files are fixed points, so any diff is a conformance
 * bug. Exits nonzero when any file diverges.
 *
 * Usage: php scripts/conformance-corpus.php [--type=module|script] [--write-dir=DIR] FILES...
 */

declare(strict_types=1);


require __DIR__ . '/../vendor/autoload.php';

use Phasis\Formatter\Formatter;
use Phasis\Formatter\FormatOptions;

$options = new FormatOptions(useTabs: true, singleQuote: true);
$sourceType = 'script';
$writeDir = null;
$files = [];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--type=')) {
        $sourceType = substr($arg, 7);
        continue;
    }
    if (str_starts_with($arg, '--write-dir=')) {
        $writeDir = substr($arg, 12);
        continue;
    }
    if (str_starts_with($arg, '--list=')) {
        $list = (string) file_get_contents(substr($arg, 7));
        foreach (preg_split('/\R/', $list) ?: [] as $line) {
            if (trim($line) !== '') {
                $files[] = trim($line);
            }
        }
        continue;
    }
    $files[] = $arg;
}

$failures = 0;
$crashes = 0;

foreach ($files as $file) {
    $source = (string) file_get_contents($file);
    $firstLineEnd = strpos($source, "\n");
    $firstLine = $firstLineEnd === false ? $source : substr($source, 0, $firstLineEnd);
    if (strlen($firstLine) > 500) {
        printf("skip  %s (minified)\n", $file);
        continue;
    }
    $type = $sourceType;
    if ($type === 'auto') {
        $type = preg_match('/^\s*(import|export)\b/m', $source) === 1 ? 'module' : 'script';
    }
    try {
        $formatted = Formatter::formatSource($source, $type, $options);
    } catch (Throwable $error) {
        $crashes++;
        printf("CRASH %s: %s\n", $file, $error->getMessage());
        continue;
    }
    if ($formatted === $source) {
        printf("ok    %s\n", $file);
        continue;
    }
    $failures++;
    printf("DIFF  %s\n", $file);
    if ($writeDir !== null) {
        @mkdir($writeDir, 0777, true);
        file_put_contents($writeDir . '/' . basename($file) . '.out', $formatted);
    }
}

printf("\n%d files, %d diffs, %d crashes\n", count($files), $failures, $crashes);
exit($failures + $crashes > 0 ? 1 : 0);
