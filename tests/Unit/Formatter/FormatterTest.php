<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Formatter;

use Phasis\Ast\EstreeSerializer;
use Phasis\Formatter\FormatOptions;
use Phasis\Formatter\Formatter;
use Phasis\Parser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatterTest extends TestCase
{
    private static function options(): FormatOptions
    {
        return new FormatOptions(useTabs: true, singleQuote: true);
    }

    private static function sourceTypeFor(string $source): string
    {
        return preg_match('/^\s*(import|export)\b/m', $source) === 1 ? 'module' : 'script';
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fixtures(): array
    {
        $cases = [];
        foreach (glob(__DIR__ . '/fixtures/*.in.js') ?: [] as $input) {
            $name = basename($input, '.in.js');
            $cases[$name] = [$input, __DIR__ . '/fixtures/' . $name . '.expected.js'];
        }
        return $cases;
    }

    #[DataProvider('fixtures')]
    public function testFixtureMatchesExpectedOutput(string $inputFile, string $expectedFile): void
    {
        $source = (string) file_get_contents($inputFile);
        $expected = (string) file_get_contents($expectedFile);

        $formatted = Formatter::formatSource($source, self::sourceTypeFor($source), self::options());

        self::assertSame($expected, $formatted);
    }

    #[DataProvider('fixtures')]
    public function testFormattingIsIdempotent(string $inputFile, string $expectedFile): void
    {
        $expected = (string) file_get_contents($expectedFile);

        $again = Formatter::formatSource($expected, self::sourceTypeFor($expected), self::options());

        self::assertSame($expected, $again);
    }

    #[DataProvider('fixtures')]
    public function testOutputReparsesToAnEquivalentProgram(string $inputFile, string $expectedFile): void
    {
        $source = (string) file_get_contents($inputFile);
        $type = self::sourceTypeFor($source);

        $formatted = Formatter::formatSource($source, $type, self::options());

        $before = self::normalizedTree($source, $type);
        $after = self::normalizedTree($formatted, $type);

        self::assertSame($before, $after);
    }

    #[DataProvider('fixtures')]
    public function testEveryCommentSurvivesFormatting(string $inputFile, string $expectedFile): void
    {
        $source = (string) file_get_contents($inputFile);
        $type = self::sourceTypeFor($source);

        $formatted = Formatter::formatSource($source, $type, self::options());

        self::assertSame(
            self::commentCount($source, $type),
            self::commentCount($formatted, $type),
        );
    }

    public function testDoubleQuoteAndSpacesOptions(): void
    {
        $out = Formatter::formatSource("const a = { x: 'hi' };\n", 'script', new FormatOptions());
        self::assertSame("const a = { x: \"hi\" };\n", $out);
    }

    public function testArrowParensAvoid(): void
    {
        $out = Formatter::formatSource(
            "const fn = (x) => x * 2;\n",
            'script',
            new FormatOptions(arrowParens: 'avoid'),
        );
        self::assertSame("const fn = x => x * 2;\n", $out);
    }

    public function testTrailingCommaModes(): void
    {
        $source = "const long = [aaaaaaaaaaaaaaaaaaaaaaa, bbbbbbbbbbbbbbbbbbbbbbbb, cccccccccccccccccccc];\n";

        $all = Formatter::formatSource($source, 'script', new FormatOptions(trailingComma: 'all'));
        self::assertStringContainsString("cccccccccccccccccccc,\n", $all);

        $none = Formatter::formatSource($source, 'script', new FormatOptions(trailingComma: 'none'));
        self::assertStringContainsString("cccccccccccccccccccc\n", $none);
    }

    public function testInvalidOptionsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FormatOptions(trailingComma: 'sometimes');
    }

    public function testInvalidSourceTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Formatter::formatSource('1;', 'json');
    }

    private static function commentCount(string $source, string $type): int
    {
        $parser = new Parser($source);
        $parser->setModuleMode($type === 'module');
        $parser->setCollectComments(true);
        $parser->parse();
        return count($parser->comments());
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizedTree(string $source, string $type): array
    {
        $tree = EstreeSerializer::toArray(Parser::parseSource($source, $type));
        self::stripVolatileKeys($tree);
        return $tree;
    }

    /**
     * @param array<int|string, mixed> $tree
     */
    private static function stripVolatileKeys(array &$tree): void
    {
        foreach (['loc', 'start', 'end', 'range', 'raw', 'verbatim'] as $key) {
            unset($tree[$key]);
        }
        if (isset($tree['value']) && is_int($tree['value'])) {
            $tree['value'] = (float) $tree['value'];
        }
        foreach ($tree as &$value) {
            if (is_array($value)) {
                self::stripVolatileKeys($value);
            }
        }
        unset($value);
    }
}
