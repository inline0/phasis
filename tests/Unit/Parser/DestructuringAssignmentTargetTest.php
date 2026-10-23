<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Parser;

use Phasis\Ast\Program;
use Phasis\Exceptions\SyntaxError;
use Phasis\Parser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DestructuringAssignmentTargetTest extends TestCase
{
    #[DataProvider('invalidTargets')]
    public function testCallsAndParenthesizedPatternsFailDuringParsing(string $source): void
    {
        $this->expectException(SyntaxError::class);
        Parser::parseSource($source);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTargets(): iterable
    {
        yield 'array call' => ['[get()] = values;'];
        yield 'array default call' => ['[(get()) = 1] = values;'];
        yield 'object call' => ['({value: get()} = object);'];
        yield 'object default call' => ['({value: (get()) = 1} = object);'];
        yield 'array rest call' => ['[...get()] = values;'];
        yield 'object rest call' => ['({...get()} = object);'];
        yield 'for-of pattern call' => ['for ([get()] of values) {}'];
        yield 'for-in pattern call' => ['for ({value: get()} in object) {}'];
        yield 'parenthesized nested object' => ['[({value})] = values;'];
        yield 'parenthesized default' => ['[(value = 1)] = values;'];
    }

    #[DataProvider('validTargets')]
    public function testPreservesSimpleParenthesizedTargetsAndStandaloneCallTargets(string $source): void
    {
        self::assertInstanceOf(Program::class, Parser::parseSource($source));
    }

    /** @return iterable<string, array{string}> */
    public static function validTargets(): iterable
    {
        yield 'parenthesized identifier' => ['[(value)] = values;'];
        yield 'parenthesized identifier default' => ['[(value) = get()] = values;'];
        yield 'object property default' => ['({value: (target.value) = get()} = object);'];
        yield 'member of call result' => ['[(get().value) = 1] = values;'];
        yield 'parenthesized rest property target' => ['[...(target.value)] = values;'];
        yield 'super property default' => ['({method() {[(super.value) = 1] = values;}});'];
        yield 'standalone call assignment' => ['get() = value;'];
        yield 'standalone for-in call' => ['for (get() in object) {}'];
        yield 'standalone for-of call' => ['for ((get()) of values) {}'];
    }
}
