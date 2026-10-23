# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-10-23T07:08:16+00:00`
- Chunk size: `25`
- Timeout: `300s`
- Jobs: `4`
- Groups: `161`
- Test files: `53616`
- Git: `main` @ `2a31848f` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 52069 | 1547 | 0 | 0 | 0 | 0 | 53616 | 53616 | 53616 | 97.1% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | PARTIAL | 1079 | 7 | 0 | 0 | 0 | 0 | 99.4% |
| built-ins | PARTIAL | 23349 | 474 | 0 | 0 | 0 | 0 | 98.0% |
| harness | PASS | 116 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402 | PARTIAL | 2410 | 972 | 0 | 0 | 0 | 0 | 71.3% |
| language | PARTIAL | 23636 | 90 | 0 | 0 | 0 | 0 | 99.6% |
| staging | PARTIAL | 1479 | 4 | 0 | 0 | 0 | 0 | 99.7% |

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
| annexB/language/expressions | PARTIAL | 19 | 7 | 0 | 0 | 0 | 0 | 73.1% |
| annexB/language/function-code | PASS | 159 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/global-code | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/literals | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/statements | PASS | 22 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AbstractModuleSource | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AggregateError | PASS | 25 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Array | PARTIAL | 3082 | 1 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ArrayBuffer | PARTIAL | 194 | 27 | 0 | 0 | 0 | 0 | 87.8% |
| built-ins/ArrayIteratorPrototype | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncDisposableStack | PARTIAL | 97 | 7 | 0 | 0 | 0 | 0 | 93.3% |
| built-ins/AsyncFromSyncIteratorPrototype | PASS | 38 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncFunction | PASS | 18 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorFunction | PASS | 23 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorPrototype | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncIteratorPrototype | PARTIAL | 11 | 2 | 0 | 0 | 0 | 0 | 84.6% |
| built-ins/Atomics | PARTIAL | 374 | 15 | 0 | 0 | 0 | 0 | 96.1% |
| built-ins/BigInt | PARTIAL | 76 | 1 | 0 | 0 | 0 | 0 | 98.7% |
| built-ins/Boolean | PASS | 51 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/DataView | PARTIAL | 550 | 11 | 0 | 0 | 0 | 0 | 98.0% |
| built-ins/Date | PASS | 594 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/DisposableStack | PARTIAL | 91 | 2 | 0 | 0 | 0 | 0 | 97.8% |
| built-ins/Error | PASS | 93 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/FinalizationRegistry | PASS | 47 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Function | PASS | 509 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/GeneratorFunction | PASS | 23 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/GeneratorPrototype | PASS | 61 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Infinity | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Iterator | PARTIAL | 444 | 210 | 0 | 0 | 0 | 0 | 67.9% |
| built-ins/JSON | PARTIAL | 165 | 1 | 0 | 0 | 0 | 0 | 99.4% |
| built-ins/Map | PARTIAL | 203 | 1 | 0 | 0 | 0 | 0 | 99.5% |
| built-ins/MapIteratorPrototype | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Math | PASS | 327 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/NaN | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/NativeErrors | PASS | 94 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Number | PASS | 340 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Object | PASS | 3411 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Promise | PARTIAL | 643 | 89 | 0 | 0 | 0 | 0 | 87.8% |
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
| built-ins/RegExp/property-escapes/generated | PARTIAL | 465 | 4 | 0 | 0 | 0 | 0 | 99.1% |
| built-ins/RegExp/prototype | PASS | 487 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/regexp-modifiers | PASS | 70 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/unicodeSets | PARTIAL | 113 | 1 | 0 | 0 | 0 | 0 | 99.1% |
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
| built-ins/Temporal/Duration | PARTIAL | 526 | 14 | 0 | 0 | 0 | 0 | 97.4% |
| built-ins/Temporal/Instant | PARTIAL | 460 | 7 | 0 | 0 | 0 | 0 | 98.5% |
| built-ins/Temporal/Now | PASS | 66 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainDate | PARTIAL | 640 | 12 | 0 | 0 | 0 | 0 | 98.2% |
| built-ins/Temporal/PlainDateTime | PARTIAL | 764 | 9 | 0 | 0 | 0 | 0 | 98.8% |
| built-ins/Temporal/PlainMonthDay | PARTIAL | 192 | 7 | 0 | 0 | 0 | 0 | 96.5% |
| built-ins/Temporal/PlainTime | PARTIAL | 483 | 10 | 0 | 0 | 0 | 0 | 98.0% |
| built-ins/Temporal/PlainYearMonth | PARTIAL | 496 | 13 | 0 | 0 | 0 | 0 | 97.4% |
| built-ins/Temporal/ZonedDateTime | PARTIAL | 891 | 10 | 0 | 0 | 0 | 0 | 98.9% |
| built-ins/Temporal/toStringTag | PASS | 2 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ThrowTypeError | PARTIAL | 13 | 1 | 0 | 0 | 0 | 0 | 92.9% |
| built-ins/TypedArray | PARTIAL | 1441 | 12 | 0 | 0 | 0 | 0 | 99.2% |
| built-ins/TypedArrayConstructors | PARTIAL | 736 | 2 | 0 | 0 | 0 | 0 | 99.7% |
| built-ins/Uint8Array | PARTIAL | 65 | 5 | 0 | 0 | 0 | 0 | 92.9% |
| built-ins/WeakMap | PASS | 141 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakRef | PASS | 29 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakSet | PASS | 85 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/decodeURI | PASS | 55 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/decodeURIComponent | PASS | 56 | 0 | 0 | 0 | 0 | 0 | 100.0% |
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
| intl402/Collator | PARTIAL | 62 | 3 | 0 | 0 | 0 | 0 | 95.4% |
| intl402/Date | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/DateTimeFormat | PARTIAL | 207 | 38 | 0 | 0 | 0 | 0 | 84.5% |
| intl402/DisplayNames | PASS | 57 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/DurationFormat | PARTIAL | 109 | 1 | 0 | 0 | 0 | 0 | 99.1% |
| intl402/FallbackSymbol | FAIL | 0 | 2 | 0 | 0 | 0 | 0 | 0.0% |
| intl402/Intl | PARTIAL | 64 | 2 | 0 | 0 | 0 | 0 | 97.0% |
| intl402/ListFormat | PASS | 81 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Locale | PARTIAL | 150 | 40 | 0 | 0 | 0 | 0 | 78.9% |
| intl402/Number | PASS | 7 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/NumberFormat | PARTIAL | 246 | 5 | 0 | 0 | 0 | 0 | 98.0% |
| intl402/PluralRules | PARTIAL | 45 | 8 | 0 | 0 | 0 | 0 | 84.9% |
| intl402/RelativeTimeFormat | PARTIAL | 79 | 1 | 0 | 0 | 0 | 0 | 98.8% |
| intl402/Segmenter | PASS | 79 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/String | PARTIAL | 17 | 2 | 0 | 0 | 0 | 0 | 89.5% |
| intl402/Temporal | PARTIAL | 1159 | 870 | 0 | 0 | 0 | 0 | 57.1% |
| intl402/TypedArray | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/arguments-object | PASS | 263 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/asi | PASS | 102 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/block-scope | PASS | 145 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/comments | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/computed-property-names | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/destructuring | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/directive-prologue | PASS | 62 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/eval-code | PASS | 347 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/export | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/expressions | PARTIAL | 11099 | 3 | 0 | 0 | 0 | 0 | 100.0% |
| language/function-code | PASS | 217 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/future-reserved-words | PASS | 55 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/global-code | PASS | 42 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifier-resolution | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifiers | PASS | 268 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/import | PARTIAL | 113 | 16 | 0 | 0 | 0 | 0 | 87.6% |
| language/keywords | PASS | 25 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/line-terminators | PASS | 41 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/literals | PASS | 536 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/module-code | PARTIAL | 589 | 10 | 0 | 0 | 0 | 0 | 98.3% |
| language/punctuators | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/reserved-words | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/rest-parameters | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/source-text | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/statementList | PASS | 80 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/statements | PARTIAL | 9286 | 61 | 0 | 0 | 0 | 0 | 99.3% |
| language/types | PASS | 113 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/white-space | PASS | 67 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging | PASS | 4 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Temporal | PASS | 2 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Uint8Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/built-ins | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/decorators | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/explicit-resource-management | PASS | 53 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/set-methods | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/sm | PARTIAL | 1403 | 3 | 0 | 0 | 0 | 0 | 99.8% |
| staging/source-phase-imports | PARTIAL | 1 | 1 | 0 | 0 | 0 | 0 | 50.0% |
| staging/top-level-await | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |

