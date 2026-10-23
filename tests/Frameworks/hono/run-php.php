<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    foreach (array_slice($error->getTrace(), 0, 12) as $frame) {
        fwrite(STDERR, ($frame['file'] ?? '[native]') . ':' . ($frame['line'] ?? 0) . PHP_EOL);
    }
    exit(1);
});
$errorReserve = str_repeat(' ', 32768);
register_shutdown_function(static function () use (&$errorReserve): void {
    $errorReserve = null;
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fwrite(STDERR, 'PHP fatal: ' . $error['message'] . ' at ' . $error['file'] . ':' . $error['line'] . PHP_EOL);
    }
});
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$engine = new Phasis\Engine(node: true);
$engine->setLimit('maxLoopIterations', 1_000_000);
$engine->setGlobalJsValue('__honoCase', Phasis\Value\JsFunction::fromCallable(
    '',
    static function (Phasis\Value\JsValue $this_, array $args): Phasis\Value\JsValue {
        $record = json_decode(($args[0] ?? Phasis\Value\JsUndefined::instance())->toJsString(), true, flags: JSON_THROW_ON_ERROR);
        $record['heapBytes'] = memory_get_usage(true);
        $record['monotonicSeconds'] = hrtime(true) / 1_000_000_000;
        fwrite(STDOUT, '__PHASIS_HONO_CASE__' . json_encode($record, JSON_THROW_ON_ERROR) . PHP_EOL);
        return Phasis\Value\JsUndefined::instance();
    },
));
$engine->setGlobalJsValue('__honoComplete', Phasis\Value\JsFunction::fromCallable(
    '',
    static function () use ($engine): Phasis\Value\JsValue {
        $engine->getNodeRuntime()?->close();
        return Phasis\Value\JsUndefined::instance();
    },
));
$engine->setGlobalJsValue('__honoResult', Phasis\Value\JsFunction::fromCallable(
    '',
    static function (Phasis\Value\JsValue $this_, array $args): Phasis\Value\JsValue {
        fwrite(STDOUT, '__PHASIS_HONO_RESULT__' . ($args[0] ?? Phasis\Value\JsUndefined::instance())->toJsString() . PHP_EOL);
        return Phasis\Value\JsUndefined::instance();
    },
));
$engine->execFile($argv[1]);
$report = $engine->eval('globalThis.__honoReport');
if ($report === null) {
    fwrite(STDERR, $engine->getConsoleOutput() . "\nThe Hono upstream runner did not finish.\n");
    exit(2);
}
echo '__PHASIS_HONO_REPORT__', json_encode($report, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
