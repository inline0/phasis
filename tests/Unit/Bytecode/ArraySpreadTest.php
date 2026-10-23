<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class ArraySpreadTest extends TestCase
{
    public function testCompiledSpreadPreservesIterationOrderAndRealHoles(): void
    {
        $engine = new Engine();
        $expected = [[0, null, 'a', '😀', 9, null, 4], [0, null, 'a', '😀', 9, null, 4], false, true, false, 'next,next,next,next'];
        self::assertSame($expected, $engine->eval(<<<'JS'
const events = [];
const iterable = { [Symbol.iterator]() {
    let index = 0;
    return { next() { events.push('next'); return index++ ? {done: true} : {value: 9, done: false}; } };
} };
function array(source) { return [0, , ...'a😀', ...source, , 4]; }
const first = array(iterable), second = array(iterable);
[first, second, Object.hasOwn(first, 1), Object.hasOwn(first, 4), Object.hasOwn(first, 5), events.join(',')];
JS));
        $function = $engine->getGlobalEnv()->get('array');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNotNull($function->compiled);
    }

    public function testArrayLiteralBypassesInheritedIndexSettersAndHonorsCustomIterators(): void
    {
        $engine = new Engine();
        self::assertSame([['custom', 2], ['custom', 2], 0], $engine->eval(<<<'JS'
const source = [1];
source[Symbol.iterator] = function* () { yield 'custom'; };
let setters = 0;
Object.defineProperty(Array.prototype, '0', {set() { setters++; }, configurable: true});
function array(input) { return [...input, 2]; }
const first = array(source), second = array(source);
delete Array.prototype[0];
[first, second, setters];
JS));
        $function = $engine->getGlobalEnv()->get('array');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNotNull($function->compiled);
    }
}
