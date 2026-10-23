<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class CapturedBindingTest extends TestCase
{
    public function testCapturedVarHoistsKeepParametersAndFreshConditionalBindings(): void
    {
        $engine = new Engine();
        self::assertSame([[3, null], [7, 8], [11, null], 'outer'], $engine->eval(<<<'JS'
const value = 'outer';
function make(value, initialize) {
    var value;
    if (initialize) { var optional = value + 1; }
    function read() { return [value, optional]; }
    return read;
}
[make(3, false)(), make(7, true)(), make(11, false)(), value];
JS));
        $function = $engine->getGlobalEnv()->get('make');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNotNull($function->compiled);
        self::assertNotNull($function->compiled->capturedEnvironmentBindings);

        $engine->setExecutionBudget(1.0);
        self::assertSame([13, null], $engine->eval('make(13, false)()'));
        $engine->setExecutionBudget(null);
        self::assertSame([17, 18], $engine->eval('make(17, true)()'));
    }

    public function testCapturedFunctionHoistsStillReplaceParameterBindingsBeforeStatements(): void
    {
        $engine = new Engine();
        self::assertSame([[true, true, true], [true, true, true], true], $engine->eval(<<<'JS'
function make(read) {
    var before = read;
    function read() { return read; }
    function other() { return read; }
    var read;
    return [before === read, read() === read, other() === read, read];
}
const first = make(0);
const second = make(17);
[first.slice(0, 3), second.slice(0, 3), first[3] !== second[3]];
JS));
        $function = $engine->getGlobalEnv()->get('make');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNotNull($function->compiled?->capturedEnvironmentBindings);
    }

    public function testArgumentsAndDefaultsRetainTheirActivationSetupAfterCompilation(): void
    {
        $engine = new Engine();
        self::assertSame([9, 11, [3, 4], [7, 8]], $engine->eval(<<<'JS'
function defaults(value = 8) {
    var value;
    function read() { return value; }
    value++;
    return read;
}
function argumentCopy(value) {
    'use strict';
    var copy = arguments;
    function read() { return [value, copy[0]]; }
    arguments[0] = value + 1;
    return read;
}
[defaults()(), defaults(10)(), argumentCopy(3)(), argumentCopy(7)()];
JS));
        foreach (['defaults', 'argumentCopy'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled);
            self::assertNull($function->compiled->capturedEnvironmentBindings);
        }

        $engine->setExecutionBudget(1.0);
        self::assertSame([13, [11, 12]], $engine->eval('[defaults(12)(), argumentCopy(11)()]'));
    }

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
        $function = $engine->getGlobalEnv()->get('lexical');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNotNull($function->compiled?->capturedEnvironmentBindings);
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

    public function testWarmHoistedFunctionsReplaceParametersWithOneSharedIdentity(): void
    {
        $engine = new Engine();
        self::assertSame([[true, true, true], [true, true, true], true], $engine->eval(<<<'JS'
function make(read = 0) {
    var before = read;
    function read() { return read; }
    function other() { return read; }
    var read;
    return [before === read, read() === read, other() === read, read];
}
const first = make();
const second = make(17);
[first.slice(0, 3), second.slice(0, 3), first[3] !== second[3]];
JS));
        $this->assertCompiled($engine, 'make');

        $engine->setExecutionBudget(1.0);
        self::assertSame([true, true, true], $engine->eval('make(23).slice(0, 3);'));
    }

    public function testLiteralPropertyNamesAndComputedKeysHaveDifferentCaptureSemantics(): void
    {
        $engine = new Engine();
        self::assertSame([['value' => 3], ['value' => 3], ['first' => 'first'], ['second' => 'second']], $engine->eval(<<<'JS'
function literal(value) {
    const object = {value: 3};
    function read() { return {value: object.value}; }
    return read();
}
function computed(value) {
    function read() { return {[value]: value}; }
    return read;
}
[literal(8), literal(9), computed('first')(), computed('second')()];
JS));
        $this->assertCompiled($engine, 'literal');
        $this->assertCompiled($engine, 'computed');
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
