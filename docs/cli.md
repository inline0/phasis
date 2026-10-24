---
title: "CLI"
description: "Complete command reference for the bin/phasis, bin/test262, and bin/wpt executables."
path: "cli"
order: 210
section: "Reference"
meta_title: "CLI"
meta_description: "Complete command reference for the bin/phasis, bin/test262, and bin/wpt executables."
---

# CLI

Phasis ships three main executables in `bin/`:

- **`bin/phasis`** — run JavaScript files, evaluate source passed on the command line, dump ASTs, render JSX components.
- **`bin/test262`** — run the official ECMAScript conformance suite against Phasis.
- **`bin/wpt`** — run the Web Platform Tests fixtures against Phasis (URL, encoding, fetch, headers, Blob, AbortSignal, streams, …).

After `composer require phasis/phasis`, the runtime CLI is linked at `vendor/bin/phasis`. The development runners need a source checkout and are excluded from the Composer archive.

## bin/phasis

```
phasis [--node] <file.js|file.mjs|file.jsx> [args] | phasis [--node] -e '<source>' | phasis --ast <file> | phasis --render <component.jsx> [--props '<json>']
```

The `--node` flag enables Node-compatible packages and host I/O. There is no REPL or source-code input from stdin.

### Run a file

```bash
./vendor/bin/phasis script.js
```

Executes `script.js` as a classic script. When it finishes, everything the script wrote with `console.log` (and `console.error`, `console.warn`, …) is printed to stdout in one go. If the script throws, the error message goes to stderr and the exit status is 1.

`.mjs` files and JSX files (`.jsx`) run as ES modules through the module loader, so `import` / `export`, top-level `await` and `import.meta` work and specifiers resolve the way bundlers resolve them (see [Modules](/docs/modules)). Every other file, including `.js`, is parsed as a classic script, so a `.js` file with `import` / `export` or top-level `await` fails with a SyntaxError.

### Evaluate source

```bash
./vendor/bin/phasis -e 'console.log(Math.PI * 2)'
# 6.283185307179586
```

The argument is evaluated as a complete script. Only `console` output is printed; the value of the last expression is not, so `phasis -e '1 + 2'` prints nothing.

### Run a Node-compatible server

```bash
./vendor/bin/phasis --node server.cjs --port 3000
./vendor/bin/phasis --node -e 'console.log(require("node:path").basename(process.cwd()))'
```

Node mode resolves packages from `node_modules`, exposes `process.argv` and a
copy of the host environment, and runs the real event loop while referenced
handles remain active. `console.log`/`info`/`debug` write immediately to stdout;
`console.warn`/`error` write immediately to stderr. `process.stdout` and
`process.stderr` accept raw bytes. The CLI closes owned resources on completion
or failure. `process.exitCode` and `process.exit(code)` select the process exit
status. See [Node compatibility](/docs/modules#node-compatibility) for module
coverage and host limitations.

### Dump the AST

```bash
./vendor/bin/phasis --ast script.js
./vendor/bin/phasis -e 'a + b' --ast
```

Prints the parse tree as JSON (indented by two spaces). Useful for debugging parser issues or building tooling on top of Phasis. For `.jsx` files the tree keeps the JSX nodes (`JsxMode::Preserve`). For programmatic access, use the [parser API](/docs/parser).

### Render a component

```bash
./vendor/bin/phasis --render components/Card.jsx --props '{"title":"Hello"}'
```

Renders the module's default export with `Engine::render()` and prints the HTML. `--props` is a JSON object passed as the component's props. See [JSX](/docs/jsx).

## bin/test262

`bin/test262` runs the [official test262 suite](https://github.com/tc39/test262) against Phasis. It's useful as a regression harness when contributing changes. The full-suite snapshot in `COMPAT.md` is produced by the related `bin/compat-report`, which the manually started CI matrix runs in shards.

### Run a single category

```bash
./bin/test262 --category built-ins/Array
```

### Run the full suite

```bash
./bin/test262 --jobs 4
```

### Configuration

The runner reads `config/support.php` from the project root. Its `test262_skipped_features` list is empty: no test262 feature is skipped, including `tail-call-optimization`. The file also lists the categories and the tests that must run in isolation.

The suite itself is the `test262` git submodule; run `git submodule update --init test262` after cloning. Without it the runner exits with "test262 suite not found".

### Other useful flags

| Flag | Purpose |
|---|---|
| `--failures` | print one line per failing test |
| `--limit N` | stop after the first N tests |
| `--jobs N` | run N tests in parallel |
| `--files-from file` | run only the tests listed in `file` |
| `--json` | emit machine-readable summary on stdout |
| `--no-progress` | suppress the progress line |

See the [Compatibility section](/docs/compatibility) for the latest snapshot.

## bin/wpt

`bin/wpt` runs the Web Platform Tests fixtures imported into `tests/Wpt/fixtures/`. The corpus covers URL, encoding, atob/btoa, structuredClone, performance, Headers, Blob/File, AbortController/Signal, EventTarget, FormData, WHATWG Streams, the full `crypto`/`SubtleCrypto` surface, `fetch`/`Request`/`Response`, `XMLHttpRequest`/`XMLHttpRequestUpload`, and `WebSocket`. See [Web APIs](/docs/compatibility/web-apis) for the per-area pass-rate table.

### Run all imported fixtures

```bash
./bin/wpt
```

### Run one category

```bash
./bin/wpt --category headers
./bin/wpt --category streams
./bin/wpt --category blob
```

### Per-subtest output

```bash
./bin/wpt --category abort --verbose
```

Prints every subtest (PASS / FAIL) inside each fixture, with assertion messages on failures.

### JSON output

```bash
./bin/wpt --json
```

Machine-readable pass/fail summary for CI pipelines or scripted analysis.

### Notes

- Fetch-touching fixtures auto-start the bundled WPT test server at `tests/Wpt/fetch-server.php` on `127.0.0.1:8765` and tear it down afterwards.
- The harness shim at `tests/Wpt/testharness-shim.js` implements just the WPT API surface the imported fixtures use (~400 lines vs the full ~3K-line `testharness.js`).
- Source fixtures live at `tests/Wpt/upstream/` (a gitignored sparse-checkout of [web-platform-tests/wpt](https://github.com/web-platform-tests/wpt)). To import more, copy `.any.js` files from `upstream/<area>/` into `fixtures/<category>/` and the runner picks them up automatically.

## Exit codes

In default mode, `bin/phasis` exits with one of two codes:

| Code | Meaning |
|---|---|
| `0` | success — the script ran to completion (also after `--ast`) |
| `1` | anything else: an uncaught JS exception, a syntax error, a missing file or invalid arguments |

Errors from the script print their message to stderr. Invalid arguments and missing files print the usage line to stderr.

Node mode additionally returns the program's explicit `process.exitCode` or
`process.exit(code)` status.

## Resource limits

`bin/phasis` runs with the engine defaults and has no flags for limits: a 4,096-frame JS call-stack ceiling, an 8,192-frame guard for transpiled closures, and the default `maxLoopIterations` of 100,000, which applies across the interpreter, bytecode VM and transpiled closures (see [API: setLimit](/docs/api#setlimit)). There is no time limit; wrap the command in `timeout` if a script may not terminate.
