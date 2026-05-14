<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="./docs/public/logo-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="./docs/public/logo-light.svg">
    <img alt="Phasis" src="./docs/public/logo-light.svg" height="50">
  </picture>
</p>

<p align="center">
  Pure PHP JavaScript engine
</p>

<p align="center">
  <a href="https://github.com/inline0/phasis/actions/workflows/compat-matrix.yml"><img src="https://github.com/inline0/phasis/actions/workflows/compat-matrix.yml/badge.svg" alt="CI"></a>
  <a href="https://packagist.org/packages/phasis/phasis"><img src="https://img.shields.io/packagist/v/phasis/phasis.svg" alt="Packagist"></a>
  <a href="https://github.com/inline0/phasis/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="license"></a>
</p>

---

## What is Phasis?

Phasis lexes, parses, and executes ECMAScript in pure PHP. No `exec('node …')`, no FFI, no binary extensions beyond `ext-mbstring`. The whole engine ships as a Composer package and runs anywhere PHP 8.2+ runs.

**The problem:** PHP applications that need to run user-supplied JavaScript — templating engines, SSR shims, validation rules, content sandboxes, headless test runners — usually shell out to Node.js or skip the feature. Either path adds operational complexity: a second runtime, a serialization boundary, a network of subprocess pipes, and a hostile deployment story for shared hosting.

**Phasis solves this** by implementing the ECMAScript language and standard library natively in PHP:

- Full ES2024+ language surface (classes, async/await, generators, decorators, top-level await, ES modules)
- Complete standard library (`Array`, `String`, `Object`, `Math`, `JSON`, `Date`, `RegExp`, `Map`, `Set`, `Promise`, `Proxy`, `Reflect`, `Symbol`, `BigInt`, `TypedArray`, `Temporal`, `Intl`)
- Direct PHP↔JS interop — share objects without serialization, bind PHP callables as JS functions
- Resource limits for call depth, loop iterations, string length, output size, and wall-clock execution
- 99.97 % of the official test262 suite passes (50,490 / 50,506)

## Quick Start

```bash
composer require phasis/phasis
```

```php
use Phasis\Engine;

$engine = new Engine();

// Evaluate an expression
$engine->eval('1 + 2 * 3');                  // 7

// Run a file
$engine->execFile('/path/to/script.js');

// Bridge a PHP value into JS
$engine->setGlobal('config', ['debug' => true]);
$engine->eval('console.log(config.debug)');  // true

// Expose a PHP closure as a JS function
$engine->setGlobal('greet', fn(string $name) => "Hello, $name");
$engine->eval('greet("world")');             // "Hello, world"

// Share a PHP object by reference
class Counter { public int $value = 0; }
$counter = new Counter();
$engine->setGlobal('counter', $counter);
$engine->eval('counter.value++');
echo $counter->value;                         // 1

// Call JS functions from PHP
$engine->eval('function add(a, b) { return a + b; }');
echo $engine->call('add', 2, 3);              // 5
```

## CLI

Phasis ships two CLI binaries:

```bash
# Run a JavaScript file
./vendor/bin/phasis script.js

# Evaluate an expression
./vendor/bin/phasis -e '[1, 2, 3].map(x => x * x)'

# Interactive REPL
./vendor/bin/phasis --repl

# Dump the AST
./vendor/bin/phasis --ast script.js

# Run the official test262 conformance suite
./vendor/bin/test262 --category built-ins/Array
./vendor/bin/test262 --jobs 4
```

## Testing

Phasis is verified against Node.js (V8) and the official ECMAScript test262 conformance suite.

```bash
# Unit tests
composer test

# PHPStan (level 6, zero errors)
composer analyse

# Code standards
composer cs

# Oracle regression (scenarios with Node.js as the ground truth)
./bin/test-regression

# Full quality gate (PHPStan + PHPCS + PHPUnit + oracle)
./bin/verify-all

# test262 conformance sample
./bin/test262 --category built-ins/Array --jobs 4
```

The complete test262 matrix runs in CI on every push across 73 parallel shards. Compliance numbers are committed to `COMPAT.md` after each run.

## Compatibility

| Engine | test262 pass rate |
|---|---:|
| V8 (Chrome / Node) | 99.8 % |
| SpiderMonkey (Firefox) | 99.6 % |
| JavaScriptCore (Safari) | 99.4 % |
| QuickJS | ~97 % |
| Hermes (React Native) | ~95 % |
| **Phasis** | **99.97 %** |

The 16 remaining skips are all SpiderMonkey JS-loop stress fixtures (1 M-iteration sweeps, O(n^4) DST cache probes) whose semantics are covered by the smaller adjacent suites we pass. See [`COMPAT.md`](./COMPAT.md) for the per-category breakdown.

## Performance

Phasis is a tree-walking interpreter with an opportunistic bytecode VM. Expect ~100× the runtime of V8 on dispatch-bound JS — the trade-off is zero dependencies, pure PHP, and host-controlled execution. For embedding workloads where you run user-supplied logic on PHP data, that ceiling rarely matters.

Current microbench numbers are committed in [`BENCH.md`](./BENCH.md) after each bench workflow run.

## Documentation

Full documentation lives at [phasis.dev](https://phasis.dev) (or in [`docs/`](./docs) if you're reading the repo).

- [Getting Started](./docs/content/docs/getting-started.mdx) — install and run your first script
- [CLI](./docs/content/docs/cli.mdx) — `bin/phasis` and `bin/test262`
- [API](./docs/content/docs/api.mdx) — full `Phasis\Engine` reference
- [Interop](./docs/content/docs/interop/) — PHP↔JS values, host functions, shared objects
- [Compatibility](./docs/content/docs/compatibility/) — test262 coverage, spec surface, limitations
- [Advanced](./docs/content/docs/advanced/) — architecture, bytecode VM, oracle testing, benchmarks

## License

MIT
