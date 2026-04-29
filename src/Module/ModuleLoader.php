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

    /** @var list<string> Module paths whose bodies are awaiting execution, in link order. */
    private array $bodyQueue = [];

    /** True while a linking pass is in progress. Nested loadModule calls
     *  issued during linking (static imports or re-exports) add to the
     *  current batch and defer to the outer call for validation and body
     *  execution. Nested loads issued from inside a body (dynamic import)
     *  start a fresh batch, so the imported module is fully evaluated
     *  before the awaiter sees its namespace. */
    private bool $linking = false;

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

        // Nested loads issued during linking (from finalizeExports /
        // processImports) just link and return; the outer call owns
        // validation and body execution for the whole graph.
        if ($this->linking) {
            return $this->linkModule($resolved, $source);
        }

        $this->linking = true;
        try {
            $namespace = $this->linkModule($resolved, $source);
            $this->finalizeStarReExports();
            $this->lockDownNamespaces();
            $this->validateIndirectExports();
            // Mark every module reachable from the entry through only
            // eager (non-defer) edges. Modules NOT in this set are
            // imported transitively via at least one defer edge and
            // wait for their deferred-namespace's first MOP access
            // before evaluation.
            $this->markEagerlyReachable($resolved);
            // Clear the linking flag before draining so dynamic imports
            // issued from a body can start their own link/validate/drain
            // cycles.
            $this->linking = false;
            $this->drainBodyQueue();
            return $namespace;
        } finally {
            $this->linking = false;
        }
    }

    /**
     * BFS from $entryPath through eager edges; mark each visited
     * record's eagerlyReachable flag. Idempotent so dynamic imports
     * (which call into the same entry-point handler) can re-run it
     * after adding new edges.
     */
    private function markEagerlyReachable(string $entryPath): void
    {
        $entry = $this->modules[$entryPath] ?? null;
        if ($entry === null) {
            return;
        }
        $queue = [$entryPath];
        $visited = [];
        while ($queue !== []) {
            $path = array_shift($queue);
            if (isset($visited[$path])) {
                continue;
            }
            $visited[$path] = true;
            $rec = $this->modules[$path] ?? null;
            if ($rec === null) {
                continue;
            }
            $rec->eagerlyReachable = true;
            foreach (array_keys($rec->eagerEdges) as $next) {
                if (!isset($visited[$next])) {
                    $queue[] = $next;
                }
            }
        }
    }

    /**
     * Evaluate module source text and return its namespace object.
     * Kept as a thin wrapper around linkModule + drainBodyQueue for callers
     * that bypass loadModule (e.g. the test runner that already holds source).
     */
    public function evaluateModule(string $absolutePath, string $source): JsObject
    {
        if (isset($this->modules[$absolutePath])) {
            return $this->modules[$absolutePath]->namespace;
        }

        if ($this->linking) {
            return $this->linkModule($absolutePath, $source);
        }

        $this->linking = true;
        try {
            $namespace = $this->linkModule($absolutePath, $source);
            $this->finalizeStarReExports();
            $this->lockDownNamespaces();
            $this->validateIndirectExports();
            $this->markEagerlyReachable($absolutePath);
            $this->linking = false;
            $this->drainBodyQueue();
            return $namespace;
        } finally {
            $this->linking = false;
        }
    }

    /**
     * Parse a module, populate its namespace with export bindings, resolve
     * its imports against dependency namespaces, and queue its body for
     * later execution. Does NOT execute the body. The caller is responsible
     * for draining the body queue at the top of the load graph.
     */
    private function linkModule(string $absolutePath, string $source): JsObject
    {
        if (isset($this->modules[$absolutePath])) {
            return $this->modules[$absolutePath]->namespace;
        }

        // Create the namespace object early for circular dependency handling.
        $namespace = new JsObject(null);

        $record = new ModuleRecord($absolutePath, $namespace);
        $this->modules[$absolutePath] = $record;

        // JSON modules: per the import attributes / json-modules proposal,
        // a .json source becomes a synthetic module whose only export is
        // `default`, holding the parsed JSON value.
        if (str_ends_with(strtolower($absolutePath), '.json')) {
            $parsed = \PhpJs\BuiltIn\JsonObject::parseSource($source);
            $namespace->defineOwnProperty(
                'default',
                PropertyDescriptor::data($parsed, false, true, false),
            );
            $namespace->setPrototype(null);
            $toStringTagSym = \PhpJs\BuiltIn\SymbolConstructor::toStringTag();
            $namespace->definePropertyBySymbol(
                $toStringTagSym,
                PropertyDescriptor::data(new JsString('Module'), false, false, false),
            );
            $record->exportsFinalized = true;
            return $namespace;
        }

        // Detect circular dependency.
        if (isset($this->evaluating[$absolutePath])) {
            return $namespace;
        }
        $this->evaluating[$absolutePath] = true;

        $prevModulePath = $this->interpreter->getCurrentModulePath();
        try {
            $parser = new Parser($source);
            $parser->setModuleMode(true);
            $program = $parser->parse();

            // Create a fresh module environment linked to the global environment.
            $moduleEnv = new Environment($this->globalEnv);
            $this->interpreter->setCurrentModulePath($absolutePath);

            // Pre-hoist function/var declarations and TDZ bindings into
            // moduleEnv, then install live namespace bindings for all exports.
            // This makes hoisted exports visible in the module's own namespace
            // before imports are resolved, so same-module self-imports and
            // circular imports see them correctly per spec.
            $this->interpreter->hoistModuleDeclarations($program->body, $moduleEnv);
            // Per §16.2.1.6.2 InitializeEnvironment step 9 and §16.2.1.5.2
            // InnerModuleLinking: requested modules are resolved in
            // source-text order so body evaluation follows the post-order
            // traversal of the requestedModules graph. Pre-load dependencies
            // here so the body queue order reflects source order, regardless
            // of whether each reference is an import or export-from.
            $this->preloadRequestedModules($program->body, $absolutePath);
            $this->finalizeExports($program->body, $moduleEnv, $absolutePath, $namespace);
            $record->exportsFinalized = true;

            // Per §16.2.1.9 GetModuleNamespace: names that are ambiguously
            // exported via multiple `export *` sources are excluded from the
            // namespace. Remove any bindings we provisionally installed for
            // such names.
            foreach (array_keys($record->ambiguousNames) as $ambiguousName) {
                // Indirect bindings are non-configurable, so use forceDelete
                // to bypass the configurability check.
                $namespace->forceDelete($ambiguousName);
            }

            // Install Symbol.toStringTag = "Module". markAsModuleNamespace
            // and preventExtensions are deferred to lockDownNamespaces so
            // finalizeStarReExports can still add indirect bindings after
            // the graph is fully linked. Module namespace exotic
            // [[DefineOwnProperty]] rejects new properties, so the flag must
            // not be set while we are still building the namespace.
            $namespace->setPrototype(null);
            $toStringTagSym = \PhpJs\BuiltIn\SymbolConstructor::toStringTag();
            $namespace->definePropertyBySymbol(
                $toStringTagSym,
                PropertyDescriptor::data(new JsString('Module'), false, false, false),
            );

            // Resolve imports against now-populated namespace accessors.
            $this->processDeclarations($program->body, $moduleEnv, $absolutePath, $namespace);

            // Queue the body for later execution. Post-order against dependency
            // links is approximated by the order linkModule completes linking.
            $record->pendingBody = [
                'program' => $program,
                'moduleEnv' => $moduleEnv,
                'prevModulePath' => $prevModulePath,
            ];
            $this->bodyQueue[] = $absolutePath;
        } finally {
            unset($this->evaluating[$absolutePath]);
            // Restore prior module path so the enclosing module continues to
            // resolve relative paths against its own location during linking.
            $this->interpreter->setCurrentModulePath($prevModulePath);
        }

        return $namespace;
    }

    /**
     * Execute every module body that was queued by linkModule, in link order.
     * Each body runs with its own moduleEnv; the interpreter's currentModulePath
     * is set to the module's path before the body runs and restored after.
     */
    private function drainBodyQueue(): void
    {
        $asyncRecords = [];
        while ($this->bodyQueue !== []) {
            $path = array_shift($this->bodyQueue);
            $record = $this->modules[$path] ?? null;
            if ($record === null || $record->pendingBody === null) {
                continue;
            }
            // Skip modules that aren't eagerly reachable from the
            // entry point. Their bodies wait for the first MOP access
            // on their deferred namespace (per import-defer Stage 3
            // evaluation triggers).
            if (!$record->eagerlyReachable) {
                continue;
            }
            // Per spec InnerModuleEvaluation, before evaluating M, every
            // async dependency in M's import graph must have settled.
            $this->awaitAsyncDeps($record);
            $pending = $record->pendingBody;
            $record->pendingBody = null;
            $record->bodyEvaluated = true;
            $prevModulePath = $this->interpreter->getCurrentModulePath();
            $this->interpreter->setCurrentModulePath($path);
            try {
                $this->interpreter->executeModuleBody(
                    $pending['program']->body,
                    $pending['moduleEnv'],
                    alreadyHoisted: true,
                    onAsyncStart: function (\PhpJs\Value\JsPromise $p) use ($record, &$asyncRecords): void {
                        $record->evaluationPromise = $p;
                        $asyncRecords[] = $record;
                    },
                );
            } finally {
                $this->interpreter->setCurrentModulePath($prevModulePath);
            }
        }
        // Drain remaining async-module promises (any module without an
        // importer still pending). Bound the loop so a never-resolving
        // promise can't hang the engine.
        if ($asyncRecords !== []) {
            $iter = 0;
            $allSettled = false;
            while (!$allSettled && $iter++ < 100000) {
                \PhpJs\Value\JsPromise::drainMicrotasks();
                $allSettled = true;
                foreach ($asyncRecords as $r) {
                    $p = $r->evaluationPromise;
                    if ($p !== null && $p->getState() === \PhpJs\Value\JsPromise::STATE_PENDING) {
                        $allSettled = false;
                        break;
                    }
                }
            }
            // Surface the first rejection as a thrown JS error so the
            // caller of loadModule sees the failure.
            foreach ($asyncRecords as $r) {
                $p = $r->evaluationPromise;
                if ($p !== null && $p->getState() === \PhpJs\Value\JsPromise::STATE_REJECTED) {
                    $this->interpreter->throwJsValue($p->getResolvedValue());
                }
            }
        }
    }

    /**
     * Drain microtasks until every async dependency reachable from the
     * given record has settled. Works against the transitive closure
     * of importedPaths so a chain main → A → B(async) blocks main on
     * B's promise even though main only directly imports A.
     */
    /**
     * Public entry point used by dynamic `import.defer(...)` and the
     * static-import processor. Returns a deferred-namespace exotic
     * for the resolved module, creating it the first time a deferred
     * import asks for it. The returned object has the same exports as
     * the module's evaluation namespace, but with an "Deferred Module"
     * @@toStringTag and a stable identity across deferred imports.
     */
    public function getDeferredNamespaceFor(string $specifier, ?string $referrer = null): JsObject
    {
        $resolved = $this->resolve($specifier, $referrer);
        $eagerNs = $this->loadModule($specifier, $referrer);
        return $this->getDeferredNamespace($resolved, $eagerNs);
    }

    private function getDeferredNamespace(string $resolved, JsObject $eagerNs): JsObject
    {
        $record = $this->modules[$resolved] ?? null;
        if ($record === null) {
            return $eagerNs;
        }
        if ($record->deferredNamespace !== null) {
            return $record->deferredNamespace;
        }
        // Per spec ModuleNamespaceCreate with phase ~defer~: the
        // deferred namespace has its own identity (distinct from the
        // eager namespace) and Symbol.toStringTag = "Deferred Module".
        // We model lazy evaluation via JsDeferredModuleNamespace, whose
        // observable MOP traps fire a one-shot trigger that runs the
        // module body before returning.
        $self = $this;
        $deferred = new \PhpJs\Value\JsDeferredModuleNamespace(
            function (\PhpJs\Value\JsDeferredModuleNamespace $ns) use ($self, $record): void {
                // Run the module body if it has not already been
                // evaluated by an eager importer. The export-binding
                // accessors copied at construction time read live
                // values from the source moduleEnv, so the
                // post-evaluation state surfaces automatically without
                // any further mirror pass.
                if (!$record->bodyEvaluated) {
                    $self->evaluateModuleBodyOnDemand($record);
                }
            },
        );
        $deferred->setEagerNamespace($eagerNs);
        // Mirror the eager namespace's export-binding accessors so the
        // deferred namespace exposes the same keys with live access to
        // the underlying moduleEnv bindings. We use the raw stored
        // descriptors (not the materialized form) so accessors stay
        // accessors and reflect post-evaluation values. Bypass the
        // lazy-eval trigger during this construction-time mirror so
        // we don't accidentally evaluate the module while wiring up
        // its own deferred namespace.
        $deferred->setBypassTrigger(true);
        foreach ($eagerNs->getOwnPropertyNames() as $name) {
            $rawDesc = $eagerNs->getOwnPropertyDescriptorRaw($name);
            if ($rawDesc === null) {
                continue;
            }
            $deferred->defineOwnProperty($name, clone $rawDesc);
        }
        $deferred->setBypassTrigger(false);
        // Stamp Symbol.toStringTag and prevent extensions per spec
        // ModuleNamespaceCreate with phase = ~defer~.
        $deferred->definePropertyBySymbol(
            \PhpJs\BuiltIn\SymbolConstructor::toStringTag(),
            \PhpJs\Object\PropertyDescriptor::data(
                new \PhpJs\Value\JsString('Deferred Module'),
                false,
                false,
                false,
            ),
        );
        $deferred->markAsModuleNamespace();
        $deferred->preventExtensions();
        $record->deferredNamespace = $deferred;
        return $deferred;
    }

    /**
     * Run a previously-skipped (deferred-only) module's body now,
     * matching the spec's lazy evaluation trigger. Handles top-level
     * await by draining microtasks until the body's promise settles.
     */
    public function evaluateModuleBodyOnDemand(ModuleRecord $record): void
    {
        if ($record->bodyEvaluated || $record->pendingBody === null) {
            $record->bodyEvaluated = true;
            return;
        }
        // First, evaluate any of this module's EAGER dependencies that
        // we skipped during the top-level drain. We must NOT
        // transitively evaluate deferred edges — those wait for their
        // own MOP-trigger.
        foreach (array_keys($record->eagerEdges) as $depPath) {
            $dep = $this->modules[$depPath] ?? null;
            if ($dep !== null && !$dep->bodyEvaluated && $dep->pendingBody !== null) {
                $this->evaluateModuleBodyOnDemand($dep);
            }
        }
        $pending = $record->pendingBody;
        $record->pendingBody = null;
        $record->bodyEvaluated = true;
        $prevModulePath = $this->interpreter->getCurrentModulePath();
        $this->interpreter->setCurrentModulePath($record->path);
        try {
            $this->interpreter->executeModuleBody(
                $pending['program']->body,
                $pending['moduleEnv'],
                alreadyHoisted: true,
                onAsyncStart: function (\PhpJs\Value\JsPromise $p) use ($record): void {
                    $record->evaluationPromise = $p;
                    // Drain microtasks until this module's TLA promise
                    // settles. Lazy triggers run synchronously from the
                    // host's perspective so the consumer of the
                    // deferred namespace sees fully-evaluated exports.
                    $iter = 0;
                    while ($p->getState() === \PhpJs\Value\JsPromise::STATE_PENDING && $iter++ < 100000) {
                        \PhpJs\Value\JsPromise::drainMicrotasks();
                    }
                    if ($p->getState() === \PhpJs\Value\JsPromise::STATE_REJECTED) {
                        $this->interpreter->throwJsValue($p->getResolvedValue());
                    }
                },
            );
        } finally {
            $this->interpreter->setCurrentModulePath($prevModulePath);
        }
    }

    private function awaitAsyncDeps(ModuleRecord $record): void
    {
        $deps = $this->collectAsyncDeps($record, []);
        if ($deps === []) {
            return;
        }
        $iter = 0;
        while ($iter++ < 100000) {
            $allSettled = true;
            foreach ($deps as $dep) {
                $p = $dep->evaluationPromise;
                if ($p !== null && $p->getState() === \PhpJs\Value\JsPromise::STATE_PENDING) {
                    $allSettled = false;
                    break;
                }
            }
            if ($allSettled) {
                break;
            }
            \PhpJs\Value\JsPromise::drainMicrotasks();
        }
        // A rejected dependency must propagate immediately: per spec
        // 16.2.1.5 ContinueDynamicImport / InnerModuleEvaluation, a
        // module whose async dep rejected itself rejects with the same
        // value, and importers throw rather than evaluating their
        // bodies.
        foreach ($deps as $dep) {
            $p = $dep->evaluationPromise;
            if ($p !== null && $p->getState() === \PhpJs\Value\JsPromise::STATE_REJECTED) {
                $this->interpreter->throwJsValue($p->getResolvedValue());
            }
        }
    }

    /**
     * Walk importedPaths transitively and collect every record that has
     * an outstanding evaluation promise. Avoids re-entering already
     * visited records to terminate on cyclic imports.
     *
     * @param array<string, true> $visited
     * @return list<ModuleRecord>
     */
    private function collectAsyncDeps(ModuleRecord $record, array $visited): array
    {
        $result = [];
        foreach (array_keys($record->importedPaths) as $path) {
            if (isset($visited[$path])) {
                continue;
            }
            $visited[$path] = true;
            $dep = $this->modules[$path] ?? null;
            if ($dep === null) {
                continue;
            }
            if ($dep->evaluationPromise !== null) {
                $result[] = $dep;
            }
            foreach ($this->collectAsyncDeps($dep, $visited) as $nested) {
                $result[] = $nested;
            }
        }
        return $result;
    }

    /**
     * Prevent extensions on every module namespace in the graph. Called
     * after finalizeStarReExports so all star re-export bindings are
     * installed before the namespace is frozen.
     */
    private function lockDownNamespaces(): void
    {
        foreach ($this->modules as $record) {
            $record->namespace->markAsModuleNamespace();
            $record->namespace->preventExtensions();
        }
    }

    /**
     * Per §16.2.1.6.2 GetExportedNames with cycle-safe star traversal:
     * for each module with `export * from 'src'` requests, copy each
     * re-exported name (minus default) into its namespace as an indirect
     * binding resolved via the source namespace. Runs after the whole
     * module graph is linked, so partial-namespace snapshots during
     * cycles no longer matter. Names contributed by multiple distinct
     * sources become ambiguous and are removed from the namespace.
     */
    private function finalizeStarReExports(): void
    {
        foreach ($this->modules as $path => $record) {
            if ($record->starExportRequests === []) {
                continue;
            }
            foreach ($record->starExportRequests as $sourcePath) {
                $names = $this->getExportedNames($sourcePath, [$path => true]);
                foreach ($names as $name) {
                    if ($name === 'default') {
                        continue;
                    }
                    // Direct exports and explicit indirect re-exports of
                    // this module win over star re-exports.
                    if (
                        $record->namespace->hasOwnProperty($name)
                        && !isset($record->starExportSources[$name])
                    ) {
                        continue;
                    }
                    if (isset($record->ambiguousNames[$name])) {
                        continue;
                    }
                    if (isset($record->starExportSources[$name])) {
                        if ($record->starExportSources[$name] !== $sourcePath) {
                            $record->ambiguousNames[$name] = true;
                            $record->namespace->forceDelete($name);
                        }
                        continue;
                    }
                    $sourceRecord = $this->modules[$sourcePath] ?? null;
                    if ($sourceRecord === null) {
                        continue;
                    }
                    $record->starExportSources[$name] = $sourcePath;
                    $this->installIndirectBinding(
                        $record->namespace,
                        $name,
                        $sourceRecord->namespace,
                        $name,
                    );
                }
            }
        }
    }

    /**
     * Cycle-aware GetExportedNames (§16.2.1.6.2). Returns the list of names
     * a module exposes (direct exports + transitive star re-exports).
     *
     * @param array<string, true> $exportStarSet Modules already visited in this traversal.
     * @return list<string>
     */
    private function getExportedNames(string $modulePath, array $exportStarSet): array
    {
        if (isset($exportStarSet[$modulePath])) {
            return [];
        }
        $exportStarSet[$modulePath] = true;
        $record = $this->modules[$modulePath] ?? null;
        if ($record === null) {
            return [];
        }
        $names = [];
        foreach ($record->namespace->getOwnPropertyNames() as $name) {
            $names[$name] = true;
        }
        foreach (array_keys($record->indirectExports) as $name) {
            $names[$name] = true;
        }
        foreach ($record->starExportRequests as $source) {
            foreach ($this->getExportedNames($source, $exportStarSet) as $n) {
                if ($n === 'default') {
                    continue;
                }
                $names[$n] = true;
            }
        }
        return array_keys($names);
    }

    /**
     * Per §16.2.1.6.3 ResolveExport: validate that every indirect re-export
     * entry across the linked module graph resolves to a concrete binding
     * (not null, not ambiguous). Runs once after the entire dependency graph
     * is linked, before any body executes.
     */
    private function validateIndirectExports(): void
    {
        foreach ($this->modules as $path => $record) {
            foreach ($record->indirectExports as $exportName => $_) {
                $resolution = $this->resolveExport($path, $exportName, []);
                if ($resolution === null) {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Indirect re-export '{$exportName}' in module '{$path}' does not resolve",
                    );
                }
                if ($resolution === 'ambiguous') {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Indirect re-export '{$exportName}' in module '{$path}' is ambiguous",
                    );
                }
            }
            foreach ($record->importEntries as $entry) {
                $resolution = $this->resolveExport($entry['source'], $entry['exportName'], []);
                if ($resolution === null) {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Module '{$entry['source']}' does not export '{$entry['exportName']}'",
                    );
                }
                if ($resolution === 'ambiguous') {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Ambiguous import '{$entry['exportName']}' from '{$entry['source']}'",
                    );
                }
            }
        }
    }

    /**
     * Spec §16.2.1.6.3 ResolveExport.
     * Returns ['module' => path, 'bindingName' => name] for a resolved
     * binding, 'ambiguous' if the name is reached through multiple
     * distinct sources, or null if the name cannot be resolved (including
     * cyclic re-exports with no concrete origin).
     *
     * @param list<array{module:string, exportName:string}> $resolveSet
     * @return array{module:string,bindingName:string}|'ambiguous'|null
     */
    private function resolveExport(string $modulePath, string $exportName, array $resolveSet): array|string|null
    {
        foreach ($resolveSet as $r) {
            if ($r['module'] === $modulePath && $r['exportName'] === $exportName) {
                return null;
            }
        }
        $resolveSet[] = ['module' => $modulePath, 'exportName' => $exportName];

        $record = $this->modules[$modulePath] ?? null;
        if ($record === null) {
            return null;
        }

        // Indirect re-export: recurse through the source module.
        if (isset($record->indirectExports[$exportName])) {
            $entry = $record->indirectExports[$exportName];
            return $this->resolveExport($entry['source'], $entry['localName'], $resolveSet);
        }

        // Direct export on this module: the namespace carries a live accessor
        // whose source module is this one. Distinguish from indirect bindings
        // copied in via star-re-exports by consulting starExportSources.
        if (
            $record->namespace->hasOwnProperty($exportName)
            && !isset($record->starExportSources[$exportName])
        ) {
            return ['module' => $modulePath, 'bindingName' => $exportName];
        }

        // Star re-exports: search transitively and detect ambiguity.
        $starResolution = null;
        foreach ($record->starExportRequests as $starSource) {
            if ($exportName === 'default') {
                continue;
            }
            $candidate = $this->resolveExport($starSource, $exportName, $resolveSet);
            if ($candidate === null) {
                continue;
            }
            if ($candidate === 'ambiguous') {
                return 'ambiguous';
            }
            if ($starResolution === null) {
                $starResolution = $candidate;
                continue;
            }
            if (
                $starResolution['module'] !== $candidate['module']
                || $starResolution['bindingName'] !== $candidate['bindingName']
            ) {
                return 'ambiguous';
            }
        }
        return $starResolution;
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
     * Pre-load every module referenced by an import or export-from
     * declaration, in source-code order. Ensures the body queue is
     * populated in the order required by the spec's post-DFS evaluation
     * traversal of [[RequestedModules]].
     *
     * @param Node[] $body
     */
    private function preloadRequestedModules(array $body, string $modulePath): void
    {
        $currentRecord = $this->modules[$modulePath] ?? null;
        foreach ($body as $node) {
            $source = null;
            $isDefer = false;
            if ($node instanceof ImportDeclaration) {
                $source = $node->source;
                $isDefer = ($node->phase) === 'defer';
            } elseif ($node instanceof ExportDeclaration && $node->source !== null) {
                $source = $node->source;
            }
            if ($source === null) {
                continue;
            }
            $this->loadModule($source, $modulePath);
            $resolved = $this->resolve($source, $modulePath);
            if ($currentRecord !== null) {
                $currentRecord->importedPaths[$resolved] = true;
            }
            // Track per-importer phase so the drain pass knows which
            // modules were reached only via deferred importers; their
            // bodies wait for first MOP access on the deferred
            // namespace per the import-defer proposal.
            $depRecord = $this->modules[$resolved] ?? null;
            if ($depRecord !== null) {
                if ($isDefer) {
                    $depRecord->deferredImporterCount++;
                } else {
                    $depRecord->eagerImporterCount++;
                }
            }
            // Record outgoing eager edges for the post-link reachability
            // pass. Eager edges propagate "needs-eager-evaluation" from
            // the entry module to its dependencies.
            if ($currentRecord !== null && !$isDefer) {
                $currentRecord->eagerEdges[$resolved] = true;
            }
        }
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
        $resolved = $this->resolve($node->source, $modulePath);
        $currentRecord = $this->modules[$modulePath] ?? null;
        $isDefer = ($node->phase) === 'defer';

        foreach ($node->specifiers as $spec) {
            if ($spec->specType === 'namespace') {
                // Namespace imports snapshot the namespace object itself,
                // or the deferred-namespace exotic if the import phase is
                // ~defer~.
                $bindingNs = $isDefer
                    ? $this->getDeferredNamespace($resolved, $importedNs)
                    : $importedNs;
                $moduleEnv->defineConst($spec->local, $bindingNs);
                continue;
            }
            // Install a live indirect binding that resolves the imported name
            // on each read. This matches spec semantics for import bindings
            // (live, read-only) and supports circular/self imports and TDZ.
            $exportName = $spec->specType === 'default'
                ? 'default'
                : ($spec->imported ?? $spec->local);
            // Record the entry so validateImports can run ResolveExport
            // after the whole graph is linked (including star re-exports
            // contributed by finalizeStarReExports). A hasOwnProperty check
            // here would race with star-re-export installation.
            if ($currentRecord !== null) {
                $currentRecord->importEntries[] = [
                    'source' => $resolved,
                    'exportName' => $exportName,
                ];
            }
            $resolver = static function () use ($importedNs, $exportName): JsValue {
                return $importedNs->get($exportName);
            };
            $moduleEnv->defineImportBinding($spec->local, $resolver);
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
                // Named function/class default exports use a live binding
                // keyed on the declared name. Avoid reading the value now
                // (the class binding may still be in TDZ at install time).
                if ($node->declaration instanceof FunctionDeclaration && $node->declaration->id !== null) {
                    $bindingName = $node->declaration->id->name;
                    $this->installLiveBinding($namespace, 'default', $moduleEnv, $bindingName);
                } elseif ($node->declaration instanceof ClassDeclaration && $node->declaration->id !== null) {
                    $bindingName = $node->declaration->id->name;
                    $this->installLiveBinding($namespace, 'default', $moduleEnv, $bindingName);
                } else {
                    // Anonymous default export (function/class) or expression.
                    // Install a live binding pointing at a synthetic slot in
                    // moduleEnv that will be populated during body execution.
                    $slotName = '__default__';
                    if (!$moduleEnv->hasOwnBinding($slotName)) {
                        $moduleEnv->declareLet($slotName);
                    }
                    $this->installLiveBinding($namespace, 'default', $moduleEnv, $slotName);
                }
            }
            return;
        }

        // export * from 'source' or export * as ns from 'source'
        if ($node->isAll && $node->source !== null) {
            $reExportNs = $this->loadModule($node->source, $modulePath);
            $sourceResolved = $this->resolve($node->source, $modulePath);
            $currentRecord = $this->modules[$modulePath] ?? null;
            if ($node->allAs !== null) {
                $namespace->defineOwnProperty(
                    $node->allAs,
                    PropertyDescriptor::data($reExportNs, true, true, false),
                );
                return;
            }
            // Record the star-re-export for later resolution. Populating the
            // namespace here would snapshot the source's partial namespace
            // during cycles and miss names added by later links. The actual
            // indirect bindings are installed by finalizeStarReExports once
            // the whole graph is linked.
            if ($currentRecord !== null) {
                $currentRecord->starExportRequests[] = $sourceResolved;
            }
            return;
        }

        // Per §16.2.2.2: `export {} from 'src'` with no specifiers still
        // contributes to [[RequestedModules]], so the source module must
        // be loaded (triggering its parse/resolution errors).
        if ($node->source !== null && $node->specifiers === []) {
            $this->loadModule($node->source, $modulePath);
            return;
        }

        // export { a, b as c } from 'source'
        if ($node->source !== null && $node->specifiers !== []) {
            $reExportNs = $this->loadModule($node->source, $modulePath);
            $resolved = $this->resolve($node->source, $modulePath);
            $currentRecord = $this->modules[$modulePath] ?? null;
            foreach ($node->specifiers as $spec) {
                // Record the indirect export entry so ResolveExport can
                // validate it across the fully-linked graph. Graph-wide
                // validation catches circular re-exports with no concrete
                // origin, which a local hasOwnProperty check cannot detect
                // because indirect bindings are always installed lazily.
                if ($currentRecord !== null) {
                    $currentRecord->indirectExports[$spec->exported] = [
                        'source' => $resolved,
                        'localName' => $spec->local,
                    ];
                }
                $this->installIndirectBinding($namespace, $spec->exported, $reExportNs, $spec->local);
            }
            return;
        }

        // export { a, b as c }
        if ($node->specifiers !== []) {
            foreach ($node->specifiers as $spec) {
                $this->installLiveBinding($namespace, $spec->exported, $moduleEnv, $spec->local);
            }
            return;
        }

        // export var/let/const/function/class
        if ($node->declaration !== null) {
            $names = $this->getDeclarationNames($node->declaration);
            foreach ($names as $name) {
                $this->installLiveBinding($namespace, $name, $moduleEnv, $name);
            }
        }
    }

    /**
     * Install a live binding on a namespace object that reads from the module environment.
     * This allows mutations to the binding in the module to be reflected when accessed.
     */
    private function installLiveBinding(
        JsObject $namespace,
        string $exportName,
        Environment $env,
        string $bindingName,
    ): void {
        $getter = JsFunction::fromCallable(
            'get ' . $exportName,
            static function (JsValue $this_, array $args) use ($env, $bindingName): JsValue {
                return $env->has($bindingName)
                    ? $env->get($bindingName)
                    : JsUndefined::instance();
            },
            0,
        );
        $namespace->defineOwnProperty(
            $exportName,
            PropertyDescriptor::accessor(
                get: $getter,
                set: null,
                enumerable: true,
                configurable: false,
            ),
        );
    }

    /**
     * Install an indirect binding that reads from another namespace object.
     * Used for re-exports (export { x } from 'other').
     */
    private function installIndirectBinding(
        JsObject $namespace,
        string $exportName,
        JsObject $sourceNamespace,
        string $sourceName,
    ): void {
        $getter = JsFunction::fromCallable(
            'get ' . $exportName,
            static function (JsValue $this_, array $args) use ($sourceNamespace, $sourceName): JsValue {
                return $sourceNamespace->get($sourceName);
            },
            0,
        );
        $namespace->defineOwnProperty(
            $exportName,
            PropertyDescriptor::accessor(
                get: $getter,
                set: null,
                enumerable: true,
                configurable: false,
            ),
        );
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
                $names = array_merge($names, $this->collectBindingPatternNames($decl->id));
            }
            return $names;
        }
        return [];
    }

    /**
     * Collect identifier names from a binding pattern (or simple identifier).
     *
     * @return string[]
     */
    private function collectBindingPatternNames(Node $pattern): array
    {
        if ($pattern instanceof \PhpJs\Ast\Expression\Identifier) {
            return [$pattern->name];
        }
        if ($pattern instanceof \PhpJs\Ast\Pattern\ArrayPattern) {
            $out = [];
            foreach ($pattern->elements as $elem) {
                if ($elem !== null) {
                    $out = array_merge($out, $this->collectBindingPatternNames($elem));
                }
            }
            return $out;
        }
        if ($pattern instanceof \PhpJs\Ast\Pattern\ObjectPattern) {
            $out = [];
            foreach ($pattern->properties as $p) {
                if ($p instanceof \PhpJs\Ast\Pattern\AssignmentProperty) {
                    $out = array_merge($out, $this->collectBindingPatternNames($p->value));
                } elseif ($p instanceof \PhpJs\Ast\Pattern\RestElement) {
                    $out = array_merge($out, $this->collectBindingPatternNames($p->argument));
                }
            }
            return $out;
        }
        if ($pattern instanceof \PhpJs\Ast\Pattern\AssignmentPattern) {
            return $this->collectBindingPatternNames($pattern->left);
        }
        if ($pattern instanceof \PhpJs\Ast\Pattern\RestElement) {
            return $this->collectBindingPatternNames($pattern->argument);
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
        $this->bodyQueue = [];
        $this->linking = false;
    }
}
