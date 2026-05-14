<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class NumberPrototypeTest extends TestCase
{
    public function testToExponentialSupportsHundredFractionDigits(): void
    {
        $engine = new Engine();

        $result = $engine->eval('(3).toExponential(100);');

        $this->assertSame(
            '3.0000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000e+0',
            $result,
        );
    }
}
