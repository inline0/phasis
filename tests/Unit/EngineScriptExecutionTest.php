<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit;

use Phasis\Engine;
use Phasis\Exceptions\JsThrowable;
use PHPUnit\Framework\TestCase;

/** Separate global scripts share their realm while retaining independent execution contexts. */
final class EngineScriptExecutionTest extends TestCase
{
    public function testStrictAndSloppyScriptsShareLexicalsWithoutChangingHarnessFunctions(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const harnessLexical = 42;
function harnessIsSloppy() { return this === globalThis; }
JS);

        self::assertSame('42,true,true', $engine->eval(<<<'JS'
'use strict';
[harnessLexical, harnessIsSloppy(), (function () { return this; })() === undefined].join(',')
JS));
        self::assertTrue($engine->eval('(function () { return this; })() === globalThis'));
        self::assertSame(42, $engine->eval('harnessLexical'));
    }

    public function testExternalScriptEvaluationRestoresTheCallingContext(): void
    {
        $engine = new Engine();
        $interpreter = $engine->getInterpreter();
        $interpreter->setStrictMode(true);
        $interpreter->setEvalContext(true);

        self::assertTrue($engine->eval(<<<'JS'
var externalVar = 5;
let externalLexical = 7;
(function () { return this; })() === globalThis
JS));
        self::assertTrue($interpreter->isStrictMode());
        self::assertTrue($interpreter->isEvalContext());
        self::assertSame(12, $engine->eval('externalVar + externalLexical'));

        try {
            $engine->eval('throw new TypeError("script failure");');
            self::fail('Expected the script exception to propagate');
        } catch (JsThrowable $error) {
            self::assertStringContainsString('script failure', $error->getMessage());
        }
        self::assertTrue($interpreter->isStrictMode());
        self::assertTrue($interpreter->isEvalContext());
    }

    public function testDeferredMicrotasksRunAfterAllHostScripts(): void
    {
        $engine = new Engine();
        $engine->evalScript(<<<'JS'
const events = [];
const value = 42;
Promise.resolve().then(() => events.push('job'));
JS, false);

        self::assertSame('test:42', $engine->evalScript(<<<'JS'
'use strict';
events.push('test:' + value);
events.join('|')
JS, false));
        self::assertSame('test:42', $engine->evalScript('events.join("|")', false));
        $engine->runEventLoop();
        self::assertSame('test:42|job', $engine->evalScript('events.join("|")', false));

        $engine->eval('Promise.resolve().then(() => events.push("default"));');
        self::assertSame('test:42|job|default', $engine->evalScript('events.join("|")', false));
    }
}
