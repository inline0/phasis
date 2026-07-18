<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Parser;

use Phasis\Ast\Declaration\ExportDeclaration;
use Phasis\Ast\Declaration\ImportDeclaration;
use Phasis\Ast\Declaration\VariableDeclaration;
use Phasis\Ast\Expression\AwaitExpression;
use Phasis\Ast\Program;
use Phasis\Ast\Statement\ExpressionStatement;
use Phasis\Exceptions\SyntaxError;
use Phasis\Parser\ParseError;
use Phasis\Parser\Parser;
use PHPUnit\Framework\TestCase;

class PublicApiTest extends TestCase
{
    public function testParseSourceReturnsProgram(): void
    {
        $program = Parser::parseSource('var x = 1;');
        $this->assertInstanceOf(Program::class, $program);
        $this->assertSame('Program', $program->type());
        $this->assertCount(1, $program->body);
        $this->assertInstanceOf(VariableDeclaration::class, $program->body[0]);
    }

    public function testDefaultGoalIsScript(): void
    {
        $program = Parser::parseSource('var await = 1;');
        $this->assertInstanceOf(VariableDeclaration::class, $program->body[0]);
    }

    public function testScriptGoalRejectsTopLevelImport(): void
    {
        $this->expectException(SyntaxError::class);
        Parser::parseSource('import { a } from "m";');
    }

    public function testModuleGoalParsesImportAndExport(): void
    {
        $program = Parser::parseSource('import { a } from "m"; export const b = a;', 'module');
        $this->assertInstanceOf(ImportDeclaration::class, $program->body[0]);
        $this->assertInstanceOf(ExportDeclaration::class, $program->body[1]);
    }

    public function testModuleGoalReservesAwait(): void
    {
        $this->expectException(SyntaxError::class);
        Parser::parseSource('var await = 1;', 'module');
    }

    public function testModuleGoalParsesTopLevelAwait(): void
    {
        $program = Parser::parseSource('await 0;', 'module');
        $this->assertInstanceOf(ExpressionStatement::class, $program->body[0]);
        $this->assertInstanceOf(AwaitExpression::class, $program->body[0]->expression);
    }

    public function testModuleGoalIsStrict(): void
    {
        $this->expectException(SyntaxError::class);
        Parser::parseSource('with (a) {}', 'module');
    }

    public function testInvalidSourceTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sourceType must be "script" or "module", got "commonjs"');
        Parser::parseSource('1;', 'commonjs');
    }

    public function testSyntaxErrorCarriesLocation(): void
    {
        try {
            Parser::parseSource("const a = 1;\nconst b = ;\n");
            $this->fail('Expected SyntaxError');
        } catch (SyntaxError $e) {
            $this->assertInstanceOf(ParseError::class, $e);
            $this->assertNotNull($e->location);
            $this->assertSame(2, $e->location->line);
            $this->assertSame(10, $e->location->column);
            $this->assertSame(23, $e->location->offset);
            $this->assertStringContainsString('at line 2, column 10', $e->getMessage());
        }
    }
}
