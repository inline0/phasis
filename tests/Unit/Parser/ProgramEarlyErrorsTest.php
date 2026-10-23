<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Parser;

use Phasis\Ast\Program;
use Phasis\Engine;
use Phasis\Exceptions\SyntaxError;
use Phasis\Parser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProgramEarlyErrorsTest extends TestCase
{
    #[DataProvider('invalidScripts')]
    public function testRejectsScriptEarlyErrorsDuringParsing(string $source): void
    {
        $this->expectException(SyntaxError::class);
        Parser::parseSource($source);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidScripts(): iterable
    {
        yield 'duplicate lexical names' => ['let x; let x;'];
        yield 'function conflicts with lexical' => ['function x() {} let x;'];
        yield 'nested var conflicts with lexical' => ['let x; if (false) { var x; }'];
        yield 'top-level new.target' => ['new.target;'];
        yield 'new.target in arrow' => ['const f = () => new.target;'];
        yield 'top-level return in block' => ['if (false) { return; }'];
        yield 'super reference' => ['super.value;'];
        yield 'unbound private name in function' => ['function read(value) { return value.#x; }'];
    }

    #[DataProvider('validScripts')]
    public function testScriptFunctionsAreVarScoped(string $source): void
    {
        self::assertInstanceOf(Program::class, Parser::parseSource($source));
    }

    /** @return iterable<string, array{string}> */
    public static function validScripts(): iterable
    {
        yield 'duplicate functions in strict script' => ['"use strict"; function f() {} function f() {}'];
        yield 'function and var share binding' => ['function f() {} var f;'];
        yield 'async and generator functions share binding' => ['async function f() {} function* f() {}'];
        yield 'nested functions have separate bindings' => ['let f; { function f() {} }'];
    }

    public function testDirectEvalInheritsMethodAndPrivateContexts(): void
    {
        $engine = new Engine();
        self::assertSame(42, $engine->eval(<<<'JS'
class Base { value() { return 40; } }
class Derived extends Base {
    #value = 2;
    read() { return eval('super.value() + this.#value'); }
}
new Derived().read();
JS));
        self::assertTrue($engine->eval(<<<'JS'
function Constructed() { this.same = eval('new.target') === Constructed; }
new Constructed().same;
JS));
    }

    public function testIndirectEvalDoesNotInheritFunctionContext(): void
    {
        $engine = new Engine();
        self::assertSame('SyntaxError', $engine->eval(<<<'JS'
function check() {
    try { (0, eval)('new.target'); } catch (error) { return error.name; }
}
check();
JS));
    }

    public function testFunctionConstructorParsesWithItsOwnFunctionContext(): void
    {
        $engine = new Engine();
        self::assertTrue($engine->eval(<<<'JS'
var Constructed = Function('this.same = new.target === arguments.callee; return;');
new Constructed().same;
JS));
    }

    #[DataProvider('sourceFragments')]
    public function testFragmentModeAcceptsToolingExcerpts(string $source, string $goal): void
    {
        $parser = new Parser($source);
        $parser->setModuleMode($goal === 'module');
        $parser->setFragmentMode(true);
        self::assertInstanceOf(Program::class, $parser->parse());
    }

    #[DataProvider('sourceFragments')]
    public function testDefaultParsingStillRequiresCompletePrograms(string $source, string $goal): void
    {
        $this->expectException(SyntaxError::class);
        Parser::parseSource($source, $goal);
    }

    #[DataProvider('sourceFragments')]
    public function testEngineDoesNotExecuteIncompleteFragments(string $source, string $goal): void
    {
        $engine = new Engine();
        $this->expectException(SyntaxError::class);
        if ($goal === 'module') {
            $engine->evalAsModule($source);
        } else {
            $engine->eval($source);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function sourceFragments(): iterable
    {
        yield 'return from enclosing function' => ['if (ready) { return value; }', 'script'];
        yield 'declaration elsewhere in module' => ['export { missing };', 'module'];
    }

    public function testFragmentModeStillRejectsDuplicateBindings(): void
    {
        $parser = new Parser('let value; let value;');
        $parser->setFragmentMode(true);
        $this->expectException(SyntaxError::class);
        $parser->parse();
    }
}
