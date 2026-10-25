<?php

declare(strict_types=1);

namespace Phasis\Module;

use Phasis\Ast\Program;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Parser\Parser;
use Phasis\Runtime\Interpreter;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * Runs CommonJS modules (`require` / `module.exports`) the way Node wraps
 * them, so npm packages that only ship CommonJS load without a bundler.
 *
 * Each file runs inside `(function (exports, require, module, __filename,
 * __dirname, process) { ... })`, kept on the first line so stack-trace line
 * numbers stay right. ModuleLoader owns the module records; this class only
 * parses, runs, and builds the ES-module view of `module.exports`.
 */
final class CommonJsLoader
{
    private const WRAPPER_HEAD = '(function (exports, require, module, __filename, __dirname, process) {';

    private ?JsObject $process = null;

    private ?JsObject $cache = null;

    /** @var array<string, ?JsObject> */
    private array $parents = [];

    /** @var array<string, JsObject> */
    private array $syntheticParents = [];

    private ?string $mainPath = null;
    private ?JsObject $mainModule = null;

    public function __construct(
        private readonly ModuleLoader $loader,
        private readonly Interpreter $interpreter,
    ) {
    }

    /** Parse a file as CommonJS. Throws SyntaxError when it is not valid script code. */
    public function parse(string $source, ?string $path = null): Program
    {
        if (str_starts_with($source, '#!')) {
            $newline = strcspn($source, "\r\n");
            $source = str_repeat(' ', $newline) . substr($source, $newline);
        }
        $head = $this->loader->moduleOptions()->nodeCompatible
            ? '(function (exports, require, module, __filename, __dirname) {'
            : self::WRAPPER_HEAD;
        $parser = new Parser($head . $source . "\n})", $path);
        $parser->setCommonJsWrapper();
        return $parser->parse();
    }

    /**
     * Run a parsed CommonJS module and return its `module` object. The module
     * object is registered with the loader before the body runs, so require
     * cycles see the partially filled exports, as in Node.
     */
    public function run(ModuleRecord $record, string $path, Program $program): JsObject
    {
        $module = $this->newModule($path);
        $exports = new JsObject();
        $module->set('exports', $exports);
        $record->commonJsModule = $module;
        if ($this->loader->moduleOptions()->nodeCompatible) {
            $this->cache()->set($path, $module);
            $parent = $this->parents[$path] ?? null;
            $module->set('parent', $parent ?? \Phasis\Value\JsNull::instance());
            if ($path === $this->mainPath) {
                $this->mainModule = $module;
                $module->set('id', new JsString('.'));
            }
            if ($parent !== null) {
                $this->appendChild($parent, $module);
            }
        }

        $previousStrict = $this->interpreter->isStrictMode();
        $this->interpreter->setStrictMode(false);
        try {
            $wrapper = $this->interpreter->execute($program);
        } finally {
            $this->interpreter->setStrictMode($previousStrict);
        }
        if (!$wrapper instanceof JsFunction) {
            throw new TypeError("CommonJS wrapper for '{$path}' did not produce a function");
        }
        $arguments = [
            $exports,
            $this->makeRequire($path),
            $module,
            new JsString($path),
            new JsString(dirname($path)),
        ];
        if (!$this->loader->moduleOptions()->nodeCompatible) {
            $arguments[] = $this->process();
        }
        $this->interpreter->callFunction($wrapper, $exports, $arguments);
        $module->set('loaded', new JsBoolean(true));
        return $module;
    }

    /**
     * Fill an ES-module namespace from `module.exports`, following bundler
     * interop: the default export is `module.exports`, or its `default`
     * property when `__esModule` is set; every own enumerable property is
     * also a named export.
     */
    public static function populateNamespace(JsObject $namespace, JsValue $exports, bool $nodeCompatible = false): void
    {
        $default = $exports;
        if (!$nodeCompatible && $exports instanceof JsObject && $exports->get('__esModule')->toBoolean()) {
            $default = $exports->get('default');
        }
        $namespace->defineOwnProperty('default', PropertyDescriptor::data($default, true, true, false));
        if ($nodeCompatible) {
            $namespace->defineOwnProperty('module.exports', PropertyDescriptor::data($exports, true, true, false));
        }
        if (!$exports instanceof JsObject) {
            return;
        }
        foreach ($exports->getOwnEnumerableKeys() as $key) {
            $key = (string) $key;
            if ($key === 'default' || ($nodeCompatible && $key === 'module.exports')) {
                continue;
            }
            $namespace->defineOwnProperty($key, PropertyDescriptor::data($exports->get($key), true, true, false));
        }
    }

