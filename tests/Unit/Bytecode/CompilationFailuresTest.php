<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Bytecode\CompiledFunction;
use Phasis\Bytecode\Compiler;
use Phasis\Bytecode\CompilerBailout;
use Phasis\Bytecode\JsToPhp;
use Phasis\Engine;
use Phasis\Parser\Parser;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class CompilationFailuresTest extends TestCase
{
    public function testAsyncBailoutDoesNotDisableOrdinaryCompilationOfTheSameSyntax(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
function helper(value) { return value + 1; }
function template(value) { return helper(value); }
JS);
        $template = $engine->getGlobalEnv()->get('template');
        self::assertInstanceOf(JsFunction::class, $template);
        $async = new JsFunction('async', $template->getParams(), $template->getBody(), $template->getClosure(), isAsync: true);
        self::assertNull(JsToPhp::compile($async));
        try {
            (new Compiler())->compile($async);
            self::fail('Async functions require the interpreter.');
        } catch (CompilerBailout $error) {
            self::assertSame('non-ordinary function kind', $error->getMessage());
        }
        $ordinary = new JsFunction('ordinary', $template->getParams(), $template->getBody(), $template->getClosure());
        self::assertInstanceOf(CompiledFunction::class, (new Compiler())->compile($ordinary));
        $engine->setGlobalJsValue('ordinary', $ordinary);
        self::assertSame(5, $engine->eval('ordinary(4)'));
    }

    public function testMethodBailoutDoesNotDisableOrdinaryNumericCompilation(): void
    {
        $engine = new Engine();
        $engine->eval('function numeric(value) { return value + 1; }');
        $template = $engine->getGlobalEnv()->get('numeric');
        self::assertInstanceOf(JsFunction::class, $template);
        $method = new JsFunction('method', $template->getParams(), $template->getBody(), $template->getClosure());
        $method->setHomeObject(new \Phasis\Value\JsObject());
        self::assertNull(JsToPhp::compile($method));
        $ordinary = new JsFunction('ordinary', $template->getParams(), $template->getBody(), $template->getClosure());
        self::assertInstanceOf(\Closure::class, JsToPhp::compile($ordinary));
        $engine->setGlobalJsValue('ordinary', $ordinary);
        self::assertSame(8, $engine->eval('ordinary(7)'));
    }

    public function testRepeatedUnsupportedClosuresKeepTheirOwnCapturedState(): void
    {
        $engine = new Engine();
        self::assertSame('2:11:3:12', $engine->eval(<<<'JS'
function create(initial) {
  return function step(options = {}) {
    const { amount = 1 } = options;
    return initial += amount;
  };
}
const first = create(1), second = create(10);
[first(), second(), first(), second()].join(':');
JS));
    }

    public function testCompilationFailureDoesNotRetainDiscardedSyntaxOrClosureEnvironment(): void
    {
        $engine = new Engine();
        $program = (new Parser('function rejected(value) { eval(""); return helper(value); }'))->parse();
        $declaration = $program->body[0];
        self::assertInstanceOf(\Phasis\Ast\Declaration\FunctionDeclaration::class, $declaration);
        $body = $declaration->body;
        $function = new JsFunction('rejected', $declaration->params, $body, $engine->getGlobalEnv()->createChild(), strict: true);
        $bodyReference = \WeakReference::create($body);
        $closureReference = \WeakReference::create($function->getClosure());
        self::assertNull(JsToPhp::compile($function));
        try {
            (new Compiler())->compile($function);
            self::fail('Dynamic scope must remain a structural compilation bailout.');
        } catch (CompilerBailout $error) {
            self::assertSame('uses eval', $error->getMessage());
        }
        unset($error, $function, $body, $declaration, $program);
        gc_collect_cycles();
        self::assertNull($bodyReference->get());
        self::assertNull($closureReference->get());
    }
}
