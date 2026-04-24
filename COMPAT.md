# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-04-24T23:01:18+00:00`
- Chunk size: `250`
- Timeout: `300s`
- Jobs: `4`
- Groups: `158`
- Test files: `50506`
- Git: `main` @ `77a3c1e` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 14861 | 1188 | 2 | 0 | 63783 | 667 | 16049 | 16051 | 80501 | 92.6% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | RUNNING | 1046 | 8 | 0 | 0 | 954 | 25 | 99.2% |
| built-ins | RUNNING | 10886 | 303 | 2 | 0 | 27748 | 467 | 97.3% |
| harness | INCOMPLETE | 114 | 2 | 0 | 0 | 116 | 0 | 98.3% |
| intl402 | INCOMPLETE | 635 | 844 | 0 | 0 | 1579 | 0 | 42.9% |
| language | RUNNING | 2133 | 3 | 0 | 0 | 30327 | 75 | 99.9% |
| staging | RUNNING | 47 | 28 | 0 | 0 | 3059 | 100 | 62.7% |

## Group Coverage

| Group | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB/built-ins/Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Date | PASS | 24 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Function | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Object | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/RegExp | RUNNING | 33 | 4 | 0 | 0 | 62 | 25 | 89.2% |
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
| built-ins/AbstractModuleSource | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| built-ins/Array | INCOMPLETE | 1693 | 32 | 0 | 0 | 4425 | 0 | 98.1% |
| built-ins/ArrayBuffer | INCOMPLETE | 149 | 1 | 0 | 0 | 234 | 0 | 99.3% |
| built-ins/ArrayIteratorPrototype | INCOMPLETE | 25 | 0 | 0 | 0 | 29 | 0 | 100.0% |
| built-ins/AsyncDisposableStack | PENDING | 0 | 0 | 0 | 0 | 104 | 0 | n/a |
| built-ins/AsyncFromSyncIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 76 | 0 | n/a |
| built-ins/AsyncFunction | PENDING | 0 | 0 | 0 | 0 | 18 | 0 | n/a |
| built-ins/AsyncGeneratorFunction | PENDING | 0 | 0 | 0 | 0 | 23 | 0 | n/a |
| built-ins/AsyncGeneratorPrototype | INCOMPLETE | 22 | 3 | 0 | 0 | 71 | 0 | 88.0% |
| built-ins/AsyncIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 10 | 0 | n/a |
| built-ins/Atomics | RUNNING | 37 | 11 | 2 | 0 | 652 | 50 | 77.1% |
| built-ins/BigInt | INCOMPLETE | 75 | 0 | 0 | 0 | 75 | 0 | 100.0% |
| built-ins/Boolean | INCOMPLETE | 51 | 0 | 0 | 0 | 51 | 0 | 100.0% |
| built-ins/DataView | INCOMPLETE | 547 | 3 | 0 | 0 | 550 | 0 | 99.5% |
| built-ins/Date | INCOMPLETE | 594 | 0 | 0 | 0 | 594 | 0 | 100.0% |
| built-ins/DisposableStack | INCOMPLETE | 52 | 0 | 0 | 0 | 52 | 0 | 100.0% |
| built-ins/Error | INCOMPLETE | 53 | 0 | 0 | 0 | 53 | 0 | 100.0% |
| built-ins/FinalizationRegistry | PENDING | 0 | 0 | 0 | 0 | 47 | 0 | n/a |
| built-ins/Function | PENDING | 0 | 0 | 0 | 0 | 509 | 0 | n/a |
| built-ins/GeneratorFunction | PARTIAL | 22 | 1 | 0 | 0 | 0 | 0 | 95.7% |
| built-ins/GeneratorPrototype | INCOMPLETE | 61 | 0 | 0 | 0 | 61 | 0 | 100.0% |
| built-ins/Infinity | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Iterator | INCOMPLETE | 431 | 0 | 0 | 0 | 431 | 0 | 100.0% |
| built-ins/JSON | INCOMPLETE | 164 | 1 | 0 | 0 | 165 | 0 | 99.4% |
| built-ins/Map | PENDING | 0 | 0 | 0 | 0 | 171 | 0 | n/a |
| built-ins/MapIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| built-ins/Math | PENDING | 0 | 0 | 0 | 0 | 327 | 0 | n/a |
| built-ins/NaN | PENDING | 0 | 0 | 0 | 0 | 6 | 0 | n/a |
| built-ins/NativeErrors | PENDING | 0 | 0 | 0 | 0 | 139 | 0 | n/a |
| built-ins/Number | PENDING | 0 | 0 | 0 | 0 | 335 | 0 | n/a |
| built-ins/Object | RUNNING | 1250 | 0 | 0 | 0 | 5495 | 75 | 100.0% |
| built-ins/Promise | PENDING | 0 | 0 | 0 | 0 | 631 | 0 | n/a |
| built-ins/Proxy | PENDING | 0 | 0 | 0 | 0 | 311 | 0 | n/a |
| built-ins/Reflect | INCOMPLETE | 153 | 0 | 0 | 0 | 153 | 0 | 100.0% |
| built-ins/RegExp | INCOMPLETE | 469 | 19 | 0 | 0 | 488 | 0 | 96.1% |
| built-ins/RegExp/CharacterClassEscapes | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| built-ins/RegExp/Symbol.species | PASS | 4 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/dotall | FAIL | 0 | 4 | 0 | 0 | 0 | 0 | 0.0% |
| built-ins/RegExp/escape | PASS | 20 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/lookBehind | PARTIAL | 2 | 15 | 0 | 0 | 0 | 0 | 11.8% |
| built-ins/RegExp/match-indices | PARTIAL | 1 | 13 | 0 | 0 | 0 | 0 | 7.1% |
| built-ins/RegExp/named-groups | INCOMPLETE | 19 | 17 | 0 | 0 | 36 | 0 | 52.8% |
| built-ins/RegExp/property-escapes | RUNNING | 0 | 75 | 0 | 0 | 1029 | 100 | 0.0% |
| built-ins/RegExp/prototype | PENDING | 0 | 0 | 0 | 0 | 487 | 0 | n/a |
| built-ins/RegExp/regexp-modifiers | INCOMPLETE | 55 | 15 | 0 | 0 | 70 | 0 | 78.6% |
| built-ins/RegExp/unicodeSets | INCOMPLETE | 38 | 75 | 0 | 0 | 113 | 0 | 33.6% |
| built-ins/RegExpStringIteratorPrototype | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Set | PENDING | 0 | 0 | 0 | 0 | 381 | 0 | n/a |
| built-ins/SetIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| built-ins/ShadowRealm | PENDING | 0 | 0 | 0 | 0 | 64 | 0 | n/a |
| built-ins/SharedArrayBuffer | PENDING | 0 | 0 | 0 | 0 | 104 | 0 | n/a |
| built-ins/String | PENDING | 0 | 0 | 0 | 0 | 1212 | 0 | n/a |
| built-ins/StringIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| built-ins/Symbol | PENDING | 0 | 0 | 0 | 0 | 94 | 0 | n/a |
| built-ins/Temporal | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/Duration | INCOMPLETE | 473 | 0 | 0 | 0 | 473 | 0 | 100.0% |
| built-ins/Temporal/Instant | INCOMPLETE | 434 | 0 | 0 | 0 | 434 | 0 | 100.0% |
| built-ins/Temporal/Now | INCOMPLETE | 66 | 0 | 0 | 0 | 66 | 0 | 100.0% |
| built-ins/Temporal/PlainDate | RUNNING | 550 | 0 | 0 | 0 | 592 | 42 | 100.0% |
| built-ins/Temporal/PlainDateTime | PENDING | 0 | 0 | 0 | 0 | 684 | 0 | n/a |
| built-ins/Temporal/PlainMonthDay | INCOMPLETE | 184 | 0 | 0 | 0 | 184 | 0 | 100.0% |
| built-ins/Temporal/PlainTime | INCOMPLETE | 457 | 0 | 0 | 0 | 457 | 0 | 100.0% |
| built-ins/Temporal/PlainYearMonth | INCOMPLETE | 465 | 0 | 0 | 0 | 465 | 0 | 100.0% |
| built-ins/Temporal/ZonedDateTime | INCOMPLETE | 805 | 0 | 0 | 0 | 805 | 0 | 100.0% |
| built-ins/Temporal/toStringTag | PASS | 2 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ThrowTypeError | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/TypedArray | RUNNING | 760 | 15 | 0 | 0 | 1977 | 100 | 98.1% |
| built-ins/TypedArrayConstructors | INCOMPLETE | 447 | 3 | 0 | 0 | 1022 | 0 | 99.3% |
| built-ins/Uint8Array | PENDING | 0 | 0 | 0 | 0 | 64 | 0 | n/a |
| built-ins/WeakMap | INCOMPLETE | 102 | 0 | 0 | 0 | 102 | 0 | 100.0% |
| built-ins/WeakRef | INCOMPLETE | 29 | 0 | 0 | 0 | 29 | 0 | 100.0% |
| built-ins/WeakSet | INCOMPLETE | 85 | 0 | 0 | 0 | 85 | 0 | 100.0% |
| built-ins/decodeURI | RUNNING | 0 | 0 | 0 | 0 | 60 | 50 | n/a |
| built-ins/decodeURIComponent | RUNNING | 0 | 0 | 0 | 0 | 62 | 50 | n/a |
| built-ins/encodeURI | PENDING | 0 | 0 | 0 | 0 | 31 | 0 | n/a |
| built-ins/encodeURIComponent | PENDING | 0 | 0 | 0 | 0 | 31 | 0 | n/a |
| built-ins/eval | PENDING | 0 | 0 | 0 | 0 | 10 | 0 | n/a |
| built-ins/global | PENDING | 0 | 0 | 0 | 0 | 29 | 0 | n/a |
| built-ins/isFinite | PENDING | 0 | 0 | 0 | 0 | 17 | 0 | n/a |
| built-ins/isNaN | PENDING | 0 | 0 | 0 | 0 | 17 | 0 | n/a |
| built-ins/parseFloat | PENDING | 0 | 0 | 0 | 0 | 59 | 0 | n/a |
| built-ins/parseInt | PENDING | 0 | 0 | 0 | 0 | 60 | 0 | n/a |
| built-ins/undefined | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| harness | INCOMPLETE | 114 | 2 | 0 | 0 | 116 | 0 | 98.3% |
| intl402 | PENDING | 0 | 0 | 0 | 0 | 22 | 0 | n/a |
| intl402/Array | PENDING | 0 | 0 | 0 | 0 | 2 | 0 | n/a |
| intl402/BigInt | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| intl402/Collator | INCOMPLETE | 44 | 18 | 0 | 0 | 62 | 0 | 71.0% |
| intl402/Date | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| intl402/DateTimeFormat | INCOMPLETE | 73 | 115 | 0 | 0 | 188 | 0 | 38.8% |
| intl402/DisplayNames | INCOMPLETE | 37 | 13 | 0 | 0 | 64 | 0 | 74.0% |
| intl402/DurationFormat | INCOMPLETE | 0 | 100 | 0 | 0 | 120 | 0 | 0.0% |
| intl402/Intl | INCOMPLETE | 33 | 34 | 0 | 0 | 67 | 0 | 49.3% |
| intl402/ListFormat | INCOMPLETE | 31 | 44 | 0 | 0 | 87 | 0 | 41.3% |
| intl402/Locale | INCOMPLETE | 81 | 66 | 0 | 0 | 147 | 0 | 55.1% |
| intl402/Number | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| intl402/NumberFormat | INCOMPLETE | 103 | 147 | 0 | 0 | 252 | 0 | 41.2% |
| intl402/PluralRules | INCOMPLETE | 39 | 11 | 0 | 0 | 50 | 0 | 78.0% |
| intl402/RelativeTimeFormat | INCOMPLETE | 37 | 38 | 0 | 0 | 83 | 0 | 49.3% |
| intl402/Segmenter | INCOMPLETE | 47 | 28 | 0 | 0 | 81 | 0 | 62.7% |
| intl402/String | PARTIAL | 8 | 9 | 0 | 0 | 0 | 0 | 47.1% |
| intl402/Temporal | INCOMPLETE | 102 | 221 | 0 | 0 | 323 | 0 | 31.6% |
| intl402/TypedArray | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/arguments-object | PENDING | 0 | 0 | 0 | 0 | 263 | 0 | n/a |
| language/asi | PENDING | 0 | 0 | 0 | 0 | 102 | 0 | n/a |
| language/block-scope | PENDING | 0 | 0 | 0 | 0 | 145 | 0 | n/a |
| language/comments | PENDING | 0 | 0 | 0 | 0 | 52 | 0 | n/a |
| language/computed-property-names | PENDING | 0 | 0 | 0 | 0 | 48 | 0 | n/a |
| language/destructuring | PENDING | 0 | 0 | 0 | 0 | 19 | 0 | n/a |
| language/directive-prologue | PENDING | 0 | 0 | 0 | 0 | 62 | 0 | n/a |
| language/eval-code | PENDING | 0 | 0 | 0 | 0 | 347 | 0 | n/a |
| language/export | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| language/expressions | PENDING | 0 | 0 | 0 | 0 | 11023 | 0 | n/a |
| language/function-code | PENDING | 0 | 0 | 0 | 0 | 217 | 0 | n/a |
| language/future-reserved-words | PENDING | 0 | 0 | 0 | 0 | 55 | 0 | n/a |
| language/global-code | PENDING | 0 | 0 | 0 | 0 | 42 | 0 | n/a |
| language/identifier-resolution | PENDING | 0 | 0 | 0 | 0 | 14 | 0 | n/a |
| language/identifiers | PENDING | 0 | 0 | 0 | 0 | 260 | 0 | n/a |
| language/import | PENDING | 0 | 0 | 0 | 0 | 85 | 0 | n/a |
| language/keywords | PENDING | 0 | 0 | 0 | 0 | 25 | 0 | n/a |
| language/line-terminators | PENDING | 0 | 0 | 0 | 0 | 41 | 0 | n/a |
| language/literals | PENDING | 0 | 0 | 0 | 0 | 534 | 0 | n/a |
| language/module-code | PENDING | 0 | 0 | 0 | 0 | 583 | 0 | n/a |
| language/punctuators | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/reserved-words | PENDING | 0 | 0 | 0 | 0 | 27 | 0 | n/a |
| language/rest-parameters | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/source-text | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/statementList | PENDING | 0 | 0 | 0 | 0 | 80 | 0 | n/a |
| language/statements | RUNNING | 2122 | 3 | 0 | 0 | 16108 | 75 | 99.9% |
| language/types | PENDING | 0 | 0 | 0 | 0 | 113 | 0 | n/a |
| language/white-space | PENDING | 0 | 0 | 0 | 0 | 67 | 0 | n/a |
| staging | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/Intl402 | PENDING | 0 | 0 | 0 | 0 | 98 | 0 | n/a |
| staging/Temporal | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| staging/Uint8Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/built-ins | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| staging/decorators | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/explicit-resource-management | PENDING | 0 | 0 | 0 | 0 | 108 | 0 | n/a |
| staging/set-methods | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/sm | RUNNING | 47 | 28 | 0 | 0 | 2681 | 100 | 62.7% |
| staging/source-phase-imports | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/upsert | PENDING | 0 | 0 | 0 | 0 | 142 | 0 | n/a |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| built-ins/Array | NORMAL | 21.133s | 25 | `test262/test/built-ins/Array/prototype/concat/Array.prototype.concat_large-typed-array.js`<br>`test262/test/built-ins/Array/prototype/concat/Array.prototype.concat_length-throws.js`<br>...<br>`test262/test/built-ins/Array/prototype/concat/S15.4.4.4_A3_T2.js`<br>`test262/test/built-ins/Array/prototype/concat/S15.4.4.4_A3_T3.js` |
| built-ins/TypedArray | NORMAL | 17.645s | 25 | `test262/test/built-ins/TypedArray/prototype/copyWithin/coerced-values-end-detached-prototype.js`<br>`test262/test/built-ins/TypedArray/prototype/copyWithin/coerced-values-end-detached.js`<br>...<br>`test262/test/built-ins/TypedArray/prototype/copyWithin/prop-desc.js`<br>`test262/test/built-ins/TypedArray/prototype/copyWithin/resizable-buffer.js` |
| annexB/built-ins/String | NORMAL | 16.969s | 25 | `test262/test/annexB/built-ins/String/prototype/strike/this-val-tostring-err.js`<br>`test262/test/annexB/built-ins/String/prototype/sub/B.2.3.13.js`<br>...<br>`test262/test/annexB/built-ins/String/prototype/sup/length.js`<br>`test262/test/annexB/built-ins/String/prototype/sup/name.js` |
| language/statements | NORMAL | 13.745s | 25 | `test262/test/language/statements/await-using/throws-if-initializer-not-object.js`<br>`test262/test/language/statements/block/12.1-1.js`<br>...<br>`test262/test/language/statements/break/S12.8_A1_T1.js`<br>`test262/test/language/statements/break/S12.8_A1_T2.js` |
| staging/sm | NORMAL | 10.044s | 25 | `test262/test/staging/sm/Date/fractions.js`<br>`test262/test/staging/sm/Date/makeday-year-month-is-infinity.js`<br>...<br>`test262/test/staging/sm/Function/15.3.4.3-01.js`<br>`test262/test/staging/sm/Function/Function-prototype.js` |
| intl402/Segmenter | NORMAL | 9.759s | 25 | `test262/test/intl402/Segmenter/prototype/resolvedOptions/order.js`<br>`test262/test/intl402/Segmenter/prototype/resolvedOptions/prop-desc.js`<br>...<br>`test262/test/intl402/Segmenter/prototype/segment/segment-sentence-iterable.js`<br>`test262/test/intl402/Segmenter/prototype/segment/segment-tostring.js` |
| intl402/NumberFormat | NORMAL | 9.694s | 25 | `test262/test/intl402/NumberFormat/prototype/format/format-rounding-increment-20.js`<br>`test262/test/intl402/NumberFormat/prototype/format/format-rounding-increment-200.js`<br>...<br>`test262/test/intl402/NumberFormat/prototype/format/format-significant-digits.js`<br>`test262/test/intl402/NumberFormat/prototype/format/length.js` |
| built-ins/Temporal/ZonedDateTime | NORMAL | 9.299s | 25 | `test262/test/built-ins/Temporal/ZonedDateTime/prototype/since/prop-desc.js`<br>`test262/test/built-ins/Temporal/ZonedDateTime/prototype/since/reversibility-of-differences.js`<br>...<br>`test262/test/built-ins/Temporal/ZonedDateTime/prototype/since/same-epoch-nanoseconds.js`<br>`test262/test/built-ins/Temporal/ZonedDateTime/prototype/since/since-until.js` |
| built-ins/Temporal/ZonedDateTime | NORMAL | 8.773s | 25 | `test262/test/built-ins/Temporal/ZonedDateTime/prototype/until/rounding-increments.js`<br>`test262/test/built-ins/Temporal/ZonedDateTime/prototype/until/roundingincrement-addition-out-of-range.js`<br>...<br>`test262/test/built-ins/Temporal/ZonedDateTime/prototype/until/smallestunit-undefined.js`<br>`test262/test/built-ins/Temporal/ZonedDateTime/prototype/until/smallestunit-wrong-type.js` |
| intl402/Intl | NORMAL | 7.644s | 25 | `test262/test/intl402/Intl/getCanonicalLocales/preferred-variant.js`<br>`test262/test/intl402/Intl/getCanonicalLocales/returned-object-is-an-array.js`<br>...<br>`test262/test/intl402/Intl/supportedValuesOf/currencies-accepted-by-DisplayNames.js`<br>`test262/test/intl402/Intl/supportedValuesOf/currencies-accepted-by-NumberFormat.js` |
| language/statements | NORMAL | 6.940s | 25 | `test262/test/language/statements/do-while/decl-fun.js`<br>`test262/test/language/statements/do-while/decl-gen.js`<br>...<br>`test262/test/language/statements/for-await-of/async-func-decl-dstr-array-elem-init-yield-ident-valid.js`<br>`test262/test/language/statements/for-await-of/async-func-decl-dstr-array-elem-iter-nrml-close.js` |
| built-ins/TypedArray | NORMAL | 6.911s | 25 | `test262/test/built-ins/TypedArray/prototype/set/array-arg-targetbuffer-detached-on-tointeger-offset-throws.js`<br>`test262/test/built-ins/TypedArray/prototype/set/array-arg-targetbuffer-detached-throws.js`<br>...<br>`test262/test/built-ins/TypedArray/prototype/set/typedarray-arg-set-values-diff-buffer-other-type-conversions.js`<br>`test262/test/built-ins/TypedArray/prototype/set/typedarray-arg-set-values-diff-buffer-other-type-sab.js` |
| built-ins/Temporal/PlainTime | NORMAL | 6.668s | 25 | `test262/test/built-ins/Temporal/PlainTime/prototype/until/roundingincrement-milliseconds.js`<br>`test262/test/built-ins/Temporal/PlainTime/prototype/until/roundingincrement-minutes.js`<br>...<br>`test262/test/built-ins/Temporal/PlainTime/prototype/until/smallestunit-undefined.js`<br>`test262/test/built-ins/Temporal/PlainTime/prototype/until/smallestunit-wrong-type.js` |
| built-ins/Temporal/PlainDate | NORMAL | 6.281s | 25 | `test262/test/built-ins/Temporal/PlainDate/prototype/until/largestunit-undefined.js`<br>`test262/test/built-ins/Temporal/PlainDate/prototype/until/largestunit-week.js`<br>...<br>`test262/test/built-ins/Temporal/PlainDate/prototype/until/roundingmode-halfCeil.js`<br>`test262/test/built-ins/Temporal/PlainDate/prototype/until/roundingmode-halfEven.js` |
| built-ins/Temporal/Duration | NORMAL | 5.957s | 25 | `test262/test/built-ins/Temporal/Duration/prototype/round/relativeto-string-limits.js`<br>`test262/test/built-ins/Temporal/Duration/prototype/round/relativeto-string-plaindatetime.js`<br>...<br>`test262/test/built-ins/Temporal/Duration/prototype/round/roundingmode-floor.js`<br>`test262/test/built-ins/Temporal/Duration/prototype/round/roundingmode-halfCeil.js` |
| built-ins/Temporal/PlainDate | NORMAL | 5.870s | 25 | `test262/test/built-ins/Temporal/PlainDate/prototype/since/prop-desc.js`<br>`test262/test/built-ins/Temporal/PlainDate/prototype/since/round-cross-unit-boundary.js`<br>...<br>`test262/test/built-ins/Temporal/PlainDate/prototype/since/smallestunit-plurals-accepted.js`<br>`test262/test/built-ins/Temporal/PlainDate/prototype/since/smallestunit-undefined.js` |
| built-ins/Temporal/PlainDate | NORMAL | 5.781s | 25 | `test262/test/built-ins/Temporal/PlainDate/prototype/until/argument-string-limits.js`<br>`test262/test/built-ins/Temporal/PlainDate/prototype/until/argument-string-minus-sign.js`<br>...<br>`test262/test/built-ins/Temporal/PlainDate/prototype/until/largestunit-plurals-accepted.js`<br>`test262/test/built-ins/Temporal/PlainDate/prototype/until/largestunit-smallestunit-mismatch.js` |
| built-ins/Temporal/PlainDate | NORMAL | 5.752s | 25 | `test262/test/built-ins/Temporal/PlainDate/from/prop-desc.js`<br>`test262/test/built-ins/Temporal/PlainDate/from/subclassing-ignored.js`<br>...<br>`test262/test/built-ins/Temporal/PlainDate/prototype/add/add-years.js`<br>`test262/test/built-ins/Temporal/PlainDate/prototype/add/argument-duration-max.js` |
| built-ins/Temporal/PlainTime | NORMAL | 5.688s | 25 | `test262/test/built-ins/Temporal/PlainTime/prototype/since/roundingincrement-non-integer.js`<br>`test262/test/built-ins/Temporal/PlainTime/prototype/since/roundingincrement-out-of-range.js`<br>...<br>`test262/test/built-ins/Temporal/PlainTime/prototype/subtract/argument-duration-out-of-range.js`<br>`test262/test/built-ins/Temporal/PlainTime/prototype/subtract/argument-duration-precision-exact-numerical-values.js` |
| built-ins/TypedArray | NORMAL | 5.684s | 25 | `test262/test/built-ins/TypedArray/prototype/sort/BigInt/comparefn-call-throws.js`<br>`test262/test/built-ins/TypedArray/prototype/sort/BigInt/comparefn-calls.js`<br>...<br>`test262/test/built-ins/TypedArray/prototype/sort/prop-desc.js`<br>`test262/test/built-ins/TypedArray/prototype/sort/resizable-buffer-default-comparator.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are executed as ES modules (always strict, import/export processed).
- Tests flagged as async are skipped by the current runner.
- Mixed-mode tests currently run only in sloppy mode unless onlyStrict or noStrict is set.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

