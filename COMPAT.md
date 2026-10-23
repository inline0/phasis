# Compatibility Snapshot

Generated from a completed `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Snapshot time: `2026-10-23T15:08:10+00:00`
- Chunk size: `25`
- Timeout: `300s`
- Jobs: `4`
- Groups: `161`
- Test files: `53616`
- Git: `fix/test262-compliance-20261023` @ `ba89bcaa` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 53499 | 110 | 0 | 7 | 0 | 0 | 53609 | 53616 | 53616 | 99.8% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | PASS | 1086 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins | BLOCKED | 23804 | 17 | 0 | 2 | 0 | 0 | 99.9% |
| harness | PASS | 116 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402 | PARTIAL | 3377 | 5 | 0 | 0 | 0 | 0 | 99.9% |
| language | PARTIAL | 23640 | 86 | 0 | 0 | 0 | 0 | 99.6% |
| staging | BLOCKED | 1476 | 2 | 0 | 5 | 0 | 0 | 99.9% |

## Group Coverage

| Group | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB/built-ins/Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Date | PASS | 24 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Function | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Object | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/RegExp | PASS | 62 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/String | PASS | 111 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/TypedArrayConstructors | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/escape | PASS | 16 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/unescape | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/comments | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/eval-code | PASS | 469 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/expressions | PASS | 26 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/function-code | PASS | 159 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/global-code | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/literals | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/statements | PASS | 22 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AbstractModuleSource | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AggregateError | PASS | 25 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Array | PARTIAL | 3070 | 13 | 0 | 0 | 0 | 0 | 99.6% |
| built-ins/ArrayBuffer | PASS | 221 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ArrayIteratorPrototype | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncDisposableStack | PASS | 104 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncFromSyncIteratorPrototype | PASS | 38 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncFunction | PASS | 18 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorFunction | PASS | 23 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorPrototype | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncIteratorPrototype | PASS | 13 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Atomics | PASS | 389 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/BigInt | PASS | 77 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Boolean | PASS | 51 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/DataView | PASS | 561 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Date | PASS | 594 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/DisposableStack | PASS | 93 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Error | PASS | 93 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/FinalizationRegistry | PASS | 47 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Function | PASS | 509 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/GeneratorFunction | PASS | 23 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/GeneratorPrototype | PASS | 61 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Infinity | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Iterator | PASS | 654 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/JSON | PASS | 166 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Map | PASS | 204 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/MapIteratorPrototype | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Math | PASS | 327 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/NaN | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/NativeErrors | PASS | 94 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Number | PARTIAL | 337 | 3 | 0 | 0 | 0 | 0 | 99.1% |
| built-ins/Object | PASS | 3411 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Promise | PASS | 732 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Proxy | PASS | 311 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Reflect | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp | PASS | 488 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/CharacterClassEscapes | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/Symbol.species | PASS | 4 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/dotall | PASS | 4 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/escape | PASS | 20 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/lookBehind | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/match-indices | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/named-groups | PASS | 36 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/property-escapes | PASS | 144 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/property-escapes/generated | PASS | 469 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/prototype | PASS | 487 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/regexp-modifiers | PASS | 70 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/unicodeSets | PASS | 114 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExpStringIteratorPrototype | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Set | PASS | 383 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/SetIteratorPrototype | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ShadowRealm | PASS | 64 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/SharedArrayBuffer | PASS | 104 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/String | PASS | 1223 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/StringIteratorPrototype | PASS | 7 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/SuppressedError | PASS | 22 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Symbol | PASS | 98 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/Duration | PASS | 540 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/Instant | PASS | 467 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/Now | PASS | 66 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainDate | PASS | 652 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainDateTime | PARTIAL | 772 | 1 | 0 | 0 | 0 | 0 | 99.9% |
| built-ins/Temporal/PlainMonthDay | PASS | 199 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainTime | PASS | 493 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainYearMonth | PASS | 509 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/ZonedDateTime | PASS | 901 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/toStringTag | PASS | 2 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ThrowTypeError | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/TypedArray | PASS | 1453 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/TypedArrayConstructors | PASS | 738 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Uint8Array | PASS | 70 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakMap | PASS | 141 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakRef | PASS | 29 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakSet | PASS | 85 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/decodeURI | BLOCKED | 54 | 0 | 0 | 1 | 0 | 0 | 100.0% |
| built-ins/decodeURIComponent | BLOCKED | 55 | 0 | 0 | 1 | 0 | 0 | 100.0% |
| built-ins/encodeURI | PASS | 31 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/encodeURIComponent | PASS | 31 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/eval | PASS | 10 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/global | PASS | 29 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isFinite | PASS | 15 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isNaN | PASS | 15 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseFloat | PASS | 54 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseInt | PASS | 55 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/undefined | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| harness | PASS | 116 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402 | PASS | 22 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Array | PASS | 2 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/BigInt | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Collator | PASS | 65 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Date | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/DateTimeFormat | PARTIAL | 243 | 2 | 0 | 0 | 0 | 0 | 99.2% |
| intl402/DisplayNames | PASS | 57 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/DurationFormat | PASS | 110 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/FallbackSymbol | PASS | 2 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Intl | PASS | 66 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/ListFormat | PASS | 81 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Locale | PASS | 190 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Number | PASS | 7 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/NumberFormat | PARTIAL | 250 | 1 | 0 | 0 | 0 | 0 | 99.6% |
| intl402/PluralRules | PASS | 53 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/RelativeTimeFormat | PASS | 80 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Segmenter | PASS | 79 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/String | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Temporal | PARTIAL | 2027 | 2 | 0 | 0 | 0 | 0 | 99.9% |
| intl402/TypedArray | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/arguments-object | PARTIAL | 262 | 1 | 0 | 0 | 0 | 0 | 99.6% |
| language/asi | PASS | 102 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/block-scope | PASS | 145 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/comments | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/computed-property-names | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/destructuring | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/directive-prologue | PARTIAL | 60 | 2 | 0 | 0 | 0 | 0 | 96.8% |
| language/eval-code | PASS | 347 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/export | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/expressions | PARTIAL | 11032 | 70 | 0 | 0 | 0 | 0 | 99.4% |
| language/function-code | PASS | 217 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/future-reserved-words | PASS | 55 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/global-code | PASS | 42 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifier-resolution | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifiers | PASS | 268 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/import | PASS | 129 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/keywords | PASS | 25 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/line-terminators | PASS | 41 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/literals | PASS | 536 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/module-code | PARTIAL | 598 | 1 | 0 | 0 | 0 | 0 | 99.8% |
| language/punctuators | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/reserved-words | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/rest-parameters | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/source-text | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/statementList | PASS | 80 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/statements | PARTIAL | 9335 | 12 | 0 | 0 | 0 | 0 | 99.9% |
| language/types | PASS | 113 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/white-space | PASS | 67 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging | PASS | 4 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Temporal | PASS | 2 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Uint8Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/built-ins | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/decorators | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/explicit-resource-management | PASS | 53 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/set-methods | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/sm | BLOCKED | 1399 | 2 | 0 | 5 | 0 | 0 | 99.9% |
| staging/source-phase-imports | PASS | 2 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/top-level-await | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |

## Failing Tests

110 individual failure paths and messages are recorded in [compat.json](compat.json) under `test262.failures`.

## Blocked Chunks

| Group | Kind | Files | Sample | Log |
|---|---|---:|---|---|
| built-ins/decodeURI | TIMEOUT | 1 | `test262/test/built-ins/decodeURI/S15.1.3.1_A2.5_T1.js` | `.compat-state-builtins-lower-de/logs/57de13dadc41eb5da8994c7bc1de8fe5c0d55dd6.log` |
| built-ins/decodeURIComponent | TIMEOUT | 1 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A2.5_T1.js` | `.compat-state-builtins-lower-de/logs/f3e05d6b74efcc2f559417cc3812a891e061afb3.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-2-of-8.js` | `.compat-state-staging/logs/c12868dac0d45e7f814a67b08cc457879cf8068e.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-3-of-8.js` | `.compat-state-staging/logs/ae0e2fc2d9fb48e84f4bc5e58b25aae6c374bd51.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-4-of-8.js` | `.compat-state-staging/logs/e81bb285d552634f96c813a5e6a8bca0e62ce4d8.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-7-of-8.js` | `.compat-state-staging/logs/e2313ebc3d771f59bbcd8e04fd6f18cc4043ac43.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-8-of-8.js` | `.compat-state-staging/logs/8d6a32feb99c0730be596e0685cb98ca220032c5.log` |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| built-ins/decodeURIComponent | TIMEOUT | 289.782s | 1 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A2.5_T1.js` |
| built-ins/decodeURI | TIMEOUT | 263.541s | 1 | `test262/test/built-ins/decodeURI/S15.1.3.1_A2.5_T1.js` |
| staging/sm | TIMEOUT | 260.716s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-4-of-8.js` |
| staging/sm | TIMEOUT | 259.237s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-7-of-8.js` |
| staging/sm | TIMEOUT | 258.716s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-3-of-8.js` |
| staging/sm | TIMEOUT | 258.231s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-2-of-8.js` |
| staging/sm | TIMEOUT | 250.670s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-8-of-8.js` |
| staging/sm | NORMAL | 226.380s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-5-of-8.js` |
| staging/sm | NORMAL | 211.302s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-1-of-8.js` |
| staging/sm | NORMAL | 183.867s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-6-of-8.js` |
| staging/sm | NORMAL | 160.066s | 1 | `test262/test/staging/sm/TypedArray/sort_large_countingsort.js` |
| staging/sm | NORMAL | 117.558s | 1 | `test262/test/staging/sm/TypedArray/element-setting-converts-using-ToNumber.js` |
| built-ins/decodeURI | NORMAL | 91.123s | 25 | `test262/test/built-ins/decodeURI/S15.1.3.1_A1.11_T2.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.12_T1.js`<br>...<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.8_T1.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.8_T2.js` |
| built-ins/decodeURIComponent | NORMAL | 90.699s | 25 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.11_T2.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.12_T1.js`<br>...<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.8_T1.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.8_T2.js` |
| intl402 | NORMAL | 88.305s | 22 | `test262/test/intl402/constructors-string-and-single-element-array.js`<br>`test262/test/intl402/constructors-taint-Object-prototype-2.js`<br>...<br>`test262/test/intl402/supportedLocalesOf-throws-if-element-not-string-or-object.js`<br>`test262/test/intl402/supportedLocalesOf-unicode-extensions-ignored.js` |
| language/literals | NORMAL | 82.516s | 25 | `test262/test/language/literals/regexp/S7.8.5_A1.1_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A1.1_T2.js`<br>...<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T2.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.3_T1.js` |
| built-ins/TypedArray | NORMAL | 78.595s | 3 | `test262/test/built-ins/TypedArray/prototype/copyWithin/coerced-values-end-detached-prototype.js`<br>`test262/test/built-ins/TypedArray/prototype/copyWithin/coerced-values-end-detached.js`<br>`test262/test/built-ins/TypedArray/prototype/copyWithin/coerced-values-end.js` |
| intl402/DateTimeFormat | NORMAL | 67.416s | 12 | `test262/test/intl402/DateTimeFormat/prototype/formatRangeToParts/temporal-plainmonthday-formatting-timezonename.js`<br>`test262/test/intl402/DateTimeFormat/prototype/formatRangeToParts/temporal-plaintime-formatting-timezonename.js`<br>...<br>`test262/test/intl402/DateTimeFormat/prototype/formatToParts/dangi-calendar-dates.js`<br>`test262/test/intl402/DateTimeFormat/prototype/formatToParts/date-constructor-not-called.js` |
| staging/sm | NORMAL | 63.656s | 1 | `test262/test/staging/sm/Array/toSpliced-dense.js` |
| annexB/built-ins/RegExp | NORMAL | 61.941s | 25 | `test262/test/annexB/built-ins/RegExp/RegExp-control-escape-russian-letter.js`<br>`test262/test/annexB/built-ins/RegExp/RegExp-decimal-escape-class-range.js`<br>...<br>`test262/test/annexB/built-ins/RegExp/legacy-accessors/lastParen/this-cross-realm-constructor.js`<br>`test262/test/annexB/built-ins/RegExp/legacy-accessors/lastParen/this-not-regexp-constructor.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are executed as ES modules (always strict, import/export processed).
- Async tests execute with the $DONE host completion hook.
- Unflagged scripts run in both sloppy and strict modes; raw scripts run unchanged once.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

