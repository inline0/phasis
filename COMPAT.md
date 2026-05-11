# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-05-11T19:38:02+00:00`
- Chunk size: `25`
- Timeout: `300s`
- Jobs: `4`
- Groups: `159`
- Test files: `50506`
- Git: `main` @ `97a0e32` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 49694 | 192 | 148 | 13 | 534 | 0 | 49886 | 50047 | 50581 | 99.6% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | PARTIAL | 1032 | 40 | 7 | 0 | 0 | 0 | 96.3% |
| built-ins | INCOMPLETE | 22108 | 69 | 91 | 2 | 509 | 0 | 99.7% |
| harness | PASS | 114 | 0 | 2 | 0 | 0 | 0 | 100.0% |
| intl402 | PARTIAL | 1555 | 11 | 0 | 0 | 0 | 0 | 99.3% |
| language | PARTIAL | 23315 | 62 | 7 | 0 | 0 | 0 | 99.7% |
| staging | INCOMPLETE | 1570 | 10 | 41 | 11 | 25 | 0 | 99.4% |

## Group Coverage

| Group | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB/built-ins/Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Date | PASS | 24 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Function | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Object | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/RegExp | PASS | 55 | 0 | 7 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/String | PASS | 111 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/TypedArrayConstructors | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/escape | PASS | 16 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/unescape | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/comments | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/eval-code | PARTIAL | 429 | 40 | 0 | 0 | 0 | 0 | 91.5% |
| annexB/language/expressions | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/function-code | PASS | 159 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/global-code | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/literals | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/statements | PASS | 22 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AbstractModuleSource | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Array | PARTIAL | 3061 | 4 | 10 | 0 | 0 | 0 | 99.9% |
| built-ins/ArrayBuffer | PARTIAL | 191 | 1 | 0 | 0 | 0 | 0 | 99.5% |
| built-ins/ArrayIteratorPrototype | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncDisposableStack | PARTIAL | 51 | 1 | 0 | 0 | 0 | 0 | 98.1% |
| built-ins/AsyncFromSyncIteratorPrototype | PASS | 38 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncFunction | PASS | 18 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorFunction | PASS | 22 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorPrototype | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncIteratorPrototype | PASS | 10 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Atomics | PASS | 322 | 0 | 54 | 0 | 0 | 0 | 100.0% |
| built-ins/BigInt | PASS | 75 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Boolean | PARTIAL | 50 | 1 | 0 | 0 | 0 | 0 | 98.0% |
| built-ins/DataView | PARTIAL | 548 | 2 | 0 | 0 | 0 | 0 | 99.6% |
| built-ins/Date | PARTIAL | 591 | 3 | 0 | 0 | 0 | 0 | 99.5% |
| built-ins/DisposableStack | PARTIAL | 51 | 1 | 0 | 0 | 0 | 0 | 98.1% |
| built-ins/Error | PARTIAL | 52 | 1 | 0 | 0 | 0 | 0 | 98.1% |
| built-ins/FinalizationRegistry | PARTIAL | 46 | 1 | 0 | 0 | 0 | 0 | 97.9% |
| built-ins/Function | PARTIAL | 496 | 8 | 5 | 0 | 0 | 0 | 98.4% |
| built-ins/GeneratorFunction | PASS | 22 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/GeneratorPrototype | PASS | 61 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Infinity | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Iterator | PARTIAL | 430 | 1 | 0 | 0 | 0 | 0 | 99.8% |
| built-ins/JSON | PASS | 164 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/Map | PARTIAL | 170 | 1 | 0 | 0 | 0 | 0 | 99.4% |
| built-ins/MapIteratorPrototype | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Math | PASS | 327 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/NaN | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/NativeErrors | PARTIAL | 131 | 8 | 0 | 0 | 0 | 0 | 94.2% |
| built-ins/Number | PARTIAL | 334 | 1 | 0 | 0 | 0 | 0 | 99.7% |
| built-ins/Object | PARTIAL | 3409 | 1 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Promise | PARTIAL | 630 | 1 | 0 | 0 | 0 | 0 | 99.8% |
| built-ins/Proxy | PARTIAL | 304 | 1 | 6 | 0 | 0 | 0 | 99.7% |
| built-ins/Reflect | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp | PARTIAL | 486 | 2 | 0 | 0 | 0 | 0 | 99.6% |
| built-ins/RegExp/CharacterClassEscapes | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/Symbol.species | PASS | 4 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/dotall | PASS | 4 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/escape | PASS | 20 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/lookBehind | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/match-indices | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/named-groups | PASS | 36 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/property-escapes | PASS | 143 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/property-escapes/generated | PENDING | 0 | 0 | 0 | 0 | 459 | 0 | n/a |
| built-ins/RegExp/prototype | PASS | 477 | 0 | 10 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/regexp-modifiers | PASS | 70 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/unicodeSets | PASS | 113 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExpStringIteratorPrototype | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Set | PARTIAL | 380 | 1 | 0 | 0 | 0 | 0 | 99.7% |
| built-ins/SetIteratorPrototype | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ShadowRealm | PARTIAL | 61 | 2 | 1 | 0 | 0 | 0 | 96.8% |
| built-ins/SharedArrayBuffer | PARTIAL | 103 | 1 | 0 | 0 | 0 | 0 | 99.0% |
| built-ins/String | PARTIAL | 1209 | 3 | 0 | 0 | 0 | 0 | 99.8% |
| built-ins/StringIteratorPrototype | PASS | 7 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Symbol | PARTIAL | 91 | 1 | 2 | 0 | 0 | 0 | 98.9% |
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
| built-ins/TypedArray | PASS | 1426 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/TypedArrayConstructors | PARTIAL | 718 | 18 | 0 | 0 | 0 | 0 | 97.6% |
| built-ins/Uint8Array | PASS | 64 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakMap | PARTIAL | 101 | 1 | 0 | 0 | 0 | 0 | 99.0% |
| built-ins/WeakRef | PARTIAL | 28 | 1 | 0 | 0 | 0 | 0 | 96.6% |
| built-ins/WeakSet | PARTIAL | 84 | 1 | 0 | 0 | 0 | 0 | 98.8% |
| built-ins/decodeURI | INCOMPLETE | 54 | 0 | 0 | 1 | 25 | 0 | 100.0% |
| built-ins/decodeURIComponent | INCOMPLETE | 55 | 0 | 0 | 1 | 25 | 0 | 100.0% |
| built-ins/encodeURI | PASS | 31 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/encodeURIComponent | PASS | 31 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/eval | PASS | 10 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/global | PARTIAL | 28 | 1 | 0 | 0 | 0 | 0 | 96.6% |
| built-ins/isFinite | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isNaN | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseFloat | PASS | 59 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseInt | PASS | 60 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/undefined | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| harness | PASS | 114 | 0 | 2 | 0 | 0 | 0 | 100.0% |
| intl402 | PASS | 22 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Array | PASS | 2 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/BigInt | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Collator | PARTIAL | 61 | 1 | 0 | 0 | 0 | 0 | 98.4% |
| intl402/Date | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/DateTimeFormat | PARTIAL | 187 | 1 | 0 | 0 | 0 | 0 | 99.5% |
| intl402/DisplayNames | PARTIAL | 56 | 1 | 0 | 0 | 0 | 0 | 98.2% |
| intl402/DurationFormat | PASS | 110 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Intl | PASS | 67 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/ListFormat | PARTIAL | 80 | 1 | 0 | 0 | 0 | 0 | 98.8% |
| intl402/Locale | PARTIAL | 146 | 1 | 0 | 0 | 0 | 0 | 99.3% |
| intl402/Number | PASS | 7 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/NumberFormat | PARTIAL | 251 | 1 | 0 | 0 | 0 | 0 | 99.6% |
| intl402/PluralRules | PARTIAL | 49 | 1 | 0 | 0 | 0 | 0 | 98.0% |
| intl402/RelativeTimeFormat | PARTIAL | 78 | 1 | 0 | 0 | 0 | 0 | 98.7% |
| intl402/Segmenter | PARTIAL | 76 | 2 | 0 | 0 | 0 | 0 | 97.4% |
| intl402/String | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Temporal | PARTIAL | 322 | 1 | 0 | 0 | 0 | 0 | 99.7% |
| intl402/TypedArray | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/arguments-object | PASS | 263 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/asi | PASS | 102 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/block-scope | PASS | 145 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/comments | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/computed-property-names | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/destructuring | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/directive-prologue | PASS | 62 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/eval-code | PARTIAL | 338 | 8 | 1 | 0 | 0 | 0 | 97.7% |
| language/export | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/expressions | PARTIAL | 10984 | 35 | 4 | 0 | 0 | 0 | 99.7% |
| language/function-code | PASS | 217 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/future-reserved-words | PASS | 55 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/global-code | PASS | 42 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifier-resolution | PARTIAL | 13 | 1 | 0 | 0 | 0 | 0 | 92.9% |
| language/identifiers | PASS | 260 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/import | PASS | 85 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/keywords | PASS | 25 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/line-terminators | PASS | 41 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/literals | PASS | 534 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/module-code | PASS | 583 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/punctuators | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/reserved-words | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/rest-parameters | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/source-text | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/statementList | PASS | 80 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/statements | PARTIAL | 9136 | 18 | 0 | 0 | 0 | 0 | 99.8% |
| language/types | PASS | 111 | 0 | 2 | 0 | 0 | 0 | 100.0% |
| language/white-space | PASS | 67 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Intl402 | PARTIAL | 47 | 2 | 0 | 0 | 0 | 0 | 95.9% |
| staging/Temporal | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Uint8Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/built-ins | PASS | 7 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/decorators | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/explicit-resource-management | PASS | 54 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/set-methods | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/sm | INCOMPLETE | 1368 | 8 | 41 | 11 | 25 | 0 | 99.4% |
| staging/source-phase-imports | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/upsert | PASS | 71 | 0 | 0 | 0 | 0 | 0 | 100.0% |

