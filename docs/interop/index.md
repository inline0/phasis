---
title: "Overview"
description: "How PHP and JavaScript values cross the boundary in Phasis — value conversion rules, exposing PHP callables as JS functions, and sharing mutable objects without serialization."
path: "interop"
order: 20
section: "Interop"
meta_title: "Overview"
meta_description: "How PHP and JavaScript values cross the boundary in Phasis — value conversion rules, exposing PHP callables as JS functions, and sharing mutable objects without serialization."
---

# Interop

Phasis bridges PHP and JavaScript values *in place*, without serialization. The same array referenced from PHP and JS is one object in memory — mutations in one language are visible from the other. The same goes for callables (PHP `Closure` ↔ JS function), Maps, and most other value types.

This section covers the rules.

## Pages

- **[Values](/docs/interop/values)** — primitive and object conversion: numbers, strings, BigInt, booleans, null/undefined, arrays, objects, Date, RegExp. Identity-preserving vs copying conversions.
- **[Host functions](/docs/interop/host-functions)** — bind PHP `callable` as JS function via `$engine->setGlobal()`. Argument unwrapping rules, return-value wrapping, exception bridging.
- **[Shared objects](/docs/interop/shared-objects)** — pass a PHP object into JS, mutate from JS, observe changes from PHP. Reference semantics, property visibility, lifetime.
- **[Fetch transport](/docs/interop/fetch-transport)** — swap the default `ext-curl` HTTP backend for Guzzle, Symfony HttpClient, a mock, or a deny-all. Plus the policy hook for allowlisting, header injection, and per-tenant binding.
- **[Cookie jar](/docs/interop/cookie-jar)** — mount a `get(url)`/`set(url, header)` jar to persist cookies across fetches. In-memory, Symfony BrowserKit, or a JS-side `Map`.
- **[AbortController patterns](/docs/interop/abort-patterns)** — timeout, race, propagation, `AbortSignal.any` / `.timeout`, and how a custom PHP transport polls the signal mid-transfer.

## Quick example

```php
$engine = new Phasis\Engine();

// 1. Bind a PHP callable as a JS function
$engine->setGlobal('greet', fn (string $name) => "Hello, $name!");
echo $engine->eval('greet("world")');  // → Hello, world!

// 2. Share a PHP object — mutation in JS is visible from PHP
class Counter { public int $value = 0; }
$counter = new Counter();
$engine->setGlobal('counter', $counter);
$engine->eval('counter.value += 5');
echo $counter->value;  // → 5

// 3. Call a JS function from PHP
$engine->eval('function add(a, b) { return a + b; }');
echo $engine->call('add', 2, 3);  // → 5
```

## See also

- [API reference](/docs/api) — the full `Phasis\Engine` surface.
- [Web APIs](/docs/compatibility/web-apis) — Web Platform Pack and Fetch Pack live on top of the same interop layer.
