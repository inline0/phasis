---
title: "Introduction"
description: "Phasis runs JavaScript, TypeScript, JSX and React server rendering inside PHP, without Node.js. Overview, example and docs map."
path: "."
order: 0
meta_title: "Phasis documentation: a JavaScript engine in pure PHP"
meta_description: "Phasis runs JavaScript, TypeScript, JSX and React server rendering inside PHP, without Node.js. Overview, example and docs map."
---

# Introduction

Phasis is a JavaScript engine written in PHP. It lexes, parses and executes ECMAScript inside the PHP process that calls it: no `exec('node …')`, no FFI and no custom binary extension. It ships as the Composer package `phasis/phasis`.

It needs PHP 8.2 or later with three standard extensions: `ext-mbstring`, `ext-bcmath` (BigInt arithmetic and integer-precision number handling) and `ext-curl` (the default `fetch()` transport). [Getting Started](/docs/getting-started#install) shows how to add `bcmath` to builds that leave it out, such as the official `php` Docker images.

Two extensions are optional: `ext-intl` enables the `Intl` APIs and non-ISO Temporal calendars, and `ext-openssl` enables `crypto.subtle` AES encryption.

PHP applications that need to run JavaScript, such as templating, server-side rendering of components, validation rules shared with a browser, or user-supplied scripts in a sandbox, usually shell out to Node.js or drop the feature. A second runtime brings a serialization boundary, subprocess management and a harder deployment on shared hosting. Phasis keeps the JavaScript in the PHP process and lets PHP and JavaScript exchange values and call each other directly.

## A first example

```bash
composer require phasis/phasis
```

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Phasis\Engine;

$engine = new Engine();

$engine->setGlobal('order', [
    'items' => [
        ['name' => 'Notebook', 'price' => 12.5, 'qty' => 2],
        ['name' => 'Pen', 'price' => 4, 'qty' => 3],
    ],
]);
$engine->setGlobal('money', fn (float $amount): string => number_format($amount, 2) . ' EUR');

$total = $engine->eval('
    const total = order.items.reduce((sum, item) => sum + item.price * item.qty, 0);
    console.log(`${order.items.length} items, ${money(total)}`);
    total;
');

echo $total;
// 37

echo $engine->getConsoleOutput();
// 2 items, 37.00 EUR

$engine->eval('function label(amount) { return `Total: ${money(amount)}`; }');
echo $engine->call('label', $total);
// Total: 37.00 EUR
```

What happens here:

- `setGlobal()` copies the PHP array into JavaScript as an object with an `items` array. The [value conversion rules](/docs/interop/values) describe every type in both directions.
- `money` is a PHP closure. JavaScript calls it like any function; the argument and the return value are converted at the boundary. See [Host functions](/docs/interop/host-functions).
- `eval()` runs the source as a classic script, drains the event loop, and returns the value of the last expression converted to PHP.
- `console.log()` writes to a buffer on the engine, not to stdout. Read it with `getConsoleOutput()`.
- The engine keeps its global state, so a later `eval()` or `call()` sees `total`, `money` and `label`.

Rendering a component works the same way, with no build step:

```php
$html = $engine->renderSource(
    "export default ({ name }) => <p>Hello {name}</p>;",
    ['name' => 'Ada'],
);
// <p>Hello <!-- -->Ada</p>
```

## What it runs

- **Classic scripts.** `eval()` and `execFile()` on a `.js` file run a script in the global scope. See [API](/docs/api#eval).
- **ES modules.** `evalAsModule()` and `execFile()` on a `.mjs` file run modules through the module loader. Imports resolve with no build step: relative and extensionless paths, `index` files, import maps, `jsconfig` aliases, `node_modules` with `package.json` `exports`, CommonJS packages, and style and asset imports. See [Modules](/docs/modules).
- **TypeScript.** `.ts`, `.tsx`, `.mts` and `.cts` files have their types erased by a native PHP parser when they are executed, imported or rendered. See [TypeScript](/docs/typescript).
- **JSX and React.** `render()` and `renderSource()` render a component module to an HTML string. The React profile produces HTML byte-identical to `react-dom@19.3.1` that React can hydrate in the browser, and `renderForHydration()` pairs that HTML with the props the client needs. The HTML profile matches `preact-render-to-string` 6.8.1 for emails, feeds and static pages. See [JSX](/docs/jsx) and [React](/docs/react).
- **Node-compatible packages.** `enableNode()` or `phasis --node` adds CommonJS, `Buffer`, streams, filesystem access, HTTP servers and clients, TLS, crypto and timers, all executed in PHP. Express and Hono support is experimental. See [Node compatibility](/docs/modules#node-compatibility).
- **Web Platform APIs.** `URL`, `URLSearchParams`, `TextEncoder`/`TextDecoder`, `atob`/`btoa`, `structuredClone`, `performance` and `DOMException`. See [Web APIs](/docs/compatibility/web-apis).
- **Fetch.** `fetch()` with `Request`, `Response`, `Headers`, `AbortController`/`AbortSignal`, `Blob`, `File`, `FormData`, `EventTarget` and WHATWG Streams.
- **Crypto, sockets and timers.** `crypto` with `SubtleCrypto`, `WebSocket`, `XMLHttpRequest`, timers and `queueMicrotask`.

The ECMAScript standard library is complete, including `Proxy`, `Reflect`, `Symbol`, `BigInt`, typed arrays, `Temporal` and `Intl`, as are modern syntax features such as classes with private fields and decorators, async generators and top-level `await` in modules. [Spec surface](/docs/compatibility/spec-surface) lists them.

Two parts of the engine are useful on their own: the [parser](/docs/parser) turns source into a typed PHP AST or ESTree-shaped arrays without executing it, and the [formatter](/docs/formatter) formats JavaScript, JSON and CSS with prettier-compatible layout.

## Under host control

The PHP application decides what JavaScript can reach and how long it runs:

- Network access goes through a replaceable [fetch transport](/docs/interop/fetch-transport) and an optional policy hook that can rewrite or reject each request. The hook covers `fetch()`, `XMLHttpRequest` and the `WebSocket` handshake. It does not cover Node mode's `http`, `https`, `net`, `tls`, `http2` and `child_process` modules, so leave [Node compatibility](/docs/modules#node-compatibility) off when the hook is a security boundary. Cookies are off unless you mount a [cookie jar](/docs/interop/cookie-jar).
- The event loop runs on virtual time: a `setTimeout(cb, 5000)` fires before `eval()` returns without PHP sleeping. See [Event loop](/docs/api#event-loop).
- `setLimit('maxLoopIterations', …)` caps iterations per loop, [`setExecutionBudget()`](/docs/api#setexecutionbudget) stops evaluation after a wall-clock budget, and a fixed call-stack ceiling stops runaway recursion. There are no limits on string length or console output. See [setLimit](/docs/api#setlimit).
- PHP objects passed to JavaScript expose their public properties and methods by reference; see [Shared objects](/docs/interop/shared-objects).

## How it is verified

Node.js (V8) is the reference: when V8 produces a result, Phasis must produce the same one. CI runs PHPStan, PHPCS, the unit suite and the oracle scenarios that compare Phasis output with Node on every pull request and on pushes to `main` whose head commit carries `[ci]`, and the Web Platform Tests and popular-package suites when the engine source changes. Conformance with the pinned official test262 suite is run by hand in CI and committed to `COMPAT.md`; [test262](/docs/compatibility/test262) explains the snapshot and [Compatibility](/docs/compatibility) collects the results.

## What it is not

Phasis is not a JIT compiler. It is a tree-walking interpreter with an opportunistic bytecode VM that compiles supported hot functions to PHP closures. Expect around 100 times the runtime of V8 on dispatch-bound workloads. The trade-off is a pure-PHP runtime with no extra process and with host-controlled execution, which suits embedding where correctness and isolation matter more than raw throughput. See [Benchmarks](/docs/advanced/benchmarks) and [Limitations](/docs/compatibility/limitations).

## How the docs are organized

### Start here

[Getting Started](/docs/getting-started) installs Phasis and runs a first script from the CLI and from PHP. [CLI](/docs/cli) covers `bin/phasis`, `bin/test262` and `bin/wpt`.

### Embedding reference

[API](/docs/api) is the `Phasis\Engine` reference: evaluation, rendering, globals, console output, limits, the event loop, fetch hooks, exceptions and lifecycle.

### Interop

[Interop](/docs/interop) explains the boundary between PHP and JavaScript. [Value conversion](/docs/interop/values), [host functions](/docs/interop/host-functions) and [shared objects](/docs/interop/shared-objects) cover how data and calls cross it.

For network access from JavaScript, read the [fetch transport](/docs/interop/fetch-transport), the [cookie jar](/docs/interop/cookie-jar) and [abort patterns](/docs/interop/abort-patterns) pages.

### Languages and rendering

[TypeScript](/docs/typescript), [JSX](/docs/jsx), [React](/docs/react) and [Modules](/docs/modules) cover file types, the render profiles, hydration and import resolution.

### Compatibility

[Compatibility](/docs/compatibility) collects the conformance results: [test262](/docs/compatibility/test262), [Web APIs](/docs/compatibility/web-apis) and [Streams](/docs/compatibility/streams).

[Popular packages](/docs/compatibility/popular-packages) lists the npm libraries checked against Node.js, [Spec surface](/docs/compatibility/spec-surface) the implemented built-ins, and [Limitations](/docs/compatibility/limitations) where Phasis differs from V8.

### Standalone tools

[Parser](/docs/parser) and [Formatter](/docs/formatter) document the APIs that work without executing code.

### Internals

[Advanced](/docs/advanced) describes the [architecture](/docs/advanced/architecture), the [bytecode VM](/docs/advanced/bytecode-vm), the [testing model](/docs/advanced/testing) and [benchmarks](/docs/advanced/benchmarks).
