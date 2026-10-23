<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class RegExpLiteralTest extends TestCase
{
    public function testEachCompiledEvaluationCreatesAFreshLiteralUsingTheRealmIntrinsic(): void
    {
        $engine = new Engine();
        self::assertSame([true, 1, 0, true, 'a\\/b', 'g', true, 0], $engine->eval(<<<'JS'
const prototype = RegExp.prototype;
let globalReads = 0;
function literal() { return /a\/b/g; }
const first = literal();
first.lastIndex = 1;
const second = literal();
Object.defineProperty(globalThis, 'RegExp', { configurable: true, get() { globalReads++; throw new Error('global'); } });
const third = literal();
[first !== second, first.lastIndex, second.lastIndex, Object.getPrototypeOf(third) === prototype,
 third.source, third.flags, third.test('a/b'), globalReads];
JS));
        $function = $engine->getGlobalEnv()->get('literal');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNotNull($function->compiled);
    }

    public function testLiteralObjectsStayInTheirOwnRealm(): void
    {
        $first = new Engine();
        $second = new Engine();
        $first->eval('function literal() { return /x/; } literal();');
        $second->eval('RegExp.prototype.realm = 2;');
        self::assertSame([true, true], $first->eval('[Object.getPrototypeOf(literal()) === RegExp.prototype, literal().realm === undefined]'));
        self::assertSame(2, $second->eval('(/x/).realm'));
    }
}
