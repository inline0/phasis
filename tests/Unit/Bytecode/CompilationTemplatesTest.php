<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Bytecode\Compiler;
use Phasis\Engine;
use Phasis\Parser\Parser;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use PHPUnit\Framework\TestCase;

final class CompilationTemplatesTest extends TestCase
{
    public function testFirstCallsOfFreshClosuresKeepTheirCapturedBindingsAndReceivers(): void
    {
        $engine = new Engine();
        self::assertSame([[4, 2, 3], [5, 7, 4], [10, 2, 1], [20, 7, 4]], $engine->eval(<<<'JS'
'use strict';
function make(offset) {
    return function (amount) { return [this.base, offset, amount]; };
}
const first = make(2);
const second = make(7);
[first.call({base: 4}, 3), second.call({base: 5}, 4),
 first.call({base: 10}, 1), second.call({base: 20}, 4)];
JS));
        foreach (['first', 'second'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled);
            self::assertTrue($function->compiled->canSkipEnvAlloc);
        }
    }

    public function testCachedLayoutsDoNotBypassWithObjectCallReceivers(): void
    {
        $engine = new Engine();
        $program = (new Parser('function read() { return selected(); }'))->parse();
        $declaration = $program->body[0];
        self::assertInstanceOf(\Phasis\Ast\Declaration\FunctionDeclaration::class, $declaration);
        $warm = new JsFunction('read', $declaration->params, $declaration->body, $engine->getGlobalEnv());
        (new Compiler())->compile($warm);

        $engine->eval('globalThis.receiver = {marker: "with receiver", selected() { return this.marker; }};');
        $receiver = $engine->getGlobalEnv()->get('receiver');
        self::assertInstanceOf(JsObject::class, $receiver);
        $scope = $engine->getGlobalEnv()->createWithEnvironment($receiver);
        $function = new JsFunction('read', $declaration->params, $declaration->body, $scope);
        $engine->setGlobalJsValue('readFromWith', $function);

        self::assertSame('with receiver', $engine->eval('with (receiver) { readFromWith(); }'));
        self::assertNull($function->compiled);
    }

    public function testRepeatedSyntaxSharesLayoutButNeverRuntimeInlineCaches(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
function make(value) { return function read() { return value.amount; }; }
const first = make({amount: 1}), second = make({amount: 9});
first(); second();
JS);
        $first = $engine->getGlobalEnv()->get('first');
        $second = $engine->getGlobalEnv()->get('second');
        self::assertInstanceOf(JsFunction::class, $first);
        self::assertInstanceOf(JsFunction::class, $second);
        self::assertNotNull($first->compiled);
        self::assertNotNull($second->compiled);
        self::assertNotSame($first->compiled, $second->compiled);
        self::assertSame($first->compiled->code, $second->compiled->code);
        $first->compiled->loadNameIc[9876] = [JsNumber::of(30), 0, \WeakReference::create($first->getClosure())];
        self::assertArrayNotHasKey(9876, $second->compiled->loadNameIc);
        self::assertSame([1, 9, 1, 9], $engine->eval('[first(), second(), first(), second()]'));
    }

    public function testTemplateDoesNotKeepDiscardedSyntaxOrEnvironmentsAlive(): void
    {
        $engine = new Engine();
        $program = (new Parser('function factory(value) { function read() { return value; } return read; }'))->parse();
        $declaration = $program->body[0];
        self::assertInstanceOf(\Phasis\Ast\Declaration\FunctionDeclaration::class, $declaration);
        $body = $declaration->body;
        $function = new JsFunction('factory', $declaration->params, $body, $engine->getGlobalEnv()->createChild());
        $bodyReference = \WeakReference::create($body);
        $closureReference = \WeakReference::create($function->getClosure());
        $compiled = (new Compiler())->compile($function);
        self::assertNotEmpty($compiled->nestedFns);
        unset($compiled, $function, $body, $declaration, $program);
        gc_collect_cycles();
        self::assertNull($bodyReference->get());
        self::assertNull($closureReference->get());
    }

    public function testWarmedNameCacheDoesNotRetainAnObjectContainingAClosure(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
function make(value) { return {read: function () { return value; }}; }
let held = make(7);
function readCached() { return [held]; }
readCached(); readCached();
JS);
        $held = $engine->getGlobalEnv()->get('held');
        self::assertInstanceOf(\Phasis\Value\JsObject::class, $held);
        $read = $held->get('read');
        self::assertInstanceOf(JsFunction::class, $read);
        $activation = \WeakReference::create($read->getClosure());
        $holder = \WeakReference::create($held);
        $cached = $engine->getGlobalEnv()->get('readCached');
        self::assertInstanceOf(JsFunction::class, $cached);
        self::assertNotNull($cached->compiled);
        self::assertNotEmpty($cached->compiled->loadNameIc);
        $engine->eval('held = undefined;');
        unset($held, $read);
        gc_collect_cycles();
        self::assertNull($holder->get());
        self::assertNull($activation->get());
    }
}
