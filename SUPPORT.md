# Support Snapshot

Generated from automated tests. Do not edit by hand.

- Refresh: `./bin/support-report`
- Source of truth: scenario oracle regression plus sampled `test262` runs.
- Snapshot time: `2026-04-16T20:12:25+00:00`
- Git: `main` @ `aa22b5c` (dirty)

## Summary

| Area | Pass | Fail | Skip | Attempted | Rate |
|---|---:|---:|---:|---:|---:|
| Scenarios | 12 | 0 | 0 | 12 | 100.0% |
| test262 sample (50/category) | 1462 | 107 | 185 | 1569 | 93.2% |

## Scenario Coverage

| Category | Status | Pass | Fail | Skip | Rate |
|---|---|---:|---:|---:|---:|
| classes | PASS | 1 | 0 | 0 | 100.0% |
| control-flow | PASS | 2 | 0 | 0 | 100.0% |
| expressions | PASS | 1 | 0 | 0 | 100.0% |
| functions | PASS | 2 | 0 | 0 | 100.0% |
| literals | PASS | 2 | 0 | 0 | 100.0% |
| objects | PASS | 1 | 0 | 0 | 100.0% |
| operators | PASS | 1 | 0 | 0 | 100.0% |
| variables | PASS | 2 | 0 | 0 | 100.0% |

## Scenario Detail

| Scenario | Mode | Status |
|---|---|---|
| classes/basic | exec | PASS |
| control-flow/for-loop | exec | PASS |
| control-flow/if-else | parse | PASS |
| expressions/basic | exec | PASS |
| functions/closures | exec | PASS |
| functions/declaration | parse | PASS |
| literals/number-integers | parse | PASS |
| literals/string-basic | parse | PASS |
| objects/literals | exec | PASS |
| operators/arithmetic | parse | PASS |
| variables/let-const | parse | PASS |
| variables/scoping | exec | PASS |

## test262 Coverage

