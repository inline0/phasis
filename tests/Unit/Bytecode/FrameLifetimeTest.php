<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsObject;
use Phasis\Value\JsUndefined;
use PHPUnit\Framework\TestCase;

final class FrameLifetimeTest extends TestCase
{
    public function testCompletedPooledFramesReleaseTheirArgumentsAndReceivers(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
function inner(value) { return [this, value].length; }
function outer(value) { return inner.call(value, value) + inner(value); }
outer({}); outer({});
JS);
        $value = new JsObject();
        $reference = \WeakReference::create($value);
        $engine->setGlobalJsValue('temporary', $value);
        self::assertSame(4, $engine->eval('outer(temporary)'));
        $engine->setGlobalJsValue('temporary', JsUndefined::instance());
        unset($value);
        gc_collect_cycles();
        self::assertTrue($reference->get() === null, 'Completed frames must release arguments and receivers.');
    }

    public function testExceptionUnwindingReleasesPooledFrames(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
function inner(value) { throw value; }
function outer(value) { try { inner(value); } catch (error) { return 7; } }
outer({}); outer({});
JS);
        $value = new JsObject();
        $reference = \WeakReference::create($value);
        $engine->setGlobalJsValue('temporary', $value);
        self::assertSame(7, $engine->eval('outer(temporary)'));
        $engine->setGlobalJsValue('temporary', JsUndefined::instance());
        unset($value);
        gc_collect_cycles();
        self::assertTrue($reference->get() === null, 'Unwound frames must release their arguments.');
    }

    public function testReturningAClosureKeepsItsActivationAlive(): void
    {
        $engine = new Engine();
        self::assertSame([3, 4, 11, 5], $engine->eval(<<<'JS'
function factory(value) { return function () { return ++value; }; }
const first = factory(2), second = factory(10);
[first(), first(), second(), first()];
JS));
    }
}
