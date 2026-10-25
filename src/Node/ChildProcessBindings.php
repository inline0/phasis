<?php

declare(strict_types=1);

namespace Phasis\Node;

use Phasis\Engine;
use Phasis\Exceptions\TypeError;
use Phasis\Interop\PhpToJs;
use Phasis\Runtime\AsyncContextStorage;
use Phasis\Value\JsArray;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/** Native processes explicitly requested by JavaScript, never a JavaScript evaluation backend. */
final class ChildProcessBindings
{
    /** @var array<int, ChildProcessState> */
    private array $children = [];
    private int $sequence = 0;

    private function __construct(private readonly Engine $engine, private readonly NodeRuntime $runtime)
    {
        $engine->getEventLoop()->addIoDriver(
            'child_process',
            fn (int $timeout): bool => $this->poll($timeout),
            fn (): bool => $this->hasReferences(),
        );
        $runtime->onClose(fn () => $this->closeAll());
    }

    /** Create the realm-owned child-process host interface. */
    public static function create(Engine $engine, NodeRuntime $runtime): JsObject
    {
        $binding = new self($engine, $runtime);
        $host = new JsObject();
        $host->set('call', JsFunction::fromCallable('call', static function (JsValue $this_, array $args) use ($binding): JsValue {
            $operation = ($args[0] ?? JsUndefined::instance())->toJsString();
            return $binding->operation($operation, array_slice($args, 1));
        }));
        return $host;
    }

    /** @param list<JsValue> $args */
    private function operation(string $operation, array $args): JsValue
    {
        $result = new JsObject();
        try {
            $value = match ($operation) {
                'spawnSync' => $this->spawnSync($this->object($args[0] ?? JsUndefined::instance())),
                'spawn' => $this->spawn($this->object($args[0] ?? JsUndefined::instance()), $this->callback($args[1] ?? JsUndefined::instance())),
                'write' => $this->write($args),
                'end' => $this->end($this->child($args[0] ?? JsUndefined::instance())),
                'pause' => $this->pause($args),
                'ref' => $this->reference($args),
                'kill' => $this->kill($args),
                'closePipe' => $this->closePipe(
                    $this->child($args[0] ?? JsUndefined::instance()),
                    (int) ($args[1] ?? JsUndefined::instance())->toNumber(),
                ),
                default => throw new TypeError("Unknown child-process operation '{$operation}'."),
            };
            $result->set('value', $value);
        } catch (\RuntimeException $error) {
            $code = match ($error->getCode()) {
                2 => 'ENOENT', 13 => 'EACCES', 32 => 'EPIPE', default => 'EIO'
            };
            $result->set('error', $this->error($code, $operation === 'spawnSync' ? 'spawnSync' : 'spawn', $error->getMessage()));
        }
        return $result;
    }

    private function spawn(JsObject $options, JsFunction $callback): JsValue
    {
        $state = $this->open($options, $callback);
        $this->children[$state->id] = $state;
        return PhpToJs::convert(['id' => $state->id, 'pid' => $state->pid, 'pipes' => array_keys($state->pipes)]);
    }

    private function open(JsObject $options, ?JsFunction $callback): ChildProcessState
    {
        $file = $options->get('file')->toJsString();
        $arguments = $options->get('args');
        $command = [$file];
        if ($arguments instanceof JsArray) {
            foreach ($arguments->toList() as $argument) {
                $command[] = $argument->toJsString();
            }
        }
        $cwd = $this->runtime->absolutePath($options->get('cwd')->toJsString());
        if (!is_dir($cwd)) {
            throw new \RuntimeException($file, 2);
        }
        $environment = [];
        $env = $options->get('env');
        if ($env instanceof JsObject) {
            foreach ($env->getOwnPropertyNames() as $key) {
                $environment[$key] = $env->get($key)->toJsString();
            }
        }
        $resolved = $this->resolveExecutable($file, $cwd, $environment['PATH'] ?? '/usr/bin:/bin');
        $command[0] = $resolved;
        if ($resolved === PHP_BINARY && isset($command[1]) && preg_match('/\.(?:cjs|mjs|js|jsx|ts|tsx|mts|cts)$/i', $command[1]) === 1) {
            // process.execPath remains PHP. A requested JavaScript child therefore
            // runs in a new Phasis realm instead of being handed to another engine.
            array_splice($command, 1, 0, [__DIR__ . '/child-runtime.php']);
        }
        $descriptors = [];
        $stdio = $options->get('stdio');
        for ($fd = 0; $fd < 3; $fd++) {
            $kind = $stdio instanceof JsArray ? $stdio->get((string) $fd)->toJsString() : 'pipe';
            $descriptors[$fd] = match ($kind) {
                'pipe', 'overlapped' => ['pipe', $fd === 0 ? 'r' : 'w'],
                'ignore' => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', $fd === 0 ? 'r' : 'w'],
                '0' => STDIN,
                '1' => STDOUT,
                '2' => STDERR,
                default => throw new TypeError('Unsupported child-process stdio descriptor.'),
            };
        }
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, $cwd, $environment, ['bypass_shell' => true]);
        if ($process === false) {
            throw new \RuntimeException($file);
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $status = proc_get_status($process);
        $state = new ChildProcessState(
            ++$this->sequence,
            $process,
            $status['pid'],
            $pipes,
            $callback,
            AsyncContextStorage::active()->snapshot(),
        );
        if (!$status['running']) {
            $state->exited = true;
            $state->exitCode = $status['signaled'] ? null : $status['exitcode'];
            $state->signal = $status['signaled'] ? $this->signalName($status['termsig']) : null;
        }
        return $state;
    }

