<?php

declare(strict_types=1);

namespace PhpJs\Tests\Unit\Runtime;

use PhpJs\Engine;
use PhpJs\Exceptions\SyntaxError;
use PHPUnit\Framework\TestCase;

class EvalTest extends TestCase
{
    public function testEvalRejectsOversizedGeneratedSource(): void
    {
        $engine = new Engine();
        // Cap is 64 MB; produce 65 MB to trip it.
        $oversizedSource = str_repeat('{}', 33 * 1024 * 1024);

        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('Source too large for eval');

        $engine->call('eval', $oversizedSource);
    }

    public function testDirectEvalTurnsOversizedSourceIntoCatchableSyntaxError(): void
    {
        $engine = new Engine();

        $result = $engine->eval(<<<'JS'
var s = "{}";
for (var i = 0; i < 26; i++) {
    s += s;
}
var outcome = "pass";
try {
    eval(s);
} catch (e) {
    outcome = e.name;
}
outcome;
JS);

        $this->assertSame('SyntaxError', $result);
    }
}
