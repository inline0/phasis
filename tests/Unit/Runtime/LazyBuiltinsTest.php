<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/**
 * Lazy default-mode semantics for the built-in surface.
 *
 * Confirms: placeholders show up as own globals before first read,
 * first read realizes the module, transitive deps cascade, the
 * eager-mode opt-out behaves identically to the legacy install path,
 * and embedder pre-emption (setGlobal) wins over the placeholder.
 */
class LazyBuiltinsTest extends TestCase
{
    public function testLazyIsDefault(): void
    {
        $engine = new Engine();
        $this->assertFalse($engine->isEager());
    }

    public function testEagerOptInRestoresEagerInstall(): void
    {
        $engine = new Engine(eager: true);
        $this->assertTrue($engine->isEager());
    }

    public function testLazyNamePresentBeforeRead(): void
    {
        // `in` and Reflect.ownKeys must see lazy names without forcing
        // the install — this is how feature-detection code works.
        $engine = new Engine();
        $this->assertSame(
            'true,true,true,true,true',
            $engine->eval(
                '[\'Map\', \'Set\', \'fetch\', \'Temporal\', \'URL\']'
                . '.map(n => n in globalThis).join(",")'
            )
        );
    }

    public function testReadRealizesAndReturnsConstructor(): void
    {
        $engine = new Engine();
        $this->assertSame('function', $engine->eval('typeof Map'));
        $this->assertSame(
            'value=1',
            $engine->eval(
                'const m = new Map(); m.set("k", 1); "value=" + m.get("k");'
            )
        );
    }

    public function testTransitiveDepsCascadeOnFirstRead(): void
    {
        // Touching `fetch` should realize its declared deps —
        // Request, Response, URL, Headers, AbortController, Streams,
        // Blob — bringing along the ArrayBuffer + EventTarget deps
        // those modules declare in turn.
        $engine = new Engine();
        $r = $engine->eval('typeof fetch');
        $this->assertSame('function', $r);
        $deps = $engine->eval(
            '[typeof Request, typeof Response, typeof URL, typeof Headers,'
            . ' typeof AbortController, typeof ReadableStream, typeof Blob,'
            . ' typeof ArrayBuffer, typeof EventTarget].join(",")'
        );
        $this->assertSame(
            'function,function,function,function,function,function,function,function,function',
            $deps,
        );
    }

    public function testRealizationReplacesAccessorWithDataDescriptor(): void
    {
        $engine = new Engine();
        // Before read: descriptor is an accessor.
        $beforeKind = $engine->eval(
            'const d1 = Object.getOwnPropertyDescriptor(globalThis, \'Map\');'
            . 'd1.get ? "accessor" : "data"'
        );
        $this->assertSame('accessor', $beforeKind);

        // Trigger realize.
        $engine->eval('void Map;');

        $afterKind = $engine->eval(
            'const d2 = Object.getOwnPropertyDescriptor(globalThis, \'Map\');'
            . 'd2.value !== undefined ? "data" : "still-accessor"'
        );
        $this->assertSame('data', $afterKind);
    }

    public function testSetGlobalPreemptsLazyAccessor(): void
    {
        $engine = new Engine();
        $engine->setGlobal('fetch', 'mocked');
        $kind = $engine->eval('typeof fetch');
        $this->assertSame('string', $kind);
        $this->assertSame('mocked', $engine->eval('fetch'));
    }

    public function testEagerModeHasNoAccessorPlaceholders(): void
    {
        $engine = new Engine(eager: true);
        $kind = $engine->eval(
            'const d = Object.getOwnPropertyDescriptor(globalThis, \'Map\');'
            . 'd.value !== undefined ? "data" : "accessor"'
        );
        $this->assertSame('data', $kind);
    }

    public function testRepeatedReadsDoNotReinstall(): void
    {
        // Once the accessor has been replaced with a data descriptor,
        // subsequent reads MUST NOT re-enter the install factory —
        // re-realizing would reset prototype identity and break
        // existing instances. Indirect check: instance identity holds.
        $engine = new Engine();
        $sameProto = $engine->eval(
            'const m1 = new Map(); const m2 = new Map();'
            . 'Object.getPrototypeOf(m1) === Object.getPrototypeOf(m2)'
        );
        $this->assertTrue($sameProto);
    }
}
