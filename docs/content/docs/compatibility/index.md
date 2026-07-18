---
title: "Overview"
description: "How Phasis compares to V8 (Node, Chrome), SpiderMonkey, and JavaScriptCore. test262 conformance, Web Platform Tests pass rates, real-world library byte-equality, and known limitations."
path: "compatibility"
order: 90
section: "Compatibility"
meta_title: "Overview"
meta_description: "How Phasis compares to V8 (Node, Chrome), SpiderMonkey, and JavaScriptCore. test262 conformance, Web Platform Tests pass rates, real-world library byte-equality, and known limitations."
---

# Compatibility

Phasis is verified against three independent oracles on every push:

1. **test262** — the official TC39 ECMAScript conformance suite. **100 %.**
2. **Web Platform Tests** — the official WHATWG / W3C suite for non-ECMAScript Web APIs (fetch, XHR, WebSocket, Streams, Headers, Blob, encoding, URL, structured-clone, abort, hr-time, atob, crypto). **100 %** on the imported corpus.
3. **Popular libraries** — 100+ widely-used npm packages (acorn, lodash, zod, ramda, immer, redux, date-fns, marked, fuse.js, semver, validator, …) execute byte-equal to Node.js on every push.

## vs other engines

| Engine | test262 | Notes |
|---|---:|---|
| **Phasis** | **100 %** | Pure PHP. Targets ES2024+. |
| V8 (Node, Chrome) | 99.8 % | Reference C++ engine. |
| SpiderMonkey (Firefox) | 99.6 % | Reference C++ engine. |
| JavaScriptCore (Safari) | 99.4 % | Reference C++ engine. |
| QuickJS | ~97 % | Lightweight embeddable engine in C. |
| Hermes (React Native) | ~95 % | Embeddable engine optimized for mobile. |

Numbers from [test262.fyi](https://test262.fyi). Phasis sits in the top tier alongside the production browser engines.

## Pages

- **[test262 coverage](/docs/compatibility/test262)** — what's tested, how the suite runs, why 100% matters.
- **[Web APIs (WPT)](/docs/compatibility/web-apis)** — Web Platform Pack and Fetch Pack: URL, encoding, atob/btoa, structuredClone, performance, Headers, Blob, AbortSignal, EventTarget, Streams, plus crypto, WebSocket, and XMLHttpRequest.
- **[Streams](/docs/compatibility/streams)** — full WHATWG Streams Standard: ReadableStream, WritableStream, TransformStream, BYOB, tee, pipeTo/pipeThrough, queuing strategies, async iteration.
- **[Popular packages](/docs/compatibility/popular-packages)** — CI-gated byte-equality with Node.js for 104 npm packages across parsing, templating, validation, date/time, math, hashing, search/diff, color, URL handling, and more.
- **[Spec surface](/docs/compatibility/spec-surface)** — every ECMAScript built-in, syntactic feature, and standard-library object that Phasis ships.
- **[Known limitations](/docs/compatibility/limitations)** — where Phasis intentionally diverges from V8: performance ceiling, multi-agent concurrency, Wasm, browser-only Web APIs.

## How it's measured

- **`bin/test262`** runs the full ECMAScript suite. Results committed to [`COMPAT.md`](https://github.com/inline0/phasis/blob/main/COMPAT.md) on every successful CI run.
- **`bin/wpt`** runs the imported Web Platform Tests fixtures.
- **`composer test`** runs PHPUnit including the popular-library byte-equality suite under `tests/Popular/`. The same suite re-runs in CI under real Node so a stale oracle is caught the moment a vendored library bumps.

A regression in any of the three blocks the PR.
