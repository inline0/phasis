---
title: "API"
description: "The Phasis\\Engine class is the public PHP entry point — eval, evalAsModule, execFile, render, setGlobal, call, console output, resource limits, and lifecycle."
path: "api"
order: 220
section: "Reference"
meta_title: "API"
meta_description: "The Phasis\\Engine class is the public PHP entry point — eval, evalAsModule, execFile, render, setGlobal, call, console output, resource limits, and lifecycle."
---

# API

The execution API is one class: `Phasis\Engine`. Everything else (`JsValue`, `JsFunction`, `JsObject`, …) is engine-internal and only exposed to host functions that bridge PHP↔JS. The one other public surface is the standalone [parser API](/docs/parser) (`Phasis\Parser\Parser` and the `Phasis\Ast` classes), which parses source without executing it.

## Construction

```php
use Phasis\Engine;

$engine = new Engine();                 // lazy mode (default)
$engine = new Engine(eager: true);      // install every built-in up front
$engine = new Engine(node: true);       // opt-in Node modules and host I/O
```

A fresh `Engine` exposes the full standard library — `Object`, `Array`, `String`, `Number`, `Symbol`, `BigInt`, `Promise`, `RegExp`, `Error` and the core language intrinsics install eagerly so the spec works (you can't have classes without `Function.prototype`, async/await without `Promise`, regex literals without `RegExp`). Everything else — `Math`, `JSON`, `Map`, `Set`, `Date`, `TypedArray` and the array-buffer family, `Atomics`, `Proxy`, `Reflect`, `console`, `Intl`, the weak-ref family, `Temporal`, `ShadowRealm`, the Web Platform Pack, and the Fetch Pack — is registered as a placeholder accessor and materializes on first read. Reading the global is transparent: `Map`, `typeof Map`, `'Map' in globalThis`, `Object.keys(globalThis).includes('Map')` all work, and after the first read the placeholder is replaced with a regular data descriptor.

Pass `eager: true` if you specifically need the pre-lazy behavior — every built-in installed at construction with the full descriptor surface visible to introspection. The conformance harness (`bin/test262`, `bin/wpt`) constructs engines in eager mode so test262/WPT see the descriptor shapes they expect.

`enableNode(?Phasis\Node\NodeOptions $options = null)` enables Node mode on an
existing engine. Options select its initial directory, environment and program
arguments. See [Node compatibility](/docs/modules#node-compatibility) for the
built-in registry, host requirements and limitations.

## eval

```php
public function eval(string $source): mixed
```

Parses and executes `$source` as a classic script in the engine's global scope, then runs the event loop until it is empty. Returns the value of the last evaluated expression converted to PHP (or `null` if the program had no trailing expression). See [Value conversion](/docs/interop/values) for the rules.

```php
$result = $engine->eval('1 + 2');           // 3
$result = $engine->eval('JSON.stringify({a: 1})');  // '{"a":1}'
$result = $engine->eval('[1,2,3].map(x => x * 2)'); // [2, 4, 6] (PHP array)
```

A Promise is not unwrapped: `eval('Promise.resolve(5)')` returns an empty array. Since the event loop has drained by the time `eval()` returns, store the settled value in a global and read it with a second `eval()`:

```php
$engine->eval('let out; (async () => { out = await Promise.resolve(9); })();');
$engine->eval('out');   // 9
```

An explicit JS `throw` that nothing catches surfaces as `Phasis\Exceptions\JsThrowable`, whose message is `"Name: message"` and whose `$jsValue` property holds the thrown `JsValue`. Errors the engine raises itself, such as reading a property of `null` or an undefined variable, surface as `Phasis\Exceptions\TypeError`, `ReferenceError` or `RangeError` instead. All of these extend `Phasis\Exceptions\RuntimeError`; parse errors extend `Phasis\Exceptions\SyntaxError`. See [Exceptions](#exceptions).

```php
try {
    $engine->eval('throw new Error("boom")');
} catch (\Phasis\Exceptions\JsThrowable $e) {
    echo $e->getMessage();
    // Error: boom
}
```

## evalAsModule

```php
public function evalAsModule(string $source, ?string $path = null): mixed
```

Evaluates `$source` as an ES module: `import` / `export`, top-level `await` and `import.meta` are available, and relative specifiers resolve against `$path` (an absolute file path). Runs the event loop like `eval()` and always returns `null`; read results through globals or the console buffer.

```php
$path = __DIR__ . '/entry.mjs';
$engine->evalAsModule(file_get_contents($path), $path);
```

Specifiers resolve the way bundlers resolve them: import maps, extensions and `index` files, aliases and `node_modules` packages, including CommonJS (see [Modules](/docs/modules)).

## execFile

```php
public function execFile(string $path): mixed
```

Loads a file from disk and runs it. `.mjs` files and JSX files (the `JsxOptions::$extensions`, `.jsx` by default) are loaded with the ES module loader, so their imports resolve, and `execFile()` returns `null` for them, like `evalAsModule()`. Every other file, including `.js`, passes its contents to `eval()` as a classic script and returns the value of its last expression; a `.js` file with `import` / `export` fails with a SyntaxError, and its real path becomes the base for dynamic `import()` specifiers. Use `getExport()` to read a module's exports.

```php
$engine->execFile(__DIR__ . '/script.js');
```

If the file cannot be read, PHP emits a warning and `execFile()` throws a plain `\RuntimeException` ("Cannot read file: …").

## render

```php
public function render(string $path, array $props = [], string $export = 'default', ?RenderProfile $profile = null): string
public function renderSource(string $source, array $props = [], string $export = 'default', ?string $path = null, ?RenderProfile $profile = null): string
```

Renders a component module to HTML: loads the module, converts `$props` to JavaScript, renders the export and runs the event loop until the render settles. `RenderProfile::React` gives React 19.3's `prerender` output; `RenderProfile::Html` gives preact-render-to-string-compatible HTML. Without a profile, the module's JSX import source decides (`react` or `phasis`). Render errors rethrow as `JsThrowable`; a render that never settles throws `RuntimeError`. See [JSX](/docs/jsx) and [React](/docs/react).

```php
echo $engine->render(__DIR__ . '/components/PostCard.jsx', ['post' => $post]);
```

## getExport

```php
public function getExport(string $path, string $name = 'default'): mixed
```

Loads a module and returns one of its exports converted to PHP. See [Modules](/docs/modules#reading-exports-from-php).

## Module and JSX configuration

```php
public function setJsxOptions(\Phasis\Parser\Jsx\JsxOptions $options): void
public function setModuleOptions(\Phasis\Module\ModuleOptions $options): void
public function setImportMap(array $imports): void                 // specifier => absolute path, or null for a stub
public function setAssetResolver(callable $resolver): void         // fn (string $file): string
public function setCssModuleResolver(callable $resolver): void     // fn (string $file): array<string, string>
```

- `setJsxOptions()`: how JSX compiles and which module paths get it (see [JSX](/docs/jsx)).
- `setModuleOptions()`: resolution extensions, package conditions, `NODE_ENV`, style and asset extensions, and the parse cache (see [Modules](/docs/modules)).
- `setImportMap()`: WHATWG import map `imports`, consulted before everything else, including the built-in React modules.
- `setAssetResolver()` and `setCssModuleResolver()`: what asset and CSS-module imports return. Without a resolver those imports throw.

## setGlobal

```php
public function setGlobal(string $name, mixed $value): void
```

Binds a global variable visible to all subsequent JS evaluation. PHP values are converted to JS:

| PHP type | JS type |
|---|---|
| `null` | `null` |
| `bool` | `boolean` |
| `int` / `float` | `number` |
| `string` | `string` (even when it names a function, such as `'strlen'`) |
| array (list) | `Array` (a copy) |
| array (assoc) | `Object` (a copy) |
| `Closure`, invokable object, `[$object, 'method']` | `function` |
| any other object, including generators | live PHP object bridge |
| resource | `undefined` |

Arrays are copied: changes JS makes to them are not visible in the PHP array. PHP objects are shared by reference through public properties and methods; see [Shared objects](/docs/interop/shared-objects).

```php
$engine->setGlobal('config', ['debug' => true, 'version' => '1.0']);
$engine->setGlobal('log', fn(string $msg) => error_log($msg));
```

Inside JS:

```javascript
console.log(config.version);   // "1.0"
log('hello');                  // calls the PHP closure
```

## call

```php
public function call(string $name, mixed ...$args): mixed
```

Calls a global JS function with PHP arguments (converted as for `setGlobal`). The return value is converted back to PHP. Calling a name that is not defined throws `Phasis\Exceptions\ReferenceError`; calling a global that is not a function throws `Phasis\Exceptions\TypeError`.

```php
$engine->eval('function add(a, b) { return a + b; }');
echo $engine->call('add', 2, 3);   // 5
```

## Console output

```php
public function getConsoleOutput(): string
public function getConsoleLines(): array
public function clearConsole(): void
```

`console.log`, `console.info`, `console.warn`, `console.error` and `console.debug` do not print. Each call appends one formatted line to a buffer on the engine. `getConsoleLines()` returns the buffered lines as a list of strings, `getConsoleOutput()` returns them joined with `\n`, and `clearConsole()` empties the buffer. The buffer is not cleared between `eval()` calls.

```php
$engine->eval('console.log("a", 1); console.warn({ b: 2 })');
$engine->getConsoleLines();    // ['a 1', '{ b: 2 }']
echo $engine->getConsoleOutput();
// a 1
// { b: 2 }
$engine->clearConsole();
```

## setLimit

```php
public function setLimit(string $limit, int $value): void
```

Sets a runtime-tunable resource limit. There is exactly one:

| Limit | Default | Description |
|---|---|---|
| `maxLoopIterations` | `100000` | maximum iterations per `for` / `while` / `do…while` loop across the interpreter, bytecode VM and transpiled closures |

```php
$engine->setLimit('maxLoopIterations', 1_000_000);
```

Unknown limit names and negative values throw `\InvalidArgumentException`. Zero permits loops whose bodies never run. Limits persist across `reset()`. There are no limits for string length or console output; wall-clock time has its own [execution budget](#setexecutionbudget).

The loop budget is per loop entry. Nested loops and repeated calls receive their own counters; suspended bytecode generators retain their counters across resumes. Exceeding the budget raises "Maximum loop iterations exceeded", which can be caught by JS `try` / `catch`. An uncaught error reaches PHP as `Phasis\Exceptions\InternalError` or, after a JS handler rethrows it, `Phasis\Exceptions\JsThrowable`; both extend `Phasis\Exceptions\RuntimeError`.

## setExecutionBudget

```php
public function setExecutionBudget(?float $seconds): void
public function budgetExceeded(): bool
public function lastLocation(): ?array
```

Stops evaluation once it has run for `$seconds` from now with `Phasis\Exceptions\BudgetExceeded`, which is not a JavaScript error, so no JS `try` statement catches it. Once the budget is exceeded every later loop iteration and call throws again and `budgetExceeded()` stays `true`, so code that swallows the exception (through a host function or a promise job, say) cannot keep running unnoticed. `null` removes the budget.

While a budget is set, evaluation stays on the tree-walking interpreter, whose loops and calls check it; indirect `eval`, the `Function` constructor and `ShadowRealm` run under the same budget. The budget persists across `reset()`.

`lastLocation()` returns where the last statement the tree-walker started begins, as a 1-based `['line' => int, 'column' => int]`. After an error escapes a budgeted evaluation, it is the statement that threw.

```php
$engine->setExecutionBudget(2.0);
try {
    $engine->eval($untrusted);
} catch (\Phasis\Exceptions\BudgetExceeded) {
    $where = $engine->lastLocation();
} finally {
    $engine->setExecutionBudget(null);
}
```

## reset

```php
public function reset(): void
```

Clears all user-defined globals and the console buffer, and rebuilds the standard library and the event loop.

Both lazy and eager engines rebuild their global object, standard built-ins and aliases, so the same engine is reusable after reset.

In Node mode, reset first closes owned sockets, file descriptors, codec contexts
and child processes, then reinstalls Node mode with its original `NodeOptions`.

## close

```php
public function close(): void
```

Cancels pending timers and releases owned Node resources, including terminating
and reaping direct child processes. Call it in a `finally` block when an embedded
program finishes or fails. Repeated calls are harmless. Use `reset()` to prepare
a closed Node engine for another program.

## Event loop

The engine ships a JavaScript event loop with `setTimeout`, `setInterval`, `clearTimeout`, `clearInterval`, and `queueMicrotask` on the global object. Microtasks (Promise handlers, `queueMicrotask`) drain between every macrotask; timers fire in deadline order via a per-realm task queue.

```php
public function runEventLoop(): void
public function tickEventLoop(): bool
public function pendingTaskCount(): int
public function getEventLoop(): \Phasis\Runtime\EventLoop
public function setTimersEnabled(bool $enabled): void
```

`eval()` and `evalAsModule()` call `runEventLoop()` automatically after the synchronous code completes. So a script that schedules `setTimeout(cb, 5000)` at top level runs `cb` before `eval()` returns — the loop uses **virtual time**, jumping its clock to the next pending deadline rather than sleeping PHP. Matches `node script.js` semantics.

For real-time embedding (long-lived servers, UI integration, scheduling against a real clock), `tickEventLoop()` runs one iteration (drain microtasks, fire any due timers) and returns. `pendingTaskCount()` reports outstanding timers; an embedder can drive its own pump:

```php
while ($engine->pendingTaskCount() > 0) {
    $engine->tickEventLoop();
    usleep(1000);
}
```

`setTimersEnabled(false)` keeps `setTimeout` and `setInterval` callbacks from firing: they are still accepted and return ids, and draining the loop runs microtasks only. The switch persists across `reset()`.

A runaway `setInterval` is bounded: `runEventLoop()` caps at 100,000 cycles and throws a plain `\RuntimeException` — the JS code's fault surfaces as a clear PHP error rather than a hang. The interval stays scheduled, so every later `eval()` on the same engine throws the same error; discard the engine afterwards. Uncaught errors in a timer callback are emitted via `error_log()` and the loop keeps draining; one bad timer doesn't kill the program.

Node mode uses elapsed time and polls sockets and child pipes between timer
phases. Referenced servers, sockets and timers keep `runEventLoop()` alive;
`unref()` handles do not. Node timers return handles with `ref`, `unref`,
`hasRef`, `refresh` and disposal, and `setImmediate` runs in the check phase.
Long-lived Node programs have no iteration-count ceiling; an explicit execution
budget still applies. Uncaught Node timer errors propagate to the PHP caller.
Use `evalScript($source, drainEventLoop: false)` followed by `tickEventLoop()`
when the PHP application needs to drive a server's loop itself.

## Fetch hooks

Phasis ships `fetch()` enabled by default with PHP's `ext-curl` as the underlying transport. Three hooks let an embedder swap the transport, gate requests, or enable cookie storage. All three accept either a PHP callable or a method-on-object; see [Web APIs](/docs/compatibility/web-apis) for the value-shape contract.

### setFetchTransport

```php
public function setFetchTransport(callable $transport): void
```

Replace the default CurlTransport. The callable receives a request descriptor and an optional `AbortSignal` JsObject; returns a response descriptor.

```php
// Use Guzzle as the transport instead of curl
$client = new GuzzleHttp\Client();
$engine->setFetchTransport(function (array $req, $signal) use ($client) {
    $r = $client->request($req['method'], $req['url'], [
        'headers' => $req['headers'],
        'body' => $req['body'],
        'http_errors' => false,
    ]);
    return [
        'status' => $r->getStatusCode(),
        'statusText' => $r->getReasonPhrase(),
        'headers' => array_map(
            fn ($name, $values) => array_map(fn ($v) => [$name, $v], $values),
            array_keys($r->getHeaders()),
            $r->getHeaders(),
        ),
        'body' => (string) $r->getBody(),
    ];
});
```

A common embedding pattern: install a mock transport in tests, the real curl transport in production.

### setFetchPolicy

```php
public function setFetchPolicy(callable $hook): void
```

Pre-flight policy hook. Receives the `Request` JsObject; can throw to deny, return a modified `Request` to rewrite, or return `null`/no value to allow.

```php
// Block non-HTTPS and inject auth on a specific host
$engine->setFetchPolicy(function ($req) {
    $url = $req->get('url')->value;
    if (str_starts_with($url, 'http://')) {
        throw new \RuntimeException('HTTPS only');
    }
    if (str_contains($url, 'api.example.com')) {
        $req->get('headers')->call('set', 'Authorization', 'Bearer ...');
    }
    return null;
});
```

### setCookieJar

```php
public function setCookieJar(mixed $jar): void
```

Opt-in cookie store. Accepts any object exposing `get(string $url): string` and `set(string $url, string $header): void` — PHP-side or JS-side.

```php
class SimpleJar
{
    private array $cookies = [];
    public function get(string $url): string { return implode('; ', $this->cookies); }
    public function set(string $url, string $header): void { $this->cookies[] = $header; }
}
$engine->setCookieJar(new SimpleJar());
```

Without `setCookieJar()`, `fetch` ignores both inbound `Set-Cookie` and outbound `Cookie` headers — the default is **no cookies**.

## Exceptions

| Class | Extends | When |
|---|---|---|
| `Phasis\Exceptions\JsThrowable` | `RuntimeError` | an explicit JS `throw` that nothing caught |
| `Phasis\Exceptions\TypeError`, `ReferenceError`, `RangeError` | `RuntimeError` | an error the engine raised itself (for example `null.x` or an undefined variable) that nothing caught |
| `Phasis\Exceptions\InternalError` | `RuntimeError` | the loop limit or the call-stack ceiling was crossed |
| `Phasis\Exceptions\RuntimeError` | `\RuntimeException` | base class of the above |
| `Phasis\Exceptions\BudgetExceeded` | `\RuntimeException` | evaluation ran past the [execution budget](#setexecutionbudget) |
| `Phasis\Parser\ParseError` | `Phasis\Exceptions\SyntaxError` (`\RuntimeException`) | the source does not parse |
| `\RuntimeException` | | `execFile()` cannot read the file; the event loop hit its 100,000-cycle cap |

To catch every failure of a script, catch `Phasis\Exceptions\RuntimeError` and `Phasis\Exceptions\SyntaxError`, or simply `\RuntimeException`.

PHP host-function exceptions become catchable JS errors with their class name and message. If uncaught, they reach PHP as `Phasis\Exceptions\JsThrowable`. See [Host functions](/docs/interop/host-functions#throwing-back-into-js).

## Lifecycle

`Engine` is cheap to construct (~few ms cold) and the standard library installs lazily. For most embedding scenarios a single long-lived engine per process is fine. For multi-tenant use, give each tenant or request its own engine so globals never leak between them. Constructing a fresh engine is the reliable clean slate today (see [reset](#reset)).

```php
foreach ($requests as $req) {
    $engine = new Engine();
    $engine->setGlobal('request', ['path' => $req->path, 'query' => $req->query]);
    $engine->execFile($req->scriptPath);
}
```
