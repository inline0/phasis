<?php

declare(strict_types=1);

namespace Phasis\Tests\Jsx;

use Phasis\Engine;
use Phasis\Parser\Jsx\JsxMode;
use Phasis\Parser\Jsx\JsxOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Precompile oracle: the whole React and HTML corpus renders byte-identical
 * with JsxMode::Precompile, against the same Node expectations the plain
 * render oracles use.
 */
final class JsxPrecompileTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function reactProvider(): iterable
    {
        foreach (glob(__DIR__ . '/react/*.jsx') ?: [] as $file) {
            yield basename($file, '.jsx') => [$file];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function htmlProvider(): iterable
    {
        foreach (glob(__DIR__ . '/html/*.jsx') ?: [] as $file) {
            yield basename($file, '.jsx') => [$file];
        }
    }

    private static function engine(): Engine
    {
        $engine = new Engine();
        $engine->setJsxOptions(new JsxOptions(mode: JsxMode::Precompile));
        return $engine;
    }

    #[DataProvider('reactProvider')]
    public function testReactProfileIsUnchanged(string $file): void
    {
        $expected = (string) file_get_contents(substr($file, 0, -4) . '.expected.txt');
        $this->assertSame(rtrim($expected, "\n"), ReactFixture::render(self::engine(), $file));
    }

    #[DataProvider('htmlProvider')]
    public function testHtmlProfileIsUnchanged(string $file): void
    {
        $expected = (string) file_get_contents(substr($file, 0, -4) . '.expected.txt');
        $this->assertSame(rtrim($expected, "\n"), HtmlFixture::render(self::engine(), $file));
    }
}
