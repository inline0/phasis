<?php

declare(strict_types=1);

namespace PhpJs\Tests\Unit\Runtime;

use PhpJs\Engine;
use PHPUnit\Framework\TestCase;

class StringReplaceTest extends TestCase
{
    public function testRegexpReplaceTreatsDollarZeroAsLiteral(): void
    {
        $engine = new Engine();

        $result = $engine->eval('"foo-bar".replace(/bar/, "|$0|");');

        $this->assertSame('foo-|$0|', $result);
    }

    public function testRegexpReplaceSupportsJsDollarSubstitutions(): void
    {
        $engine = new Engine();

        $result = $engine->eval('"uid=31".replace(/(uid=)(\\d+)/, "$11" + "15");');

        $this->assertSame('uid=115', $result);
    }

    public function testRegexpReplaceTurnsExplosiveGrowthIntoCatchableError(): void
    {
        $engine = new Engine();

        $result = $engine->eval(<<<'JS'
function puff(x, n) {
    while (x.length < n) {
        x += x;
    }
    return x.substring(0, n);
}

var x = puff("1", 1 << 16);
var rep = puff("$1", 1 << 13);
var outcome = "pass";
try {
    x.replace(/(.+)/g, rep);
} catch (e) {
    outcome = e.name;
}
outcome;
JS);

        $this->assertSame('RangeError', $result);
    }
}
