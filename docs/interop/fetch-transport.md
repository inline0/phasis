---
title: "Fetch transport"
description: "Send Phasis fetch() through ext-curl, Guzzle, Symfony HttpClient or a mock, and allow or block each request with a policy hook."
path: "interop/fetch-transport"
order: 60
section: "Interop"
meta_title: "Fetch transport"
meta_description: "Send Phasis fetch() through ext-curl, Guzzle, Symfony HttpClient or a mock, and allow or block each request with a policy hook."
---

# Fetch transport

`fetch()` in Phasis builds the request, applies the policy hook, walks the redirect chain and builds the `Response` in the engine. The only part that touches the network is the transport: a PHP callable that sends one HTTP request and returns one response. The default transport, `Phasis\BuiltIn\Fetch\CurlTransport`, uses PHP's `ext-curl` with `CURLOPT_FOLLOWLOCATION` off, because Phasis follows redirects itself, and with `CURLOPT_PROGRESSFUNCTION` polling the request's `AbortSignal`.

Replace the transport to route requests through the host application's HTTP client, a proxy, a mock in tests, or nothing at all in a sandbox.

## The interface

```php
public function setFetchTransport(callable $transport): void
public function getFetchTransport(): ?callable
```

`getFetchTransport()` returns the installed callable, or `null` while the default `CurlTransport` is in use. The callable is called as:

```php
function (array $request, ?\Phasis\Value\JsObject $signal): array
```

`fetch()` passes a `$request` array with these keys:

| Key | Type | Notes |
|---|---|---|
| `method` | `string` | The request method. `DELETE`, `GET`, `HEAD`, `OPTIONS`, `POST` and `PUT` are uppercased in any spelling, as the Fetch standard requires; other methods keep their case, so `"patch"` arrives as `patch`. |
| `url` | `string` | The absolute URL of this hop. Each redirect hop is a separate call with the new URL. |
| `headers` | `list<array{0: string, 1: string}>` | `[name, value]` pairs. Names set through `Headers` arrive lowercased (`content-type`). Phasis then appends `Cookie` from the [cookie jar](/docs/interop/cookie-jar), when one is mounted and returns a value, and `User-Agent: Phasis/0.1` unless the request already set a user agent. |
| `body` | `string` | The request body bytes, or an empty string. |
| `redirect` | `string` | `follow`, `error` or `manual`. Informational: the transport should not follow redirects, because Phasis handles all three modes. |
| `timeout` | `int` | Overall time limit in seconds. `fetch()` always sends `30`. |
| `connectTimeout` | `int` | Connection time limit in seconds. `fetch()` always sends `10`. |

`CurlTransport` passes `timeout` and `connectTimeout` to `CURLOPT_TIMEOUT` and `CURLOPT_CONNECTTIMEOUT`. A custom transport may honor them or apply its own limits.

`$signal` is the request's `AbortSignal` as a `JsObject`, or `null` when the request was made without a signal. Read its state with `$signal->get('aborted')`, which returns a `JsBoolean` whose `value` property is the PHP `bool`. [Abort patterns](/docs/interop/abort-patterns#signal-in-a-custom-fetch-transport) shows a transport that polls it during a transfer.

The transport returns an array:

```php
return [
    'status'     => 200,
    'statusText' => 'OK',
    'headers'    => [['content-type', 'text/plain'], ['x-custom', '1']],
    'body'       => 'response bytes',
];
```

| Key | Default when missing | Notes |
|---|---|---|
| `status` | `200` | A `301`, `302`, `303`, `307` or `308` with a `location` header (matched in any case) starts the next hop. |
| `statusText` | `''` | The reason phrase. |
| `headers` | `[]` | `[name, value]` pairs. Repeat a name for multiple values, such as several `set-cookie` headers. |
| `body` | `''` | The response body bytes. |
| `finalUrl` | the request URL | The URL reported as `response.url`. |

Returning anything other than an array rejects the `fetch()` promise with `TypeError: fetch transport must return an array`.

### Errors

Throw `Phasis\BuiltIn\Fetch\TransportException` to report a failed request. Its constructor is `new TransportException(string $message, string $kind = 'network-error')`, and `fetch()` maps the kind:

