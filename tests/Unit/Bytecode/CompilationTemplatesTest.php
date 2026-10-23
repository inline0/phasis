<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Bytecode\Compiler;
use Phasis\Engine;
use Phasis\Parser\Parser;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use PHPUnit\Framework\TestCase;

final class CompilationTemplatesTest extends TestCase
{
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
        $first->compiled->loadNameIc[9876] = [JsNumber::of(30), 0];
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
}
