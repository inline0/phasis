<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/** Unicode data and string-set matching remain independent of host ICU versions. */
class UnicodeDataTest extends TestCase
{
    public function testDefaultCaseConversionIncludesBmpAndSupplementaryLetters(): void
    {
        $engine = new Engine();
        self::assertTrue($engine->eval(<<<'JS'
'\uA7CE\u{16EA0}'.toLowerCase() === '\uA7CF\u{16EBB}' &&
'\uA7CF\u{16EBB}'.toUpperCase() === '\uA7CE\u{16EA0}' &&
'\uD800ß\uDC00'.toUpperCase() === '\uD800SS\uDC00'
JS));
    }

    public function testFinalSigmaUsesUnicodeCaseContextProperties(): void
    {
        $engine = new Engine();
        self::assertTrue($engine->eval(<<<'JS'
'\u{16EA0}\u0301Σ'.toLowerCase() === '\u{16EBB}\u0301ς' &&
'ΑΣ\u0301\u{16EA0}'.toLowerCase() === 'ασ\u0301\u{16EBB}'
JS));
    }

    public function testEmojiStringPropertiesMatchWholeSequencesAndSetDifferences(): void
    {
        $engine = new Engine();
        self::assertTrue($engine->eval(<<<'JS'
(() => {
    const emoji = '\u{1F468}\u{1F3FB}\u200D\u{1F430}\u200D\u{1F468}\u{1F3FC}';
    return /\p{RGI_Emoji}/v.exec(emoji)[0] === emoji &&
        /[\p{RGI_Emoji}]/v.exec(emoji)[0] === emoji &&
        /^[\p{RGI_Emoji}--\p{Basic_Emoji}]$/v.test(emoji) &&
        !/^[\p{RGI_Emoji}--\p{Basic_Emoji}]$/v.test('\u{1F6D8}') &&
        /\p{Basic_Emoji}/v.test('\u{1F6D8}');
})()
JS));
    }

    public function testStringSetFactoringPreservesEscapesAndLongestPrefixes(): void
    {
        $engine = new Engine();
        self::assertSame("abc,ab,a,a|b,a\nb,abc", $engine->eval(<<<'JS'
[
    /[\q{a|\u0061b|abc}]/v.exec('abcd')[0],
    /[\q{a|\x61b|abc}]/v.exec('ab!')[0],
    /[\q{a|ab|abc}]/v.exec('a!')[0],
    /[\q{a\|b|a}]/v.exec('a|b')[0],
    /[\q{a\nb|a}]/v.exec('a\nb')[0],
    /[\q{\u{61}bc|a}]/v.exec('abc')[0]
].join(',')
JS));
    }

    public function testAstralStringSetsAreNormalizedForTheCustomMatcher(): void
    {
        $engine = new Engine();
        self::assertSame('😀x,😀x,😀x,😀x', $engine->eval(<<<'JS'
[
    /[\q{\uD83D\uDE00x|\u{1F600}}]/v.exec('😀x')[0],
    /[\q{\u{1F600}x|\uD83D\uDE00}]/v.exec('😀x')[0],
    /[\q{\uD83D\uDE00x|\u{1F600}}]x/v.exec('😀x')[0],
    /\p{RGI_Emoji}./v.exec('😀x')[0]
].join(',')
JS));
    }
}
