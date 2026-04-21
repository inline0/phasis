<?php

declare(strict_types=1);

namespace PhpJs\Module;

use PhpJs\Ast\Declaration\ExportDeclaration;
use PhpJs\Ast\Declaration\ExportSpecifier;
use PhpJs\Ast\Declaration\FunctionDeclaration;
use PhpJs\Ast\Declaration\ImportDeclaration;
use PhpJs\Ast\Declaration\VariableDeclaration;
use PhpJs\Ast\Declaration\ClassDeclaration;
use PhpJs\Ast\Node;
use PhpJs\Object\PropertyDescriptor;
use PhpJs\Parser\Parser;
use PhpJs\Runtime\Environment;
use PhpJs\Runtime\Interpreter;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

/**
 * Handles ES module loading, parsing, linking, and evaluation.
 *
 * Modules are identified by their absolute file path. Each module is evaluated
 * once and its namespace object is cached. Circular dependencies are handled
 * by returning the partially-evaluated namespace.
 */
class ModuleLoader
{
    /** @var array<string, ModuleRecord> Evaluated module cache keyed by absolute path. */
    private array $modules = [];

    /** @var array<string, true> Set of modules currently being evaluated (cycle detection). */
    private array $evaluating = [];

    private Interpreter $interpreter;
    private Environment $globalEnv;

    public function __construct(Interpreter $interpreter, Environment $globalEnv)
    {
        $this->interpreter = $interpreter;
        $this->globalEnv = $globalEnv;
    }

    /**
     * Load and evaluate a module, returning its namespace object.
     *
     * @param string $specifier The module specifier (relative or absolute path).
     * @param string|null $referrer The absolute path of the importing module (for resolving relative paths).
     * @return JsObject The module namespace object.
     */
    public function loadModule(string $specifier, ?string $referrer = null): JsObject
    {
        $resolved = $this->resolve($specifier, $referrer);

        // Return cached module namespace if already evaluated.
        if (isset($this->modules[$resolved])) {
            return $this->modules[$resolved]->namespace;
        }

        $source = @file_get_contents($resolved);
        if ($source === false) {
            throw new \PhpJs\Exceptions\TypeError("Cannot find module '{$specifier}'");
        }

        return $this->evaluateModule($resolved, $source);
    }

    /**
     * Evaluate module source text and return its namespace object.
     */
    public function evaluateModule(string $absolutePath, string $source): JsObject
    {
        // Return cached if available.
        if (isset($this->modules[$absolutePath])) {
            return $this->modules[$absolutePath]->namespace;
        }

        // Create the namespace object early for circular dependency handling.
        $namespace = new JsObject(null);

        $record = new ModuleRecord($absolutePath, $namespace);
        $this->modules[$absolutePath] = $record;

        // Detect circular dependency.
        if (isset($this->evaluating[$absolutePath])) {
            return $namespace;
        }
        $this->evaluating[$absolutePath] = true;

        try {
            $parser = new Parser($source);
            $program = $parser->parse();

            // Create a fresh module environment linked to the global environment.
            $moduleEnv = new Environment($this->globalEnv);

            // First pass: collect exports and process import declarations.
            $this->processDeclarations($program->body, $moduleEnv, $absolutePath, $namespace);

            // Execute the module body.
            $this->interpreter->executeModuleBody($program->body, $moduleEnv);

            // Second pass: populate namespace with final export values.
            $this->finalizeExports($program->body, $moduleEnv, $absolutePath, $namespace);

            // Module namespace objects have a null prototype per spec.
            $namespace->setPrototype(null);

            // Symbol.toStringTag = "Module" per spec.
            $toStringTagSym = \PhpJs\BuiltIn\SymbolConstructor::toStringTag();
            if ($toStringTagSym !== null) {
                $namespace->definePropertyBySymbol(
                    $toStringTagSym,
                    PropertyDescriptor::data(new JsString('Module'), false, false, false),
                );
            }
        } finally {
            unset($this->evaluating[$absolutePath]);
        }

        return $namespace;
    }

    /**
     * Resolve a module specifier to an absolute path.
     */
    public function resolve(string $specifier, ?string $referrer = null): string
    {
        // If the specifier is already an absolute path.
        if ($specifier !== '' && $specifier[0] === '/') {
            return $this->normalizeModulePath($specifier);
        }

        // Relative path: resolve relative to referrer.
        if ($specifier !== '' && ($specifier[0] === '.' || str_starts_with($specifier, './'))) {
            if ($referrer === null) {
                throw new \PhpJs\Exceptions\TypeError(
                    "Cannot resolve relative module specifier '{$specifier}' without a referrer",
                );
            }
            $base = dirname($referrer);
            return $this->normalizeModulePath($base . '/' . $specifier);
        }

        // Bare specifiers: not supported in our simple loader.
        throw new \PhpJs\Exceptions\TypeError(
            "Cannot resolve module specifier '{$specifier}'",
        );
    }

