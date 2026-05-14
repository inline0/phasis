<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use Phasis\Exceptions\TypeError;
use PHPUnit\Framework\TestCase;

class ArraySpliceTest extends TestCase
{
    public function testArrayLikeSpliceRejectsLengthsAboveMaxSafeInteger(): void
    {
        $engine = new Engine();

        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('Array length exceeds the supported limit');

        $engine->eval(<<<'JS'
var arrayLike = {};
arrayLike.length = 2 ** 53 - 1;
Array.prototype.splice.call(arrayLike, 0, 0, null);
JS);
    }

    public function testArrayLikeSpliceHandlesNearMaxSafeIntegerDeletes(): void
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

var removed = Array.prototype.splice.call(arrayLike, 9007199254740989, 2 ** 53 + 4);
[
  removed[0],
  removed[1],
  arrayLike.length,
  "9007199254740989" in arrayLike,
  "9007199254740990" in arrayLike
];
JS);

        $this->assertIsArray($result);
        $this->assertSame(
            ['9007199254740989', '9007199254740990', 9007199254740989, false, false],
            $result,
        );
    }
}
