<?php

declare(strict_types=1);

namespace Phasis\Node;

use Phasis\Engine;
use Phasis\Exceptions\TypeError;
use Phasis\Interop\PhpToJs;
use Phasis\Runtime\AsyncContextStorage;
use Phasis\Value\JsFunction;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/** Native host operations consumed by the Node compatibility modules. */
final class HostBindings
{
    public static function create(Engine $engine, NodeRuntime $runtime): JsObject
    {
        $host = new JsObject();
        $host->set('fs', FileSystemBindings::create($engine, $runtime));
        $host->set('net', IoBindings::create($engine, $runtime));
        $host->set('crypto', CryptoBindings::create($engine, $runtime));
        $host->set('zlib', ZlibBindings::create($engine, $runtime));
        $host->set('vm', VmBindings::create($engine, $runtime));
        $host->set('child_process', ChildProcessBindings::create($engine, $runtime));
        $host->set('path', PathBindings::create());
        self::method($host, 'isatty', static function (array $args): JsValue {
            $fd = (int) ($args[0] ?? JsUndefined::instance())->toNumber();
            $stream = match ($fd) {
                0 => STDIN, 1 => STDOUT, 2 => STDERR, default => null
            };
            return new JsBoolean($stream !== null && stream_isatty($stream));
        });
        self::method($host, 'stdioWrite', static function (array $args): JsValue {
            $fd = (int) ($args[0] ?? JsUndefined::instance())->toNumber();
            $stream = match ($fd) {
                1 => STDOUT, 2 => STDERR, default => null
            };
            $data = $args[1] ?? JsUndefined::instance();
            if ($stream === null || !$data instanceof \Phasis\Value\JsTypedArray) {
                throw new TypeError('Invalid standard stream write');
            }
            $bytes = $data->getBuffer()->readBytes($data->getByteOffset(), $data->getLength());
            $written = fwrite($stream, $bytes);
            if ($written === false) {
                throw new \RuntimeException('Cannot write to standard stream');
            }
            return JsNumber::of($written);
        });
        self::method($host, 'stdioRead', static function (array $args) use ($runtime): JsValue {
            if ((int) ($args[0] ?? JsUndefined::instance())->toNumber() !== 0) {
                throw new TypeError('Invalid standard input descriptor');
            }
            $read = [STDIN];
            $write = $except = [];
            if (stream_select($read, $write, $except, 0, 0) === 0) {
                return JsUndefined::instance();
            }
            $bytes = fread(STDIN, 65536);
            if ($bytes === false) {
                throw new \RuntimeException('Cannot read standard input');
            }
            if ($bytes === '' && feof(STDIN)) {
                return \Phasis\Value\JsNull::instance();
            }
            return $runtime->byteArray($bytes);
        });
        self::method($host, 'timer', static function (array $args) use ($engine): JsValue {
            $operation = ($args[0] ?? JsUndefined::instance())->toJsString();
            $loop = $engine->getEventLoop();
            if ($operation === 'clear') {
                $loop->clear((int) ($args[1] ?? JsUndefined::instance())->toNumber());
                return JsUndefined::instance();
            }
            if ($operation === 'ref') {
                $loop->reference((int) ($args[1] ?? JsUndefined::instance())->toNumber(), ($args[2] ?? JsUndefined::instance())->toBoolean());
                return JsUndefined::instance();
            }
            $callback = self::functionArgument($args[1] ?? JsUndefined::instance());
            $delay = ($args[2] ?? JsUndefined::instance())->toNumber();
            return JsNumber::of(match ($operation) {
                'immediate' => $loop->setImmediate($callback),
                'interval' => $loop->setInterval($callback, $delay),
                'timeout' => $loop->setTimeout($callback, $delay),
                default => throw new TypeError('Unknown timer operation.'),
            });
        });
        $started = hrtime(true);
        self::method($host, 'cwd', static fn (array $args): JsValue => new JsString($runtime->cwd()));
        self::method($host, 'chdir', static function (array $args) use ($runtime): JsValue {
            $runtime->chdir(($args[0] ?? JsUndefined::instance())->toJsString());
            return JsUndefined::instance();
        });
        self::method($host, 'nextTick', static function (array $args) use ($runtime): JsValue {
            $callback = self::functionArgument($args[0] ?? JsUndefined::instance());
            $runtime->nextTick($callback, array_slice($args, 1));
            return JsUndefined::instance();
        });
        self::method($host, 'hrtime', static function (array $args): JsValue {
            $now = hrtime(true);
            return PhpToJs::convert([intdiv($now, 1_000_000_000), $now % 1_000_000_000]);
        });
        self::method($host, 'hrtimeBigInt', static fn (array $args): JsValue => new \Phasis\Value\JsBigInt((string) hrtime(true)));
        self::method($host, 'uptime', static fn (array $args): JsValue => JsNumber::of((hrtime(true) - $started) / 1e9));
        self::method($host, 'memoryUsage', static fn (array $args): JsValue => PhpToJs::convert([
            'rss' => memory_get_usage(true), 'heapTotal' => memory_get_usage(true),
            'heapUsed' => memory_get_usage(), 'external' => 0, 'arrayBuffers' => 0,
        ]));
        self::method($host, 'pid', static fn (array $args): JsValue => JsNumber::of(getmypid() ?: 0));
        self::method($host, 'ppid', static fn (array $args): JsValue => JsNumber::of(function_exists('posix_getppid') ? posix_getppid() : 0));
        self::method($host, 'builtin', static fn (array $args): JsValue => $runtime->module(($args[0] ?? JsUndefined::instance())->toJsString()));
        self::method($host, 'builtinNames', static fn (array $args): JsValue => PhpToJs::convert($runtime->builtinNames()));
        self::method($host, 'createRequire', static function (array $args) use ($engine): JsValue {
            $path = ($args[0] ?? JsUndefined::instance())->toJsString();
            if (str_starts_with($path, 'file:')) {
                $parsed = parse_url($path);
                if (
                    $parsed === false || !isset($parsed['path'])
                    || (isset($parsed['host']) && $parsed['host'] !== '' && $parsed['host'] !== 'localhost')
                ) {
                    throw new TypeError('createRequire expects a local file URL or absolute filename.');
                }
                $path = rawurldecode($parsed['path']);
            }
            if (!str_starts_with($path, '/')) {
                throw new TypeError('createRequire expects a file URL or absolute filename.');
            }
            return $engine->getInterpreter()->getModuleLoader()->createRequire($path);
        });
        self::method($host, 'eventListeners', static function (array $args): JsValue {
            $target = self::objectArgument($args[0] ?? JsUndefined::instance());
            if ($target->getInternalProperty(\Phasis\BuiltIn\EventTargetConstructor::SLOT_IS_TARGET) !== true) {
                throw new TypeError('Expected an EventTarget.');
            }
            $type = ($args[1] ?? JsUndefined::instance())->toJsString();
            /** @var array<string, list<array<string, mixed>>> $listeners */
            $listeners = $target->getInternalProperty(\Phasis\BuiltIn\EventTargetConstructor::SLOT_LISTENERS) ?? [];
            $callbacks = [];
            foreach ($listeners[$type] ?? [] as $listener) {
                if (!empty($listener['removed'])) {
                    continue;
                }
                $signal = $listener['signal'] ?? null;
                if ($signal instanceof JsObject && $signal->get('aborted')->toBoolean()) {
                    continue;
                }
                if (($listener['callback'] ?? null) instanceof JsValue) {
                    $callbacks[] = $listener['callback'];
                }
            }
            return PhpToJs::convert($callbacks);
        });
        self::method($host, 'contextGet', static function (array $args): JsValue {
            $key = self::objectArgument($args[0] ?? JsUndefined::instance());
            return AsyncContextStorage::active()->getRaw($key) ?? JsUndefined::instance();
        });
        self::method($host, 'contextHas', static function (array $args): JsValue {
            $key = self::objectArgument($args[0] ?? JsUndefined::instance());
            return new JsBoolean(AsyncContextStorage::active()->getRaw($key) !== null);
        });
        self::method($host, 'contextSet', static function (array $args): JsValue {
            AsyncContextStorage::active()->set(self::objectArgument($args[0] ?? JsUndefined::instance()), $args[1] ?? JsUndefined::instance());
            return JsUndefined::instance();
        });
        self::method($host, 'contextDelete', static function (array $args): JsValue {
            AsyncContextStorage::active()->restoreSlot(self::objectArgument($args[0] ?? JsUndefined::instance()), null);
            return JsUndefined::instance();
        });
        self::method($host, 'contextRun', static function (array $args) use ($engine): JsValue {
            $key = self::objectArgument($args[0] ?? JsUndefined::instance());
            $callback = self::functionArgument($args[2] ?? JsUndefined::instance());
            $storage = AsyncContextStorage::active();
            $previous = $storage->getRaw($key);
            $storage->set($key, $args[1] ?? JsUndefined::instance());
            try {
                return $engine->getInterpreter()->callFunction($callback, JsUndefined::instance(), array_slice($args, 3));
            } finally {
                $storage->restoreSlot($key, $previous);
            }
        });
        self::method($host, 'contextCapture', static function (array $args): JsValue {
            $snapshot = new JsObject();
            $snapshot->setInternalProperty('[[NodeAsyncSnapshot]]', AsyncContextStorage::active()->snapshot());
            return $snapshot;
        });
        self::method($host, 'contextRunSnapshot', static function (array $args) use ($engine): JsValue {
            $token = self::objectArgument($args[0] ?? JsUndefined::instance());
            $snapshot = $token->getInternalProperty('[[NodeAsyncSnapshot]]');
            if (!is_array($snapshot)) {
                throw new TypeError('Invalid async context snapshot.');
            }
            $callback = self::functionArgument($args[1] ?? JsUndefined::instance());
            $storage = AsyncContextStorage::active();
            $previous = $storage->snapshot();
            /** @var array{0: array<int, JsObject>, 1: array<int, JsValue>} $snapshot */
            $storage->restore($snapshot);
            try {
                return $engine->getInterpreter()->callFunction($callback, $args[2] ?? JsUndefined::instance(), array_slice($args, 3));
            } finally {
                $storage->restore($previous);
            }
        });
        return $host;
    }

    /** @param \Closure(list<JsValue>): JsValue $callback */
    private static function method(JsObject $target, string $name, \Closure $callback): void
    {
        $target->set($name, JsFunction::fromCallable($name, static fn (JsValue $this_, array $args): JsValue => $callback($args)));
    }

    private static function objectArgument(JsValue $value): JsObject
    {
        if (!$value instanceof JsObject) {
            throw new TypeError('Expected an object.');
        }
        return $value;
    }

    private static function functionArgument(JsValue $value): JsFunction
    {
        if (!$value instanceof JsFunction) {
            throw new TypeError('The callback argument must be a function.');
        }
        return $value;
    }
}
