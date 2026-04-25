# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-04-25T16:27:58+00:00`
- Chunk size: `25`
- Timeout: `300s`
- Jobs: `4`
- Groups: `159`
- Test files: `50506`
- Git: `main` @ `d9bb2af` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 48000 | 1471 | 520 | 45 | 1082 | 0 | 49471 | 50036 | 51118 | 97.0% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | PARTIAL | 1067 | 12 | 0 | 0 | 0 | 0 | 98.9% |
| built-ins | INCOMPLETE | 21879 | 355 | 15 | 21 | 746 | 0 | 98.4% |
| harness | PARTIAL | 114 | 2 | 0 | 0 | 0 | 0 | 98.3% |
| intl402 | PARTIAL | 684 | 882 | 0 | 0 | 0 | 0 | 43.7% |
| language | INCOMPLETE | 22833 | 32 | 504 | 4 | 61 | 0 | 99.9% |
| staging | INCOMPLETE | 1423 | 188 | 1 | 20 | 275 | 0 | 88.3% |

## Group Coverage

| Group | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB/built-ins/Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Date | PASS | 24 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Function | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Object | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/RegExp | PARTIAL | 54 | 8 | 0 | 0 | 0 | 0 | 87.1% |
| annexB/built-ins/String | PASS | 111 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/TypedArrayConstructors | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/escape | PASS | 16 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/unescape | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/comments | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/eval-code | PASS | 469 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/expressions | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/function-code | PARTIAL | 158 | 1 | 0 | 0 | 0 | 0 | 99.4% |
| annexB/language/global-code | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/literals | PARTIAL | 5 | 3 | 0 | 0 | 0 | 0 | 62.5% |
| annexB/language/statements | PASS | 22 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AbstractModuleSource | SKIPPED | 0 | 0 | 8 | 0 | 0 | 0 | n/a |
| built-ins/Array | PARTIAL | 3050 | 25 | 0 | 0 | 0 | 0 | 99.2% |
| built-ins/ArrayBuffer | PARTIAL | 191 | 1 | 0 | 0 | 0 | 0 | 99.5% |
| built-ins/ArrayIteratorPrototype | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncDisposableStack | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncFromSyncIteratorPrototype | PASS | 38 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncFunction | PASS | 18 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorFunction | PARTIAL | 22 | 1 | 0 | 0 | 0 | 0 | 95.7% |
| built-ins/AsyncGeneratorPrototype | PARTIAL | 45 | 3 | 0 | 0 | 0 | 0 | 93.8% |
| built-ins/AsyncIteratorPrototype | PASS | 10 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Atomics | INCOMPLETE | 282 | 74 | 7 | 13 | 175 | 0 | 79.2% |
| built-ins/BigInt | PASS | 75 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Boolean | PASS | 51 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/DataView | PARTIAL | 547 | 3 | 0 | 0 | 0 | 0 | 99.5% |
| built-ins/Date | PASS | 594 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/DisposableStack | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Error | PASS | 53 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/FinalizationRegistry | PASS | 47 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Function | PARTIAL | 504 | 5 | 0 | 0 | 0 | 0 | 99.0% |
| built-ins/GeneratorFunction | PARTIAL | 22 | 1 | 0 | 0 | 0 | 0 | 95.7% |
| built-ins/GeneratorPrototype | PASS | 61 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Infinity | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Iterator | PASS | 431 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/JSON | PARTIAL | 164 | 1 | 0 | 0 | 0 | 0 | 99.4% |
| built-ins/Map | PASS | 171 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/MapIteratorPrototype | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Math | PASS | 327 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/NaN | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/NativeErrors | PASS | 139 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Number | PASS | 335 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Object | PASS | 3410 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Promise | PASS | 631 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Proxy | PARTIAL | 304 | 7 | 0 | 0 | 0 | 0 | 97.7% |
| built-ins/Reflect | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp | PARTIAL | 475 | 13 | 0 | 0 | 0 | 0 | 97.3% |
| built-ins/RegExp/CharacterClassEscapes | INCOMPLETE | 6 | 0 | 0 | 6 | 12 | 0 | 100.0% |
| built-ins/RegExp/Symbol.species | PASS | 4 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/dotall | FAIL | 0 | 4 | 0 | 0 | 0 | 0 | 0.0% |
| built-ins/RegExp/escape | PASS | 20 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/lookBehind | PARTIAL | 3 | 14 | 0 | 0 | 0 | 0 | 17.6% |
| built-ins/RegExp/match-indices | PARTIAL | 13 | 1 | 0 | 0 | 0 | 0 | 92.9% |
| built-ins/RegExp/named-groups | PARTIAL | 30 | 6 | 0 | 0 | 0 | 0 | 83.3% |
| built-ins/RegExp/property-escapes | PASS | 143 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/property-escapes/generated | PENDING | 0 | 0 | 0 | 0 | 459 | 0 | n/a |
| built-ins/RegExp/prototype | PARTIAL | 445 | 42 | 0 | 0 | 0 | 0 | 91.4% |
| built-ins/RegExp/regexp-modifiers | PARTIAL | 59 | 11 | 0 | 0 | 0 | 0 | 84.3% |
| built-ins/RegExp/unicodeSets | PARTIAL | 19 | 94 | 0 | 0 | 0 | 0 | 16.8% |
| built-ins/RegExpStringIteratorPrototype | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Set | PASS | 381 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/SetIteratorPrototype | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ShadowRealm | PARTIAL | 55 | 9 | 0 | 0 | 0 | 0 | 85.9% |
| built-ins/SharedArrayBuffer | PASS | 104 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/String | PARTIAL | 1207 | 5 | 0 | 0 | 0 | 0 | 99.6% |
| built-ins/StringIteratorPrototype | PASS | 7 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Symbol | PARTIAL | 92 | 2 | 0 | 0 | 0 | 0 | 97.9% |
| built-ins/Temporal | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/Duration | PASS | 473 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/Instant | PASS | 434 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/Now | PASS | 66 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainDate | PASS | 592 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainDateTime | PASS | 684 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainMonthDay | PASS | 184 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainTime | PASS | 457 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainYearMonth | PASS | 465 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/ZonedDateTime | PASS | 805 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/toStringTag | PASS | 2 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ThrowTypeError | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/TypedArray | PARTIAL | 1397 | 29 | 0 | 0 | 0 | 0 | 98.0% |
| built-ins/TypedArrayConstructors | PARTIAL | 733 | 3 | 0 | 0 | 0 | 0 | 99.6% |
| built-ins/Uint8Array | PARTIAL | 63 | 1 | 0 | 0 | 0 | 0 | 98.4% |
| built-ins/WeakMap | PASS | 102 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakRef | PASS | 29 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakSet | PASS | 85 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/decodeURI | INCOMPLETE | 54 | 0 | 0 | 1 | 50 | 0 | 100.0% |
| built-ins/decodeURIComponent | INCOMPLETE | 55 | 0 | 0 | 1 | 50 | 0 | 100.0% |
| built-ins/encodeURI | PASS | 31 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/encodeURIComponent | PASS | 31 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/eval | PASS | 10 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/global | PASS | 29 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isFinite | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isNaN | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseFloat | PASS | 59 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseInt | PASS | 60 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/undefined | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| harness | PARTIAL | 114 | 2 | 0 | 0 | 0 | 0 | 98.3% |
| intl402 | PARTIAL | 8 | 14 | 0 | 0 | 0 | 0 | 36.4% |
| intl402/Array | PASS | 2 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/BigInt | PARTIAL | 6 | 5 | 0 | 0 | 0 | 0 | 54.5% |
| intl402/Collator | PARTIAL | 44 | 18 | 0 | 0 | 0 | 0 | 71.0% |
| intl402/Date | PARTIAL | 10 | 2 | 0 | 0 | 0 | 0 | 83.3% |
| intl402/DateTimeFormat | PARTIAL | 73 | 115 | 0 | 0 | 0 | 0 | 38.8% |
| intl402/DisplayNames | PARTIAL | 41 | 16 | 0 | 0 | 0 | 0 | 71.9% |
| intl402/DurationFormat | FAIL | 0 | 110 | 0 | 0 | 0 | 0 | 0.0% |
| intl402/Intl | PARTIAL | 33 | 34 | 0 | 0 | 0 | 0 | 49.3% |
| intl402/ListFormat | PARTIAL | 37 | 44 | 0 | 0 | 0 | 0 | 45.7% |
| intl402/Locale | PARTIAL | 81 | 66 | 0 | 0 | 0 | 0 | 55.1% |
| intl402/Number | PARTIAL | 5 | 2 | 0 | 0 | 0 | 0 | 71.4% |
| intl402/NumberFormat | PARTIAL | 103 | 149 | 0 | 0 | 0 | 0 | 40.9% |
| intl402/PluralRules | PARTIAL | 39 | 11 | 0 | 0 | 0 | 0 | 78.0% |
| intl402/RelativeTimeFormat | PARTIAL | 41 | 38 | 0 | 0 | 0 | 0 | 51.9% |
| intl402/Segmenter | PARTIAL | 50 | 28 | 0 | 0 | 0 | 0 | 64.1% |
| intl402/String | PARTIAL | 8 | 9 | 0 | 0 | 0 | 0 | 47.1% |
| intl402/Temporal | PARTIAL | 102 | 221 | 0 | 0 | 0 | 0 | 31.6% |
| intl402/TypedArray | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/arguments-object | PASS | 263 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/asi | PASS | 102 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/block-scope | PASS | 145 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/comments | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/computed-property-names | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/destructuring | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/directive-prologue | PASS | 62 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/eval-code | PARTIAL | 346 | 1 | 0 | 0 | 0 | 0 | 99.7% |
| language/export | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/expressions | PARTIAL | 10602 | 12 | 409 | 0 | 0 | 0 | 99.9% |
| language/function-code | PASS | 217 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/future-reserved-words | PASS | 55 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/global-code | PASS | 42 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifier-resolution | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifiers | PASS | 260 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/import | PASS | 4 | 0 | 81 | 0 | 0 | 0 | 100.0% |
| language/keywords | PASS | 25 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/line-terminators | PASS | 41 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/literals | INCOMPLETE | 528 | 2 | 0 | 4 | 50 | 0 | 99.6% |
| language/module-code | PARTIAL | 564 | 5 | 14 | 0 | 0 | 0 | 99.1% |
| language/punctuators | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/reserved-words | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/rest-parameters | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/source-text | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/statementList | PASS | 80 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/statements | PARTIAL | 9144 | 10 | 0 | 0 | 0 | 0 | 99.9% |
| language/types | PARTIAL | 111 | 2 | 0 | 0 | 0 | 0 | 98.2% |
| language/white-space | PASS | 67 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Intl402 | PARTIAL | 2 | 47 | 0 | 0 | 0 | 0 | 4.1% |
| staging/Temporal | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Uint8Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/built-ins | PARTIAL | 2 | 5 | 0 | 0 | 0 | 0 | 28.6% |
| staging/decorators | PARTIAL | 2 | 1 | 0 | 0 | 0 | 0 | 66.7% |
| staging/explicit-resource-management | PARTIAL | 53 | 1 | 0 | 0 | 0 | 0 | 98.1% |
| staging/set-methods | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/sm | INCOMPLETE | 1274 | 134 | 0 | 20 | 275 | 0 | 90.5% |
| staging/source-phase-imports | SKIPPED | 0 | 0 | 1 | 0 | 0 | 0 | n/a |
| staging/upsert | PASS | 71 | 0 | 0 | 0 | 0 | 0 | 100.0% |

