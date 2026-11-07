---
title: "Cookie jar"
description: "Phasis fetch() sends and stores no cookies by default. Mount a jar with setCookieJar() to keep cookies across requests: any PHP or JavaScript object with get(url) and set(url, header) methods works."
path: "interop/cookie-jar"
order: 70
section: "Interop"
meta_title: "Cookie jar"
meta_description: "Phasis fetch() sends and stores no cookies by default. Mount a jar with setCookieJar() to keep cookies across requests: any PHP or JavaScript object with get(url) and set(url, header) methods works."
---

# Cookie jar

By default `fetch()` in Phasis ignores `Set-Cookie` headers in responses and sends no `Cookie` header with requests. That is the safe default for server-side embedding, where one visitor's response should not influence the next visitor's request.

When JavaScript needs cookies, for example to drive a login flow, to call an API that keeps session state, or to mirror browser behavior in a test, mount a jar:

```php
public function setCookieJar(mixed $jar): void
public function getCookieJar(): mixed
```

`setCookieJar()` installs the jar on the engine and `getCookieJar()` returns the same value. Passing `null` removes the jar and restores the cookieless default.

## The interface

A jar is any object with two methods:

```
get(url: string): string                 // the Cookie header value for url, e.g. "sid=abc; pref=dark"
set(url: string, header: string): void   // receives one Set-Cookie header value
```