## Failing Tests

1547 individual failure paths and messages are recorded in [compat.json](compat.json) under `test262.failures`.

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| staging/sm | NORMAL | 63.375s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-4-of-8.js` |
| staging/sm | NORMAL | 62.594s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-7-of-8.js` |
| staging/sm | NORMAL | 62.293s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-8-of-8.js` |
| staging/sm | NORMAL | 61.862s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-2-of-8.js` |
| staging/sm | NORMAL | 61.481s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-3-of-8.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 59.988s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Initial_Punctuation.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Letter.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Letter_Number.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Line_Separator.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Lowercase_Letter.js` |
| staging/sm | NORMAL | 56.128s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-5-of-8.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 54.205s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Hebrew.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Hiragana.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Imperial_Aramaic.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Inherited.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Inscriptional_Pahlavi.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 52.824s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Mahajani.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Makasar.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Malayalam.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Mandaic.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Manichaean.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 52.719s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Kawi.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Kayah_Li.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Kharoshthi.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Khitan_Small_Script.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Khmer.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 51.970s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Unknown.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Vai.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Vithkuqi.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Wancho.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Warang_Citi.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 51.493s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Thaana.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Thai.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Tibetan.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Tifinagh.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Tirhuta.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 51.126s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Marchen.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Masaram_Gondi.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Medefaidrin.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Meetei_Mayek.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Mende_Kikakui.js` |
| staging/sm | NORMAL | 50.821s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-1-of-8.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 50.749s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Saurashtra.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Sharada.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Shavian.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Siddham.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Sidetic.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 50.368s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Osmanya.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Pahawh_Hmong.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Palmyrene.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Pau_Cin_Hau.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Phags_Pa.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 50.324s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Takri.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Tamil.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Tangsa.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Tangut.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Telugu.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 50.291s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Old_Italic.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Old_North_Arabian.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Old_Permic.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Old_Persian.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Old_Sogdian.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 50.226s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Greek.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Gujarati.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Gunjala_Gondi.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Gurmukhi.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Gurung_Khema.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 50.190s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Mro.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Multani.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Myanmar.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Nabataean.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Nag_Mundari.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are executed as ES modules (always strict, import/export processed).
- Tests flagged as async are skipped by the current runner.
- Mixed-mode tests currently run only in sloppy mode unless onlyStrict or noStrict is set.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

