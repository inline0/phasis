<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class CurrentNumericSemanticsTest extends TestCase
{
    public function testNumberAndJsonSerializationShareShortestDecimalSpelling(): void
    {
        $engine = new Engine();
        self::assertSame(
            '999999999999999900000|{"value":999999999999999900000}|0.000001|1e-7',
            $engine->eval(<<<'JS'
var number = 999999999999999900000;
[String(number), JSON.stringify({ value: number }), JSON.stringify(1e-6), JSON.stringify(1e-7)].join('|');
JS),
        );
    }

    public function testBigIntRadixUsesNumberHintAndPropagatesCoercionErrors(): void
    {
        $engine = new Engine();
        self::assertSame('number:ff|true', $engine->eval(<<<'JS'
var hint;
var radix = { [Symbol.toPrimitive](received) { hint = received; return 16.9; } };
var digits = (255n).toString(radix);
var threw = false;
try { (0n).toString({ valueOf: undefined, toString: undefined }); }
catch (error) { threw = error instanceof TypeError; }
hint + ':' + digits + '|' + threw;
JS));
    }

    public function testComputedMapInsertionPassesTheCanonicalZero(): void
    {
        $engine = new Engine();
        self::assertTrue($engine->eval(<<<'JS'
var map = new Map();
var result = map.getOrInsertComputed(-0, key => {
    map.set(+0, 'intermediate');
    return Object.is(key, +0);
});
result && map.size === 1 && map.get(-0) === true;
JS));
    }

    public function testCrossRealmDynamicFunctionsUseTheirOwnArgumentsIntrinsics(): void
    {
        $other = new Engine();
        $engine = new Engine();
        $engine->setGlobalJsValue('other', $other->getGlobalEnv()->get('globalThis'));
        self::assertTrue($engine->eval(<<<'JS'
var localArguments = function() { 'use strict'; return arguments; }();
var otherArguments = new other.Function('"use strict"; return arguments;')();
var localThrow = Object.getOwnPropertyDescriptor(localArguments, 'callee').get;
var otherThrow = Object.getOwnPropertyDescriptor(otherArguments, 'callee').get;
var correctError = false;
try { otherThrow(); } catch (error) { correctError = error.constructor === other.TypeError; }
correctError && localThrow !== otherThrow &&
    Object.getPrototypeOf(otherArguments) === other.Object.prototype &&
    otherArguments[Symbol.iterator] === other.Array.prototype[Symbol.iterator];
JS));
    }
}
