<?php

declare(strict_types=1);

namespace Phasis\Tests\Jsx;

use Phasis\Ast\EstreeSerializer;
use Phasis\Exceptions\SyntaxError;
use Phasis\Parser\Jsx\JsxMode;
use Phasis\Parser\Jsx\JsxOptions;
use Phasis\Parser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parse oracle: Phasis's JSX AST (Preserve mode, ESTree summary) must match
 * @babel/parser with the jsx and estree plugins. Regenerate the expected
 * files with `node tests/Jsx/oracle/jsx-oracle.ts parse`.
 */
final class JsxParseOracleTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function fixtureProvider(): iterable
    {
        foreach (glob(__DIR__ . '/parse/*.jsx') ?: [] as $file) {
            yield basename($file, '.jsx') => [$file];
        }
    }

    #[DataProvider('fixtureProvider')]
    public function testMatchesBabel(string $file): void
    {
        $expected = (string) file_get_contents(substr($file, 0, -4) . '.expected.txt');
        $this->assertSame($expected, self::summarize((string) file_get_contents($file)) . "\n");
    }

    public static function summarize(string $source): string
    {
        try {
            $program = Parser::parseSource($source, 'module', new JsxOptions(mode: JsxMode::Preserve));
            return EstreeSerializer::summarize($program);
        } catch (SyntaxError $e) {
            return 'SyntaxError: ' . preg_replace('/ at line \d+, column \d+$/', '', $e->getMessage());
        }
    }
}
