<?php

/**
 * Formats one file to stdout with the given options. Development helper for
 * conformance diffing.
 *
 * Usage: php scripts/format-file.php [--type=script|module|auto] [--double] [--spaces] FILE
 */

declare(strict_types=1);


require __DIR__ . '/../vendor/autoload.php';

use Phasis\Formatter\Formatter;
use Phasis\Formatter\FormatOptions;

$sourceType = 'auto';
$useTabs = true;
$singleQuote = true;
$file = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--type=')) {
        $sourceType = substr($arg, 7);
    } elseif ($arg === '--double') {
        $singleQuote = false;
    } elseif ($arg === '--spaces') {
        $useTabs = false;
    } else {
        $file = $arg;
    }
}

if ($file === null) {
    fwrite(STDERR, "No file given.\n");
    exit(2);
}

$source = (string) file_get_contents($file);
if ($sourceType === 'auto') {
    $sourceType = preg_match('/^\s*(import|export)\b/m', $source) === 1 ? 'module' : 'script';
}

echo Formatter::formatSource($source, $sourceType, new FormatOptions(useTabs: $useTabs, singleQuote: $singleQuote));
