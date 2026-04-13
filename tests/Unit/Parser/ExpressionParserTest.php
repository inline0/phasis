<?php

declare(strict_types=1);

namespace PhpJs\Tests\Unit\Parser;

use PhpJs\Ast\Expression\ArrowFunction;
use PhpJs\Ast\Expression\AssignmentExpression;
use PhpJs\Ast\Expression\BinaryExpression;
use PhpJs\Ast\Expression\CallExpression;
use PhpJs\Ast\Expression\ConditionalExpression;
use PhpJs\Ast\Expression\FunctionExpression;
use PhpJs\Ast\Expression\Identifier;
use PhpJs\Ast\Expression\Literal;
use PhpJs\Ast\Expression\LogicalExpression;
use PhpJs\Ast\Expression\MemberExpression;
use PhpJs\Ast\Expression\NewExpression;
use PhpJs\Ast\Expression\ObjectExpression;
use PhpJs\Ast\Expression\ArrayExpression;
use PhpJs\Ast\Expression\SequenceExpression;
use PhpJs\Ast\Expression\SpreadElement;
use PhpJs\Ast\Expression\TemplateLiteral;
use PhpJs\Ast\Expression\ThisExpression;
use PhpJs\Ast\Expression\UnaryExpression;
use PhpJs\Ast\Expression\UpdateExpression;
use PhpJs\Ast\Node;
use PhpJs\Ast\Statement\ExpressionStatement;
use PhpJs\Parser\Parser;
use PHPUnit\Framework\TestCase;

class ExpressionParserTest extends TestCase
{
    private function parseExpression(string $source): Node
    {
        $parser = new Parser($source);
        $program = $parser->parse();
        $this->assertCount(1, $program->body);
        $stmt = $program->body[0];
        $this->assertInstanceOf(ExpressionStatement::class, $stmt);
        return $stmt->expression;
    }

    // Literals

    public function testNumericLiterals(): void
    {
        $expr = $this->parseExpression('42;');
        $this->assertInstanceOf(Literal::class, $expr);
        $this->assertSame(42, $expr->value);
    }

    public function testFloatLiteral(): void
    {
        $expr = $this->parseExpression('3.14;');
        $this->assertInstanceOf(Literal::class, $expr);
        $this->assertSame(3.14, $expr->value);
    }

    public function testHexLiteral(): void
    {
        $expr = $this->parseExpression('0xFF;');
        $this->assertInstanceOf(Literal::class, $expr);
        $this->assertSame(255, $expr->value);
    }

    public function testStringLiteral(): void
    {
        $expr = $this->parseExpression('"hello";');
        $this->assertInstanceOf(Literal::class, $expr);
        $this->assertSame('hello', $expr->value);
    }

    public function testBooleanLiterals(): void
    {
        $expr = $this->parseExpression('true;');
        $this->assertInstanceOf(Literal::class, $expr);
        $this->assertTrue($expr->value);

        $expr = $this->parseExpression('false;');
        $this->assertInstanceOf(Literal::class, $expr);
        $this->assertFalse($expr->value);
    }

    public function testNullLiteral(): void
    {
        $expr = $this->parseExpression('null;');
        $this->assertInstanceOf(Literal::class, $expr);
        $this->assertNull($expr->value);
    }

    // Binary expressions

    public function testAddition(): void
    {
        $expr = $this->parseExpression('1 + 2;');
        $this->assertInstanceOf(BinaryExpression::class, $expr);
        $this->assertSame('+', $expr->operator);
        $this->assertInstanceOf(Literal::class, $expr->left);
        $this->assertInstanceOf(Literal::class, $expr->right);
    }

    public function testPrecedence(): void
    {
        // 1 + 2 * 3 should be 1 + (2 * 3)
        $expr = $this->parseExpression('1 + 2 * 3;');
        $this->assertInstanceOf(BinaryExpression::class, $expr);
        $this->assertSame('+', $expr->operator);
        $this->assertInstanceOf(Literal::class, $expr->left);
        $this->assertInstanceOf(BinaryExpression::class, $expr->right);
        $this->assertSame('*', $expr->right->operator);
    }

    public function testComparison(): void
    {
        $expr = $this->parseExpression('a === b;');
        $this->assertInstanceOf(BinaryExpression::class, $expr);
        $this->assertSame('===', $expr->operator);
    }

