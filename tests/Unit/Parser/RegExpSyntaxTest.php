<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Parser;

use Phasis\Exceptions\SyntaxError;
use Phasis\Parser\Parser;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class RegExpSyntaxTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testRegularExpressionEarlyErrorsLoadNothingFromTheEngine(): void
    {
        (new Parser('/\p{Script=Greek}(?<a>x)|(?<a>y)/u; /(?i:a)/; /\p{RGI_Emoji}/v;'))->parse();

        foreach (['/(?<a>x)(?<a>y)/', '/\p{Lowercase=Yes}/u', '/\p{General_Category=Nope}/u', '/(?ii:a)/'] as $source) {
            try {
                (new Parser($source))->parse();
                self::fail("{$source} parsed");
            } catch (SyntaxError) {
            }
        }

        self::assertFalse(class_exists(\Phasis\Runtime\Interpreter::class, false));
        self::assertFalse(class_exists(\Phasis\Engine::class, false));
    }
}
