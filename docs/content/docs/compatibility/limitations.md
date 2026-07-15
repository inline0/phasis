---
title: "Known limitations"
description: "Where Phasis intentionally diverges from V8 — performance ceilings, host integrations, and design trade-offs."
path: "compatibility/limitations"
order: 15
section: "Compatibility"
meta_title: "Known limitations"
meta_description: "Where Phasis intentionally diverges from V8 — performance ceilings, host integrations, and design trade-offs."
---

# Known limitations

Phasis aims to behave identically to V8 on every observable spec test. This page lists the places where that identity ends in practice — usually because of fundamental differences between a tree-walking PHP interpreter and a JIT-compiled C++ engine.

## Performance ceiling

The engine is a tree-walker with an opportunistic bytecode VM and a JsToPhp transpiler that lowers numeric / locals-heavy bodies (including `obj.method()`, `new Ctor()`, and `this.member` shapes) to native PHP closures that PHP 8.5's tracing JIT can compile to machine code. Most hot dispatch happens inside the VM's switch loop or directly in JIT-compiled PHP — not by walking the AST — but each VM opcode still pays a PHP dispatch tax, and any shape JsToPhp doesn't yet handle falls back to the slower VM. The ceiling on dispatch-bound workloads is roughly **~100× slower than V8**.

For embedding workloads — running user-supplied logic on PHP data, content-transformation pipelines, validation rules — the ceiling rarely matters. V8 is 100 ms; Phasis is 10 s; both are well below the request timeout. Closing more of the gap would require teaching JsToPhp more shapes (parameter type inference, `await` / `yield*` lowering) or emitting opcache opcodes directly rather than through `eval()`. See [Benchmarks § Roadmap](/docs/advanced/benchmarks#roadmap).

## Multi-agent concurrency

JavaScript's `SharedArrayBuffer` + `Atomics.wait` + `postMessage` model assumes the host can run real OS threads. PHP can't (without `pcntl_fork`, which isn't available on every host).

Phasis simulates a single agent — `Atomics.wait` returns "not-equal" or "timed-out" immediately, `notify` always returns 0. This passes every test262 test that runs single-agent (input validation, error paths, no-op notify), which covers 376 of 376 Atomics tests.

Tests that actually require two concurrent agents observing each other are not supported. Use a worker queue at the PHP level (Redis, Beanstalkd, …) for true parallelism.

## Realm semantics

`ShadowRealm` is implemented but each child realm runs in the same PHP process as the host. Host functions can leak references across realms unless you're careful to keep PHP code firewalled. The spec allows this; V8 enforces stricter isolation via separate v8::Isolate instances.

`createRealm()` (test262 host hook) creates a fresh `Phasis\Engine` internally and bridges the standard library across.

## Regex engine choice

Phasis uses PHP's PCRE2 as the primary regex backend with a custom matcher for cases PCRE2 can't handle exactly (lookbehind with captures, quantified groups with reset, certain `/v`-flag semantics). The custom matcher is slower than PCRE2 and is only used when the dispatch detects an incompatible pattern.

The notable consequence: PCRE2 may have a different case-folding table than V8's ICU version, especially on older Linux distros (Ubuntu 22.04 ships ICU 70; Phasis bundles an ICU 76+ fold table to override host drift on `/iu` patterns).

## Memory model

PHP's reference-counting GC differs from V8's tracing collector. Cycles between PHP and JS (a PHP object held by a JS closure that's in turn referenced by the PHP object) are not collected until the `Engine` is destroyed. Use `WeakMap` / `WeakRef` for caches you don't want to keep alive.

`process.memoryUsage()` and similar V8-specific introspection APIs are not implemented.

## Module loader

The ES module loader resolves relative imports against the file system but does not understand bundler conventions:

- No `node_modules` resolution.
- No `package.json` `exports` map.
- No file-extension inference (you must write `import './foo.mjs'`, not `import './foo'`).
- No HTTP imports.

This matches the bare ECMAScript spec but not Node's behaviour. To use npm packages, either inline-bundle them with a tool like esbuild before passing the bundle to Phasis, or register a custom module loader via `Engine::setModuleResolver()`.

## Wasm

`WebAssembly` is not implemented. There's no PHP wasm runtime to bind to.

## Browser-only Web APIs we skip

Phasis ships the full **Web Platform Pack** (`URL`, `TextEncoder`/`TextDecoder`, `atob`/`btoa`, `structuredClone`, `performance`, `DOMException`), the full **Fetch Pack** (`fetch`, `Request`, `Response`, `Headers`, `Body`, `AbortController`/`AbortSignal`, `Blob`/`File`, `FormData`, `EventTarget`/`Event`, full WHATWG Streams, `navigator`), plus `crypto` (`getRandomValues`, `randomUUID`, full `SubtleCrypto`: digest, HMAC, AES-GCM/CBC/CTR, RSA-OAEP/PSS/PKCS1, ECDSA, ECDH, HKDF, PBKDF2), `WebSocket` (constructor + RFC 6455 frame codec, swappable transport), `XMLHttpRequest` (layered over the fetch transport), and a real event loop (`setTimeout`, `setInterval`, `clearTimeout`, `clearInterval`, `queueMicrotask`, `AsyncContext`). See [Web APIs](/docs/compatibility/web-apis).

What's *not* shipped:

- `window`, `document`, the DOM tree — Phasis isn't a browser.
- `localStorage`, `sessionStorage`, `IndexedDB` — host-side concerns; the embedder owns persistence.
- `Worker`, `SharedWorker`, `MessageChannel` — needs real OS threads, which PHP doesn't offer.
- Service Workers, Push API, Notifications, BroadcastChannel — irrelevant outside a browser.

## Node globals

`process`, `Buffer`, `__dirname`, `__filename`, `require()` — none of these exist either. They're Node host conventions, not language features.

If you're porting Node code, replace `require('foo')` with `import 'foo'`, swap `process.env.X` for a host-bound `env.X`, and pass `__dirname`-equivalent paths in from PHP.

## Decimal precision

ECMAScript numbers are IEEE 754 doubles. Decimal arithmetic with more than 15 significant digits loses precision exactly like every other JS engine. Use `BigInt` for exact integer math or use a JS decimal library if you need exact fractional math.

PHP's `bcmath` is used internally for `BigInt`; the only real difference vs V8 is that very large bigint operations are slower in PHP than in V8's C++ implementation. Numeric correctness is identical.
