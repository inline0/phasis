<?php

declare(strict_types=1);

namespace Phasis\Module;

use Phasis\Ast\Declaration\ExportDeclaration;
use Phasis\Ast\Declaration\ExportSpecifier;
use Phasis\Ast\Declaration\FunctionDeclaration;
use Phasis\Ast\Declaration\ImportDeclaration;
use Phasis\Ast\Declaration\VariableDeclaration;
use Phasis\Ast\Declaration\ClassDeclaration;
use Phasis\Ast\Node;
use Phasis\Object\PropertyDescriptor;
use Phasis\Parser\Parser;
use Phasis\Runtime\Environment;
use Phasis\Runtime\Interpreter;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

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

    /** @var array<string, true> Set of paths currently inside an
     *  evaluateModuleBodyOnDemand call. Used as a cycle guard so a
     *  dynamic import that re-enters a cycle member doesn't recurse
     *  forever. Distinct from the bodyEvaluating flag, which is set
     *  for every eagerly-reachable module at the top of
     *  drainBodyQueue and would trigger a false positive here. */
    private array $onDemandStack = [];

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
            $cached = $this->modules[$resolved];
            // If a previous evaluation rejected, re-throw the same
            // value so subsequent callers observe the rejection per
            // EvaluateSync semantics.
            if ($cached->evaluationError !== null) {
                throw new \Phasis\Exceptions\JsThrowable($cached->evaluationError);
            }
            // A previously deferred-only module may have been linked
            // but its body never ran — its only importer was the
            // defer phase, so markEagerlyReachable skipped it. An
            // eager dynamic import must now evaluate the body so the
            // returned namespace reflects the post-evaluation state
            // (and any throw the body produces propagates to the
            // caller).
            //
            // A module whose body is already queued for the current
            // drain pass (bodyEvaluating == true) must NOT be force-
            // evaluated here — doing so preempts the spec-mandated DFS
            // order. The drain loop will reach it in source order,
            // and any pending dynamic-import promise resolves through
            // microtasks once the body has run.
            if (
                !$cached->bodyEvaluated
                && $cached->pendingBody !== null
                && !$this->linking
                && !$cached->bodyEvaluating
            ) {
                $this->evaluateModuleBodyOnDemand($cached);
                if ($cached->evaluationError !== null) {
                    throw new \Phasis\Exceptions\JsThrowable($cached->evaluationError);
                }
            }
            return $cached->namespace;
        }

        return $this->loadModuleInternal($resolved, $specifier);
    }

    /**
     * Variant of loadModule used by deferred-phase importers. If the
     * source module has already failed evaluation, returns the cached
     * namespace without re-throwing — the cached error surfaces later
     * when an observable Get on the deferred namespace fires
     * EnsureDeferredNamespaceEvaluation. If the module hasn't been
     * loaded yet, links it (which doesn't run its body during the
     * importer's link pass anyway, since defer-only edges are skipped
     * by markEagerlyReachable).
     */
    public function loadModuleForDeferred(string $specifier, ?string $referrer = null): JsObject
    {
        $resolved = $this->resolve($specifier, $referrer);
        if (isset($this->modules[$resolved])) {
            // Even if the module has a cached evaluation error, return
            // its namespace so the deferred-namespace exotic can mirror
            // its export shape. The error will be re-thrown by
            // ensureEvaluated() when an observable trap actually fires.
            return $this->modules[$resolved]->namespace;
        }
        return $this->loadModuleInternal($resolved, $specifier);
    }

    /**
     * Shared body of loadModule / loadModuleForDeferred for the
     * not-yet-cached path. Reads the source, links it, and (when this
     * is the outermost load call) drains the eager body queue.
     */
    private function loadModuleInternal(string $resolved, string $specifier): JsObject
    {
        $source = @file_get_contents($resolved);
        if ($source === false) {
            throw new \Phasis\Exceptions\TypeError("Cannot find module '{$specifier}'");
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
            $this->finalizeDeferredNamespaces();
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
     * Walk the entry module graph through eager edges; mark every
     * visited record's eagerlyReachable flag and emit the entries in
     * spec InnerModuleEvaluation order — depth-first, post-order, in
     * source order at each level. The drainer consumes the list so
     * defer-only edges leave their bodies pending while eager edges
     * evaluate before the importer that owned them, exactly as
     * GatherAsynchronousTransitiveDependencies prescribes for sync
     * graphs.
     *
     * Idempotent against duplicate dynamic imports — the visited set
     * is shared with subsequent calls in the same loadModule pass.
     */
    private function markEagerlyReachable(string $entryPath): void
    {
        if (!isset($this->modules[$entryPath])) {
            return;
        }
        $visited = [];
        $order = [];
        $this->walkEager($entryPath, $visited, $order);
        $this->bodyQueue = $order;
    }

    /**
     * @param array<string, true> $visited
     * @param list<string>        $order
     */
    private function walkEager(string $path, array &$visited, array &$order): void
    {
        if (isset($visited[$path])) {
            return;
        }
        $visited[$path] = true;
        $rec = $this->modules[$path] ?? null;
        if ($rec === null) {
            return;
        }
        $rec->eagerlyReachable = true;
        // Visit edges in source-text order so the resulting evaluation
        // list mirrors the spec's per-source-position interleaving of
        // eager and defer dependency traversal. For each defer edge we
        // hoist transitively-reachable TLA modules into the evaluation
        // list (per spec GatherAsynchronousTransitiveDependencies); the
        // deferred target itself stays unevaluated until its namespace
        // is observed.
        $edges = $rec->orderedEdges;
        if ($edges === []) {
            // Backwards-compatible fallback for any path that didn't
            // populate the ordered list (e.g. modules from a callsite
            // outside preloadRequestedModules).
            foreach (array_keys($rec->eagerEdges) as $next) {
                $edges[] = ['path' => $next, 'defer' => false];
            }
            foreach (array_keys($rec->deferEdges) as $next) {
                $edges[] = ['path' => $next, 'defer' => true];
            }
        }
        foreach ($edges as $edge) {
            $next = $edge['path'];
            if ($edge['defer'] === false) {
                $this->walkEager($next, $visited, $order);
                continue;
            }
            // Defer edge: gather TLA modules in the target's subtree
            // and walk each eagerly so their own synchronous deps run
            // before the TLA body evaluates.
            if (!isset($this->modules[$next])) {
                continue;
            }
            $gathered = [];
            $gatherSeen = [];
            $this->gatherAsyncTransitiveDeps($next, $gatherSeen, $gathered);
            foreach ($gathered as $tlaPath) {
                $this->walkEager($tlaPath, $visited, $order);
            }
        }
        $order[] = $path;
    }

    /**
     * Spec GatherAsynchronousTransitiveDependencies. Walks `path`'s
     * dependency graph (treating defer and eager edges identically,
     * mirroring [[RequestedModules]] regardless of phase) and returns
     * every module that hits TLA. Stops descending past a TLA module
     * — its synchronous deps are reached when walkEager consumes the
     * gathered path.
     *
     * @param array<string, true> $seen
     * @param list<string>        $result
     */
    private function gatherAsyncTransitiveDeps(string $path, array &$seen, array &$result): void
    {
        if (isset($seen[$path])) {
            return;
        }
        $seen[$path] = true;
        $rec = $this->modules[$path] ?? null;
        if ($rec === null) {
            return;
        }
        if ($rec->hasTopLevelAwait) {
            if (!in_array($path, $result, true)) {
                $result[] = $path;
            }
            return;
        }
        foreach (array_keys($rec->eagerEdges) as $next) {
            $this->gatherAsyncTransitiveDeps($next, $seen, $result);
        }
        foreach (array_keys($rec->deferEdges) as $next) {
            $this->gatherAsyncTransitiveDeps($next, $seen, $result);
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
            $this->finalizeDeferredNamespaces();
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
            $parsed = \Phasis\BuiltIn\JsonObject::parseSource($source);
            $namespace->defineOwnProperty(
                'default',
                PropertyDescriptor::data($parsed, false, true, false),
            );
            $namespace->setPrototype(null);
            $toStringTagSym = \Phasis\BuiltIn\SymbolConstructor::toStringTag();
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

            // Detect top-level await before linking dependencies. The
            // markEagerlyReachable walk needs the flag so a deferred
            // importer of a TLA module can promote the edge to eager
            // (per the import-defer proposal: TLA modules cannot be
            // deferred because EvaluateSync would have to wait for an
            // async settlement).
            $record->hasTopLevelAwait = $this->programHasTopLevelAwait($program->body);

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
            $toStringTagSym = \Phasis\BuiltIn\SymbolConstructor::toStringTag();
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
     * Execute every module body that was queued by linkModule, in link
     * order. Each body runs with its own moduleEnv; the interpreter's
     * currentModulePath is set to the module's path before the body runs
     * and restored after.
     *
     * Per spec InnerModuleEvaluation, a module whose [[PendingAsyncDependencies]]
     * is greater than zero — that is, any module whose transitive imports
     * include a TLA module not yet settled — does NOT run its body in the
     * synchronous pass. Its body is deferred until every async dependency
     * settles. This lets sibling sync modules later in source order run
     * in the correct sync slot rather than blocking on an unrelated TLA
     * dependency, which is what the import-defer flattening-order tests
     * verify.
     */
    private function drainBodyQueue(): void
    {
        $asyncRecords = [];
        // Modules whose body is awaiting one or more pending async deps.
        // Each entry holds the record plus the closure that runs the body.
        // Tracked separately from the synchronous run-list so deferred
        // bodies fire only after their dep promises settle.
        $deferredBodies = [];
        // Per spec InnerModuleEvaluation: a module's [[Status]] becomes
        // ~evaluating~ the moment it is pushed onto the evaluation
        // stack, BEFORE its dependencies' bodies execute. Mark every
        // eagerly-reachable module as evaluating up front so a
        // deferred-namespace observed during a sibling body sees the
        // expected ~evaluating~ status and throws TypeError per
        // EnsureDeferredNamespaceEvaluation. The flag is cleared in
        // runDeferredBody's finally block when the body completes (or,
        // for TLA modules, when the body suspends — the namespace then
        // checks the pending evaluation promise instead).
        foreach ($this->bodyQueue as $queuedPath) {
            $rec = $this->modules[$queuedPath] ?? null;
            if ($rec !== null && $rec->eagerlyReachable && $rec->pendingBody !== null) {
                $rec->bodyEvaluating = true;
            }
        }
        while ($this->bodyQueue !== []) {
            $path = array_shift($this->bodyQueue);
            $record = $this->modules[$path] ?? null;
            if ($record === null || $record->pendingBody === null) {
                continue;
            }
            if (!$record->eagerlyReachable) {
                continue;
            }
            $pendingDeps = $this->collectPendingAsyncDeps($record);
            if ($pendingDeps === []) {
                $this->runModuleBody($record, $asyncRecords);
                continue;
            }
            // Module has at least one async dep that hasn't settled.
            // Capture body + env for later; register a continuation on
            // the dep promises so the body runs as soon as the last
            // pending dep settles. Mark pendingBody as "claimed" by
            // nulling it but preserving the captured snapshot.
            $pending = $record->pendingBody;
            $record->pendingBody = null;
            $record->bodyEvaluated = true;
            $deferredBodies[] = [
                'record' => $record,
                'pending' => $pending,
            ];
        }
        // Run deferred bodies in source order, draining microtasks
        // between attempts so each TLA dep can settle and unblock its
        // dependents. Modules whose deps reject re-throw the same
        // value, mirroring spec AsyncModuleExecutionRejected.
        if ($deferredBodies !== []) {
            $iter = 0;
            while ($deferredBodies !== [] && $iter++ < 100000) {
                \Phasis\Value\JsPromise::drainMicrotasks();
                $progress = false;
                $remaining = [];
                foreach ($deferredBodies as $entry) {
                    $rec = $entry['record'];
                    $pendingDeps = $this->collectPendingAsyncDeps($rec);
                    // If any dep rejected, propagate that rejection
                    // immediately to this module's evaluationError.
                    $rejectedDep = $this->firstRejectedDep($rec);
                    if ($rejectedDep !== null) {
                        $rec->evaluationError = $rejectedDep;
                        $progress = true;
                        continue;
                    }
                    if ($pendingDeps === []) {
                        $this->runDeferredBody($rec, $entry['pending'], $asyncRecords);
                        $progress = true;
                        continue;
                    }
                    $remaining[] = $entry;
                }
                $deferredBodies = $remaining;
                if (!$progress && $deferredBodies !== []) {
                    // No deferred body became runnable this iteration.
                    // Either all pending TLA deps are still pending or
                    // the loop is genuinely deadlocked — either way the
                    // microtask drain is the only way forward.
                    \Phasis\Value\JsPromise::drainMicrotasks();
                }
            }
        }
        // Drain remaining async-module promises (any module without an
        // importer still pending). Bound the loop so a never-resolving
        // promise can't hang the engine.
        if ($asyncRecords !== []) {
            $iter = 0;
            $allSettled = false;
            while (!$allSettled && $iter++ < 100000) {
                \Phasis\Value\JsPromise::drainMicrotasks();
                $allSettled = true;
                foreach ($asyncRecords as $r) {
                    $p = $r->evaluationPromise;
                    if ($p !== null && $p->getState() === \Phasis\Value\JsPromise::STATE_PENDING) {
                        $allSettled = false;
                        break;
                    }
                }
            }
            // Surface the first rejection as a thrown JS error so the
            // caller of loadModule sees the failure.
            foreach ($asyncRecords as $r) {
                $p = $r->evaluationPromise;
                if ($p !== null && $p->getState() === \Phasis\Value\JsPromise::STATE_REJECTED) {
                    $this->interpreter->throwJsValue($p->getResolvedValue());
                }
            }
        }
    }

    /**
     * Collect every TLA module reachable from $record through its
     * import graph whose evaluation promise is still pending. An empty
     * result means the module's [[PendingAsyncDependencies]] is zero,
     * so its body may execute synchronously.
     *
     * @return list<ModuleRecord>
     */
    private function collectPendingAsyncDeps(ModuleRecord $record): array
    {
        $visited = [];
        $result = [];
        $this->walkPendingAsyncDeps($record, $visited, $result);
        return $result;
    }

    /**
     * @param array<string, true>  $visited
     * @param list<ModuleRecord>   $result
     */
    private function walkPendingAsyncDeps(ModuleRecord $record, array &$visited, array &$result): void
    {
        foreach (array_keys($record->importedPaths) as $depPath) {
            if (isset($visited[$depPath])) {
                continue;
            }
            $visited[$depPath] = true;
            $dep = $this->modules[$depPath] ?? null;
            if ($dep === null) {
                continue;
            }
            $p = $dep->evaluationPromise;
            if ($p !== null && $p->getState() === \Phasis\Value\JsPromise::STATE_PENDING) {
                $result[] = $dep;
            }
            // Recurse into the dep's own deps so a chain
            // M → A → tla still reports tla as pending for M.
            $this->walkPendingAsyncDeps($dep, $visited, $result);
        }
    }

    /**
     * Walk the importedPaths closure looking for the first dep whose
     * evaluation promise is rejected. Returns the rejected JsValue or
     * null if none has rejected yet.
     */
    private function firstRejectedDep(ModuleRecord $record): ?\Phasis\Value\JsValue
    {
        $visited = [];
        $stack = [$record];
        while ($stack !== []) {
            $current = array_pop($stack);
            foreach (array_keys($current->importedPaths) as $depPath) {
                if (isset($visited[$depPath])) {
                    continue;
                }
                $visited[$depPath] = true;
                $dep = $this->modules[$depPath] ?? null;
                if ($dep === null) {
                    continue;
                }
                if ($dep->evaluationError !== null) {
                    return $dep->evaluationError;
                }
                $p = $dep->evaluationPromise;
                if ($p !== null && $p->getState() === \Phasis\Value\JsPromise::STATE_REJECTED) {
                    return $p->getResolvedValue();
                }
                $stack[] = $dep;
            }
        }
        return null;
    }

    /**
     * Run a body whose pendingBody is still set on the record. Used
     * during the synchronous drain pass for modules with no pending
     * async deps.
     *
     * @param list<ModuleRecord> $asyncRecords
     */
    private function runModuleBody(ModuleRecord $record, array &$asyncRecords): void
    {
        $pending = $record->pendingBody;
        if ($pending === null) {
            return;
        }
        $record->pendingBody = null;
        $record->bodyEvaluated = true;
        $this->runDeferredBody($record, $pending, $asyncRecords);
    }

    /**
     * Execute a previously-captured body snapshot. Handles the sync
     * and TLA cases identically: the body either completes inline or
     * yields its evaluation promise back via the onAsyncStart hook.
     *
     * @param array{program: \Phasis\Ast\Program, moduleEnv: Environment, prevModulePath: ?string} $pending
     * @param list<ModuleRecord> $asyncRecords
     */
    private function runDeferredBody(ModuleRecord $record, array $pending, array &$asyncRecords): void
    {
        $record->bodyEvaluating = true;
        $prevModulePath = $this->interpreter->getCurrentModulePath();
        $this->interpreter->setCurrentModulePath($record->path);
        try {
            $this->interpreter->executeModuleBody(
                $pending['program']->body,
                $pending['moduleEnv'],
                alreadyHoisted: true,
                onAsyncStart: function (\Phasis\Value\JsPromise $p) use ($record, &$asyncRecords): void {
                    $record->evaluationPromise = $p;
                    $asyncRecords[] = $record;
                },
            );
        } catch (\Phasis\Exceptions\JsThrowable $e) {
            $record->evaluationError = $e->jsValue;
            throw $e;
        } finally {
            $this->interpreter->setCurrentModulePath($prevModulePath);
            $record->bodyEvaluating = false;
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
        // Link the module graph (and its eager dependencies) without
        // triggering body evaluation for the deferred root. We bypass
        // markEagerlyReachable for the requested module so its body
        // stays in pendingBody until first MOP access on the deferred
        // namespace fires the lazy trigger.
        $alreadyLoaded = isset($this->modules[$resolved]);
        $eagerNs = $this->loadModuleLinkOnly($specifier, $referrer);
        // Per ContinueDynamicImport with phase = ~defer~ (16.2.1.10.1),
        // before resolving the import.defer promise we run
        // GatherAsynchronousTransitiveDependencies on the requested
        // module and Evaluate every TLA module it returns. The
        // deferred root itself stays unevaluated unless it has TLA;
        // its non-TLA body waits for first MOP access on the deferred
        // namespace.
        $rootRecord = $this->modules[$resolved] ?? null;
        if ($rootRecord !== null) {
            $tlaModules = [];
            $seen = [];
            $this->gatherAsyncTransitiveDepsByRecord($rootRecord, $seen, $tlaModules);
            foreach ($tlaModules as $tlaRecord) {
                if ($tlaRecord->bodyEvaluated || $tlaRecord->pendingBody === null) {
                    continue;
                }
                $this->evaluateModuleBodyOnDemand($tlaRecord);
            }
        }
        // After linking, mark this module as deferred-only so the
        // drain pass does NOT evaluate its body. Eager dependencies
        // imported by it (via non-defer edges) ARE still reachable
        // through the original entry's eager graph if any.
        return $this->getDeferredNamespace($resolved, $eagerNs);
    }

    /**
     * Spec GatherAsynchronousTransitiveDependencies (13.3.10.1.1).
     * Walks the dependency graph of `record` (following both eager and
     * defer edges) and collects every TLA module reachable. Stops
     * descending past a TLA module (the TLA module itself is added but
     * its own dependencies are handled by its own evaluation). Skips
     * modules that are already evaluating or evaluated.
     *
     * @param array<string, true> $seen
     * @param list<ModuleRecord>  $result
     */
    private function gatherAsyncTransitiveDepsByRecord(
        ModuleRecord $record,
        array &$seen,
        array &$result,
    ): void {
        if (isset($seen[$record->path])) {
            return;
        }
        $seen[$record->path] = true;
        if ($record->bodyEvaluated || $record->bodyEvaluating) {
            return;
        }
        if ($record->hasTopLevelAwait) {
            $result[] = $record;
            return;
        }
        foreach (array_keys($record->eagerEdges) as $depPath) {
            $dep = $this->modules[$depPath] ?? null;
            if ($dep !== null) {
                $this->gatherAsyncTransitiveDepsByRecord($dep, $seen, $result);
            }
        }
        foreach (array_keys($record->deferEdges) as $depPath) {
            $dep = $this->modules[$depPath] ?? null;
            if ($dep !== null) {
                $this->gatherAsyncTransitiveDepsByRecord($dep, $seen, $result);
            }
        }
    }

    /**
     * Link a module (and its dependencies) without running its body.
     * Used by import.defer(...) so the deferred namespace can lazily
     * evaluate the body on first MOP access.
     */
    private function loadModuleLinkOnly(string $specifier, ?string $referrer): JsObject
    {
        $resolved = $this->resolve($specifier, $referrer);
        if (isset($this->modules[$resolved])) {
            return $this->modules[$resolved]->namespace;
        }
        $source = @file_get_contents($resolved);
        if ($source === false) {
            throw new \Phasis\Exceptions\TypeError("Cannot find module '{$specifier}'");
        }
        if ($this->linking) {
            return $this->linkModule($resolved, $source);
        }
        $this->linking = true;
        try {
            $namespace = $this->linkModule($resolved, $source);
            $this->finalizeStarReExports();
            $this->lockDownNamespaces();
            $this->validateIndirectExports();
            $this->finalizeDeferredNamespaces();
            $this->linking = false;
            // Note: deliberately skip markEagerlyReachable + drain.
            // The body queue items for this graph stay pending; they'll
            // be evaluated on first MOP access via
            // evaluateModuleBodyOnDemand.
            return $namespace;
        } finally {
            $this->linking = false;
        }
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
        $deferred = new \Phasis\Value\JsDeferredModuleNamespace(
            function (\Phasis\Value\JsDeferredModuleNamespace $ns) use ($self, $record): void {
                // Per ECMA-262 EnsureDeferredNamespaceEvaluation, the
                // deferred namespace can only complete if
                // ReadyForSyncExecution(record) is true. The spec walks
                // the record's [[RequestedModules]] graph and returns
                // false if any reachable module's [[Status]] is
                // ~evaluating~ or ~evaluating-async~. Mirror that walk
                // here so a deferred namespace whose target imports a
                // mid-evaluation module throws TypeError without
                // attempting to evaluate the target's body.
                if (!$self->isReadyForSyncExecution($record)) {
                    throw new \Phasis\Exceptions\TypeError(
                        'Cannot access deferred namespace of module that is currently evaluating'
                    );
                }
                // If a previous evaluation failed, re-throw the same
                // JsValue (per EvaluateSync — rejected evaluation
                // promises propagate forever).
                if ($record->evaluationError !== null) {
                    throw new \Phasis\Exceptions\JsThrowable($record->evaluationError);
                }
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
        // Property mirroring + namespace lockdown (toStringTag,
        // markAsModuleNamespace, preventExtensions) are deferred to
        // finalizeDeferredNamespaces(), which runs after the whole
        // module graph is linked. Mirroring at construction time
        // would race with cyclic imports — when this method runs
        // during dep-1.1's link pass, dep-1-tla's finalizeExports
        // may not have populated its namespace yet, leaving the
        // deferred namespace permanently empty.
        $record->deferredNamespace = $deferred;
        return $deferred;
    }

    /**
     * Spec ReadyForSyncExecution. Returns true iff `record` and every
     * module reachable from it (through both eager and defer edges)
     * is in a state that allows synchronous evaluation: either already
     * ~evaluated~, or ~linked~ with no TLA in the transitive closure.
     * Returns false if any reachable module is ~evaluating~ or
     * ~evaluating-async~ (mid-evaluation), which signals that a
     * deferred-namespace observer must throw TypeError instead of
     * forcing evaluation. Public so the deferred-namespace closure
     * captured by getDeferredNamespace can call it via $self.
     */
    public function isReadyForSyncExecution(ModuleRecord $record): bool
    {
        $seen = [];
        return $this->readyForSyncExecutionImpl($record, $seen);
    }

    /**
     * @param array<string, true> $seen
     */
    private function readyForSyncExecutionImpl(ModuleRecord $record, array &$seen): bool
    {
        if (isset($seen[$record->path])) {
            return true;
        }
        $seen[$record->path] = true;
        if ($record->bodyEvaluated && $record->evaluationError === null) {
            $promise = $record->evaluationPromise;
            if ($promise === null || $promise->getState() !== \Phasis\Value\JsPromise::STATE_PENDING) {
                // ~evaluated~: ok.
            } else {
                // ~evaluating-async~: not ok.
                return false;
            }
        }
        if ($record->bodyEvaluating) {
            return false;
        }
        $promise = $record->evaluationPromise;
        if ($promise !== null && $promise->getState() === \Phasis\Value\JsPromise::STATE_PENDING) {
            return false;
        }
        // Not yet evaluated. ~linked~ state: walk requested modules.
        if (!$record->bodyEvaluated && $record->hasTopLevelAwait) {
            // TLA modules in ~linked~ state cannot run synchronously.
            return false;
        }
        foreach (array_keys($record->importedPaths) as $depPath) {
            $dep = $this->modules[$depPath] ?? null;
            if ($dep === null) {
                continue;
            }
            if (!$this->readyForSyncExecutionImpl($dep, $seen)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Mirror each deferred namespace's exports from its eager
     * namespace and seal the deferred namespace per spec
     * ModuleNamespaceCreate(phase=defer). Runs after the entire
     * module graph is linked so cyclic imports see the fully
     * populated eager namespace.
     */
    private function finalizeDeferredNamespaces(): void
    {
        foreach ($this->modules as $record) {
            $deferred = $record->deferredNamespace;
            if (!($deferred instanceof \Phasis\Value\JsDeferredModuleNamespace)) {
                continue;
            }
            if ($deferred->isFinalized()) {
                continue;
            }
            $eagerNs = $record->namespace;
            $deferred->setBypassTrigger(true);
            foreach ($eagerNs->getOwnPropertyNames() as $name) {
                $rawDesc = $eagerNs->getOwnPropertyDescriptorRaw($name);
                if ($rawDesc === null) {
                    continue;
                }
                $deferred->defineOwnProperty($name, clone $rawDesc);
            }
            $deferred->setBypassTrigger(false);
            $deferred->definePropertyBySymbol(
                \Phasis\BuiltIn\SymbolConstructor::toStringTag(),
                \Phasis\Object\PropertyDescriptor::data(
                    new \Phasis\Value\JsString('Deferred Module'),
                    false,
                    false,
                    false,
                ),
            );
            $deferred->markAsModuleNamespace();
            $deferred->preventExtensions();
            $deferred->markFinalized();
        }
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
        // Cycle guard. A dynamic import or deferred-namespace MOP that
        // touches a record already on the onDemand call stack must
        // return immediately rather than re-entering the body. Without
        // it, a cyclic eager graph (A imports B, B imports A) where
        // a body queued for the top-level drain triggers a dynamic
        // import recurses through the eager-dep walk forever and
        // overflows the PHP stack.
        if (isset($this->onDemandStack[$record->path])) {
            return;
        }
        $this->onDemandStack[$record->path] = true;
        try {
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
            $record->bodyEvaluating = true;
            $prevModulePath = $this->interpreter->getCurrentModulePath();
            $this->interpreter->setCurrentModulePath($record->path);
            try {
                $this->interpreter->executeModuleBody(
                    $pending['program']->body,
                    $pending['moduleEnv'],
                    alreadyHoisted: true,
                    onAsyncStart: function (\Phasis\Value\JsPromise $p) use ($record): void {
                        $record->evaluationPromise = $p;
                        // Drain microtasks until this module's TLA promise
                        // settles. Lazy triggers run synchronously from the
                        // host's perspective so the consumer of the
                        // deferred namespace sees fully-evaluated exports.
                        $iter = 0;
                        while ($p->getState() === \Phasis\Value\JsPromise::STATE_PENDING && $iter++ < 100000) {
                            \Phasis\Value\JsPromise::drainMicrotasks();
                        }
                        if ($p->getState() === \Phasis\Value\JsPromise::STATE_REJECTED) {
                            $this->interpreter->throwJsValue($p->getResolvedValue());
                        }
                    },
                );
            } catch (\Phasis\Exceptions\JsThrowable $e) {
                // Stash the thrown value so subsequent deferred-namespace
                // accesses re-throw the same JsValue (per spec
                // EvaluateSync: a rejected evaluation promise propagates
                // forever).
                $record->evaluationError = $e->jsValue;
                throw $e;
            } finally {
                $this->interpreter->setCurrentModulePath($prevModulePath);
                $record->bodyEvaluating = false;
            }
        } finally {
            unset($this->onDemandStack[$record->path]);
        }
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
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Indirect re-export '{$exportName}' in module '{$path}' does not resolve",
                    );
                }
                if ($resolution === 'ambiguous') {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Indirect re-export '{$exportName}' in module '{$path}' is ambiguous",
                    );
                }
            }
            foreach ($record->importEntries as $entry) {
                $resolution = $this->resolveExport($entry['source'], $entry['exportName'], []);
                if ($resolution === null) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Module '{$entry['source']}' does not export '{$entry['exportName']}'",
                    );
                }
                if ($resolution === 'ambiguous') {
                    throw new \Phasis\Exceptions\SyntaxError(
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
                throw new \Phasis\Exceptions\TypeError(
                    "Cannot resolve relative module specifier '{$specifier}' without a referrer",
                );
            }
            $base = dirname($referrer);
            return $this->normalizeModulePath($base . '/' . $specifier);
        }

        // Bare specifiers: not supported in our simple loader.
        throw new \Phasis\Exceptions\TypeError(
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
            // For deferred imports of an already-failed module, link
            // the source without re-throwing its cached evaluation
            // error. The error must only surface when the importer
            // performs an observable Get on the deferred namespace
            // (per EnsureDeferredNamespaceEvaluation), not at the
            // importer's own link/load time.
            if ($isDefer) {
                $this->loadModuleForDeferred($source, $modulePath);
            } else {
                $this->loadModule($source, $modulePath);
            }
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
            if ($currentRecord !== null) {
                if ($isDefer) {
                    $currentRecord->deferEdges[$resolved] = true;
                } else {
                    $currentRecord->eagerEdges[$resolved] = true;
                }
                $currentRecord->orderedEdges[] = [
                    'path' => $resolved,
                    'defer' => $isDefer,
                ];
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
        $isDefer = ($node->phase) === 'defer';
        // Same rationale as preloadRequestedModules: deferred imports
        // of a previously-failed module must not propagate the cached
        // evaluation error at link time.
        $importedNs = $isDefer
            ? $this->loadModuleForDeferred($node->source, $modulePath)
            : $this->loadModule($node->source, $modulePath);
        $resolved = $this->resolve($node->source, $modulePath);
        $currentRecord = $this->modules[$modulePath] ?? null;

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
        if ($pattern instanceof \Phasis\Ast\Expression\Identifier) {
            return [$pattern->name];
        }
        if ($pattern instanceof \Phasis\Ast\Pattern\ArrayPattern) {
            $out = [];
            foreach ($pattern->elements as $elem) {
                if ($elem !== null) {
                    $out = array_merge($out, $this->collectBindingPatternNames($elem));
                }
            }
            return $out;
        }
        if ($pattern instanceof \Phasis\Ast\Pattern\ObjectPattern) {
            $out = [];
            foreach ($pattern->properties as $p) {
                if ($p instanceof \Phasis\Ast\Pattern\AssignmentProperty) {
                    $out = array_merge($out, $this->collectBindingPatternNames($p->value));
                } elseif ($p instanceof \Phasis\Ast\Pattern\RestElement) {
                    $out = array_merge($out, $this->collectBindingPatternNames($p->argument));
                }
            }
            return $out;
        }
        if ($pattern instanceof \Phasis\Ast\Pattern\AssignmentPattern) {
            return $this->collectBindingPatternNames($pattern->left);
        }
        if ($pattern instanceof \Phasis\Ast\Pattern\RestElement) {
            return $this->collectBindingPatternNames($pattern->argument);
        }
        return [];
    }

    /**
     * Walk the parsed module body and decide whether it uses a
     * top-level await. Recurses through statements, expressions, and
     * declarations but stops at any function / generator / class
     * member boundary, since `await` inside those bodies is per-call,
     * not at the module level.
     *
     * @param Node[] $body
     */
    private function programHasTopLevelAwait(array $body): bool
    {
        foreach ($body as $node) {
            if ($this->nodeContainsTopLevelAwait($node)) {
                return true;
            }
        }
        return false;
    }

    private function nodeContainsTopLevelAwait(mixed $node): bool
    {
        if (!($node instanceof Node)) {
            return false;
        }
        if ($node instanceof \Phasis\Ast\Expression\AwaitExpression) {
            return true;
        }
        // Function bodies, arrow functions, methods, classes, and
        // generators introduce a new function scope where `await`
        // is local. Treat them as opaque.
        if (
            $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
            || $node instanceof \Phasis\Ast\Declaration\FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
            || $node instanceof \Phasis\Ast\Declaration\ClassDeclaration
        ) {
            return false;
        }
        $vars = get_object_vars($node);
        foreach ($vars as $value) {
            if ($value instanceof Node) {
                if ($this->nodeContainsTopLevelAwait($value)) {
                    return true;
                }
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node && $this->nodeContainsTopLevelAwait($child)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Reset the module cache (useful for testing).
     */
    public function reset(): void
    {
        $this->modules = [];
        $this->evaluating = [];
        $this->onDemandStack = [];
        $this->bodyQueue = [];
        $this->linking = false;
    }
}
