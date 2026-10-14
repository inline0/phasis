---
title: "Getting Started"
description: "Install Phasis and run your first JavaScript from PHP."
path: "getting-started"
order: 10
meta_title: "Getting Started"
meta_description: "Install Phasis and run your first JavaScript from PHP."
---

# Getting Started

## Install

```bash
composer require phasis/phasis
```

Phasis needs PHP 8.2 or later with three extensions, all required by `composer.json`:

- `mbstring` — string handling.
- `bcmath` — BigInt arithmetic and integer-precision number handling.
- `curl` — the default transport behind `fetch()`.

`mbstring` and `curl` are enabled in most builds. `bcmath` often is not: the official `php` Docker images (`php:8.x-cli`, `php:8.x-fpm`) leave it out, and some distributions package it separately (`php-bcmath`). Without it Composer refuses to install Phasis. In a Dockerfile based on the official image, add:

```dockerfile
RUN docker-php-ext-install bcmath
```

Run `php -m` to see which extensions your build has.

`ext-intl` is optional. Phasis works without it, but the entire `Intl.*` family (`Collator`, `NumberFormat`, `DateTimeFormat`, `PluralRules`, `Locale`, `DisplayNames`, `ListFormat`, `RelativeTimeFormat`, `Segmenter`, `DurationFormat`) and the non-ISO Temporal calendars (`hebrew`, `islamic`, `japanese`, `persian`, etc.) need it. Install it whenever the host application exposes JS that touches locale-aware formatting. `ext-openssl` is optional as well: `crypto.subtle` AES-CBC, AES-CTR and AES-GCM encryption need it, while digests and HMAC work without it.

No `exec`, no FFI, no Node.js, no custom native extension.

## Run a script from the CLI

```bash
./vendor/bin/phasis -e 'console.log(1 + 2 * 3)'
# 7

./vendor/bin/phasis -e 'console.log([1, 2, 3].map(x => x * x).join(","))'
# 1,4,9

./vendor/bin/phasis path/to/script.js
```

The CLI prints what the script writes with `console.log`; it does not print the value of the last expression.

See the [CLI reference](/docs/cli) for the full command set.

## Embed in PHP

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Phasis\Engine;

$engine = new Engine();

// Evaluate an expression
$result = $engine->eval('1 + 2 * 3');
echo $result;
// 7

// Execute a script file
$engine->execFile('path/to/script.js');

// Bridge a PHP value into the JS global scope
$engine->setGlobal('config', ['debug' => true, 'version' => '1.0']);
$engine->eval('console.log(config.version)');

// console output is buffered on the engine, not printed
echo $engine->getConsoleOutput();
// 1.0
```

The `Engine` instance keeps its global state between calls, so subsequent `eval()` invocations see anything you defined earlier.

`console.log` and its siblings append to a buffer on the engine instead of writing to stdout. Read it with `getConsoleOutput()` (one string, lines joined with `\n`) or `getConsoleLines()` (a list of lines), and empty it with `clearConsole()`.

Cold-start is cheap: only the spec-load-bearing core (`Object`, `Function`, `Array`, `Promise`, `Symbol`, `BigInt`, `RegExp`, the error types) is installed up front. `Map`, `TypedArray`, `Temporal`, `Intl`, `URL`, `fetch`, the full Web Platform / Fetch Packs, and everything else materialize transparently on first read. Pass `new Engine(eager: true)` to install every built-in at construction — useful for conformance tooling that introspects descriptors before reading them; rarely needed for normal embedding.

## Call JS functions from PHP

```php
$engine->eval('function add(a, b) { return a + b; }');
echo $engine->call('add', 2, 3);
// 5
```

`call()` looks up the function by name on the global object and invokes it with the supplied PHP arguments (converted automatically to JS values).

## Expose a PHP callable as a JS function

```php
$engine->setGlobal('fetchData', function (string $url): array {
    return json_decode(file_get_contents($url), true);
});

$engine->eval('const users = fetchData("https://api.example.com/users")');
```

PHP closures are wrapped as JS functions. Their arguments are converted from JS to PHP on call, and their return values are converted back from PHP to JS.

## PHP objects

PHP objects expose public properties and methods by reference. PHP and JS observe the same instance:

```php
class Counter {
    public int $value = 0;
    public function increment(): void { $this->value++; }
}

$counter = new Counter();
$engine->setGlobal('counter', $counter);

$engine->eval('counter.increment(); counter.increment();');

echo $counter->value;
// 2
```

See [Shared objects](/docs/interop/shared-objects) for the details and other workarounds.

## Resource limits

```php
$engine = new Engine();
$engine->setLimit('maxLoopIterations', 100_000);
```

`maxLoopIterations` (default 100,000) applies to loops in the tree-walking interpreter, bytecode VM and transpiled closures. Exceeding it raises a catchable JS error. Uncaught limit errors extend `Phasis\Exceptions\RuntimeError` on the PHP side.

The engine also has a fixed 4,096-frame call-stack ceiling (and an 8,192-frame guard for transpiled closures); crossing it throws "Maximum call stack size exceeded", again as `InternalError`. There is no wall-clock limit, so run untrusted code under PHP's own `max_execution_time` or in a separate process. See [API: setLimit](/docs/api#setlimit).

## Next steps

- [API reference](/docs/api) — full `Phasis\Engine` surface.
- [Interop](/docs/interop) — value conversion rules, host functions, PHP objects.
- [Compatibility](/docs/compatibility) — what's implemented, test262 coverage.
- [Parser](/docs/parser) and [Formatter](/docs/formatter) — parse and format JavaScript without executing it.
- [Advanced](/docs/advanced) — architecture, bytecode VM, benchmarks, oracle testing.
