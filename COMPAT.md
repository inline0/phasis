# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-04-18T00:01:36+00:00`
- Chunk size: `250`
- Timeout: `300s`
- Jobs: `4`
- Groups: `137`
- Test files: `50506`
- Git: `main` @ `5c52a67` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 27420 | 2945 | 19249 | 0 | 838 | 54 | 30365 | 49614 | 50506 | 90.3% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | INCOMPLETE | 824 | 190 | 40 | 0 | 25 | 0 | 81.3% |
| built-ins | RUNNING | 13646 | 751 | 7779 | 0 | 499 | 54 | 94.8% |
| harness | INCOMPLETE | 69 | 1 | 17 | 0 | 29 | 0 | 98.6% |
| intl402 | INCOMPLETE | 7 | 1168 | 356 | 0 | 35 | 0 | 0.6% |
| language | INCOMPLETE | 12180 | 396 | 10745 | 0 | 63 | 0 | 96.9% |
| staging | INCOMPLETE | 694 | 439 | 312 | 0 | 187 | 0 | 61.3% |

## Group Coverage

| Group | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB/built-ins/Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/Date | PASS | 24 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Function | PENDING | 0 | 0 | 0 | 0 | 6 | 0 | n/a |
| annexB/built-ins/Object | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/RegExp | PASS | 54 | 0 | 8 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/String | PASS | 105 | 0 | 6 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/TypedArrayConstructors | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/escape | PASS | 16 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/unescape | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/comments | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| annexB/language/eval-code | PARTIAL | 293 | 176 | 0 | 0 | 0 | 0 | 62.5% |
| annexB/language/expressions | PASS | 2 | 0 | 17 | 0 | 0 | 0 | 100.0% |
| annexB/language/function-code | PASS | 159 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/global-code | PARTIAL | 139 | 14 | 0 | 0 | 0 | 0 | 90.8% |
| annexB/language/literals | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| annexB/language/statements | PASS | 13 | 0 | 9 | 0 | 0 | 0 | 100.0% |
| built-ins/AbstractModuleSource | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| built-ins/Array | RUNNING | 2603 | 99 | 178 | 0 | 157 | 38 | 96.3% |
| built-ins/ArrayBuffer | PARTIAL | 75 | 5 | 112 | 0 | 0 | 0 | 93.8% |
| built-ins/ArrayIteratorPrototype | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncDisposableStack | SKIPPED | 0 | 0 | 52 | 0 | 0 | 0 | n/a |
| built-ins/AsyncFromSyncIteratorPrototype | SKIPPED | 0 | 0 | 38 | 0 | 0 | 0 | n/a |
| built-ins/AsyncFunction | PASS | 17 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorFunction | PASS | 1 | 0 | 22 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorPrototype | SKIPPED | 0 | 0 | 48 | 0 | 0 | 0 | n/a |
| built-ins/AsyncIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 10 | 0 | n/a |
| built-ins/Atomics | FAIL | 0 | 6 | 370 | 0 | 0 | 0 | 0.0% |
| built-ins/BigInt | PASS | 74 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/Boolean | PASS | 50 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/DataView | PARTIAL | 430 | 8 | 112 | 0 | 0 | 0 | 98.2% |
| built-ins/Date | PARTIAL | 572 | 11 | 11 | 0 | 0 | 0 | 98.1% |
| built-ins/DisposableStack | SKIPPED | 0 | 0 | 52 | 0 | 0 | 0 | n/a |
| built-ins/Error | PASS | 50 | 0 | 3 | 0 | 0 | 0 | 100.0% |
| built-ins/FinalizationRegistry | SKIPPED | 0 | 0 | 47 | 0 | 0 | 0 | n/a |
| built-ins/Function | INCOMPLETE | 437 | 13 | 50 | 0 | 9 | 0 | 97.1% |
| built-ins/GeneratorFunction | PASS | 21 | 0 | 2 | 0 | 0 | 0 | 100.0% |
| built-ins/GeneratorPrototype | PASS | 61 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Infinity | PENDING | 0 | 0 | 0 | 0 | 6 | 0 | n/a |
| built-ins/Iterator | PARTIAL | 5 | 31 | 395 | 0 | 0 | 0 | 13.9% |
| built-ins/JSON | PARTIAL | 139 | 2 | 24 | 0 | 0 | 0 | 98.6% |
| built-ins/Map | PARTIAL | 165 | 4 | 2 | 0 | 0 | 0 | 97.6% |
| built-ins/MapIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| built-ins/Math | PARTIAL | 309 | 3 | 15 | 0 | 0 | 0 | 99.0% |
| built-ins/NaN | PENDING | 0 | 0 | 0 | 0 | 6 | 0 | n/a |
| built-ins/NativeErrors | PARTIAL | 93 | 17 | 29 | 0 | 0 | 0 | 84.5% |
| built-ins/Number | PARTIAL | 326 | 8 | 1 | 0 | 0 | 0 | 97.6% |
| built-ins/Object | PARTIAL | 3323 | 74 | 13 | 0 | 0 | 0 | 97.8% |
| built-ins/Promise | INCOMPLETE | 156 | 78 | 287 | 0 | 110 | 0 | 66.7% |
| built-ins/Proxy | PARTIAL | 268 | 5 | 38 | 0 | 0 | 0 | 98.2% |
| built-ins/Reflect | PARTIAL | 152 | 1 | 0 | 0 | 0 | 0 | 99.3% |
| built-ins/RegExp | RUNNING | 723 | 112 | 970 | 0 | 46 | 16 | 86.6% |
| built-ins/RegExpStringIteratorPrototype | PARTIAL | 4 | 13 | 0 | 0 | 0 | 0 | 23.5% |
| built-ins/Set | PARTIAL | 191 | 4 | 186 | 0 | 0 | 0 | 97.9% |
| built-ins/SetIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| built-ins/ShadowRealm | SKIPPED | 0 | 0 | 64 | 0 | 0 | 0 | n/a |
| built-ins/SharedArrayBuffer | SKIPPED | 0 | 0 | 104 | 0 | 0 | 0 | n/a |
| built-ins/String | PARTIAL | 1159 | 27 | 26 | 0 | 0 | 0 | 97.7% |
| built-ins/StringIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| built-ins/Symbol | PARTIAL | 74 | 2 | 18 | 0 | 0 | 0 | 97.4% |
| built-ins/Temporal | SKIPPED | 0 | 0 | 4165 | 0 | 0 | 0 | n/a |
| built-ins/ThrowTypeError | PENDING | 0 | 0 | 0 | 0 | 14 | 0 | n/a |
| built-ins/TypedArray | PARTIAL | 1166 | 49 | 211 | 0 | 0 | 0 | 96.0% |
| built-ins/TypedArrayConstructors | PARTIAL | 534 | 118 | 84 | 0 | 0 | 0 | 81.9% |
| built-ins/Uint8Array | PARTIAL | 8 | 56 | 0 | 0 | 0 | 0 | 12.5% |
| built-ins/WeakMap | PARTIAL | 91 | 1 | 10 | 0 | 0 | 0 | 98.9% |
| built-ins/WeakRef | SKIPPED | 0 | 0 | 29 | 0 | 0 | 0 | n/a |
| built-ins/WeakSet | PARTIAL | 76 | 1 | 8 | 0 | 0 | 0 | 98.7% |
| built-ins/decodeURI | INCOMPLETE | 27 | 0 | 0 | 0 | 28 | 0 | 100.0% |
| built-ins/decodeURIComponent | INCOMPLETE | 28 | 0 | 0 | 0 | 28 | 0 | 100.0% |
| built-ins/encodeURI | PASS | 31 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/encodeURIComponent | PASS | 31 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/eval | PENDING | 0 | 0 | 0 | 0 | 10 | 0 | n/a |
| built-ins/global | PARTIAL | 27 | 2 | 0 | 0 | 0 | 0 | 93.1% |
| built-ins/isFinite | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isNaN | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseFloat | PARTIAL | 58 | 1 | 0 | 0 | 0 | 0 | 98.3% |
| built-ins/parseInt | INCOMPLETE | 30 | 0 | 0 | 0 | 30 | 0 | 100.0% |
| built-ins/undefined | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| harness | INCOMPLETE | 69 | 1 | 17 | 0 | 29 | 0 | 98.6% |
| intl402 | FAIL | 0 | 22 | 0 | 0 | 0 | 0 | 0.0% |
| intl402/Array | PENDING | 0 | 0 | 0 | 0 | 2 | 0 | n/a |
| intl402/BigInt | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| intl402/Collator | FAIL | 0 | 61 | 1 | 0 | 0 | 0 | 0.0% |
| intl402/Date | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| intl402/DateTimeFormat | FAIL | 0 | 172 | 16 | 0 | 0 | 0 | 0.0% |
| intl402/DisplayNames | FAIL | 0 | 56 | 1 | 0 | 0 | 0 | 0.0% |
| intl402/DurationFormat | FAIL | 0 | 104 | 6 | 0 | 0 | 0 | 0.0% |
| intl402/Intl | FAIL | 0 | 65 | 2 | 0 | 0 | 0 | 0.0% |
| intl402/ListFormat | FAIL | 0 | 80 | 1 | 0 | 0 | 0 | 0.0% |
| intl402/Locale | FAIL | 0 | 146 | 1 | 0 | 0 | 0 | 0.0% |
| intl402/Number | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| intl402/NumberFormat | INCOMPLETE | 0 | 249 | 1 | 0 | 2 | 0 | 0.0% |
| intl402/PluralRules | FAIL | 0 | 49 | 1 | 0 | 0 | 0 | 0.0% |
| intl402/RelativeTimeFormat | FAIL | 0 | 78 | 1 | 0 | 0 | 0 | 0.0% |
| intl402/Segmenter | FAIL | 0 | 76 | 2 | 0 | 0 | 0 | 0.0% |
| intl402/String | PARTIAL | 7 | 10 | 0 | 0 | 0 | 0 | 41.2% |
| intl402/Temporal | SKIPPED | 0 | 0 | 323 | 0 | 0 | 0 | n/a |
| intl402/TypedArray | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/arguments-object | INCOMPLETE | 120 | 28 | 102 | 0 | 13 | 0 | 81.1% |
| language/asi | PASS | 102 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/block-scope | PASS | 126 | 0 | 19 | 0 | 0 | 0 | 100.0% |
| language/comments | PASS | 51 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| language/computed-property-names | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/destructuring | PASS | 18 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| language/directive-prologue | PASS | 62 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/eval-code | PARTIAL | 254 | 40 | 53 | 0 | 0 | 0 | 86.4% |
| language/export | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| language/expressions | PARTIAL | 5848 | 102 | 5073 | 0 | 0 | 0 | 98.3% |
| language/function-code | PARTIAL | 214 | 3 | 0 | 0 | 0 | 0 | 98.6% |
| language/future-reserved-words | PARTIAL | 54 | 1 | 0 | 0 | 0 | 0 | 98.2% |
| language/global-code | PASS | 38 | 0 | 4 | 0 | 0 | 0 | 100.0% |
| language/identifier-resolution | PENDING | 0 | 0 | 0 | 0 | 14 | 0 | n/a |
| language/identifiers | INCOMPLETE | 160 | 34 | 56 | 0 | 10 | 0 | 82.5% |
| language/import | PASS | 6 | 0 | 79 | 0 | 0 | 0 | 100.0% |
| language/keywords | PASS | 25 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/line-terminators | PASS | 41 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/literals | PARTIAL | 447 | 4 | 83 | 0 | 0 | 0 | 99.1% |
| language/module-code | SKIPPED | 0 | 0 | 583 | 0 | 0 | 0 | n/a |
| language/punctuators | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/reserved-words | PASS | 26 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| language/rest-parameters | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/source-text | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/statementList | PARTIAL | 63 | 17 | 0 | 0 | 0 | 0 | 78.8% |
| language/statements | PARTIAL | 4302 | 164 | 4688 | 0 | 0 | 0 | 96.3% |
| language/types | PARTIAL | 108 | 3 | 2 | 0 | 0 | 0 | 97.3% |
| language/white-space | PASS | 67 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/Intl402 | SKIPPED | 0 | 0 | 49 | 0 | 0 | 0 | n/a |
| staging/Temporal | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| staging/Uint8Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/built-ins | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| staging/decorators | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/explicit-resource-management | SKIPPED | 0 | 0 | 54 | 0 | 0 | 0 | n/a |
| staging/set-methods | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/sm | INCOMPLETE | 671 | 397 | 203 | 0 | 157 | 0 | 62.8% |
| staging/source-phase-imports | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/upsert | PARTIAL | 23 | 42 | 6 | 0 | 0 | 0 | 35.4% |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| staging/sm | NORMAL | 196.169s | 250 | `test262/test/staging/sm/TypedArray/from_basics.js`<br>`test262/test/staging/sm/TypedArray/from_constructor.js`<br>...<br>`test262/test/staging/sm/expressions/string-literal-escape-sequences.js`<br>`test262/test/staging/sm/expressions/tagged-template-constant-folding.js` |
| built-ins/decodeURI | NORMAL | 83.091s | 27 | `test262/test/built-ins/decodeURI/S15.1.3.1_A1.10_T1.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.11_T1.js`<br>...<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.6_T1.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.7_T1.js` |
| built-ins/decodeURIComponent | NORMAL | 82.013s | 28 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.10_T1.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.11_T1.js`<br>...<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.7_T1.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.8_T1.js` |
| language/literals | NORMAL | 56.098s | 250 | `test262/test/language/literals/bigint/binary-invalid-digit.js`<br>`test262/test/language/literals/bigint/exponent-part.js`<br>...<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T2.js` |
| annexB/built-ins/RegExp | NORMAL | 54.206s | 62 | `test262/test/annexB/built-ins/RegExp/RegExp-control-escape-russian-letter.js`<br>`test262/test/annexB/built-ins/RegExp/RegExp-decimal-escape-class-range.js`<br>...<br>`test262/test/annexB/built-ins/RegExp/prototype/compile/this-subclass-instance.js`<br>`test262/test/annexB/built-ins/RegExp/prototype/flags/order-after-compile.js` |
| staging/sm | NORMAL | 37.573s | 63 | `test262/test/staging/sm/Symbol/equality.js`<br>`test262/test/staging/sm/Symbol/errors.js`<br>...<br>`test262/test/staging/sm/TypedArray/findLast-and-findLastIndex.js`<br>`test262/test/staging/sm/TypedArray/forEach.js` |
| built-ins/encodeURI | NORMAL | 32.777s | 31 | `test262/test/built-ins/encodeURI/S15.1.3.3_A1.1_T1.js`<br>`test262/test/built-ins/encodeURI/S15.1.3.3_A1.1_T2.js`<br>...<br>`test262/test/built-ins/encodeURI/not-a-constructor.js`<br>`test262/test/built-ins/encodeURI/prop-desc.js` |
| built-ins/encodeURIComponent | NORMAL | 31.282s | 31 | `test262/test/built-ins/encodeURIComponent/S15.1.3.4_A1.1_T1.js`<br>`test262/test/built-ins/encodeURIComponent/S15.1.3.4_A1.1_T2.js`<br>...<br>`test262/test/built-ins/encodeURIComponent/not-a-constructor.js`<br>`test262/test/built-ins/encodeURIComponent/prop-desc.js` |
| language/comments | NORMAL | 26.002s | 52 | `test262/test/language/comments/S7.4_A1_T1.js`<br>`test262/test/language/comments/S7.4_A1_T2.js`<br>...<br>`test262/test/language/comments/multi-line-html-close-extra.js`<br>`test262/test/language/comments/single-line-html-close-without-lt.js` |
| language/literals | NORMAL | 19.206s | 250 | `test262/test/language/literals/regexp/S7.8.5_A2.3_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.3_T2.js`<br>...<br>`test262/test/language/literals/string/S7.8.4_A7.2_T1.js`<br>`test262/test/language/literals/string/S7.8.4_A7.2_T2.js` |
| staging/sm | NORMAL | 16.755s | 125 | `test262/test/staging/sm/Date/fractions.js`<br>`test262/test/staging/sm/Date/makeday-year-month-is-infinity.js`<br>...<br>`test262/test/staging/sm/Iterator/prototype/find/check-fn-after-getting-iterator.js`<br>`test262/test/staging/sm/Iterator/prototype/find/coerce-result-to-boolean.js` |
| staging/sm | NORMAL | 12.899s | 31 | `test262/test/staging/sm/RegExp/unicode-raw.js`<br>`test262/test/staging/sm/Set/difference.js`<br>...<br>`test262/test/staging/sm/String/normalize-parameter.js`<br>`test262/test/staging/sm/String/normalize-rope.js` |
| staging/sm | NORMAL | 9.941s | 125 | `test262/test/staging/sm/Proxy/ownkeys-allowed-types.js`<br>`test262/test/staging/sm/Proxy/ownkeys-linear.js`<br>...<br>`test262/test/staging/sm/RegExp/unicode-ignoreCase.js`<br>`test262/test/staging/sm/RegExp/unicode-lead-trail.js` |
| built-ins/Function | NORMAL | 8.959s | 250 | `test262/test/built-ins/Function/prototype/apply/S15.3.4.3_A7_T10.js`<br>`test262/test/built-ins/Function/prototype/apply/S15.3.4.3_A7_T2.js`<br>...<br>`test262/test/built-ins/Function/prototype/toString/proxy-function-expression.js`<br>`test262/test/built-ins/Function/prototype/toString/proxy-generator-function.js` |
| staging/sm | NORMAL | 8.782s | 250 | `test262/test/staging/sm/Iterator/prototype/find/descriptor.js`<br>`test262/test/staging/sm/Iterator/prototype/find/error-from-correct-realm.js`<br>...<br>`test262/test/staging/sm/Proxy/hasInstance.js`<br>`test262/test/staging/sm/Proxy/json-stringify-replacer-array-revocable-proxy.js` |
| built-ins/parseFloat | NORMAL | 6.004s | 59 | `test262/test/built-ins/parseFloat/15.1.2.3-2-1.js`<br>`test262/test/built-ins/parseFloat/S15.1.2.3_A1_T1.js`<br>...<br>`test262/test/built-ins/parseFloat/tonumber-numeric-separator-literal-nzd-nsl-dds.js`<br>`test262/test/built-ins/parseFloat/tonumber-numeric-separator-literal-sign-plus-dds-nsl-dd.js` |
| annexB/built-ins/String | NORMAL | 5.549s | 111 | `test262/test/annexB/built-ins/String/prototype/anchor/B.2.3.2.js`<br>`test262/test/annexB/built-ins/String/prototype/anchor/attr-tostring-err.js`<br>...<br>`test262/test/annexB/built-ins/String/prototype/trimRight/prop-desc.js`<br>`test262/test/annexB/built-ins/String/prototype/trimRight/reference-trimEnd.js` |
| staging/sm | NORMAL | 5.243s | 125 | `test262/test/staging/sm/extensions/8.12.5-01.js`<br>`test262/test/staging/sm/extensions/ArrayBuffer-slice-arguments-detaching.js`<br>...<br>`test262/test/staging/sm/lexical-environment/block-scoped-functions-annex-b-notapplicable.js`<br>`test262/test/staging/sm/lexical-environment/block-scoped-functions-annex-b-parameter.js` |
| intl402 | NORMAL | 4.747s | 22 | `test262/test/intl402/constructors-string-and-single-element-array.js`<br>`test262/test/intl402/constructors-taint-Object-prototype-2.js`<br>...<br>`test262/test/intl402/supportedLocalesOf-throws-if-element-not-string-or-object.js`<br>`test262/test/intl402/supportedLocalesOf-unicode-extensions-ignored.js` |
| staging/sm | NORMAL | 4.434s | 178 | `test262/test/staging/sm/regress/regress-410852.js`<br>`test262/test/staging/sm/regress/regress-428366.js`<br>...<br>`test262/test/staging/sm/template.js`<br>`test262/test/staging/sm/types/8.12.5-01.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are skipped by the current runner.
- Tests flagged as async are skipped by the current runner.
- Mixed-mode tests currently run only in sloppy mode unless onlyStrict or noStrict is set.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

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
- `Symbol.asyncIterator`
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
- `IsHTMLDDA`
- `iterator-helpers`
- `set-methods`
- `Array.fromAsync`
- `Math.sumPrecise`
- `well-formed-json-stringify`
- `json-parse-with-source`
- `String.prototype.isWellFormed`
- `String.prototype.toWellFormed`
