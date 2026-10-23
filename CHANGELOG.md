# Changelog

## [Unreleased]

### Added
- Keyed Promise combinators, iterator chunk/window/zip helpers, and immutable ArrayBuffers with protected typed-array, DataView, and Atomics writes.
- Locale variants and locale-specific calendar, collation, hour-cycle, text-direction, and week information.

### Fixed
- Temporal calendar fields, arithmetic, rounding, parsing, timezone boundaries, and Intl formatting use consistent calendar data and option validation.
- Module evaluation, source and typed imports, resource disposal, cross-realm intrinsics, numeric serialization, and compiled global bindings follow current language semantics.
- Unicode 17 case mappings and complete emoji string properties are generated reproducibly from pinned upstream data.
- Intl option precedence, legacy constructors, plural notation, numbering systems, and plain date/time formatting.

### Changed
- The test262 runner executes both variants of unflagged scripts, preserves raw sources and module parse goals, requires async completion, and checks negative-test error types and phases.
- Compatibility shards retain full file manifests; merging rejects overlapping or stale results and keeps missing coverage visible.

## [0.8.0] - 2026-10-16

- Route ordinary Unicode `/v` property patterns through bundled tables on older hosts; keep set and string operations on their existing lowering path.
- Ship only the runtime CLI in Composer archives; the test262 CLI remains available in source checkouts.

### Added
- Current Temporal era identifiers and era years for all supported era-bearing calendars, including single-era calendars
- Unicode 17 property tables generated from the pinned test262 fixtures, including Beria Erfe script aliases and updated Tangut/Telugu membership, independent of host ICU
- Standard `Error.prototype.stack` accessor with captured traces, receiver checks, cross-realm behavior and string-only overrides
- A reproducible Prettier 3.9.10 oracle for native JavaScript, JSON and CSS formatting

### Changed
- React and React DOM compatibility references to 19.3.1; Preact render-to-string to 6.8.1
- Official TC39 test262 snapshot to October 13, 2026; canonical reports retain individual failure paths and messages

### Fixed
- Temporal date/date-time/year-month property bags resolve current era names, aliases and arithmetic years, reject inconsistent explicit years, and use calendar-relative ROC fields
- HTML attributes now escape after one default-hint string coercion, including arrays and custom objects
- The formatter preserves import/export attributes, keeps empty-switch comments inside the body, and honors `semi: false` with automatic-semicolon-insertion guards
- Test262 JSON reporting survives failure messages containing lone surrogate code units

## [0.7.0] - 2026-10-15

### Added
- Execution budget: `Engine::setExecutionBudget(?float $seconds)` stops evaluation once it has run that long with `Phasis\Exceptions\BudgetExceeded`, which no JavaScript `try` catches. Once exceeded, every later loop iteration and call throws again and `budgetExceeded()` stays `true`. Indirect `eval`, the `Function` constructor and `ShadowRealm` run under the same budget, and it persists across `reset()`
- `Engine::lastLocation()` returns where the last statement started, locating an error that escaped a budgeted evaluation
- `Engine::setTimersEnabled(false)` keeps `setTimeout` and `setInterval` callbacks from firing; draining the event loop then runs microtasks only
- `Phasis\Parser\RegExpSyntax` holds the regular-expression early-error checks, so parsing a regular expression literal no longer loads the interpreter

### Fixed
- The formatter dropped the empty statement before a string and so made the string a directive: `;"use strict"; x = 1` printed as `"use strict"; x = 1;`, which makes the program strict. A string statement that is not a directive now prints as `("use strict");`, like prettier, and a parenthesized one keeps its parentheses
- `("use strict")` made the enclosing program or function strict; a parenthesized string is not a directive
- The formatter dropped the parentheses around the head of a member chain with three or more calls, so `(await x).json().then(a).catch(b)` printed as `await x.json().then(a).catch(b)` and `(a ? b : c).d().e().f()` lost its conditional's parentheses too
- The formatter printed an arrow function's object body in two pairs of parentheses, `(({ id }))`
- The formatter dropped a comment between an operator and its operand (`a + /* c */ b`) or after `=`; every comment now prints exactly once, where it was written, and a same-line block comment no longer moves to the end of the line
- A member chain of more than two calls whose arguments are all simple stays on one line when it fits, as in prettier
- A member access on the next line after a unary or `await` operand bound to the whole expression: `!a\n.b` parsed as `(!a).b`
- Line and column numbers after a multi-line template literal, or a string or template with a line continuation, were off by the lines it spanned
- Indirect `eval` and the `Function` constructor ran with the default loop limit instead of the engine's
- PHP 8.5 deprecation notices from `ord()` in the lexer for identifiers with non-ASCII characters or Unicode escapes

