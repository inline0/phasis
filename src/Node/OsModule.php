<?php

declare(strict_types=1);

namespace Phasis\Node;

use Phasis\Engine;
use Phasis\Exceptions\TypeError;
use Phasis\Interop\PhpToJs;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/** Host operating-system information without starting an external process. */
final class OsModule
{
    /** Create this realm's Node os module. */
    public static function create(Engine $engine): JsObject
    {
        $module = new JsObject();
        $module->set('EOL', new JsString(PHP_EOL));
        $module->set('devNull', new JsString(PHP_OS_FAMILY === 'Windows' ? '\\\\.\\nul' : '/dev/null'));
        $module->set('constants', PhpToJs::convert(self::constants()));
        $functions = [
            'arch' => static fn (): string => self::processProperty($engine, 'arch'),
            'platform' => static fn (): string => self::processProperty($engine, 'platform'),
            'machine' => static fn (): string => php_uname('m'),
            'type' => static fn (): string => php_uname('s'),
            'release' => static fn (): string => php_uname('r'),
            'version' => static fn (): string => php_uname('v'),
            'hostname' => static fn (): string => gethostname() ?: php_uname('n'),
            'endianness' => static fn (): string => ord(pack('S', 1)[0]) === 1 ? 'LE' : 'BE',
            'homedir' => static fn (): string => self::homeDirectory($engine),
            'tmpdir' => static fn (): string => self::temporaryDirectory($engine),
            'loadavg' => static fn (): array => function_exists('sys_getloadavg') ? sys_getloadavg() : [0.0, 0.0, 0.0],
            'freemem' => static fn (): int => self::memory('MemFree'),
            'totalmem' => static fn (): int => self::memory('MemTotal'),
            'uptime' => static fn (): float => self::uptime(),
        ];
        foreach ($functions as $name => $function) {
            $module->set($name, JsFunction::fromCallable(
                $name,
                static fn (JsValue $this_, array $args): JsValue => PhpToJs::convert($function()),
                0,
            ));
        }
        $module->set('userInfo', JsFunction::fromCallable('userInfo', static function (JsValue $this_, array $args) use ($engine): JsValue {
            $options = $args[0] ?? JsUndefined::instance();
            if (!$options instanceof JsUndefined && !$options instanceof JsObject) {
                throw new TypeError('The "options" argument must be of type object.');
            }
            $encoding = $options instanceof JsObject ? $options->get('encoding') : JsUndefined::instance();
            $encoding = $encoding instanceof JsUndefined ? 'utf8' : $encoding->toJsString();
            $result = PhpToJs::convert(self::userInfo($engine));
            if (!$result instanceof JsObject || $encoding === 'utf8' || $encoding === 'utf-8') {
                return $result;
            }
            $buffer = $engine->getGlobalEnv()->get('Buffer');
            $from = $buffer instanceof JsObject ? $buffer->get('from') : JsUndefined::instance();
            if (!$from instanceof JsFunction) {
                throw new TypeError('Buffer is unavailable in this realm.');
            }
            foreach (['username', 'homedir', 'shell'] as $property) {
                $value = $result->get($property);
                if (!$value instanceof JsString) {
                    continue;
                }
                $encoded = $engine->getInterpreter()->callFunction($from, $buffer, [$value]);
                if ($encoding !== 'buffer' && $encoded instanceof JsObject) {
                    $toString = $encoded->get('toString');
                    if ($toString instanceof JsFunction) {
                        $encoded = $engine->getInterpreter()->callFunction($toString, $encoded, [new JsString($encoding)]);
                    }
                }
                $result->set($property, $encoded);
            }
            return $result;
        }));
        return $module;
    }

    /** @return array{signals: array<string, int>, errno: array<string, int>} */
    private static function constants(): array
    {
        $signals = [];
        $errno = [];
        foreach (get_defined_constants() as $name => $value) {
            if (!is_int($value)) {
                continue;
            }
            if (preg_match('/^SIG[A-Z0-9]+$/', $name) === 1) {
                $signals[$name] = $value;
            } elseif (str_starts_with($name, 'SOCKET_E')) {
                $errno[substr($name, 7)] = $value;
            }
        }
        return ['signals' => $signals, 'errno' => $errno];
    }

    private static function processProperty(Engine $engine, string $name): string
    {
        $process = $engine->getGlobalEnv()->get('process');
        return $process instanceof JsObject ? $process->get($name)->toJsString() : '';
    }

    private static function environment(Engine $engine, string $name): ?string
    {
        $process = $engine->getGlobalEnv()->get('process');
        $environment = $process instanceof JsObject ? $process->get('env') : JsUndefined::instance();
        $value = $environment instanceof JsObject ? $environment->get($name) : JsUndefined::instance();
        return $value instanceof JsUndefined ? null : $value->toJsString();
    }

    private static function homeDirectory(Engine $engine): string
    {
        $environment = self::environment($engine, PHP_OS_FAMILY === 'Windows' ? 'USERPROFILE' : 'HOME');
        if ($environment !== null && $environment !== '') {
            return $environment;
        }
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user = posix_getpwuid(posix_geteuid());
            if (is_array($user)) {
                return (string) $user['dir'];
            }
        }
        throw new TypeError('Could not determine the home directory on this PHP host.');
    }

    private static function temporaryDirectory(Engine $engine): string
    {
        $keys = PHP_OS_FAMILY === 'Windows' ? ['TEMP', 'TMP'] : ['TMPDIR', 'TMP', 'TEMP'];
        foreach ($keys as $key) {
            $value = self::environment($engine, $key);
            if ($value !== null && $value !== '') {
                return strlen($value) > 1 ? rtrim($value, '/\\') : $value;
            }
        }
        return PHP_OS_FAMILY === 'Windows' ? sys_get_temp_dir() : '/tmp';
    }

    /** @return array{uid: int, gid: int, username: string, homedir: string, shell: ?string} */
    private static function userInfo(Engine $engine): array
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user = posix_getpwuid(posix_geteuid());
            if (is_array($user)) {
                return [
                    'uid' => (int) $user['uid'],
                    'gid' => (int) $user['gid'],
                    'username' => (string) $user['name'],
                    'homedir' => (string) $user['dir'],
                    'shell' => (string) $user['shell'],
                ];
            }
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return [
                'uid' => -1,
                'gid' => -1,
                'username' => self::environment($engine, 'USERNAME') ?? get_current_user(),
                'homedir' => self::homeDirectory($engine),
                'shell' => null,
            ];
        }
        throw new TypeError('Could not determine the current user on this PHP host.');
    }

    private static function memory(string $field, ?string $fallback = null): int
    {
        $data = is_readable('/proc/meminfo') ? file_get_contents('/proc/meminfo') : false;
        if ($data !== false) {
            foreach ([$field, $fallback] as $key) {
                if ($key !== null && preg_match('/^' . $key . ':\s+(\d+)\s+kB$/m', $data, $match) === 1) {
                    return (int) $match[1] * 1024;
                }
            }
        }
        throw new TypeError('Host memory information is unavailable without an operating-system PHP binding.');
    }

    private static function uptime(): float
    {
        $data = is_readable('/proc/uptime') ? file_get_contents('/proc/uptime') : false;
        if ($data !== false && preg_match('/^([0-9.]+)/', $data, $match) === 1) {
            return floor((float) $match[1]);
        }
        throw new TypeError('Host uptime is unavailable without an operating-system PHP binding.');
    }
}