    private function resolveExecutable(string $file, string $cwd, string $path): string
    {
        $candidates = str_contains($file, '/') ? [$file] : array_map(
            static fn (string $dir): string => ($dir === '' ? '.' : $dir) . '/' . $file,
            explode(PATH_SEPARATOR, $path),
        );
        $denied = false;
        foreach ($candidates as $candidate) {
            $candidate = str_starts_with($candidate, '/') ? $candidate : $cwd . '/' . $candidate;
            if (is_file($candidate)) {
                if (is_executable($candidate)) {
                    return realpath($candidate) ?: $candidate;
                }
                $denied = true;
            }
        }
        throw new \RuntimeException($file, $denied ? 13 : 2);
    }

    private function spawnSync(JsObject $options): JsValue
    {
        $state = $this->open($options, null);
        $input = $options->get('input');
        $state->input = $input instanceof JsTypedArray ? $this->bytes($input) : '';
        $state->ending = true;
        $state->paused = [1 => false, 2 => false];
        $output = [1 => '', 2 => ''];
        $timeout = $options->get('timeout')->toNumber();
        $deadline = $timeout > 0 ? microtime(true) + $timeout / 1000 : INF;
        $maximum = $options->get('maxBuffer')->toNumber();
        $signal = (int) $options->get('killSignal')->toNumber();
        $error = null;
        try {
            while (!$state->exited || $state->pipes !== []) {
                if ($error === null && microtime(true) >= $deadline) {
                    $error = $this->error('ETIMEDOUT', 'spawnSync', $options->get('file')->toJsString());
                    proc_terminate($state->process, $signal);
                }
                $this->transfer($state, 20_000, static function (int $fd, string $bytes) use (&$output): void {
                    $output[$fd] .= $bytes;
                });
                if ($error === null && strlen($output[1]) + strlen($output[2]) > $maximum) {
                    $error = $this->error('ENOBUFS', 'spawnSync', $options->get('file')->toJsString());
                    proc_terminate($state->process, $signal);
                }
                $this->updateStatus($state);
                if ($state->exited && isset($state->pipes[0])) {
                    $this->closePipe($state, 0);
                }
            }
            $this->reap($state);
        } finally {
            $this->dispose($state);
        }
        $result = new JsObject();
        $result->set('pid', JsNumber::of($state->pid));
        $result->set('status', $state->exitCode === null ? JsNull::instance() : JsNumber::of($state->exitCode));
        $result->set('signal', $state->signal === null ? JsNull::instance() : new JsString($state->signal));
        $result->set('stdout', $this->buffer($output[1]));
        $result->set('stderr', $this->buffer($output[2]));
        if ($error !== null) {
            $result->set('error', $error);
        }
        return $result;
    }

    /** @param list<JsValue> $args */
    private function write(array $args): JsValue
    {
        $state = $this->child($args[0] ?? JsUndefined::instance());
        if (!isset($state->pipes[0])) {
            throw new \RuntimeException('write EPIPE', 32);
        }
        $bytes = $args[1] ?? JsUndefined::instance();
        if (!$bytes instanceof JsTypedArray) {
            throw new TypeError('Child input must contain bytes.');
        }
        $state->input .= $this->bytes($bytes);
        $state->writeCallback = $this->callback($args[2] ?? JsUndefined::instance());
        if ($state->input === '') {
            $this->runtime->nextTick($state->writeCallback, []);
            $state->writeCallback = null;
        }
        return JsUndefined::instance();
    }

    private function end(ChildProcessState $state): JsValue
    {
        $state->ending = true;
        return JsUndefined::instance();
    }

