<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class ObjectPrototypeTest extends TestCase
{
    public function testObjectPrototypeRemainsImmutable(): void
    {
        $engine = new Engine();

        $result = $engine->eval(<<<'JS'
var reflectResult = Reflect.setPrototypeOf(Object.prototype, { __proto__: null });
var objectThrows = false;
try {
    Object.setPrototypeOf(Object.prototype, {});
} catch (e) {
    objectThrows = e instanceof TypeError;
}
[reflectResult, objectThrows, Object.getPrototypeOf(Object.prototype) === null];
JS);

        $this->assertIsArray($result);
        $this->assertSame([false, true, true], $result);
    }

    public function testObjectSetPrototypeOfRejectsCycles(): void
    {
        $engine = new Engine();

        $result = $engine->eval(<<<'JS'
var a = {};
var b = {};
Object.setPrototypeOf(a, b);
var reflectResult = Reflect.setPrototypeOf(b, a);
var objectThrows = false;
try {
    Object.setPrototypeOf(b, a);
} catch (e) {
    objectThrows = e instanceof TypeError;
}
[reflectResult, objectThrows, Object.getPrototypeOf(b) === Object.prototype];
JS);

        $this->assertIsArray($result);
        $this->assertSame([false, true, true], $result);
    }
}
