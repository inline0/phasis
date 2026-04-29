<?php

declare(strict_types=1);

namespace PhpJs\Module;

use PhpJs\Value\JsObject;

/**
 * Tracks a loaded module: its resolved path and namespace object.
 */
class ModuleRecord
{
    /**
     * @var array<string, string> Map of star-exported name → source module
     *                            path that contributed it via `export * from`.
     */
    public array $starExportSources = [];

    /**
     * @var array<string, true> Names that are ambiguously exported via
     *                          multiple `export *` sources.
     */
    public array $ambiguousNames = [];

    /** True after finalizeExports has completed and the namespace exposes
     *  all of the module's exports (direct and star re-exports). Consumers
     *  in a cycle can use this to decide whether they can enforce the
     *  exports-exist check immediately or must defer it. */
    public bool $exportsFinalized = false;

    /**
     * @var array<string, array{source:string,localName:string}> Indirect
     *       export entries from `export { x } from 'src'` style declarations.
     *       Populated by finalizeExports and consumed by ResolveExport to
     *       validate that each indirect export resolves (not null, not
     *       ambiguous) across the module graph, even when re-exports form
     *       a cycle.
     */
    public array $indirectExports = [];

    /**
     * @var list<array{source:string,exportName:string}> Named imports
     *       declared on this module. Populated by processImportDeclaration
     *       and validated post-link via ResolveExport so names contributed
     *       by star re-exports are visible before the check runs.
     */
    public array $importEntries = [];

    /**
     * @var list<string> Absolute paths of modules referenced via
     *       `export * from 'src'`. Used by ResolveExport to search for
     *       re-exported names beyond what was copied into the namespace.
     */
    public array $starExportRequests = [];

    /**
     * Program body queued for evaluation. Set by linkModule; consumed by
     * the top-level loadModule call after the whole graph is linked and
     * validated.
     * @var array{program: \PhpJs\Ast\Program, moduleEnv: \PhpJs\Runtime\Environment, prevModulePath: ?string}|null
     */
    public ?array $pendingBody = null;

    /**
     * Outstanding evaluation promise for an async (top-level-await)
     * module. The body has started in a Fiber but hasn't settled yet;
     * the loader drains microtasks across all such records after
     * dispatching every body, so a TLA module doesn't block its
     * siblings' synchronous evaluation.
     */
    public ?\PhpJs\Value\JsPromise $evaluationPromise = null;

    /**
     * Distinct absolute paths of every module this one imports
     * (including export-from sources). Populated during linkModule so
     * the body-queue drainer can block a module's body on every async
     * dependency that hasn't yet settled, matching the spec's
     * InnerModuleEvaluation post-order semantics.
     *
     * @var array<string, true>
     */
    public array $importedPaths = [];

    /**
     * Per-module cached deferred namespace exotic object. Created
     * lazily the first time a deferred import (`import defer * as` or
     * `import.defer(...)`) requests this module so all such imports
     * return the same object identity (per spec GetModuleNamespace
     * with phase = ~defer~).
     */
    public ?JsObject $deferredNamespace = null;

    /**
     * Has this module's body been executed? Tracked separately from
     * pendingBody so deferred imports can decide whether to trigger
     * lazy evaluation on first MOP access.
     */
    public bool $bodyEvaluated = false;

    /**
     * True while the body is actively running. Used by deferred
     * namespaces to detect re-entrance: per ECMA-262
     * EnsureDeferredNamespaceEvaluation, accessing a deferred
     * namespace whose module is still ~evaluating~ throws TypeError.
     */
    public bool $bodyEvaluating = false;

    /** Count of importers that reached this module via the eager phase. */
    public int $eagerImporterCount = 0;

    /** Count of importers that reached this module via the defer phase. */
    public int $deferredImporterCount = 0;

    /**
     * Outgoing eager-import edges as resolved paths. Populated by
     * preloadRequestedModules so the loader can propagate the
     * "needs-eager-evaluation" flag from the entry module across the
     * import graph. Modules that aren't reachable via at least one
     * eager edge from the entry point are skipped during the drain
     * pass and lazily evaluated on first MOP access on their
     * deferred namespace.
     *
     * @var array<string, true>
     */
    public array $eagerEdges = [];

    /**
     * Set by markEagerlyReachable after the graph is fully linked.
     * True for the entry module and any module reachable via at
     * least one purely-eager chain of imports from the entry.
     */
    public bool $eagerlyReachable = false;

    public function __construct(
        public readonly string $path,
        public readonly JsObject $namespace,
    ) {
    }
}
