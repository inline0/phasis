<?php

declare(strict_types=1);

namespace PhpJs\Tests\Unit\Runtime;

use PhpJs\Engine;
use PHPUnit\Framework\TestCase;

class PromiseRaceTest extends TestCase
{
    public function testPromiseRaceClosesIteratorWhenResolveThrows(): void
    {
        $engine = new Engine();

        $result = $engine->eval(<<<'JS'
var returnCount = 0;
var err = new Error("boom");
var iterable = {};
iterable[Symbol.iterator] = function() {
  return {
    next: function() {
      return { value: null, done: false };
    },
    return: function() {
      returnCount += 1;
      return {};
    }
  };
};

Promise.resolve = function() {
  throw err;
};

Promise.race(iterable);
returnCount;
JS);

        $this->assertSame(1, $result);
    }
}
