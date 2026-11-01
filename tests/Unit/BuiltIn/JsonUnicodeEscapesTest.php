<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonUnicodeEscapesTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function cases(): iterable
    {
        yield 'escaped pair' => ['String.raw`"a😊b"`', '4 61,d83d,de0a,62'];
        yield 'lone high escape' => ['String.raw`"\ud800"`', '1 d800'];
        yield 'reversed halves' => ['String.raw`"\udc00\ud800"`', '2 dc00,d800'];
        yield 'high before pair' => ['String.raw`"x\ud83d😊y"`', '5 78,d83d,d83d,de0a,79'];
        yield 'escaped backslash' => ['String.raw`"\\\\ud800"`', '6 5c,75,64,38,30,30'];
        yield 'raw lone high' => ['\'"\ud800x"\'', '2 d800,78'];
        yield 'escape then raw low' => ['String.raw`"\ud83d` + \'\ude0a"\'', '2 d83d,de0a'];
        yield 'lone halves and NUL keys' => [
            'String.raw`{"\u0000a":1,"\ud800":"\udfff"}`',
            '{"\u0000a":1,"\ud800":"\udfff"}',
        ];
        yield 'flags, ZWJ and astral CJK' => [
            'String.raw`["🇩🇪","👨‍👩‍👧","𠀀𠀁"]`',
            '["🇩🇪","👨‍👩‍👧","𠀀𠀁"]',
        ];
        yield 'unterminated after escape' => ['String.raw`"\ud800`', 'SyntaxError'];
        yield 'short escape' => ['String.raw`"\u12"`', 'SyntaxError'];
        yield 'three digit escape' => ['String.raw`"\u005"`', 'SyntaxError'];
        yield 'non-hex escape beside a lone half' => ['String.raw`"\ud800\u00G0x"`', 'SyntaxError'];
        yield 'uppercase escape letter' => ['String.raw`"\U0041"`', 'SyntaxError'];
        yield 'trailing comma' => ['String.raw`{"a":"\ud800",}`', 'SyntaxError'];
    }

    #[DataProvider('cases')]
    public function testParseMatchesV8WithAndWithoutReviver(string $source, string $expected): void
    {
        $engine = new Engine();
        $result = $engine->eval(str_replace('SOURCE', $source, <<<'JS'
const show = (v) => typeof v === 'string'
    ? v.length + ' ' + Array.from({ length: v.length }, (_, i) => v.charCodeAt(i).toString(16)).join(',')
    : JSON.stringify(v);
const attempt = (fn) => { try { return show(fn()); } catch (e) { return e.name; } };
const text = SOURCE;
attempt(() => JSON.parse(text)) + '|' + attempt(() => JSON.parse(text, (k, v) => v));
JS));
        self::assertSame($expected . '|' . $expected, $result);
    }
}