## [0.6.1] - 2026-10-15

### Fixed
- Class fields of a class with an implicit constructor were not initialized when a subclass constructed it (`class B extends A { x = 1 }`, `class C extends B {}`, `new C().x` was `undefined`). Effect's `Data.TaggedError` classes lost their `_tag`, so `Effect.catchTag` never matched
- A short-circuited optional chain stored in a binding, passed as an argument or returned (`const v = a?.b`) was not strictly equal to `undefined` when read from code compiled by the bytecode VM, so `v === undefined` was `false`
- Parentheses now end an optional chain. A short-circuit of `a?.b` no longer skipped the rest of `(a?.b).c`, `(a?.b)()` or `delete (a?.b).c`, which now throw a `TypeError` like V8. `` (a?.b)`t` ``, `(a?.b).c = 1` and `(a?.b).c++` were rejected as syntax errors and now parse
- Optional chains are rejected as destructuring assignment targets (`[a?.b] = []`, `({ x: (a?.b) } = {})`), as the spec requires
- A method call on `null` or `undefined` in the tree-walker said "Cannot convert undefined to object"; it now says "Cannot read properties of undefined (reading 'name')", like V8 and the bytecode VM
- `EstreeSerializer` emits ESTree's `ChainExpression` around each optional chain, ending where parentheses close it, matching `@babel/parser`'s `estree` output

### Added
- Effect 4 (`effect` 4.0.5) in the popular-packages suite, byte-equal with Node: 205 libraries

## [0.6.0] - 2026-10-15

### Added
- JSX Pack: render JSX components to HTML from PHP with no build step
  - JSX syntax in `.jsx` modules (`Phasis\Parser\Jsx\JsxOptions`, `JsxMode`), lowered at parse time exactly like esbuild's automatic and classic runtimes, with `@jsx*` pragmas. `JsxMode::Preserve` keeps JSX nodes (`Phasis\Ast\Jsx\*`) for tooling and `EstreeSerializer` exports them as ESTree JSX
  - `Engine::render()` and `Engine::renderSource()` render a component module with PHP props, choosing the profile from the JSX import source or a `Phasis\RenderProfile`
  - Native React 19.3: `react`, `react/jsx-runtime`, `react-dom`, `react-dom/server` and `react-dom/static` are built-in modules implemented in PHP, a port of React's Fizz server renderer. Output is byte-identical to `react-dom@19.3.0` (`renderToString`, `renderToStaticMarkup`, `prerender`) and hydrates with React 19.3's client
  - The general HTML profile: `phasis` and `phasis/jsx-runtime`, byte-identical to preact-render-to-string 6.8.0, with `raw()` and `renderToStringAsync()`
  - `JsxMode::Precompile`: static markup compiled to string templates, byte-identical output
  - Bundler-style module resolution: import maps (`Engine::setImportMap()`), extension and `index` probing, `jsconfig`/`tsconfig` path aliases, `package.json` `exports`/`imports`/`module`/`main` with conditions (`Phasis\Module\ModuleOptions`), CommonJS packages with bundler interop, `process.env.NODE_ENV`, empty style imports, asset and CSS-module resolvers (`setAssetResolver()`, `setCssModuleResolver()`), and barrel pruning for `sideEffects: false` packages
  - `Engine::getExport()` returns one export of a module converted to PHP
  - `preload.php` for `opcache.preload`, and `ModuleOptions::$parseCacheDir`, an on-disk cache of parsed modules
  - `ModuleOptions::$deferSideEffectFreeModules` (opt-in): modules from packages that declare `"sideEffects": false` load when one of their bindings is first read. Importing one `lucide-react` icon then loads 4 modules instead of 13
  - `bin/phasis --render <file.jsx> --props '<json>'`
- `WebAssembly.Memory`, including shared memories, and the `WebAssembly.CompileError`, `LinkError` and `RuntimeError` constructors. Phasis still can't compile or run WebAssembly modules, so `compile`, `instantiate`, `Module` and the other module APIs stay absent
- Docs: JSX, React and Modules pages

