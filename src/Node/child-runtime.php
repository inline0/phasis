<?php

declare(strict_types=1);

use Phasis\Node\NodeCommand;

(static function (): void {
    foreach ([__DIR__ . '/../../vendor/autoload.php', __DIR__ . '/../../../../autoload.php'] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
            return;
        }
    }
    fwrite(STDERR, "Could not locate the Composer autoloader.\n");
    exit(1);
})();

$arguments = $_SERVER['argv'] ?? [];
$script = $arguments[1] ?? null;
if ($script === null || !is_file($script)) {
    fwrite(STDERR, "A readable JavaScript child script is required.\n");
    exit(1);
}
exit(NodeCommand::runFile($script, array_slice($arguments, 2)));
