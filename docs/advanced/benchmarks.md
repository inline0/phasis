---
title: "Benchmarks"
description: "How Phasis performs vs V8, what kind of workloads it handles well, and how to measure your own."
path: "advanced/benchmarks"
order: 200
section: "Advanced"
meta_title: "Benchmarks"
meta_description: "How Phasis performs vs V8, what kind of workloads it handles well, and how to measure your own."
---

# Benchmarks

Phasis is **roughly two orders of magnitude slower than V8** on dispatch-bound JavaScript. That ceiling is the inherent cost of a PHP-hosted interpreter vs a native JIT compiler. The question for an embedder isn't "how does it compare to V8" — it's "is it fast enough for what I'm doing." For most embedding workloads the answer is yes.

## When the ceiling doesn't matter

- **Templating** — SSR-style render of a typical page in well under 10 ms.
- **Validation rules** — user-supplied JS for form validation finishes in microseconds.
- **Data transformations** — `array.map(transform)` over hundreds of items completes in tens of milliseconds.
- **Sandbox runners** — short user-supplied scripts with a 1-second host cap leave plenty of headroom.

## When the ceiling matters

- **Compute-heavy inner loops** — image processing, large-array reductions, simulations. Move the compute to a native PHP library and expose it as a [host function](/docs/interop/host-functions).
- **Real-time animation** — anything driving 60 fps. That JS belongs in the client (browser, native app), not the embedded engine.
- **JS reimplementations of compute-expensive algorithms** — cryptography, compression, parsing. PHP has native equivalents that are 1000× faster; use those.

The right mental model: Phasis is a **glue layer** for JS-shaped logic embedded in PHP-shaped applications. The PHP host handles compute; the JS layer handles configuration, branching, and small transformations.

## What makes the engine fast (relative to a naïve tree-walker)

- **Bytecode VM** — most hot dispatch runs through `VM::execute`'s opcode switch, not the AST.
- **Custom callstack** — eligible bytecode calls keep state inside `VM::execute` and save the caller's frame in a pool, without another PHP call frame. See [Bytecode VM § Custom callstack](/docs/advanced/bytecode-vm).
- **Inline caches** — a per-PC cache retains up to four prototype shapes, allowing repeated method reads (the `c.method()` shape) to skip the prototype-chain walk while checking for mutations.
- **Frame-snapshot generators** — simple generator bodies suspend by capturing the dispatch state into a heap object instead of crossing a PHP Fiber.
- **JsToPhp transpiler** — proven numeric bodies and string-scanning loops compile to PHP closures that PHP's tracing JIT can optimize. Entry checks validate assumptions before observable effects; calls that can expose their caller remain on tracked execution paths.
- **JSON.stringify cache** — `JSON.stringify(o)` caches its output on the input object with transitive version invalidation, accelerating the deep-clone idiom `JSON.parse(JSON.stringify(o))`.

## Measuring your own workload

```bash
php bench/run.php
```

This runs the microbench suite shipped under `bench/microbench.js` — a handful of synthetic workloads (loop arithmetic, recursive functions, object creation, prototype-method calls, array push, JSON round-trip, etc.) and prints median wall time per test. Useful as a sanity check; current numbers are committed to [`BENCH.md`](https://github.com/inline0/phasis/blob/main/BENCH.md) on every CI bench run.

For your own code, swap in any script:

```bash
php -d xdebug.mode=off bench/run.php path/to/your-script.js
```

For PHP-level profiling, Xdebug's profiler works against Phasis exactly like any other PHP application. Run a script under `xdebug.mode=profile` and inspect the cachegrind output to see which `Phasis\` classes dominate.

## Server-side rendering

`bench/ssr.php` renders `bench/ssr.jsx` (header, nav, 100 post cards, a context consumer, about 1,300 elements, importing a CommonJS package and lucide-react icons) five ways:

| Variant | What runs |
|---|---|
| `native` | the built-in React 19.3 profile (`Engine::render`) |
| `precompile` | native, with `JsxMode::Precompile` |
| `real-react` | real React 19.3 (the vendored edge build) inside Phasis, through an import map |
| `html` | the phasis HTML profile |
| `floor` | the components alone: every component called, every element created, no HTML |

```bash
php -d xdebug.mode=off -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M bench/ssr.php
```

The targets are ratios, so they hold across machines: native renders at least 10x faster than real React in Phasis, stays within 1.5x of the floor, and precompile renders faster than native. Local medians on PHP 8.5 with the tracing JIT: 12.7x, 1.07x and 1.04x. Most of the remaining time is the components themselves, interpreted as JavaScript (the 125 lucide icons alone take about two thirds of a render); React's own work (elements, hooks, serialization) is PHP.

Precompile's gain on this component-heavy page is a few percent, less than the drift between variants timed one after the other. So the script times it in a paired run: a native and a precompiled engine render back to back in alternating order, and the reported ratio is the median of the per-round ratios.

For production, preload Phasis so none of its PHP is compiled per request:

```ini
opcache.preload=/path/to/vendor/phasis/phasis/preload.php
opcache.preload_user=www-data
```

## Roadmap

What would close more of the V8 gap, in rough order of tractability:

- **`yield*` and try-with-yield on the frame-snapshot generator path** — these shapes currently route to the Fiber-backed tree-walker. Each needs explicit state-machine handling (delegate-iterator forwarding, handler stack preserved across suspend) before they're snapshot-safe.
- **Async generators and `await` on snapshots** — the spec composes generator suspension with the microtask queue. Once the bytecode body supports both `yield` and `await`, async generators become snapshot-friendly too.
- **Broader JsToPhp lowering** — support additional parameter types and calls while preserving observable caller records, coercion order and fallback before effects. Object-typed parameters alone are not sufficient to make `function (obj) { obj.method() }` safe to lower.
- **Direct opcache emission** — skip JsToPhp's `eval()` step by writing opcache-format opcodes directly. Deepest item; the others can be done within the existing architecture.

None of these are necessary for the embedding workloads Phasis targets.
