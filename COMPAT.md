# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-04-24T22:02:16+00:00`
- Chunk size: `250`
- Timeout: `300s`
- Jobs: `4`
- Groups: `158`
- Test files: `50506`
- Git: `main` @ `b793133` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 48053 | 2040 | 7 | 51 | 49697 | 12 | 50093 | 50151 | 99860 | 95.9% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | INCOMPLETE | 1067 | 12 | 0 | 0 | 954 | 0 | 98.9% |
| built-ins | RUNNING | 21689 | 663 | 7 | 26 | 22559 | 12 | 97.0% |
| harness | INCOMPLETE | 112 | 4 | 0 | 0 | 116 | 0 | 96.6% |
| intl402 | INCOMPLETE | 684 | 882 | 0 | 0 | 1442 | 0 | 43.7% |
| language | INCOMPLETE | 23230 | 139 | 0 | 4 | 23073 | 0 | 99.4% |
| staging | INCOMPLETE | 1271 | 340 | 0 | 21 | 1553 | 0 | 78.9% |

## Group Coverage

| Group | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB/built-ins/Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Date | PASS | 24 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Function | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Object | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/RegExp | INCOMPLETE | 54 | 8 | 0 | 0 | 62 | 0 | 87.1% |
| annexB/built-ins/String | INCOMPLETE | 111 | 0 | 0 | 0 | 111 | 0 | 100.0% |
| annexB/built-ins/TypedArrayConstructors | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/escape | PASS | 16 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/unescape | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/comments | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/eval-code | INCOMPLETE | 469 | 0 | 0 | 0 | 469 | 0 | 100.0% |
| annexB/language/expressions | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/function-code | INCOMPLETE | 159 | 0 | 0 | 0 | 159 | 0 | 100.0% |
| annexB/language/global-code | INCOMPLETE | 153 | 0 | 0 | 0 | 153 | 0 | 100.0% |
| annexB/language/literals | PARTIAL | 4 | 4 | 0 | 0 | 0 | 0 | 50.0% |
| annexB/language/statements | PASS | 22 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AbstractModuleSource | FAIL | 0 | 8 | 0 | 0 | 0 | 0 | 0.0% |
| built-ins/Array | INCOMPLETE | 3084 | 71 | 0 | 1 | 3075 | 0 | 97.7% |
| built-ins/ArrayBuffer | INCOMPLETE | 191 | 1 | 0 | 0 | 192 | 0 | 99.5% |
| built-ins/ArrayIteratorPrototype | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncDisposableStack | INCOMPLETE | 52 | 0 | 0 | 0 | 52 | 0 | 100.0% |
| built-ins/AsyncFromSyncIteratorPrototype | PARTIAL | 35 | 3 | 0 | 0 | 0 | 0 | 92.1% |
| built-ins/AsyncFunction | PASS | 18 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorFunction | PARTIAL | 22 | 1 | 0 | 0 | 0 | 0 | 95.7% |
| built-ins/AsyncGeneratorPrototype | PARTIAL | 45 | 3 | 0 | 0 | 0 | 0 | 93.8% |
| built-ins/AsyncIteratorPrototype | PASS | 10 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Atomics | INCOMPLETE | 282 | 74 | 7 | 13 | 376 | 0 | 79.2% |
| built-ins/BigInt | INCOMPLETE | 75 | 0 | 0 | 0 | 75 | 0 | 100.0% |
| built-ins/Boolean | INCOMPLETE | 51 | 0 | 0 | 0 | 51 | 0 | 100.0% |
| built-ins/DataView | INCOMPLETE | 547 | 3 | 0 | 0 | 500 | 0 | 99.5% |
| built-ins/Date | INCOMPLETE | 594 | 0 | 0 | 0 | 594 | 0 | 100.0% |
| built-ins/DisposableStack | INCOMPLETE | 52 | 0 | 0 | 0 | 52 | 0 | 100.0% |
| built-ins/Error | INCOMPLETE | 53 | 0 | 0 | 0 | 53 | 0 | 100.0% |
| built-ins/FinalizationRegistry | PASS | 47 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Function | INCOMPLETE | 500 | 9 | 0 | 0 | 500 | 0 | 98.2% |
| built-ins/GeneratorFunction | PARTIAL | 22 | 1 | 0 | 0 | 0 | 0 | 95.7% |
| built-ins/GeneratorPrototype | INCOMPLETE | 61 | 0 | 0 | 0 | 61 | 0 | 100.0% |
| built-ins/Infinity | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Iterator | INCOMPLETE | 431 | 0 | 0 | 0 | 431 | 0 | 100.0% |
| built-ins/JSON | INCOMPLETE | 164 | 1 | 0 | 0 | 165 | 0 | 99.4% |
| built-ins/Map | INCOMPLETE | 171 | 0 | 0 | 0 | 171 | 0 | 100.0% |
| built-ins/MapIteratorPrototype | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Math | INCOMPLETE | 327 | 0 | 0 | 0 | 327 | 0 | 100.0% |
| built-ins/NaN | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/NativeErrors | INCOMPLETE | 139 | 0 | 0 | 0 | 139 | 0 | 100.0% |
| built-ins/Number | INCOMPLETE | 335 | 0 | 0 | 0 | 335 | 0 | 100.0% |
| built-ins/Object | INCOMPLETE | 3410 | 0 | 0 | 0 | 3410 | 0 | 100.0% |
| built-ins/Promise | INCOMPLETE | 589 | 42 | 0 | 0 | 631 | 0 | 93.3% |
| built-ins/Proxy | INCOMPLETE | 304 | 7 | 0 | 0 | 311 | 0 | 97.7% |
| built-ins/Reflect | INCOMPLETE | 153 | 0 | 0 | 0 | 153 | 0 | 100.0% |
| built-ins/RegExp | INCOMPLETE | 469 | 19 | 0 | 0 | 488 | 0 | 96.1% |
| built-ins/RegExp/CharacterClassEscapes | INCOMPLETE | 6 | 0 | 0 | 6 | 12 | 0 | 100.0% |
| built-ins/RegExp/Symbol.species | PASS | 4 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/dotall | FAIL | 0 | 4 | 0 | 0 | 0 | 0 | 0.0% |
| built-ins/RegExp/escape | PASS | 20 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/lookBehind | PARTIAL | 2 | 15 | 0 | 0 | 0 | 0 | 11.8% |
| built-ins/RegExp/match-indices | PARTIAL | 1 | 13 | 0 | 0 | 0 | 0 | 7.1% |
| built-ins/RegExp/named-groups | PARTIAL | 19 | 17 | 0 | 0 | 0 | 0 | 52.8% |
| built-ins/RegExp/property-escapes | RUNNING | 11 | 166 | 0 | 0 | 1015 | 12 | 6.2% |
| built-ins/RegExp/prototype | INCOMPLETE | 421 | 65 | 0 | 1 | 487 | 0 | 86.6% |
| built-ins/RegExp/regexp-modifiers | INCOMPLETE | 55 | 15 | 0 | 0 | 70 | 0 | 78.6% |
| built-ins/RegExp/unicodeSets | INCOMPLETE | 38 | 75 | 0 | 0 | 113 | 0 | 33.6% |
| built-ins/RegExpStringIteratorPrototype | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Set | INCOMPLETE | 381 | 0 | 0 | 0 | 381 | 0 | 100.0% |
| built-ins/SetIteratorPrototype | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ShadowRealm | INCOMPLETE | 52 | 12 | 0 | 0 | 64 | 0 | 81.3% |
| built-ins/SharedArrayBuffer | INCOMPLETE | 104 | 0 | 0 | 0 | 104 | 0 | 100.0% |
| built-ins/String | INCOMPLETE | 1207 | 5 | 0 | 0 | 1212 | 0 | 99.6% |
| built-ins/StringIteratorPrototype | PASS | 7 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Symbol | INCOMPLETE | 92 | 2 | 0 | 0 | 94 | 0 | 97.9% |
| built-ins/Temporal | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/Duration | INCOMPLETE | 473 | 0 | 0 | 0 | 473 | 0 | 100.0% |
| built-ins/Temporal/Instant | INCOMPLETE | 434 | 0 | 0 | 0 | 434 | 0 | 100.0% |
| built-ins/Temporal/Now | INCOMPLETE | 66 | 0 | 0 | 0 | 66 | 0 | 100.0% |
| built-ins/Temporal/PlainDate | INCOMPLETE | 592 | 0 | 0 | 0 | 592 | 0 | 100.0% |
| built-ins/Temporal/PlainDateTime | INCOMPLETE | 684 | 0 | 0 | 0 | 684 | 0 | 100.0% |
| built-ins/Temporal/PlainMonthDay | INCOMPLETE | 184 | 0 | 0 | 0 | 184 | 0 | 100.0% |
| built-ins/Temporal/PlainTime | INCOMPLETE | 457 | 0 | 0 | 0 | 457 | 0 | 100.0% |
| built-ins/Temporal/PlainYearMonth | INCOMPLETE | 465 | 0 | 0 | 0 | 465 | 0 | 100.0% |
| built-ins/Temporal/ZonedDateTime | INCOMPLETE | 805 | 0 | 0 | 0 | 805 | 0 | 100.0% |
| built-ins/Temporal/toStringTag | PASS | 2 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ThrowTypeError | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/TypedArray | INCOMPLETE | 1399 | 27 | 0 | 0 | 1426 | 0 | 98.1% |
| built-ins/TypedArrayConstructors | INCOMPLETE | 733 | 3 | 0 | 0 | 736 | 0 | 99.6% |
| built-ins/Uint8Array | INCOMPLETE | 63 | 1 | 0 | 0 | 64 | 0 | 98.4% |
| built-ins/WeakMap | INCOMPLETE | 102 | 0 | 0 | 0 | 102 | 0 | 100.0% |
| built-ins/WeakRef | PASS | 29 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakSet | INCOMPLETE | 85 | 0 | 0 | 0 | 85 | 0 | 100.0% |
| built-ins/decodeURI | INCOMPLETE | 53 | 0 | 0 | 2 | 55 | 0 | 100.0% |
| built-ins/decodeURIComponent | INCOMPLETE | 53 | 0 | 0 | 3 | 56 | 0 | 100.0% |
| built-ins/encodeURI | INCOMPLETE | 31 | 0 | 0 | 0 | 31 | 0 | 100.0% |
| built-ins/encodeURIComponent | INCOMPLETE | 31 | 0 | 0 | 0 | 31 | 0 | 100.0% |
| built-ins/eval | PASS | 10 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/global | PASS | 29 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isFinite | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isNaN | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseFloat | INCOMPLETE | 59 | 0 | 0 | 0 | 59 | 0 | 100.0% |
| built-ins/parseInt | INCOMPLETE | 60 | 0 | 0 | 0 | 60 | 0 | 100.0% |
| built-ins/undefined | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| harness | INCOMPLETE | 112 | 4 | 0 | 0 | 116 | 0 | 96.6% |
| intl402 | PARTIAL | 8 | 14 | 0 | 0 | 0 | 0 | 36.4% |
| intl402/Array | PASS | 2 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/BigInt | PARTIAL | 6 | 5 | 0 | 0 | 0 | 0 | 54.5% |
| intl402/Collator | INCOMPLETE | 44 | 18 | 0 | 0 | 62 | 0 | 71.0% |
| intl402/Date | PARTIAL | 10 | 2 | 0 | 0 | 0 | 0 | 83.3% |
| intl402/DateTimeFormat | INCOMPLETE | 73 | 115 | 0 | 0 | 188 | 0 | 38.8% |
| intl402/DisplayNames | INCOMPLETE | 41 | 16 | 0 | 0 | 57 | 0 | 71.9% |
| intl402/DurationFormat | INCOMPLETE | 0 | 110 | 0 | 0 | 110 | 0 | 0.0% |
| intl402/Intl | INCOMPLETE | 33 | 34 | 0 | 0 | 67 | 0 | 49.3% |
| intl402/ListFormat | INCOMPLETE | 37 | 44 | 0 | 0 | 81 | 0 | 45.7% |
| intl402/Locale | INCOMPLETE | 81 | 66 | 0 | 0 | 147 | 0 | 55.1% |
| intl402/Number | PARTIAL | 5 | 2 | 0 | 0 | 0 | 0 | 71.4% |
| intl402/NumberFormat | INCOMPLETE | 103 | 149 | 0 | 0 | 250 | 0 | 40.9% |
| intl402/PluralRules | PARTIAL | 39 | 11 | 0 | 0 | 0 | 0 | 78.0% |
| intl402/RelativeTimeFormat | INCOMPLETE | 41 | 38 | 0 | 0 | 79 | 0 | 51.9% |
| intl402/Segmenter | INCOMPLETE | 50 | 28 | 0 | 0 | 78 | 0 | 64.1% |
| intl402/String | PARTIAL | 8 | 9 | 0 | 0 | 0 | 0 | 47.1% |
| intl402/Temporal | INCOMPLETE | 102 | 221 | 0 | 0 | 323 | 0 | 31.6% |
| intl402/TypedArray | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/arguments-object | INCOMPLETE | 263 | 0 | 0 | 0 | 250 | 0 | 100.0% |
| language/asi | INCOMPLETE | 102 | 0 | 0 | 0 | 102 | 0 | 100.0% |
| language/block-scope | INCOMPLETE | 145 | 0 | 0 | 0 | 145 | 0 | 100.0% |
| language/comments | INCOMPLETE | 52 | 0 | 0 | 0 | 52 | 0 | 100.0% |
| language/computed-property-names | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/destructuring | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/directive-prologue | INCOMPLETE | 62 | 0 | 0 | 0 | 62 | 0 | 100.0% |
| language/eval-code | INCOMPLETE | 346 | 1 | 0 | 0 | 347 | 0 | 99.7% |
| language/export | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/expressions | INCOMPLETE | 10974 | 49 | 0 | 0 | 11000 | 0 | 99.6% |
| language/function-code | INCOMPLETE | 217 | 0 | 0 | 0 | 217 | 0 | 100.0% |
| language/future-reserved-words | INCOMPLETE | 55 | 0 | 0 | 0 | 55 | 0 | 100.0% |
| language/global-code | PASS | 42 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifier-resolution | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifiers | INCOMPLETE | 260 | 0 | 0 | 0 | 250 | 0 | 100.0% |
| language/import | INCOMPLETE | 19 | 66 | 0 | 0 | 85 | 0 | 22.4% |
| language/keywords | PASS | 25 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/line-terminators | PASS | 41 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/literals | INCOMPLETE | 527 | 3 | 0 | 4 | 500 | 0 | 99.4% |
| language/module-code | INCOMPLETE | 577 | 6 | 0 | 0 | 583 | 0 | 99.0% |
| language/punctuators | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/reserved-words | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/rest-parameters | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/source-text | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/statementList | INCOMPLETE | 80 | 0 | 0 | 0 | 80 | 0 | 100.0% |
| language/statements | INCOMPLETE | 9142 | 12 | 0 | 0 | 9154 | 0 | 99.9% |
| language/types | INCOMPLETE | 111 | 2 | 0 | 0 | 113 | 0 | 98.2% |
| language/white-space | INCOMPLETE | 67 | 0 | 0 | 0 | 67 | 0 | 100.0% |
| staging | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Intl402 | PARTIAL | 2 | 47 | 0 | 0 | 0 | 0 | 4.1% |
| staging/Temporal | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Uint8Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/built-ins | PARTIAL | 3 | 4 | 0 | 0 | 0 | 0 | 42.9% |
| staging/decorators | PARTIAL | 2 | 1 | 0 | 0 | 0 | 0 | 66.7% |
| staging/explicit-resource-management | INCOMPLETE | 51 | 3 | 0 | 0 | 54 | 0 | 94.4% |
| staging/set-methods | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/sm | INCOMPLETE | 1123 | 284 | 0 | 21 | 1428 | 0 | 79.8% |
| staging/source-phase-imports | FAIL | 0 | 1 | 0 | 0 | 0 | 0 | 0.0% |
| staging/upsert | INCOMPLETE | 71 | 0 | 0 | 0 | 71 | 0 | 100.0% |