    /** Build a require function anchored to an absolute module filename. */
    public function makeRequire(string $path): JsFunction
    {
        $loader = $this->loader;
        $require = JsFunction::fromCallable('require', static function (JsValue $this_, array $args) use ($loader, $path): JsValue {
            $specifier = ($args[0] ?? JsUndefined::instance())->toJsString();
            return $loader->requireModule($specifier, $path);
        }, 1);
        $require->set('resolve', JsFunction::fromCallable('resolve', static function (JsValue $this_, array $args) use ($loader, $path): JsValue {
            $specifier = ($args[0] ?? JsUndefined::instance())->toJsString();
            $resolved = $loader->resolve($specifier, $path, 'require');
            if ($loader->moduleOptions()->nodeCompatible && str_starts_with($resolved, ModuleResolver::BUILTIN)) {
                return new JsString($specifier);
            }
            return new JsString($resolved);
        }, 1));
        if ($loader->moduleOptions()->nodeCompatible) {
            $require->set('cache', $this->cache());
            $require->defineOwnProperty('main', PropertyDescriptor::accessor(
                JsFunction::fromCallable('get main', fn (): JsValue => $this->mainModule ?? JsUndefined::instance()),
                null,
                true,
                true,
            ));
        }
        return $require;
    }

    /** Record the requiring module before a child executes, including cyclic imports. */
    public function prepareRequire(string $path, string $referrer): void
    {
        if (str_starts_with($path, ModuleResolver::BUILTIN)) {
            return;
        }
        if ($path === $referrer && $this->mainPath === null && !$this->cache()->hasOwnProperty($path)) {
            $this->mainPath = $path;
            $this->parents[$path] = null;
            return;
        }
        $parent = $this->cache()->get($referrer);
        if (!$parent instanceof JsObject) {
            $parent = $this->syntheticParents[$referrer] ??= $this->newModule($referrer);
        }
        if (!$this->cache()->hasOwnProperty($path)) {
            $this->parents[$path] = $parent;
        }
        $child = $this->cache()->get($path);
        if ($child instanceof JsObject) {
            $this->appendChild($parent, $child);
        }
    }

    private function newModule(string $path): JsObject
    {
        $module = new JsObject();
        $module->set('id', new JsString($path));
        $module->set('filename', new JsString($path));
        $module->set('loaded', new JsBoolean(false));
        if ($this->loader->moduleOptions()->nodeCompatible) {
            $module->set('path', new JsString(dirname($path)));
            $module->set('children', \Phasis\Value\JsArray::fromArray([]));
            $module->set('require', $this->makeRequire($path));
            $paths = [];
            $directory = dirname($path);
            while (true) {
                if (basename($directory) !== 'node_modules') {
                    $paths[] = new JsString(rtrim($directory, '/') . '/node_modules');
                }
                $next = dirname($directory);
                if ($next === $directory) {
                    break;
                }
                $directory = $next;
            }
            $module->set('paths', \Phasis\Value\JsArray::fromArray($paths));
            $module->set('isPreloading', new JsBoolean(false));
        }
        return $module;
    }

    private function appendChild(JsObject $parent, JsObject $child): void
    {
        $children = $parent->get('children');
        if (!$children instanceof \Phasis\Value\JsArray) {
            return;
        }
        for ($i = 0; $i < $children->getLength(); $i++) {
            if ($children->get((string) $i) === $child) {
                return;
            }
        }
        $children->push($child);
    }

    /** A failed require removes the cache entry and its edge in the parent's children array. */
    public function forgetFailedModule(string $path): void
    {
        $failed = $this->cache()->get($path);
        $parent = $this->parents[$path] ?? null;
        $children = $parent?->get('children');
        if ($children instanceof \Phasis\Value\JsArray) {
            $next = 0;
            $length = $children->getLength();
            for ($i = 0; $i < $length; $i++) {
                $child = $children->get((string) $i);
                if ($child !== $failed) {
                    $children->set((string) $next++, $child);
                }
            }
            $children->setLength($next);
        }
        $this->cache()->forceDelete($path);
        unset($this->parents[$path]);
    }

    /** The shared, mutable CommonJS module cache exposed as require.cache. */
    public function cache(): JsObject
    {
        return $this->cache ??= JsObject::createNullPrototype();
    }

    /** Discard all module entries while preserving existing require.cache references. */
    public function resetCache(): void
    {
        $this->parents = [];
        $this->syntheticParents = [];
        $this->mainPath = null;
        $this->mainModule = null;
        if ($this->cache === null) {
            return;
        }
        foreach ($this->cache->getOwnEnumerableKeys() as $key) {
            $this->cache->forceDelete((string) $key);
        }
    }

    /** The minimal `process` CommonJS modules see: only `env.NODE_ENV`. */
    private function process(): JsObject
    {
        $global = $this->interpreter->getGlobalEnv();
        if ($global->has('process')) {
            $process = $global->get('process');
            if ($process instanceof JsObject) {
                return $process;
            }
        }
        if ($this->process === null) {
            $env = new JsObject();
            $env->set('NODE_ENV', new JsString($this->loader->moduleOptions()->nodeEnv));
            $this->process = new JsObject();
            $this->process->set('env', $env);
        }
        return $this->process;
    }
}
