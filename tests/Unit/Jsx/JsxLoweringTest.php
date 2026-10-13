<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Jsx;

use Phasis\Ast\Declaration\ImportDeclaration;
use Phasis\Ast\Expression\CallExpression;
use Phasis\Ast\Expression\Identifier;
use Phasis\Ast\Statement\ExpressionStatement;
use Phasis\Exceptions\SyntaxError;
use Phasis\Parser\Jsx\JsxMode;
use Phasis\Parser\Jsx\JsxOptions;
use Phasis\Parser\Parser;
use PHPUnit\Framework\TestCase;

final class JsxLoweringTest extends TestCase
{
    public function testAutomaticRuntimeImportsJsxAndJsxs(): void
    {
        $program = Parser::parseSource('x = <ul><li>a</li><li>b</li></ul>; y = <p>one</p>;', 'module', new JsxOptions());
        $import = $program->body[0];
        $this->assertInstanceOf(ImportDeclaration::class, $import);
        $this->assertSame('react/jsx-runtime', $import->source);
        $names = array_map(static fn ($s): ?string => $s->imported, $import->specifiers);
        $this->assertSame(['jsx', 'jsxs'], $names);
    }

    public function testImportSourcePragmaWins(): void
    {
        $program = Parser::parseSource("/** @jsxImportSource phasis */\nx = <p />;", 'module', new JsxOptions());
        $import = $program->body[0];
        $this->assertInstanceOf(ImportDeclaration::class, $import);
        $this->assertSame('phasis/jsx-runtime', $import->source);
    }

    public function testClassicRuntimeCallsTheFactory(): void
    {
        $program = Parser::parseSource("/** @jsx h */\nx = <p a=\"1\">t</p>;", 'module', new JsxOptions(mode: JsxMode::Classic));
        $statement = $program->body[0];
        $this->assertInstanceOf(ExpressionStatement::class, $statement);
        $call = $statement->expression->right;
        $this->assertInstanceOf(CallExpression::class, $call);
        $this->assertInstanceOf(Identifier::class, $call->callee);
        $this->assertSame('h', $call->callee->name);
    }

    public function testBareKeyIsAnError(): void
    {
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('Please provide an explicit value for "key"');
        Parser::parseSource('x = <p key />;', 'module', new JsxOptions());
    }

    public function testScriptGoalNeedsTheClassicRuntime(): void
    {
        $this->expectException(SyntaxError::class);
        Parser::parseSource('x = <p />;', 'script', new JsxOptions());
    }

    public function testOptionsDecideWhichFilesGetJsx(): void
    {
        $options = new JsxOptions();
        $this->assertTrue($options->appliesTo('/app/Card.jsx'));
        $this->assertFalse($options->appliesTo('/app/card.js'));
        $this->assertTrue((new JsxOptions(extensions: ['.jsx', '.js']))->appliesTo('/app/card.js'));
    }
}
