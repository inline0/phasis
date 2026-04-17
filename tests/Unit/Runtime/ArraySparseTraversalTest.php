<?php

declare(strict_types=1);

namespace PhpJs\Tests\Unit\Runtime;

use PhpJs\Engine;
use PHPUnit\Framework\TestCase;

class ArraySparseTraversalTest extends TestCase
{
    public function testReduceRightHandlesNearMaxSafeIntegerArrayLike(): void
    {
        $engine = new Engine();

        $result = $engine->eval(<<<'JS'
var arrayLike = {
  length: Number.MAX_SAFE_INTEGER,
};

arrayLike[Number.MAX_SAFE_INTEGER - 1] = 1;
arrayLike[Number.MAX_SAFE_INTEGER - 3] = 3;

try {
  Array.prototype.reduceRight.call(arrayLike, function(acc, el, index) {
    acc.push([el, index]);
    if (el === 3) {
      throw acc;
    }
    return acc;
  }, []);
} catch (acc) {
  [acc.length, acc[0][1], acc[1][1]];
}
JS);

        $this->assertIsArray($result);
        $this->assertSame([2, 9007199254740990, 9007199254740988], $result);
    }

    public function testLastIndexOfHandlesNearMaxSafeIntegerArrayLike(): void
    {
        $engine = new Engine();

        $result = $engine->eval(<<<'JS'
var el = {};
var arrayLike = {
  length: Number.MAX_SAFE_INTEGER,
};
arrayLike[Number.MAX_SAFE_INTEGER - 3] = el;
Array.prototype.lastIndexOf.call(arrayLike, el, Number.MAX_SAFE_INTEGER - 1);
JS);

        $this->assertSame(9007199254740988, $result);
    }
}