Both PHP objects and JavaScript objects work; see [JS-side jar](#js-side-jar) for the second.

Cookie policy belongs to the jar. Phasis passes each `Set-Cookie` value through unparsed and sends whatever `get()` returns, so domain matching, path scoping, expiry, `Secure`, `HttpOnly` and `SameSite` are the jar's responsibility.

## When Phasis calls the jar

`fetch()` follows redirects itself rather than letting the transport follow them, and it consults the jar on every hop of that loop:

1. Before each transport call, Phasis calls `get(url)` with the URL of that hop. A non-empty result is appended to the outgoing headers as `['Cookie', $value]`. An empty string adds no header.
2. After each transport call, Phasis calls `set(url, header)` once for every `Set-Cookie` header in the response (the name is matched case-insensitively), with the URL that was requested on that hop.
3. If the response is a redirect and the redirect mode is `follow`, Phasis resolves the `Location` header and starts the next hop at step 1.

Because step 2 runs before the redirect is followed, a cookie set by a `302` response is already in the jar when the next hop asks for its `Cookie` header. This is the usual login pattern, and the end-to-end example below relies on it.

The jar sits between `fetch()` and the [fetch transport](/docs/interop/fetch-transport), so it works the same with the default `ext-curl` transport and with one installed by `setFetchTransport()`. A custom transport sees the jar's `Cookie` header in `$request['headers']` and returns `Set-Cookie` headers in its response like any other header pair. With [Node compatibility](/docs/modules#node-compatibility) enabled and no custom transport, `fetch()` runs through the Node HTTP client, which calls the same `get` and `set` methods on each hop.

`XMLHttpRequest` calls the transport directly and does not consult the jar.

Return values are read strictly. A PHP jar's `get()` result is used only when it is a string; anything else counts as no cookies. A JavaScript `get()` that returns `undefined` counts as no cookies, and any other value is converted to a string. An object without a `get` or `set` method is skipped for that step.

## End-to-end example

This example runs a login flow against a mock server. It combines three parts of the API: a PHP jar mounted with `setCookieJar()`, a [custom transport](/docs/interop/fetch-transport) that plays the server, and a [host function](/docs/interop/host-functions) that hands each result back to PHP.

```php
use Phasis\Engine;

final class MemoryJar
{
    /** @var array<string, array<string, string>> host → name → "name=value" */
    private array $store = [];

    public function get(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        return implode('; ', $this->store[$host] ?? []);
    }

    public function set(string $url, string $header): void
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        [$nv] = explode(';', $header, 2);
        [$name, $value] = explode('=', trim($nv), 2) + ['', ''];
        if ($name !== '') {
            $this->store[$host][$name] = "$name=$value";
        }
    }
}

$engine = new Engine();
$jar = new MemoryJar();
$engine->setCookieJar($jar);

$engine->setFetchTransport(function (array $request): array {
    $path = parse_url($request['url'], PHP_URL_PATH);
    $cookie = '';
    foreach ($request['headers'] as [$name, $value]) {
        if (strtolower($name) === 'cookie') {
            $cookie = $value;
        }
    }

    if ($path === '/login' && $request['method'] === 'POST') {
        return [
            'status' => 302,
            'statusText' => 'Found',
            'headers' => [
                ['location', '/account'],
                ['set-cookie', 'sid=abc123; Path=/; HttpOnly'],
            ],
            'body' => '',
        ];
    }

    if ($path === '/account' && $cookie === 'sid=abc123') {
        return ['status' => 200, 'statusText' => 'OK', 'headers' => [], 'body' => 'Signed in'];
    }

    return ['status' => 401, 'statusText' => 'Unauthorized', 'headers' => [], 'body' => 'Sign in first'];
});

$results = [];
$engine->setGlobal('report', function (int $status, string $url, string $body) use (&$results): void {
    $results[] = "$status $url $body";
});

$engine->eval(<<<'JS'
fetch("https://app.example.test/account")
  .then((response) => response.text().then((body) => report(response.status, response.url, body)))
  .then(() => fetch("https://app.example.test/login", { method: "POST", body: "user=ada" }))
  .then((response) => response.text().then((body) => report(response.status, response.url, body)));
JS);

print_r($results);
// Array
// (
//     [0] => 401 https://app.example.test/account Sign in first
//     [1] => 200 https://app.example.test/account Signed in
// )

echo $jar->get('https://app.example.test/');
// sid=abc123
```

The sequence:

1. The first request to `/account` carries no `Cookie` header, because the jar is empty, so the mock answers `401`.
2. The `POST` to `/login` answers `302` with a `Set-Cookie` header. Phasis passes `sid=abc123; Path=/; HttpOnly` to `$jar->set()` with the `/login` URL, then follows the `Location` header. A `302` after a `POST` continues as a `GET` without the body.
3. On the redirect hop Phasis calls `$jar->get('https://app.example.test/account')`, sends `Cookie: sid=abc123`, and the mock answers `200`. The response's `url` is the final URL after the redirect.

`eval()` drains the event loop before it returns, so both responses have been reported by the time `print_r()` runs. `eval()` runs a classic script, where top-level `await` is a syntax error, so the flow is a promise chain. The jar is a plain PHP object, so its state is readable from PHP afterwards.

`MemoryJar` keys cookies by host and ignores every attribute after the first `;`. That is enough for a test fixture but not for anything that crosses a trust boundary.

## Symfony BrowserKit

If Symfony's BrowserKit component is installed, its `CookieJar` applies domain, path and expiry rules. An adapter maps it onto the two methods:

```php
use Symfony\Component\BrowserKit\CookieJar as BrowserCookieJar;
use Symfony\Component\BrowserKit\Cookie;

final class SymfonyJarAdapter
{
    public function __construct(private BrowserCookieJar $jar) {}

    public function get(string $url): string
    {
        $cookies = $this->jar->allValues($url);
        return implode('; ', array_map(
            fn ($n, $v) => "$n=$v",
            array_keys($cookies),
            array_values($cookies),
        ));
    }

    public function set(string $url, string $header): void
    {
        $cookie = Cookie::fromString($header, $url);
        $this->jar->set($cookie);
    }
}

$engine->setCookieJar(new SymfonyJarAdapter(new BrowserCookieJar()));
```

## JS-side jar

A jar can also be a JavaScript object, which is useful when scripts should seed or inspect it. Phasis calls its `get` and `set` functions with the jar as `this`.

`eval()` converts its result to PHP, so `$engine->eval('jar')` returns a PHP array, not an object Phasis can call. Read the binding from the engine's global environment instead, which returns the JavaScript object itself:

```php
$engine->eval(<<<'JS'
globalThis.jar = {
  cookies: new Map(),
  get(url) {
    return Array.from(this.cookies, ([name, value]) => `${name}=${value}`).join("; ");
  },
  set(url, header) {
    const [pair] = header.split(";");
    const index = pair.indexOf("=");
    this.cookies.set(pair.slice(0, index).trim(), pair.slice(index + 1).trim());
  },
};
JS);

$engine->setCookieJar($engine->getGlobalEnv()->get('jar'));
```

After a response sets `theme=dark`, `$engine->eval('jar.get("https://app.example.test/")')` returns `"theme=dark"`.

## Per-request scope

A jar stays mounted for every `fetch()` until it is replaced or cleared with `setCookieJar(null)`. To scope cookies to one flow, mount a fresh jar around it:

```php
$engine->setCookieJar(new MemoryJar());
try {
    $engine->eval('runLoginFlow()');
} finally {
    $engine->setCookieJar(null);
}
```

Because `eval()` drains the event loop, every request the flow starts has finished before the jar is removed.

## Inspecting from PHP

`getCookieJar()` returns the object that was mounted, which is convenient for assertions in tests:

```php
$engine->setCookieJar(new MemoryJar());
$engine->eval('fetch("https://api.example.com/login", { method: "POST" })');

assertNotEmpty($engine->getCookieJar()->get('https://api.example.com/'));
```

## See also

- [Fetch transport](/docs/interop/fetch-transport): the transport interface, the request and response arrays, and the fetch policy hook that runs before the jar and the transport.
- [Host functions](/docs/interop/host-functions): exposing PHP callables such as `report()` above to JavaScript, and how their arguments are converted.
- [API: Fetch hooks](/docs/api#fetch-hooks): short signatures for `setFetchTransport()`, `setFetchPolicy()` and `setCookieJar()`.
- [Abort patterns](/docs/interop/abort-patterns): cancelling a fetch flow with `AbortSignal`.
- [Web APIs](/docs/compatibility/web-apis): the Fetch Pack surface and its Web Platform Tests coverage.
