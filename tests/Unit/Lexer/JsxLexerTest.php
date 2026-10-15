<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Lexer;

use Phasis\Exceptions\SyntaxError;
use Phasis\Lexer\Lexer;
use Phasis\Lexer\TokenType;
use PHPUnit\Framework\TestCase;

class JsxLexerTest extends TestCase
{
    /** @return list<string> */
    private function tokens(string $source, bool $jsx = true): array
    {
        $lexer = new Lexer($source);
        $lexer->setJsx($jsx);
        $result = [];
        foreach ($lexer->tokenize() as $token) {
            if ($token->type === TokenType::EOF) {
                break;
            }
            $result[] = $token->type->name . ':' . $token->value;
        }
        return $result;
    }

    public function testTextIsNotLexedAsJavaScript(): void
    {
        $this->assertSame(
            ['JsxTagStart:<', 'JsxName:p', 'JsxTagEnd:>', "JsxText:Don't // x /* y */", 'JsxTagStart:<', 'Slash:/', 'JsxName:p', 'JsxTagEnd:>'],
            $this->tokens("<p>Don't // x /* y */</p>"),
        );
    }

    public function testNamesMayContainDashesAndKeywords(): void
    {
        $this->assertSame(
            ['JsxTagStart:<', 'JsxName:my-el', 'JsxName:class', 'Equal:=', 'JsxString:a b', 'JsxName:for', 'Slash:/', 'JsxTagEnd:>'],
            $this->tokens('<my-el class="a b" for />'),
        );
    }

    public function testAttributeStringsKeepBackslashesAndNewlines(): void
    {
        $tokens = $this->tokens("<a t='x\\n\ny' />");
        $this->assertContains("JsxString:x\\n\ny", $tokens);
    }

    public function testExpressionContainersCountBraces(): void
    {
        $this->assertSame(
            ['JsxTagStart:<', 'JsxName:a', 'JsxName:b', 'Equal:=', 'LeftBrace:{', 'LeftBrace:{', 'Identifier:c', 'Colon::',
                'Number:1', 'RightBrace:}', 'RightBrace:}', 'Slash:/', 'JsxTagEnd:>'],
            $this->tokens('<a b={{c: 1}} />'),
        );
    }

    public function testTemplatesAndJsxNestBothWays(): void
    {
        $this->assertSame(
            ['TemplateHead:', 'JsxTagStart:<', 'JsxName:b', 'JsxTagEnd:>', 'LeftBrace:{', 'NoSubstitutionTemplate:x',
                'RightBrace:}', 'JsxTagStart:<', 'Slash:/', 'JsxName:b', 'JsxTagEnd:>', 'TemplateTail:'],
            $this->tokens('`${<b>{`x`}</b>}`'),
        );
    }

    public function testLessThanAfterAValueStaysAComparison(): void
    {
        $this->assertSame(['Identifier:a', 'LessThan:<', 'Identifier:b'], $this->tokens('a < b'));
        $this->assertSame(
            ['Identifier:x', 'Equal:=', 'JsxTagStart:<', 'JsxName:a', 'Slash:/', 'JsxTagEnd:>', 'Slash:/', 'Number:2'],
            $this->tokens('x = <a /> / 2'),
        );
    }

    public function testJsxOffLeavesTokenizationUnchanged(): void
    {
        $this->assertSame(['Identifier:x', 'Equal:=', 'LessThan:<', 'Identifier:a', 'GreaterThan:>'], $this->tokens('x = <a>', false));
    }

    public function testPragmasAreCaptured(): void
    {
        $lexer = new Lexer("/** @jsxRuntime classic */\n// @jsx h\n/* @jsxFrag Frag */ x");
        $lexer->setJsx(true);
        $lexer->tokenize();
        $this->assertSame(['jsxRuntime' => 'classic', 'jsx' => 'h', 'jsxFrag' => 'Frag'], $lexer->jsxPragmas());
    }

    public function testAdjacentRootsAreRejected(): void
    {
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('Adjacent JSX elements must be wrapped in an enclosing tag');
        $this->tokens("x = <a />\n<b />");
    }

    public function testUnterminatedContentsAreRejected(): void
    {
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('Unterminated JSX contents.');
        $this->tokens('<a>text');
    }

    public function testLocationsTrackLinesInsideText(): void
    {
        $lexer = new Lexer("<a>\n\n  x{y}</a>");
        $lexer->setJsx(true);
        $tokens = $lexer->tokenize();
        $this->assertSame(TokenType::Identifier, $tokens[5]->type);
        $this->assertSame(3, $tokens[5]->location->line);
        $this->assertSame(4, $tokens[5]->location->column);
    }
}
