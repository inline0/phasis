<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class ArrayIntrinsicPrototypeTest extends TestCase
{
    public function testArrayCreationDoesNotReadTheMutableConstructorGlobal(): void
    {
        $engine = new Engine();
        self::assertSame([true, true, true, true, 0], $engine->eval(<<<'JS'
const intrinsic = Array.prototype;
let reads = 0;
Object.defineProperty(globalThis, 'Array', {
    configurable: true,
    get() { reads++; throw new Error('Array global must not be read'); }
});
function make(value) { return [value, ...[2]]; }
function rest(...values) { return values; }
const literal = make(1);
const restValue = rest(3, 4);
const keys = Object.keys({item: true});
const sliced = intrinsic.slice.call({0: 'x', length: 1});
[Object.getPrototypeOf(literal) === intrinsic,
 Object.getPrototypeOf(restValue) === intrinsic,
 Object.getPrototypeOf(keys) === intrinsic,
 Object.getPrototypeOf(sliced) === intrinsic, reads];
JS));
    }

    public function testReplacedGlobalsDoNotMixArrayPrototypesAcrossRealms(): void
    {
        $first = new Engine();
        $second = new Engine();
        foreach ([$first, $second] as $engine) {
            $engine->eval(<<<'JS'
const intrinsic = Array.prototype;
function make() { return [1, 2]; }
globalThis.Array = function Replacement() {};
JS);
        }
        $first->setGlobalJsValue('foreignMake', $second->getGlobalEnv()->get('make'));
        $first->setGlobalJsValue('foreignIntrinsic', $second->getGlobalEnv()->get('intrinsic'));
        self::assertSame([true, true, true], $first->eval(<<<'JS'
[Object.getPrototypeOf(make()) === intrinsic,
 Object.getPrototypeOf(foreignMake()) === foreignIntrinsic,
 Object.getPrototypeOf(make()) !== foreignIntrinsic];
JS));
        self::assertTrue($second->eval('Object.getPrototypeOf(make()) === intrinsic'));
    }

    public function testForeignNewTargetUsesRetainedArrayIntrinsicForFallback(): void
    {
        $first = new Engine();
        $second = new Engine();
        $second->eval(<<<'JS'
const intrinsic = Array.prototype;
function Target() {}
Target.prototype = null;
globalThis.Array = function Replacement() {};
JS);
        $first->setGlobalJsValue('foreignTarget', $second->getGlobalEnv()->get('Target'));
        $first->setGlobalJsValue('foreignIntrinsic', $second->getGlobalEnv()->get('intrinsic'));
        self::assertSame([true, true, true], $first->eval(<<<'JS'
const constructed = Reflect.construct(Array, [1, 2], foreignTarget);
const sized = Reflect.construct(Array, [3], foreignTarget);
[Object.getPrototypeOf(constructed) === foreignIntrinsic,
 Object.getPrototypeOf(sized) === foreignIntrinsic,
 Object.getPrototypeOf([]) === Array.prototype];
JS));
    }
}
