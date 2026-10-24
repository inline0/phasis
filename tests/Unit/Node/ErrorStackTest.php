<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class ErrorStackTest extends TestCase
{
    public function testStructuredStackIsLazyCachedAndExcludesTheConstructor(): void
    {
        $engine = new Engine(node: true);
        try {
            $engine->eval(<<<'JS'
let preparations = 0;
Error.stackTraceLimit = 1;
Error.prepareStackTrace = (error, frames) => {
  preparations++;
  return { message: error.message, frames };
};
function createError() {
  const error = { message: 'example' };
  Error.captureStackTrace(error, createError);
  return error;
}
function caller() {
  const error = createError();
  const before = preparations;
  const stack = error.stack;
  return [before, preparations, stack === error.stack, stack.message, stack.frames.length,
    stack.frames[0].getFunctionName(), stack.frames[0].getFunction() === caller,
    Object.keys(error).includes('stack')];
}
globalThis.stackResult = caller();
JS);
            self::assertSame([0, 1, true, 'example', 1, 'caller', true, false], $engine->eval('stackResult'));
            self::assertSame('replacement', $engine->eval(<<<'JS'
const overwritten = {};
Error.captureStackTrace(overwritten);
overwritten.stack = 'replacement';
overwritten.stack;
JS));
        } finally {
            $engine->close();
        }
    }
}
