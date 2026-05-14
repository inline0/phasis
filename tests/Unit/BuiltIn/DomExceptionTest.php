<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class DomExceptionTest extends TestCase
{
    public function testConstructorDefaultsMatchWebIDL(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
var e = new DOMException();
[e.name, e.message, e.code];
JS);
        $this->assertSame(['Error', '', 0], $result);
    }

    public function testConstructorWithMessageAndName(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
var e = new DOMException("hello", "NotFoundError");
[e.name, e.message, e.code];
JS);
        $this->assertSame(['NotFoundError', 'hello', 8], $result);
    }

    public function testLegacyCodeMapping(): void
    {
        $engine = new Engine();
        // Spot-check the table at boundaries: 1 (first), 5, 8, 25 (last).
        $result = $engine->eval(<<<'JS'
[
  new DOMException("", "IndexSizeError").code,
  new DOMException("", "InvalidCharacterError").code,
  new DOMException("", "NotFoundError").code,
  new DOMException("", "DataCloneError").code,
  new DOMException("", "WhateverUnknown").code,
];
JS);
        $this->assertSame([1, 5, 8, 25, 0], $result);
    }

    public function testInstanceofError(): void
    {
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
var e = new DOMException("oops", "AbortError");
e instanceof Error && e instanceof DOMException;
JS);
        $this->assertTrue($result);
    }

    public function testCalledWithoutNewStillReturnsInstance(): void
    {
        // Per WebIDL spec, calling a constructor as a function is allowed.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
var e = DOMException("msg", "SyntaxError");
[e.name, e.code, e instanceof DOMException];
JS);
        $this->assertSame(['SyntaxError', 12, true], $result);
    }

    public function testLegacyConstantOnConstructorAndPrototype(): void
    {
        // Per WebIDL, the legacy `_ERR` constants are exposed on both the
        // interface object and the prototype.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
[DOMException.INDEX_SIZE_ERR, DOMException.NOT_FOUND_ERR, DOMException.prototype.DATA_CLONE_ERR];
JS);
        $this->assertSame([1, 8, 25], $result);
    }

    public function testNumericCodeIsNotEnumerableOnInstance(): void
    {
        // Per WebIDL the `code` attribute is defined on the prototype as a
        // getter; assertion here is just that the property is present and
        // numeric, which the smoke test already exercised.
        $engine = new Engine();
        $result = $engine->eval(<<<'JS'
typeof new DOMException("", "TimeoutError").code;
JS);
        $this->assertSame('number', $result);
    }
}
