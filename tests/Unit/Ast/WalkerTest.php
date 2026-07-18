<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Ast;

use Phasis\Ast\Expression\BinaryExpression;
use Phasis\Ast\Node;
use Phasis\Ast\Walker;
use Phasis\Parser\Parser;
use PHPUnit\Framework\TestCase;

class WalkerTest extends TestCase
{
    public function testEnterAndLeaveOrder(): void
    {
        $program = Parser::parseSource('a + b;');
        $events = [];
        Walker::walk(
            $program,
            function (Node $node) use (&$events): void {
                $events[] = 'enter:' . $node->type();
            },
            function (Node $node) use (&$events): void {
                $events[] = 'leave:' . $node->type();
            },
        );
        $this->assertSame([
            'enter:Program',
            'enter:ExpressionStatement',
            'enter:BinaryExpression',
            'enter:Identifier',
            'leave:Identifier',
            'enter:Identifier',
            'leave:Identifier',
            'leave:BinaryExpression',
            'leave:ExpressionStatement',
            'leave:Program',
        ], $events);
    }

    public function testEnterReceivesParent(): void
    {
        $program = Parser::parseSource('a + b;');
        $pairs = [];
        Walker::walk($program, function (Node $node, ?Node $parent) use (&$pairs): void {
            $pairs[] = $node->type() . '<' . ($parent === null ? 'null' : $parent->type());
        });
        $this->assertSame([
            'Program<null',
            'ExpressionStatement<Program',
            'BinaryExpression<ExpressionStatement',
            'Identifier<BinaryExpression',
            'Identifier<BinaryExpression',
        ], $pairs);
    }

    public function testSkipPrunesChildrenButStillLeaves(): void
    {
        $program = Parser::parseSource('a + b; c;');
        $entered = [];
        $left = [];
        Walker::walk(
            $program,
            function (Node $node) use (&$entered) {
                $entered[] = $node->type();
                return $node instanceof BinaryExpression ? Walker::SKIP : null;
            },
            function (Node $node) use (&$left): void {
                $left[] = $node->type();
            },
        );
        $this->assertSame([
            'Program',
            'ExpressionStatement',
            'BinaryExpression',
            'ExpressionStatement',
            'Identifier',
        ], $entered);
        $this->assertContains('BinaryExpression', $left);
    }

    public function testWalksDeclarationsAndFunctions(): void
    {
        $program = Parser::parseSource('function f(x) { return x * 2; } const y = f(1);');
        $types = [];
        Walker::walk($program, function (Node $node) use (&$types): void {
            $types[] = $node->type();
        });
        $this->assertContains('FunctionDeclaration', $types);
        $this->assertContains('ReturnStatement', $types);
        $this->assertContains('VariableDeclarator', $types);
        $this->assertContains('CallExpression', $types);
    }
}
