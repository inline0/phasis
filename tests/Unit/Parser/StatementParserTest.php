<?php

declare(strict_types=1);

namespace PhpJs\Tests\Unit\Parser;

use PhpJs\Ast\Declaration\FunctionDeclaration;
use PhpJs\Ast\Declaration\VariableDeclaration;
use PhpJs\Ast\Expression\Identifier;
use PhpJs\Ast\Expression\Literal;
use PhpJs\Ast\Statement\BlockStatement;
use PhpJs\Ast\Statement\BreakStatement;
use PhpJs\Ast\Statement\ContinueStatement;
use PhpJs\Ast\Statement\DoWhileStatement;
use PhpJs\Ast\Statement\EmptyStatement;
use PhpJs\Ast\Statement\ExpressionStatement;
use PhpJs\Ast\Statement\ForInStatement;
use PhpJs\Ast\Statement\ForOfStatement;
use PhpJs\Ast\Statement\ForStatement;
use PhpJs\Ast\Statement\IfStatement;
use PhpJs\Ast\Statement\ReturnStatement;
use PhpJs\Ast\Statement\SwitchStatement;
use PhpJs\Ast\Statement\ThrowStatement;
use PhpJs\Ast\Statement\TryStatement;
use PhpJs\Ast\Statement\WhileStatement;
use PhpJs\Parser\Parser;
use PHPUnit\Framework\TestCase;

class StatementParserTest extends TestCase
{
    private function parse(string $source): array
    {
        $parser = new Parser($source);
        return $parser->parse()->body;
    }

    // Variable declarations

    public function testVarDeclaration(): void
    {
        $body = $this->parse('var x = 1;');
        $this->assertCount(1, $body);
        $this->assertInstanceOf(VariableDeclaration::class, $body[0]);
        $this->assertSame('var', $body[0]->kind);
        $this->assertCount(1, $body[0]->declarations);
    }

    public function testLetDeclaration(): void
    {
        $body = $this->parse('let x = 1;');
        $this->assertInstanceOf(VariableDeclaration::class, $body[0]);
        $this->assertSame('let', $body[0]->kind);
    }

    public function testConstDeclaration(): void
    {
        $body = $this->parse('const x = 1;');
        $this->assertInstanceOf(VariableDeclaration::class, $body[0]);
        $this->assertSame('const', $body[0]->kind);
    }

    public function testMultipleDeclarators(): void
    {
        $body = $this->parse('let a = 1, b = 2;');
        $this->assertInstanceOf(VariableDeclaration::class, $body[0]);
        $this->assertCount(2, $body[0]->declarations);
    }

    public function testUninitializedDeclarator(): void
    {
        $body = $this->parse('let x;');
        $this->assertInstanceOf(VariableDeclaration::class, $body[0]);
        $this->assertNull($body[0]->declarations[0]->init);
    }

    // Function declarations

    public function testFunctionDeclaration(): void
    {
        $body = $this->parse('function foo(a, b) { return a + b; }');
        $this->assertCount(1, $body);
        $this->assertInstanceOf(FunctionDeclaration::class, $body[0]);
        $this->assertSame('foo', $body[0]->id->name);
        $this->assertCount(2, $body[0]->params);
        $this->assertInstanceOf(BlockStatement::class, $body[0]->body);
    }

    // If statement

    public function testIfStatement(): void
    {
        $body = $this->parse('if (x) { y; }');
        $this->assertInstanceOf(IfStatement::class, $body[0]);
        $this->assertNull($body[0]->alternate);
    }

    public function testIfElseStatement(): void
    {
        $body = $this->parse('if (x) { y; } else { z; }');
        $this->assertInstanceOf(IfStatement::class, $body[0]);
        $this->assertNotNull($body[0]->alternate);
    }

    public function testIfElseIfStatement(): void
    {
        $body = $this->parse('if (a) { b; } else if (c) { d; } else { e; }');
        $this->assertInstanceOf(IfStatement::class, $body[0]);
        $this->assertInstanceOf(IfStatement::class, $body[0]->alternate);
    }

    // Loops

    public function testForStatement(): void
    {
        $body = $this->parse('for (let i = 0; i < 10; i++) { x; }');
        $this->assertInstanceOf(ForStatement::class, $body[0]);
        $this->assertNotNull($body[0]->init);
        $this->assertNotNull($body[0]->test);
        $this->assertNotNull($body[0]->update);
    }

    public function testForInStatement(): void
    {
        $body = $this->parse('for (let key in obj) { x; }');
        $this->assertInstanceOf(ForInStatement::class, $body[0]);
    }

    public function testForOfStatement(): void
    {
        $body = $this->parse('for (let item of arr) { x; }');
        $this->assertInstanceOf(ForOfStatement::class, $body[0]);
    }

    public function testWhileStatement(): void
    {
        $body = $this->parse('while (x) { y; }');
        $this->assertInstanceOf(WhileStatement::class, $body[0]);
    }

