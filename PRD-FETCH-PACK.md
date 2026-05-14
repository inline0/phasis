# PRD: Fetch Pack

**Status**: Approved — execution in progress

**Sign-off decisions (2026-05-14)**:
1. fetch **default-on**, no policy gating
2. Cookie jar **opt-in** via `setCookieJar()`
3. **Full Streams API** (not minimal subset) — ReadableStream, WritableStream, TransformStream, queuing strategies, BYOB
4. **Ship our own** WPT test server (`tests/Wpt/fetch-server.php`)
5. **Bundle `navigator`** global with `userAgent: "Phasis/X"`
6. **Local testing only** — no CI triggers, iterate until 100% WPT pass rate against the fetch + streams + Blob/File + FormData + AbortController + EventTarget areas
**Author**: this session
**Last updated**: 2026-05-14
**Builds on**: PRD-WEB-PLATFORM-PACK.md (which shipped `URL` / `URLSearchParams` / `TextEncoder` / `TextDecoder` / `atob` / `btoa` / `structuredClone` / `performance.now` / `DOMException` — all prerequisites for fetch)

## Summary

Ship the WHATWG Fetch API as a complete, spec-grade subsystem in Phasis. Includes `fetch()` plus every value type the spec defines (`Request`, `Response`, `Headers`, the `Body` mixin, `AbortController`, `AbortSignal`, `Blob`, `File`, `FormData`, `EventTarget`, `Event`). HTTP transport is a pluggable layer with a default PHP-curl backend and a security policy hook so embedders can deny, allowlist, or transform requests.

## Goals

After this lands, the following JavaScript runs end-to-end in Phasis without any host-side setup:

```js
const res = await fetch("https://api.example.com/data", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ x: 1 }),
    signal: AbortSignal.timeout(5000),
});
if (!res.ok) throw new Error("HTTP " + res.status);
const data = await res.json();
```

Plus the constructor surface for libraries that just want value types:

```js
const headers = new Headers({ "X-Api-Key": "abc" });
const req = new Request("https://...", { method: "POST", headers, body: new FormData() });
const blob = new Blob(["hello"], { type: "text/plain" });
```

## Non-goals

- **Streams API** in full. We implement a minimal `ReadableStream`-shaped wrapper around the response body — enough for `.body.getReader()` and `for-await-of` consumption. Full `pipeTo` / `pipeThrough` / `WritableStream` / `TransformStream` are out of scope.
- **Service Workers / Workers**. No `Worker`, no `MessageChannel`, no SW fetch interception.
- **`XMLHttpRequest`**. Legacy, deferred indefinitely.
- **WebSocket**. Different transport, separate PRD when desired.
- **CORS as browsers implement it**. Phasis isn't a browser; same-origin / preflight don't apply. We do ship `mode` / `credentials` / `referrerPolicy` properties for spec round-trip, but the values don't gate the request the way they would in a browser.
- **Subresource Integrity (`integrity`)**. Skip unless a real consumer needs it.
- **`Range` request partial-content streaming with `seek`**. Use full-body reads for v1.

## Why this is worth a multi-week project

Universal: `fetch` is the single most-requested missing API. Every modern JS library either uses it directly or wraps it. Adding it removes the biggest "needs a host bridge" gotcha for Phasis embeddings.

Spec-stable: WHATWG Fetch hasn't materially changed in 3+ years. Pay the cost once, stays correct.

Composable: the value types (`Headers`, `Request`, `Response`, `Body`) are also used outside fetch — by tools that parse HTTP, build HTTP request messages, etc. Shipping them as standalone globals is independent value.

## Architecture

### Layer 0: Foundations (must land first)

- **`EventTarget` / `Event`** — common base for AbortSignal, FormData (no events, but extends), and future APIs.
- **`Blob`** — immutable binary chunk with a `type` and a byte source. Composed from strings / ArrayBuffers / TypedArrays / other Blobs. `slice()`, `arrayBuffer()`, `text()`, `bytes()`, `stream()`.
- **`File`** — `Blob` subclass with a name + lastModified.
- **`FormData`** — multipart form data. Iterable. Used as request body, serialized to multipart/form-data on the wire.

