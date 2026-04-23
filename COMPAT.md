# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-04-23T20:45:06+00:00`
- Chunk size: `250`
- Timeout: `300s`
- Jobs: `4`
- Groups: `137`
- Test files: `50506`
- Git: `main` @ `1539b2d` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 40894 | 1200 | 0 | 0 | 8037 | 375 | 42094 | 42094 | 50506 | 97.1% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | INCOMPLETE | 781 | 0 | 0 | 0 | 298 | 0 | 100.0% |
| built-ins | RUNNING | 17744 | 343 | 0 | 0 | 4267 | 375 | 98.1% |
| harness | PENDING | 0 | 0 | 0 | 0 | 116 | 0 | n/a |
| intl402 | INCOMPLETE | 332 | 503 | 0 | 0 | 731 | 0 | 39.8% |
| language | INCOMPLETE | 21624 | 267 | 0 | 0 | 1493 | 0 | 98.8% |
| staging | INCOMPLETE | 413 | 87 | 0 | 0 | 1132 | 0 | 82.6% |

## Group Coverage

| Group | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB/built-ins/Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/Date | PENDING | 0 | 0 | 0 | 0 | 24 | 0 | n/a |
| annexB/built-ins/Function | PENDING | 0 | 0 | 0 | 0 | 6 | 0 | n/a |
| annexB/built-ins/Object | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/RegExp | PENDING | 0 | 0 | 0 | 0 | 62 | 0 | n/a |
| annexB/built-ins/String | PENDING | 0 | 0 | 0 | 0 | 111 | 0 | n/a |
| annexB/built-ins/TypedArrayConstructors | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/escape | PENDING | 0 | 0 | 0 | 0 | 16 | 0 | n/a |
| annexB/built-ins/unescape | PENDING | 0 | 0 | 0 | 0 | 19 | 0 | n/a |
| annexB/language/comments | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| annexB/language/eval-code | PASS | 469 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/expressions | PENDING | 0 | 0 | 0 | 0 | 19 | 0 | n/a |
| annexB/language/function-code | PASS | 159 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/global-code | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/literals | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| annexB/language/statements | PENDING | 0 | 0 | 0 | 0 | 22 | 0 | n/a |
| built-ins/AbstractModuleSource | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| built-ins/Array | INCOMPLETE | 2065 | 60 | 0 | 0 | 950 | 0 | 97.2% |
| built-ins/ArrayBuffer | PARTIAL | 191 | 1 | 0 | 0 | 0 | 0 | 99.5% |
| built-ins/ArrayIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 27 | 0 | n/a |
| built-ins/AsyncDisposableStack | PENDING | 0 | 0 | 0 | 0 | 52 | 0 | n/a |
| built-ins/AsyncFromSyncIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 38 | 0 | n/a |
| built-ins/AsyncFunction | PENDING | 0 | 0 | 0 | 0 | 18 | 0 | n/a |
| built-ins/AsyncGeneratorFunction | PENDING | 0 | 0 | 0 | 0 | 23 | 0 | n/a |
| built-ins/AsyncGeneratorPrototype | PENDING | 0 | 0 | 0 | 0 | 48 | 0 | n/a |
| built-ins/AsyncIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 10 | 0 | n/a |
| built-ins/Atomics | PENDING | 0 | 0 | 0 | 0 | 376 | 0 | n/a |
| built-ins/BigInt | PENDING | 0 | 0 | 0 | 0 | 75 | 0 | n/a |
| built-ins/Boolean | PENDING | 0 | 0 | 0 | 0 | 51 | 0 | n/a |
| built-ins/DataView | INCOMPLETE | 496 | 4 | 0 | 0 | 50 | 0 | 99.2% |
| built-ins/Date | INCOMPLETE | 500 | 0 | 0 | 0 | 94 | 0 | 100.0% |
| built-ins/DisposableStack | PENDING | 0 | 0 | 0 | 0 | 52 | 0 | n/a |
| built-ins/Error | PENDING | 0 | 0 | 0 | 0 | 53 | 0 | n/a |
| built-ins/FinalizationRegistry | PENDING | 0 | 0 | 0 | 0 | 47 | 0 | n/a |
| built-ins/Function | INCOMPLETE | 490 | 10 | 0 | 0 | 9 | 0 | 98.0% |
| built-ins/GeneratorFunction | PENDING | 0 | 0 | 0 | 0 | 23 | 0 | n/a |
| built-ins/GeneratorPrototype | PENDING | 0 | 0 | 0 | 0 | 61 | 0 | n/a |
| built-ins/Infinity | PENDING | 0 | 0 | 0 | 0 | 6 | 0 | n/a |
| built-ins/Iterator | PARTIAL | 430 | 1 | 0 | 0 | 0 | 0 | 99.8% |
| built-ins/JSON | PARTIAL | 164 | 1 | 0 | 0 | 0 | 0 | 99.4% |
| built-ins/Map | PASS | 171 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/MapIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| built-ins/Math | INCOMPLETE | 250 | 0 | 0 | 0 | 77 | 0 | 100.0% |
| built-ins/NaN | PENDING | 0 | 0 | 0 | 0 | 6 | 0 | n/a |
| built-ins/NativeErrors | PARTIAL | 137 | 2 | 0 | 0 | 0 | 0 | 98.6% |
| built-ins/Number | INCOMPLETE | 250 | 0 | 0 | 0 | 85 | 0 | 100.0% |
| built-ins/Object | PARTIAL | 3409 | 1 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Promise | INCOMPLETE | 175 | 81 | 0 | 0 | 375 | 0 | 68.4% |
| built-ins/Proxy | INCOMPLETE | 245 | 5 | 0 | 0 | 61 | 0 | 98.0% |
| built-ins/Reflect | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp | RUNNING | 761 | 114 | 0 | 0 | 617 | 375 | 87.0% |
| built-ins/RegExpStringIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 17 | 0 | n/a |
| built-ins/Set | PASS | 381 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/SetIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| built-ins/ShadowRealm | PENDING | 0 | 0 | 0 | 0 | 64 | 0 | n/a |
| built-ins/SharedArrayBuffer | PENDING | 0 | 0 | 0 | 0 | 104 | 0 | n/a |
| built-ins/String | PARTIAL | 1205 | 7 | 0 | 0 | 0 | 0 | 99.4% |
| built-ins/StringIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| built-ins/Symbol | PENDING | 0 | 0 | 0 | 0 | 94 | 0 | n/a |
| built-ins/Temporal | PASS | 4165 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ThrowTypeError | PENDING | 0 | 0 | 0 | 0 | 14 | 0 | n/a |
| built-ins/TypedArray | PARTIAL | 1383 | 43 | 0 | 0 | 0 | 0 | 97.0% |
| built-ins/TypedArrayConstructors | PARTIAL | 723 | 13 | 0 | 0 | 0 | 0 | 98.2% |
| built-ins/Uint8Array | PENDING | 0 | 0 | 0 | 0 | 64 | 0 | n/a |
| built-ins/WeakMap | PENDING | 0 | 0 | 0 | 0 | 102 | 0 | n/a |
| built-ins/WeakRef | PENDING | 0 | 0 | 0 | 0 | 29 | 0 | n/a |
| built-ins/WeakSet | PENDING | 0 | 0 | 0 | 0 | 85 | 0 | n/a |
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
| intl402/DateTimeFormat | PARTIAL | 73 | 115 | 0 | 0 | 0 | 0 | 38.8% |
| intl402/DisplayNames | PENDING | 0 | 0 | 0 | 0 | 57 | 0 | n/a |
| intl402/DurationFormat | PENDING | 0 | 0 | 0 | 0 | 110 | 0 | n/a |
| intl402/Intl | PENDING | 0 | 0 | 0 | 0 | 67 | 0 | n/a |
| intl402/ListFormat | PENDING | 0 | 0 | 0 | 0 | 81 | 0 | n/a |
| intl402/Locale | PARTIAL | 81 | 66 | 0 | 0 | 0 | 0 | 55.1% |
| intl402/Number | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| intl402/NumberFormat | INCOMPLETE | 103 | 147 | 0 | 0 | 2 | 0 | 41.2% |
| intl402/PluralRules | PENDING | 0 | 0 | 0 | 0 | 50 | 0 | n/a |
| intl402/RelativeTimeFormat | PENDING | 0 | 0 | 0 | 0 | 79 | 0 | n/a |
| intl402/Segmenter | PENDING | 0 | 0 | 0 | 0 | 78 | 0 | n/a |
| intl402/String | PENDING | 0 | 0 | 0 | 0 | 17 | 0 | n/a |
| intl402/Temporal | INCOMPLETE | 75 | 175 | 0 | 0 | 73 | 0 | 30.0% |
| intl402/TypedArray | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/arguments-object | INCOMPLETE | 250 | 0 | 0 | 0 | 13 | 0 | 100.0% |
| language/asi | PENDING | 0 | 0 | 0 | 0 | 102 | 0 | n/a |
| language/block-scope | PASS | 145 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/comments | PENDING | 0 | 0 | 0 | 0 | 52 | 0 | n/a |
| language/computed-property-names | PENDING | 0 | 0 | 0 | 0 | 48 | 0 | n/a |
| language/destructuring | PENDING | 0 | 0 | 0 | 0 | 19 | 0 | n/a |
| language/directive-prologue | PENDING | 0 | 0 | 0 | 0 | 62 | 0 | n/a |
| language/eval-code | INCOMPLETE | 250 | 0 | 0 | 0 | 97 | 0 | 100.0% |
| language/export | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| language/expressions | INCOMPLETE | 10492 | 133 | 0 | 0 | 398 | 0 | 98.7% |
| language/function-code | PASS | 217 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/future-reserved-words | PENDING | 0 | 0 | 0 | 0 | 55 | 0 | n/a |
| language/global-code | PENDING | 0 | 0 | 0 | 0 | 42 | 0 | n/a |
| language/identifier-resolution | PENDING | 0 | 0 | 0 | 0 | 14 | 0 | n/a |
| language/identifiers | INCOMPLETE | 250 | 0 | 0 | 0 | 10 | 0 | 100.0% |
| language/import | PENDING | 0 | 0 | 0 | 0 | 85 | 0 | n/a |
| language/keywords | PENDING | 0 | 0 | 0 | 0 | 25 | 0 | n/a |
| language/line-terminators | PENDING | 0 | 0 | 0 | 0 | 41 | 0 | n/a |
| language/literals | INCOMPLETE | 497 | 3 | 0 | 0 | 34 | 0 | 99.4% |
| language/module-code | INCOMPLETE | 386 | 114 | 0 | 0 | 83 | 0 | 77.2% |
| language/punctuators | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/reserved-words | PENDING | 0 | 0 | 0 | 0 | 27 | 0 | n/a |
| language/rest-parameters | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/source-text | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/statementList | PENDING | 0 | 0 | 0 | 0 | 80 | 0 | n/a |
| language/statements | PARTIAL | 9137 | 17 | 0 | 0 | 0 | 0 | 99.8% |
| language/types | PENDING | 0 | 0 | 0 | 0 | 113 | 0 | n/a |
| language/white-space | PENDING | 0 | 0 | 0 | 0 | 67 | 0 | n/a |
| staging | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/Intl402 | PENDING | 0 | 0 | 0 | 0 | 49 | 0 | n/a |
| staging/Temporal | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| staging/Uint8Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/built-ins | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| staging/decorators | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/explicit-resource-management | PENDING | 0 | 0 | 0 | 0 | 54 | 0 | n/a |
| staging/set-methods | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/sm | INCOMPLETE | 413 | 87 | 0 | 0 | 928 | 0 | 82.6% |
| staging/source-phase-imports | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/upsert | PENDING | 0 | 0 | 0 | 0 | 71 | 0 | n/a |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| language/literals | NORMAL | 51.492s | 250 | `test262/test/language/literals/bigint/binary-invalid-digit.js`<br>`test262/test/language/literals/bigint/exponent-part.js`<br>...<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T2.js` |
| built-ins/Function | NORMAL | 25.426s | 250 | `test262/test/built-ins/Function/prototype/apply/S15.3.4.3_A7_T10.js`<br>`test262/test/built-ins/Function/prototype/apply/S15.3.4.3_A7_T2.js`<br>...<br>`test262/test/built-ins/Function/prototype/toString/proxy-function-expression.js`<br>`test262/test/built-ins/Function/prototype/toString/proxy-generator-function.js` |
| language/literals | NORMAL | 19.550s | 250 | `test262/test/language/literals/regexp/S7.8.5_A2.3_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.3_T2.js`<br>...<br>`test262/test/language/literals/string/S7.8.4_A7.2_T1.js`<br>`test262/test/language/literals/string/S7.8.4_A7.2_T2.js` |
| language/expressions | NORMAL | 17.933s | 250 | `test262/test/language/expressions/async-generator/yield-star-next-not-callable-symbol-throw.js`<br>`test262/test/language/expressions/async-generator/yield-star-next-not-callable-undefined-throw.js`<br>...<br>`test262/test/language/expressions/class/accessor-name-inst/literal-numeric-zero.js`<br>`test262/test/language/expressions/class/accessor-name-inst/literal-string-char-escape.js` |
| staging/sm | NORMAL | 17.896s | 250 | `test262/test/staging/sm/Iterator/prototype/find/descriptor.js`<br>`test262/test/staging/sm/Iterator/prototype/find/error-from-correct-realm.js`<br>...<br>`test262/test/staging/sm/Proxy/hasInstance.js`<br>`test262/test/staging/sm/Proxy/json-stringify-replacer-array-revocable-proxy.js` |
| language/statements | NORMAL | 13.905s | 250 | `test262/test/language/statements/for/dstr/let-ary-ptrn-elem-ary-empty-iter.js`<br>`test262/test/language/statements/for/dstr/let-ary-ptrn-elem-ary-rest-init.js`<br>...<br>`test262/test/language/statements/function/13.2-18-1.js`<br>`test262/test/language/statements/function/13.2-18-s.js` |
| built-ins/TypedArray | NORMAL | 13.439s | 250 | `test262/test/built-ins/TypedArray/prototype/set/BigInt/array-arg-targetbuffer-detached-throws.js`<br>`test262/test/built-ins/TypedArray/prototype/set/BigInt/bigint-tobigint64.js`<br>...<br>`test262/test/built-ins/TypedArray/prototype/sort/prop-desc.js`<br>`test262/test/built-ins/TypedArray/prototype/sort/resizable-buffer-default-comparator.js` |
| staging/sm | NORMAL | 12.663s | 125 | `test262/test/staging/sm/Date/fractions.js`<br>`test262/test/staging/sm/Date/makeday-year-month-is-infinity.js`<br>...<br>`test262/test/staging/sm/Iterator/prototype/find/check-fn-after-getting-iterator.js`<br>`test262/test/staging/sm/Iterator/prototype/find/coerce-result-to-boolean.js` |
| language/statements | NORMAL | 12.013s | 250 | `test262/test/language/statements/try/scope-catch-param-var-none.js`<br>`test262/test/language/statements/try/static-init-await-binding-invalid.js`<br>...<br>`test262/test/language/statements/with/S12.10_A1.10_T5.js`<br>`test262/test/language/statements/with/S12.10_A1.11_T1.js` |
| built-ins/TypedArray | NORMAL | 10.656s | 250 | `test262/test/built-ins/TypedArray/Symbol.species/length.js`<br>`test262/test/built-ins/TypedArray/Symbol.species/name.js`<br>...<br>`test262/test/built-ins/TypedArray/prototype/every/values-are-not-cached.js`<br>`test262/test/built-ins/TypedArray/prototype/fill/BigInt/coerced-indexes.js` |
| language/expressions | NORMAL | 10.408s | 250 | `test262/test/language/expressions/super/prop-expr-getsuperbase-before-topropertykey-putvalue.js`<br>`test262/test/language/expressions/super/prop-expr-obj-err.js`<br>...<br>`test262/test/language/expressions/yield/star-rhs-iter-rtrn-res-done-no-value.js`<br>`test262/test/language/expressions/yield/star-rhs-iter-rtrn-res-value-err.js` |
| language/statements | NORMAL | 9.408s | 250 | `test262/test/language/statements/switch/syntax/redeclaration/const-name-redeclaration-attempt-with-class.js`<br>`test262/test/language/statements/switch/syntax/redeclaration/const-name-redeclaration-attempt-with-const.js`<br>...<br>`test262/test/language/statements/try/scope-catch-param-lex-close.js`<br>`test262/test/language/statements/try/scope-catch-param-lex-open.js` |
| built-ins/Temporal | NORMAL | 8.386s | 250 | `test262/test/built-ins/Temporal/PlainTime/from/options-wrong-type.js`<br>`test262/test/built-ins/Temporal/PlainTime/from/order-of-operations.js`<br>...<br>`test262/test/built-ins/Temporal/PlainTime/prototype/toString/basic.js`<br>`test262/test/built-ins/Temporal/PlainTime/prototype/toString/branding.js` |
| built-ins/Temporal | NORMAL | 7.861s | 250 | `test262/test/built-ins/Temporal/Duration/basic.js`<br>`test262/test/built-ins/Temporal/Duration/builtin.js`<br>...<br>`test262/test/built-ins/Temporal/Duration/prototype/round/roundingmode-floor.js`<br>`test262/test/built-ins/Temporal/Duration/prototype/round/roundingmode-halfCeil.js` |
| built-ins/Temporal | NORMAL | 7.628s | 250 | `test262/test/built-ins/Temporal/ZonedDateTime/prototype/since/not-a-constructor.js`<br>`test262/test/built-ins/Temporal/ZonedDateTime/prototype/since/options-object.js`<br>...<br>`test262/test/built-ins/Temporal/ZonedDateTime/prototype/until/not-a-constructor.js`<br>`test262/test/built-ins/Temporal/ZonedDateTime/prototype/until/options-object.js` |
| built-ins/Temporal | NORMAL | 7.583s | 250 | `test262/test/built-ins/Temporal/PlainDate/prototype/since/rounding-relative.js`<br>`test262/test/built-ins/Temporal/PlainDate/prototype/since/roundingincrement-nan.js`<br>...<br>`test262/test/built-ins/Temporal/PlainDate/prototype/until/roundingmode-halfExpand.js`<br>`test262/test/built-ins/Temporal/PlainDate/prototype/until/roundingmode-halfFloor.js` |
| built-ins/Temporal | NORMAL | 7.562s | 250 | `test262/test/built-ins/Temporal/PlainDateTime/prototype/equals/argument-string-critical-unknown-annotation.js`<br>`test262/test/built-ins/Temporal/PlainDateTime/prototype/equals/argument-string-date-with-utc-offset.js`<br>...<br>`test262/test/built-ins/Temporal/PlainDateTime/prototype/toString/calendarname-never.js`<br>`test262/test/built-ins/Temporal/PlainDateTime/prototype/toString/calendarname-undefined.js` |
| language/statements | NORMAL | 7.558s | 250 | `test262/test/language/statements/generators/dstr/dflt-ary-ptrn-elem-id-init-fn-name-arrow.js`<br>`test262/test/language/statements/generators/dstr/dflt-ary-ptrn-elem-id-init-fn-name-class.js`<br>...<br>`test262/test/language/statements/labeled/cptn-nrml.js`<br>`test262/test/language/statements/labeled/decl-async-function.js` |
| built-ins/Temporal | NORMAL | 7.472s | 250 | `test262/test/built-ins/Temporal/PlainDateTime/prototype/toString/calendarname-wrong-type.js`<br>`test262/test/built-ins/Temporal/PlainDateTime/prototype/toString/fractionalseconddigits-auto.js`<br>...<br>`test262/test/built-ins/Temporal/PlainDateTime/throws-if-time-is-invalid.js`<br>`test262/test/built-ins/Temporal/PlainMonthDay/argument-invalid.js` |
| built-ins/Temporal | NORMAL | 7.384s | 250 | `test262/test/built-ins/Temporal/PlainTime/prototype/toString/builtin.js`<br>`test262/test/built-ins/Temporal/PlainTime/prototype/toString/fractionalseconddigits-auto.js`<br>...<br>`test262/test/built-ins/Temporal/PlainYearMonth/name.js`<br>`test262/test/built-ins/Temporal/PlainYearMonth/negative-infinity-throws-rangeerror.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are executed as ES modules (always strict, import/export processed).
- Tests flagged as async are skipped by the current runner.
- Mixed-mode tests currently run only in sloppy mode unless onlyStrict or noStrict is set.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

