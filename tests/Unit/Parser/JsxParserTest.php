<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Parser;

use Phasis\Ast\Jsx\JsxElement;
use Phasis\Ast\Jsx\JsxText;
use Phasis\Ast\Statement\ExpressionStatement;
use Phasis\Exceptions\SyntaxError;
use Phasis\Parser\Jsx\JsxMode;
use Phasis\Parser\Jsx\JsxOptions;
use Phasis\Parser\Parser;
use PHPUnit\Framework\TestCase;

class JsxParserTest extends TestCase
{
    public function testPreserveKeepsJsxNodes(): void
    {
        $program = Parser::parseSource('<p>a &amp; b</p>;', 'module', new JsxOptions(mode: JsxMode::Preserve));
        $statement = $program->body[0];
        $this->assertInstanceOf(ExpressionStatement::class, $statement);
        $element = $statement->expression;
        $this->assertInstanceOf(JsxElement::class, $element);
        $text = $element->children[0];
        $this->assertInstanceOf(JsxText::class, $text);
        $this->assertSame('a & b', $text->value);
        $this->assertSame('a &amp; b', $text->raw);
    }

    public function testJsxIsOffWithoutOptions(): void
    {
        $this->expectException(SyntaxError::class);
        Parser::parseSource('x = <p />;', 'module');
    }

    public function testMismatchedClosingTag(): void
    {
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('Expected corresponding JSX closing tag for <a>.');
        Parser::parseSource('x = <a></b>;', 'module', new JsxOptions());
    }

    public function testEmptyAttributeExpression(): void
    {
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('JSX attributes must only be assigned a non-empty expression.');
        Parser::parseSource('x = <a b={} />;', 'module', new JsxOptions());
    }

    public function testAutomaticRuntimeNeedsModuleCode(): void
    {
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('The automatic JSX runtime needs module code');
        Parser::parseSource('x = <a />;', 'script', new JsxOptions());
    }

    public function testClassicRuntimeWorksInScripts(): void
    {
        $program = Parser::parseSource('x = <a />;', 'script', new JsxOptions(mode: JsxMode::Classic, factory: 'h'));
        $this->assertCount(1, $program->body);
    }

    public function testInjectedImportsAvoidNamesUsedInTheFile(): void
    {
        $program = Parser::parseSource('const _jsx = 1; x = <a />;', 'module', new JsxOptions());
        $import = $program->body[0];
        $this->assertInstanceOf(\Phasis\Ast\Declaration\ImportDeclaration::class, $import);
        $this->assertSame('_jsx2', $import->specifiers[0]->local);
    }
}
