# Changelog

## [0.2.0] - 2026-07-18

### Added
- Public parser API: `Phasis\Parser\Parser::parseSource($source, $sourceType)` parses JavaScript under the `script` or `module` goal and returns the typed `Phasis\Ast\Program`, throwing `Phasis\Exceptions\SyntaxError` with line, column, and offset on invalid input. No `Engine` required.
- `Phasis\Ast\Walker`: depth-first AST traversal with enter and leave callbacks, a parent argument, and child pruning via `Walker::SKIP`.
- `Phasis\Ast\Serializer`: array/JSON export of the typed AST in the exact format `bin/phasis --ast` prints; the CLI now delegates to it.
- `Phasis\Ast\EstreeSerializer`: ESTree-shaped array/JSON export (ESTree type names, synthesized `ClassBody` and specifier nodes, `start` offsets) plus a deterministic one-line-per-node `summarize()` that byte-matches the acorn popular-package oracle.
- Parser reference page in the docs (`/docs/parser`).

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
