<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
ini_set('memory_limit', '2G');
set_time_limit(0);

$file = $argv[1] ?? __DIR__ . '/microbench.js';
$src = file_get_contents($file);

$engine = new \PhpJs\Engine();
$engine->setLimit('maxLoopIterations', 10_000_000);
$engine->setLimit('maxCallDepth', 1000);

$t0 = hrtime(true);
$engine->eval($src);
$t1 = hrtime(true);
echo $engine->getConsoleOutput();
$totalMs = ($t1 - $t0) / 1e6;
fprintf(STDERR, "TOTAL: %.1fms\n", $totalMs);