    public function testDoWhileStatement(): void
    {
        $body = $this->parse('do { y; } while (x);');
        $this->assertInstanceOf(DoWhileStatement::class, $body[0]);
    }

    // Switch

    public function testSwitchStatement(): void
    {
        $body = $this->parse('switch (x) { case 1: break; case 2: break; default: break; }');
        $this->assertInstanceOf(SwitchStatement::class, $body[0]);
        $this->assertCount(3, $body[0]->cases);
        $this->assertNull($body[0]->cases[2]->test); // default
    }

    // Try/catch/finally

    public function testTryCatch(): void
    {
        $body = $this->parse('try { x; } catch (e) { y; }');
        $this->assertInstanceOf(TryStatement::class, $body[0]);
        $this->assertNotNull($body[0]->handler);
        $this->assertNull($body[0]->finalizer);
    }

    public function testTryCatchFinally(): void
    {
        $body = $this->parse('try { x; } catch (e) { y; } finally { z; }');
        $this->assertInstanceOf(TryStatement::class, $body[0]);
        $this->assertNotNull($body[0]->handler);
        $this->assertNotNull($body[0]->finalizer);
    }

    public function testTryFinally(): void
    {
        $body = $this->parse('try { x; } finally { z; }');
        $this->assertInstanceOf(TryStatement::class, $body[0]);
        $this->assertNull($body[0]->handler);
        $this->assertNotNull($body[0]->finalizer);
    }

    public function testOptionalCatchBinding(): void
    {
        $body = $this->parse('try { x; } catch { y; }');
        $this->assertInstanceOf(TryStatement::class, $body[0]);
        $this->assertNull($body[0]->handler->param);
    }

    // Return, throw, break, continue

    public function testReturnStatement(): void
    {
        $body = $this->parse('function f() { return 42; }');
        $decl = $body[0];
        $this->assertInstanceOf(FunctionDeclaration::class, $decl);
        $returnStmt = $decl->body->body[0];
        $this->assertInstanceOf(ReturnStatement::class, $returnStmt);
        $this->assertInstanceOf(Literal::class, $returnStmt->argument);
    }

    public function testReturnVoid(): void
    {
        $body = $this->parse('function f() { return; }');
        $returnStmt = $body[0]->body->body[0];
        $this->assertInstanceOf(ReturnStatement::class, $returnStmt);
        $this->assertNull($returnStmt->argument);
    }

    public function testThrowStatement(): void
    {
        $body = $this->parse('throw new Error("fail");');
        $this->assertInstanceOf(ThrowStatement::class, $body[0]);
    }

    public function testBreakStatement(): void
    {
        $body = $this->parse('while (true) { break; }');
        $breakStmt = $body[0]->body->body[0];
        $this->assertInstanceOf(BreakStatement::class, $breakStmt);
        $this->assertNull($breakStmt->label);
    }

    public function testContinueStatement(): void
    {
        $body = $this->parse('while (true) { continue; }');
        $continueStmt = $body[0]->body->body[0];
        $this->assertInstanceOf(ContinueStatement::class, $continueStmt);
    }

    // Empty statement

    public function testEmptyStatement(): void
    {
        $body = $this->parse(';');
        $this->assertInstanceOf(EmptyStatement::class, $body[0]);
    }

    // Block statement

    public function testBlockStatement(): void
    {
        $body = $this->parse('{ let x = 1; let y = 2; }');
        $this->assertInstanceOf(BlockStatement::class, $body[0]);
        $this->assertCount(2, $body[0]->body);
    }

    // ASI

    public function testASIAfterReturn(): void
    {
        $body = $this->parse("function f() {\n  return\n  42\n}");
        $decl = $body[0];
        $this->assertInstanceOf(FunctionDeclaration::class, $decl);
        $returnStmt = $decl->body->body[0];
        $this->assertInstanceOf(ReturnStatement::class, $returnStmt);
        $this->assertNull($returnStmt->argument); // ASI inserts ; after return
    }

    public function testASIBeforeClosingBrace(): void
    {
        $body = $this->parse('{ let x = 1 }');
        $this->assertInstanceOf(BlockStatement::class, $body[0]);
    }

    public function testASIAtEndOfInput(): void
    {
        $body = $this->parse('let x = 1');
        $this->assertInstanceOf(VariableDeclaration::class, $body[0]);
    }

    // Multiple statements

    public function testMultipleStatements(): void
    {
        $body = $this->parse('let x = 1; let y = 2; x + y;');
        $this->assertCount(3, $body);
        $this->assertInstanceOf(VariableDeclaration::class, $body[0]);
        $this->assertInstanceOf(VariableDeclaration::class, $body[1]);
        $this->assertInstanceOf(ExpressionStatement::class, $body[2]);
    }

    // Empty program

    public function testEmptyProgram(): void
    {
        $body = $this->parse('');
        $this->assertEmpty($body);
    }

    // Syntax error

    public function testSyntaxError(): void
    {
        $this->expectException(\PhpJs\Exceptions\SyntaxError::class);
        $this->parse('let = ;');
    }
}