    public function testLogicalOperators(): void
    {
        $expr = $this->parseExpression('a && b || c;');
        $this->assertInstanceOf(LogicalExpression::class, $expr);
        $this->assertSame('||', $expr->operator);
        $this->assertInstanceOf(LogicalExpression::class, $expr->left);
        $this->assertSame('&&', $expr->left->operator);
    }

    // Unary expressions

    public function testUnaryNot(): void
    {
        $expr = $this->parseExpression('!x;');
        $this->assertInstanceOf(UnaryExpression::class, $expr);
        $this->assertSame('!', $expr->operator);
        $this->assertTrue($expr->prefix);
    }

    public function testTypeof(): void
    {
        $expr = $this->parseExpression('typeof x;');
        $this->assertInstanceOf(UnaryExpression::class, $expr);
        $this->assertSame('typeof', $expr->operator);
    }

    public function testUnaryMinus(): void
    {
        $expr = $this->parseExpression('-x;');
        $this->assertInstanceOf(UnaryExpression::class, $expr);
        $this->assertSame('-', $expr->operator);
    }

    // Update expressions

    public function testPrefixIncrement(): void
    {
        $expr = $this->parseExpression('++x;');
        $this->assertInstanceOf(UpdateExpression::class, $expr);
        $this->assertSame('++', $expr->operator);
        $this->assertTrue($expr->prefix);
    }

    public function testPostfixDecrement(): void
    {
        $expr = $this->parseExpression('x--;');
        $this->assertInstanceOf(UpdateExpression::class, $expr);
        $this->assertSame('--', $expr->operator);
        $this->assertFalse($expr->prefix);
    }

    // Assignment

    public function testAssignment(): void
    {
        $expr = $this->parseExpression('x = 42;');
        $this->assertInstanceOf(AssignmentExpression::class, $expr);
        $this->assertSame('=', $expr->operator);
        $this->assertInstanceOf(Identifier::class, $expr->left);
        $this->assertInstanceOf(Literal::class, $expr->right);
    }

    public function testCompoundAssignment(): void
    {
        $expr = $this->parseExpression('x += 1;');
        $this->assertInstanceOf(AssignmentExpression::class, $expr);
        $this->assertSame('+=', $expr->operator);
    }

    // Conditional

    public function testConditional(): void
    {
        $expr = $this->parseExpression('a ? b : c;');
        $this->assertInstanceOf(ConditionalExpression::class, $expr);
    }

    // Member access

    public function testDotAccess(): void
    {
        $expr = $this->parseExpression('obj.prop;');
        $this->assertInstanceOf(MemberExpression::class, $expr);
        $this->assertFalse($expr->computed);
        $this->assertInstanceOf(Identifier::class, $expr->object);
        $this->assertInstanceOf(Identifier::class, $expr->property);
    }

    public function testBracketAccess(): void
    {
        $expr = $this->parseExpression('obj["prop"];');
        $this->assertInstanceOf(MemberExpression::class, $expr);
        $this->assertTrue($expr->computed);
    }

    public function testChainedAccess(): void
    {
        $expr = $this->parseExpression('a.b.c;');
        $this->assertInstanceOf(MemberExpression::class, $expr);
        $this->assertInstanceOf(MemberExpression::class, $expr->object);
    }

    // Call expressions

    public function testFunctionCall(): void
    {
        $expr = $this->parseExpression('foo();');
        $this->assertInstanceOf(CallExpression::class, $expr);
        $this->assertEmpty($expr->arguments);
    }

    public function testCallWithArguments(): void
    {
        $expr = $this->parseExpression('foo(1, 2, 3);');
        $this->assertInstanceOf(CallExpression::class, $expr);
        $this->assertCount(3, $expr->arguments);
    }

    public function testMethodCall(): void
    {
        $expr = $this->parseExpression('obj.method(x);');
        $this->assertInstanceOf(CallExpression::class, $expr);
        $this->assertInstanceOf(MemberExpression::class, $expr->callee);
    }

    // New expression

    public function testNewExpression(): void
    {
        $expr = $this->parseExpression('new Foo();');
        $this->assertInstanceOf(NewExpression::class, $expr);
        $this->assertInstanceOf(Identifier::class, $expr->callee);
    }

    public function testNewWithArgs(): void
    {
        $expr = $this->parseExpression('new Foo(1, 2);');
        $this->assertInstanceOf(NewExpression::class, $expr);
        $this->assertCount(2, $expr->arguments);
    }