### Layer 1: Cancellation

- **`AbortController`** — owns an `AbortSignal`. `abort(reason?)` fires the signal.
- **`AbortSignal`** — has `aborted`, `reason`, `throwIfAborted()`, `onabort`, plus statics `AbortSignal.abort(reason?)`, `AbortSignal.timeout(ms)`, `AbortSignal.any(signals[])`. Inherits `EventTarget` so listeners can attach.

### Layer 2: HTTP value types

- **`Headers`** — case-insensitive name lookup, multi-value via append, sorted iteration. `append`, `delete`, `get`, `getSetCookie`, `has`, `set`, plus iteration + `entries` / `keys` / `values` / `forEach`. Guards: "immutable", "request", "request-no-cors", "response", "none" per spec — for v1 we ship "none" (no guard) and document the gap; setting guards is internal to Request/Response construction.
- **`Body`** mixin (`text()`, `json()`, `arrayBuffer()`, `blob()`, `formData()`, `bytes()`, `body` getter returns ReadableStream-shape, `bodyUsed`). Mixed into Request + Response.
- **`Request`** — URL + method + headers + body + cache + credentials + redirect + referrer + referrerPolicy + mode + signal + integrity (stored, not enforced) + keepalive (stored, not honored). `clone()`.
- **`Response`** — body + status + statusText + headers + ok + redirected + type + url. Statics: `Response.error()`, `Response.redirect(url, status)`, `Response.json(data, init?)`. `clone()`.

### Layer 3: Minimal Streams shim

We need `Response.body` to be a ReadableStream because real-world code does `for await (const chunk of response.body)` and `response.body.getReader()`. Full ReadableStream is too big; ship a minimal subset:

- `ReadableStream` constructor accepting `{ start, pull, cancel }` underlying source.
- `getReader()` returning a ReadableStreamDefaultReader with `read()` and `releaseLock()`.
- `[Symbol.asyncIterator]()` for `for await` consumption.
- `cancel(reason)`.

Skip: tee / pipeThrough / pipeTo / WritableStream / TransformStream / BYOB readers / lock guards beyond basic safety. Document the gap.

### Layer 4: fetch + HTTP transport

- **`fetch(input, init?)`** — global function. Returns `Promise<Response>`.
- **HTTP backend interface** — internal `HttpTransport` interface with `send(Request, AbortSignal): Response`. Default implementation `CurlTransport` uses PHP's curl extension (already present per audit fixes).
- **Embedder override** — `$engine->setFetchTransport($customCallable)`. The callable receives a PHP-side request descriptor + AbortSignal, returns a response descriptor. Allows Guzzle / Symfony HttpClient / mock / blocked-by-default.
- **Fetch policy** — `$engine->setFetchPolicy($callable)`. Pre-flight hook that can rewrite the request, deny outright (throw), or allow through. Runs before transport.

### Default security posture

- **Out of the box**: fetch is enabled, follows redirects up to 20, default timeout 30s, no cookies stored, sends a `User-Agent: Phasis/{version}` header.
- **Embedder can lock down**: `$engine->setFetchPolicy(fn ($req) => throw new \RuntimeException('fetch disabled'))` or allowlist via the same hook.
- **No automatic credentials**: `credentials: "include"` is honored only insofar as cookies set on a per-Engine cookie jar (also opt-in).

## Detailed scope per type

### EventTarget / Event

- `EventTarget`: `addEventListener(type, listener, options)`, `removeEventListener`, `dispatchEvent(event)`. Options: `once`, `capture` (no-op since no DOM tree), `passive` (no-op), `signal` (auto-remove on abort).
- `Event`: `type`, `target`, `currentTarget`, `bubbles` (false), `cancelable`, `defaultPrevented`, `preventDefault()`, `stopPropagation()`, `composedPath()` (returns `[target]`). `Event.NONE / CAPTURING_PHASE / AT_TARGET / BUBBLING_PHASE` constants.
- Estimated: ~250 lines.

