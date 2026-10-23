<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class StringCodeUnitTest extends TestCase
{
    public function testSplitUsesCodeUnitsForSupplementaryAndLoneSurrogateStrings(): void
    {
        $engine = new Engine();
        self::assertSame([
            [0xD83D, 0xDE00],
            [0xDFFF],
            [0xD83D],
            [[], [0xDE00]],
            [[0xD83D], []],
            [[], []],
            [[0xD800, 0x6100, 0x0062]],
        ], $engine->eval(<<<'JS'
function units(value) {
    const result = [];
    for (let i = 0; i < value.length; i++) result.push(value.charCodeAt(i));
    return result;
}
function split(value, separator) { return value.split(separator).map(units); }
for (let i = 0; i < 10; i++) split('warm/up', '/');
[
    '😀'.split('').map(value => value.charCodeAt(0)),
    '\udfff'.split('').map(value => value.charCodeAt(0)),
    '😀'.split('', 1).map(value => value.charCodeAt(0)),
    split('😀', '\ud83d'),
    split('😀', '\ude00'),
    split('\ud83d' + '\ude00', '😀'),
    split('\ud800\u6100\u0062', '\u6261'),
];
JS));
    }

    public function testNativeUtf8ConversionKeepsUtf16LengthsAndNullBytes(): void
    {
        $engine = new Engine();
        self::assertSame([
            [3, 65, 0, 122],
            [2, 0x00E4, 0x20AC],
            [2, 0xD83D, 0xDE00],
            [3, 0x6587, 0xD83D, 0xDE00],
        ], $engine->eval(<<<'JS'
['A\0z', 'ä€', '😀', '文😀'].map(value => {
    const units = [value.length];
    for (let i = 0; i < value.length; i++) units.push(value.charCodeAt(i));
    return units;
});
JS));
    }

    public function testCachedCodeUnitsPreserveSurrogatesAndCoercionOrder(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const value = 'a\ud83d\ude00\ud800z';
const observations = [];
for (let i = 0; i < 3; i++) {
  observations.push([value.length, value.charCodeAt(1), value.charCodeAt(2), value.charCodeAt(3),
    value.slice(1, 3), value.substring(1, 3), value.at(-1), value.codePointAt(1)]);
}
const order = [];
const receiver = {toString() { order.push('receiver'); return 'xyz'; }};
const index = {valueOf() { order.push('index'); return 1; }};
const coerced = String.prototype.charCodeAt.call(receiver, index);
const bounds = [value.charCodeAt(NaN), Number.isNaN(value.charCodeAt(Infinity)), Number.isNaN(value.charCodeAt(-Infinity)),
  value.charAt(NaN), value.charAt(Infinity), value.charAt(-Infinity)];
JS);
        $expected = [5, 0xD83D, 0xDE00, 0xD800, '😀', '😀', 'z', 0x1F600];
        self::assertSame([$expected, $expected, $expected], $engine->eval('observations'));
        self::assertSame(['receiver', 'index'], $engine->eval('order'));
        self::assertSame(121, $engine->eval('coerced'));
        self::assertSame([97, true, true, 'a', '', ''], $engine->eval('bounds'));
    }
}
