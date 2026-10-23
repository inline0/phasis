<?php

declare(strict_types=1);

const UNICODE_DATA_VERSION = '17.0.0';

/** Read a pinned Unicode source, optionally from a local download directory. */
function readUnicodeData(string $name, ?string $directory): string
{
    $sources = [
        'UnicodeData.txt' => [
            'ucd/UnicodeData.txt',
            '2e1efc1dcb59c575eedf5ccae60f95229f706ee6d031835247d843c11d96470c',
        ],
        'SpecialCasing.txt' => [
            'ucd/SpecialCasing.txt',
            'efc25faf19de21b92c1194c111c932e03d2a5eaf18194e33f1156e96de4c9588',
        ],
        'DerivedCoreProperties.txt' => [
            'ucd/DerivedCoreProperties.txt',
            '24c7fed1195c482faaefd5c1e7eb821c5ee1fb6de07ecdbaa64b56a99da22c08',
        ],
        'emoji-sequences.txt' => [
            'emoji/emoji-sequences.txt',
            '12cc8267dc33cbd11ed32bcf6fc5dc2ad9c7a77bae1bdfba2f41b1b9b3ead8dd',
        ],
        'emoji-zwj-sequences.txt' => [
            'emoji/emoji-zwj-sequences.txt',
            '5b25441daed2322b068c5e70cda522946a4f0274df864445a1965a92e5fc5cad',
        ],
    ];
    if (!isset($sources[$name])) {
        throw new InvalidArgumentException("Unknown Unicode data file: {$name}");
    }
    [$relative, $checksum] = $sources[$name];
    $path = $directory === null
        ? 'https://www.unicode.org/Public/' . UNICODE_DATA_VERSION . '/' . $relative
        : rtrim($directory, '/') . '/' . $name;
    $data = file_get_contents($path, false, stream_context_create(['http' => ['timeout' => 30]]));
    if ($data === false || hash('sha256', $data) !== $checksum) {
        throw new RuntimeException("Missing or unexpected Unicode data: {$path}");
    }
    return $data;
}

/**
 * Read semicolon-delimited Unicode records without comments or blank lines.
 *
 * @return iterable<list<string>>
 */
function unicodeDataRecords(string $data): iterable
{
    foreach (explode("\n", $data) as $line) {
        $record = trim(explode('#', $line, 2)[0]);
        if ($record !== '') {
            yield array_map('trim', explode(';', $record));
        }
    }
}
