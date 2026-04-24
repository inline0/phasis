# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-04-24T11:46:40+00:00`
- Chunk size: `250`
- Timeout: `300s`
- Jobs: `8`
- Groups: `137`
- Test files: `50506`
- Git: `main` @ `4f82f98` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 47541 | 2331 | 6 | 0 | 614 | 14 | 49872 | 49878 | 50506 | 95.3% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | INCOMPLETE | 1064 | 12 | 0 | 0 | 3 | 0 | 98.9% |
| built-ins | RUNNING | 21425 | 757 | 6 | 0 | 527 | 14 | 96.6% |
| harness | PARTIAL | 112 | 4 | 0 | 0 | 0 | 0 | 96.6% |
| intl402 | INCOMPLETE | 683 | 882 | 0 | 0 | 1 | 0 | 43.6% |
| language | INCOMPLETE | 23020 | 322 | 0 | 0 | 42 | 0 | 98.6% |
| staging | INCOMPLETE | 1237 | 354 | 0 | 0 | 41 | 0 | 77.7% |

## Group Coverage

| Group | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB/built-ins/Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/Date | PASS | 24 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Function | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Object | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/RegExp | PARTIAL | 54 | 8 | 0 | 0 | 0 | 0 | 87.1% |
| annexB/built-ins/String | PASS | 111 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/TypedArrayConstructors | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/escape | PASS | 16 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/unescape | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/comments | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/eval-code | PASS | 469 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/expressions | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/function-code | PASS | 159 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/global-code | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/literals | PARTIAL | 4 | 4 | 0 | 0 | 0 | 0 | 50.0% |
| annexB/language/statements | PASS | 22 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AbstractModuleSource | FAIL | 0 | 8 | 0 | 0 | 0 | 0 | 0.0% |
| built-ins/Array | INCOMPLETE | 2943 | 99 | 0 | 0 | 33 | 0 | 96.7% |
| built-ins/ArrayBuffer | PARTIAL | 191 | 1 | 0 | 0 | 0 | 0 | 99.5% |
| built-ins/ArrayIteratorPrototype | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncDisposableStack | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncFromSyncIteratorPrototype | PARTIAL | 35 | 3 | 0 | 0 | 0 | 0 | 92.1% |
| built-ins/AsyncFunction | PASS | 18 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorFunction | PARTIAL | 22 | 1 | 0 | 0 | 0 | 0 | 95.7% |
| built-ins/AsyncGeneratorPrototype | PARTIAL | 45 | 3 | 0 | 0 | 0 | 0 | 93.8% |
| built-ins/AsyncIteratorPrototype | PASS | 10 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Atomics | INCOMPLETE | 276 | 66 | 6 | 0 | 28 | 0 | 80.7% |
| built-ins/BigInt | PASS | 75 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Boolean | PASS | 51 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/DataView | PARTIAL | 547 | 3 | 0 | 0 | 0 | 0 | 99.5% |
| built-ins/Date | PARTIAL | 593 | 1 | 0 | 0 | 0 | 0 | 99.8% |
| built-ins/DisposableStack | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Error | PASS | 53 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/FinalizationRegistry | PASS | 47 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Function | PARTIAL | 499 | 10 | 0 | 0 | 0 | 0 | 98.0% |
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
| built-ins/Promise | INCOMPLETE | 489 | 118 | 0 | 0 | 24 | 0 | 80.6% |
| built-ins/Proxy | PARTIAL | 304 | 7 | 0 | 0 | 0 | 0 | 97.7% |
| built-ins/Reflect | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp | RUNNING | 1035 | 382 | 0 | 0 | 436 | 14 | 73.0% |
| built-ins/RegExpStringIteratorPrototype | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Set | PASS | 381 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/SetIteratorPrototype | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ShadowRealm | PARTIAL | 52 | 12 | 0 | 0 | 0 | 0 | 81.3% |
| built-ins/SharedArrayBuffer | PASS | 104 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/String | PARTIAL | 1205 | 7 | 0 | 0 | 0 | 0 | 99.4% |
| built-ins/StringIteratorPrototype | PASS | 7 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Symbol | PARTIAL | 92 | 2 | 0 | 0 | 0 | 0 | 97.9% |
| built-ins/Temporal | PASS | 4165 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ThrowTypeError | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/TypedArray | PARTIAL | 1399 | 27 | 0 | 0 | 0 | 0 | 98.1% |
| built-ins/TypedArrayConstructors | PARTIAL | 732 | 4 | 0 | 0 | 0 | 0 | 99.5% |
| built-ins/Uint8Array | PARTIAL | 63 | 1 | 0 | 0 | 0 | 0 | 98.4% |
| built-ins/WeakMap | PASS | 102 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakRef | PASS | 29 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakSet | PASS | 85 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/decodeURI | INCOMPLETE | 52 | 0 | 0 | 0 | 3 | 0 | 100.0% |
| built-ins/decodeURIComponent | INCOMPLETE | 53 | 0 | 0 | 0 | 3 | 0 | 100.0% |
| built-ins/encodeURI | PASS | 31 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/encodeURIComponent | PASS | 31 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/eval | PASS | 10 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/global | PASS | 29 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isFinite | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isNaN | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseFloat | PASS | 59 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseInt | PASS | 60 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/undefined | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| harness | PARTIAL | 112 | 4 | 0 | 0 | 0 | 0 | 96.6% |
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
| intl402/TypedArray | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/arguments-object | PASS | 263 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/asi | PASS | 102 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/block-scope | PASS | 145 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/comments | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/computed-property-names | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/destructuring | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/directive-prologue | PASS | 62 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/eval-code | PARTIAL | 346 | 1 | 0 | 0 | 0 | 0 | 99.7% |
| language/export | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/expressions | INCOMPLETE | 10782 | 200 | 0 | 0 | 41 | 0 | 98.2% |
| language/function-code | PASS | 217 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/future-reserved-words | PASS | 55 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/global-code | PASS | 42 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifier-resolution | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifiers | PASS | 260 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/import | PARTIAL | 19 | 66 | 0 | 0 | 0 | 0 | 22.4% |
| language/keywords | PASS | 25 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/line-terminators | PASS | 41 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/literals | PARTIAL | 527 | 7 | 0 | 0 | 0 | 0 | 98.7% |
| language/module-code | PARTIAL | 555 | 28 | 0 | 0 | 0 | 0 | 95.2% |
| language/punctuators | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/reserved-words | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/rest-parameters | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/source-text | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/statementList | PASS | 80 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/statements | PARTIAL | 9136 | 18 | 0 | 0 | 0 | 0 | 99.8% |
| language/types | PARTIAL | 111 | 2 | 0 | 0 | 0 | 0 | 98.2% |
| language/white-space | PASS | 67 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Intl402 | PARTIAL | 2 | 47 | 0 | 0 | 0 | 0 | 4.1% |
| staging/Temporal | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Uint8Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/built-ins | PARTIAL | 3 | 4 | 0 | 0 | 0 | 0 | 42.9% |
| staging/decorators | PARTIAL | 2 | 1 | 0 | 0 | 0 | 0 | 66.7% |
| staging/explicit-resource-management | PARTIAL | 51 | 3 | 0 | 0 | 0 | 0 | 94.4% |
| staging/set-methods | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/sm | INCOMPLETE | 1090 | 299 | 0 | 0 | 39 | 0 | 78.5% |
| staging/source-phase-imports | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/upsert | PASS | 71 | 0 | 0 | 0 | 0 | 0 | 100.0% |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| language/literals | NORMAL | 67.305s | 250 | `test262/test/language/literals/bigint/binary-invalid-digit.js`<br>`test262/test/language/literals/bigint/exponent-part.js`<br>...<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T2.js` |
| built-ins/decodeURIComponent | NORMAL | 50.783s | 28 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.10_T1.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.11_T1.js`<br>...<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.7_T1.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.8_T1.js` |
| built-ins/decodeURI | NORMAL | 50.277s | 27 | `test262/test/built-ins/decodeURI/S15.1.3.1_A1.10_T1.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.11_T1.js`<br>...<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.6_T1.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.7_T1.js` |
| annexB/built-ins/RegExp | NORMAL | 47.099s | 62 | `test262/test/annexB/built-ins/RegExp/RegExp-control-escape-russian-letter.js`<br>`test262/test/annexB/built-ins/RegExp/RegExp-decimal-escape-class-range.js`<br>...<br>`test262/test/annexB/built-ins/RegExp/prototype/compile/this-subclass-instance.js`<br>`test262/test/annexB/built-ins/RegExp/prototype/flags/order-after-compile.js` |
| built-ins/Function | NORMAL | 35.969s | 250 | `test262/test/built-ins/Function/prototype/apply/S15.3.4.3_A7_T10.js`<br>`test262/test/built-ins/Function/prototype/apply/S15.3.4.3_A7_T2.js`<br>...<br>`test262/test/built-ins/Function/prototype/toString/proxy-function-expression.js`<br>`test262/test/built-ins/Function/prototype/toString/proxy-generator-function.js` |
| built-ins/Atomics | NORMAL | 30.186s | 62 | `test262/test/built-ins/Atomics/notify/null-bufferdata-throws.js`<br>`test262/test/built-ins/Atomics/notify/out-of-range-index-throws.js`<br>...<br>`test262/test/built-ins/Atomics/wait/bad-range.js`<br>`test262/test/built-ins/Atomics/wait/bigint/bad-range.js` |
| staging/sm | NORMAL | 28.272s | 2 | `test262/test/staging/sm/TypedArray/sort_small.js`<br>`test262/test/staging/sm/TypedArray/sort_snans.js` |
| staging/sm | NORMAL | 27.489s | 16 | `test262/test/staging/sm/String/split-undefined-separator.js`<br>`test262/test/staging/sm/String/split-xregexp.js`<br>...<br>`test262/test/staging/sm/Symbol/enumeration-order.js`<br>`test262/test/staging/sm/Symbol/enumeration.js` |
| language/literals | NORMAL | 26.363s | 250 | `test262/test/language/literals/regexp/S7.8.5_A2.3_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.3_T2.js`<br>...<br>`test262/test/language/literals/string/S7.8.4_A7.2_T1.js`<br>`test262/test/language/literals/string/S7.8.4_A7.2_T2.js` |
| built-ins/encodeURI | NORMAL | 26.134s | 31 | `test262/test/built-ins/encodeURI/S15.1.3.3_A1.1_T1.js`<br>`test262/test/built-ins/encodeURI/S15.1.3.3_A1.1_T2.js`<br>...<br>`test262/test/built-ins/encodeURI/not-a-constructor.js`<br>`test262/test/built-ins/encodeURI/prop-desc.js` |
| built-ins/encodeURIComponent | NORMAL | 25.978s | 31 | `test262/test/built-ins/encodeURIComponent/S15.1.3.4_A1.1_T1.js`<br>`test262/test/built-ins/encodeURIComponent/S15.1.3.4_A1.1_T2.js`<br>...<br>`test262/test/built-ins/encodeURIComponent/not-a-constructor.js`<br>`test262/test/built-ins/encodeURIComponent/prop-desc.js` |
| staging/sm | NORMAL | 24.982s | 250 | `test262/test/staging/sm/Iterator/prototype/find/descriptor.js`<br>`test262/test/staging/sm/Iterator/prototype/find/error-from-correct-realm.js`<br>...<br>`test262/test/staging/sm/Proxy/hasInstance.js`<br>`test262/test/staging/sm/Proxy/json-stringify-replacer-array-revocable-proxy.js` |
| language/expressions | NORMAL | 23.592s | 250 | `test262/test/language/expressions/async-generator/yield-star-next-not-callable-symbol-throw.js`<br>`test262/test/language/expressions/async-generator/yield-star-next-not-callable-undefined-throw.js`<br>...<br>`test262/test/language/expressions/class/accessor-name-inst/literal-numeric-zero.js`<br>`test262/test/language/expressions/class/accessor-name-inst/literal-string-char-escape.js` |
| language/identifiers | NORMAL | 20.964s | 250 | `test262/test/language/identifiers/other_id_continue-escaped.js`<br>`test262/test/language/identifiers/other_id_continue.js`<br>...<br>`test262/test/language/identifiers/vals-eng-alpha-upper-via-escape-hex4.js`<br>`test262/test/language/identifiers/vals-eng-alpha-upper.js` |
| language/comments | NORMAL | 20.401s | 52 | `test262/test/language/comments/S7.4_A1_T1.js`<br>`test262/test/language/comments/S7.4_A1_T2.js`<br>...<br>`test262/test/language/comments/multi-line-html-close-extra.js`<br>`test262/test/language/comments/single-line-html-close-without-lt.js` |
| language/statements | NORMAL | 19.756s | 250 | `test262/test/language/statements/for/dstr/let-ary-ptrn-elem-ary-empty-iter.js`<br>`test262/test/language/statements/for/dstr/let-ary-ptrn-elem-ary-rest-init.js`<br>...<br>`test262/test/language/statements/function/13.2-18-1.js`<br>`test262/test/language/statements/function/13.2-18-s.js` |
| staging/sm | NORMAL | 19.229s | 125 | `test262/test/staging/sm/Date/fractions.js`<br>`test262/test/staging/sm/Date/makeday-year-month-is-infinity.js`<br>...<br>`test262/test/staging/sm/Iterator/prototype/find/check-fn-after-getting-iterator.js`<br>`test262/test/staging/sm/Iterator/prototype/find/coerce-result-to-boolean.js` |
| built-ins/TypedArray | NORMAL | 18.593s | 250 | `test262/test/built-ins/TypedArray/prototype/set/BigInt/array-arg-targetbuffer-detached-throws.js`<br>`test262/test/built-ins/TypedArray/prototype/set/BigInt/bigint-tobigint64.js`<br>...<br>`test262/test/built-ins/TypedArray/prototype/sort/prop-desc.js`<br>`test262/test/built-ins/TypedArray/prototype/sort/resizable-buffer-default-comparator.js` |
| staging/sm | NORMAL | 17.333s | 8 | `test262/test/staging/sm/TypedArray/sort_sorted.js`<br>`test262/test/staging/sm/TypedArray/sort_stable.js`<br>`test262/test/staging/sm/TypedArray/sorting_buffer_access.js`<br>`test262/test/staging/sm/TypedArray/subarray-species.js`<br>`test262/test/staging/sm/TypedArray/subarray.js`<br>`test262/test/staging/sm/TypedArray/test-integrity-level-detached.js`<br>`test262/test/staging/sm/TypedArray/test-integrity-level.js`<br>`test262/test/staging/sm/TypedArray/toLocaleString-detached.js` |
| language/statements | NORMAL | 17.024s | 250 | `test262/test/language/statements/try/scope-catch-param-var-none.js`<br>`test262/test/language/statements/try/static-init-await-binding-invalid.js`<br>...<br>`test262/test/language/statements/with/S12.10_A1.10_T5.js`<br>`test262/test/language/statements/with/S12.10_A1.11_T1.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are executed as ES modules (always strict, import/export processed).
- Tests flagged as async are skipped by the current runner.
- Mixed-mode tests currently run only in sloppy mode unless onlyStrict or noStrict is set.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