    // Array expression

    public function testArrayLiteral(): void
    {
        $expr = $this->parseExpression('[1, 2, 3];');
        $this->assertInstanceOf(ArrayExpression::class, $expr);
        $this->assertCount(3, $expr->elements);
    }

    public function testArrayWithHoles(): void
    {
        $expr = $this->parseExpression('[1, , 3];');
        $this->assertInstanceOf(ArrayExpression::class, $expr);
        $this->assertCount(3, $expr->elements);
        $this->assertNull($expr->elements[1]);
    }

    public function testArrayWithSpread(): void
    {
        $expr = $this->parseExpression('[...arr];');
        $this->assertInstanceOf(ArrayExpression::class, $expr);
        $this->assertInstanceOf(SpreadElement::class, $expr->elements[0]);
    }

    // Object expression

    public function testObjectLiteral(): void
    {
        $expr = $this->parseExpression('({a: 1, b: 2});');
        $this->assertInstanceOf(ObjectExpression::class, $expr);
        $this->assertCount(2, $expr->properties);
    }

    public function testShorthandProperty(): void
    {
        $expr = $this->parseExpression('({x});');
        $this->assertInstanceOf(ObjectExpression::class, $expr);
        $this->assertCount(1, $expr->properties);
        $this->assertTrue($expr->properties[0]->shorthand);
    }

    // Arrow function

    public function testArrowFunction(): void
    {
        $expr = $this->parseExpression('x => x * 2;');
        $this->assertInstanceOf(ArrowFunction::class, $expr);
        $this->assertTrue($expr->expression);
        $this->assertCount(1, $expr->params);
    }

    public function testArrowFunctionWithParens(): void
    {
        $expr = $this->parseExpression('(a, b) => a + b;');
        $this->assertInstanceOf(ArrowFunction::class, $expr);
        $this->assertCount(2, $expr->params);
    }

    public function testArrowFunctionWithBlock(): void
    {
        $expr = $this->parseExpression('(x) => { return x; };');
        $this->assertInstanceOf(ArrowFunction::class, $expr);
        $this->assertFalse($expr->expression);
    }

    // Function expression

    public function testFunctionExpression(): void
    {
        $expr = $this->parseExpression('(function(x) { return x; });');
        $this->assertInstanceOf(FunctionExpression::class, $expr);
        $this->assertNull($expr->name);
        $this->assertCount(1, $expr->params);
    }

    public function testNamedFunctionExpression(): void
    {
        $expr = $this->parseExpression('(function add(a, b) { return a + b; });');
        $this->assertInstanceOf(FunctionExpression::class, $expr);
        $this->assertSame('add', $expr->name);
    }

    // This

    public function testThisExpression(): void
    {
        $expr = $this->parseExpression('this;');
        $this->assertInstanceOf(ThisExpression::class, $expr);
    }

    // Sequence (comma)

    public function testSequenceExpression(): void
    {
        $expr = $this->parseExpression('(1, 2, 3);');
        $this->assertInstanceOf(SequenceExpression::class, $expr);
        $this->assertCount(3, $expr->expressions);
    }

    // Template literal

    public function testSimpleTemplateLiteral(): void
    {
        $expr = $this->parseExpression('`hello`;');
        $this->assertInstanceOf(TemplateLiteral::class, $expr);
        $this->assertCount(1, $expr->quasis);
        $this->assertEmpty($expr->expressions);
    }

    // Optional chaining

    public function testOptionalChaining(): void
    {
        $expr = $this->parseExpression('obj?.prop;');
        $this->assertInstanceOf(MemberExpression::class, $expr);
        $this->assertTrue($expr->optional);
    }

    // Nullish coalescing

    public function testNullishCoalescing(): void
    {
        $expr = $this->parseExpression('a ?? b;');
        $this->assertInstanceOf(LogicalExpression::class, $expr);
        $this->assertSame('??', $expr->operator);
    }

    // Exponentiation (right-associative)

    public function testExponentiation(): void
    {
        // 2 ** 3 ** 2 should be 2 ** (3 ** 2) (right-associative)
        $expr = $this->parseExpression('2 ** 3 ** 2;');
        $this->assertInstanceOf(BinaryExpression::class, $expr);
        $this->assertSame('**', $expr->operator);
        $this->assertInstanceOf(BinaryExpression::class, $expr->right);
        $this->assertSame('**', $expr->right->operator);
    }
}
