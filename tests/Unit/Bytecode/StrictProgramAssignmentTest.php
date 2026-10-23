<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Bytecode\Compiler;
use Phasis\Bytecode\CompilerBailout;
use Phasis\Bytecode\Op;
use Phasis\Engine;
use Phasis\Exceptions\JsThrowable;
use Phasis\Exceptions\ReferenceError;
use Phasis\Exceptions\RuntimeError;
use Phasis\Parser\Parser;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use PHPUnit\Framework\TestCase;

final class StrictProgramAssignmentTest extends TestCase
{
    public function testCompiledProgramsPreserveTheNodeAssignmentOracle(): void
    {
        $fixture = (string) file_get_contents(__DIR__ . '/fixtures/strict-program-assignments.js');
        $cases = (new Engine())->eval($fixture . "\nstrictAssignmentCases;");
        self::assertIsArray($cases);
        $results = [];
        foreach ($cases as $case) {
            $engine = new Engine();
            $engine->eval($case['setup']);
            $compiled = (new Compiler())->compileProgram((new Parser($case['source']))->parse());
            self::assertContains(Op::EVAL_EXPRESSION, $compiled->code, $case['name']);
            self::assertNotEmpty($compiled->interpretedExpressions, $case['name']);
            try {
                $completion = ['return', $engine->eval($case['source'])];
            } catch (JsThrowable $error) {
                self::assertInstanceOf(JsObject::class, $error->jsValue);
                $name = $error->jsValue->get('name');
                self::assertInstanceOf(JsString::class, $name);
                $completion = ['throw', $name->value];
            } catch (RuntimeError $error) {
                $completion = ['throw', (new \ReflectionClass($error))->getShortName()];
            }
            $results[] = [$case['name'], $completion, $engine->eval($case['observation'])];
        }
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/strict-program-assignments.json',
            json_encode($results, JSON_THROW_ON_ERROR),
        );
    }

    public function testAnExpressionDependingOnACatchSlotKeepsTheInterpreterPath(): void
    {
        $source = <<<'JS'
"use strict";
var target;
if (true) { try { throw 3; } catch (value) { target = value; } }
target;
JS;
        try {
            (new Compiler())->compileProgram((new Parser($source))->parse());
            self::fail('The interpreter cannot read a value kept only in a compiled catch slot.');
        } catch (CompilerBailout $error) {
            self::assertSame('interpreted expression depends on a local slot', $error->getMessage());
        }
        self::assertSame(3, (new Engine())->eval($source));
    }

    public function testARightHandSideCannotResolveAnAlreadyMissingReference(): void
    {
        // This is required by test262 assign-to-global-undefined.js. Node 26
        // currently resolves the reference late and is not the oracle here.
        $source = '"use strict"; missing = (globalThis.missing = 5);';
        $compiled = (new Compiler())->compileProgram((new Parser($source))->parse());
        self::assertContains(Op::EVAL_EXPRESSION, $compiled->code);
        $engine = new Engine();
        try {
            $engine->eval($source);
            self::fail('The original reference must remain unresolvable.');
        } catch (ReferenceError $error) {
            self::assertSame('missing is not defined', $error->getMessage());
        }
        self::assertSame(5, $engine->eval('globalThis.missing'));
    }
}
