# Changelog

## [0.1.1] - 2026-06-21

### Changed
- Regex engine: pure-ASCII subjects take a fast-path that skips UTF-16/byte offset conversion (byte, code-unit, and code-point offsets all coincide), speeding up the common case.
- Regex engine: capturing-group indices are memoized per AST node instead of recomputed on every group match.

### Added
- `Matcher::match()` accepts an optional `$scanAnchor` that resolves the `\G` scan-anchor (`Anchor::SCAN`) independently of the attempt offset; defaults to the current behaviour, so existing calls are unaffected.

## [0.1.0] - 2026-06-11

### Added
- Pure PHP JavaScript engine for lexing, parsing, and executing ECMAScript without Node.js, FFI, or custom extensions
- Parser, AST, bytecode VM, runtime objects, modules, promises, async/generator support, and host interop APIs
- Broad built-in object coverage, including standard collection types, typed arrays, streams, crypto helpers, Intl-backed APIs, Temporal, and regular expressions
- CLI entrypoints for executing JavaScript, dumping ASTs, and running test262 categories
- Oracle regression scenarios, unit tests, popular-library compatibility fixtures, WPT coverage, and test262 conformance tooling
- Compatibility and benchmark reports in `COMPAT.md`, `compat.json`, `BENCH.md`, and `bench.json`
- Composer package metadata for `phasis/phasis`