    private function normalizeModulePath(string $path): string
    {
        $realpath = realpath($path);
        if ($realpath !== false) {
            return $realpath;
        }
        // If realpath fails, use the path as-is after resolving . and ..
        $parts = explode('/', $path);
        $stack = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($stack);
            } else {
                $stack[] = $part;
            }
        }
        return '/' . implode('/', $stack);
    }

    /**
     * First pass: process import and export declarations to set up bindings.
     *
     * @param Node[] $body
     */
    private function processDeclarations(
        array $body,
        Environment $moduleEnv,
        string $modulePath,
        JsObject $namespace,
    ): void {
        foreach ($body as $node) {
            if ($node instanceof ImportDeclaration) {
                $this->processImportDeclaration($node, $moduleEnv, $modulePath);
            }
        }
    }

    private function processImportDeclaration(
        ImportDeclaration $node,
        Environment $moduleEnv,
        string $modulePath,
    ): void {
        $importedNs = $this->loadModule($node->source, $modulePath);

        foreach ($node->specifiers as $spec) {
            $value = match ($spec->specType) {
                'default' => $importedNs->get('default'),
                'namespace' => $importedNs,
                'named' => $importedNs->get($spec->imported ?? $spec->local),
                default => JsUndefined::instance(),
            };
            $moduleEnv->defineConst($spec->local, $value);
        }
    }

    /**
     * Final pass: populate namespace object with export values after evaluation.
     *
     * @param Node[] $body
     */
    private function finalizeExports(
        array $body,
        Environment $moduleEnv,
        string $modulePath,
        JsObject $namespace,
    ): void {
        foreach ($body as $node) {
            if ($node instanceof ExportDeclaration) {
                $this->processExportDeclaration($node, $moduleEnv, $modulePath, $namespace);
            }
        }
    }

    private function processExportDeclaration(
        ExportDeclaration $node,
        Environment $moduleEnv,
        string $modulePath,
        JsObject $namespace,
    ): void {
        // export default expr
        if ($node->isDefault) {
            if ($node->declaration !== null) {
                $value = $this->getDeclarationValue($node->declaration, $moduleEnv);
                $namespace->defineOwnProperty(
                    'default',
                    PropertyDescriptor::data($value, true, true, false),
                );
            }
            return;
        }

        // export * from 'source' or export * as ns from 'source'
        if ($node->isAll && $node->source !== null) {
            $reExportNs = $this->loadModule($node->source, $modulePath);
            if ($node->allAs !== null) {
                $namespace->defineOwnProperty(
                    $node->allAs,
                    PropertyDescriptor::data($reExportNs, true, true, false),
                );
            } else {
                // Re-export all named exports (except default).
                foreach ($reExportNs->getOwnPropertyNames() as $name) {
                    if ($name === 'default') {
                        continue;
                    }
                    $namespace->defineOwnProperty(
                        $name,
                        PropertyDescriptor::data($reExportNs->get($name), true, true, false),
                    );
                }
            }
            return;
        }

        // export { a, b as c } from 'source'
        if ($node->source !== null && $node->specifiers !== []) {
            $reExportNs = $this->loadModule($node->source, $modulePath);
            foreach ($node->specifiers as $spec) {
                $namespace->defineOwnProperty(
                    $spec->exported,
                    PropertyDescriptor::data($reExportNs->get($spec->local), true, true, false),
                );
            }
            return;
        }

        // export { a, b as c }
        if ($node->specifiers !== []) {
            foreach ($node->specifiers as $spec) {
                $value = $moduleEnv->has($spec->local)
                    ? $moduleEnv->get($spec->local)
                    : JsUndefined::instance();
                $namespace->defineOwnProperty(
                    $spec->exported,
                    PropertyDescriptor::data($value, true, true, false),
                );
            }
            return;
        }

        // export var/let/const/function/class
        if ($node->declaration !== null) {
            $names = $this->getDeclarationNames($node->declaration);
            foreach ($names as $name) {
                $value = $moduleEnv->has($name)
                    ? $moduleEnv->get($name)
                    : JsUndefined::instance();
                $namespace->defineOwnProperty(
                    $name,
                    PropertyDescriptor::data($value, true, true, false),
                );
            }
        }
    }

    /**
     * Get the value of a declaration node from the environment.
     */
    private function getDeclarationValue(Node $node, Environment $env): JsValue
    {
        if ($node instanceof FunctionDeclaration) {
            $name = $node->id?->name;
            if ($name !== null && $env->has($name)) {
                return $env->get($name);
            }
        }
        if ($node instanceof ClassDeclaration) {
            $name = $node->id?->name;
            if ($name !== null && $env->has($name)) {
                return $env->get($name);
            }
        }
        if ($node instanceof VariableDeclaration) {
            $last = end($node->declarations);
            if ($last !== null && $last->id instanceof \PhpJs\Ast\Expression\Identifier) {
                $name = $last->id->name;
                if ($env->has($name)) {
                    return $env->get($name);
                }
            }
        }
        // For expressions (export default <expr>), the interpreter already evaluated it.
        // We need to evaluate it here.
        return $this->interpreter->evaluate($node, $env);
    }

    /**
     * Get declared names from a declaration node.
     *
     * @return list<string>
     */
    private function getDeclarationNames(Node $node): array
    {
        if ($node instanceof FunctionDeclaration && $node->id !== null) {
            return [$node->id->name];
        }
        if ($node instanceof ClassDeclaration && $node->id !== null) {
            return [$node->id->name];
        }
        if ($node instanceof VariableDeclaration) {
            $names = [];
            foreach ($node->declarations as $decl) {
                if ($decl->id instanceof \PhpJs\Ast\Expression\Identifier) {
                    $names[] = $decl->id->name;
                }
            }
            return $names;
        }
        return [];
    }

    /**
     * Reset the module cache (useful for testing).
     */
    public function reset(): void
    {
        $this->modules = [];
        $this->evaluating = [];
    }
}