## Blocked Chunks

| Group | Kind | Files | Sample | Log |
|---|---|---:|---|---|
| built-ins/decodeURI | CRASH | 1 | `test262/test/built-ins/decodeURI/S15.1.3.1_A2.5_T1.js` | `.compat-state-builtins-lower-de/logs/6687624bd5fb87a6a4ff167cd35af4b69d14f50d.log` |
| built-ins/decodeURIComponent | CRASH | 1 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A2.5_T1.js` | `.compat-state-builtins-lower-de/logs/5a3e033d7be8e84f9d36d0440da488ff5b100e26.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/TypedArray/element-setting-converts-using-ToNumber.js` | `.compat-state-staging/logs/655e0e3f8baf6b2fbdfb85776837eb8131d920b4.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/TypedArray/sort_large_countingsort.js` | `.compat-state-staging/logs/f1232104e4d338c7c76a723ace84621f84895ca1.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/regress/regress-1507322-deep-weakmap.js` | `.compat-state-staging/logs/59f611b1d8f6087de2cf8dd8fc9a3ce8ba03416e.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-4-of-8.js` | `.compat-state-staging/logs/dd2d25d5cc94da8b81ed7a27013584a93402ab8e.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-1-of-8.js` | `.compat-state-staging/logs/f72507a466dd0056e8a719c4d2fad7b060dc5a34.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-7-of-8.js` | `.compat-state-staging/logs/931d7d3921a965471071a964166aad45b1d595f4.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-8-of-8.js` | `.compat-state-staging/logs/af18b1bdadaf3999fb864b5776bc2b53ed5c7dcb.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-5-of-8.js` | `.compat-state-staging/logs/c58e8146c6f3a001cf0781adbce015d2d9682c2d.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-6-of-8.js` | `.compat-state-staging/logs/fc434724163390adbcf5051f147ab7c4fd54a93b.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-2-of-8.js` | `.compat-state-staging/logs/42df9b8c4fc0d995dcf4cb51158e13f63ab55e1e.log` |
| staging/sm | TIMEOUT | 1 | `test262/test/staging/sm/Date/dst-offset-caching-3-of-8.js` | `.compat-state-staging/logs/f77d96875630465a6823161d465877fc4c60f9ae.log` |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| staging/sm | TIMEOUT | 242.403s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-5-of-8.js` |
| staging/sm | TIMEOUT | 241.540s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-1-of-8.js` |
| staging/sm | TIMEOUT | 240.921s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-6-of-8.js` |
| staging/sm | TIMEOUT | 240.491s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-3-of-8.js` |
| staging/sm | TIMEOUT | 240.329s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-4-of-8.js` |
| staging/sm | TIMEOUT | 181.818s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-7-of-8.js` |
| staging/sm | TIMEOUT | 181.410s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-2-of-8.js` |
| staging/sm | TIMEOUT | 180.529s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-8-of-8.js` |
| staging/sm | TIMEOUT | 125.356s | 1 | `test262/test/staging/sm/TypedArray/sort_large_countingsort.js` |
| staging/sm | TIMEOUT | 95.735s | 1 | `test262/test/staging/sm/TypedArray/element-setting-converts-using-ToNumber.js` |
| staging/sm | TIMEOUT | 95.072s | 1 | `test262/test/staging/sm/regress/regress-1507322-deep-weakmap.js` |
| language/expressions | NORMAL | 72.574s | 25 | `test262/test/language/expressions/call/spread-sngl-iter.js`<br>`test262/test/language/expressions/call/spread-sngl-literal.js`<br>...<br>`test262/test/language/expressions/class/accessor-name-inst/literal-numeric-zero.js`<br>`test262/test/language/expressions/class/accessor-name-inst/literal-string-char-escape.js` |
| built-ins/decodeURIComponent | CRASH | 66.225s | 1 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A2.5_T1.js` |
| built-ins/decodeURI | CRASH | 66.217s | 1 | `test262/test/built-ins/decodeURI/S15.1.3.1_A2.5_T1.js` |
| staging/sm | NORMAL | 60.946s | 25 | `test262/test/staging/sm/Symbol/valueOf.js`<br>`test262/test/staging/sm/Symbol/well-known.js`<br>...<br>`test262/test/staging/sm/TypedArray/constructor-typedarray-species-other-global.js`<br>`test262/test/staging/sm/TypedArray/constructor-undefined-args.js` |
| built-ins/decodeURIComponent | NORMAL | 60.710s | 12 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.11_T2.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.12_T1.js`<br>...<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.15_T1.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.15_T2.js` |
| built-ins/decodeURI | NORMAL | 60.630s | 12 | `test262/test/built-ins/decodeURI/S15.1.3.1_A1.11_T2.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.12_T1.js`<br>...<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.15_T1.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.15_T2.js` |
| staging/sm | NORMAL | 53.274s | 25 | `test262/test/staging/sm/expressions/destructuring-array-done.js`<br>`test262/test/staging/sm/expressions/destructuring-array-lexical.js`<br>...<br>`test262/test/staging/sm/expressions/short-circuit-compound-assignment-property-key-evaluation.js`<br>`test262/test/staging/sm/expressions/short-circuit-compound-assignment-tdz.js` |
| language/literals | NORMAL | 51.772s | 25 | `test262/test/language/literals/regexp/S7.8.5_A1.1_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A1.1_T2.js`<br>...<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T2.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.3_T1.js` |
| intl402 | NORMAL | 48.312s | 22 | `test262/test/intl402/constructors-string-and-single-element-array.js`<br>`test262/test/intl402/constructors-taint-Object-prototype-2.js`<br>...<br>`test262/test/intl402/supportedLocalesOf-throws-if-element-not-string-or-object.js`<br>`test262/test/intl402/supportedLocalesOf-unicode-extensions-ignored.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are executed as ES modules (always strict, import/export processed).
- Tests flagged as async are skipped by the current runner.
- Mixed-mode tests currently run only in sloppy mode unless onlyStrict or noStrict is set.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

