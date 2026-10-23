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
}