## Blocked Chunks

| Group | Kind | Files | Sample | Log |
|---|---|---:|---|---|
| built-ins/Atomics | CRASH | 1 | `test262/test/built-ins/Atomics/wait/good-views.js` | `.compat-state-builtins-A/logs/f1043b0fe4b011e742a413d3395f878303d2f15a.log` |
| built-ins/Atomics | CRASH | 1 | `test262/test/built-ins/Atomics/notify/undefined-index-defaults-to-zero.js` | `.compat-state-builtins-A/logs/b521b6a22b5b07b86af45b842bcab64d6e2fa4fa.log` |
| built-ins/Atomics | CRASH | 1 | `test262/test/built-ins/Atomics/waitAsync/bigint/symbol-for-timeout-throws-agent.js` | `.compat-state-builtins-A/logs/9bc9d5619ee56dab49f658e05131197bfe34738f.log` |
| built-ins/Atomics | CRASH | 1 | `test262/test/built-ins/Atomics/waitAsync/good-views.js` | `.compat-state-builtins-A/logs/fb55eceac30d4e4532498548409b56cd137a08ec.log` |
| built-ins/Atomics | CRASH | 1 | `test262/test/built-ins/Atomics/wait/waiterlist-order-of-operations-is-fifo.js` | `.compat-state-builtins-A/logs/360a317ad81d289e0cd8ebe98e5093483b257f5e.log` |
| built-ins/Atomics | CRASH | 1 | `test262/test/built-ins/Atomics/waitAsync/bigint/good-views.js` | `.compat-state-builtins-A/logs/990225c3d82151a30e1f9a2c54f94c5bcdbcf193.log` |
| built-ins/Atomics | CRASH | 1 | `test262/test/built-ins/Atomics/wait/bigint/waiterlist-order-of-operations-is-fifo.js` | `.compat-state-builtins-A/logs/99f77a68e9d23feea9bbb8897a391d4dac0b435e.log` |
| built-ins/Atomics | CRASH | 1 | `test262/test/built-ins/Atomics/waitAsync/poisoned-object-for-timeout-throws-agent.js` | `.compat-state-builtins-A/logs/8d48063e4c57bcaf3ff34c19c4b43955c31a6970.log` |
| built-ins/Atomics | CRASH | 1 | `test262/test/built-ins/Atomics/wait/waiterlist-block-indexedposition-wake.js` | `.compat-state-builtins-A/logs/719251ba1739eb43675043a70858942e6cf8878b.log` |
| built-ins/Atomics | CRASH | 1 | `test262/test/built-ins/Atomics/waitAsync/symbol-for-timeout-throws-agent.js` | `.compat-state-builtins-A/logs/9067da206278e759cb52a70362b23d1ba3f0244b.log` |
| built-ins/Atomics | CRASH | 1 | `test262/test/built-ins/Atomics/notify/notify-in-order-one-time.js` | `.compat-state-builtins-A/logs/54aae61a2773839e29cc71d012c368aeeca4d8d3.log` |
| built-ins/Atomics | CRASH | 1 | `test262/test/built-ins/Atomics/notify/notify-in-order.js` | `.compat-state-builtins-A/logs/b30c96a538d7753a383fdb8be79dace311695074.log` |
| built-ins/Atomics | CRASH | 1 | `test262/test/built-ins/Atomics/waitAsync/bigint/poisoned-object-for-timeout-throws-agent.js` | `.compat-state-builtins-A/logs/d2c0bf60ecc0c23543f011c04bf66bce8b5d6042.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-digit-class-escape-positive-cases.js` | `.compat-state-builtins-RegExp-rest/logs/722abcc8bbca8f22a6f4e5029b251630a2d88ff7.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-digit-class-escape-negative-cases.js` | `.compat-state-builtins-RegExp-rest/logs/04ddd46937901b97333e1bad5a7183629e181178.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-whitespace-class-escape-positive-cases.js` | `.compat-state-builtins-RegExp-rest/logs/9022b8c5942fd25645927bb1658a3645cd98d06e.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-word-class-escape-negative-cases.js` | `.compat-state-builtins-RegExp-rest/logs/f81d5d8efb2c4dd1df1c2faa058ff85302c5855b.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-word-class-escape-positive-cases.js` | `.compat-state-builtins-RegExp-rest/logs/5af0de9dd6d6784c7156b505489408bfaf27e665.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-whitespace-class-escape-negative-cases.js` | `.compat-state-builtins-RegExp-rest/logs/f0ebbf279665e1ee7193d8369fb48c919e0422c2.log` |
| built-ins/decodeURI | CRASH | 1 | `test262/test/built-ins/decodeURI/S15.1.3.1_A2.5_T1.js` | `.compat-state-builtins-lower-de/logs/6687624bd5fb87a6a4ff167cd35af4b69d14f50d.log` |
| built-ins/decodeURIComponent | CRASH | 1 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A2.5_T1.js` | `.compat-state-builtins-lower-de/logs/5a3e033d7be8e84f9d36d0440da488ff5b100e26.log` |
| language/literals | CRASH | 1 | `test262/test/language/literals/regexp/S7.8.5_A2.4_T2.js` | `.compat-state-language-literals/logs/2d9ce6285a09a880c93e91b65081fd963e13707a.log` |
| language/literals | CRASH | 1 | `test262/test/language/literals/regexp/S7.8.5_A1.4_T2.js` | `.compat-state-language-literals/logs/a53fea2e0fecfcecd6532ebd795cb1dae3744df8.log` |
| language/literals | CRASH | 1 | `test262/test/language/literals/regexp/S7.8.5_A2.1_T2.js` | `.compat-state-language-literals/logs/d9e1bf9de472dab2910e641eec8172033ad31a6c.log` |
| language/literals | CRASH | 1 | `test262/test/language/literals/regexp/S7.8.5_A1.1_T2.js` | `.compat-state-language-literals/logs/d05363a5be24ca4fcae715d01678920a9b9dd8f2.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/BigInt/large-bit-length.js` | `.compat-state-staging/logs/384b2e0bfc2b466c4c57a4a23d7c3f82c656ddac.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Date/dst-offset-caching-3-of-8.js` | `.compat-state-staging/logs/f77d96875630465a6823161d465877fc4c60f9ae.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/expressions/nullish-coalescing.js` | `.compat-state-staging/logs/fad83b5e2409a84effda05416199c0acf9fa5248.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/regress/regress-1507322-deep-weakmap.js` | `.compat-state-staging/logs/59f611b1d8f6087de2cf8dd8fc9a3ce8ba03416e.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Array/toSpliced-dense.js` | `.compat-state-staging/logs/1d8bc8ec59afa4977eac427273dcca4bb1c34b5e.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/TypedArray/set-same-buffer-different-source-target-types.js` | `.compat-state-staging/logs/f31cff08aae8050007f42d47f3ce1d7c29321ebb.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/TypedArray/sort_large_countingsort.js` | `.compat-state-staging/logs/f1232104e4d338c7c76a723ace84621f84895ca1.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Date/dst-offset-caching-4-of-8.js` | `.compat-state-staging/logs/dd2d25d5cc94da8b81ed7a27013584a93402ab8e.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Date/dst-offset-caching-5-of-8.js` | `.compat-state-staging/logs/c58e8146c6f3a001cf0781adbce015d2d9682c2d.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Date/dst-offset-caching-1-of-8.js` | `.compat-state-staging/logs/f72507a466dd0056e8a719c4d2fad7b060dc5a34.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Date/dst-offset-caching-2-of-8.js` | `.compat-state-staging/logs/42df9b8c4fc0d995dcf4cb51158e13f63ab55e1e.log` |
| staging/sm | OOM | 1 | `test262/test/staging/sm/String/replace-math.js` | `.compat-state-staging/logs/05d8c12e9055c0e405b4fc8ebd3aee35b95f8c6f.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/TypedArray/sort_modifications.js` | `.compat-state-staging/logs/ea3a9224766eb7444ed6f90eb1c8f521ae9dc56c.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/String/fromCodePoint.js` | `.compat-state-staging/logs/b7a6467e0fd7aca48f43b52e9e2e76feff030b05.log` |
| staging/sm | OOM | 1 | `test262/test/staging/sm/regress/regress-567152.js` | `.compat-state-staging/logs/f2fc29f0b2ecc140a7956dac1e2335e2f3b2da5e.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Date/dst-offset-caching-6-of-8.js` | `.compat-state-staging/logs/fc434724163390adbcf5051f147ab7c4fd54a93b.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Date/dst-offset-caching-7-of-8.js` | `.compat-state-staging/logs/931d7d3921a965471071a964166aad45b1d595f4.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Date/dst-offset-caching-8-of-8.js` | `.compat-state-staging/logs/af18b1bdadaf3999fb864b5776bc2b53ed5c7dcb.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Proxy/ownkeys-linear.js` | `.compat-state-staging/logs/a263da00063883c2fe17b886920ad7c7186a6463.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/TypedArray/element-setting-converts-using-ToNumber.js` | `.compat-state-staging/logs/655e0e3f8baf6b2fbdfb85776837eb8131d920b4.log` |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| staging/sm | NORMAL | 64.651s | 25 | `test262/test/staging/sm/String/string-pad-start-end.js`<br>`test262/test/staging/sm/String/string-space-trim.js`<br>...<br>`test262/test/staging/sm/Symbol/property-reflection.js`<br>`test262/test/staging/sm/Symbol/realms.js` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 61.841s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-digit-class-escape-positive-cases.js` |
| staging/sm | CRASH | 61.697s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-7-of-8.js` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 61.669s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-digit-class-escape-negative-cases.js` |
| built-ins/Atomics | CRASH | 61.648s | 1 | `test262/test/built-ins/Atomics/wait/bigint/waiterlist-order-of-operations-is-fifo.js` |
| built-ins/Atomics | CRASH | 61.549s | 1 | `test262/test/built-ins/Atomics/notify/notify-in-order.js` |
| staging/sm | CRASH | 61.368s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-6-of-8.js` |
| staging/sm | CRASH | 61.264s | 1 | `test262/test/staging/sm/expressions/nullish-coalescing.js` |
| staging/sm | CRASH | 61.135s | 1 | `test262/test/staging/sm/TypedArray/sort_modifications.js` |
| staging/sm | CRASH | 61.125s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-8-of-8.js` |
| staging/sm | CRASH | 60.972s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-1-of-8.js` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 60.902s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-whitespace-class-escape-negative-cases.js` |
| built-ins/Atomics | CRASH | 60.864s | 1 | `test262/test/built-ins/Atomics/waitAsync/bigint/good-views.js` |
| built-ins/Atomics | CRASH | 60.833s | 1 | `test262/test/built-ins/Atomics/notify/undefined-index-defaults-to-zero.js` |
| built-ins/Atomics | CRASH | 60.800s | 1 | `test262/test/built-ins/Atomics/wait/good-views.js` |
| staging/sm | CRASH | 60.729s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-5-of-8.js` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 60.710s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-word-class-escape-negative-cases.js` |
| built-ins/Atomics | CRASH | 60.694s | 1 | `test262/test/built-ins/Atomics/wait/waiterlist-order-of-operations-is-fifo.js` |
| staging/sm | CRASH | 60.643s | 1 | `test262/test/staging/sm/TypedArray/sort_large_countingsort.js` |
| staging/sm | CRASH | 60.530s | 1 | `test262/test/staging/sm/Proxy/ownkeys-linear.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are executed as ES modules (always strict, import/export processed).
- Tests flagged as async are skipped by the current runner.
- Mixed-mode tests currently run only in sloppy mode unless onlyStrict or noStrict is set.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

- `import-defer`
- `source-phase-imports`
- `import-attributes`
- `json-modules`