| Category | Status | Pass | Fail | Skip | Attempted | Rate | Visual |
|---|---|---:|---:|---:|---:|---:|---|
| annexB/built-ins/String | PARTIAL | 47 | 1 | 2 | 48 | 97.9% | `####################` |
| annexB/built-ins/escape | PASS | 16 | 0 | 0 | 16 | 100.0% | `####################` |
| annexB/built-ins/unescape | PASS | 19 | 0 | 0 | 19 | 100.0% | `####################` |
| built-ins/Array | PARTIAL | 46 | 2 | 2 | 48 | 95.8% | `###################-` |
| built-ins/Boolean | PARTIAL | 46 | 3 | 1 | 49 | 93.9% | `###################-` |
| built-ins/Date | PARTIAL | 47 | 1 | 2 | 48 | 97.9% | `####################` |
| built-ins/Error | PASS | 47 | 0 | 3 | 47 | 100.0% | `####################` |
| built-ins/Function | PASS | 43 | 0 | 7 | 43 | 100.0% | `####################` |
| built-ins/JSON | PARTIAL | 45 | 2 | 3 | 47 | 95.7% | `###################-` |
| built-ins/Map | PASS | 49 | 0 | 1 | 49 | 100.0% | `####################` |
| built-ins/Math | PASS | 45 | 0 | 5 | 45 | 100.0% | `####################` |
| built-ins/Number | PARTIAL | 46 | 3 | 1 | 49 | 93.9% | `###################-` |
| built-ins/Object | PASS | 48 | 0 | 2 | 48 | 100.0% | `####################` |
| built-ins/RegExp | PARTIAL | 23 | 2 | 25 | 25 | 92.0% | `##################--` |
| built-ins/Set | PASS | 49 | 0 | 1 | 49 | 100.0% | `####################` |
| built-ins/String | PARTIAL | 48 | 1 | 1 | 49 | 98.0% | `####################` |
| built-ins/Symbol | PASS | 43 | 0 | 7 | 43 | 100.0% | `####################` |
| built-ins/isFinite | PASS | 17 | 0 | 0 | 17 | 100.0% | `####################` |
| built-ins/isNaN | PASS | 17 | 0 | 0 | 17 | 100.0% | `####################` |
| built-ins/parseFloat | PARTIAL | 46 | 4 | 0 | 50 | 92.0% | `##################--` |
| built-ins/parseInt | PARTIAL | 44 | 6 | 0 | 50 | 88.0% | `##################--` |
| language/arguments-object | PARTIAL | 22 | 2 | 26 | 24 | 91.7% | `##################--` |
| language/asi | PASS | 50 | 0 | 0 | 50 | 100.0% | `####################` |
| language/block-scope | PASS | 45 | 0 | 5 | 45 | 100.0% | `####################` |
| language/comments | PASS | 21 | 0 | 29 | 21 | 100.0% | `####################` |
| language/computed-property-names | PARTIAL | 32 | 16 | 0 | 48 | 66.7% | `#############-------` |
| language/destructuring | PARTIAL | 17 | 1 | 1 | 18 | 94.4% | `###################-` |
| language/directive-prologue | PARTIAL | 43 | 7 | 0 | 50 | 86.0% | `#################---` |
| language/eval-code | PARTIAL | 15 | 26 | 9 | 41 | 36.6% | `#######-------------` |
| language/expressions | PASS | 50 | 0 | 0 | 50 | 100.0% | `####################` |
| language/function-code | PARTIAL | 39 | 11 | 0 | 50 | 78.0% | `################----` |
| language/future-reserved-words | PASS | 50 | 0 | 0 | 50 | 100.0% | `####################` |
| language/identifier-resolution | PARTIAL | 12 | 1 | 1 | 13 | 92.3% | `##################--` |
| language/keywords | PASS | 25 | 0 | 0 | 25 | 100.0% | `####################` |
| language/line-terminators | PASS | 41 | 0 | 0 | 41 | 100.0% | `####################` |
| language/literals | PASS | 50 | 0 | 0 | 50 | 100.0% | `####################` |
| language/reserved-words | PASS | 26 | 0 | 1 | 26 | 100.0% | `####################` |
| language/rest-parameters | PARTIAL | 10 | 1 | 0 | 11 | 90.9% | `##################--` |
| language/statements | SKIPPED | 0 | 0 | 50 | 0 | n/a | `n/a` |
| language/types | PASS | 50 | 0 | 0 | 50 | 100.0% | `####################` |
| language/white-space | PARTIAL | 33 | 17 | 0 | 50 | 66.0% | `#############-------` |

## Explicitly Skipped test262 Feature Families

- `async-iteration`
- `top-level-await`
- `Atomics`
- `SharedArrayBuffer`
- `resizable-arraybuffer`
- `arraybuffer-transfer`
- `Float16Array`
- `WeakRef`
- `FinalizationRegistry`
- `symbols-as-weakmap-keys`
- `import-assertions`
- `import-attributes`
- `dynamic-import`
- `arbitrary-module-namespace-names`
- `json-modules`
- `source-phase-imports`
- `Symbol.species`
- `Symbol.asyncIterator`
- `regexp-lookbehind`
- `regexp-named-groups`
- `regexp-dotall`
- `regexp-unicode-property-escapes`
- `regexp-match-indices`
- `regexp-v-flag`
- `regexp-modifiers`
- `regexp-duplicate-named-groups`
- `RegExp.escape`
- `class-fields-public`
- `class-fields-private`
- `class-static-fields-public`
- `class-static-fields-private`
- `class-methods-private`
- `class-static-methods-private`
- `class-static-block`
- `caller`
- `cross-realm`
- `Intl`
- `tail-call-optimization`
- `ShadowRealm`
- `Temporal`
- `decorators`
- `explicit-resource-management`
- `hashbang`
- `IsHTMLDDA`
- `iterator-helpers`
- `set-methods`
- `Array.fromAsync`
- `change-array-by-copy`
- `Math.sumPrecise`
- `well-formed-json-stringify`
- `json-parse-with-source`
- `String.prototype.isWellFormed`
- `String.prototype.toWellFormed`
