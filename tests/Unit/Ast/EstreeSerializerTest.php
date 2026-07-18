<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Ast;

use Phasis\Ast\EstreeSerializer;
use Phasis\Parser\Parser;
use PHPUnit\Framework\TestCase;

class EstreeSerializerTest extends TestCase
{
    public function testRenamesDeviatingTypes(): void
    {
        $ast = EstreeSerializer::toArray(Parser::parseSource('const f = () => tag`a`;'));
        $arrow = $ast['body'][0]['declarations'][0]['init'];
        $this->assertSame('ArrowFunctionExpression', $arrow['type']);
        $this->assertSame('TaggedTemplateExpression', $arrow['body']['type']);
    }

    public function testWrapsClassElementsInClassBody(): void
    {
        $ast = EstreeSerializer::toArray(Parser::parseSource('class C { #p = 1; m() {} static s() {} }'));
        $class = $ast['body'][0];
        $this->assertSame('ClassDeclaration', $class['type']);
        $this->assertSame('ClassBody', $class['body']['type']);
        $property = $class['body']['body'][0];
        $this->assertSame('PropertyDefinition', $property['type']);
        $this->assertSame('PrivateIdentifier', $property['key']['type']);
        $this->assertSame('p', $property['key']['name']);
        $method = $class['body']['body'][1];
        $this->assertSame('MethodDefinition', $method['type']);
        $this->assertSame('method', $method['kind']);
        $this->assertFalse($method['static']);
        $this->assertSame('FunctionExpression', $method['value']['type']);
        $this->assertTrue($class['body']['body'][2]['static']);
    }

    public function testSynthesizesImportAndExportShapes(): void
    {
        $source = 'import d, { a as b } from "m"; import * as ns from "n"; export { b }; export default d;';
        $ast = EstreeSerializer::toArray(Parser::parseSource($source, 'module'));
        [$import, $namespace, $named, $default] = $ast['body'];
        $this->assertSame('ImportDefaultSpecifier', $import['specifiers'][0]['type']);
        $this->assertSame('ImportSpecifier', $import['specifiers'][1]['type']);
        $this->assertSame('a', $import['specifiers'][1]['imported']['name']);
        $this->assertSame('b', $import['specifiers'][1]['local']['name']);
        $this->assertSame(['type' => 'Literal', 'value' => 'm'], $import['source']);
        $this->assertSame('ImportNamespaceSpecifier', $namespace['specifiers'][0]['type']);
        $this->assertSame('ExportNamedDeclaration', $named['type']);
        $this->assertSame('ExportSpecifier', $named['specifiers'][0]['type']);
        $this->assertSame('ExportDefaultDeclaration', $default['type']);
    }

    public function testCarriesStartOffsets(): void
    {
        $ast = EstreeSerializer::toArray(Parser::parseSource('let x = y;'));
        $this->assertSame(0, $ast['start']);
        $declarator = $ast['body'][0]['declarations'][0];
        $this->assertSame(4, $declarator['start']);
        $this->assertSame(8, $declarator['init']['start']);
    }

    public function testRegexLiteralShape(): void
    {
        $ast = EstreeSerializer::toArray(Parser::parseSource('/ab+c/gi;'));
        $literal = $ast['body'][0]['expression'];
        $this->assertSame('Literal', $literal['type']);
        $this->assertNull($literal['value']);
        $this->assertSame(['pattern' => 'ab+c', 'flags' => 'gi'], $literal['regex']);
    }

    public function testToJsonRoundTrips(): void
    {
        $program = Parser::parseSource('a ?? b;');
        $decoded = json_decode(EstreeSerializer::toJson($program), true);
        $this->assertSame(EstreeSerializer::toArray($program), $decoded);
    }

    public function testSummarizeEmitsOneLinePerNode(): void
    {
        $summary = EstreeSerializer::summarize(Parser::parseSource('let x = a + 1;'));
        $this->assertSame(implode("\n", [
            'Program',
            '  VariableDeclaration kind=let',
            '    VariableDeclarator',
            '      Identifier name=x',
            '      BinaryExpression op=+',
            '        Identifier name=a',
            '        Literal value=1',
        ]), $summary);
    }

    public function testSummarizeStringifiesStringValues(): void
    {
        $summary = EstreeSerializer::summarize(Parser::parseSource('"a/b";'));
        $this->assertStringContainsString('Literal value="a/b"', $summary);
    }
}
