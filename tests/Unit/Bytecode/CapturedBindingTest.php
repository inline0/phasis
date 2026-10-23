<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class CapturedBindingTest extends TestCase
{
    public function testClosuresAndTheirCompiledParentShareMutableBindingsAcrossCalls(): void
    {
        $engine = new Engine();
        self::assertSame([13, 18, 24, 26, 23, 31], $engine->eval(<<<'JS'
function make(start) {
    let current = start;
    const offset = 3;
    var calls = 0;
    function read() { return current + offset; }
    function add(value) { current += value; }
    function record() { calls++; return calls + current; }
    add(2);
    current++;
    return [read, add, record];
}
const first = make(7);
const second = make(20);
const result = [first[0]()];
first[1](5);
result.push(first[0](), second[2](), second[0]());
first[1](5);
result.push(first[0]());
second[1](5);
result.push(second[0]());
result;
JS));
        $this->assertCompiled($engine, 'make');
    }

    public function testCapturedLexicalsKeepTheirDeadZonesAndImmutability(): void
    {
        $engine = new Engine();
        self::assertSame([['ReferenceError', 4, null, 'TypeError'], ['ReferenceError', 4, null, 'TypeError']], $engine->eval(<<<'JS'
function lexical() {
    function read() { return value; }
    function write() { constant = 9; }
    function uninitialized() { return empty; }
    var before;
    try { read(); } catch (error) { before = error.name; }
    let value = 4;
    let empty;
    const constant = 7;
    var assignment;
    try { write(); } catch (other) { assignment = other.name; }
    return [before, read(), uninitialized(), assignment];
}
[lexical(), lexical()];
JS));
        $this->assertCompiled($engine, 'lexical');
    }

    public function testNestedArrowParametersAndDefaultBodyScopesRemainIndependent(): void
    {
        $engine = new Engine();
        self::assertSame([4, 8, 3, 6, 10, 11], $engine->eval(<<<'JS'
const arrow = (value) => () => value;
function defaults(value = 2) {
    function read() { return value; }
    value++;
    return read;
}
function redeclared(value = 8) {
    var value;
    function read() { return value; }
    value += 2;
    return read;
}
const first = arrow(4);
const second = arrow(8);
[first(), second(), defaults()(), defaults(5)(), redeclared()(), redeclared(9)()];
JS));
        $this->assertCompiled($engine, 'arrow');
        $this->assertCompiled($engine, 'defaults');
        $this->assertCompiled($engine, 'redeclared');
    }

    public function testPerIterationAndCatchCapturesKeepInterpreterScopes(): void
    {
        $engine = new Engine();
        self::assertSame([[0, 1, 2], [0, 1, 2]], $engine->eval(<<<'JS'
function iterations() {
    var result = [];
    for (let i = 0; i < 3; i++) result.push(() => i);
    return result;
}
function catches() {
    var result = [];
    for (var i = 0; i < 3; i++) {
        try { throw i; } catch (error) { result.push(() => error); }
    }
    return result;
}
[iterations().map(fn => fn()), catches().map(fn => fn())];
JS));
        foreach (['iterations', 'catches'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNull($function->compiled);
        }
    }

    public function testCapturedLoopCounterReadsMutationsMadeByCallbacks(): void
    {
        $engine = new Engine();
        self::assertSame([2, 2], $engine->eval(<<<'JS'
function loop() {
    var counter = 0;
    var limit = 8;
    function advance() { counter += 2; limit = 5; }
    var visits = 0;
    for (counter = 0; counter < limit; counter++) {
        advance();
        visits++;
    }
    return visits;
}
[loop(), loop()];
JS));
        $this->assertCompiled($engine, 'loop');
    }

    public function testRecursiveActivationsCannotReuseInnerCapturedNameCacheValues(): void
    {
        $engine = new Engine();
        self::assertSame([['a' => ['b' => 3]], ['a' => ['b' => 5]]], $engine->eval(<<<'JS'
function clone(source) {
    var result = {};
    Object.keys(source).forEach(function (key) {
        result[key] = source[key] instanceof Object ? clone(source[key]) : source[key];
    });
    return result;
}
[clone({a: {b: 3}}), clone({a: {b: 5}})];
JS));
        $this->assertCompiled($engine, 'clone');
    }

    private function assertCompiled(Engine $engine, string $name): void
    {
        $function = $engine->getGlobalEnv()->get($name);
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNotNull($function->compiled, "{$name} must exercise captured bytecode bindings.");
    }
}
