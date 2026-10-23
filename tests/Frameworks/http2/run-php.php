<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Phasis\Engine;
use Phasis\Node\NodeOptions;

$engine = new Engine();
$engine->enableNode(new NodeOptions(argv: ['phasis', $argv[1], ...array_slice($argv, 2)]));
$engine->setLimit('maxLoopIterations', 1_000_000);
$engine->setGlobal('__http2Ready', static function (int $port): void {
    fwrite(STDOUT, json_encode(['ready' => $port], JSON_THROW_ON_ERROR) . "\n");
});
$engine->setGlobal('__http2Report', static function (string $report): void {
    fwrite(STDOUT, $report . "\n");
});
try {
    $engine->execFile($argv[1]);
} finally {
    $engine->getNodeRuntime()?->close();
}
