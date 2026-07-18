# PRD: Web Platform Pack

**Status**: Draft for review
**Author**: this session
**Last updated**: 2026-05-14

## Summary

Phasis currently ships only the ECMAScript (TC39) spec — 50,506 / 50,506 test262 pass. Real-world JavaScript libraries also assume a small set of WHATWG / W3C Web Platform APIs exist. This PRD scopes a "Web Platform Pack" that adds the five most universally-assumed APIs as pure-PHP implementations, with no host I/O.

## Goals

Add the following globals so JS libraries that pre-check for them via `typeof X` work without a host bridge:

| API | Spec | Owner |
|---|---|---|
| `URL`, `URLSearchParams` | [WHATWG URL](https://url.spec.whatwg.org/) | WHATWG |
| `TextEncoder`, `TextDecoder` | [WHATWG Encoding](https://encoding.spec.whatwg.org/) | WHATWG |
| `atob`, `btoa` | [HTML §8.7](https://html.spec.whatwg.org/multipage/webappapis.html#atob) | WHATWG |
| `structuredClone` | [HTML §2.7](https://html.spec.whatwg.org/multipage/structured-data.html#structured-cloning) | WHATWG |
| `performance.now()` | [High-Res Time](https://w3c.github.io/hr-time/) | W3C |

## Non-goals

- `fetch` / `Request` / `Response` / `Headers` — out of scope; reserved for a future "Fetch Pack" PRD with explicit host bridging design. Doing fetch right is a multi-week project (streams, AbortController, redirect handling, mixed content, CORS-ish gating).
- `setTimeout` / `setInterval` / `queueMicrotask` — out of scope; needs a real event-loop model and changes Phasis from "synchronous embeddable" to "stateful runtime". Worth its own design phase.
- `crypto.subtle` / `WebSocket` / `Blob` / `File` / `FormData` — out of scope; each is its own large spec surface.
- `localStorage` / `sessionStorage` — out of scope; needs persistence model (in-memory vs filesystem vs host bridge).

## Why these five specifically

All five are:
- **Pure data transformation** — no I/O, no network, no host state, no event loop.
- **Universally pre-checked** — modern JS libraries (`qs`, `uuid`, `jose`, JWT decoders, MIME parsers, Mustache itself once internationalized, `nanoid`, etc.) gate features on `typeof URL !== 'undefined'` or import polyfills if the global is missing.
- **Spec-stable** — these specs haven't materially changed in 5+ years; implementing once stays correct.
- **Small** — each is ~200–800 lines of pure PHP.

## Detailed scope per API

### 1. `URL` + `URLSearchParams`

Spec: <https://url.spec.whatwg.org/>

Properties (all per-spec, with full state-machine parser):
- `URL`: `href` / `origin` / `protocol` / `username` / `password` / `host` / `hostname` / `port` / `pathname` / `search` / `searchParams` (live) / `hash`
- `URL.canParse(input, base?)` — newer static method
- `URLSearchParams`: iterable; `get` / `getAll` / `has` / `set` / `append` / `delete` / `entries` / `keys` / `values` / `sort` / `size` / `forEach` / `toString`
- `URLSearchParams` constructor accepts string / array-of-pairs / record / another URLSearchParams
- Punycode for IDN hostnames

**Estimated size**: 600–800 lines. The state-machine parser is the bulk; everything else is property accessors.

### 2. `TextEncoder` / `TextDecoder`

Spec: <https://encoding.spec.whatwg.org/>

- `TextEncoder` is UTF-8 only per spec. `encode(string)` → `Uint8Array`. `encodeInto(string, Uint8Array)` for in-place.
- `TextDecoder` supports `'utf-8'`, `'utf-16le'`, `'utf-16be'`, and the legacy single-byte encodings the spec lists. Options: `fatal`, `ignoreBOM`. `decode(input, {stream})` for streaming.
- Lone-surrogate handling per spec (`fatal` throws, default emits U+FFFD).

**Estimated size**: 300–400 lines. PHP's `mb_convert_encoding` covers the common encodings; the tricky part is lone-surrogate handling and the streaming buffer.

### 3. `atob` / `btoa`

Spec: <https://html.spec.whatwg.org/multipage/webappapis.html#atob>

- `btoa(input)` — input must be Latin-1 (each codepoint < 256); throws `InvalidCharacterError` otherwise. Returns base64-encoded ASCII.
- `atob(input)` — input is forgiving base64 (strips whitespace, validates remaining chars + padding); throws `InvalidCharacterError` if invalid. Returns Latin-1 string.

**Estimated size**: ~80 lines. PHP's `base64_encode` / `base64_decode` get you most of the way; the rest is the spec's character-class checks and DOMException-shaped errors.

### 4. `structuredClone`

Spec: <https://html.spec.whatwg.org/multipage/structured-data.html#structured-cloning>

- Deep-clone of any cloneable JS value with full cycle support (one-shot map of seen objects → clones).
- Handles: primitives, plain objects, arrays, Date, RegExp, Map, Set, Error and subclasses, ArrayBuffer, TypedArrays, DataView, Blob/File (out of scope here — throw DataCloneError if encountered).
- `transfer` option for ArrayBuffer detach semantics (we already model these).
- Throws `DataCloneError` on uncloneable types (Function, Symbol unless it's a registered symbol, WeakMap/WeakSet/WeakRef, DOM nodes, etc.).

**Estimated size**: 400–500 lines. The recursive walker is short; correctness lives in the dispatch table for every JS type.

### 5. `performance.now()` (and minimal `performance` object)

Spec: <https://w3c.github.io/hr-time/>

- `performance.now()` returns a high-resolution monotonic time in milliseconds, floating-point, relative to engine start. PHP `hrtime(true)` provides nanosecond precision.
- Bonus: `performance.timeOrigin` (Unix ms at engine start).

**Estimated size**: ~50 lines. Trivial PHP wrapper.

## Architecture

Implementation pattern matches Phasis's existing `src/BuiltIn/*Constructor.php` files:

- `src/BuiltIn/UrlConstructor.php` (installs `URL` + `URLSearchParams`)
- `src/BuiltIn/TextEncoderConstructor.php` (installs `TextEncoder` + `TextDecoder`)
- `src/BuiltIn/Base64Functions.php` (installs `atob` / `btoa` as globals)
- `src/BuiltIn/StructuredCloneFunction.php` (installs `structuredClone` as global)
- `src/BuiltIn/PerformanceObject.php` (installs `performance` global)

Wire each into `Engine::installBuiltins()` after the existing builtins. Each builtin is gated by no host condition — they're always available.

Following Phasis tradition: if any internal helpers cross ~500 lines we apply the trait pattern (`src/BuiltIn/Url/`, etc.) up front.

## Testing strategy

**Test262 doesn't cover any of this** — it's the ECMAScript suite. The canonical test source is **Web Platform Tests (WPT)** at <https://github.com/web-platform-tests/wpt>:

| Pack | WPT subtree | Approx test count |
|---|---|---:|
| URL + URLSearchParams | `url/` | ~1,200 |
| TextEncoder/Decoder | `encoding/` | ~400 |
| atob/btoa | `html/webappapis/atob/` | ~60 |
| structuredClone | `html/webappapis/structured-clone/` | ~200 |
| performance.now | `hr-time/` | ~20 |

Total: ~1,880 spec-conformance tests, each runnable headless.

WPT tests use `testharness.js` (its own minimal test framework). We need a small WPT runner — same shape as the existing test262 runner:

1. `bin/wpt` CLI mirroring `bin/test262` (per-file run, JSON output)
2. Vendored `wpt/resources/testharness.js` (small, ~3K lines)
3. `tests/Wpt/WptRunner.php` (PHPUnit DataProvider that walks `wpt/<area>/` and executes each fixture through Phasis)
4. CI workflow `wpt.yml` mirroring `compat-matrix.yml` (shards by area, commits a `WPT.md` snapshot)
5. New "wpt" PHPUnit testsuite in `phpunit.xml.dist`

Existing `tests/Popular/` smoke-tests (acorn / mustache / lodash / marked) stay — they're real-library integration coverage that's complementary to WPT's spec coverage.

## Acceptance criteria

For each API:

1. **`typeof X !== 'undefined'`** returns `true` after a fresh `new Engine()`.
2. **WPT pass rate ≥ 95%** for that API's subtree (matches Bun's and Deno's published numbers for these specific specs).
3. **No regression** in test262 (50,506 / 50,506 stays).
4. **No regression** in popular packages (acorn / mustache / lodash / marked still byte-equal to Node).
5. **bin/verify-all green** (PHPStan, PHPCS, PHPUnit).

For the pack overall:

6. CI gates: new `wpt.yml` workflow runs on push to `main` and PRs.
7. Documentation: `docs/web-platform.md` enumerates what's shipped and what's not.
8. README compatibility section gains a "Web Platform" subsection alongside test262.
9. Composer requirements unchanged (still `ext-mbstring + ext-bcmath`, no new extensions).

## Phased delivery

Each API is independent, so we ship in order of risk × value:

| Phase | API | Why this order |
|---|---|---|
| 1 | `atob` / `btoa` + `performance.now()` | Trivial; proves the install-as-global pipeline |
| 2 | `TextEncoder` / `TextDecoder` | Pure encoding; lots of libraries use it |
| 3 | `URL` / `URLSearchParams` | Biggest API, biggest payoff; the state-machine parser is the hardest part |
| 4 | `structuredClone` | Needs every other JS type already in place; safest done last |
| 5 | WPT runner + CI gate | Lands once with all five APIs at ≥ 95% |

Each phase is one commit, each phase verifies test262 still green, then the WPT runner lands at the end as a single PR.

## Risks

- **WPT runner false positives**: WPT tests assume a browser context. Some test fixtures load `<script src=…>` or use `document.title`. The runner needs to detect "not applicable" fixtures and skip them — same heuristic test262 uses for browser-only features. Mitigation: start with the deepest pure-JS leaf subtrees (`url/`, `encoding/`) which barely touch the DOM at all.
- **IDN hostnames in URL**: full UTS#46 / Punycode is ~5K lines of Unicode data. Phasis already ships Unicode 16 tables; the parser can hook into those. Worst case we ship without IDN and add it as phase 3.5.
- **structuredClone + transfer for shared ArrayBuffers**: SharedArrayBuffer transfer semantics interact with the existing fiber runtime. Worth a one-day spike before committing.

## Open questions

1. **DOMException vs TypeError**: WHATWG specs throw `DOMException` (a separate exception type with `name` / `message` / `code`). Phasis currently throws TypeErrors / RangeErrors. Either we ship a minimal `DOMException` global, or we map spec errors to existing PhasisError types and document the difference. Recommendation: ship the global — it's ~50 lines and other Web APIs will need it.
2. **`performance.mark` / `performance.measure`**: nice-to-haves on top of `now()`. Defer to a follow-up unless a popular library wants them.
3. **Should `URL` accept a `Phasis\Url` PHP value via `setGlobal`?** I.e., PHP-side URL construction with the JS URL prototype. Probably yes — matches the existing PHP↔JS interop. ~50 lines for the bridge.

## Out-of-scope (explicitly deferred)

- Fetch Pack (`fetch` / `Request` / `Response` / `Headers`) — needs its own PRD covering host bridging, security model, and the streaming-body design.
- Timer Pack (`setTimeout` / `setInterval` / `queueMicrotask`) — needs Phasis's runtime model to evolve from "synchronous embeddable" to "stateful with an event loop". Affects every embedding.
- Crypto Pack (`crypto.subtle`, `getRandomValues`) — usable in security-sensitive contexts; needs explicit threat model.

## Decision needed

If approved, kickoff sequence:

1. Vendor `wpt/resources/testharness.js` and write `tests/Wpt/WptRunner.php` skeleton
2. Ship Phase 1 (atob/btoa + performance.now) — proves the pattern
3. Ship Phases 2–4 in order
4. Land `wpt.yml` workflow + new `wpt` PHPUnit testsuite
5. Documentation pass
