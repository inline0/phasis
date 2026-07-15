---
title: "Phasis"
description: "Pure PHP JavaScript engine. Full ES2024+ at 100% test262, plus Web Platform Pack and Fetch Pack at 100% WPT — fetch, Streams, Blob, FormData, URL, AbortSignal."
path: "."
order: 0
section: "Documentation"
meta_title: "Phasis"
meta_description: "Pure PHP JavaScript engine. Full ES2024+ at 100% test262, plus Web Platform Pack and Fetch Pack at 100% WPT — fetch, Streams, Blob, FormData, URL, AbortSignal."
---

# Phasis

Phasis is a pure-PHP JavaScript engine. It lexes, parses, and executes ECMAScript in the host PHP process — without `exec('node …')`, without FFI, and without binary extensions beyond `ext-mbstring` (always shipped) and `ext-bcmath` (default on every mainstream PHP build). `ext-intl` is optional. `ext-curl` enables outbound `fetch()`.

- **Embeddable** — drop a JS runtime into any PHP app, framework, or CLI tool via the `Phasis\Engine` class.
- **Spec-compliant** — passes the official test262 suite at 100 %, every category, no skips.
- **Web APIs** — full **Web Platform Pack** (URL, TextEncoder/Decoder, atob/btoa, structuredClone, performance, DOMException), full **Fetch Pack** (fetch, Request, Response, Headers, Body, AbortController/Signal, Blob, File, FormData, EventTarget, full WHATWG Streams, navigator), plus `crypto` + `SubtleCrypto`, `WebSocket`, `XMLHttpRequest`, and a real event loop. 100 % of imported Web Platform Tests pass.
- **Modern surface** — arrow functions, classes, async/await, Promises, generators, Symbol, BigInt, Proxy, Reflect, Temporal, Intl, TypedArrays, full module support.
- **Direct PHP↔JS interop** — share PHP objects with JS without serialization. Bind PHP closures as JS functions and mutate PHP arrays from JS in place.

## Why Phasis exists

PHP applications that need to run user-supplied JavaScript — templating engines, frontend SSR shims, data-validation rules, content sandboxes, headless test runners — usually shell out to Node.js or skip the feature. Either path adds operational complexity: a second runtime, a serialization boundary, a network of subprocess pipes, and a hostile deployment story for shared hosting.

Phasis closes that gap. Your PHP code can lex, parse, and execute JavaScript using only the standard library plus `mbstring` + `bcmath`. The engine ships as a Composer package, runs anywhere PHP 8.2+ runs, and exposes a single small API surface.

## What it does

- Implements the full ECMAScript standard library: `Array`, `String`, `Object`, `Math`, `JSON`, `Date`, `RegExp`, `Map`, `Set`, `Promise`, `Proxy`, `Reflect`, `Symbol`, `BigInt`, `TypedArray`, `Temporal`, `Intl`, generators, async iterators, modules.
- Ships the **Web Platform** APIs that real-world JS libraries pre-check for: `URL`, `URLSearchParams`, `TextEncoder`/`TextDecoder`, `atob`/`btoa`, `structuredClone`, `performance`, `DOMException`. See [Web APIs](/docs/compatibility/web-apis) for the full surface.
- Ships **`fetch`** with the full Fetch family — `Request`, `Response`, `Headers`, body consumers, `AbortController`/`AbortSignal`, `Blob`, `File`, `FormData`, `EventTarget`/`Event`, and full WHATWG Streams (`ReadableStream`, `WritableStream`, `TransformStream`, BYOB, queuing strategies). Real HTTP works out of the box via PHP's `ext-curl`.
- Ships **`crypto`** + full `SubtleCrypto` (SHA family, HMAC, AES-GCM/CBC/CTR, RSA-OAEP/PSS/PKCS1, ECDSA, ECDH, HKDF, PBKDF2), **`WebSocket`** (RFC 6455 + swappable transport), and **`XMLHttpRequest`** (layered over the same fetch transport).
- Ships a real **event loop**: `setTimeout`, `setInterval`, `clearTimeout`, `clearInterval`, `queueMicrotask`, plus Stage-3 `AsyncContext` for value propagation across async boundaries.
- Supports modern syntax — destructuring, spread/rest, computed properties, optional chaining, nullish coalescing, template literals, tagged templates, classes (with private fields, static blocks, decorators), top-level await.
- Bridges PHP and JS values losslessly. Pass a PHP object into JS and mutations are visible to PHP. Pass a PHP callable and JS sees it as a function.
- Sandboxes execution. Resource limits cover call depth, loop iterations, string length, console output, and wall-clock time. The host controls the runtime, including the `fetch` transport and policy hooks.
- Verifies every change against Node.js (V8). Compliance is measured continuously against the official test262 suite plus the Web Platform Tests in CI on every push.

## What it is not

Phasis is not a JIT compiler. It is a tree-walking interpreter with an opportunistic bytecode VM. Expect ~100× the runtime of V8 on dispatch-bound workloads. The trade-off is zero dependencies, pure PHP, and host-controlled execution — perfect for embedding JavaScript into PHP apps where correctness and isolation matter more than raw throughput.

## Where to start

- [Getting Started](/docs/getting-started) — install and run your first script.
- [CLI](/docs/cli) — `bin/phasis` for running files, evaluating expressions, and the REPL.
- [API](/docs/api) — embed `Phasis\Engine` in your PHP app.
- [Interop](/docs/interop) — share PHP values and objects with JavaScript.
- [Compatibility](/docs/compatibility) — test262 coverage and supported features.
- [Advanced](/docs/advanced) — architecture, bytecode VM, benchmarks, and the oracle testing model.
