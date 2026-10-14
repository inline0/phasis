---
title: "Value conversion"
description: "How PHP types map to JavaScript types and back when crossing the engine boundary."
path: "interop/values"
order: 30
section: "Interop"
meta_title: "Value conversion"
meta_description: "How PHP types map to JavaScript types and back when crossing the engine boundary."
---

# Value conversion

Every call that crosses the PHP↔JS boundary — `setGlobal`, `call`, a host function invoked from JS — goes through a converter:

- **PHP → JS** when host values flow into the engine (`setGlobal()`, `call()` arguments, host-function return values).
- **JS → PHP** when JS values flow back out. There are two paths with slightly different rules: results of `eval()` and `call()`, and the arguments JS passes to a host function.

Conversion copies. Primitives map to primitives, arrays and objects are copied into new structures, and PHP callables are wrapped as JS functions. Nothing is shared by reference except the callables themselves.

## PHP → JS

| PHP value | JS result |
|---|---|
| `null` | `null` |
| `true` / `false` | `true` / `false` |
| `int` | `number` (IEEE 754 double; ints beyond 2^53 lose precision) |
| `float` | `number` (NaN, ±Infinity, ±0 preserved) |
| `string` | `string` (UTF-8 PHP bytes interpreted as UTF-16 code units), even when it names a PHP function |
| list `array` (keys 0, 1, 2, … in order) | new `Array`, elements converted recursively |
| any other `array` | new plain `Object`, values converted recursively |
| `Closure`, invokable object, callable array such as `[$object, 'method']` | `function` that calls the PHP callable |
| any other object, `Generator`, resource | `undefined` |

Mixed-key arrays (PHP's `[0 => 'a', 'name' => 'b']`) become plain objects because Arrays in JS must have a contiguous integer index space.

```php
$engine->setGlobal('list', [1, 2, 3]);                 // → JS Array
$engine->setGlobal('map',  ['a' => 1, 'b' => 2]);      // → JS Object
$engine->setGlobal('mixed', [0 => 'x', 'k' => 'v']);   // → JS Object
```

Because arrays are copied, JS changes stay in JS:

```php
$list = [1, 2, 3];
$engine->setGlobal('list', $list);
$engine->eval('list.push(4)');
count($list);                 // 3
$engine->eval('list.length'); // 4
```

PHP objects that are not callable do not convert; they arrive as `undefined` ([issue #18](https://github.com/inline0/phasis/issues/18)). Convert them to arrays first, for example with `get_object_vars()`, or expose their methods as closures. See [Shared objects](/docs/interop/shared-objects).

## JS → PHP: `eval()` and `call()` results

| JS value | PHP result |
|---|---|
| `undefined`, `null` | `null` |
| `boolean` | `bool` |
| `number` (integral, within int range) | `int` (`-0` becomes `0`) |
| `NaN`, `Infinity`, `-Infinity` | `NAN`, `INF`, `-INF` |
| `number` (other) | `float` |
| `string` | `string` (UTF-8) |
| `bigint`, `symbol` | `null` |
| `function` | `null` |
| `Array` | list `array`, elements converted recursively |
| `TypedArray` | list `array` of numbers |
| any other object (plain objects, `Error`, `Date`, `Map`, `Set`, `Promise`, `ArrayBuffer`, …) | associative `array` of its own data properties, skipping accessors and function-valued properties |

The last row means objects whose state lives in internal slots convert to an empty array: `new Date(0)`, `new Map([["a", 1]])` and `Promise.resolve(5)` all return `[]`. An `Error` returns its own `message` and `stack`. A self-referencing object converts its inner references to `null`.

```php
$engine->eval('[1, 2, 3]');                // [1, 2, 3]
$engine->eval('({a: 1, b: 2})');           // ['a' => 1, 'b' => 2]
$engine->eval('new Uint8Array([1, 2, 3])'); // [1, 2, 3]
$engine->eval('1234567890123456789n');     // null
$engine->eval('new Date(0)');              // []
```

Convert inside JS when you need one of those values in PHP:

```php
$engine->eval('(1234567890123456789n).toString()');  // "1234567890123456789"
$engine->eval('new Date(0).toISOString()');           // "1970-01-01T00:00:00.000Z"
$engine->eval('Object.fromEntries(new Map([["a", 1]]))'); // ['a' => 1]
```

## JS → PHP: host-function arguments

Arguments JS passes to a PHP host function follow the same rules for primitives, arrays and plain objects, with these differences:

| JS value | PHP argument |
|---|---|
| `TypedArray`, `ArrayBuffer`, `DataView` | the engine's own `JsValue` object, unconverted |
| object | associative `array` of all its own properties, including accessors (whose getters run) and function-valued properties |
| arrow function | an `array` (usually empty) |
| `function` declared with the `function` keyword, or any object that references itself | no conversion: the copy never terminates and PHP runs out of memory ([issue #21](https://github.com/inline0/phasis/issues/21)) |

There is no way yet to receive a JS callback in a host function. Pass data, not functions, across this boundary.

## Numbers and precision

JavaScript numbers are IEEE 754 doubles. PHP ints are platform-native (64-bit on every modern build). The conversion preserves integers up to ±2^53 exactly; larger PHP ints become `number` and lose precision past 16 decimal digits. Use `BigInt` inside JS if you need exact arithmetic on integers larger than that, and pass the result out as a string.

## Strings

PHP strings are byte arrays; JS strings are UTF-16 code unit sequences. The engine bridges them by interpreting PHP bytes as UTF-8 and re-encoding into UTF-16 internally. This is lossless for valid UTF-8 input. Bytes that are not valid UTF-8 are passed through unchanged rather than replaced with U+FFFD.

`String.length`, indexed access (`s[0]`), and slicing all count UTF-16 code units per the ECMAScript spec — not codepoints and not bytes. Strings containing astral characters like `'🍕'` therefore have `.length === 2` (the two surrogate halves of U+1F355).

## Round-trip stability

Plain data survives a round trip: a PHP value made of scalars, lists and associative arrays that goes in through `setGlobal()` and comes back through `eval()` or `call()` produces the same structure, except that integral floats come back as `int` (`1.0` returns `1`).

Everything else in the tables above that maps to `null`, `undefined` or `[]` does not round-trip.
