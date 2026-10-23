<?php

declare(strict_types=1);

namespace Phasis\Node;

use Phasis\Engine;
use Phasis\Interop\PhpToJs;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/** Binary filesystem operations and descriptors owned by one Node realm. */
final class FileSystemBindings
{
    /** @var array<int, array{stream: resource, path: string, readable: bool, writable: bool}> */
    private array $descriptors = [];

    private int $nextDescriptor = 100;

    private function __construct(private readonly Engine $engine, private readonly NodeRuntime $runtime)
    {
    }

    /** Install the native half of fs, with cleanup on engine reset and close. */
    public static function create(Engine $engine, NodeRuntime $runtime): JsObject
    {
        $bindings = new self($engine, $runtime);
        $runtime->onClose($bindings->closeAll(...));
        $host = new JsObject();
        $host->set('call', JsFunction::fromCallable('call', static function (JsValue $this_, array $args) use ($bindings): JsValue {
            $operation = array_shift($args)?->toJsString() ?? '';
            $result = new JsObject();
            try {
                $result->set('value', $bindings->dispatch($operation, $args));
            } catch (FileSystemError $error) {
                $result->set('error', self::error($error));
            }
            return $result;
        }));
        return $host;
    }

    /** @param list<JsValue> $args */
    private function dispatch(string $operation, array $args): JsValue
    {
        $first = $args[0] ?? JsUndefined::instance();
        if ($operation === 'open') {
            return JsNumber::of($this->open($first->toJsString(), $args[1]->toJsString(), (int) $args[2]->toNumber()));
        }
        if ($operation === 'close') {
            $descriptor = (int) $first->toNumber();
            $entry = $this->descriptor($descriptor, 'close');
            fclose($entry['stream']);
            unset($this->descriptors[$descriptor]);
            return JsUndefined::instance();
        }
        if ($operation === 'read' || $operation === 'write') {
            return JsNumber::of($this->transfer($operation, $args));
        }
        if ($operation === 'fstat') {
            $entry = $this->descriptor((int) $first->toNumber(), 'fstat');
            $stat = fstat($entry['stream']);
            if ($stat === false) {
                throw new FileSystemError('EIO', 'fstat');
            }
            return $this->statObject($stat);
        }
        if (in_array($operation, ['ftruncate', 'fsync', 'fdatasync'], true)) {
            $entry = $this->descriptor((int) $first->toNumber(), $operation);
            $success = match ($operation) {
                'ftruncate' => @ftruncate($entry['stream'], (int) $args[1]->toNumber()),
                'fsync' => @fsync($entry['stream']),
                default => @fdatasync($entry['stream']),
            };
            if (!$success) {
                throw new FileSystemError($entry['writable'] ? 'EIO' : 'EINVAL', $operation);
            }
            return JsUndefined::instance();
        }
        if ($operation === 'readAll') {
            $entry = $this->descriptor((int) $first->toNumber(), 'read');
            if (!$entry['readable']) {
                throw new FileSystemError('EBADF', 'read');
            }
            if (is_dir($this->runtime->absolutePath($entry['path']))) {
                throw new FileSystemError('EISDIR', 'read');
            }
            $bytes = stream_get_contents($entry['stream']);
            if ($bytes === false) {
                throw new FileSystemError('EIO', 'read');
            }
            return $this->bytes($bytes);
        }

        $path = $first->toJsString();
        $absolute = $this->runtime->absolutePath($path);
        clearstatcache(true, $absolute);
        if ($operation === 'stat' || $operation === 'lstat') {
            $stat = $operation === 'stat' ? @stat($absolute) : @lstat($absolute);
            if ($stat === false) {
                throw $this->pathError($operation, $path);
            }
            return $this->statObject($stat);
        }
        if ($operation === 'exists') {
            return new JsBoolean(file_exists($absolute));
        }
        if ($operation === 'access') {
            if (!file_exists($absolute)) {
                throw $this->pathError('access', $path);
            }
            $mode = (int) $args[1]->toNumber();
            if ((($mode & 4) && !is_readable($absolute)) || (($mode & 2) && !is_writable($absolute)) || (($mode & 1) && !is_executable($absolute))) {
                throw new FileSystemError('EACCES', 'access', $path);
            }
            return JsUndefined::instance();
        }
        if ($operation === 'readdir') {
            $names = @scandir($absolute);
            if ($names === false) {
                throw $this->pathError('scandir', $path, is_file($absolute) ? 'ENOTDIR' : null);
            }
            $entries = [];
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $stat = @lstat($absolute . '/' . $name);
                $entries[] = ['name' => $this->bytes($name), 'mode' => $stat['mode'] ?? 0];
            }
            return PhpToJs::convert($entries);
        }
        if ($operation === 'realpath' || $operation === 'readlink') {
            $value = $operation === 'realpath' ? realpath($absolute) : @readlink($absolute);
            if ($value === false) {
                throw $this->pathError($operation, $path, $operation === 'readlink' && file_exists($absolute) ? 'EINVAL' : null);
            }
            return $this->bytes($value);
        }
        if ($operation === 'mkdir') {
            $recursive = $args[2]->toBoolean();
            if (file_exists($absolute) || is_link($absolute)) {
                if ($recursive && is_dir($absolute)) {
                    return JsUndefined::instance();
                }
                throw new FileSystemError('EEXIST', 'mkdir', $path);
            }
            $firstCreated = $absolute;
            if ($recursive) {
                while (!file_exists(dirname($firstCreated)) && dirname($firstCreated) !== $firstCreated) {
                    $firstCreated = dirname($firstCreated);
                }
            }
            if (!@mkdir($absolute, (int) $args[1]->toNumber(), $recursive)) {
                throw $this->pathError('mkdir', $path);
            }
            if (!$recursive) {
                return JsUndefined::instance();
            }
            $value = str_starts_with($path, '/') ? $firstCreated : substr($firstCreated, strlen($this->runtime->cwd()) + 1);
            return new JsString($value);
        }
        if ($operation === 'mkdtemp') {
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $suffix = '';
                $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
                for ($i = 0; $i < 6; $i++) {
                    $suffix .= $alphabet[random_int(0, 61)];
                }
                if (@mkdir($absolute . $suffix, 0700)) {
                    return $this->bytes($path . $suffix);
                }
                if (!file_exists($absolute . $suffix)) {
                    throw $this->pathError('mkdtemp', $path . 'XXXXXX');
                }
            }
            throw new FileSystemError('EEXIST', 'mkdtemp', $path . 'XXXXXX');
        }
        if ($operation === 'unlink' || $operation === 'rmdir') {
            if ($operation === 'unlink' && is_dir($absolute) && !is_link($absolute)) {
                throw new FileSystemError('EPERM', 'unlink', $path);
            }
            $success = $operation === 'unlink' ? @unlink($absolute) : @rmdir($absolute);
            if (!$success) {
                $code = $operation === 'rmdir' && is_dir($absolute) ? 'ENOTEMPTY' : null;
                throw $this->pathError($operation, $path, $code);
            }
            return JsUndefined::instance();
        }
        if (in_array($operation, ['rename', 'link', 'symlink', 'copyFile'], true)) {
            return $this->twoPaths($operation, $path, $args[1]->toJsString(), $args[2] ?? JsUndefined::instance());
        }
        if ($operation === 'chmod') {
            if (!@chmod($absolute, (int) $args[1]->toNumber())) {
                throw $this->pathError('chmod', $path);
            }
            return JsUndefined::instance();
        }
        if ($operation === 'chown' || $operation === 'lchown') {
            $uid = (int) $args[1]->toNumber();
            $gid = (int) $args[2]->toNumber();
            $link = $operation === 'lchown';
            $owner = $uid === -1 || ($link ? @lchown($absolute, $uid) : @chown($absolute, $uid));
            $group = $gid === -1 || ($link ? @lchgrp($absolute, $gid) : @chgrp($absolute, $gid));
            if (!$owner || !$group) {
                throw $this->pathError($operation, $path);
            }
            return JsUndefined::instance();
        }
        if ($operation === 'utimes') {
            if (!file_exists($absolute)) {
                throw $this->pathError('utime', $path);
            }
            if (!@touch($absolute, (int) $args[2]->toNumber(), (int) $args[1]->toNumber())) {
                throw $this->pathError('utime', $path);
            }
            return JsUndefined::instance();
        }
        throw new FileSystemError('ENOSYS', $operation, $path);
    }

    private function open(string $path, string $flags, int $mode): int
    {
        $absolute = $this->runtime->absolutePath($path);
        clearstatcache(true, $absolute);
        $readable = str_contains($flags, '+') || $flags[0] === 'r';
        $writable = $flags[0] !== 'r' || str_contains($flags, '+');
        $phpMode = str_contains($flags, 'x') ? 'x' : $flags[0];
        if (str_contains($flags, '+')) {
            $phpMode .= '+';
        }
        $phpMode .= 'b';
        $existed = file_exists($absolute) || is_link($absolute);
        if (str_contains($flags, 'x') && $existed) {
            throw new FileSystemError('EEXIST', 'open', $path);
        }
        if ($writable && is_dir($absolute)) {
            throw new FileSystemError('EISDIR', 'open', $path);
        }
        $stream = @fopen($absolute, $phpMode);
        if ($stream === false) {
            throw $this->pathError('open', $path);
        }
        if (!$existed && $writable) {
            @chmod($absolute, $mode & ~umask());
        }
        $descriptor = $this->nextDescriptor++;
        $this->descriptors[$descriptor] = ['stream' => $stream, 'path' => $path, 'readable' => $readable, 'writable' => $writable];
        return $descriptor;
    }

    /** @param list<JsValue> $args */
    private function transfer(string $operation, array $args): int
    {
        $entry = $this->descriptor((int) $args[0]->toNumber(), $operation);
        if (!$entry[$operation === 'read' ? 'readable' : 'writable']) {
            throw new FileSystemError('EBADF', $operation);
        }
        $view = $args[1];
        if (!$view instanceof JsTypedArray) {
            throw new \Phasis\Exceptions\TypeError('Filesystem transfer requires a byte view.');
        }
        $view->validateNotDetached($operation === 'read');
        $offset = (int) $args[2]->toNumber();
        $length = (int) $args[3]->toNumber();
        $position = $args[4];
        if ($length === 0) {
            return 0;
        }
        if ($operation === 'read' && is_dir($this->runtime->absolutePath($entry['path']))) {
            throw new FileSystemError('EISDIR', 'read');
        }
        $stream = $entry['stream'];
        $restore = null;
        if (!$position instanceof JsNull && !$position instanceof JsUndefined && $position->toNumber() >= 0) {
            $restore = ftell($stream);
            if (fseek($stream, (int) $position->toNumber(), SEEK_SET) !== 0) {
                throw new FileSystemError('ESPIPE', $operation);
            }
        }
        try {
            if ($operation === 'read') {
                $bytes = @fread($stream, $length);
                if ($bytes === false) {
                    throw new FileSystemError('EIO', 'read');
                }
                $view->getBuffer()->writeBytes($view->getByteOffset() + $offset, $bytes);
                return strlen($bytes);
            }
            $bytes = $view->getBuffer()->readBytes($view->getByteOffset() + $offset, $length);
            $written = @fwrite($stream, $bytes);
            if ($written === false) {
                throw new FileSystemError('EIO', 'write');
            }
            fflush($stream);
            return $written;
        } finally {
            if (is_int($restore)) {
                fseek($stream, $restore, SEEK_SET);
            }
        }
    }

    private function twoPaths(string $operation, string $path, string $destination, JsValue $options): JsValue
    {
        $source = $this->runtime->absolutePath($path);
        $target = $this->runtime->absolutePath($destination);
        if ($operation === 'copyFile') {
            $flags = (int) $options->toNumber();
            if (is_dir($source) || is_dir($target)) {
                throw new FileSystemError('EISDIR', 'copyfile', $path, $destination);
            }
            if (($flags & 1) && (file_exists($target) || is_link($target))) {
                throw new FileSystemError('EEXIST', 'copyfile', $path, $destination);
            }
            if ($flags & 4) {
                throw new FileSystemError('ENOTSUP', 'copyfile', $path, $destination);
            }
            $sourceStat = @stat($source);
            $targetStat = @stat($target);
            if (
                is_array($sourceStat) && is_array($targetStat)
                && $sourceStat['dev'] === $targetStat['dev'] && $sourceStat['ino'] === $targetStat['ino']
            ) {
                return JsUndefined::instance();
            }
            $input = @fopen($source, 'rb');
            if ($input === false) {
                throw $this->pathError('copyfile', $path);
            }
            try {
                $output = @fopen($target, ($flags & 1) ? 'xb' : 'wb');
                if ($output === false) {
                    throw new FileSystemError(!is_dir(dirname($target)) ? 'ENOENT' : 'EACCES', 'copyfile', $path, $destination);
                }
                try {
                    if (stream_copy_to_stream($input, $output) === false) {
                        throw new FileSystemError('EIO', 'copyfile', $path, $destination);
                    }
                    if (is_array($sourceStat)) {
                        @chmod($target, $sourceStat['mode'] & 0777);
                    }
                } finally {
                    fclose($output);
                }
            } finally {
                fclose($input);
            }
            return JsUndefined::instance();
        }
        $success = match ($operation) {
            'rename' => @rename($source, $target),
            'link' => @link($source, $target),
            default => @symlink($path, $target),
        };
        if (!$success) {
            $code = ($operation !== 'rename' && (file_exists($target) || is_link($target))) ? 'EEXIST' : null;
            $error = $this->pathError($operation, $path, $code);
            throw new FileSystemError($error->errorCode, $operation, $path, $destination);
        }
        return JsUndefined::instance();
    }

    /** @return array{stream: resource, path: string, readable: bool, writable: bool} */
    private function descriptor(int $descriptor, string $operation): array
    {
        if (!isset($this->descriptors[$descriptor])) {
            throw new FileSystemError('EBADF', $operation);
        }
        return $this->descriptors[$descriptor];
    }

    /** @param array<int|string, int> $stat */
    private function statObject(array $stat): JsObject
    {
        $result = new JsObject();
        foreach (['dev', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'blksize', 'ino', 'size', 'blocks'] as $key) {
            $result->set($key, JsNumber::of($stat[$key] ?? 0));
        }
        foreach (['atime', 'mtime', 'ctime', 'birthtime'] as $key) {
            $result->set($key . 'Ms', JsNumber::of(($stat[$key] ?? $stat['ctime']) * 1000));
        }
        return $result;
    }

    private function bytes(string $bytes): JsTypedArray
    {
        $constructor = $this->engine->getGlobalEnv()->get('Uint8Array');
        $prototype = $constructor instanceof JsObject ? $constructor->get('prototype') : null;
        $view = JsTypedArray::fromLength('Uint8Array', strlen($bytes), $prototype instanceof JsObject ? $prototype : null);
        $view->getBuffer()->setData($bytes);
        return $view;
    }

    private function pathError(string $operation, string $path, ?string $code = null): FileSystemError
    {
        if ($code !== null) {
            return new FileSystemError($code, $operation, $path);
        }
        $absolute = $this->runtime->absolutePath($path);
        $nameTooLong = defined('SOCKET_ENAMETOOLONG') ? constant('SOCKET_ENAMETOOLONG') : (PHP_OS_FAMILY === 'Darwin' ? 63 : 36);
        // PHP's stat warning omits errno. pathconf preserves the host's path-resolution error.
        if (function_exists('posix_pathconf')) {
            if (@posix_pathconf($absolute, POSIX_PC_NAME_MAX) === false && posix_get_last_error() === $nameTooLong) {
                return new FileSystemError('ENAMETOOLONG', $operation, $path);
            }
        } elseif (strlen($absolute) >= PHP_MAXPATHLEN) {
            return new FileSystemError('ENAMETOOLONG', $operation, $path);
        }
        if (function_exists('posix_access') && !@posix_access($absolute, POSIX_F_OK) && posix_get_last_error() === $nameTooLong) {
            return new FileSystemError('ENAMETOOLONG', $operation, $path);
        }
        $parent = dirname($absolute);
        while (!file_exists($parent) && !is_link($parent) && dirname($parent) !== $parent) {
            $parent = dirname($parent);
        }
        $code = !is_dir($parent) ? 'ENOTDIR' : (file_exists($absolute) ? 'EACCES' : 'ENOENT');
        return new FileSystemError($code, $operation, $path);
    }

    private static function error(FileSystemError $error): JsObject
    {
        $codes = [
            'EPERM' => [1, 'operation not permitted'], 'ENOENT' => [2, 'no such file or directory'],
            'EIO' => [5, 'i/o error'], 'EBADF' => [9, 'bad file descriptor'],
            'EACCES' => [13, 'permission denied'], 'EEXIST' => [17, 'file already exists'],
            'ENOTDIR' => [20, 'not a directory'], 'EISDIR' => [21, 'illegal operation on a directory'],
            'EINVAL' => [22, 'invalid argument'], 'EMFILE' => [24, 'too many open files'],
            'ESPIPE' => [29, 'invalid seek'], 'ENOSYS' => [PHP_OS_FAMILY === 'Darwin' ? 78 : 38, 'function not implemented'],
            'ENOTEMPTY' => [PHP_OS_FAMILY === 'Darwin' ? 66 : 39, 'directory not empty'],
            'ENAMETOOLONG' => [
                defined('SOCKET_ENAMETOOLONG') ? constant('SOCKET_ENAMETOOLONG') : (PHP_OS_FAMILY === 'Darwin' ? 63 : 36),
                'name too long',
            ],
            'ENOTSUP' => [PHP_OS_FAMILY === 'Darwin' ? 45 : 95, 'operation not supported on socket'],
        ];
        [$number, $description] = $codes[$error->errorCode] ?? [5, 'i/o error'];
        $message = $error->errorCode . ': ' . $description . ', ' . $error->syscall;
        $data = ['errno' => -$number, 'code' => $error->errorCode, 'syscall' => $error->syscall];
        if ($error->path !== null) {
            $data['path'] = $error->path;
            $message .= " '{$error->path}'";
        }
        if ($error->destination !== null) {
            $data['dest'] = $error->destination;
            $message .= " -> '{$error->destination}'";
        }
        $data['message'] = $message;
        $value = PhpToJs::convert($data);
        assert($value instanceof JsObject);
        return $value;
    }

    private function closeAll(): void
    {
        foreach ($this->descriptors as $entry) {
            if (is_resource($entry['stream'])) {
                fclose($entry['stream']);
            }
        }
        $this->descriptors = [];
    }
}