## Blocked Chunks

| Group | Kind | Files | Sample | Log |
|---|---|---:|---|---|
| built-ins/Atomics | TIMEOUT | 1 | `test262/test/built-ins/Atomics/wait/good-views.js` | `.compat-state-builtins-A/logs/f1043b0fe4b011e742a413d3395f878303d2f15a.log` |
| built-ins/Atomics | TIMEOUT | 1 | `test262/test/built-ins/Atomics/notify/undefined-index-defaults-to-zero.js` | `.compat-state-builtins-A/logs/b521b6a22b5b07b86af45b842bcab64d6e2fa4fa.log` |
| built-ins/Atomics | TIMEOUT | 1 | `test262/test/built-ins/Atomics/waitAsync/bigint/symbol-for-timeout-throws-agent.js` | `.compat-state-builtins-A/logs/9bc9d5619ee56dab49f658e05131197bfe34738f.log` |
| built-ins/Atomics | TIMEOUT | 1 | `test262/test/built-ins/Atomics/waitAsync/good-views.js` | `.compat-state-builtins-A/logs/fb55eceac30d4e4532498548409b56cd137a08ec.log` |
| built-ins/Array | TIMEOUT | 1 | `test262/test/built-ins/Array/fromAsync/asyncitems-arraylike-too-long.js` | `.compat-state-builtins-A/logs/8bef7a42348d0e516acb60d05acdb3bf47665f19.log` |
| built-ins/Atomics | TIMEOUT | 1 | `test262/test/built-ins/Atomics/wait/waiterlist-order-of-operations-is-fifo.js` | `.compat-state-builtins-A/logs/360a317ad81d289e0cd8ebe98e5093483b257f5e.log` |
| built-ins/Atomics | TIMEOUT | 1 | `test262/test/built-ins/Atomics/waitAsync/bigint/good-views.js` | `.compat-state-builtins-A/logs/990225c3d82151a30e1f9a2c54f94c5bcdbcf193.log` |
| built-ins/Atomics | TIMEOUT | 1 | `test262/test/built-ins/Atomics/wait/bigint/waiterlist-order-of-operations-is-fifo.js` | `.compat-state-builtins-A/logs/99f77a68e9d23feea9bbb8897a391d4dac0b435e.log` |
| built-ins/Atomics | TIMEOUT | 1 | `test262/test/built-ins/Atomics/waitAsync/poisoned-object-for-timeout-throws-agent.js` | `.compat-state-builtins-A/logs/8d48063e4c57bcaf3ff34c19c4b43955c31a6970.log` |
| built-ins/Atomics | TIMEOUT | 1 | `test262/test/built-ins/Atomics/waitAsync/symbol-for-timeout-throws-agent.js` | `.compat-state-builtins-A/logs/9067da206278e759cb52a70362b23d1ba3f0244b.log` |
| built-ins/Atomics | TIMEOUT | 1 | `test262/test/built-ins/Atomics/notify/notify-in-order-one-time.js` | `.compat-state-builtins-A/logs/54aae61a2773839e29cc71d012c368aeeca4d8d3.log` |
| built-ins/Atomics | TIMEOUT | 1 | `test262/test/built-ins/Atomics/notify/notify-in-order.js` | `.compat-state-builtins-A/logs/b30c96a538d7753a383fdb8be79dace311695074.log` |
| built-ins/Atomics | TIMEOUT | 1 | `test262/test/built-ins/Atomics/wait/waiterlist-block-indexedposition-wake.js` | `.compat-state-builtins-A/logs/719251ba1739eb43675043a70858942e6cf8878b.log` |
| built-ins/Atomics | TIMEOUT | 1 | `test262/test/built-ins/Atomics/waitAsync/bigint/poisoned-object-for-timeout-throws-agent.js` | `.compat-state-builtins-A/logs/d2c0bf60ecc0c23543f011c04bf66bce8b5d6042.log` |
| built-ins/RegExp/prototype | TIMEOUT | 1 | `test262/test/built-ins/RegExp/prototype/Symbol.split/str-coerce-lastindex.js` | `.compat-state-builtins-RegExp-prototype/logs/402e0291ddcd8c8e6994ba9784bc4c9dd4157ac8.log` |
| built-ins/RegExp/CharacterClassEscapes | TIMEOUT | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-digit-class-escape-negative-cases.js` | `.compat-state-builtins-RegExp-rest/logs/04ddd46937901b97333e1bad5a7183629e181178.log` |
| built-ins/RegExp/CharacterClassEscapes | TIMEOUT | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-digit-class-escape-positive-cases.js` | `.compat-state-builtins-RegExp-rest/logs/722abcc8bbca8f22a6f4e5029b251630a2d88ff7.log` |
| built-ins/RegExp/CharacterClassEscapes | TIMEOUT | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-whitespace-class-escape-positive-cases.js` | `.compat-state-builtins-RegExp-rest/logs/9022b8c5942fd25645927bb1658a3645cd98d06e.log` |
| built-ins/RegExp/CharacterClassEscapes | TIMEOUT | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-word-class-escape-negative-cases.js` | `.compat-state-builtins-RegExp-rest/logs/f81d5d8efb2c4dd1df1c2faa058ff85302c5855b.log` |
| built-ins/RegExp/CharacterClassEscapes | TIMEOUT | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-word-class-escape-positive-cases.js` | `.compat-state-builtins-RegExp-rest/logs/5af0de9dd6d6784c7156b505489408bfaf27e665.log` |
| built-ins/RegExp/CharacterClassEscapes | TIMEOUT | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-whitespace-class-escape-negative-cases.js` | `.compat-state-builtins-RegExp-rest/logs/f0ebbf279665e1ee7193d8369fb48c919e0422c2.log` |
| built-ins/decodeURIComponent | TIMEOUT | 1 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.12_T1.js` | `.compat-state-builtins-lower-de/logs/9b8eeaae429ea1ee343ab352ff27a68fa939341d.log` |
| built-ins/decodeURIComponent | TIMEOUT | 1 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A2.4_T1.js` | `.compat-state-builtins-lower-de/logs/3bc8f36cb6a0e1244018c0253aa9f24b6c9deef8.log` |
| built-ins/decodeURIComponent | TIMEOUT | 1 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A2.5_T1.js` | `.compat-state-builtins-lower-de/logs/5a3e033d7be8e84f9d36d0440da488ff5b100e26.log` |
| built-ins/decodeURI | TIMEOUT | 1 | `test262/test/built-ins/decodeURI/S15.1.3.1_A2.4_T1.js` | `.compat-state-builtins-lower-de/logs/62ef5c08b34c6a7aea99504afbb46459cb654a4f.log` |
| built-ins/decodeURI | TIMEOUT | 1 | `test262/test/built-ins/decodeURI/S15.1.3.1_A2.5_T1.js` | `.compat-state-builtins-lower-de/logs/6687624bd5fb87a6a4ff167cd35af4b69d14f50d.log` |
| language/literals | TIMEOUT | 1 | `test262/test/language/literals/regexp/S7.8.5_A2.4_T2.js` | `.compat-state-language-literals/logs/2d9ce6285a09a880c93e91b65081fd963e13707a.log` |
| language/literals | TIMEOUT | 1 | `test262/test/language/literals/regexp/S7.8.5_A2.1_T2.js` | `.compat-state-language-literals/logs/d9e1bf9de472dab2910e641eec8172033ad31a6c.log` |
| language/literals | TIMEOUT | 1 | `test262/test/language/literals/regexp/S7.8.5_A1.1_T2.js` | `.compat-state-language-literals/logs/d05363a5be24ca4fcae715d01678920a9b9dd8f2.log` |
| language/literals | TIMEOUT | 1 | `test262/test/language/literals/regexp/S7.8.5_A1.4_T2.js` | `.compat-state-language-literals/logs/a53fea2e0fecfcecd6532ebd795cb1dae3744df8.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/BigInt/large-bit-length.js` | `.compat-state-staging/logs/384b2e0bfc2b466c4c57a4a23d7c3f82c656ddac.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-3-of-8.js` | `.compat-state-staging/logs/f77d96875630465a6823161d465877fc4c60f9ae.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/expressions/nullish-coalescing.js` | `.compat-state-staging/logs/fad83b5e2409a84effda05416199c0acf9fa5248.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/regress/regress-1507322-deep-weakmap.js` | `.compat-state-staging/logs/59f611b1d8f6087de2cf8dd8fc9a3ce8ba03416e.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Array/toSpliced-dense.js` | `.compat-state-staging/logs/1d8bc8ec59afa4977eac427273dcca4bb1c34b5e.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/TypedArray/set-same-buffer-different-source-target-types.js` | `.compat-state-staging/logs/f31cff08aae8050007f42d47f3ce1d7c29321ebb.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/TypedArray/sort_large_countingsort.js` | `.compat-state-staging/logs/f1232104e4d338c7c76a723ace84621f84895ca1.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-4-of-8.js` | `.compat-state-staging/logs/dd2d25d5cc94da8b81ed7a27013584a93402ab8e.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-5-of-8.js` | `.compat-state-staging/logs/c58e8146c6f3a001cf0781adbce015d2d9682c2d.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/TypedArray/sort_modifications.js` | `.compat-state-staging/logs/ea3a9224766eb7444ed6f90eb1c8f521ae9dc56c.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/TypedArray/sort_small.js` | `.compat-state-staging/logs/52f623545148a1d44ec09f914a582ab83c001e20.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/String/fromCodePoint.js` | `.compat-state-staging/logs/b7a6467e0fd7aca48f43b52e9e2e76feff030b05.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-1-of-8.js` | `.compat-state-staging/logs/f72507a466dd0056e8a719c4d2fad7b060dc5a34.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-2-of-8.js` | `.compat-state-staging/logs/42df9b8c4fc0d995dcf4cb51158e13f63ab55e1e.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/String/normalize-generateddata-input.js` | `.compat-state-staging/logs/3b5b0dfdd5b7319fb66e34287be3304e891b1274.log` |
| staging/sm | OOM | 1 | `test262/test/staging/sm/String/replace-math.js` | `.compat-state-staging/logs/05d8c12e9055c0e405b4fc8ebd3aee35b95f8c6f.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-6-of-8.js` | `.compat-state-staging/logs/fc434724163390adbcf5051f147ab7c4fd54a93b.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-7-of-8.js` | `.compat-state-staging/logs/931d7d3921a965471071a964166aad45b1d595f4.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/regress/regress-567152.js` | `.compat-state-staging/logs/f2fc29f0b2ecc140a7956dac1e2335e2f3b2da5e.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-8-of-8.js` | `.compat-state-staging/logs/af18b1bdadaf3999fb864b5776bc2b53ed5c7dcb.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Proxy/ownkeys-linear.js` | `.compat-state-staging/logs/a263da00063883c2fe17b886920ad7c7186a6463.log` |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| staging/sm | TIMEOUT | 61.177s | 1 | `test262/test/staging/sm/String/fromCodePoint.js` |
| staging/sm | TIMEOUT | 61.027s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-5-of-8.js` |
| built-ins/RegExp/CharacterClassEscapes | TIMEOUT | 61.018s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-digit-class-escape-negative-cases.js` |
| staging/sm | TIMEOUT | 60.823s | 1 | `test262/test/staging/sm/expressions/nullish-coalescing.js` |
| built-ins/RegExp/CharacterClassEscapes | TIMEOUT | 60.719s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-word-class-escape-negative-cases.js` |
| built-ins/RegExp/CharacterClassEscapes | TIMEOUT | 60.670s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-whitespace-class-escape-positive-cases.js` |
| built-ins/RegExp/CharacterClassEscapes | TIMEOUT | 60.607s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-whitespace-class-escape-negative-cases.js` |
| staging/sm | TIMEOUT | 60.250s | 1 | `test262/test/staging/sm/regress/regress-567152.js` |
| staging/sm | TIMEOUT | 60.148s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-1-of-8.js` |
| staging/sm | TIMEOUT | 60.075s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-6-of-8.js` |
| staging/sm | TIMEOUT | 60.063s | 1 | `test262/test/staging/sm/TypedArray/set-same-buffer-different-source-target-types.js` |
| built-ins/RegExp/CharacterClassEscapes | TIMEOUT | 59.832s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-digit-class-escape-positive-cases.js` |
| language/literals | TIMEOUT | 59.714s | 1 | `test262/test/language/literals/regexp/S7.8.5_A2.4_T2.js` |
| staging/sm | TIMEOUT | 59.624s | 1 | `test262/test/staging/sm/TypedArray/sort_large_countingsort.js` |
| staging/sm | TIMEOUT | 59.192s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-3-of-8.js` |
| staging/sm | TIMEOUT | 59.168s | 1 | `test262/test/staging/sm/regress/regress-1507322-deep-weakmap.js` |
| built-ins/Atomics | TIMEOUT | 59.088s | 1 | `test262/test/built-ins/Atomics/waitAsync/bigint/good-views.js` |
| built-ins/Atomics | TIMEOUT | 59.004s | 1 | `test262/test/built-ins/Atomics/wait/bigint/waiterlist-order-of-operations-is-fifo.js` |
| built-ins/Atomics | TIMEOUT | 58.921s | 1 | `test262/test/built-ins/Atomics/notify/notify-in-order.js` |
| built-ins/Atomics | TIMEOUT | 58.586s | 1 | `test262/test/built-ins/Atomics/notify/notify-in-order-one-time.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are executed as ES modules (always strict, import/export processed).
- Tests flagged as async are skipped by the current runner.
- Mixed-mode tests currently run only in sloppy mode unless onlyStrict or noStrict is set.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

