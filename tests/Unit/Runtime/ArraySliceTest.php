<?php

declare(strict_types=1);

namespace PhpJs\Tests\Unit\Runtime;

use PhpJs\Engine;
use PhpJs\Exceptions\RangeError;
use PHPUnit\Framework\TestCase;

class ArraySliceTest extends TestCase
{
    public function testArrayLikeSliceRejectsResultLengthsAboveArrayLimit(): void
    {
        $engine = new Engine();

        $this->expectException(RangeError::class);
        $this->expectExceptionMessage('Invalid array length');

        $engine->eval(<<<'JS'
var obj = {};
obj.slice = Array.prototype.slice;
obj[0] = "x";
obj[4294967295] = "y";
obj.length = 4294967296;
obj.slice(0, 4294967296);
JS);
    }

    public function testArrayLikeSliceSupportsNearMaxSafeIntegerLookups(): void
    {
        $engine = new Engine();

        $result = $engine->eval(<<<'JS'
var arrayLike = {
  "9007199254740988": "9007199254740988",
  "9007199254740989": "9007199254740989",
  "9007199254740990": "9007199254740990",
  "9007199254740991": "9007199254740991",
  length: 2 ** 53 + 2,
};
Array.prototype.slice.call(arrayLike, 9007199254740989);
JS);

        $this->assertIsArray($result);
        $this->assertSame(['9007199254740989', '9007199254740990'], $result);
    }
}
