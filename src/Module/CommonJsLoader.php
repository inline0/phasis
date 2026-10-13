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

    public function __construct(
        private readonly ModuleLoader $loader,
        private readonly Interpreter $interpreter,
    ) {
    }

    /** Parse a file as CommonJS. Throws SyntaxError when it is not valid script code. */
    public function parse(string $source): Program
    {
        if (str_starts_with($source, '#!')) {
            $newline = strcspn($source, "\r\n");
            $source = str_repeat(' ', $newline) . substr($source, $newline);
        }
        return (new Parser(self::WRAPPER_HEAD . $source . "\n})"))->parse();
    }

    /**
     * Run a parsed CommonJS module and return its `module` object. The module
     * object is registered with the loader before the body runs, so require
     * cycles see the partially filled exports, as in Node.
     */
    public function run(ModuleRecord $record, string $path, Program $program): JsObject
    {
        $module = new JsObject();
        $exports = new JsObject();
        $module->set('exports', $exports);
        $module->set('id', new JsString($path));
        $module->set('filename', new JsString($path));
        $module->set('loaded', new JsBoolean(false));
        $record->commonJsModule = $module;

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
        $this->interpreter->callFunction($wrapper, $exports, [
            $exports,
            $this->makeRequire($path),
            $module,
            new JsString($path),
            new JsString(dirname($path)),
            $this->process(),
        ]);
        $module->set('loaded', new JsBoolean(true));
        return $module;
    }

    /**
     * Fill an ES-module namespace from `module.exports`, following bundler
     * interop: the default export is `module.exports`, or its `default`
     * property when `__esModule` is set; every own enumerable property is
     * also a named export.
     */
    public static function populateNamespace(JsObject $namespace, JsValue $exports): void
    {
        $default = $exports;
        if ($exports instanceof JsObject && $exports->get('__esModule')->toBoolean()) {
            $default = $exports->get('default');
        }
        $namespace->defineOwnProperty('default', PropertyDescriptor::data($default, true, true, false));
        if (!$exports instanceof JsObject) {
            return;
        }
        foreach ($exports->getOwnEnumerableKeys() as $key) {
            $key = (string) $key;
            if ($key === 'default') {
                continue;
            }
            $namespace->defineOwnProperty($key, PropertyDescriptor::data($exports->get($key), true, true, false));
        }
    }

    private function makeRequire(string $path): JsFunction
    {
        $loader = $this->loader;
        $require = JsFunction::fromCallable('require', static function (JsValue $this_, array $args) use ($loader, $path): JsValue {
            $specifier = ($args[0] ?? JsUndefined::instance())->toJsString();
            return $loader->requireModule($specifier, $path);
        }, 1);
        $require->set('resolve', JsFunction::fromCallable('resolve', static function (JsValue $this_, array $args) use ($loader, $path): JsValue {
            $specifier = ($args[0] ?? JsUndefined::instance())->toJsString();
            return new JsString($loader->resolve($specifier, $path, 'require'));
        }, 1));
        return $require;
    }

    /** The minimal `process` CommonJS modules see: only `env.NODE_ENV`. */
    private function process(): JsObject
    {
        if ($this->process === null) {
            $env = new JsObject();
            $env->set('NODE_ENV', new JsString($this->loader->moduleOptions()->nodeEnv));
            $this->process = new JsObject();
            $this->process->set('env', $env);
        }
        return $this->process;
    }
}
