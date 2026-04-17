# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-04-17T11:37:49+00:00`
- Chunk size: `250`
- Timeout: `300s`
- Jobs: `4`
- Groups: `137`
- Test files: `50506`
- Git: `main` @ `5442541` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 26091 | 4866 | 19347 | 0 | 190 | 12 | 30957 | 50304 | 50506 | 84.3% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | INCOMPLETE | 360 | 676 | 40 | 0 | 3 | 0 | 34.7% |
| built-ins | RUNNING | 13137 | 1601 | 7858 | 0 | 125 | 8 | 89.1% |
| harness | INCOMPLETE | 91 | 1 | 17 | 0 | 7 | 0 | 98.9% |
| intl402 | INCOMPLETE | 23 | 1182 | 356 | 0 | 5 | 0 | 1.9% |
| language | INCOMPLETE | 11814 | 820 | 10746 | 0 | 4 | 0 | 93.5% |
| staging | RUNNING | 666 | 586 | 330 | 0 | 46 | 4 | 53.2% |

## Group Coverage

| Group | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB/built-ins/Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/Date | FAIL | 0 | 24 | 0 | 0 | 0 | 0 | 0.0% |
| annexB/built-ins/Function | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Object | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/RegExp | PARTIAL | 4 | 50 | 8 | 0 | 0 | 0 | 7.4% |
| annexB/built-ins/String | PARTIAL | 104 | 1 | 6 | 0 | 0 | 0 | 99.0% |
| annexB/built-ins/TypedArrayConstructors | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/escape | PASS | 16 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/unescape | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/comments | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/eval-code | PARTIAL | 107 | 362 | 0 | 0 | 0 | 0 | 22.8% |
| annexB/language/expressions | PASS | 2 | 0 | 17 | 0 | 0 | 0 | 100.0% |
| annexB/language/function-code | PARTIAL | 54 | 105 | 0 | 0 | 0 | 0 | 34.0% |
| annexB/language/global-code | PARTIAL | 25 | 128 | 0 | 0 | 0 | 0 | 16.3% |
| annexB/language/literals | PARTIAL | 3 | 5 | 0 | 0 | 0 | 0 | 37.5% |
| annexB/language/statements | PARTIAL | 12 | 1 | 9 | 0 | 0 | 0 | 92.3% |
| built-ins/AbstractModuleSource | SKIPPED | 0 | 0 | 8 | 0 | 0 | 0 | n/a |
| built-ins/Array | RUNNING | 2700 | 119 | 188 | 0 | 64 | 4 | 95.8% |
| built-ins/ArrayBuffer | PARTIAL | 63 | 17 | 112 | 0 | 0 | 0 | 78.8% |
| built-ins/ArrayIteratorPrototype | PARTIAL | 16 | 11 | 0 | 0 | 0 | 0 | 59.3% |
| built-ins/AsyncDisposableStack | SKIPPED | 0 | 0 | 52 | 0 | 0 | 0 | n/a |
| built-ins/AsyncFromSyncIteratorPrototype | SKIPPED | 0 | 0 | 38 | 0 | 0 | 0 | n/a |
| built-ins/AsyncFunction | PARTIAL | 9 | 8 | 1 | 0 | 0 | 0 | 52.9% |
| built-ins/AsyncGeneratorFunction | PASS | 1 | 0 | 22 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorPrototype | SKIPPED | 0 | 0 | 48 | 0 | 0 | 0 | n/a |
| built-ins/AsyncIteratorPrototype | SKIPPED | 0 | 0 | 10 | 0 | 0 | 0 | n/a |
| built-ins/Atomics | FAIL | 0 | 6 | 370 | 0 | 0 | 0 | 0.0% |
| built-ins/BigInt | PASS | 74 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/Boolean | PASS | 50 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/DataView | PARTIAL | 297 | 141 | 112 | 0 | 0 | 0 | 67.8% |
| built-ins/Date | PARTIAL | 571 | 12 | 11 | 0 | 0 | 0 | 97.9% |
| built-ins/DisposableStack | SKIPPED | 0 | 0 | 52 | 0 | 0 | 0 | n/a |
| built-ins/Error | PARTIAL | 49 | 1 | 3 | 0 | 0 | 0 | 98.0% |
| built-ins/FinalizationRegistry | SKIPPED | 0 | 0 | 47 | 0 | 0 | 0 | n/a |
| built-ins/Function | PARTIAL | 445 | 14 | 50 | 0 | 0 | 0 | 96.9% |
| built-ins/GeneratorFunction | PARTIAL | 9 | 12 | 2 | 0 | 0 | 0 | 42.9% |
| built-ins/GeneratorPrototype | PARTIAL | 44 | 17 | 0 | 0 | 0 | 0 | 72.1% |
| built-ins/Infinity | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Iterator | FAIL | 0 | 36 | 395 | 0 | 0 | 0 | 0.0% |
| built-ins/JSON | PASS | 141 | 0 | 24 | 0 | 0 | 0 | 100.0% |
| built-ins/Map | PARTIAL | 165 | 4 | 2 | 0 | 0 | 0 | 97.6% |
| built-ins/MapIteratorPrototype | PARTIAL | 2 | 9 | 0 | 0 | 0 | 0 | 18.2% |
| built-ins/Math | PARTIAL | 309 | 3 | 15 | 0 | 0 | 0 | 99.0% |
| built-ins/NaN | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/NativeErrors | PARTIAL | 92 | 18 | 29 | 0 | 0 | 0 | 83.6% |
| built-ins/Number | PARTIAL | 326 | 8 | 1 | 0 | 0 | 0 | 97.6% |
| built-ins/Object | PARTIAL | 3056 | 341 | 13 | 0 | 0 | 0 | 90.0% |
| built-ins/Promise | INCOMPLETE | 160 | 90 | 335 | 0 | 46 | 0 | 64.0% |
| built-ins/Proxy | PARTIAL | 269 | 4 | 38 | 0 | 0 | 0 | 98.5% |
| built-ins/Reflect | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp | RUNNING | 736 | 141 | 971 | 0 | 15 | 4 | 83.9% |
| built-ins/RegExpStringIteratorPrototype | PARTIAL | 5 | 12 | 0 | 0 | 0 | 0 | 29.4% |
| built-ins/Set | PARTIAL | 191 | 4 | 186 | 0 | 0 | 0 | 97.9% |
| built-ins/SetIteratorPrototype | PARTIAL | 2 | 9 | 0 | 0 | 0 | 0 | 18.2% |
| built-ins/ShadowRealm | SKIPPED | 0 | 0 | 64 | 0 | 0 | 0 | n/a |
| built-ins/SharedArrayBuffer | SKIPPED | 0 | 0 | 104 | 0 | 0 | 0 | n/a |
| built-ins/String | PARTIAL | 1154 | 32 | 26 | 0 | 0 | 0 | 97.3% |
| built-ins/StringIteratorPrototype | FAIL | 0 | 7 | 0 | 0 | 0 | 0 | 0.0% |
| built-ins/Symbol | PARTIAL | 74 | 2 | 18 | 0 | 0 | 0 | 97.4% |
| built-ins/Temporal | SKIPPED | 0 | 0 | 4165 | 0 | 0 | 0 | n/a |
| built-ins/ThrowTypeError | PARTIAL | 8 | 5 | 1 | 0 | 0 | 0 | 61.5% |
| built-ins/TypedArray | PARTIAL | 1084 | 131 | 211 | 0 | 0 | 0 | 89.2% |
| built-ins/TypedArrayConstructors | PARTIAL | 426 | 226 | 84 | 0 | 0 | 0 | 65.3% |
| built-ins/Uint8Array | PARTIAL | 6 | 58 | 0 | 0 | 0 | 0 | 9.4% |
| built-ins/WeakMap | PARTIAL | 85 | 7 | 10 | 0 | 0 | 0 | 92.4% |
| built-ins/WeakRef | SKIPPED | 0 | 0 | 29 | 0 | 0 | 0 | n/a |
| built-ins/WeakSet | PARTIAL | 73 | 4 | 8 | 0 | 0 | 0 | 94.8% |
| built-ins/decodeURI | PARTIAL | 18 | 37 | 0 | 0 | 0 | 0 | 32.7% |
| built-ins/decodeURIComponent | PARTIAL | 22 | 34 | 0 | 0 | 0 | 0 | 39.3% |
| built-ins/encodeURI | PARTIAL | 22 | 9 | 0 | 0 | 0 | 0 | 71.0% |
| built-ins/encodeURIComponent | PARTIAL | 23 | 8 | 0 | 0 | 0 | 0 | 74.2% |
| built-ins/eval | PASS | 9 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/global | PARTIAL | 27 | 2 | 0 | 0 | 0 | 0 | 93.1% |
| built-ins/isFinite | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isNaN | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseFloat | PARTIAL | 58 | 1 | 0 | 0 | 0 | 0 | 98.3% |
| built-ins/parseInt | PARTIAL | 59 | 1 | 0 | 0 | 0 | 0 | 98.3% |
| built-ins/undefined | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| harness | INCOMPLETE | 91 | 1 | 17 | 0 | 7 | 0 | 98.9% |
| intl402 | FAIL | 0 | 22 | 0 | 0 | 0 | 0 | 0.0% |
| intl402/Array | PENDING | 0 | 0 | 0 | 0 | 2 | 0 | n/a |
| intl402/BigInt | PARTIAL | 5 | 6 | 0 | 0 | 0 | 0 | 45.5% |
| intl402/Collator | FAIL | 0 | 61 | 1 | 0 | 0 | 0 | 0.0% |
| intl402/Date | PARTIAL | 8 | 4 | 0 | 0 | 0 | 0 | 66.7% |
| intl402/DateTimeFormat | FAIL | 0 | 172 | 16 | 0 | 0 | 0 | 0.0% |
| intl402/DisplayNames | FAIL | 0 | 56 | 1 | 0 | 0 | 0 | 0.0% |
| intl402/DurationFormat | FAIL | 0 | 104 | 6 | 0 | 0 | 0 | 0.0% |
| intl402/Intl | FAIL | 0 | 65 | 2 | 0 | 0 | 0 | 0.0% |
| intl402/ListFormat | FAIL | 0 | 80 | 1 | 0 | 0 | 0 | 0.0% |
| intl402/Locale | FAIL | 0 | 146 | 1 | 0 | 0 | 0 | 0.0% |
| intl402/Number | PARTIAL | 3 | 4 | 0 | 0 | 0 | 0 | 42.9% |
| intl402/NumberFormat | INCOMPLETE | 0 | 249 | 1 | 0 | 2 | 0 | 0.0% |
| intl402/PluralRules | FAIL | 0 | 49 | 1 | 0 | 0 | 0 | 0.0% |
| intl402/RelativeTimeFormat | FAIL | 0 | 78 | 1 | 0 | 0 | 0 | 0.0% |
| intl402/Segmenter | FAIL | 0 | 76 | 2 | 0 | 0 | 0 | 0.0% |
| intl402/String | PARTIAL | 7 | 10 | 0 | 0 | 0 | 0 | 41.2% |
| intl402/Temporal | SKIPPED | 0 | 0 | 323 | 0 | 0 | 0 | n/a |
| intl402/TypedArray | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/arguments-object | PARTIAL | 138 | 23 | 102 | 0 | 0 | 0 | 85.7% |
| language/asi | PASS | 102 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/block-scope | PASS | 126 | 0 | 19 | 0 | 0 | 0 | 100.0% |
| language/comments | PARTIAL | 48 | 3 | 1 | 0 | 0 | 0 | 94.1% |
| language/computed-property-names | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/destructuring | PARTIAL | 17 | 1 | 1 | 0 | 0 | 0 | 94.4% |
| language/directive-prologue | PARTIAL | 57 | 5 | 0 | 0 | 0 | 0 | 91.9% |
| language/eval-code | PARTIAL | 109 | 185 | 53 | 0 | 0 | 0 | 37.1% |
| language/export | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| language/expressions | PARTIAL | 5675 | 275 | 5073 | 0 | 0 | 0 | 95.4% |
| language/function-code | PARTIAL | 211 | 6 | 0 | 0 | 0 | 0 | 97.2% |
| language/future-reserved-words | PARTIAL | 54 | 1 | 0 | 0 | 0 | 0 | 98.2% |
| language/global-code | PARTIAL | 20 | 18 | 4 | 0 | 0 | 0 | 52.6% |
| language/identifier-resolution | PASS | 13 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| language/identifiers | PARTIAL | 164 | 40 | 56 | 0 | 0 | 0 | 80.4% |
| language/import | PASS | 6 | 0 | 79 | 0 | 0 | 0 | 100.0% |
| language/keywords | PASS | 25 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/line-terminators | PASS | 41 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/literals | PARTIAL | 442 | 9 | 83 | 0 | 0 | 0 | 98.0% |
| language/module-code | SKIPPED | 0 | 0 | 583 | 0 | 0 | 0 | n/a |
| language/punctuators | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/reserved-words | PASS | 26 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| language/rest-parameters | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/source-text | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/statementList | PARTIAL | 63 | 17 | 0 | 0 | 0 | 0 | 78.8% |
| language/statements | PARTIAL | 4251 | 215 | 4688 | 0 | 0 | 0 | 95.2% |
| language/types | PARTIAL | 108 | 3 | 2 | 0 | 0 | 0 | 97.3% |
| language/white-space | PARTIAL | 48 | 19 | 0 | 0 | 0 | 0 | 71.6% |
| staging | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/Intl402 | SKIPPED | 0 | 0 | 49 | 0 | 0 | 0 | n/a |
| staging/Temporal | SKIPPED | 0 | 0 | 12 | 0 | 0 | 0 | n/a |
| staging/Uint8Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/built-ins | PASS | 1 | 0 | 6 | 0 | 0 | 0 | 100.0% |
| staging/decorators | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/explicit-resource-management | SKIPPED | 0 | 0 | 54 | 0 | 0 | 0 | n/a |
| staging/set-methods | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/sm | RUNNING | 642 | 544 | 203 | 0 | 35 | 4 | 54.1% |
| staging/source-phase-imports | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/upsert | PARTIAL | 23 | 42 | 6 | 0 | 0 | 0 | 35.4% |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| built-ins/decodeURI | NORMAL | 215.349s | 55 | `test262/test/built-ins/decodeURI/S15.1.3.1_A1.10_T1.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.11_T1.js`<br>...<br>`test262/test/built-ins/decodeURI/not-a-constructor.js`<br>`test262/test/built-ins/decodeURI/prop-desc.js` |
| built-ins/decodeURIComponent | NORMAL | 210.347s | 56 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.10_T1.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.11_T1.js`<br>...<br>`test262/test/built-ins/decodeURIComponent/prop-desc.js`<br>`test262/test/built-ins/decodeURIComponent/throw-URIError.js` |
| staging/sm | NORMAL | 191.757s | 15 | `test262/test/staging/sm/Array/group.js`<br>`test262/test/staging/sm/Array/includes-trailing-holes.js`<br>...<br>`test262/test/staging/sm/Array/length-truncate-nonconfigurable-sparse.js`<br>`test262/test/staging/sm/Array/length-truncate-nonconfigurable.js` |
| staging/sm | NORMAL | 186.234s | 8 | `test262/test/staging/sm/DataView/get-set-index-range.js`<br>`test262/test/staging/sm/DataView/getter-name.js`<br>`test262/test/staging/sm/Date/UTC-convert-all-arguments.js`<br>`test262/test/staging/sm/Date/constructor-convert-all-arguments.js`<br>`test262/test/staging/sm/Date/constructor-one-Date-argument.js`<br>`test262/test/staging/sm/Date/constructor-one-argument.js`<br>`test262/test/staging/sm/Date/defaultvalue.js`<br>`test262/test/staging/sm/Date/dst-offset-caching-1-of-8.js` |
| staging/sm | NORMAL | 107.418s | 250 | `test262/test/staging/sm/TypedArray/from_basics.js`<br>`test262/test/staging/sm/TypedArray/from_constructor.js`<br>...<br>`test262/test/staging/sm/expressions/string-literal-escape-sequences.js`<br>`test262/test/staging/sm/expressions/tagged-template-constant-folding.js` |
| staging/sm | NORMAL | 37.320s | 8 | `test262/test/staging/sm/Array/to-length.js`<br>`test262/test/staging/sm/Array/toLocaleString-01.js`<br>`test262/test/staging/sm/Array/toLocaleString-nointl.js`<br>`test262/test/staging/sm/Array/toLocaleString.js`<br>`test262/test/staging/sm/Array/toSpliced-dense.js`<br>`test262/test/staging/sm/Array/toSpliced.js`<br>`test262/test/staging/sm/Array/toString-01.js`<br>`test262/test/staging/sm/Array/unscopables.js` |
| language/literals | NORMAL | 36.063s | 250 | `test262/test/language/literals/bigint/binary-invalid-digit.js`<br>`test262/test/language/literals/bigint/exponent-part.js`<br>...<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T2.js` |
| staging/sm | NORMAL | 20.203s | 63 | `test262/test/staging/sm/Symbol/equality.js`<br>`test262/test/staging/sm/Symbol/errors.js`<br>...<br>`test262/test/staging/sm/TypedArray/findLast-and-findLastIndex.js`<br>`test262/test/staging/sm/TypedArray/forEach.js` |
| built-ins/encodeURI | NORMAL | 14.045s | 31 | `test262/test/built-ins/encodeURI/S15.1.3.3_A1.1_T1.js`<br>`test262/test/built-ins/encodeURI/S15.1.3.3_A1.1_T2.js`<br>...<br>`test262/test/built-ins/encodeURI/not-a-constructor.js`<br>`test262/test/built-ins/encodeURI/prop-desc.js` |
| built-ins/encodeURIComponent | NORMAL | 14.037s | 31 | `test262/test/built-ins/encodeURIComponent/S15.1.3.4_A1.1_T1.js`<br>`test262/test/built-ins/encodeURIComponent/S15.1.3.4_A1.1_T2.js`<br>...<br>`test262/test/built-ins/encodeURIComponent/not-a-constructor.js`<br>`test262/test/built-ins/encodeURIComponent/prop-desc.js` |
| language/literals | NORMAL | 12.514s | 250 | `test262/test/language/literals/regexp/S7.8.5_A2.3_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.3_T2.js`<br>...<br>`test262/test/language/literals/string/S7.8.4_A7.2_T1.js`<br>`test262/test/language/literals/string/S7.8.4_A7.2_T2.js` |
| staging/sm | NORMAL | 11.414s | 16 | `test262/test/staging/sm/String/split-undefined-separator.js`<br>`test262/test/staging/sm/String/split-xregexp.js`<br>...<br>`test262/test/staging/sm/Symbol/enumeration-order.js`<br>`test262/test/staging/sm/Symbol/enumeration.js` |
| language/comments | NORMAL | 11.209s | 52 | `test262/test/language/comments/S7.4_A1_T1.js`<br>`test262/test/language/comments/S7.4_A1_T2.js`<br>...<br>`test262/test/language/comments/multi-line-html-close-extra.js`<br>`test262/test/language/comments/single-line-html-close-without-lt.js` |
| staging/sm | NORMAL | 7.595s | 250 | `test262/test/staging/sm/Iterator/prototype/find/descriptor.js`<br>`test262/test/staging/sm/Iterator/prototype/find/error-from-correct-realm.js`<br>...<br>`test262/test/staging/sm/Proxy/hasInstance.js`<br>`test262/test/staging/sm/Proxy/json-stringify-replacer-array-revocable-proxy.js` |
| staging/sm | NORMAL | 6.307s | 31 | `test262/test/staging/sm/RegExp/unicode-raw.js`<br>`test262/test/staging/sm/Set/difference.js`<br>...<br>`test262/test/staging/sm/String/normalize-parameter.js`<br>`test262/test/staging/sm/String/normalize-rope.js` |
| staging/sm | NORMAL | 5.650s | 125 | `test262/test/staging/sm/Proxy/ownkeys-allowed-types.js`<br>`test262/test/staging/sm/Proxy/ownkeys-linear.js`<br>...<br>`test262/test/staging/sm/RegExp/unicode-ignoreCase.js`<br>`test262/test/staging/sm/RegExp/unicode-lead-trail.js` |
| built-ins/parseInt | NORMAL | 4.740s | 60 | `test262/test/built-ins/parseInt/15.1.2.2-2-1.js`<br>`test262/test/built-ins/parseInt/S15.1.2.2_A1_T1.js`<br>...<br>`test262/test/built-ins/parseInt/not-a-constructor.js`<br>`test262/test/built-ins/parseInt/prop-desc.js` |
| staging/sm | NORMAL | 4.596s | 125 | `test262/test/staging/sm/Date/fractions.js`<br>`test262/test/staging/sm/Date/makeday-year-month-is-infinity.js`<br>...<br>`test262/test/staging/sm/Iterator/prototype/find/check-fn-after-getting-iterator.js`<br>`test262/test/staging/sm/Iterator/prototype/find/coerce-result-to-boolean.js` |
| intl402/NumberFormat | NORMAL | 4.172s | 250 | `test262/test/intl402/NumberFormat/builtin.js`<br>`test262/test/intl402/NumberFormat/casing-numbering-system-options.js`<br>...<br>`test262/test/intl402/NumberFormat/throws-for-maximumFractionDigits-over-limit.js`<br>`test262/test/intl402/NumberFormat/throws-for-maximumFractionDigits-under-limit.js` |
| annexB/built-ins/String | NORMAL | 3.763s | 111 | `test262/test/annexB/built-ins/String/prototype/anchor/B.2.3.2.js`<br>`test262/test/annexB/built-ins/String/prototype/anchor/attr-tostring-err.js`<br>...<br>`test262/test/annexB/built-ins/String/prototype/trimRight/prop-desc.js`<br>`test262/test/annexB/built-ins/String/prototype/trimRight/reference-trimEnd.js` |

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
