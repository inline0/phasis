<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Ast;

use Phasis\Ast\EstreeSerializer;
use Phasis\Parser\Parser;
use PHPUnit\Framework\TestCase;

class AcornOracleTest extends TestCase
{
    public function testSummaryMatchesAcornOracle(): void
    {
        $dir = dirname(__DIR__, 2) . '/Popular/acorn';
        $runner = file_get_contents($dir . '/runner.js');
        $this->assertIsString($runner);
        $matched = preg_match('/const source = `((?:\\\\.|[^`\\\\])*)`;/s', $runner, $m);
        $this->assertSame(1, $matched, 'runner.js no longer embeds the source template literal');
        $source = strtr($m[1], ['\\`' => '`', '\\$' => '$', '\\\\' => '\\']);

        $program = Parser::parseSource($source, 'module');
        $summary = EstreeSerializer::summarize($program);

        $oracle = file_get_contents($dir . '/oracle.txt');
        $this->assertIsString($oracle);
        $this->assertSame(rtrim($oracle, "\n"), $summary);
    }
}
