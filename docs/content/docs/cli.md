---
title: "CLI"
description: "Complete command reference for the bin/phasis, bin/test262, and bin/wpt executables."
path: "cli"
order: 21
section: "Documentation"
meta_title: "CLI"
meta_description: "Complete command reference for the bin/phasis, bin/test262, and bin/wpt executables."
---

# CLI

Phasis ships three main executables in `bin/`:

- **`bin/phasis`** — run JavaScript files, evaluate expressions, dump ASTs, open a REPL.
- **`bin/test262`** — run the official ECMAScript conformance suite against Phasis.
- **`bin/wpt`** — run the Web Platform Tests fixtures against Phasis (URL, encoding, fetch, headers, Blob, AbortSignal, streams, …).

After `composer require phasis/phasis`, `bin/phasis` and `bin/test262` are linked from `vendor/bin/`. `bin/wpt` is dev-only — run it from a checkout of the Phasis source repo.

## bin/phasis

### Run a file

```bash
./vendor/bin/phasis script.js
```

Executes `script.js` end-to-end. `console.log` output is written to stdout; thrown JS exceptions exit with status 1 and the message on stderr.

### Evaluate an expression

```bash
./vendor/bin/phasis -e 'Math.PI * 2'
```

The argument is evaluated as a complete script; the last expression's result is printed.

### Dump the AST

```bash
./vendor/bin/phasis --ast script.js
```

Prints the parse tree as indented PHP-readable output. Useful for debugging parser issues or building tooling on top of Phasis.

### REPL

```bash
./vendor/bin/phasis --repl
```

Interactive prompt that evaluates each entered line in a persistent `Engine`. Multi-line input is supported via continuation when the line ends inside an open block.

### Read from stdin

```bash
echo 'console.log(2 + 2)' | ./vendor/bin/phasis -
```

Pass `-` as the file argument to read source from stdin.

### Module mode

```bash
./vendor/bin/phasis --module entry.mjs
```

Parses the file as an ES module: `import` / `export`, top-level `await`, and `import.meta` are all available. Relative specifiers resolve from the file's own directory.

## bin/test262

`bin/test262` is the runner used by Phasis's own CI to measure compliance against the [official test262 suite](https://github.com/tc39/test262). It's useful as a regression harness when contributing changes.

### Run a single category

```bash
./vendor/bin/test262 --category built-ins/Array
```

### Run the full suite

```bash
./vendor/bin/test262 --jobs 4
```

### Skip-features list

The runner reads `config/support.php` from the project root to decide which test262 features to skip (e.g. `tail-call-optimization` is intentionally unsupported).

### Other useful flags

| Flag | Purpose |
|---|---|
| `--failures` | print one line per failing test |
| `--limit N` | stop after the first N tests |
| `--jobs N` | run N tests in parallel |
| `--files-from file` | run only the tests listed in `file` |
| `--json` | emit machine-readable summary on stdout |
| `--no-progress` | suppress the progress line |

See the [Compatibility section](/docs/compatibility) for the latest CI numbers.

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

| Code | Meaning |
|---|---|
| `0` | success — script ran to completion |
| `1` | uncaught JS exception |
| `2` | syntax / parse error |
| `64` | invalid CLI arguments |
| `65` | input file not found |

Exit codes match what most JS runtimes use, so existing scripts can swap `node` for `phasis` without changing error handling.

## Resource limits

`bin/phasis` runs with the engine defaults: the bytecode VM's call-stack ceiling (4 096 JS frames) and the JsToPhp transpiled-closure ceiling (8 192 PHP frames) are baked in. To set a per-script loop budget, use the embedded API:

```php
$engine = new Phasis\Engine();
$engine->setLimit('maxLoopIterations', 1_000_000);
$engine->execFile('script.js');
```

See [API reference](/docs/api#setlimit) for the full surface.
