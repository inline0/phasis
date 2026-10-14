---
title: "Introduction"
description: "Pure PHP JavaScript engine. Full ES2024+ at 100% test262, plus Web Platform Pack and Fetch Pack at 100% WPT — fetch, Streams, Blob, FormData, URL, AbortSignal."
path: "."
order: 0
meta_title: "Introduction"
meta_description: "Pure PHP JavaScript engine. Full ES2024+ at 100% test262, plus Web Platform Pack and Fetch Pack at 100% WPT — fetch, Streams, Blob, FormData, URL, AbortSignal."
---

# Introduction
Phasis is a pure-PHP JavaScript engine. It lexes, parses, and executes ECMAScript in the host PHP process — without `exec('node …')`, without FFI, and without a custom binary extension. It requires three standard PHP extensions: `ext-mbstring`, `ext-bcmath` and `ext-curl` (the default `fetch()` transport). `ext-intl` and `ext-openssl` are optional. See [Getting Started](/docs/getting-started) for builds that leave `bcmath` out.

- **Embeddable** — drop a JS runtime into any PHP app, framework, or CLI tool via the `Phasis\Engine` class.
- **Spec-compliant** — passes the official test262 suite at 100 %, every category, no skips.
- **Web APIs** — full **Web Platform Pack** (URL, TextEncoder/Decoder, atob/btoa, structuredClone, performance, DOMException), full **Fetch Pack** (fetch, Request, Response, Headers, Body, AbortController/Signal, Blob, File, FormData, EventTarget, full WHATWG Streams, navigator), plus `crypto` + `SubtleCrypto`, `WebSocket`, `XMLHttpRequest`, and a real event loop. 100 % of imported Web Platform Tests pass.
- **Modern surface** — arrow functions, classes, async/await, Promises, generators, Symbol, BigInt, Proxy, Reflect, Temporal, Intl, TypedArrays, full module support.
- **PHP↔JS interop** — pass PHP scalars and arrays into JS (arrays are copied), bind PHP closures as JS functions, and call JS functions from PHP. PHP objects expose public properties and methods by reference, while JS callbacks become callable PHP closures; see [Shared objects](/docs/interop/shared-objects).

## Why Phasis exists

PHP applications that need to run user-supplied JavaScript — templating engines, frontend SSR shims, data-validation rules, content sandboxes, headless test runners — usually shell out to Node.js or skip the feature. Either path adds operational complexity: a second runtime, a serialization boundary, a network of subprocess pipes, and a hostile deployment story for shared hosting.

Phasis closes that gap. Your PHP code can lex, parse, and execute JavaScript using only PHP 8.2+ with `mbstring`, `bcmath` and `curl`. The engine ships as a Composer package and exposes a single small API surface.

## What it does

- Implements the full ECMAScript standard library: `Array`, `String`, `Object`, `Math`, `JSON`, `Date`, `RegExp`, `Map`, `Set`, `Promise`, `Proxy`, `Reflect`, `Symbol`, `BigInt`, `TypedArray`, `Temporal`, `Intl`, generators, async iterators, modules.
- Ships the **Web Platform** APIs that real-world JS libraries pre-check for: `URL`, `URLSearchParams`, `TextEncoder`/`TextDecoder`, `atob`/`btoa`, `structuredClone`, `performance`, `DOMException`. See [Web APIs](/docs/compatibility/web-apis) for the full surface.
- Ships **`fetch`** with the full Fetch family — `Request`, `Response`, `Headers`, body consumers, `AbortController`/`AbortSignal`, `Blob`, `File`, `FormData`, `EventTarget`/`Event`, and full WHATWG Streams (`ReadableStream`, `WritableStream`, `TransformStream`, BYOB, queuing strategies). Real HTTP works out of the box via PHP's `ext-curl`.
- Ships **`crypto`** + full `SubtleCrypto` (SHA family, HMAC, AES-GCM/CBC/CTR, RSA-OAEP/PSS/PKCS1, ECDSA, ECDH, HKDF, PBKDF2), **`WebSocket`** (RFC 6455 + swappable transport), and **`XMLHttpRequest`** (layered over the same fetch transport).
- Ships a real **event loop**: `setTimeout`, `setInterval`, `clearTimeout`, `clearInterval`, `queueMicrotask`, plus Stage-3 `AsyncContext` for value propagation across async boundaries.
- Supports modern syntax — destructuring, spread/rest, computed properties, optional chaining, nullish coalescing, template literals, tagged templates, classes (with private fields, static blocks, decorators), top-level await.
- Converts values across the PHP↔JS boundary. Scalars and arrays are copied in, PHP callables become JS functions, and JS results come back as PHP scalars and arrays. See [Value conversion](/docs/interop/values) for the exact rules.
- Runs under host control. The host owns the `fetch` transport and policy hooks, the event loop runs on virtual time, and a fixed call-stack ceiling stops runaway recursion. A loop-iteration limit exists, but it is not yet enforced on loops the bytecode VM runs, and there are no string-length, output or wall-clock limits; see [API: setLimit](/docs/api#setlimit).
- Is verified against Node.js (V8). CI runs the unit and oracle suites on every push to `main` and every pull request, and the Web Platform Tests and popular-package suites when the engine source changes; the full test262 matrix is run by hand and its snapshot committed to `COMPAT.md`. See [Compatibility](/docs/compatibility).

## What it is not

Phasis is not a JIT compiler. It is a tree-walking interpreter with an opportunistic bytecode VM. Expect ~100× the runtime of V8 on dispatch-bound workloads. The trade-off is zero dependencies, pure PHP, and host-controlled execution — perfect for embedding JavaScript into PHP apps where correctness and isolation matter more than raw throughput.

## Where to start

- [Getting Started](/docs/getting-started) — install and run your first script.
- [CLI](/docs/cli) — `bin/phasis` for running files, evaluating source, and dumping the AST.
- [API](/docs/api) — embed `Phasis\Engine` in your PHP app.
- [Interop](/docs/interop) — pass PHP values and callables to JavaScript.
- [Compatibility](/docs/compatibility) — test262 coverage and supported features.
- [Parser](/docs/parser) — parse JavaScript into a typed AST or ESTree without executing it.
- [Formatter](/docs/formatter) — format JavaScript, JSON and CSS with prettier-compatible layout.
- [Advanced](/docs/advanced) — architecture, bytecode VM, benchmarks, and the oracle testing model.