    /** @param list<JsValue> $args */
    private function pause(array $args): JsValue
    {
        $state = $this->child($args[0] ?? JsUndefined::instance());
        $fd = (int) ($args[1] ?? JsUndefined::instance())->toNumber();
        $state->paused[$fd] = ($args[2] ?? JsUndefined::instance())->toBoolean();
        return JsUndefined::instance();
    }

    /** @param list<JsValue> $args */
    private function reference(array $args): JsValue
    {
        $state = $this->child($args[0] ?? JsUndefined::instance());
        $reference = ($args[1] ?? JsUndefined::instance())->toBoolean();
        $fd = $args[2] ?? JsUndefined::instance();
        if ($fd instanceof JsUndefined) {
            $state->referenced = $reference;
        } else {
            $state->pipeReferences[(int) $fd->toNumber()] = $reference;
        }
        return JsUndefined::instance();
    }

    /** @param list<JsValue> $args */
    private function kill(array $args): JsValue
    {
        $state = $this->children[(int) ($args[0] ?? JsUndefined::instance())->toNumber()] ?? null;
        $signal = (int) ($args[1] ?? JsUndefined::instance())->toNumber();
        return new JsBoolean($state !== null && !$state->exited && proc_terminate($state->process, $signal));
    }

    private function poll(int $timeout): bool
    {
        $progress = false;
        foreach ($this->children as $state) {
            if ($state->spawnPending) {
                $state->spawnPending = false;
                $this->emit($state, 'spawn');
                $progress = true;
            }
            $progress = $this->transfer($state, $progress ? 0 : $timeout * 1000, function (int $fd, string $bytes) use ($state): void {
                $value = new JsObject();
                $value->set('fd', JsNumber::of($fd));
                $value->set('data', $this->buffer($bytes));
                $this->emit($state, 'data', $value);
            }) || $progress;
            $timeout = 0;
            $this->updateStatus($state);
            if ($state->exited && !$state->exitEmitted) {
                $state->exitEmitted = true;
                $this->emit($state, 'exit', PhpToJs::convert(['code' => $state->exitCode, 'signal' => $state->signal]));
                if (isset($state->pipes[0])) {
                    $this->closePipe($state, 0);
                }
                $progress = true;
            }
            if ($state->exited && $state->pipes === []) {
                $this->reap($state);
                unset($this->children[$state->id]);
                $this->emit($state, 'close', PhpToJs::convert(['code' => $state->exitCode, 'signal' => $state->signal]));
                $progress = true;
            }
        }
        return $progress;
    }

    /** @param \Closure(int, string): void $receive */
    private function transfer(ChildProcessState $state, int $timeout, \Closure $receive): bool
    {
        if (isset($state->pipes[0]) && $state->input === '' && $state->ending) {
            $this->closePipe($state, 0);
        }
        $read = [];
        foreach ([1, 2] as $fd) {
            if (isset($state->pipes[$fd]) && !$state->paused[$fd]) {
                $read[] = $state->pipes[$fd];
            }
        }
        $write = isset($state->pipes[0]) && $state->input !== '' ? [$state->pipes[0]] : [];
        if ($read === [] && $write === []) {
            if ($timeout > 0) {
                usleep($timeout);
            }
            return false;
        }
        $except = [];
        $selected = @stream_select($read, $write, $except, 0, min($timeout, 999_999));
        if (!$selected) {
            return false;
        }
        foreach ($write as $pipe) {
            $written = @fwrite($pipe, $state->input);
            if ($written === false || ($written === 0 && feof($pipe))) {
                $this->completeWrite($state, $this->error('EPIPE', 'write', ''));
                $this->closePipe($state, 0);
            } else {
                $state->input = substr($state->input, $written);
                if ($state->input === '') {
                    $this->completeWrite($state);
                    if ($state->ending) {
                        $this->closePipe($state, 0);
                    }
                }
            }
        }
        foreach ([1, 2] as $fd) {
            $pipe = $state->pipes[$fd] ?? null;
            if ($pipe === null || !in_array($pipe, $read, true)) {
                continue;
            }
            $bytes = fread($pipe, 65536);
            if (is_string($bytes) && $bytes !== '') {
                $receive($fd, $bytes);
            }
            if (isset($state->pipes[$fd]) && feof($pipe)) {
                $this->closePipe($state, $fd);
                $this->emit($state, 'end', JsNumber::of($fd));
            }
        }
        return true;
    }

    private function updateStatus(ChildProcessState $state): void
    {
        if ($state->exited) {
            return;
        }
        $status = proc_get_status($state->process);
        if (!$status['running']) {
            $state->exited = true;
            $state->exitCode = $status['signaled'] ? null : $status['exitcode'];
            if ($status['signaled']) {
                $state->signal = $this->signalName($status['termsig']);
            }
        }
    }

