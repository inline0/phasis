<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use Phasis\Value\JsString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeAsciiStringTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function executionModes(): iterable
    {
        yield 'VM' => [false];
        yield 'AST' => [true];
    }

    #[DataProvider('executionModes')]
    public function testAsciiAndUtf16BoundariesAndCoercionsMatchNode(bool $budgeted): void
    {
        $engine = new Engine();
        if ($budgeted) {
            $engine->setExecutionBudget(10);
        }
        $engine->execFile(__DIR__ . '/fixtures/native-ascii-strings.cjs');
        $observations = $engine->eval('JSON.stringify(nativeAsciiObservations)');
        self::assertIsString($observations);
        self::assertJsonStringEqualsJsonFile(__DIR__ . '/fixtures/native-ascii-strings.json', $observations);
        if (!$budgeted) {
            foreach (['nativeAsciiIndex', 'nativeAsciiSlice'] as $name) {
                $function = $engine->getGlobalEnv()->get($name);
                self::assertInstanceOf(JsFunction::class, $function);
                self::assertNotNull($function->compiled);
            }
        }
    }

    public function testHostStringsKeepTheirUtf16HooksBeforeArgumentCoercions(): void
    {
        $engine = new Engine();
        $events = new \ArrayObject();
        $make = static fn (string $value, string $name): JsString => new class ($value, $name, $events) extends JsString {
            public function __construct(string $value, private readonly string $name, private readonly \ArrayObject $events)
            {
                parent::__construct($value);
            }

            public function isAscii(): bool
            {
                throw new \LogicException('Host string ASCII hooks must stay on the existing fallback.');
            }

            public function utf16LE(): string
            {
                $this->events->append($this->name . ':utf16');
                return parent::utf16LE();
            }
        };
        $engine->getGlobalEnv()->defineVar('hostReceiver', $make('abc', 'receiver'));
        $engine->getGlobalEnv()->defineVar('hostSearch', $make('b', 'search'));
        $engine->setGlobal('recordCoercion', static function (string $name) use ($events): void {
            $events->append($name);
        });
        self::assertSame(1, $engine->eval(<<<'JS'
String.prototype.indexOf.call(hostReceiver, hostSearch, {valueOf() {recordCoercion('position'); return 1;}});
JS));
        self::assertSame(['receiver:utf16', 'search:utf16', 'position'], $events->getArrayCopy());
        $events->exchangeArray([]);
        self::assertSame(1, $engine->eval(<<<'JS'
'abc'.indexOf(hostSearch, {valueOf() {recordCoercion('position'); return 1;}});
JS));
        self::assertSame(['search:utf16', 'position'], $events->getArrayCopy());
        $events->exchangeArray([]);
        self::assertSame('b', $engine->eval(<<<'JS'
String.prototype.slice.call(hostReceiver,
    {valueOf() {recordCoercion('start'); return 1;}},
    {valueOf() {recordCoercion('end'); return 2;}});
JS));
        self::assertSame(['receiver:utf16', 'start', 'end'], $events->getArrayCopy());
    }
}
