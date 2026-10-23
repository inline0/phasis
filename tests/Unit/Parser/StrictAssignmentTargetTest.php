<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Parser;

use Phasis\Ast\Program;
use Phasis\Exceptions\SyntaxError;
use Phasis\Parser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StrictAssignmentTargetTest extends TestCase
{
    #[DataProvider('strictAssignments')]
    public function testRestrictedTargetsFailBeforeEvaluation(string $source): void
    {
        $this->expectException(SyntaxError::class);
        Parser::parseSource($source);
    }

    /** @return iterable<string, array{string}> */
    public static function strictAssignments(): iterable
    {
        yield 'inherited strict function' => ['"use strict"; function f() { arguments = 7; }'];
        yield 'function strict directive' => ['function f() { "use strict"; eval = 7; }'];
        yield 'directive amid other directives' => ['"a"; "use strict"; "c"; eval = 42;'];
        yield 'compound assignment' => ['"use strict"; arguments += 1;'];
        yield 'logical assignment' => ['"use strict"; eval ||= 1;'];
        yield 'prefix update' => ['"use strict"; ++arguments;'];
        yield 'postfix update' => ['"use strict"; eval++;'];
        yield 'parenthesized assignment' => ['"use strict"; (arguments) = 1;'];
        yield 'logical and excludes Annex B call targets' => ['get() &&= 1;'];
        yield 'logical or excludes Annex B call targets' => ['(get()) ||= 1;'];
        yield 'nullish assignment excludes Annex B call targets' => ['get() ??= 1;'];
    }

    #[DataProvider('validAssignments')]
    public function testAllowsSloppyTargetsAndStrictPropertyNames(string $source): void
    {
        self::assertInstanceOf(Program::class, Parser::parseSource($source));
    }

    /** @return iterable<string, array{string}> */
    public static function validAssignments(): iterable
    {
        yield 'sloppy restricted names' => ['eval = 1; arguments += 2;'];
        yield 'sloppy updates' => ['++arguments; eval++;'];
        yield 'escaped directive is not strict' => ['"use\\x20strict"; eval = 1;'];
        yield 'strict member assignments' => ['"use strict"; object.eval = 1; object.arguments++;'];
        yield 'strict computed property' => ['"use strict"; object[eval] = arguments;'];
        yield 'Annex B ordinary call assignments' => ['get() = 1; (get()) += 1;'];
        yield 'logical assignment to a call result member' => ['get().value &&= 1;'];
    }
}
