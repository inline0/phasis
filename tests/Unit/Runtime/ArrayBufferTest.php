<?php

declare(strict_types=1);

namespace PhpJs\Tests\Unit\Runtime;

use PhpJs\Engine;
use PhpJs\Exceptions\RangeError;
use PHPUnit\Framework\TestCase;

class ArrayBufferTest extends TestCase
{
    public function testHugeAllocationThrowsRangeErrorInsteadOfFatal(): void
    {
        $engine = new Engine();

        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('Invalid array buffer length');

        $engine->eval('new ArrayBuffer(7 * 1125899906842624);');
    }

    public function testLengthUsesToIndexValidation(): void
    {
        $engine = new Engine();

        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('Invalid index');

        $engine->eval('new ArrayBuffer(Infinity);');
    }
}
