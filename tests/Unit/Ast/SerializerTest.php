<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Ast;

use Phasis\Ast\Serializer;
use Phasis\Parser\Parser;
use PHPUnit\Framework\TestCase;

class SerializerTest extends TestCase
{
    public function testToArrayUsesTypedNames(): void
    {
        $ast = Serializer::toArray(Parser::parseSource('const f = () => tag`a`;'));
        $this->assertSame('Program', $ast['type']);
        $declaration = $ast['body'][0];
        $this->assertSame('VariableDeclaration', $declaration['type']);
        $this->assertSame('const', $declaration['kind']);
        $arrow = $declaration['declarations'][0]['init'];
        $this->assertSame('ArrowFunction', $arrow['type']);
        $this->assertSame('TaggedTemplate', $arrow['body']['type']);
    }

    public function testToArraySkipsInternalFields(): void
    {
        $ast = Serializer::toArray(Parser::parseSource('let x = 1;'));
        $identifier = $ast['body'][0]['declarations'][0]['id'];
        $literal = $ast['body'][0]['declarations'][0]['init'];
        $this->assertSame(['name', 'type'], array_keys($identifier));
        $this->assertSame(['raw', 'type', 'value'], array_keys($literal));
        $this->assertSame(1, $literal['value']);
    }

    public function testToArrayKeysAreSorted(): void
    {
        $ast = Serializer::toArray(Parser::parseSource('for (;;) break;'));
        $forStatement = $ast['body'][0];
        $keys = array_keys($forStatement);
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys);
    }

    public function testToJsonRoundTrips(): void
    {
        $program = Parser::parseSource('f(1, "two");');
        $decoded = json_decode(Serializer::toJson($program), true);
        $this->assertSame(Serializer::toArray($program), $decoded);
        $pretty = Serializer::toJson($program, pretty: true);
        $this->assertStringContainsString("\n", $pretty);
        $this->assertSame($decoded, json_decode($pretty, true));
    }
}
