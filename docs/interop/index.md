---
title: "Overview"
description: "How PHP and JavaScript values cross the boundary in Phasis — value conversion rules, exposing PHP callables as JS functions, and what happens to PHP objects."
path: "interop"
order: 20
section: "Interop"
meta_title: "Overview"
meta_description: "How PHP and JavaScript values cross the boundary in Phasis — value conversion rules, exposing PHP callables as JS functions, and what happens to PHP objects."
---

# Interop

Phasis runs in the same PHP process as your application, so values cross between PHP and JavaScript without JSON, pipes or a second runtime. They cross by **conversion**: scalars map to JS primitives, PHP arrays are copied into new JS arrays and objects, and JS results are copied back into PHP arrays. A change JS makes to a converted array is not visible in the PHP array. PHP callables are the exception that stays live: they become JS functions that call straight into PHP.

This section covers the rules.

## Pages

- **[Values](/docs/interop/values)** — the conversion tables in both directions: numbers, strings, booleans, null/undefined, arrays, objects, and the values that do not convert.
- **[Host functions](/docs/interop/host-functions)** — bind PHP `callable` as JS function via `$engine->setGlobal()`. Argument conversion, return values, and how exceptions behave.
- **[Shared objects](/docs/interop/shared-objects)** — what happens when you pass a PHP object to JS today (it arrives as `undefined`), and how to expose PHP state and behaviour with arrays and closures instead.
- **[Fetch transport](/docs/interop/fetch-transport)** — swap the default `ext-curl` HTTP backend for Guzzle, Symfony HttpClient, a mock, or a deny-all. Plus the policy hook for allowlisting, header injection, and per-tenant binding.
- **[Cookie jar](/docs/interop/cookie-jar)** — mount a `get(url)`/`set(url, header)` jar to persist cookies across fetches. In-memory, Symfony BrowserKit, or a JS-side `Map`.
- **[AbortController patterns](/docs/interop/abort-patterns)** — timeout, race, propagation, `AbortSignal.any` / `.timeout`, and how a custom PHP transport polls the signal mid-transfer.

## Quick example

```php
$engine = new Phasis\Engine();

// 1. Bind a PHP callable as a JS function
$engine->setGlobal('greet', fn (string $name) => "Hello, $name!");
echo $engine->eval('greet("world")');  // → Hello, world!

// 2. Let JS change PHP state through a closure
$total = 0;
$engine->setGlobal('add', function (int $n) use (&$total): void { $total += $n; });
$engine->eval('add(2); add(3)');
echo $total;  // → 5

// 3. Call a JS function from PHP
$engine->eval('function add(a, b) { return a + b; }');
echo $engine->call('add', 2, 3);  // → 5
```

## See also

- [API reference](/docs/api) — the full `Phasis\Engine` surface.
- [Web APIs](/docs/compatibility/web-apis) — Web Platform Pack and Fetch Pack live on top of the same interop layer.
