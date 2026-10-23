<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Parser;

use Phasis\Ast\Program;
use Phasis\Engine;
use Phasis\Exceptions\SyntaxError;
use Phasis\Parser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FunctionBindingEarlyErrorsTest extends TestCase
{
    #[DataProvider('restrictedFunctionBindings')]
    public function testRejectsStrictNamesBeforeExecution(string $source): void
    {
        $this->expectException(SyntaxError::class);
        Parser::parseSource($source);
    }

    /** @return iterable<string, array{string}> */
    public static function restrictedFunctionBindings(): iterable
    {
        yield 'strict declaration' => ['"use strict"; function arguments() {}'];
        yield 'declaration strict body' => ['function eval() { "use strict"; }'];
        yield 'expression strict body' => ['(function arguments() { "use strict"; });'];
        yield 'async expression strict body' => ['(async function eval() { "use strict"; });'];
        yield 'async generator inherited strict' => ['"use strict"; (async function* arguments() {});'];
        yield 'strict concise arrow' => ['"use strict"; arguments => 1;'];
        yield 'strict covered concise arrow' => ['"use strict"; (eval) => 1;'];
        yield 'strict async concise arrow' => ['"use strict"; async (arguments) => 1;'];
    }

    #[DataProvider('validFunctionBindings')]
    public function testRetainsSloppyBindingsAndMethodNames(string $source): void
    {
        self::assertInstanceOf(Program::class, Parser::parseSource($source));
    }

    /** @return iterable<string, array{string}> */
    public static function validFunctionBindings(): iterable
    {
        yield 'sloppy declarations' => ['function eval() {} async function arguments() {}'];
        yield 'sloppy expressions' => ['(function arguments() {}); (async function* eval() {});'];
        yield 'sloppy concise arrows' => ['arguments => 1; (eval) => 1; async (eval) => 1;'];
        yield 'method keys are not bindings' => ['"use strict"; ({ eval() {}, arguments() {} });'];
        yield 'class method keys' => ['class C { eval() {} static arguments() {} }'];
    }

    public function testDeleteOfNewTargetIsAValueOperationInStrictCode(): void
    {
        $engine = new Engine();
        self::assertTrue($engine->eval('"use strict"; (function () { return delete new.target; })();'));
    }

    public function testEscapedLetRemainsAnIdentifierAcrossLineBreaks(): void
    {
        $engine = new Engine();
        self::assertSame(2, $engine->eval("var let = 1; l\\u0065t\na; var a = 2; a;"));
    }
}
