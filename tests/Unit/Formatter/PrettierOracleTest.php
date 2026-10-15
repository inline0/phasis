<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Formatter;

use Phasis\Formatter\FormatOptions;
use Phasis\Formatter\Formatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrettierOracleTest extends TestCase
{
    /** @return iterable<string, array{string, string, array<string, mixed>, string}> */
    public static function fixtures(): iterable
    {
        $oracle = json_decode((string) file_get_contents(__DIR__ . '/fixtures/prettier-expected.json'), true);
        foreach ($oracle['cases'] as $case) {
            yield $case['name'] => [$case['source'], $case['type'], $case['options'], $case['expected']];
        }
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('fixtures')]
    public function testMatchesPinnedPrettier(string $source, string $type, array $options, string $expected): void
    {
        $formatOptions = new FormatOptions(...$options);
        self::assertSame($expected, Formatter::formatSource($source, $type, $formatOptions));
        self::assertSame($expected, Formatter::formatSource($expected, $type, $formatOptions));
    }
}
