<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="./.github/logo-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="./.github/logo-light.svg">
    <img alt="Phasis" src="./.github/logo-light.svg" height="50">
  </picture>
</p>

<p align="center">
  Pure PHP JavaScript engine
</p>

<p align="center">
  <a href="https://github.com/inline0/phasis/actions/workflows/ci.yml"><img src="https://github.com/inline0/phasis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://packagist.org/packages/phasis/phasis"><img src="https://img.shields.io/packagist/v/phasis/phasis.svg" alt="Packagist"></a>
  <a href="https://github.com/inline0/phasis/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="license"></a>
</p>

---

## What is Phasis?

Phasis lexes, parses, and executes ECMAScript in pure PHP. No `exec('node …')`, no FFI, no custom binary extension. It needs PHP 8.2+ with three standard extensions: `ext-mbstring`, `ext-bcmath` (BigInt arithmetic and integer-precision number handling) and `ext-curl` (the default `fetch()` transport). `bcmath` is not enabled in every PHP build: the official `php` Docker images, for example, need `docker-php-ext-install bcmath`. `ext-intl` is optional — Phasis runs without it, but the Intl.* APIs (Collator, NumberFormat, DateTimeFormat, …) and non-ISO Temporal calendars require it. `ext-openssl` is optional too and enables `crypto.subtle` AES encryption. The whole engine ships as a Composer package.

**The problem:** PHP applications that need to run user-supplied JavaScript — templating engines, SSR shims, validation rules, content sandboxes, headless test runners — usually shell out to Node.js or skip the feature. Either path adds operational complexity: a second runtime, a serialization boundary, a network of subprocess pipes, and a hostile deployment story for shared hosting.

**Phasis solves this** by implementing the ECMAScript language and standard library natively in PHP:

- Full ES2024+ language surface (classes, async/await, generators, decorators, top-level await, ES modules)
- Complete standard library (`Array`, `String`, `Object`, `Math`, `JSON`, `Date`, `RegExp`, `Map`, `Set`, `Promise`, `Proxy`, `Reflect`, `Symbol`, `BigInt`, `TypedArray`, `Temporal`, `Intl`)
- **Web Platform Pack**: `URL`, `URLSearchParams`, `TextEncoder`/`TextDecoder`, `atob`/`btoa`, `structuredClone`, `performance`, `DOMException`
- **Fetch Pack**: `fetch`, `Request`, `Response`, `Headers`, `Body`, `AbortController`/`AbortSignal`, `Blob`/`File`, `FormData`, `EventTarget`/`Event`, full WHATWG Streams, `navigator`
- **Crypto**: `crypto.getRandomValues`, `crypto.randomUUID`, full `SubtleCrypto` (SHA family, HMAC, AES-GCM/CBC/CTR, RSA-OAEP/PSS/PKCS1, ECDSA, ECDH, HKDF, PBKDF2)
- **WebSocket** (RFC 6455 + replaceable transport), **XMLHttpRequest** (layered over fetch), and a real **event loop** (`setTimeout` / `setInterval` / `queueMicrotask`, plus Stage-3 `AsyncContext`)
- **JSX Pack**: render JSX components to HTML from PHP with `Engine::render()`, no build step
  - **Native React 19.3 server renderer** in PHP: `react`, `react-dom/server` and `react-dom/static` are built in, with HTML byte-identical to `react-dom@19.3.1` and hydratable by React on the client (hooks, context, Suspense, `useId`, async components, resource hints)
  - **General HTML renderer** (`phasis`, `phasis/jsx-runtime`) for emails, feeds and static pages, byte-identical to preact-render-to-string 6.8.1, plus `raw()`
  - **Bundler-style imports**: extensionless paths and `index` files, `jsconfig` aliases, `node_modules` with `package.json` `exports`, CommonJS packages, style and asset imports, import maps, and barrel pruning for `sideEffects: false` packages
  - Optional precompile of static markup, an `opcache.preload` script, and an on-disk parse cache
