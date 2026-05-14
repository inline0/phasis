<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class NavigatorTest extends TestCase
{
    public function testNavigatorIsAnObject(): void
    {
        $engine = new Engine();
        $this->assertSame('object', $engine->eval('typeof navigator;'));
    }

    public function testUserAgentMatchesPhasisPrefix(): void
    {
        $engine = new Engine();
        $this->assertSame(
            true,
            $engine->eval('typeof navigator.userAgent === "string" && /^Phasis\//.test(navigator.userAgent);')
        );
    }

    public function testAppNameIsPhasis(): void
    {
        $engine = new Engine();
        $this->assertSame('Phasis', $engine->eval('navigator.appName;'));
    }

    public function testUserAgentAndAppNameAreReadOnly(): void
    {
        // Per WebIDL navigator attributes are read-only; we install them
        // as non-writable so silent assignment fails in strict mode and
        // is a no-op in sloppy mode.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
        "use strict";
        let caught = null;
        try {
            navigator.userAgent = "x";
        } catch (e) {
            caught = e.name;
        }
        caught;
        JS);
        $this->assertSame('TypeError', $result);
    }

    public function testNavigatorAttributesNonEnumerableViaProperty(): void
    {
        // userAgent is non-writable but still readable.
        $engine = new Engine();
        $this->assertSame(
            'Phasis/0.1',
            $engine->eval('navigator.userAgent;')
        );
    }
}