| Thrown | `fetch()` rejects with |
|---|---|
| `TransportException` with kind `aborted` | the signal's abort reason, or an `AbortError` `DOMException` when the signal has no reason or there is no signal |
| `TransportException` with kind `timeout` or `network-error` | `TypeError` with the exception's message |
| any other `Throwable` | `TypeError` with the exception's message |

`CurlTransport` throws `aborted` when the signal aborts, `timeout` when curl reports `CURLE_OPERATION_TIMEOUTED`, and `network-error` for every other curl error.

### Other callers

`XMLHttpRequest` sends its requests through the same transport and does not consult the cookie jar. Its calls differ from `fetch()` in these ways:

- `$signal` is always `null`.
- `timeout` is the XHR `timeout` rounded up to whole seconds, or `30` when unset.
- A `credentials` key holds `include` when `withCredentials` is set and `same-origin` otherwise.
- A `followRedirects` key says who follows redirects.
- No `User-Agent` header is added.
- An `Origin` header is added when the realm has a base URL (`__phasisRequestBaseUrl` or `location.href`) and the script set none.
- Header names keep the case given to `setRequestHeader()`.

Without a policy hook, `followRedirects` is `true` and the transport is expected to follow redirects itself. `CurlTransport` then lets libcurl follow them, limited to `http` and `https`. With a [policy hook](#redirects) it is `false`, and XHR follows up to 20 redirects itself, one transport call per hop.

On those hops XHR drops `authorization`, `proxy-authorization` and `cookie` headers when the origin changes, comparing origins with the same WHATWG URL parser that builds the hop's URL. A `Location` that is not an `http` or `https` URL fails the request. It continues a `303`, or a `301` or `302` after a method other than `GET` or `HEAD`, as a `GET` without a body.

With [Node compatibility](/docs/modules#node-compatibility) enabled and no transport installed, `fetch()` uses the Node HTTP client instead of `CurlTransport`. Installing a transport takes precedence in Node mode too.

`CurlTransport` also serves `blob:` URLs created with `URL.createObjectURL()` from memory. A custom transport receives those URLs like any other and must handle them itself if scripts fetch them.

## Worked examples

### Guzzle

```php
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Phasis\BuiltIn\Fetch\TransportException;

$client = new Client();

$engine->setFetchTransport(function (array $request, $signal) use ($client): array {
    $headers = [];
    foreach ($request['headers'] as [$name, $value]) {
        $headers[$name][] = $value;
    }

    try {
        $response = $client->request($request['method'], $request['url'], [
            'headers'         => $headers,
            'body'            => $request['body'],
            'timeout'         => $request['timeout'],
            'connect_timeout' => $request['connectTimeout'],
            'allow_redirects' => false,
            'http_errors'     => false,
        ]);
    } catch (GuzzleException $e) {
        throw new TransportException($e->getMessage(), 'network-error');
    }

    $pairs = [];
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            $pairs[] = [$name, $value];
        }
    }

    return [
        'status'     => $response->getStatusCode(),
        'statusText' => $response->getReasonPhrase(),
        'headers'    => $pairs,
        'body'       => (string) $response->getBody(),
    ];
});
```

`allow_redirects` must be `false`. Guzzle follows redirects by default, which would hide them from Phasis and break `redirect: "manual"` and `redirect: "error"`.

### Symfony HttpClient

```php
use Phasis\BuiltIn\Fetch\TransportException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

$client = HttpClient::create();

$engine->setFetchTransport(function (array $request, $signal) use ($client): array {
    $headers = [];
    foreach ($request['headers'] as [$name, $value]) {
        $headers[$name][] = $value;
    }

    try {
        $response = $client->request($request['method'], $request['url'], [
            'headers'       => $headers,
            'body'          => $request['body'],
            'max_duration'  => (float) $request['timeout'],
            'max_redirects' => 0,
        ]);
        $status = $response->getStatusCode();
        $responseHeaders = $response->getHeaders(false);
        $body = $response->getContent(false);
    } catch (TransportExceptionInterface $e) {
        throw new TransportException($e->getMessage(), 'network-error');
    }

    $pairs = [];
    foreach ($responseHeaders as $name => $values) {
        foreach ($values as $value) {
            $pairs[] = [$name, $value];
        }
    }

    return [
        'status'     => $status,
        'statusText' => '',
        'headers'    => $pairs,
        'body'       => $body,
    ];
});
```

Symfony sends requests lazily, so network errors surface from `getStatusCode()`, `getHeaders()` or `getContent()`, which is why they sit inside the `try`. Passing `false` to the last two returns 4xx and 5xx responses instead of throwing.

### Deny-all

```php
use Phasis\BuiltIn\Fetch\TransportException;

$engine->setFetchTransport(function (): array {
    throw new TransportException('network access disabled in this sandbox', 'network-error');
});
```

Every `fetch()` then rejects with `TypeError: network access disabled in this sandbox`, and every `XMLHttpRequest` fires `error`.

### Mock for tests

```php
$canned = [
    'https://api.example.com/users' => ['status' => 200, 'body' => '{"users":[]}'],
    'https://api.example.com/404'   => ['status' => 404, 'body' => 'not found'],
];

$engine->setFetchTransport(function (array $request) use ($canned): array {
    $response = $canned[$request['url']] ?? ['status' => 500, 'body' => 'no mock'];
    return [
        'status'     => $response['status'],
        'statusText' => $response['status'] === 200 ? 'OK' : 'ERR',
        'headers'    => [['content-type', 'application/json']],
        'body'       => $response['body'],
    ];
});
```

The [cookie jar](/docs/interop/cookie-jar#end-to-end-example) page has a longer mock that answers a login flow with a redirect and a session cookie.

## The policy hook

The transport sends requests; the policy hook decides whether a request is sent at all. Use it for HTTPS-only rules, allowlists, header injection or URL rewriting.

```php
public function setFetchPolicy(callable $hook): void
public function getFetchPolicy(): ?callable
```

The hook is called with the `Request` as a `JsObject` before the transport, and before any cookie or user-agent header is added. It runs for the request passed to `fetch()` and again for every [redirect hop](#redirects):

- Return `null`, or nothing, to send the request as it is.
- Return a different `Request` `JsObject` to send that one instead. Any other return value rejects the `fetch()` with a `TypeError`.
- Throw to reject the `fetch()`. A PHP exception becomes a `TypeError` carrying the exception's message.

Request properties are JavaScript values. `$request->get('url')` returns a `JsString` whose `value` is the PHP string. `$request->get('headers')` returns the request's `Headers` object; call its `set` method through `JsFunction::call()`, which takes the receiver and a list of JavaScript arguments. Changes made this way are part of the request the transport receives.

```php
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;

$allowedHosts = ['api.example.com', 'cdn.example.com'];
$token = getenv('INTERNAL_API_TOKEN');

$engine->setFetchPolicy(function (JsObject $request) use ($allowedHosts, $token): ?JsObject {
    $url = $request->get('url');
    $host = parse_url($url instanceof JsString ? $url->value : '', PHP_URL_HOST) ?? '';
    if (!in_array($host, $allowedHosts, true)) {
        throw new \RuntimeException("host not allowed: $host");
    }

    $headers = $request->get('headers');
    $set = $headers instanceof JsObject ? $headers->get('set') : null;
    if ($set instanceof JsFunction) {
        $set->call($headers, [new JsString('x-internal-token'), new JsString($token)]);
    }

    return null;
});
```

`fetch("https://evil.example.net/")` then rejects with `TypeError: host not allowed: evil.example.net`, and requests to the allowed hosts reach the transport with an `x-internal-token` header.

### Redirects

The hook runs again for every redirect hop that Phasis follows. Before each hop it builds a `Request` for the new URL with the hop's method, headers and body, and passes it to the hook like the first request:

- Returning `null` sends the hop. Headers the hook sets on the hop's `Request` are sent with it.
- Returning a different `Request` sends that request as the hop instead.
- Throwing rejects the `fetch()` with a `TypeError` carrying the message, exactly as a direct request to that URL would be rejected, and the transport is not called for that URL.

So with the allowlist above, `fetch("https://api.example.com/go")` answered by a `302` to `https://evil.example.net/` rejects with `TypeError: host not allowed: evil.example.net`, while a redirect from `api.example.com` to `cdn.example.com` is followed. The hop's method and body are already adjusted when the hook sees it: after a `303`, the hook sees a `GET` with no body.

`redirect: "manual"` and `redirect: "error"` follow nothing, so the hook runs only for the first request.

When a hop changes origin (scheme, host or port, compared with the WHATWG URL parser), Phasis removes `authorization`, `proxy-authorization` and `cookie` from the hop's headers before the hook sees them. Headers removed this way stay removed for the rest of the chain. So a token the hook adds for `api.example.com` is never carried to another host; the hook adds it again only where its own rule says so. A hop to a URL that is not `http` or `https`, including one the hook returns, rejects with a `TypeError` before the transport is called.

The same rule applies to the other paths that follow redirects. With a hook installed, `XMLHttpRequest` runs it before its first request and on every hop, and walks the redirect chain itself instead of asking the transport to follow redirects; a rejection fails the request with an `error` event, or a `NetworkError` from a synchronous `send()`. In [Node mode](/docs/modules#node-compatibility) without a custom transport, `fetch()` runs the hook on every hop of the Node HTTP client's redirect chain.

### WebSocket

`new WebSocket(url)` runs the hook once, before the [WebSocket transport](/docs/compatibility/web-apis) is called. The hook receives a `GET` `Request` for the handshake URL, with `https:` in place of `wss:` and `http:` in place of `ws:`:

- Returning `null` opens the socket as requested.
- Returning a different `Request` connects to its URL instead, mapped back to `wss:` or `ws:`. Its headers and body are not used, because the WebSocket transport takes no headers.
- Throwing fails the socket without calling the transport: an `error` event, then a `close` event with code `1006`.

### What the hook does not cover

Node mode's `http`, `https`, `net`, `tls` and `http2` clients open connections without the hook, and its `child_process` and `fs` modules reach the host directly. When the hook is a security boundary, leave Node compatibility off. A custom WebSocket transport installed with `setWebSocketTransport()` still runs after the hook.

## Default behavior

| Concern | Default |
|---|---|
| Transport | `CurlTransport` (`ext-curl`) |
| Redirects | Followed by Phasis up to 20 hops; the 21st redirect rejects with `TypeError: Too many redirects (exceeded 20 hops)`. |
| Redirect methods | A `303`, or a `301` or `302` after a method other than `GET` or `HEAD`, continues as `GET` without a body; `307` and `308` keep the method and body. |
| Redirect modes | `redirect: "manual"` resolves with the redirect response, whose `type` is `opaqueredirect`. `redirect: "error"` rejects with a `TypeError`. |
| Redirect credentials | `authorization`, `proxy-authorization` and `cookie` are removed on the first hop that changes origin and stay removed. |
| URL schemes | `fetch()` sends `http` and `https` URLs, and `blob:` URLs on the first request only. Any other scheme, directly or through a redirect, rejects with a `TypeError` before the transport is called. `CurlTransport` also limits libcurl to `http` and `https` with `CURLOPT_PROTOCOLS` and `CURLOPT_REDIR_PROTOCOLS`. |
| Time limits | 30 seconds overall, 10 seconds to connect |
| TLS verification | `CURLOPT_SSL_VERIFYPEER` on and `CURLOPT_SSL_VERIFYHOST` at `2`, with no option to turn them off. Replace the transport to change TLS settings. |
| User-Agent | `Phasis/0.1`, added only when the request has none |
| Cookies | None sent or stored. Mount a [cookie jar](/docs/interop/cookie-jar) to enable them. |
| Policy hook | None: every request and redirect hop is allowed. |
| AbortSignal | Checked before the request starts and polled during the transfer through the curl progress callback. |

## See also

- [Cookie jar](/docs/interop/cookie-jar): persisting cookies across requests, with an end-to-end example that uses a mock transport.
- [Abort patterns](/docs/interop/abort-patterns): timeouts, cancellation and polling the signal in a custom transport.
- [Host functions](/docs/interop/host-functions): exposing other PHP services to JavaScript when `fetch()` is not the right shape.
- [API: Fetch hooks](/docs/api#fetch-hooks): short signature reference.
- [Web APIs](/docs/compatibility/web-apis): the full Fetch Pack surface.
