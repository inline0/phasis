<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class ArgumentsBindingTest extends TestCase
{
    public function testUnmappedAndZeroParameterArgumentsRemainFreshOnWarmCalls(): void
    {
        $engine = new Engine();
        self::assertSame([[2, 4, true], [1, 9, true], [3, 7, 1], [8, 7, 1], 6, 11], $engine->eval(<<<'JS'
function zero() { return [arguments.length, arguments[0], arguments.callee === zero]; }
function strict(a) { 'use strict'; arguments[0] = 7; return [a, arguments[0], arguments.length]; }
function outer() { return (() => arguments[0])(); }
[zero(4, 5), zero(9), strict(3), strict(8), outer(6), outer(11)];
JS));
        foreach (['zero', 'strict', 'outer'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name . ' must exercise bytecode.');
            self::assertFalse($function->compiled->canSkipEnvAlloc);
        }
    }

    public function testMappedParametersAndShadowedArgumentsKeepInterpreterSemantics(): void
    {
        $engine = new Engine();
        self::assertSame([[7, 8], [7, 8], 3, 2], $engine->eval(<<<'JS'
function mapped(a) { arguments[0] = 7; const result = a; a = 8; return [result, arguments[0]]; }
function shadowed() { var arguments; return arguments.length; }
[mapped(1), mapped(2), shadowed(1, 2, 3), shadowed(4, 5)];
JS));
    }
}
