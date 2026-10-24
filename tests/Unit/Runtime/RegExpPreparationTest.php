<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use Phasis\Value\JsObject;
use PHPUnit\Framework\TestCase;

final class RegExpPreparationTest extends TestCase
{
    public function testRepeatedPatternsKeepCoercionsLastIndexAndCompileMutationIndependent(): void
    {
        $engine = new Engine();
        self::assertSame(['pattern,flags,pattern,flags', true, 'aa', 2, '(?<word>a+)', 'g'], $engine->eval(<<<'JS'
const calls = [];
const pattern = { toString() { calls.push('pattern'); return '(?<word>a+)'; } };
const flags = { toString() { calls.push('flags'); return 'g'; } };
const first = new RegExp(pattern, flags);
const second = new RegExp(pattern, flags);
first.lastIndex = 17;
first.compile('b', 'i');
const result = second.exec('aa');
[calls.join(','), first.test('B'), result.groups.word, second.lastIndex, second.source, second.flags];
JS));
    }

    public function testPreparedPatternsDoNotRetainTheirInstances(): void
    {
        $engine = new Engine();
        $engine->eval('var disposable = new RegExp("(?<word>a+)", "g"); new RegExp("(?<word>a+)", "g");');
        $regexp = $engine->getGlobalEnv()->get('disposable');
        self::assertInstanceOf(JsObject::class, $regexp);
        $reference = \WeakReference::create($regexp);
        unset($regexp);
        $engine->eval('disposable = null;');
        gc_collect_cycles();
        self::assertNull($reference->get());
    }

    public function testCachedNullCharactersCannotBypassInvalidFlagValidation(): void
    {
        $engine = new Engine();
        self::assertSame([true, true], $engine->eval(<<<'JS'
const seeded = new RegExp('a\0x', 'g');
let rejected = false;
try { new RegExp('x', 'g\0a'); } catch (error) { rejected = error instanceof SyntaxError; }
[seeded.test('a\0x'), rejected];
JS));
    }

    public function testRepeatedCustomPatternsKeepCapturesAndCompileChangesIndependent(): void
    {
        $engine = new Engine();
        self::assertSame([true, 'ab', 'b', 2, 0, 'a', 'a', 1, 'b', 2, true, 'b'], $engine->eval(<<<'JS'
const first = new RegExp('(?<letter>a|b)+', 'dg');
const second = new RegExp('(?<letter>a|b)+', 'dg');
const match = first.exec('ab');
const saved = match.groups.letter;
match.groups.letter = 'changed';
match.indices.groups.letter[0] = 99;
const secondBefore = second.lastIndex;
const next = second.exec('a');
first.compile('(?<letter>b)+', 'g');
const third = new RegExp('(?<letter>a|b)+', 'dg');
third.lastIndex = 1;
const last = third.exec('ab');
[first !== second, match[0], saved, match.indices[0][1], secondBefore,
 next[0], next.groups.letter, second.lastIndex, last.groups.letter, third.lastIndex,
 first.test('b'), RegExp.$1];
JS));
    }

    public function testCustomPatternFlagsAndUnicodeModesStayIndependent(): void
    {
        $engine = new Engine();
        self::assertSame([true, true, false, true, false, true, true, false, true, true], $engine->eval(<<<'JS'
const checks = [];
for (let repeat = 0; repeat < 2; repeat++) {
  checks.push(new RegExp('^[\\u{10000}-\\u{10ffff}]+$', 'u').test('\ud83d\ude00'));
  checks.push(new RegExp('^[\\u{10000}-\\u{10ffff}]+$', 'u').test('\ud83d'));
  checks.push(new RegExp('^(?<letter>a)+$', 'i').test('AA'));
  checks.push(new RegExp('^(?<letter>a)+$', '').test('AA'));
}
const unicodeSet = new RegExp('[\\p{ASCII}&&[^A-Z]]+', 'v');
const repeatedSet = new RegExp('[\\p{ASCII}&&[^A-Z]]+', 'v');
[checks[0], checks[2], checks[1], checks[4], checks[5], checks[6],
 unicodeSet.test('abc'), repeatedSet.test('ABC'), unicodeSet !== repeatedSet,
 new RegExp('(?<=a)(b)', 'd').exec('ab').indices[1][0] === 1];
JS));
    }

    public function testPreparedCustomPatternsDoNotRetainInstancesOrShareRealmPrototypes(): void
    {
        $first = new Engine();
        $first->eval('var temporary = new RegExp("(?<x>a)+", "g"); RegExp.prototype.realm = 1;');
        $regexp = $first->getGlobalEnv()->get('temporary');
        self::assertInstanceOf(JsObject::class, $regexp);
        $reference = \WeakReference::create($regexp);
        unset($regexp);
        $second = new Engine();
        $second->eval('new RegExp("(?<x>a)+", "g"); RegExp.prototype.realm = 2;');
        self::assertSame([1, true, 'a'], $first->eval(<<<'JS'
temporary = null;
const next = new RegExp('(?<x>a)+', 'g');
[next.realm, Object.getPrototypeOf(next) === RegExp.prototype, next.exec('aa').groups.x];
JS));
        gc_collect_cycles();
        self::assertNull($reference->get());
        self::assertSame(2, $second->eval('new RegExp("(?<x>a)+", "g").realm'));
    }
}
