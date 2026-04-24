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

    public function __construct(
        public readonly string $path,
        public readonly JsObject $namespace,
    ) {
    }
}
