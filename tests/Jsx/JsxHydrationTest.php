<?php

declare(strict_types=1);

namespace Phasis\Tests\Jsx;

use Phasis\Engine;
use Phasis\Hydration\HydrationOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsxHydrationTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    protected function tearDown(): void
    {
        $this->engine->close();
    }

    private function options(string $rootId = 'root', string $prefix = 'test-'): HydrationOptions
    {
        return new HydrationOptions(rootId: $rootId, entryId: 'test-entry', buildId: 'build-123', identifierPrefix: $prefix);
    }

    public function testCapturesBeforeModuleEvaluationAndNeverReserializesRenderedProps(): void
    {
        $data = (object) ['value' => 10];
        $this->engine->setGlobal('changeInput', static function () use ($data): void {
            $data->value = 99;
        });
        $result = $this->engine->renderForHydration(__DIR__ . '/hydration/capture.jsx', ['data' => $data], options: $this->options());
        $this->assertSame(99, $data->value);
        $this->assertSame('<p>11</p>', $result->html);
        $state = json_decode($result->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['value' => 10], $state['props']['data']);
        $this->assertSame(1, $state['protocol']);
        $this->assertSame('19.3.1', $state['reactVersion']);
        $this->assertStringNotContainsString(__DIR__, $result->payload);
        $this->assertStringNotContainsString('toJSON', $result->payload);
    }

    public function testDataShapesAndCallableLookingArraysRemainData(): void
    {
        $data = (object) [
            'emptyObject' => new \stdClass(),
            'emptyArray' => [],
            'null' => null,
            'callable' => ['DateTime', 'createFromFormat'],
            '__proto__' => (object) ['polluted' => true],
            'constructor' => ['prototype' => ['safe' => true]],
            'toJSON' => 'ordinary data',
            'numericMap' => [2 => 'two', 0 => 'zero'],
        ];
        $result = $this->engine->renderForHydration(
            __DIR__ . '/hydration/Root.jsx',
            ['data' => $data],
            export: 'Shapes',
            options: $this->options(),
        );
        $state = json_decode($result->payload, flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $state->props->data->emptyObject);
        $this->assertSame([], $state->props->data->emptyArray);
        $this->assertSame(['DateTime', 'createFromFormat'], $state->props->data->callable);
        $this->assertTrue($state->props->data->__proto__->polluted);
        $this->assertStringContainsString('&quot;toJSON&quot;:&quot;ordinary data&quot;', $result->html);
        $this->assertFalse($this->engine->eval('Object.hasOwn(Object.prototype, "polluted")'));
    }

    public function testEmptyRootPropsAreObjectsAndClassDefaultsAndReservedPropsMatchCreateElement(): void
    {
        $result = $this->engine->renderForHydration(
            __DIR__ . '/hydration/Root.jsx',
            ['key' => 'root-key', '__self' => 'hidden', '__source' => 'hidden', 'ref' => null],
            export: 'Defaults',
            options: $this->options(),
        );
        $this->assertSame('<p>fallback<!-- -->:<!-- -->label</p>', $result->html);
        $empty = $this->engine->renderForHydration(__DIR__ . '/hydration/Root.jsx', export: 'Defaults', options: $this->options());
        $this->assertInstanceOf(\stdClass::class, json_decode($empty->payload)->props);
        $this->assertSame('<p>fallback<!-- -->:<!-- -->label</p>', $empty->html);
    }

    public function testFiniteDoublesSignedZeroAndSafeIntegersRoundTripDespiteHostPrecision(): void
    {
        $previous = ini_set('serialize_precision', '3');
        try {
            $result = $this->engine->renderForHydration(
                __DIR__ . '/hydration/Root.jsx',
                ['data' => ['zero' => -0.0, 'decimal' => 1.2345678901234567, 'max' => 9007199254740991, 'large' => 1.0e100]],
                export: 'Shapes',
                options: $this->options(),
            );
            $this->assertSame('3', ini_get('serialize_precision'));
            $state = json_decode($result->payload);
            $this->assertSame(-INF, fdiv(1.0, $state->props->data->zero));
            $this->assertSame(1.2345678901234567, $state->props->data->decimal);
            $this->assertSame(9007199254740991, $state->props->data->max);
            $this->assertStringContainsString('negative-zero', $result->html);
            $this->assertStringContainsString('1.2345678901234567', $result->html);
        } finally {
            ini_set('serialize_precision', $previous);
        }
    }

    public function testScriptSafetyAndAttributeEscapingAreSeparate(): void
    {
        $text = '</script><script>alert("&\'exploit")</script>' . "\u{2028}\u{2029}";
        $id = 'root"<&\'';
        $result = $this->engine->renderForHydration(
            __DIR__ . '/hydration/Root.jsx',
            ['data' => ['text' => $text]],
            export: 'Shapes',
            options: $this->options($id),
        );
        $this->assertStringNotContainsString('<', $result->payload);
        $this->assertStringNotContainsString('&quot;', $result->payload);
        $this->assertSame($text, json_decode($result->payload)->props->data->text);
        $this->assertStringContainsString('id="root&quot;&lt;&amp;&apos;"', $result->toHtml());
        $this->assertSame(1, substr_count($result->toHtml(), '<script '));
        $this->assertStringContainsString('</div><script type="application/json"', $result->toHtml());
    }

    public function testIdentifierPrefixReachesFizzAndRemainsSeparateFromTheRootId(): void
    {
        $first = $this->engine->renderForHydration(
            __DIR__ . '/hydration/Root.jsx',
            ['data' => ['label' => 'First', 'value' => 1]],
            options: $this->options('first-root', 'first-'),
        );
        $second = $this->engine->renderForHydration(
            __DIR__ . '/hydration/Root.jsx',
            ['data' => ['label' => 'Second', 'value' => 2]],
            options: $this->options('second-root', 'second-'),
        );
        $this->assertStringContainsString('_first-R_', $first->html);
        $this->assertStringContainsString('_second-R_', $second->html);
        $this->assertSame('first-', json_decode($first->payload)->identifierPrefix);
        $this->assertSame('first-root', json_decode($first->payload)->rootId);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidData(): iterable
    {
        yield 'callable' => [static fn (): string => 'secret'];
        yield 'arbitrary object' => [new \DateTimeImmutable()];
        yield 'serializer' => [new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new \LogicException('must never invoke serializer');
            }
        }];
        yield 'infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'NaN' => [NAN];
        yield 'unsafe integer' => [9007199254740992];
        yield 'negative unsafe integer' => [-9007199254740992];
        yield 'bad UTF-8' => ["\xFF"];
        $cycle = [];
        $cycle['cycle'] = &$cycle;
        yield 'array cycle' => [$cycle];
        $object = new \stdClass();
        $object->self = $object;
        yield 'object cycle' => [$object];
    }

    #[DataProvider('invalidData')]
    public function testInvalidDataIsRejectedBeforeModuleExecution(mixed $invalid): void
    {
        $this->engine->setGlobal('moduleLoaded', false);
        try {
            $this->engine->renderForHydration(__DIR__ . '/hydration/capture.jsx', ['data' => $invalid], options: $this->options());
            $this->fail('Invalid hydration data was accepted.');
        } catch (\InvalidArgumentException | \JsonException) {
            $this->assertFalse($this->engine->eval('moduleLoaded'));
        }
    }

    public function testResourcesAreRejectedBeforeModuleExecution(): void
    {
        $resource = fopen('php://memory', 'r+');
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->engine->renderForHydration(__DIR__ . '/hydration/capture.jsx', ['data' => $resource], options: $this->options());
        } finally {
            fclose($resource);
        }
    }

    public function testSharedObjectsAreAcceptedWithoutMutatingTheirPHPValues(): void
    {
        $data = (object) ['value' => 10];
        $result = $this->engine->renderForHydration(
            __DIR__ . '/hydration/capture.jsx',
            ['data' => $data, 'same' => $data],
            options: $this->options(),
        );
        $this->assertSame(10, $data->value);
        $this->assertSame('<p>11</p>', $result->html);
    }

    public function testMissingOptionsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->engine->renderForHydration(__DIR__ . '/hydration/Root.jsx');
    }

    public function testListsAreNotRootProps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->engine->renderForHydration(__DIR__ . '/hydration/Root.jsx', ['list'], options: $this->options());
    }

    public function testMissingExportsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->engine->renderForHydration(__DIR__ . '/hydration/Root.jsx', export: 'missing', options: $this->options());
    }

    public function testFullDocumentConvenienceTransportIsExplicitlyUnsupported(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('element roots');
        $this->engine->renderForHydration(__DIR__ . '/hydration/Root.jsx', export: 'Document', options: $this->options());
    }

    public function testLegacyRenderStillSupportsGeneralCallableInterop(): void
    {
        $html = $this->engine->renderSource('export default function App({ value }) { return <p>{value()}</p>; }', [
            'value' => static fn (): string => 'legacy callable',
        ]);
        $this->assertSame('<p>legacy callable</p>', $html);
    }

    public function testInvalidUtf8KeysAreRejectedBeforeModuleEvaluation(): void
    {
        $this->engine->setGlobal('moduleLoaded', false);
        try {
            $this->engine->renderForHydration(__DIR__ . '/hydration/capture.jsx', ["\xFF" => 'value'], options: $this->options());
            $this->fail('Invalid UTF-8 key was accepted.');
        } catch (\JsonException) {
            $this->assertFalse($this->engine->eval('moduleLoaded'));
        }
    }

    public function testRenderFailureRestoresHostPrecisionAndInterpreter(): void
    {
        $previous = ini_set('serialize_precision', '4');
        try {
            try {
                $this->engine->renderForHydration(__DIR__ . '/hydration/Root.jsx', export: 'Broken', options: $this->options());
                $this->fail('Broken component rendered successfully.');
            } catch (\Phasis\Exceptions\JsThrowable $error) {
                $this->assertStringContainsString('render failed deliberately', $error->getMessage());
            }
            $this->assertSame('4', ini_get('serialize_precision'));
            $this->assertSame(2, $this->engine->eval('1 + 1'));
        } finally {
            ini_set('serialize_precision', $previous);
        }
    }

    public function testInvalidMetadataFailsBeforeRendering(): void
    {
        foreach ([['', 'entry', 'build'], ['root', '', 'build'], ['root', 'entry', ''], ["root\n", 'entry', 'build']] as $values) {
            try {
                new HydrationOptions(...$values);
                $this->fail('Invalid hydration identity was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->engine->renderForHydration(__DIR__ . '/hydration/Root.jsx', export: '', options: $this->options());
    }

    public function testDataKeysCannotBecomeHiddenNativeSlots(): void
    {
        $result = $this->engine->renderForHydration(
            __DIR__ . '/hydration/Root.jsx',
            ['[[public]]' => true, 'data' => ['[[public]]' => 'visible', '[[PrimitiveValue]]' => 42]],
            export: 'Keys',
            options: $this->options(),
        );
        $this->assertStringContainsString('[[public]],data', $result->html);
        $this->assertStringContainsString('[[public]],[[PrimitiveValue]]', $result->html);
        $this->assertStringContainsString('&quot;[[public]]&quot;:&quot;visible&quot;', $result->html);
        $this->assertStringContainsString('&quot;[[PrimitiveValue]]&quot;:42', $result->html);
    }

    public function testLeadingNullBytesInDataKeysRemainJsonProperties(): void
    {
        $result = $this->engine->renderForHydration(
            __DIR__ . '/hydration/Root.jsx',
            ['data' => ["\0field" => 'visible']],
            export: 'Shapes',
            options: $this->options(),
        );
        $this->assertStringContainsString('\\u0000field', $result->html);
        $this->assertStringContainsString('visible', $result->html);
    }
}
