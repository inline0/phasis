<?php

declare(strict_types=1);

namespace Phasis\Node;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/** Standalone CLI process control. Embedded engines never install process.exit(). */
final class NodeCommand
{
    /**
     * Execute a Node-compatible entry file with live stdout/stderr.
     * @param list<string> $arguments Arguments following the entry file.
     */
    public static function runFile(string $file, array $arguments = []): int
    {
        $path = realpath($file);
        if ($path === false || !is_file($path)) {
            fwrite(STDERR, "Cannot read file: {$file}\n");
            return 1;
        }
        return self::execute($path, null, [PHP_BINARY, $path, ...$arguments]);
    }

    /**
     * Evaluate a Node-compatible global script with live stdout/stderr.
     * @param list<string> $arguments Arguments following the source option.
     */
    public static function runSource(string $source, array $arguments = []): int
    {
        return self::execute(null, $source, [PHP_BINARY, ...$arguments]);
    }

    /** @param list<string> $argv */
    private static function execute(?string $file, ?string $source, array $argv): int
    {
        $engine = new Engine();
        register_shutdown_function(static fn () => $engine->close());
        try {
            $engine->enableNode(new NodeOptions(argv: $argv));
            $process = $engine->getGlobalEnv()->get('process');
            if (!$process instanceof JsObject) {
                throw new \RuntimeException('Node process global was not installed.');
            }
            $process->set('exit', JsFunction::fromCallable('exit', static function (JsValue $this_, array $args) use ($process): JsValue {
                exit(self::exitCode($args[0] ?? $process->get('exitCode')));
            }));
            $engine->eval(<<<'JS'
(() => {
  const format = require('node:util').format;
  for (const name of ['log', 'info', 'debug', 'warn', 'error']) {
    console[name] = (...args) => {
      const stream = name === 'warn' || name === 'error' ? process.stderr : process.stdout;
      stream.write(format(...args) + '\n');
    };
  }
})();
JS);
            if ($file === null) {
                $engine->eval($source ?? '');
            } else {
                $engine->execFile($file);
            }
            return self::exitCode($process->get('exitCode'));
        } catch (\Throwable $error) {
            fwrite(STDERR, $error->getMessage() . "\n");
            return 1;
        } finally {
            $engine->close();
        }
    }

    private static function exitCode(JsValue $value): int
    {
        return $value instanceof JsUndefined ? 0 : (int) $value->toNumber();
    }
}
