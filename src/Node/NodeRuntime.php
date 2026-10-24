<?php

declare(strict_types=1);

namespace Phasis\Node;

use Phasis\Engine;
use Phasis\Exceptions\TypeError;
use Phasis\Interop\PhpToJs;
use Phasis\Runtime\AsyncContextStorage;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsString;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/** Node-compatible modules and host resources owned by one JavaScript realm. */
final class NodeRuntime
{
    /** @var array<string, string> */
    private const LIBRARIES = [
        'buffer' => 'buffer.cjs',
        'events' => 'events.cjs',
        'stream' => 'stream.cjs',
        'stream/promises' => 'stream-promises.cjs',
        'stream/consumers' => 'stream-consumers.cjs',
        'stream/web' => 'stream-web.cjs',
        'string_decoder' => 'string_decoder.cjs',
        'path' => 'path.cjs',
        'url' => 'url.cjs',
        'querystring' => 'querystring.cjs',
        'util' => 'util.cjs',
        'assert' => 'assert.cjs',
        'process' => 'process.cjs',
        'async_hooks' => 'async_hooks.cjs',
        'fs' => 'fs.cjs',
        'net' => 'net.cjs',
        'http' => 'http.cjs',
        'http2' => 'http2.cjs',
        '_http_common' => 'http-common.cjs',
        'https' => 'https.cjs',
        'tls' => 'tls.cjs',
        'tty' => 'tty.cjs',
        'vm' => 'vm.cjs',
        'child_process' => 'child_process.cjs',
        'crypto' => 'crypto.cjs',
        'zlib' => 'zlib.cjs',
        'module' => 'module.cjs',
        'timers' => 'timers.cjs',
        'timers/promises' => 'timers-promises.cjs',
    ];

    private string $workingDirectory;

    private JsObject $process;

    /** @var list<\Closure(): void> */
    private array $nextTicks = [];

    /** @var array<string, JsValue> */
    private array $moduleValues = [];

    /** @var list<\Closure(): void> */
    private array $closeHooks = [];

    /** @var ?\Closure(): bool */
    private ?\Closure $turnHook = null;

    private bool $closed = false;

    public function __construct(private readonly Engine $engine, private readonly NodeOptions $configuration)
    {
        $cwd = realpath($configuration->cwd ?? (getcwd() ?: '.'));
        if ($cwd === false || !is_dir($cwd)) {
            throw new \InvalidArgumentException('Node working directory must exist.');
        }
        $this->workingDirectory = $cwd;
        $this->process = new JsObject();
    }

    /** Configuration retained across Engine::reset(). */
    public function options(): NodeOptions
    {
        return $this->configuration;
    }

    /** Current directory for this realm, without changing PHP's process directory. */
    public function cwd(): string
    {
        return $this->workingDirectory;
    }

    /** Set the realm's working directory. */
    public function chdir(string $directory): void
    {
        $path = realpath($this->absolutePath($directory));
        if ($path === false || !is_dir($path)) {
            throw new TypeError("ENOENT: no such directory, chdir '{$directory}'");
        }
        $this->workingDirectory = $path;
    }

