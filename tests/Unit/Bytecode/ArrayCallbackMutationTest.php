<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class ArrayCallbackMutationTest extends TestCase
{
    public function testMapPreservesHolesAndReadsAccessorsAddedByEarlierCallbacks(): void
    {
        $this->assertBothScriptModes('1,30,4|4|false|60|8|false', <<<'JS'
var source = [1, 2, 3, 4];
var seen = [];
var mapped = source.map(function (value, index) {
    seen.push(value);
    if (index === 0) {
        delete source[1];
        Object.defineProperty(source, '2', { get: function () { return 30; } });
        source.push(5);
    }
    return value * 2;
});
[seen.join(), mapped.length, 1 in mapped, mapped[2], mapped[3], 4 in mapped].join('|');
JS);
    }

    public function testFilterReadsInheritedIndicesAfterTheReceiverShrinks(): void
    {
        $this->assertBothScriptModes('1,20,30', <<<'JS'
var source = [1, 2, 3, 4];
var filtered = source.filter(function (value, index) {
    if (index === 0) {
        source[1] = 20;
        source.length = 2;
        var prototype = Object.create(Array.prototype);
        prototype[2] = 30;
        Object.setPrototypeOf(source, prototype);
    }
    return true;
});
filtered.join();
JS);
    }

    public function testForEachUsesTheInitialLengthAndVisitsNewIndicesBelowIt(): void
    {
        $this->assertBothScriptModes('0:1|1:2|2:30', <<<'JS'
var source = [1, 2, 3, 4];
var seen = [];
source.forEach(function (value, index) {
    seen.push(index + ':' + value);
    if (index === 0) {
        source.length = 2;
        source[2] = 30;
        source[5] = 60;
    }
});
seen.join('|');
JS);
    }

    public function testReduceDoesNotRepeatCallbacksWhenItEncountersAHole(): void
    {
        $this->assertBothScriptModes('43|2|1', <<<'JS'
var source = [1, 2, , 4];
var calls = 0;
var reads = 0;
var sum = source.reduce(function (accumulator, value, index) {
    calls++;
    if (index === 1) {
        Object.defineProperty(source, '3', {
            get: function () { reads++; return 40; }
        });
    }
    return accumulator + value;
});
[sum, calls, reads].join('|');
JS);
    }

    private function assertBothScriptModes(string $expected, string $source): void
    {
        foreach (['', '"use strict";'] as $directive) {
            $engine = new Engine();
            self::assertSame($expected, $engine->eval($directive . "\n" . $source));
        }
    }
}
