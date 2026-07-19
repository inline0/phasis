<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Formatter;

use Phasis\Formatter\FormatOptions;
use Phasis\Formatter\Formatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CssJsonFormatterTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cssFixtures(): array
    {
        $cases = [];
        foreach (glob(__DIR__ . '/fixtures/css/*.in.css') ?: [] as $input) {
            $name = basename($input, '.in.css');
            $cases[$name] = [$input, __DIR__ . '/fixtures/css/' . $name . '.expected.css'];
        }
        return $cases;
    }

    #[DataProvider('cssFixtures')]
    public function testCssFixtureMatchesExpectedOutput(string $inputFile, string $expectedFile): void
    {
        $source = (string) file_get_contents($inputFile);
        $expected = (string) file_get_contents($expectedFile);

        self::assertSame($expected, Formatter::formatSource($source, 'css', new FormatOptions()));
    }

    #[DataProvider('cssFixtures')]
    public function testCssFormattingIsIdempotent(string $inputFile, string $expectedFile): void
    {
        $expected = (string) file_get_contents($expectedFile);

        self::assertSame($expected, Formatter::formatSource($expected, 'css', new FormatOptions()));
    }

    public function testCssHonorsTabsAndSingleQuotes(): void
    {
        $out = Formatter::formatSource(
            "[data-x=\"y\"]{color:red}\n",
            'css',
            new FormatOptions(useTabs: true, singleQuote: true),
        );
        self::assertSame("[data-x='y'] {\n\tcolor: red;\n}\n", $out);
    }

    public function testCssEmptySourceStaysEmpty(): void
    {
        self::assertSame('', Formatter::formatSource("  \n\t\n", 'css'));
    }

    public function testJsonMatchesPrettierShapes(): void
    {
        $source = '{"name":"test","list":[1,2,3],"nested":{"a":true,"b":null},'
            . '"long":["aaaaaaaaaaaaaaaaaaaa","bbbbbbbbbbbbbbbbbbbb","cccccccccccccccccccc"]}';
        $expected = <<<'JSON'
{
  "name": "test",
  "list": [1, 2, 3],
  "nested": { "a": true, "b": null },
  "long": [
    "aaaaaaaaaaaaaaaaaaaa",
    "bbbbbbbbbbbbbbbbbbbb",
    "cccccccccccccccccccc"
  ]
}

JSON;

        self::assertSame($expected, Formatter::formatSource($source, 'json'));
    }

    public function testJsonKeepsCommentsAndDropsTrailingCommas(): void
    {
        $out = Formatter::formatSource("{\n  // comment\n  \"a\": 1, \"b\": [2]\n}", 'json');

        self::assertSame("{\n  // comment\n  \"a\": 1,\n  \"b\": [2]\n}\n", $out);
    }

    public function testJsonIgnoresSingleQuotePreference(): void
    {
        $out = Formatter::formatSource('{"a":1}', 'json', new FormatOptions(useTabs: true, singleQuote: true));

        self::assertSame("{ \"a\": 1 }\n", $out);
    }

    public function testJsonRoundTripsSemantics(): void
    {
        $source = '{"a": [1, 2.5, -3], "b": {"c": "text", "d": false}, "e": null}';

        $formatted = Formatter::formatSource($source, 'json');

        self::assertSame(json_decode($source, true), json_decode($formatted, true));
        self::assertSame($formatted, Formatter::formatSource($formatted, 'json'));
    }

    public function testJsonScalarDocument(): void
    {
        self::assertSame("42\n", Formatter::formatSource('42', 'json'));
    }

    public function testUnknownSourceTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Formatter::formatSource('a{}', 'less');
    }
}
