# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-04-26T18:28:23+00:00`
- Chunk size: `250`
- Timeout: `300s`
- Jobs: `4`
- Groups: `159`
- Test files: `50506`
- Git: `main` @ `c767da0` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 43358 | 192 | 423 | 0 | 6170 | 363 | 43550 | 43973 | 50506 | 99.6% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | INCOMPLETE | 780 | 1 | 0 | 0 | 298 | 0 | 99.9% |
| built-ins | RUNNING | 19265 | 97 | 0 | 0 | 3004 | 363 | 99.5% |
| harness | PARTIAL | 114 | 2 | 0 | 0 | 0 | 0 | 98.3% |
| intl402 | INCOMPLETE | 797 | 38 | 0 | 0 | 731 | 0 | 95.4% |
| language | INCOMPLETE | 21927 | 29 | 423 | 0 | 1005 | 0 | 99.9% |
| staging | INCOMPLETE | 475 | 25 | 0 | 0 | 1132 | 0 | 95.0% |

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
| annexB/language/function-code | PARTIAL | 158 | 1 | 0 | 0 | 0 | 0 | 99.4% |
| annexB/language/global-code | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/literals | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| annexB/language/statements | PENDING | 0 | 0 | 0 | 0 | 22 | 0 | n/a |
| built-ins/AbstractModuleSource | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| built-ins/Array | INCOMPLETE | 2977 | 23 | 0 | 0 | 75 | 0 | 99.2% |
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
| built-ins/DataView | INCOMPLETE | 497 | 3 | 0 | 0 | 50 | 0 | 99.4% |
| built-ins/Date | INCOMPLETE | 500 | 0 | 0 | 0 | 94 | 0 | 100.0% |
| built-ins/DisposableStack | PENDING | 0 | 0 | 0 | 0 | 52 | 0 | n/a |
| built-ins/Error | PENDING | 0 | 0 | 0 | 0 | 53 | 0 | n/a |
| built-ins/FinalizationRegistry | PENDING | 0 | 0 | 0 | 0 | 47 | 0 | n/a |
| built-ins/Function | INCOMPLETE | 495 | 5 | 0 | 0 | 9 | 0 | 99.0% |
| built-ins/GeneratorFunction | PENDING | 0 | 0 | 0 | 0 | 23 | 0 | n/a |
| built-ins/GeneratorPrototype | PENDING | 0 | 0 | 0 | 0 | 61 | 0 | n/a |
| built-ins/Infinity | PENDING | 0 | 0 | 0 | 0 | 6 | 0 | n/a |
| built-ins/Iterator | PASS | 431 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/JSON | PARTIAL | 164 | 1 | 0 | 0 | 0 | 0 | 99.4% |
| built-ins/Map | PASS | 171 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/MapIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| built-ins/Math | INCOMPLETE | 250 | 0 | 0 | 0 | 77 | 0 | 100.0% |
| built-ins/NaN | PENDING | 0 | 0 | 0 | 0 | 6 | 0 | n/a |
| built-ins/NativeErrors | PASS | 139 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Number | INCOMPLETE | 250 | 0 | 0 | 0 | 85 | 0 | 100.0% |
| built-ins/Object | PASS | 3410 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Promise | PASS | 631 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Proxy | INCOMPLETE | 244 | 6 | 0 | 0 | 61 | 0 | 97.6% |
| built-ins/Reflect | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp | PARTIAL | 481 | 7 | 0 | 0 | 0 | 0 | 98.6% |
| built-ins/RegExp/CharacterClassEscapes | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| built-ins/RegExp/Symbol.species | PENDING | 0 | 0 | 0 | 0 | 4 | 0 | n/a |
| built-ins/RegExp/dotall | PENDING | 0 | 0 | 0 | 0 | 4 | 0 | n/a |
| built-ins/RegExp/escape | PENDING | 0 | 0 | 0 | 0 | 20 | 0 | n/a |
| built-ins/RegExp/lookBehind | PENDING | 0 | 0 | 0 | 0 | 17 | 0 | n/a |
| built-ins/RegExp/match-indices | PENDING | 0 | 0 | 0 | 0 | 14 | 0 | n/a |
| built-ins/RegExp/named-groups | PENDING | 0 | 0 | 0 | 0 | 36 | 0 | n/a |
| built-ins/RegExp/property-escapes | PASS | 143 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/property-escapes/generated | RUNNING | 0 | 0 | 0 | 0 | 209 | 250 | n/a |
| built-ins/RegExp/prototype | PARTIAL | 473 | 14 | 0 | 0 | 0 | 0 | 97.1% |
| built-ins/RegExp/regexp-modifiers | PENDING | 0 | 0 | 0 | 0 | 70 | 0 | n/a |
| built-ins/RegExp/unicodeSets | RUNNING | 0 | 0 | 0 | 0 | 0 | 113 | n/a |
| built-ins/RegExpStringIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 17 | 0 | n/a |
| built-ins/Set | PASS | 381 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/SetIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| built-ins/ShadowRealm | PENDING | 0 | 0 | 0 | 0 | 64 | 0 | n/a |
| built-ins/SharedArrayBuffer | PENDING | 0 | 0 | 0 | 0 | 104 | 0 | n/a |
| built-ins/String | PARTIAL | 1207 | 5 | 0 | 0 | 0 | 0 | 99.6% |
| built-ins/StringIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| built-ins/Symbol | PENDING | 0 | 0 | 0 | 0 | 94 | 0 | n/a |
| built-ins/Temporal | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| built-ins/Temporal/Duration | PASS | 473 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/Instant | PASS | 434 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/Now | PENDING | 0 | 0 | 0 | 0 | 66 | 0 | n/a |
| built-ins/Temporal/PlainDate | INCOMPLETE | 500 | 0 | 0 | 0 | 92 | 0 | 100.0% |
| built-ins/Temporal/PlainDateTime | PASS | 684 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainMonthDay | PASS | 184 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainTime | PASS | 457 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainYearMonth | PASS | 465 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/ZonedDateTime | INCOMPLETE | 750 | 0 | 0 | 0 | 55 | 0 | 100.0% |
| built-ins/Temporal/toStringTag | PENDING | 0 | 0 | 0 | 0 | 2 | 0 | n/a |
| built-ins/ThrowTypeError | PENDING | 0 | 0 | 0 | 0 | 14 | 0 | n/a |
| built-ins/TypedArray | PARTIAL | 1397 | 29 | 0 | 0 | 0 | 0 | 98.0% |
| built-ins/TypedArrayConstructors | PARTIAL | 733 | 3 | 0 | 0 | 0 | 0 | 99.6% |
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
| harness | PARTIAL | 114 | 2 | 0 | 0 | 0 | 0 | 98.3% |
| intl402 | PENDING | 0 | 0 | 0 | 0 | 22 | 0 | n/a |
| intl402/Array | PENDING | 0 | 0 | 0 | 0 | 2 | 0 | n/a |
| intl402/BigInt | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| intl402/Collator | PENDING | 0 | 0 | 0 | 0 | 62 | 0 | n/a |
| intl402/Date | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| intl402/DateTimeFormat | PARTIAL | 185 | 3 | 0 | 0 | 0 | 0 | 98.4% |
| intl402/DisplayNames | PENDING | 0 | 0 | 0 | 0 | 57 | 0 | n/a |
| intl402/DurationFormat | PENDING | 0 | 0 | 0 | 0 | 110 | 0 | n/a |
| intl402/Intl | PENDING | 0 | 0 | 0 | 0 | 67 | 0 | n/a |
| intl402/ListFormat | PENDING | 0 | 0 | 0 | 0 | 81 | 0 | n/a |
| intl402/Locale | PASS | 147 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Number | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| intl402/NumberFormat | INCOMPLETE | 249 | 1 | 0 | 0 | 2 | 0 | 99.6% |
| intl402/PluralRules | PENDING | 0 | 0 | 0 | 0 | 50 | 0 | n/a |
| intl402/RelativeTimeFormat | PENDING | 0 | 0 | 0 | 0 | 79 | 0 | n/a |
| intl402/Segmenter | PENDING | 0 | 0 | 0 | 0 | 78 | 0 | n/a |
| intl402/String | PENDING | 0 | 0 | 0 | 0 | 17 | 0 | n/a |
| intl402/Temporal | INCOMPLETE | 216 | 34 | 0 | 0 | 73 | 0 | 86.4% |
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
| language/expressions | INCOMPLETE | 10579 | 12 | 409 | 0 | 23 | 0 | 99.9% |
| language/function-code | PASS | 217 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/future-reserved-words | PENDING | 0 | 0 | 0 | 0 | 55 | 0 | n/a |
| language/global-code | PENDING | 0 | 0 | 0 | 0 | 42 | 0 | n/a |
| language/identifier-resolution | PENDING | 0 | 0 | 0 | 0 | 14 | 0 | n/a |
| language/identifiers | INCOMPLETE | 250 | 0 | 0 | 0 | 10 | 0 | 100.0% |
| language/import | PENDING | 0 | 0 | 0 | 0 | 85 | 0 | n/a |
| language/keywords | PENDING | 0 | 0 | 0 | 0 | 25 | 0 | n/a |
| language/line-terminators | PENDING | 0 | 0 | 0 | 0 | 41 | 0 | n/a |
| language/literals | INCOMPLETE | 498 | 2 | 0 | 0 | 34 | 0 | 99.6% |
| language/module-code | INCOMPLETE | 483 | 3 | 14 | 0 | 83 | 0 | 99.4% |
| language/punctuators | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/reserved-words | PENDING | 0 | 0 | 0 | 0 | 27 | 0 | n/a |
| language/rest-parameters | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/source-text | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/statementList | PENDING | 0 | 0 | 0 | 0 | 80 | 0 | n/a |
| language/statements | PARTIAL | 9144 | 10 | 0 | 0 | 0 | 0 | 99.9% |
| language/types | PARTIAL | 111 | 2 | 0 | 0 | 0 | 0 | 98.2% |
| language/white-space | PENDING | 0 | 0 | 0 | 0 | 67 | 0 | n/a |
| staging | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/Intl402 | PENDING | 0 | 0 | 0 | 0 | 49 | 0 | n/a |
| staging/Temporal | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| staging/Uint8Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/built-ins | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| staging/decorators | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/explicit-resource-management | PENDING | 0 | 0 | 0 | 0 | 54 | 0 | n/a |
| staging/set-methods | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/sm | INCOMPLETE | 475 | 25 | 0 | 0 | 928 | 0 | 95.0% |
| staging/source-phase-imports | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/upsert | PENDING | 0 | 0 | 0 | 0 | 71 | 0 | n/a |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| language/literals | NORMAL | 65.751s | 250 | `test262/test/language/literals/bigint/binary-invalid-digit.js`<br>`test262/test/language/literals/bigint/exponent-part.js`<br>...<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T2.js` |
| built-ins/Function | NORMAL | 34.352s | 250 | `test262/test/built-ins/Function/prototype/apply/S15.3.4.3_A7_T10.js`<br>`test262/test/built-ins/Function/prototype/apply/S15.3.4.3_A7_T2.js`<br>...<br>`test262/test/built-ins/Function/prototype/toString/proxy-function-expression.js`<br>`test262/test/built-ins/Function/prototype/toString/proxy-generator-function.js` |
| language/literals | NORMAL | 22.980s | 250 | `test262/test/language/literals/regexp/S7.8.5_A2.3_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.3_T2.js`<br>...<br>`test262/test/language/literals/string/S7.8.4_A7.2_T1.js`<br>`test262/test/language/literals/string/S7.8.4_A7.2_T2.js` |
| staging/sm | NORMAL | 21.032s | 250 | `test262/test/staging/sm/Iterator/prototype/find/descriptor.js`<br>`test262/test/staging/sm/Iterator/prototype/find/error-from-correct-realm.js`<br>...<br>`test262/test/staging/sm/Proxy/hasInstance.js`<br>`test262/test/staging/sm/Proxy/json-stringify-replacer-array-revocable-proxy.js` |
| intl402/Temporal | NORMAL | 20.702s | 250 | `test262/test/intl402/Temporal/Duration/compare/relativeto-hour.js`<br>`test262/test/intl402/Temporal/Duration/compare/relativeto-sub-minute-offset.js`<br>...<br>`test262/test/intl402/Temporal/ZonedDateTime/prototype/equals/different-calendar-not-equal.js`<br>`test262/test/intl402/Temporal/ZonedDateTime/prototype/equals/infinity-throws-rangeerror.js` |
| language/expressions | NORMAL | 20.152s | 250 | `test262/test/language/expressions/async-generator/yield-star-next-not-callable-symbol-throw.js`<br>`test262/test/language/expressions/async-generator/yield-star-next-not-callable-undefined-throw.js`<br>...<br>`test262/test/language/expressions/class/accessor-name-inst/literal-numeric-zero.js`<br>`test262/test/language/expressions/class/accessor-name-inst/literal-string-char-escape.js` |
| language/identifiers | NORMAL | 17.921s | 250 | `test262/test/language/identifiers/other_id_continue-escaped.js`<br>`test262/test/language/identifiers/other_id_continue.js`<br>...<br>`test262/test/language/identifiers/vals-eng-alpha-upper-via-escape-hex4.js`<br>`test262/test/language/identifiers/vals-eng-alpha-upper.js` |
| intl402/NumberFormat | NORMAL | 17.830s | 250 | `test262/test/intl402/NumberFormat/builtin.js`<br>`test262/test/intl402/NumberFormat/casing-numbering-system-options.js`<br>...<br>`test262/test/intl402/NumberFormat/throws-for-maximumFractionDigits-over-limit.js`<br>`test262/test/intl402/NumberFormat/throws-for-maximumFractionDigits-under-limit.js` |
| built-ins/TypedArray | NORMAL | 16.911s | 250 | `test262/test/built-ins/TypedArray/prototype/set/BigInt/array-arg-targetbuffer-detached-throws.js`<br>`test262/test/built-ins/TypedArray/prototype/set/BigInt/bigint-tobigint64.js`<br>...<br>`test262/test/built-ins/TypedArray/prototype/sort/prop-desc.js`<br>`test262/test/built-ins/TypedArray/prototype/sort/resizable-buffer-default-comparator.js` |
| language/statements | NORMAL | 14.844s | 250 | `test262/test/language/statements/for/dstr/let-ary-ptrn-elem-ary-empty-iter.js`<br>`test262/test/language/statements/for/dstr/let-ary-ptrn-elem-ary-rest-init.js`<br>...<br>`test262/test/language/statements/function/13.2-18-1.js`<br>`test262/test/language/statements/function/13.2-18-s.js` |
| staging/sm | NORMAL | 14.587s | 125 | `test262/test/staging/sm/Date/fractions.js`<br>`test262/test/staging/sm/Date/makeday-year-month-is-infinity.js`<br>...<br>`test262/test/staging/sm/Iterator/prototype/find/check-fn-after-getting-iterator.js`<br>`test262/test/staging/sm/Iterator/prototype/find/coerce-result-to-boolean.js` |
| language/statements | NORMAL | 12.927s | 250 | `test262/test/language/statements/try/scope-catch-param-var-none.js`<br>`test262/test/language/statements/try/static-init-await-binding-invalid.js`<br>...<br>`test262/test/language/statements/with/S12.10_A1.10_T5.js`<br>`test262/test/language/statements/with/S12.10_A1.11_T1.js` |
| language/expressions | NORMAL | 12.703s | 250 | `test262/test/language/expressions/super/prop-expr-getsuperbase-before-topropertykey-putvalue.js`<br>`test262/test/language/expressions/super/prop-expr-obj-err.js`<br>...<br>`test262/test/language/expressions/yield/star-rhs-iter-rtrn-res-done-no-value.js`<br>`test262/test/language/expressions/yield/star-rhs-iter-rtrn-res-value-err.js` |
| built-ins/TypedArray | NORMAL | 12.654s | 250 | `test262/test/built-ins/TypedArray/Symbol.species/length.js`<br>`test262/test/built-ins/TypedArray/Symbol.species/name.js`<br>...<br>`test262/test/built-ins/TypedArray/prototype/every/values-are-not-cached.js`<br>`test262/test/built-ins/TypedArray/prototype/fill/BigInt/coerced-indexes.js` |
| built-ins/Array | NORMAL | 12.144s | 250 | `test262/test/built-ins/Array/of/does-not-use-set-for-indices.js`<br>`test262/test/built-ins/Array/of/length.js`<br>...<br>`test262/test/built-ins/Array/prototype/every/15.4.4.16-5-24.js`<br>`test262/test/built-ins/Array/prototype/every/15.4.4.16-5-3.js` |
| built-ins/Temporal/PlainTime | NORMAL | 11.076s | 250 | `test262/test/built-ins/Temporal/PlainTime/basic.js`<br>`test262/test/built-ins/Temporal/PlainTime/builtin.js`<br>...<br>`test262/test/built-ins/Temporal/PlainTime/prototype/since/roundingincrement-nan.js`<br>`test262/test/built-ins/Temporal/PlainTime/prototype/since/roundingincrement-nanoseconds.js` |
| built-ins/Temporal/Duration | NORMAL | 10.977s | 250 | `test262/test/built-ins/Temporal/Duration/basic.js`<br>`test262/test/built-ins/Temporal/Duration/builtin.js`<br>...<br>`test262/test/built-ins/Temporal/Duration/prototype/round/roundingmode-floor.js`<br>`test262/test/built-ins/Temporal/Duration/prototype/round/roundingmode-halfCeil.js` |
| built-ins/Temporal/PlainDateTime | NORMAL | 10.902s | 250 | `test262/test/built-ins/Temporal/PlainDateTime/prototype/round/roundingincrement-out-of-range.js`<br>`test262/test/built-ins/Temporal/PlainDateTime/prototype/round/roundingincrement-undefined.js`<br>...<br>`test262/test/built-ins/Temporal/PlainDateTime/prototype/toZonedDateTime/timezone-wrong-type.js`<br>`test262/test/built-ins/Temporal/PlainDateTime/prototype/toZonedDateTime/two-digit-year.js` |
| built-ins/Temporal/ZonedDateTime | NORMAL | 10.869s | 250 | `test262/test/built-ins/Temporal/ZonedDateTime/prototype/getTimeZoneTransition/builtin.js`<br>`test262/test/built-ins/Temporal/ZonedDateTime/prototype/getTimeZoneTransition/direction-undefined.js`<br>...<br>`test262/test/built-ins/Temporal/ZonedDateTime/prototype/toJSON/offset.js`<br>`test262/test/built-ins/Temporal/ZonedDateTime/prototype/toJSON/prop-desc.js` |
| built-ins/Temporal/ZonedDateTime | NORMAL | 10.591s | 250 | `test262/test/built-ins/Temporal/ZonedDateTime/prototype/toJSON/year-format.js`<br>`test262/test/built-ins/Temporal/ZonedDateTime/prototype/toLocaleString/branding.js`<br>...<br>`test262/test/built-ins/Temporal/ZonedDateTime/prototype/withPlainTime/argument-string-critical-unknown-annotation.js`<br>`test262/test/built-ins/Temporal/ZonedDateTime/prototype/withPlainTime/argument-string-date-with-utc-offset.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are executed as ES modules (always strict, import/export processed).
- Tests flagged as async are skipped by the current runner.
- Mixed-mode tests currently run only in sloppy mode unless onlyStrict or noStrict is set.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

- `import-defer`
- `source-phase-imports`
- `import-attributes`
- `json-modules`