    /** Resolve a host path against this realm's current directory. */
    public function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->workingDirectory . '/' . $path;
    }

    /** Register built-ins and install the Node global environment. */
    public function install(): void
    {
        $this->engine->getEventLoop()->setRealTime(true);
        $loader = $this->engine->getInterpreter()->getModuleLoader();
        foreach (self::LIBRARIES as $name => $file) {
            $loader->registerCommonJsBuiltinModule(
                $name,
                fn (): JsValue => $this->loadLibrary($name, $file),
                ['node:' . $name],
            );
        }
        $loader->registerCommonJsBuiltinModule('os', fn (): JsValue => OsModule::create($this->engine), ['node:os']);
        foreach (
            ['path/posix' => ['path', 'posix'], 'path/win32' => ['path', 'win32'],
            'util/types' => ['util', 'types'], 'assert/strict' => ['assert', 'strict'],
            'fs/promises' => ['fs', 'promises']] as $name => [$parent, $property]
        ) {
            $loader->registerCommonJsBuiltinModule($name, function () use ($parent, $property): JsValue {
                $module = $this->module($parent);
                return $module instanceof JsObject ? $module->get($property) : JsUndefined::instance();
            }, ['node:' . $name]);
        }

        $env = $this->engine->getGlobalEnv();
        $env->defineVar('global', $env->get('globalThis'));
        $env->defineVar('process', $this->process);
        $this->initializeProcess();
        $this->engine->setGlobalJsValue('__phasisNodeHost', HostBindings::create($this->engine, $this));
        ErrorStack::install($this->engine);
        $env->defineVar('require', $loader->createRequire($this->workingDirectory . '/.phasis-eval.cjs'));
        $this->turnHook = fn (): bool => $this->drainNextTicks();
        JsPromise::setTurnBoundaryHook($this->turnHook);
        $this->module('process');
        $buffer = $this->module('buffer');
        if ($buffer instanceof JsObject) {
            $env->defineVar('Buffer', $buffer->get('Buffer'));
        }
        // Realize the web timer family first so its remaining lazy entries
        // cannot later overwrite the Node handle-returning functions.
        $env->get('queueMicrotask');
        $timers = $this->module('timers');
        if ($timers instanceof JsObject) {
            foreach (['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'setImmediate', 'clearImmediate'] as $name) {
                $env->defineVar($name, $timers->get($name));
            }
        }
        $channels = $this->loadLibrary('#broadcast-channel', 'broadcast_channel.cjs');
        if ($channels instanceof JsObject) {
            $env->defineVar('BroadcastChannel', $channels->get('BroadcastChannel'));
            $closeChannels = $channels->get('closeAll');
            if ($closeChannels instanceof JsFunction) {
                $this->onClose(function () use ($closeChannels): void {
                    $this->engine->getInterpreter()->callFunction($closeChannels, JsUndefined::instance(), []);
                });
            }
        }
    }

    /** The shared CommonJS value, also exposed as the corresponding ESM default. */
    public function module(string $name): JsValue
    {
        return $this->engine->getInterpreter()->getModuleLoader()->requireModule($name, __FILE__);
    }

    /** Create fresh bytes with the owning realm's view and backing-buffer intrinsics. */
    public function byteArray(string $bytes): JsTypedArray
    {
        $env = $this->engine->getGlobalEnv();
        $viewPrototype = $env->getIntrinsic('Uint8Array.prototype');
        $bufferPrototype = $env->getIntrinsic('ArrayBuffer.prototype');
        if (!$viewPrototype instanceof JsObject || !$bufferPrototype instanceof JsObject) {
            throw new \LogicException('Node byte arrays require installed TypedArray intrinsics.');
        }
        $length = strlen($bytes);
        $buffer = new JsArrayBuffer($length, $bufferPrototype);
        $buffer->setData($bytes);
        return new JsTypedArray('Uint8Array', $buffer, 0, $length, $viewPrototype);
    }

    /** Run the asynchronous Fetch transport on this realm's HTTP sockets. */
    public function fetch(
        JsObject $request,
        JsValue $bytes,
        JsFunction $cookieGet,
        JsFunction $cookieSet,
        JsFunction $finalize,
        JsFunction $responseConstructor,
    ): JsPromise {
        $transport = $this->loadLibrary('#fetch', 'fetch.cjs');
        if (!$transport instanceof JsFunction) {
            throw new \LogicException('Invalid Node fetch transport');
        }
        $result = $this->engine->getInterpreter()->callFunction(
            $transport,
            JsUndefined::instance(),
            [$request, $bytes, $cookieGet, $cookieSet, $finalize, $responseConstructor],
        );
        if (!$result instanceof JsPromise) {
            throw new \LogicException('Node fetch transport must return a Promise');
        }
        return $result;
    }

    /**
     * Known native module names, used by module.isBuiltin and process helpers.
     * @return list<string>
     */
    public function builtinNames(): array
    {
        return [...array_keys(self::LIBRARIES), 'os', 'path/posix', 'path/win32', 'util/types', 'assert/strict', 'fs/promises'];
    }

    /**
     * Queue a nextTick callback with the active async context.
     * @param list<JsValue> $arguments
     */
    public function nextTick(JsFunction $callback, array $arguments = []): void
    {
        $context = AsyncContextStorage::active()->snapshot();
        $this->nextTicks[] = function () use ($callback, $arguments, $context): void {
            $storage = AsyncContextStorage::active();
            $previous = $storage->snapshot();
            $storage->restore($context);
            try {
                $this->engine->getInterpreter()->callFunction($callback, JsUndefined::instance(), $arguments);
            } finally {
                $storage->restore($previous);
            }
        };
    }

    /** Run the entire nextTick batch before Promise jobs resume. */
    public function drainNextTicks(): bool
    {
        $progressed = $this->nextTicks !== [];
        while ($this->nextTicks !== []) {
            $callback = array_shift($this->nextTicks);
            $callback();
        }
        return $progressed;
    }

    /** Release runtime resources when the owning engine is reset or closed. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->nextTicks = [];
        if (JsPromise::getTurnBoundaryHook() === $this->turnHook) {
            JsPromise::setTurnBoundaryHook(null);
        }
        $this->engine->getEventLoop()->clearAll();
        $failure = null;
        foreach (array_reverse($this->closeHooks) as $close) {
            try {
                $close();
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        }
        $this->closeHooks = [];
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** Register cleanup for a host resource group owned by this runtime. */
    public function onClose(\Closure $close): void
    {
        $this->closeHooks[] = $close;
    }

    private function loadLibrary(string $name, string $file): JsValue
    {
        if (!isset($this->moduleValues[$name])) {
            $path = __DIR__ . '/Lib/' . $file;
            if (!is_file($path)) {
                throw new TypeError("Node module '{$name}' is not installed: {$path}");
            }
            $this->moduleValues[$name] = $this->engine->getInterpreter()->getModuleLoader()->requireModule($path, __FILE__);
        }
        return $this->moduleValues[$name];
    }

    private function initializeProcess(): void
    {
        $environment = $this->configuration->env ?? (getenv() ?: []);
        $environment['NODE_ENV'] ??= $this->engine->getInterpreter()->getModuleLoader()->moduleOptions()->nodeEnv;
        $env = new JsObject();
        foreach ($environment as $key => $value) {
            $env->set((string) $key, new JsString($value));
        }
        $this->process->set('env', $env);
        $this->process->set('argv', PhpToJs::convert($this->configuration->argv));
        $this->process->set('argv0', new JsString($this->configuration->argv[0] ?? 'phasis'));
        $this->process->set('execPath', new JsString(PHP_BINARY));
        $this->process->set('version', new JsString('v26.12.0'));
        $this->process->set('release', PhpToJs::convert(['name' => 'node']));
        $this->process->set('versions', PhpToJs::convert(['node' => '26.12.0', 'phasis' => '0.8.0', 'php' => PHP_VERSION]));
        $this->process->set('platform', new JsString(match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin', 'Windows' => 'win32', default => strtolower(PHP_OS_FAMILY),
        }));
        $this->process->set('arch', new JsString(match (php_uname('m')) {
            'arm64', 'aarch64' => 'arm64', 'x86_64', 'amd64' => 'x64', default => php_uname('m'),
        }));
    }
}
