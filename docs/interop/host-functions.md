---
title: "Host functions"
description: "Expose PHP callables to JavaScript and call them directly without serialization overhead."
path: "interop/host-functions"
order: 40
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

Conversion follows the [value conversion rules](/docs/interop/values). Arguments are copies: a JS array or object arrives as a PHP array, and changing it in PHP does not change the JS value.

## Arity and parameters

The engine passes the JS arguments to the callable positionally, as they are; it does not inspect the signature. Extra arguments are ignored by ordinary closures and collected by variadic parameters. Missing arguments are not filled in: if JS passes fewer arguments than the callable requires, PHP throws `ArgumentCountError`. Give optional parameters default values.

```php
$engine->setGlobal('format', function (string $template, ...$args): string {
    return vsprintf($template, $args);
});

echo $engine->eval('format("%s is %d years old", "Ada", 36)');
// Ada is 36 years old
```

## Type juggling

Arguments are converted to PHP using the JS→PHP rules. The engine calls your callable from code that declares `strict_types=1`, so PHP checks scalar parameter types strictly and does not coerce: a JS number passed to a `string` parameter is a `TypeError`. It becomes a JS error that JS code can catch (see below).

```php
$engine->setGlobal('len', fn(string $s): int => strlen($s));

$engine->eval('try { len(42); } catch (e) { e.name; }'); // "TypeError"
```

Either coerce manually inside the closure (`(string) $value`) or use union types (`int|string`).

## Throwing back into JS

PHP throwables from a host function become catchable JavaScript errors, including exceptions and PHP's `TypeError`, `ArgumentCountError` and `DivisionByZeroError`. The error preserves the PHP class's short name and message. PHP `TypeError` instances use the JS `TypeError` prototype; other ordinary PHP exceptions use `Error`.

```php
$engine->setGlobal('divide', function (int $a, int $b): int {
    return intdiv($a, $b);
});

$engine->eval('try { divide(10, 0); } catch (e) { console.log(e.name, e.message); }');
// DivisionByZeroError Division by zero
```

An uncaught host error reaches PHP as `Phasis\Exceptions\JsThrowable`, carrying the JS error in `$jsValue`. JavaScript throws from callbacks retain their original JS value.

To raise an error that JS code can catch, throw one of the engine's own error classes: `Phasis\Exceptions\TypeError`, `RangeError` or `ReferenceError` arrive in JS as the matching JS error type, and any other `Phasis\Exceptions\RuntimeError` arrives as a plain `Error`, each with your message.

```php
use Phasis\Exceptions\RangeError;

$engine->setGlobal('divide', function (int $a, int $b): int {
    if ($b === 0) {
        throw new RangeError('cannot divide by zero');
    }
    return intdiv($a, $b);
});

$engine->eval('
    try {
        divide(10, 0);
    } catch (e) {
        console.log(e.name, e.message);  // RangeError cannot divide by zero
    }
');
```

## Returning rich values

The return value is converted with the PHP→JS rules. To return a JS object literal, return a PHP associative array:

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

Returning a PHP object creates a live [object bridge](/docs/interop/shared-objects), exposing its public properties and methods.

## Async host functions

Host functions run synchronously. Returning a PHP promise creates an object bridge; the engine does not drive a PHP event loop. JavaScript can assimilate a public `then(resolve, reject)` method, whose callbacks arrive as PHP callables, but asynchronous PHP work must be driven by the host application. A synchronous result can also be awaited, which wraps it in a resolved JS promise:

```php
$engine->setGlobal('loadBody', function (string $url): string {
    return file_get_contents($url);
});

$engine->eval('
    async function main() {
        const body = await loadBody("https://example.com");
        console.log(body.length);
    }
    main();
');
```

For HTTP specifically, the built-in `fetch()` already returns a real JS promise and can be routed through your own PHP client with a [custom fetch transport](/docs/interop/fetch-transport).

## Performance notes

Each host-function call crosses the PHP↔JS boundary once for arguments and once for the return value. Array and plain JS object conversion copies their contents and costs proportionally to their size. Primitives and PHP object bridges cross without copying an object graph.

For hot inner loops, prefer one call that does more work over many small calls, and avoid passing large arrays back and forth when only a few fields change.
