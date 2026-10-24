<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Object\PropertyDescriptor;
use Phasis\Object\PropertyMap;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StringIntrinsicGuardTest extends TestCase
{
    /** @return iterable<string, array{bool, bool}> */
    public static function entries(): iterable
    {
        yield 'cold descriptor' => [false, false];
        yield 'cached descriptor' => [true, false];
        yield 'cold property map' => [false, true];
        yield 'cached property map' => [true, true];
    }

    #[DataProvider('entries')]
    public function testUnusedStringMethodsDoNotReadHostMetadata(bool $warm, bool $mapOverride): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
function prime(text) { return text.charCodeAt(0); }
function scan(text, offset) {
    while (offset < text.length) {
        const code = text.charCodeAt(offset);
        if (code === 120) break;
        offset++;
    }
    return offset;
}
prime('x');
JS);
        if ($warm) {
            self::assertSame(2, $engine->eval("scan(' ax', 0)"));
        }
        $prototype = $engine->getGlobalEnv()->get('String')->get('prototype');
        self::assertInstanceOf(JsObject::class, $prototype);
        $originalMap = $prototype->properties;
        $originalDescriptor = $originalMap->descriptors['charCodeAt'];
        $descriptor = new class (
            value: $originalDescriptor->value,
            writable: true,
            enumerable: false,
            configurable: true,
        ) extends PropertyDescriptor {
            public int $reads = 0;

            public function isDataDescriptor(): bool
            {
                ++$this->reads;
                return parent::isDataDescriptor();
            }
        };
        $map = new class ($originalMap) extends PropertyMap {
            public int $reads = 0;

            public function __construct(private readonly PropertyMap $original)
            {
                $this->order = $original->order;
                $this->version = $original->version;
                $this->mutationVersion = $original->mutationVersion;
                $this->isUsedAsProto = $original->isUsedAsProto;
                unset($this->dataSlots, $this->descriptors);
            }

            public function __isset(string $name): bool
            {
                ++$this->reads;
                return $name === 'dataSlots' || $name === 'descriptors';
            }

            public function __get(string $name): mixed
            {
                ++$this->reads;
                return match ($name) {
                    'dataSlots' => $this->original->dataSlots,
                    'descriptors' => $this->original->descriptors,
                    default => throw new \LogicException('Unexpected metadata read: ' . $name),
                };
            }
        };
        $previous = Engine::getCurrentInterpreter();
        Engine::setCurrentInterpreter($engine->getInterpreter());
        try {
            self::assertTrue($engine->getInterpreter()->hasCachedStringIntrinsic('charCodeAt', 'string.charCodeAt'));
            if ($mapOverride) {
                $prototype->properties = $map;
            } else {
                $originalMap->descriptors['charCodeAt'] = $descriptor;
            }
            self::assertSame(0, $engine->eval("scan('', 0)"));
            self::assertSame(0, $descriptor->reads);
            self::assertSame(0, $map->reads);
            self::assertSame(2, $engine->eval("scan(' ax', 0)"));
            self::assertSame(0, $descriptor->reads);
            if ($mapOverride) {
                self::assertGreaterThan(0, $map->reads);
            }
            $function = $engine->getGlobalEnv()->get('scan');
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertInstanceOf(\Closure::class, $function->phpCompiled);
        } finally {
            $prototype->properties = $originalMap;
            $originalMap->descriptors['charCodeAt'] = $originalDescriptor;
            Engine::setCurrentInterpreter($previous);
        }
    }
}
