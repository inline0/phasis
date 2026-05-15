<?php

declare(strict_types=1);

namespace Phasis\Runtime;

/**
 * Map of lazily-installable built-in modules.
 *
 * Each entry is one install "module" (a `BuiltIn\X::install($env)` call)
 * that creates one or more JS-visible globals. Modules can declare
 * dependencies on other modules — realizing a module realizes its
 * transitive deps first, mirroring the install order the eager
 * constructor uses today.
 *
 * The realization side effect is whatever the factory does — typically
 * `defineVar` calls that put bindings on the global environment and,
 * via its linked global object, replace the placeholder accessor that
 * triggered the realize with a real data descriptor. Subsequent reads
 * of the same name skip the registry entirely.
 */
final class LazyBuiltinRegistry
{
    /** @var array<string, LazyBuiltinEntry> JS name → owning entry. */
    private array $byName = [];

    /** @var list<LazyBuiltinEntry> All registered entries, insertion order. */
    private array $entries = [];

    /**
     * Register a lazy-installable module.
     *
     * @param list<string>    $names   JS globals this module installs.
     * @param list<string>    $deps    Other modules that must realize first
     *                                 (named by any one of their `names`).
     * @param callable():void $factory Performs the install. Typically a
     *                                 thin closure that calls
     *                                 `BuiltIn\X::install($env)`.
     */
    public function register(array $names, array $deps, callable $factory): void
    {
        $entry = new LazyBuiltinEntry($names, $deps, $factory);
        $this->entries[] = $entry;
        foreach ($names as $name) {
            $this->byName[$name] = $entry;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    /** @return list<string> Every registered name. */
    public function names(): array
    {
        return array_keys($this->byName);
    }

    /** @return list<LazyBuiltinEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Realize the module that owns `$name`, plus its transitive deps.
     * Safe to call repeatedly — already-realized entries no-op.
     */
    public function realize(string $name): void
    {
        $entry = $this->byName[$name] ?? null;
        if ($entry === null) {
            return;
        }
        $this->realizeEntry($entry);
    }

    private function realizeEntry(LazyBuiltinEntry $entry): void
    {
        if ($entry->realized) {
            return;
        }
        // Set first so a (defensive) cycle in declared deps doesn't
        // recurse infinitely — the second visit no-ops and lets the
        // first finish its install.
        $entry->realized = true;
        foreach ($entry->deps as $depName) {
            $depEntry = $this->byName[$depName] ?? null;
            if ($depEntry !== null) {
                $this->realizeEntry($depEntry);
            }
        }
        ($entry->factory)();
    }
}
