---
title: "Fetch transport"
description: "Phasis's fetch() runs through a pluggable HTTP transport. Default is PHP's ext-curl. Embedders can swap to Guzzle, Symfony HttpClient, a mock, an allowlist, or a deny-all — and rewrite or block individual requests via the fetch policy hook."
path: "interop/fetch-transport"
order: 6
section: "Interop"
meta_title: "Fetch transport"
meta_description: "Phasis's fetch() runs through a pluggable HTTP transport. Default is PHP's ext-curl. Embedders can swap to Guzzle, Symfony HttpClient, a mock, an allowlist, or a deny-all — and rewrite or block individual requests via the fetch policy hook."
---

# Fetch transport

The default `fetch()` in Phasis runs through PHP's `ext-curl` — `CURLOPT_FOLLOWLOCATION=false` (the spec redirect loop runs in Phasis-land) with `CURLOPT_PROGRESSFUNCTION` polling the `AbortSignal`. For most embeddings this is fine.

For everything else — proxies, custom DNS, the host app's existing HTTP client, network sandboxing, mocking in tests — the transport is **pluggable**.

## The interface

```php
public function setFetchTransport(callable $transport): void
```

Your callable receives:

```php
function (array $request, ?\Phasis\Value\JsObject $signal): array
```

`$request` is an associative array:

| Key | Type | Notes |
|---|---|---|
| `method` | `string` | Already uppercased (`GET`, `POST`, …) |
| `url` | `string` | Resolved absolute URL after URL parser + redirect chain |
| `headers` | `list<list<string>>` | Pairs of `[name, value]`; case as the spec normalizes; multi-value entries are separate pairs (Set-Cookie etc.) |
| `body` | `string` | Raw bytes. Empty string for bodyless methods. |
| `redirect` | `string` | `"follow"` / `"error"` / `"manual"` (your transport should NOT follow — the redirect loop runs in Phasis) |
| `timeout` | `int` | Wall-clock budget in milliseconds. `0` = no limit. |
| `credentials` | `string` | `"omit"` / `"same-origin"` / `"include"` — informational; cookies are wired separately via `setCookieJar()`. |

`$signal` is `null` if the request wasn't given a signal, otherwise a `JsObject` exposing `aborted` and `reason`. Poll it during long transfers.

Return shape:

```php
return [
    'status'     => 200,                          // int
    'statusText' => 'OK',                         // string
    'headers'    => [['content-type', 'text/plain'], ['x-custom', '1']],
    'body'       => '...response bytes...',       // string
];
```

If the transport throws, Phasis catches and translates:

- A `Phasis\BuiltIn\Fetch\TransportException` with `$e->kind === "aborted"` → `fetch` rejects with `AbortError DOMException`.
- `kind === "timeout"` → `TypeError` carrying the message.
- `kind === "network-error"` (default) → `TypeError`.
- Anything else → `TypeError` with the exception message.

## Worked examples

### Guzzle

```php
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

$client = new Client(['allow_redirects' => false]);

$engine->setFetchTransport(function (array $req, $signal) use ($client) {
    try {
        $r = $client->request($req['method'], $req['url'], [
            'headers'     => collect($req['headers'])->groupBy(0)->map->pluck(1)->all(),
            'body'        => $req['body'],
            'timeout'     => max(1, $req['timeout'] / 1000.0),
            'http_errors' => false,
        ]);
    } catch (GuzzleException $e) {
        throw new \Phasis\BuiltIn\Fetch\TransportException($e->getMessage(), 'network-error');
    }
    $headers = [];
    foreach ($r->getHeaders() as $name => $values) {
        foreach ($values as $v) {
            $headers[] = [$name, $v];
        }
    }
    return [
        'status'     => $r->getStatusCode(),
        'statusText' => $r->getReasonPhrase(),
        'headers'    => $headers,
        'body'       => (string) $r->getBody(),
    ];
});
```

### Symfony HttpClient

```php
use Symfony\Component\HttpClient\HttpClient;

$client = HttpClient::create(['max_redirects' => 0]);

$engine->setFetchTransport(function (array $req, $signal) use ($client) {
    $headers = [];
    foreach ($req['headers'] as [$name, $value]) {
        $headers[$name][] = $value;
    }
    $r = $client->request($req['method'], $req['url'], [
        'headers'      => $headers,
        'body'         => $req['body'],
        'timeout'      => $req['timeout'] / 1000.0,
        'max_redirects'=> 0,
    ]);
    return [
        'status'     => $r->getStatusCode(),
        'statusText' => '',
        'headers'    => collect($r->getHeaders(false))
            ->flatMap(fn ($values, $name) => array_map(fn ($v) => [$name, $v], $values))
            ->values()->all(),
        'body'       => $r->getContent(false),
    ];
});
```

### Deny-all

```php
$engine->setFetchTransport(function () {
    throw new \Phasis\BuiltIn\Fetch\TransportException(
        'network access disabled in this sandbox',
        'network-error',
    );
});
```

This makes every `fetch()` call reject with TypeError. Useful for sandboxing user-supplied JS.

### Mock for tests

```php
$canned = [
    'https://api.example.com/users' => ['status' => 200, 'body' => '{"users":[]}'],
    'https://api.example.com/404'   => ['status' => 404, 'body' => 'not found'],
];

$engine->setFetchTransport(function (array $req) use ($canned) {
    $r = $canned[$req['url']] ?? ['status' => 500, 'body' => 'no mock'];
    return [
        'status'     => $r['status'],
        'statusText' => $r['status'] === 200 ? 'OK' : 'ERR',
        'headers'    => [['content-type', 'application/json']],
        'body'       => $r['body'],
    ];
});
```

## The policy hook

The transport runs the request; the **policy hook** decides whether to run it at all. Use this for HTTPS-only enforcement, header injection, URL rewriting, allowlists, or per-tenant credential binding.

```php
public function setFetchPolicy(callable $hook): void
```

Hook signature: `function (JsObject $request): ?JsObject`. Returning `null` allows as-is. Returning a Request rewrites. Throwing rejects the fetch with the thrown error.

```php
$allowedHosts = ['api.example.com', 'cdn.example.com'];

$engine->setFetchPolicy(function ($req) use ($allowedHosts) {
    $url = parse_url($req->get('url')->value);
    if (!in_array($url['host'] ?? '', $allowedHosts, true)) {
        throw new \RuntimeException("host not allowed: " . ($url['host'] ?? ''));
    }
    // Inject internal-only auth header
    $req->get('headers')->call('set', 'X-Internal-Token', $secret);
    return null;
});
```

The policy hook runs **before** the transport — so even a buggy or untrusted transport can't bypass it.

## Default behavior summary

| Concern | Default |
|---|---|
| Transport | `CurlTransport` (PHP `ext-curl`) |
| Redirects | Follow up to 20 per spec. `redirect: "manual"` returns the redirect Response with `type: "opaqueredirect"`. |
| Timeout | 30 s wall-clock, 10 s connect |
| TLS verification | `CURLOPT_SSL_VERIFYPEER = true` (no opt-out — override via transport replacement) |
| User-Agent | `Phasis/0.1` injected only if not set |
| Cookies | None stored. Use `setCookieJar()` to enable. |
| Policy hook | None. All requests allowed unless explicitly denied. |
| AbortSignal | Honored mid-flight via curl progress-callback poll. |

## See also

- [API reference — Fetch hooks](/docs/api#fetch-hooks) — short signature reference.
- [Web APIs](/docs/compatibility/web-apis) — the full Fetch Pack surface.
- [Sandboxing — shared objects](/docs/interop/shared-objects) — passing PHP objects to JS, including cookie jars.