### Blob / File

- `new Blob(parts, { type })` — `parts` is `Array<BlobPart>` where each part is `Blob`, `BufferSource`, or `USVString`.
- `Blob.prototype.size`, `type`, `slice(start, end, contentType)`, `arrayBuffer()`, `text()`, `bytes()`, `stream()`.
- `File extends Blob` — adds `name`, `lastModified`, `webkitRelativePath` (always "").
- Internal storage: a single PHP byte string (`string`) plus the MIME type. `slice` returns a new Blob over a substring.
- Estimated: ~400 lines.

### FormData

- `new FormData(form?)` — form param is for HTML `<form>` elements; we accept it but it's no-op.
- `append`, `delete`, `get`, `getAll`, `has`, `set`, `entries`, `keys`, `values`, `forEach`. Iterable.
- Values are strings or Blobs (with optional filename for Blob values).
- Serialization: when used as fetch body, encodes as `multipart/form-data; boundary=...`.
- Estimated: ~300 lines.

### AbortController / AbortSignal

- `AbortController`: `signal` getter, `abort(reason?)`.
- `AbortSignal`: `aborted` (read-only), `reason`, `throwIfAborted()`, `onabort` (setter), inherits `EventTarget`. Static: `abort(reason?)`, `timeout(ms)`, `any(signals[])`.
- Aborting fires an `abort` event on the signal (and any signal returned from `AbortSignal.any` containing it).
- Internal: `[[AbortReason]]` slot + linked signals list for `.any()`.
- Estimated: ~300 lines.

### Headers

