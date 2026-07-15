---
title: "Host functions"
description: "Expose PHP callables to JavaScript and call them directly without serialization overhead."
path: "interop/host-functions"
order: 4
section: "Interop"
meta_title: "Host functions"
meta_description: "Expose PHP callables to JavaScript and call them directly without serialization overhead."
---

# Host functions

Any PHP closure or callable handed to the engine via `setGlobal()` becomes a callable JS function. There's no manual registration step, no schema, no marshalling — just pass the callable.

## Basics

```php
$engine->setGlobal('greet', function (string $name): string {
    return "Hello, $name!";
});

echo $engine->eval('greet("world")');
// Hello, world!
```

When `greet("world")` runs in JS:

1. The string `"world"` is converted from JS to PHP.
2. The PHP closure is invoked with the converted argument.
3. The return value is converted from PHP back to JS.

Conversion follows the [value conversion rules](/docs/interop/values).

## Arity and parameters

The engine inspects the PHP closure's signature with reflection. JS callers may pass fewer arguments (missing positions get `null`) or more (extras are ignored). Variadics and union types work as expected.

```php
$engine->setGlobal('format', function (string $template, ...$args): string {
    return vsprintf($template, $args);
});

echo $engine->eval('format("%s is %d years old", "Ada", 36)');
// Ada is 36 years old
```

## Type juggling

Arguments are converted to PHP using the JS→PHP rules. The receiving PHP parameter types are *not* enforced by the engine — they're enforced by PHP itself when the closure is invoked. A type mismatch produces a `TypeError` from PHP, which propagates back to JS as a thrown `Error`.

```php
$engine->setGlobal('len', fn(string $s): int => strlen($s));

$engine->eval('len(42)');  // PHP TypeError: argument #1 must be string, int given
```

Either coerce manually inside the closure (`(string) $value`) or use union types (`int|string`).

## Throwing back into JS

PHP exceptions thrown inside a host function are caught by the engine and re-thrown as JS `Error` instances. The exception message and class name are preserved.

```php
$engine->setGlobal('divide', function (int $a, int $b): int {
    if ($b === 0) {
        throw new \DivisionByZeroError('cannot divide by zero');
    }
    return intdiv($a, $b);
});

$engine->eval('
    try {
        divide(10, 0);
    } catch (e) {
        console.log(e.message);  // "cannot divide by zero"
        console.log(e.name);     // "DivisionByZeroError"
    }
');
```

To throw a plain JS `Error`, just throw a regular PHP `\Exception` — the message becomes the JS error message.

## Returning rich values

The return value flows back through the JS→PHP rules in reverse. To return a JS object literal, return a PHP associative array:

```php
$engine->setGlobal('lookup', function (string $key): array {
    return ['key' => $key, 'value' => 42, 'meta' => ['ts' => time()]];
});

$engine->eval('
    const r = lookup("answer");
    console.log(r.value, r.meta.ts);
');
```

To return a JS array, return a sequential PHP list:

```php
$engine->setGlobal('range', fn(int $a, int $b): array => range($a, $b));

$engine->eval('range(1, 5).reduce((a, b) => a + b)');  // 15
```

## Async host functions

If a host function returns a `Promise` (PHP `React\Promise\Promise` or any thenable), the engine awaits it before resuming JS. This integrates with PHP async runtimes like ReactPHP or Amp:

```php
use React\Promise\Promise;

$engine->setGlobal('fetch', function (string $url): Promise {
    return new Promise(function ($resolve, $reject) use ($url) {
        // async HTTP call
        $resolve(file_get_contents($url));
    });
});

$engine->eval('
    async function main() {
        const body = await fetch("https://example.com");
        console.log(body.length);
    }
    main();
');
```

The engine drains its microtask queue between each `await` so JS continuations resume in spec order.

## Performance notes

Each host-function call crosses the PHP↔JS boundary once for arguments and once for the return value. Both conversions are O(size of value) — primitives are constant-time, objects/arrays are proportional to their depth.

For hot inner loops, prefer returning a single host object with multiple methods over many small host functions. The object dispatch is cheaper than repeated boundary crossings.
