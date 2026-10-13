<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/** Reflect.construct initializes class fields exactly as `new` does. */
final class ReflectConstructFieldsTest extends TestCase
{
    public function testFieldsOfBaseAndDerivedClasses(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
            class Base { x = 1; #p = 2; p() { return this.#p; } }
            class Implicit extends Base { y = 3; }
            class Explicit extends Base { y = 4; constructor() { super(); } }
            function Plain() {}
            class FromFunction extends Plain { z = 5; }
            const b = Reflect.construct(Base, []);
            console.log(b.x, b.p(), Reflect.construct(Implicit, []).y, Reflect.construct(Explicit, []).y, Reflect.construct(FromFunction, []).z);
            JS);
        $this->assertSame('1 2 3 4 5', $engine->getConsoleOutput());
    }
}
