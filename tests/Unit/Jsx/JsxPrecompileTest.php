<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Jsx;

use Phasis\Ast\Declaration\ImportDeclaration;
use Phasis\Ast\Declaration\VariableDeclaration;
use Phasis\Ast\Expression\ArrayExpression;
use Phasis\Ast\Expression\Literal;
use Phasis\Parser\Jsx\JsxMode;
use Phasis\Parser\Jsx\JsxOptions;
use Phasis\Parser\Parser;
use PHPUnit\Framework\TestCase;

final class JsxPrecompileTest extends TestCase
{
    /** @return list<string> */
    private static function templateParts(string $source): array
    {
        $program = Parser::parseSource($source, 'module', new JsxOptions(mode: JsxMode::Precompile));
        foreach ($program->body as $node) {
            if ($node instanceof VariableDeclaration) {
                $init = $node->declarations[0]->init;
                if ($init instanceof ArrayExpression) {
                    $parts = [];
                    foreach ($init->elements as $element) {
                        if ($element instanceof Literal && is_string($element->value)) {
                            $parts[] = $element->value;
                        }
                    }
                    return $parts;
                }
            }
        }
        return [];
    }

    public function testStaticHtmlBecomesStringParts(): void
    {
        $parts = self::templateParts('export const C = ({ title }) => <div className="card"><h2>{title}</h2><p>Static text</p></div>;');
        $this->assertSame(['<h2>', '</h2><p>Static text</p>'], $parts);
    }

    public function testHelpersComeFromTheInternalModule(): void
    {
        $program = Parser::parseSource('x = <div><p class="a" id={y}>t</p></div>;', 'module', new JsxOptions(mode: JsxMode::Precompile));
        $sources = [];
        foreach ($program->body as $node) {
            if ($node instanceof ImportDeclaration) {
                $sources[$node->source] = array_map(static fn ($s): ?string => $s->imported, $node->specifiers);
            }
        }
        $this->assertEqualsCanonicalizing(['jsxTemplate', 'jsxAttr'], $sources['phasis/jsx-precompile']);
        $this->assertSame(['<p class="a"', '>t</p>'], self::templateParts('x = <div><p className="a" id={y}>t</p></div>;'));
    }

    public function testHandlersAndComponentsStayOutOfTheStaticParts(): void
    {
        $parts = self::templateParts('x = <div><button onClick={() => go()}>b</button><p onClick={() => go()}>p<Card /></p></div>;');
        $this->assertSame(['', '<p>p', '</p>'], $parts);
    }

    public function testOutermostElementAndComponentChildrenStayElements(): void
    {
        $this->assertSame([], self::templateParts('x = <div>only text {y}</div>;'));
        $this->assertSame([], self::templateParts('x = <Card><span>child of a component</span></Card>;'));
        $this->assertSame([], self::templateParts('x = <div {...rest}><p>spread</p></div>;'));
    }

    public function testHtmlProfileUsesPreactSerialization(): void
    {
        $parts = self::templateParts("/** @jsxImportSource phasis */\nx = <div><p className=\"a\" readOnly>a & b</p><br /></div>;");
        $this->assertSame(['<p class="a" readonly>a &amp; b</p><br/>'], $parts);
    }
}