- Case-insensitive name normalization (lowercase on store, case-preserving on first append's display? — spec says case-insensitive lookup, headers iteration is sorted-lowercase).
- Multi-value via `append`. `set` replaces all values.
- `getSetCookie()` returns `Set-Cookie` headers as a list (Set-Cookie is special — it doesn't combine via comma).
- Iteration order: sorted by lowercase name.
- Forbidden header names per spec (e.g. `Host`, `Connection`, `Cookie` in some modes) — for v1 we accept all of them and rely on the transport to handle.
- Estimated: ~300 lines.

### Body mixin

The mixin is a shared trait both `Request` and `Response` use:

- `body` (getter) — returns `ReadableStream | null` (null for no body).
- `bodyUsed` — true once any consumption method or `body.getReader()` was called.
- `arrayBuffer()` — Promise<ArrayBuffer>
- `blob()` — Promise<Blob>
- `bytes()` — Promise<Uint8Array> (ES2024 addition)
- `formData()` — Promise<FormData> (parses multipart or x-www-form-urlencoded based on Content-Type)
- `json()` — Promise<any>
- `text()` — Promise<string> (UTF-8 decoding always per spec, with replacement chars on invalid bytes)

All return promises that reject with TypeError if body is already consumed (`bodyUsed === true`).

Internal representation: a `JsObject` with `[[Body]]` slot holding either a string (for fully-buffered bodies) or a ReadableStream (for streaming responses from transport). The consumption methods drain the stream if needed, then expose.

- Estimated: ~400 lines (shared between Request and Response).

### Request

- Constructor accepts URL string OR another Request. Init options: `method`, `headers`, `body`, `mode`, `credentials`, `cache`, `redirect`, `referrer`, `referrerPolicy`, `integrity`, `keepalive`, `signal`, `priority`, `window` (must be `null`).
- Read-only properties: every init option plus `url`, `destination`, `bodyUsed`.
- `clone()` produces a deep-ish copy (body is cloned via the stream-or-string mechanism).
- Default values per spec table.
- Body sources accepted: `Blob`, `BufferSource`, `FormData`, `URLSearchParams`, `String`, `ReadableStream`. Each path computes the Content-Type if not set.
- Estimated: ~500 lines.

### Response

- Constructor: `new Response(body?, init?)`. `init` has `status`, `statusText`, `headers`.
- Statics: `Response.error()`, `Response.redirect(url, status=302)`, `Response.json(data, init?)`.
- Read-only properties: `body`, `bodyUsed`, `headers`, `ok`, `redirected`, `status`, `statusText`, `type`, `url`.
- `type` is one of: `"basic"` (default for synthetic), `"cors"` / `"opaque"` / `"opaqueredirect"` (we don't simulate these), `"error"` (for `Response.error()`).
- `clone()` semantics same as Request.
- Estimated: ~400 lines.

### Minimal ReadableStream

- `new ReadableStream({ start(controller), pull(controller), cancel(reason) })` — controller has `enqueue(chunk)`, `close()`, `error(e)`.
- `getReader()` → reader with `read()` Promise<{value, done}>, `releaseLock()`, `closed` Promise.
- `[Symbol.asyncIterator]()` — yields chunks until done.
- `cancel(reason)` — drains + signals upstream.
- No tee, no transform, no writable side.
- Estimated: ~400 lines.

### fetch implementation

- Steps per spec §5.4 — main fetch:
  1. Normalize input → Request.
  2. Run fetch policy (Phasis extension) — may throw, may rewrite.
  3. Check signal: if aborted before send, reject with AbortError DOMException.
  4. Build transport request descriptor: method, URL, headers, body bytes, follow-redirects flag, timeout.
  5. Hand to transport. Transport returns descriptor with status, headers, body stream.
  6. Build Response. Resolve the returned Promise.
- Abort propagation: signal-fires-during-flight → transport sees cancellation, fetch's promise rejects with AbortError.
- Redirect handling: default `follow` mode auto-follows; `error` mode rejects on redirect; `manual` exposes the redirect Response.
- Estimated: ~600 lines (including transport interface, default curl backend, redirect loop, header building).

### Default HttpTransport: PHP curl

- Single class: `Phasis\BuiltIn\Fetch\Transport\CurlTransport`.
- Method: `send(array $requestDesc, ?AbortSignal $signal): array $responseDesc`.
- Uses `curl_init()` + `curl_setopt_array()`. Sets timeouts (`CURLOPT_CONNECTTIMEOUT`, `CURLOPT_TIMEOUT`), header capture via `CURLOPT_HEADERFUNCTION`, body capture via `CURLOPT_WRITEFUNCTION` (streaming-ready).
- AbortSignal integration: install a `CURLOPT_PROGRESSFUNCTION` that checks `$signal->aborted` and returns 1 to abort the transfer.
- Composer requirement: `ext-curl` becomes required (currently undeclared but always present on PHP builds that ship with curl by default — Homebrew, apt, RHEL, Docker `php:cli` all include it). Add to composer.json `require` like ext-bcmath was.

### Embedder hooks

- `Engine::setFetchTransport(callable|FetchTransportInterface $t)`. Default: `new CurlTransport()`. Callable signature: `function (array $request, ?AbortSignal $signal): array $response`. Allows swap to Guzzle, mock, deny-all.
- `Engine::setFetchPolicy(callable $hook)`. Runs before transport. Signature: `function (Request $req): Request|null`. Returning `null` denies (fetch promise rejects with TypeError). Returning a modified Request rewrites the outgoing request. Throwing propagates as fetch rejection.

## Testing strategy

WPT has ~2,000 fetch-related tests across:
- `fetch/api/` — most of it (request, response, headers, body)
- `fetch/h1-parsing/` — HTTP/1 parsing edge cases (skip — transport concern)
- `fetch/orb/` — opaque response blocking (skip — browser-only)
- `fetch/range/` — range requests (skip for v1)
- `fetch/redirect/` — ~100 tests
- `fetch/security/` — most are browser-context (CSP, mixed content); skip
- `xhr/abort/` — relevant for AbortController testing

Plus separate trees we touch:
- `streams/` — ~600 tests, mostly out of scope; cherry-pick the readable-stream subset
- `dom/abort/` — AbortController / AbortSignal ~100 tests
- `dom/events/` — EventTarget ~200 tests
- `FileAPI/` — Blob / File ~150 tests
- `xhr/formdata/` — FormData ~100 tests

Target pass rates per area for v1 (matches Bun's published numbers for similar implementations):

| Area | Target | Notes |
|---|---:|---|
| `Headers` | ≥ 98% | Almost pure data — should be near-perfect |
| `Request` / `Response` | ≥ 90% | Body integration is the hard part |
| `Body` (text/json/arrayBuffer/etc.) | ≥ 95% | Encoding edges |
| `AbortController` / `AbortSignal` | ≥ 95% | Pure value type |
| `EventTarget` / `Event` | ≥ 90% | We're not a DOM; some bubble-related tests N/A |
| `Blob` / `File` | ≥ 95% | Pure value types |
| `FormData` | ≥ 95% | Pure value type, serialization edges |
| `fetch` end-to-end | ≥ 85% | Transport edges, redirect semantics |
| `ReadableStream` (our subset) | ≥ 70% | We don't ship tee/pipe |

WPT runner already exists from the previous PRD. The fetch tests use the same `.any.js` shape, so the runner picks them up automatically when we drop fixtures into `tests/Wpt/fixtures/fetch/`, etc.

**One new requirement for fetch tests**: many require a test HTTP server. WPT provides one via Python's `wptserve`. For Phasis CI we'd either:
- Run `wptserve` locally in CI, OR
- Ship a tiny PHP HTTP server (`-S 127.0.0.1:8765`) with route-based responses to match WPT's expected endpoints (slow to write but standalone), OR
- Use a static `httpbin`-like mock (e.g. https://httpbin.org via outbound HTTPS — fragile for CI).

Recommendation: ship a tiny PHP test server at `tests/Wpt/fetch-server.php` that handles the dozen-ish endpoints WPT fetch tests actually hit. Start in CI before the WPT suite runs. The endpoint surface is small and stable (echo, status code, redirect chain, cookie set, header dump).

## Phased delivery

Each phase is one PR. Each must stay test262 100% + popular byte-equal + prior WPT 100%.

| Phase | Scope | Lines | Subagents |
|---|---|---:|---|
| 1 | EventTarget + Event (foundation) | ~250 | 1 agent |
| 2 | Blob + File + FormData (value types, no network) | ~700 | 1 agent |
| 3 | AbortController + AbortSignal | ~300 | 1 agent |
| 4 | Headers | ~300 | 1 agent |
| 5 | Body mixin + Request + Response (no fetch yet — value types) | ~1,300 | 2 agents (split Body+Request vs Response) |
| 6 | Minimal ReadableStream | ~400 | 1 agent |
| 7 | fetch + CurlTransport + redirect loop + policy hook | ~1,200 | 1 agent (highest-care, supervised) |
| 8 | WPT fetch server (PHP) + fixture import + CI | ~600 | 1 agent |

Total estimate: ~5,000 lines of engine code + ~600 lines of test infrastructure. Sequential because each phase depends on prior. Subagents work great phase-by-phase since each phase is self-contained.

Wall clock: maybe 6-10 hours of agent time spread across the phases, plus my merging.

## Acceptance criteria

1. **Every API has `typeof X !== 'undefined'`** after fresh `new Engine()`.
2. **Per-area WPT pass rate** meets the table above. Each new area lands as a `tests/Wpt/fixtures/<area>/` directory and the existing runner picks it up.
3. **End-to-end smoke**: this works (against a public httpbin-like endpoint or our local test server):
   ```js
   const r = await fetch("http://127.0.0.1:8765/echo-post", {
       method: "POST",
       headers: { "Content-Type": "application/json" },
       body: JSON.stringify({ x: 1 })
   });
   const data = await r.json();
   assert(data.x === 1);
   ```
4. **AbortController works**:
   ```js
   const c = new AbortController();
   setTimeout(() => c.abort(), 100);  // when setTimeout ships
   await fetch(slowUrl, { signal: c.signal });  // throws AbortError
   ```
5. **test262 stays 100%** — fetch is non-ECMAScript so this is unaffected.
6. **Popular pkgs (acorn / mustache / lodash / marked) stay byte-equal**.
7. **CI gate `wpt.yml`** stays green; new `fetch-server.php` is started in CI before the WPT suite runs.
8. **No new composer deps beyond `ext-curl`** (which Phasis should require properly).
9. **Engine API extended**: `setFetchTransport()` + `setFetchPolicy()` documented in `docs/`.

## Risks

1. **`ext-curl` requirement**: ship as a hard require in composer.json. Document that PHP without curl is rare in practice but doable (Guzzle has a pure-PHP stream backend; we could fall back if needed).
2. **HTTP/2 / HTTP/3**: curl handles both transparently if libcurl was built with them. We don't need engine-side awareness.
3. **TLS verification**: default `CURLOPT_SSL_VERIFYPEER = true`. Embedder can override via fetch policy or transport replacement for testing.
4. **Cookies leaking across Engine instances**: do NOT share a default cookie jar. Each Engine starts cookieless; opt-in jar via engine config.
5. **Request body size**: PHP can handle multi-GB strings poorly. For very large bodies, the streaming-body code path matters. v1 buffers the entire request body; document the limit at ~256 MB.
6. **Mock servers in CI**: shipping a PHP-built-in HTTP server alongside CI adds a moving part. Mitigation: keep its endpoint surface tiny (~12 routes) and make it deterministic.
7. **Event-loop semantics for `Promise` returned by fetch**: Phasis already drains microtasks at script end via the existing Promise runtime. Long-running async tests need explicit `await` boundaries; same constraint as the existing Promise tests.

## Open questions

1. **Should `fetch` be on by default, or opt-in via `$engine->enableFetch()`?**
   - Default-on is friendly for embedders who want to "just run JS that does fetch".
   - Default-off is safer for security-sensitive embedders.
   - Recommendation: **default-on, but with a default policy that denies non-HTTPS unless `setFetchPolicy()` overrides.** Most security stories are "I want HTTP outbound to be allowed but to my services only" which the policy hook covers.

2. **Cookie jar**: per-Engine `setCookieJar(PhasisCookieJar $jar)` opt-in? Or just expose it via fetch policy (let embedder set/read `Cookie` and `Set-Cookie` headers themselves)?
   - Recommendation: **opt-in jar via API**. Provides spec-compatible cookie behavior when explicitly enabled.

3. **DOMException name for fetch failures**:
   - Per spec: `AbortError` for abort, `TypeError` for everything else (network errors, CORS denial, etc.).
   - We honor this. `AbortError` is the only DOMException class we throw from fetch; the rest are TypeErrors.

4. **Streams compromise**: do we ship the minimal ReadableStream now (~400 lines) or defer it and have `response.body` return null, with `response.text()`/`json()`/`arrayBuffer()` being the only way to read?
   - Recommendation: **ship minimal ReadableStream**. Libraries like `eventsource-parser`, SSE polyfills, and streaming JSON parsers depend on `body.getReader()`. Without it, a meaningful chunk of modern web JS won't work.

5. **`navigator.userAgent`**: many libraries pre-check `navigator.userAgent`. Ship a minimal `navigator` global with `userAgent: "Phasis/X"`? Trivial, ~50 lines.
   - Recommendation: **yes, bundle with Phase 7 (fetch)**.

## Decision needed

If approved:

1. Confirm phase order + recommended answers to open questions.
2. Decide on subagent strategy: phase-per-subagent in series? Or some phases in parallel (1-4 are independent of each other; 5-7 are sequential)?
3. Decide on test server: ship `tests/Wpt/fetch-server.php` ourselves, or use a hosted httpbin clone in CI?
4. Confirm `ext-curl` becomes a hard composer requirement.

Once signed off, I'll kick off Phase 1 (EventTarget + Event) and we proceed serially through the layers, merging each phase into main as a separate PR.