### Changed
- `Engine::execFile()` runs `.mjs` and JSX files through the module loader and returns `null` for them. The docs claimed this for any file with top-level `import`; `.js` files are, and stay, classic scripts
- `bin/phasis` runs `.mjs` and `.jsx` files as modules, and `--ast` keeps JSX for `.jsx` files

### Fixed
- `CompressionStream` and `DecompressionStream` accepted chunks backed by a `SharedArrayBuffer`; `BufferSource` excludes shared buffers, so the write now rejects with a `TypeError`
- In strict code, `try { return f(); } catch (e) { ... }` treated the call as a tail call, so an exception from `f` skipped the `catch`
- `Reflect.construct` did not initialize class fields of base classes, or of derived classes with an implicit constructor
- PHP 8.5 deprecation notices from `SplObjectStorage` in `JSON` and `DisposableStack`

## [0.5.0] - 2026-10-14

### Added
- PHP objects are shared by reference through `PhpBridge`, with live public properties, callable public methods, preserved identity and the original object returned when crossing back into PHP
- JavaScript functions passed to PHP host functions become callable closures tied to their original realm and lexical environment

### Fixed
- PHP host-function exceptions, including signature and arithmetic errors, become catchable JavaScript errors with their class name and message. Callback throws retain their original JavaScript value
- Cyclic JavaScript objects and arrays passed to host functions terminate conversion, replacing repeated references with null
- `reset()` restores the global object, aliases, descriptors and lazy built-ins as well as eager built-ins
- Loop limits apply to bytecode and transpiled code, including nested calls and suspended generators, and persist across engine resets

### Changed
- Unknown limit names and negative limit values throw `InvalidArgumentException` instead of being silently ignored
- Interop and lifecycle documentation describes the supported behavior

## [0.4.2] - 2026-09-24

### Fixed
- `vendor/bin/phasis` and `vendor/bin/test262` work when phasis is installed as a dependency. The binaries only looked for the autoloader in their own `vendor/`
- The usage message names the binary `phasis` instead of the old `php-js`
- The Composer package no longer ships `prds/`, `scripts/` or the development scripts in `bin/`

### Changed
- Development dependencies raised to the inline0 canonical minimums, including `squizlabs/php_codesniffer` `^3.13.6` for CVE-2026-67434. Dev only, no effect on consumers

## [0.4.1] - 2026-07-19

### Fixed
- CSS: reformatting a selector whose pseudo-class arguments had been wrapped across lines no longer introduces a stray leading space inside the parentheses; formatting is idempotent on such selectors.

## [0.4.0] - 2026-07-19

### Added
- CSS formatting: `Formatter::formatSource($css, 'css')` prints stylesheets with prettier-compatible layout through a new structural CSS parser and printer (`Phasis\Formatter\Css`) built on the shared document engine. Selector lists, value fills, shadow and gradient lists, nesting, custom properties, and unknown at-rule preludes (Tailwind `@apply` and friends) all match prettier output; conformance is asserted against fixtures generated by prettier itself.
- JSON formatting: `Formatter::formatSource($json, 'json')` reuses the JavaScript parser and printers with prettier's json rules (quoted keys, double-quoted strings, no trailing commas, comments preserved).


## [0.3.0] - 2026-07-19

### Added
- Native JavaScript formatter: `Phasis\Formatter\Formatter::formatSource($source, $sourceType, $options)` reprints source with prettier-compatible layout semantics, entirely in PHP. `Phasis\Formatter\FormatOptions` mirrors prettier 3.x options (printWidth, tabWidth, useTabs, semi, singleQuote, trailingComma, bracketSpacing, arrowParens).
- Prettier document model as public API: `Phasis\Formatter\Doc` (group, conditionalGroup, fill, indent, line, softline, hardline, literalline, ifBreak, indentIfBreak, lineSuffix, breakParent) and the width-fitting `Phasis\Formatter\DocPrinter`, reusable by printers for other languages.
- Comment collection in the lexer behind an opt-in flag: `Parser::setCollectComments(true)` plus `Parser::comments()` return `Phasis\Lexer\Comment` records (kind, raw text, location, end offset, newline-before) without changing engine tokenization.
- Comment reattachment for tooling: `Phasis\Formatter\CommentAttacher` binds comments to AST nodes as leading, trailing, or dangling attachments from start offsets plus targeted source scans.
- Formatter conformance suite: fixtures generated with real prettier, byte-equality on prettier-formatted corpora, and invariants for idempotency, reparse equivalence, and comment preservation.
- Formatter reference page in the docs (`/docs/formatter`).

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