    private function signalName(int $signal): string
    {
        foreach (get_defined_constants() as $name => $value) {
            if (str_starts_with($name, 'SIG') && !str_starts_with($name, 'SIG_') && $value === $signal) {
                return $name;
            }
        }
        return 'SIG' . $signal;
    }

    private function closePipe(ChildProcessState $state, int $fd): JsValue
    {
        if (isset($state->pipes[$fd])) {
            fclose($state->pipes[$fd]);
            unset($state->pipes[$fd]);
        }
        if ($fd === 0 && $state->input !== '') {
            $state->input = '';
            $this->completeWrite($state, $this->error('EPIPE', 'write', ''));
        }
        return JsUndefined::instance();
    }

    private function reap(ChildProcessState $state): void
    {
        if (!$state->closed) {
            $code = proc_close($state->process);
            if ($state->exitCode === null && $state->signal === null && $code >= 0) {
                $state->exitCode = $code;
            }
            $state->closed = true;
        }
    }

    private function dispose(ChildProcessState $state): void
    {
        foreach ($state->pipes as $pipe) {
            fclose($pipe);
        }
        $state->pipes = [];
        if (!$state->closed) {
            if (!$state->exited) {
                proc_terminate($state->process, 9);
            }
            $this->reap($state);
        }
    }

    private function closeAll(): void
    {
        foreach ($this->children as $state) {
            $this->dispose($state);
        }
        $this->children = [];
        $this->engine->getEventLoop()->removeIoDriver('child_process');
    }

    private function hasReferences(): bool
    {
        foreach ($this->children as $state) {
            if ($state->referenced && !$state->exited) {
                return true;
            }
            foreach ($state->pipes as $fd => $pipe) {
                if ($state->pipeReferences[$fd]) {
                    return true;
                }
            }
        }
        return false;
    }

    private function emit(ChildProcessState $state, string $event, ?JsValue $value = null): void
    {
        if ($state->callback !== null) {
            $this->invoke($state, $state->callback, [new JsString($event), $value ?? JsUndefined::instance()]);
        }
    }

    private function completeWrite(ChildProcessState $state, ?JsObject $error = null): void
    {
        $callback = $state->writeCallback;
        $state->writeCallback = null;
        if ($callback !== null) {
            $this->invoke($state, $callback, [$error ?? JsUndefined::instance()]);
        }
    }

    /** @param list<JsValue> $args */
    private function invoke(ChildProcessState $state, JsFunction $callback, array $args): void
    {
        $previous = Engine::getCurrentInterpreter();
        $context = AsyncContextStorage::active()->snapshot();
        Engine::setCurrentInterpreter($this->engine->getInterpreter());
        AsyncContextStorage::active()->restore($state->context);
        try {
            $this->engine->getInterpreter()->callFunction($callback, JsUndefined::instance(), $args);
        } finally {
            AsyncContextStorage::active()->restore($context);
            Engine::setCurrentInterpreter($previous);
        }
    }

    private function child(JsValue $id): ChildProcessState
    {
        return $this->children[(int) $id->toNumber()] ?? throw new \RuntimeException('Child process is closed', 32);
    }

    private function object(JsValue $value): JsObject
    {
        return $value instanceof JsObject ? $value : throw new TypeError('Expected child-process options.');
    }

    private function callback(JsValue $value): JsFunction
    {
        return $value instanceof JsFunction ? $value : throw new TypeError('Expected a child-process callback.');
    }

    private function bytes(JsTypedArray $value): string
    {
        return $value->getBuffer()->readBytes($value->getByteOffset(), $value->getLength() * $value->getBytesPerElement());
    }

    private function buffer(string $bytes): JsTypedArray
    {
        $buffer = new JsArrayBuffer(strlen($bytes));
        $buffer->setData($bytes);
        $constructor = $this->engine->getGlobalEnv()->get('Uint8Array');
        $prototype = $constructor instanceof JsObject ? $constructor->get('prototype') : null;
        return new JsTypedArray('Uint8Array', $buffer, 0, strlen($bytes), $prototype instanceof JsObject ? $prototype : null);
    }

    private function error(string $code, string $syscall, string $file): JsObject
    {
        $error = new JsObject();
        $error->set('message', new JsString($syscall . ($file === '' ? '' : ' ' . $file) . ' ' . $code));
        $error->set('code', new JsString($code));
        $error->set('errno', JsNumber::of(-match ($code) {
            'ENOENT' => 2, 'EACCES' => 13, 'EPIPE' => 32, 'ETIMEDOUT' => 60, 'ENOBUFS' => 55, default => 5
        }));
        $error->set('syscall', new JsString($syscall . ($file === '' ? '' : ' ' . $file)));
        return $error;
    }
}
