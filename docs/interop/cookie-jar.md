---
title: "Cookie jar"
description: "Phasis fetch is cookieless by default. Mount a jar via setCookieJar() to persist cookies across requests. Two methods — get(url) and set(url, header) — and any object that responds to them works."
path: "interop/cookie-jar"
order: 70
section: "Interop"
meta_title: "Cookie jar"
meta_description: "Phasis fetch is cookieless by default. Mount a jar via setCookieJar() to persist cookies across requests. Two methods — get(url) and set(url, header) — and any object that responds to them works."
---

# Cookie jar

By default Phasis's `fetch()` ignores `Set-Cookie` from responses and sends no `Cookie` header on requests. That's the right default for server-side embedding — you typically don't want one user's response to influence another's request.

If you do want cookies — driving a flow that depends on session state, scraping behind a login, mirroring browser behavior in tests — mount a jar with `$engine->setCookieJar($jar)`.

## The interface

The jar is any object that responds to two methods:

```
get(url: string): string         // returns a "Cookie:" header value, e.g. "sid=abc; pref=dark"
set(url: string, header: string): void   // receives one Set-Cookie line
```

Both PHP objects and JS-side `JsObject`s with matching members work. Passing `null` clears the jar.

Phasis calls `get(url)` once per request hop (so redirects to a different host see fresh cookies) and `set(url, line)` once per `Set-Cookie` header in the response. Cookie *policy* — domain matching, path scoping, expiry, `SameSite`, `Secure` — is the jar's responsibility, not Phasis's.

## In-memory jar (tests)

```php
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

$engine->setCookieJar(new MemoryJar());
```

This is enough for a happy-path test fixture but ignores `Domain=`, `Path=`, `Expires=`, and the `Secure`/`HttpOnly`/`SameSite` attributes. Don't use it for anything that crosses a trust boundary.

## Symfony BrowserKit

If you already have Symfony's HTTP libraries installed, `BrowserKit\CookieJar` handles policy correctly.

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

If your jar lives on the JS side — say, because you want to inspect or seed it from JavaScript — bind it via a JS object:

```js
const jar = new Map();
$jar.get = (url) => Array.from(jar.entries()).map(([k, v]) => `${k}=${v}`).join('; ');
$jar.set = (url, header) => {
  const [nv] = header.split(';');
  const [name, value] = nv.split('=');
  jar.set(name.trim(), value.trim());
};
```

```php
$engine->eval($script);
$engine->setCookieJar($engine->eval('$jar'));
```

The same `get`/`set` methods are called whether the receiver is a PHP object or a `JsObject`.

## Per-request scope

A jar lives on the realm — it persists across every `fetch()` until cleared with `setCookieJar(null)`. To scope a jar to a single request flow, do it explicitly:

```php
$engine->setCookieJar($jar);
$engine->eval('await runLoginFlow()');
$engine->setCookieJar(null);
```

## Inspecting from PHP

`$engine->getCookieJar()` returns whatever was passed in — useful for assertions in tests.

```php
$jar = new MemoryJar();
$engine->setCookieJar($jar);
$engine->eval('await fetch("https://api.example.com/login", { method: "POST" })');

assertNotEmpty($engine->getCookieJar()->get('https://api.example.com/'));
```

## See also

- [Fetch transport](/docs/interop/fetch-transport) — the cookie jar runs **inside** Phasis's redirect loop, layered onto whichever transport you've installed.
- [Web APIs (WPT)](/docs/compatibility/web-apis) — the Fetch Pack tests covering cookie behavior.