- PHP↔JS interop — pass PHP scalars and arrays into JS, bind PHP callables as JS functions, call JS functions from PHP
- **Official test262 coverage** — measured against the pinned TC39 suite, with pass, fail and blocked counts in the canonical CI snapshot ([`COMPAT.md`](./COMPAT.md))
- **100 % of imported Web Platform Tests pass** across fetch, headers, blob, abort, streams, encoding, URL, structured-clone, hr-time, and atob (the brotli subtests need `ext-brotli`)

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

// Bridge a PHP value into JS (arrays are copied)
$engine->setGlobal('config', ['debug' => true]);
$engine->eval('config.debug');               // true

// console.log is buffered, not printed
$engine->eval('console.log("hi")');
echo $engine->getConsoleOutput();            // hi

// Expose a PHP closure as a JS function
$engine->setGlobal('greet', fn(string $name) => "Hello, $name");
$engine->eval('greet("world")');             // "Hello, world"

// Call JS functions from PHP
$engine->eval('function add(a, b) { return a + b; }');
echo $engine->call('add', 2, 3);              // 5
```

## CLI

`bin/phasis` runs scripts from the command line:

```bash
# Run a JavaScript file (classic script; console.log output goes to stdout)
./vendor/bin/phasis script.js

# Evaluate source; print with console.log
./vendor/bin/phasis -e 'console.log([1, 2, 3].map(x => x * x).join(","))'

# Dump the AST as JSON
./vendor/bin/phasis --ast script.js
```

`bin/test262`, the conformance runner, needs the `test262` submodule and the dev autoloader, so run it from a clone of this repository (see Testing below).

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

# test262 conformance sample (needs: git submodule update --init test262)
./bin/test262 --category built-ins/Array --jobs 4
```

CI runs PHPStan, PHPCS, the unit suite and the oracle regression on every push to `main` and every pull request; the Web Platform Tests and popular-package suites run when `src/` or their fixtures change. The full test262 matrix (`compat-matrix.yml`) is started by hand, not on every push; it runs against the test262 revision pinned by the submodule and commits the result to `COMPAT.md`, which records the snapshot time.

## Compatibility

| Engine | test262 pass rate |
|---|---:|
| V8 (Chrome / Node) | 99.8 % |
| SpiderMonkey (Firefox) | 99.6 % |
| JavaScriptCore (Safari) | 99.4 % |
| QuickJS | ~97 % |
| Hermes (React Native) | ~95 % |
| **Phasis** | **100 %** |

Every test in the pinned test262 revision passes, every category. See [`COMPAT.md`](./COMPAT.md) for the latest snapshot. Other engines' numbers are from [test262.fyi](https://test262.fyi).

## Performance

Phasis is a tree-walking interpreter with an opportunistic bytecode VM. Expect ~100× the runtime of V8 on dispatch-bound JS — the trade-off is zero dependencies, pure PHP, and host-controlled execution. For embedding workloads where you run user-supplied logic on PHP data, that ceiling rarely matters.

Current microbench numbers are committed in [`BENCH.md`](./BENCH.md) after each bench workflow run.

## Documentation

Full documentation lives at [phasis.dev](https://phasis.dev) (or in [`docs/`](./docs) if you're reading the repo).

- [Getting Started](./docs/getting-started.md): install and run your first script
- [CLI](./docs/cli.md): `bin/phasis`, `bin/test262` and `bin/wpt`
- [API](./docs/api.md): full `Phasis\Engine` reference
- [Parser](./docs/parser.md): standalone `Phasis\Parser\Parser` API, AST walker, ESTree export
- [JSX](./docs/jsx.md): `.jsx` modules, `Engine::render()`, the React and HTML profiles, precompile
- [React](./docs/react.md): the native React 19.3 server renderer and hydration
- [Modules](./docs/modules.md): import maps, `node_modules`, CommonJS, aliases, assets, barrel pruning
- [Formatter](./docs/formatter.md): prettier-compatible formatting for JavaScript, JSON and CSS
- [Interop](./docs/interop/): PHP↔JS values, host functions, shared PHP objects
- [Compatibility](./docs/compatibility/): test262 coverage, spec surface, limitations
- [Advanced](./docs/advanced/): architecture, bytecode VM, oracle testing, benchmarks

## License

MIT
