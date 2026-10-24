<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Object\PropertyDescriptor;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeneratedDataBindingTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function entries(): iterable
    {
        yield 'cold' => [false];
        yield 'cached' => [true];
    }

    #[DataProvider('entries')]
    public function testGlobalReadsDoNotInvokeHostDescriptorMetadata(bool $warm): void
    {
        $engine = new Engine();
        $engine->eval('var captured = 7; function readCaptured() { return captured; }');
        if ($warm) {
            self::assertSame(7, $engine->eval('readCaptured();'));
        }

        $descriptor = new class (value: JsNumber::of(19), writable: false) extends PropertyDescriptor {
            public int $metadataReads = 0;

            public function isDataDescriptor(): bool
            {
                ++$this->metadataReads;
                return parent::isDataDescriptor();
            }
        };
        $global = $engine->getGlobalEnv()->getLinkedObject();
        self::assertNotNull($global);
        $global->properties->set('captured', $descriptor);

        self::assertSame(19, $engine->eval('readCaptured();'));
        self::assertSame(19, $engine->eval('readCaptured();'));
        self::assertSame(0, $descriptor->metadataReads);
        $function = $engine->getGlobalEnv()->get('readCaptured');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertInstanceOf(\Closure::class, $function->phpCompiled);
    }
}
