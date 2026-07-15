---
title: "Overview"
description: "How Phasis works under the hood — architecture, the bytecode VM, perf numbers, the oracle testing model, and where to extend the engine."
path: "advanced"
order: 16
section: "Advanced"
meta_title: "Overview"
meta_description: "How Phasis works under the hood — architecture, the bytecode VM, perf numbers, the oracle testing model, and where to extend the engine."
---

# Advanced

The internals. If you're embedding Phasis you can skip this section. If you're modifying the engine, every page here is load-bearing.

## Pages

- **[Architecture](/docs/advanced/architecture)** — how source becomes execution: lexer → parser → AST → bytecode compile → bytecode VM with tree-walker fallback. Module loader, realms, the role of `Phasis\Engine` as the realm boundary.
- **[Bytecode VM](/docs/advanced/bytecode-vm)** — opcode set, dispatch loop, inline caches, the JsToPhp source-transpiler fast path, function-shape canSkipEnvAlloc heuristic, when calls take the bytecode path vs the tree-walker.
- **[Benchmarks](/docs/advanced/benchmarks)** — current `bench/` numbers, how to interpret them, why ~100× V8 is the steady-state ceiling for tree-walkers and what would close the gap.
- **[Testing](/docs/advanced/testing)** — the oracle model. Every change is verified against three independent oracles: Node.js (V8 spec ground truth), test262, and the popular-library byte-equality suite.

## Conventions for contributing

- **Don't touch core engine without a strong reason.** Anything under `src/Runtime/`, `src/Bytecode/`, `src/Lexer/`, `src/Parser/`, `src/Regex/` is on the test262 critical path. Touching it without a corresponding test262 verification is a recipe for regressions. Built-ins (`src/BuiltIn/`) are safer because the test262 sub-suites for each built-in catch breakage locally.
- **Use traits for organization, not classes.** When a file exceeds ~2K lines, split it into trait files under a sibling directory (`src/BuiltIn/Temporal/`, `src/Runtime/Parts/`, etc.) rather than carving out new classes — keeps `self::` lookups working and avoids breaking the private-method API surface.
- **Run `bin/verify-all` locally before pushing.** It runs PHPStan + PHPCS + PHPUnit + the popular-library suite. The full test262 + WPT matrix runs in CI.
- **For every Web API addition, add a WPT fixture.** Drop the `.any.js` from `tests/Wpt/upstream/<area>/` into `tests/Wpt/fixtures/<category>/` and the runner picks it up.

## See also

- [Compatibility](/docs/compatibility) — how the oracle outputs are measured.
- [CLI reference](/docs/cli) — `bin/phasis`, `bin/test262`, `bin/wpt`.
