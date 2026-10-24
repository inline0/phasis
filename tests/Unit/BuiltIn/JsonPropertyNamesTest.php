<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class JsonPropertyNamesTest extends TestCase
{
    public function testStringifyPreservesUnicodeSeparatorsInValuesAndKeys(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const separatorData = { ['\u2028/\u2029']: '\u2028 \u2029 woot' };
const separatorCompact = JSON.stringify(separatorData);
const separatorCached = JSON.stringify(separatorData);
const separatorReplacer = JSON.stringify(separatorData, (key, value) => value);
const surrogateKey = JSON.stringify({ ['\ud800']: 1 }, (key, value) => value);
JS);
        $expected = '{"' . "\u{2028}/\u{2029}" . '":"' . "\u{2028} \u{2029} woot" . '"}';
        self::assertSame($expected, $engine->eval('separatorCompact'));
        self::assertSame($expected, $engine->eval('separatorCached'));
        self::assertSame($expected, $engine->eval('separatorReplacer'));
        self::assertSame('{"\\ud800":1}', $engine->eval('surrogateKey'));
    }

    public function testStringifyPreservesUserPropertyPrefixesAndIntegerOrder(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const data = JSON.parse('{"__proto__":"data","__private":1,"[prefix]":2,"[":3,"10":"ten","2":"two"}');
const compact = JSON.stringify(data);
const viaReplacer = JSON.stringify(data, (key, value) => value);
data.__private = 4;
const changed = JSON.stringify(data);
JS);
        self::assertSame(
            '{"2":"two","10":"ten","__proto__":"data","__private":1,"[prefix]":2,"[":3}',
            $engine->eval('compact'),
        );
        self::assertSame($engine->eval('compact'), $engine->eval('viaReplacer'));
        self::assertSame(
            '{"2":"two","10":"ten","__proto__":"data","__private":4,"[prefix]":2,"[":3}',
            $engine->eval('changed'),
        );
    }
}
