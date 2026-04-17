# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-04-17T23:15:12+00:00`
- Chunk size: `250`
- Timeout: `300s`
- Jobs: `4`
- Groups: `137`
- Test files: `50506`
- Git: `main` @ `30650b1` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 25532 | 2607 | 18518 | 0 | 3660 | 189 | 28139 | 46657 | 50506 | 90.7% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | INCOMPLETE | 696 | 190 | 6 | 0 | 187 | 0 | 78.6% |
| built-ins | RUNNING | 12517 | 675 | 7233 | 0 | 2178 | 126 | 94.9% |
| harness | PENDING | 0 | 0 | 0 | 0 | 116 | 0 | n/a |
| intl402 | INCOMPLETE | 0 | 970 | 353 | 0 | 243 | 0 | 0.0% |
| language | INCOMPLETE | 11762 | 393 | 10738 | 0 | 491 | 0 | 96.8% |
| staging | RUNNING | 557 | 379 | 188 | 0 | 445 | 63 | 59.5% |

## Group Coverage

| Group | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB/built-ins/Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/Date | PENDING | 0 | 0 | 0 | 0 | 24 | 0 | n/a |
| annexB/built-ins/Function | PENDING | 0 | 0 | 0 | 0 | 6 | 0 | n/a |
| annexB/built-ins/Object | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/RegExp | PENDING | 0 | 0 | 0 | 0 | 62 | 0 | n/a |
| annexB/built-ins/String | PASS | 105 | 0 | 6 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/TypedArrayConstructors | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/escape | PENDING | 0 | 0 | 0 | 0 | 16 | 0 | n/a |
| annexB/built-ins/unescape | PENDING | 0 | 0 | 0 | 0 | 19 | 0 | n/a |
| annexB/language/comments | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| annexB/language/eval-code | PARTIAL | 293 | 176 | 0 | 0 | 0 | 0 | 62.5% |
| annexB/language/expressions | PENDING | 0 | 0 | 0 | 0 | 19 | 0 | n/a |
| annexB/language/function-code | PASS | 159 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/global-code | PARTIAL | 139 | 14 | 0 | 0 | 0 | 0 | 90.8% |
| annexB/language/literals | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| annexB/language/statements | PENDING | 0 | 0 | 0 | 0 | 22 | 0 | n/a |
| built-ins/AbstractModuleSource | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| built-ins/Array | RUNNING | 2238 | 87 | 113 | 0 | 574 | 63 | 96.3% |
| built-ins/ArrayBuffer | PARTIAL | 75 | 5 | 112 | 0 | 0 | 0 | 93.8% |
| built-ins/ArrayIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 27 | 0 | n/a |
| built-ins/AsyncDisposableStack | PENDING | 0 | 0 | 0 | 0 | 52 | 0 | n/a |
| built-ins/AsyncFromSyncIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 38 | 0 | n/a |
| built-ins/AsyncFunction | PENDING | 0 | 0 | 0 | 0 | 18 | 0 | n/a |
| built-ins/AsyncGeneratorFunction | PENDING | 0 | 0 | 0 | 0 | 23 | 0 | n/a |
| built-ins/AsyncGeneratorPrototype | PENDING | 0 | 0 | 0 | 0 | 48 | 0 | n/a |
| built-ins/AsyncIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 10 | 0 | n/a |
| built-ins/Atomics | FAIL | 0 | 6 | 370 | 0 | 0 | 0 | 0.0% |
| built-ins/BigInt | PASS | 74 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/Boolean | PENDING | 0 | 0 | 0 | 0 | 51 | 0 | n/a |
| built-ins/DataView | INCOMPLETE | 389 | 8 | 103 | 0 | 50 | 0 | 98.0% |
| built-ins/Date | PARTIAL | 572 | 11 | 11 | 0 | 0 | 0 | 98.1% |
| built-ins/DisposableStack | PENDING | 0 | 0 | 0 | 0 | 52 | 0 | n/a |
| built-ins/Error | PENDING | 0 | 0 | 0 | 0 | 53 | 0 | n/a |
| built-ins/FinalizationRegistry | PENDING | 0 | 0 | 0 | 0 | 47 | 0 | n/a |
| built-ins/Function | INCOMPLETE | 437 | 13 | 50 | 0 | 9 | 0 | 97.1% |
| built-ins/GeneratorFunction | PENDING | 0 | 0 | 0 | 0 | 23 | 0 | n/a |
| built-ins/GeneratorPrototype | PENDING | 0 | 0 | 0 | 0 | 61 | 0 | n/a |
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
| built-ins/Promise | INCOMPLETE | 105 | 37 | 114 | 0 | 375 | 0 | 73.9% |
| built-ins/Proxy | INCOMPLETE | 210 | 5 | 35 | 0 | 61 | 0 | 97.7% |
| built-ins/Reflect | PARTIAL | 152 | 1 | 0 | 0 | 0 | 0 | 99.3% |
| built-ins/RegExp | RUNNING | 606 | 105 | 969 | 0 | 124 | 63 | 85.2% |
| built-ins/RegExpStringIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 17 | 0 | n/a |
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
| built-ins/WeakRef | PENDING | 0 | 0 | 0 | 0 | 29 | 0 | n/a |
| built-ins/WeakSet | PARTIAL | 76 | 1 | 8 | 0 | 0 | 0 | 98.7% |
| built-ins/decodeURI | PENDING | 0 | 0 | 0 | 0 | 55 | 0 | n/a |
| built-ins/decodeURIComponent | PENDING | 0 | 0 | 0 | 0 | 56 | 0 | n/a |
| built-ins/encodeURI | PENDING | 0 | 0 | 0 | 0 | 31 | 0 | n/a |
| built-ins/encodeURIComponent | PENDING | 0 | 0 | 0 | 0 | 31 | 0 | n/a |
| built-ins/eval | PENDING | 0 | 0 | 0 | 0 | 10 | 0 | n/a |
| built-ins/global | PENDING | 0 | 0 | 0 | 0 | 29 | 0 | n/a |
| built-ins/isFinite | PENDING | 0 | 0 | 0 | 0 | 17 | 0 | n/a |
| built-ins/isNaN | PENDING | 0 | 0 | 0 | 0 | 17 | 0 | n/a |
| built-ins/parseFloat | PENDING | 0 | 0 | 0 | 0 | 59 | 0 | n/a |
| built-ins/parseInt | PENDING | 0 | 0 | 0 | 0 | 60 | 0 | n/a |
| built-ins/undefined | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| harness | PENDING | 0 | 0 | 0 | 0 | 116 | 0 | n/a |
| intl402 | PENDING | 0 | 0 | 0 | 0 | 22 | 0 | n/a |
| intl402/Array | PENDING | 0 | 0 | 0 | 0 | 2 | 0 | n/a |
| intl402/BigInt | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| intl402/Collator | PENDING | 0 | 0 | 0 | 0 | 62 | 0 | n/a |
| intl402/Date | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| intl402/DateTimeFormat | FAIL | 0 | 172 | 16 | 0 | 0 | 0 | 0.0% |
| intl402/DisplayNames | PENDING | 0 | 0 | 0 | 0 | 57 | 0 | n/a |
| intl402/DurationFormat | FAIL | 0 | 104 | 6 | 0 | 0 | 0 | 0.0% |
| intl402/Intl | FAIL | 0 | 65 | 2 | 0 | 0 | 0 | 0.0% |
| intl402/ListFormat | FAIL | 0 | 80 | 1 | 0 | 0 | 0 | 0.0% |
| intl402/Locale | FAIL | 0 | 146 | 1 | 0 | 0 | 0 | 0.0% |
| intl402/Number | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| intl402/NumberFormat | INCOMPLETE | 0 | 249 | 1 | 0 | 2 | 0 | 0.0% |
| intl402/PluralRules | PENDING | 0 | 0 | 0 | 0 | 50 | 0 | n/a |
| intl402/RelativeTimeFormat | FAIL | 0 | 78 | 1 | 0 | 0 | 0 | 0.0% |
| intl402/Segmenter | FAIL | 0 | 76 | 2 | 0 | 0 | 0 | 0.0% |
| intl402/String | PENDING | 0 | 0 | 0 | 0 | 17 | 0 | n/a |
| intl402/Temporal | SKIPPED | 0 | 0 | 323 | 0 | 0 | 0 | n/a |
| intl402/TypedArray | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/arguments-object | INCOMPLETE | 120 | 28 | 102 | 0 | 13 | 0 | 81.1% |
| language/asi | PASS | 102 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/block-scope | PASS | 126 | 0 | 19 | 0 | 0 | 0 | 100.0% |
| language/comments | PENDING | 0 | 0 | 0 | 0 | 52 | 0 | n/a |
| language/computed-property-names | PENDING | 0 | 0 | 0 | 0 | 48 | 0 | n/a |
| language/destructuring | PENDING | 0 | 0 | 0 | 0 | 19 | 0 | n/a |
| language/directive-prologue | PENDING | 0 | 0 | 0 | 0 | 62 | 0 | n/a |
| language/eval-code | PARTIAL | 254 | 40 | 53 | 0 | 0 | 0 | 86.4% |
| language/export | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| language/expressions | INCOMPLETE | 5827 | 100 | 5073 | 0 | 23 | 0 | 98.3% |
| language/function-code | PARTIAL | 214 | 3 | 0 | 0 | 0 | 0 | 98.6% |
| language/future-reserved-words | PENDING | 0 | 0 | 0 | 0 | 55 | 0 | n/a |
| language/global-code | PENDING | 0 | 0 | 0 | 0 | 42 | 0 | n/a |
| language/identifier-resolution | PENDING | 0 | 0 | 0 | 0 | 14 | 0 | n/a |
| language/identifiers | INCOMPLETE | 160 | 34 | 56 | 0 | 10 | 0 | 82.5% |
| language/import | PASS | 6 | 0 | 79 | 0 | 0 | 0 | 100.0% |
| language/keywords | PENDING | 0 | 0 | 0 | 0 | 25 | 0 | n/a |
| language/line-terminators | PENDING | 0 | 0 | 0 | 0 | 41 | 0 | n/a |
| language/literals | INCOMPLETE | 413 | 4 | 83 | 0 | 34 | 0 | 99.0% |
| language/module-code | SKIPPED | 0 | 0 | 583 | 0 | 0 | 0 | n/a |
| language/punctuators | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/reserved-words | PENDING | 0 | 0 | 0 | 0 | 27 | 0 | n/a |
| language/rest-parameters | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/source-text | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/statementList | PARTIAL | 63 | 17 | 0 | 0 | 0 | 0 | 78.8% |
| language/statements | PARTIAL | 4302 | 164 | 4688 | 0 | 0 | 0 | 96.3% |
| language/types | PARTIAL | 108 | 3 | 2 | 0 | 0 | 0 | 97.3% |
| language/white-space | PASS | 67 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/Intl402 | PENDING | 0 | 0 | 0 | 0 | 49 | 0 | n/a |
| staging/Temporal | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| staging/Uint8Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/built-ins | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| staging/decorators | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/explicit-resource-management | PENDING | 0 | 0 | 0 | 0 | 54 | 0 | n/a |
| staging/set-methods | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/sm | RUNNING | 534 | 337 | 182 | 0 | 312 | 63 | 61.3% |
| staging/source-phase-imports | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/upsert | PARTIAL | 23 | 42 | 6 | 0 | 0 | 0 | 35.4% |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| staging/sm | NORMAL | 196.169s | 250 | `test262/test/staging/sm/TypedArray/from_basics.js`<br>`test262/test/staging/sm/TypedArray/from_constructor.js`<br>...<br>`test262/test/staging/sm/expressions/string-literal-escape-sequences.js`<br>`test262/test/staging/sm/expressions/tagged-template-constant-folding.js` |
| language/literals | NORMAL | 56.098s | 250 | `test262/test/language/literals/bigint/binary-invalid-digit.js`<br>`test262/test/language/literals/bigint/exponent-part.js`<br>...<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T2.js` |
| language/literals | NORMAL | 19.206s | 250 | `test262/test/language/literals/regexp/S7.8.5_A2.3_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.3_T2.js`<br>...<br>`test262/test/language/literals/string/S7.8.4_A7.2_T1.js`<br>`test262/test/language/literals/string/S7.8.4_A7.2_T2.js` |
| staging/sm | NORMAL | 16.755s | 125 | `test262/test/staging/sm/Date/fractions.js`<br>`test262/test/staging/sm/Date/makeday-year-month-is-infinity.js`<br>...<br>`test262/test/staging/sm/Iterator/prototype/find/check-fn-after-getting-iterator.js`<br>`test262/test/staging/sm/Iterator/prototype/find/coerce-result-to-boolean.js` |
| staging/sm | NORMAL | 9.941s | 125 | `test262/test/staging/sm/Proxy/ownkeys-allowed-types.js`<br>`test262/test/staging/sm/Proxy/ownkeys-linear.js`<br>...<br>`test262/test/staging/sm/RegExp/unicode-ignoreCase.js`<br>`test262/test/staging/sm/RegExp/unicode-lead-trail.js` |
| built-ins/Function | NORMAL | 8.959s | 250 | `test262/test/built-ins/Function/prototype/apply/S15.3.4.3_A7_T10.js`<br>`test262/test/built-ins/Function/prototype/apply/S15.3.4.3_A7_T2.js`<br>...<br>`test262/test/built-ins/Function/prototype/toString/proxy-function-expression.js`<br>`test262/test/built-ins/Function/prototype/toString/proxy-generator-function.js` |
| staging/sm | NORMAL | 8.782s | 250 | `test262/test/staging/sm/Iterator/prototype/find/descriptor.js`<br>`test262/test/staging/sm/Iterator/prototype/find/error-from-correct-realm.js`<br>...<br>`test262/test/staging/sm/Proxy/hasInstance.js`<br>`test262/test/staging/sm/Proxy/json-stringify-replacer-array-revocable-proxy.js` |
| annexB/built-ins/String | NORMAL | 5.549s | 111 | `test262/test/annexB/built-ins/String/prototype/anchor/B.2.3.2.js`<br>`test262/test/annexB/built-ins/String/prototype/anchor/attr-tostring-err.js`<br>...<br>`test262/test/annexB/built-ins/String/prototype/trimRight/prop-desc.js`<br>`test262/test/annexB/built-ins/String/prototype/trimRight/reference-trimEnd.js` |
| staging/sm | NORMAL | 5.243s | 125 | `test262/test/staging/sm/extensions/8.12.5-01.js`<br>`test262/test/staging/sm/extensions/ArrayBuffer-slice-arguments-detaching.js`<br>...<br>`test262/test/staging/sm/lexical-environment/block-scoped-functions-annex-b-notapplicable.js`<br>`test262/test/staging/sm/lexical-environment/block-scoped-functions-annex-b-parameter.js` |
| staging/sm | NORMAL | 4.434s | 178 | `test262/test/staging/sm/regress/regress-410852.js`<br>`test262/test/staging/sm/regress/regress-428366.js`<br>...<br>`test262/test/staging/sm/template.js`<br>`test262/test/staging/sm/types/8.12.5-01.js` |
| intl402/NumberFormat | NORMAL | 4.375s | 250 | `test262/test/intl402/NumberFormat/builtin.js`<br>`test262/test/intl402/NumberFormat/casing-numbering-system-options.js`<br>...<br>`test262/test/intl402/NumberFormat/throws-for-maximumFractionDigits-over-limit.js`<br>`test262/test/intl402/NumberFormat/throws-for-maximumFractionDigits-under-limit.js` |
| built-ins/Array | NORMAL | 3.974s | 250 | `test262/test/built-ins/Array/of/does-not-use-set-for-indices.js`<br>`test262/test/built-ins/Array/of/length.js`<br>...<br>`test262/test/built-ins/Array/prototype/every/15.4.4.16-5-24.js`<br>`test262/test/built-ins/Array/prototype/every/15.4.4.16-5-3.js` |
| built-ins/TypedArray | NORMAL | 3.936s | 250 | `test262/test/built-ins/TypedArray/Symbol.species/length.js`<br>`test262/test/built-ins/TypedArray/Symbol.species/name.js`<br>...<br>`test262/test/built-ins/TypedArray/prototype/every/values-are-not-cached.js`<br>`test262/test/built-ins/TypedArray/prototype/fill/BigInt/coerced-indexes.js` |
| built-ins/Math | NORMAL | 3.725s | 250 | `test262/test/built-ins/Math/E/prop-desc.js`<br>`test262/test/built-ins/Math/E/value.js`<br>...<br>`test262/test/built-ins/Math/random/length.js`<br>`test262/test/built-ins/Math/random/name.js` |
| built-ins/TypedArray | NORMAL | 3.530s | 250 | `test262/test/built-ins/TypedArray/prototype/set/BigInt/array-arg-targetbuffer-detached-throws.js`<br>`test262/test/built-ins/TypedArray/prototype/set/BigInt/bigint-tobigint64.js`<br>...<br>`test262/test/built-ins/TypedArray/prototype/sort/prop-desc.js`<br>`test262/test/built-ins/TypedArray/prototype/sort/resizable-buffer-default-comparator.js` |
| built-ins/TypedArray | NORMAL | 3.518s | 250 | `test262/test/built-ins/TypedArray/prototype/fill/BigInt/detached-buffer.js`<br>`test262/test/built-ins/TypedArray/prototype/fill/BigInt/fill-values-conversion-once.js`<br>...<br>`test262/test/built-ins/TypedArray/prototype/findLastIndex/BigInt/detached-buffer.js`<br>`test262/test/built-ins/TypedArray/prototype/findLastIndex/BigInt/get-length-ignores-length-prop.js` |
| intl402/DurationFormat | NORMAL | 3.504s | 110 | `test262/test/intl402/DurationFormat/constructor-locales-invalid.js`<br>`test262/test/intl402/DurationFormat/constructor-locales-valid.js`<br>...<br>`test262/test/intl402/DurationFormat/supportedLocalesOf/name.js`<br>`test262/test/intl402/DurationFormat/supportedLocalesOf/prop-desc.js` |
| built-ins/TypedArrayConstructors | NORMAL | 3.333s | 250 | `test262/test/built-ins/TypedArrayConstructors/ctors/buffer-arg/custom-proto-access-throws.js`<br>`test262/test/built-ins/TypedArrayConstructors/ctors/buffer-arg/defined-length-and-offset-sab.js`<br>...<br>`test262/test/built-ins/TypedArrayConstructors/internals/Delete/key-is-not-numeric-index-non-strict.js`<br>`test262/test/built-ins/TypedArrayConstructors/internals/Delete/key-is-not-numeric-index-strict.js` |
| intl402/Intl | NORMAL | 3.307s | 67 | `test262/test/intl402/Intl/DateTimeFormat/prototype/formatRange/fails-on-distinct-temporal-types.js`<br>`test262/test/intl402/Intl/DateTimeFormat/prototype/formatRangeToParts/fails-on-distinct-temporal-types.js`<br>...<br>`test262/test/intl402/Intl/toStringTag/toString.js`<br>`test262/test/intl402/Intl/toStringTag/toStringTag.js` |
| built-ins/TypedArray | NORMAL | 3.279s | 250 | `test262/test/built-ins/TypedArray/prototype/lastIndexOf/return-abrupt-tointeger-fromindex-symbol.js`<br>`test262/test/built-ins/TypedArray/prototype/lastIndexOf/return-abrupt-tointeger-fromindex.js`<br>...<br>`test262/test/built-ins/TypedArray/prototype/set/BigInt/array-arg-target-arraylength-internal.js`<br>`test262/test/built-ins/TypedArray/prototype/set/BigInt/array-arg-targetbuffer-detached-on-tointeger-offset-throws.js` |

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
