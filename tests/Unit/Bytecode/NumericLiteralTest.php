<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Bytecode\JsToPhp;
use Phasis\Bytecode\PhpNumericLiteral;
use Phasis\Engine;
use Phasis\Parser\Parser;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class NumericLiteralTest extends TestCase
{
    /** @return list<string> */
    public static function expressions(): array
    {
        return [
            '0', '-0', 'NaN', 'Infinity', '-Infinity',
            '0.30000000000000004', '1.0000000000000002', '0.9999999999999999',
            '5e-324', '-5e-324', '1.7976931348623157e308', '-1.7976931348623157e308',
            '9007199254740991', '9007199254740992', '9007199254740994',
            '9223372036854774784', '9223372036854775808', '9223372036854777856',
            '-9223372036854774784', '-9223372036854775808', '-9223372036854777856',
        ];
    }

    public function testCompiledNumbersMatchTheirOriginalLiteralBits(): void
    {
        foreach (self::expressions() as $expression) {
            $engine = new Engine();
            self::assertTrue($engine->eval(
                'function literal() { return ' . $expression . '; } Object.is(literal(), (' . $expression . '));'
            ), $expression);
            $function = $engine->getGlobalEnv()->get('literal');
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->phpCompiled, $expression . ' did not use the PHP backend');
        }
    }

    public function testFormattingIgnoresPhpDisplayPrecision(): void
    {
        $previous = ini_get('precision');
        ini_set('precision', '3');
        try {
            $engine = new Engine();
            self::assertTrue($engine->eval(<<<'JS'
function literal() { return 0.30000000000000004; }
Object.is(literal(), 0.30000000000000004);
JS));
            $function = $engine->getGlobalEnv()->get('literal');
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->phpCompiled);
        } finally {
            ini_set('precision', $previous);
        }
    }

    public function testRoundTripExpressionsPreserveFiniteExtremesAndSignedZero(): void
    {
        foreach (
            [0.0, -0.0, 0.30000000000000004, PHP_FLOAT_MIN, PHP_FLOAT_MAX,
            5.0e-324, 9223372036854775808.0, -9223372036854775808.0, INF, -INF, NAN] as $value
        ) {
            $literal = PhpNumericLiteral::render($value);
            $actual = eval('return ' . $literal . ';');
            self::assertIsFloat($actual, $literal);
            self::assertSame(pack('E', $value), pack('E', $actual), $literal);
        }
    }

    public function testCompilationDoesNotRewriteOriginalAstSource(): void
    {
        $engine = new Engine();
        $program = (new Parser('function value() { return 0.30000000000000004; }'))->parse();
        $declaration = $program->body[0];
        self::assertInstanceOf(\Phasis\Ast\Declaration\FunctionDeclaration::class, $declaration);
        $return = $declaration->body->body[0];
        self::assertInstanceOf(\Phasis\Ast\Statement\ReturnStatement::class, $return);
        $literal = $return->argument;
        self::assertInstanceOf(\Phasis\Ast\Expression\Literal::class, $literal);
        $before = [$literal->raw, pack('E', $literal->value), $literal->cached];
        $function = new JsFunction('value', [], $declaration->body, $engine->getGlobalEnv());
        self::assertNotNull(JsToPhp::compile($function));
        self::assertSame($before, [$literal->raw, pack('E', $literal->value), $literal->cached]);
    }

    public function testLocaleDecimalSeparatorsDoNotEnterGeneratedPhp(): void
    {
        $previous = setlocale(LC_NUMERIC, '0');
        try {
            foreach (['C', 'de_DE.UTF-8', 'fr_FR.UTF-8', 'de_DE', 'fr_FR'] as $locale) {
                if (setlocale(LC_NUMERIC, $locale) === false) {
                    continue;
                }
                $value = 0.30000000000000004;
                $literal = PhpNumericLiteral::render($value);
                self::assertStringNotContainsString(',', $literal);
                self::assertSame(pack('E', $value), pack('E', eval('return ' . $literal . ';')));
            }
        } finally {
            setlocale(LC_NUMERIC, $previous);
        }
    }
}
