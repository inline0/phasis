<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Bytecode\CompiledFunction;
use Phasis\Bytecode\Compiler;
use Phasis\Bytecode\Op;
use Phasis\Engine;
use Phasis\Parser\Parser;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class ThisBindingMetadataTest extends TestCase
{
    public function testProgramMetadataAlsoDistinguishesOperandsFromThisReads(): void
    {
        $declarations = array_map(static fn (int $index): string => 'p' . $index . '=' . $index, range(0, 15));
        $source = 'var ' . implode(', ', $declarations) . '; p15;';
        $compiled = (new Compiler())->compileProgram((new Parser($source))->parse());
        self::assertContains(Op::LOAD_THIS, $compiled->code);
        self::assertFalse($compiled->needsThis);
        self::assertSame(15, (new Engine())->eval($source));

        $compiled = (new Compiler())->compileProgram((new Parser('this;'))->parse());
        self::assertTrue($compiled->needsThis);
        self::assertTrue((new Engine())->eval('this === globalThis'));
    }

    public function testAnOperandMatchingLoadThisDoesNotRequireAnEnvironmentBinding(): void
    {
        $engine = new Engine();
        $parameters = implode(', ', array_map(static fn (int $index): string => 'p' . $index, range(0, 15)));
        $arguments = implode(', ', range(0, 15));
        $engine->eval("function ordinary({$parameters}) { return p15; }\n"
            . "const expression = ({$parameters}) => p15;\n");

        foreach (['ordinary', 'expression'] as $name) {
            $compiled = $this->compiled($engine, $name);
            self::assertSame(15, $engine->eval("{$name}({$arguments})"));
            self::assertContains(Op::LOAD_THIS, $compiled->code, 'The fixture must contain the colliding operand.');
            self::assertFalse($compiled->needsThis);
            self::assertTrue($compiled->canSkipEnvAlloc);
        }
    }

    public function testLexicalThisAndNestedArrowCapturesKeepTheirEnvironmentBinding(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
globalThis.value = 17;
const owner = {value: 41};
function capture() { return () => this.value; }
const direct = () => this.value;
function ordinary() { return this.value; }
function* generator() { yield this.value; return this.value; }
JS);
        foreach (['capture', 'direct', 'ordinary', 'generator'] as $name) {
            $this->compiled($engine, $name);
        }
        $engine->eval('const reader = capture.call(owner);');
        $this->compiled($engine, 'reader');
        self::assertSame([41, 17, 41, 41, 41], $engine->eval(<<<'JS'
const iterator = generator.call(owner);
[reader.call({value: 99}), direct.call({value: 99}), ordinary.call(owner),
 iterator.next().value, iterator.next().value];
JS));

        foreach (['capture', 'reader', 'direct', 'generator'] as $name) {
            $compiled = $this->compiled($engine, $name);
            self::assertTrue($compiled->needsThis, $name);
            self::assertFalse($compiled->canSkipEnvAlloc, $name);
        }
        $ordinary = $this->compiled($engine, 'ordinary');
        self::assertFalse($ordinary->needsThis);
        self::assertTrue($ordinary->canSkipEnvAlloc);
    }

    private function compiled(Engine $engine, string $name): CompiledFunction
    {
        $function = $engine->getGlobalEnv()->get($name);
        self::assertInstanceOf(JsFunction::class, $function);
        $function->compiled ??= (new Compiler())->compile($function);

        return $function->compiled;
    }
}
