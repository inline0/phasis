# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-04-26T19:02:30+00:00`
- Chunk size: `250`
- Timeout: `300s`
- Jobs: `4`
- Groups: `159`
- Test files: `50506`
- Git: `main` @ `4f1d5ac` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 48417 | 450 | 507 | 0 | 1085 | 47 | 48867 | 49374 | 50506 | 99.1% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | INCOMPLETE | 1046 | 8 | 0 | 0 | 25 | 0 | 99.2% |
| built-ins | RUNNING | 21751 | 206 | 3 | 0 | 722 | 47 | 99.1% |
| harness | PARTIAL | 114 | 2 | 0 | 0 | 0 | 0 | 98.3% |
| intl402 | INCOMPLETE | 1481 | 50 | 0 | 0 | 35 | 0 | 96.7% |
| language | INCOMPLETE | 22785 | 32 | 504 | 0 | 63 | 0 | 99.9% |
| staging | INCOMPLETE | 1240 | 152 | 0 | 0 | 240 | 0 | 89.1% |

## Group Coverage

| Group | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB/built-ins/Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/Date | PASS | 24 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Function | PENDING | 0 | 0 | 0 | 0 | 6 | 0 | n/a |
| annexB/built-ins/Object | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/RegExp | PARTIAL | 55 | 7 | 0 | 0 | 0 | 0 | 88.7% |
| annexB/built-ins/String | PASS | 111 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/TypedArrayConstructors | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/escape | PASS | 16 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/unescape | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/comments | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| annexB/language/eval-code | PASS | 469 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/expressions | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/function-code | PARTIAL | 158 | 1 | 0 | 0 | 0 | 0 | 99.4% |
| annexB/language/global-code | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/literals | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| annexB/language/statements | PASS | 22 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AbstractModuleSource | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| built-ins/Array | PARTIAL | 3050 | 25 | 0 | 0 | 0 | 0 | 99.2% |
| built-ins/ArrayBuffer | PARTIAL | 191 | 1 | 0 | 0 | 0 | 0 | 99.5% |
| built-ins/ArrayIteratorPrototype | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncDisposableStack | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncFromSyncIteratorPrototype | PASS | 38 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncFunction | PASS | 18 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorFunction | PARTIAL | 22 | 1 | 0 | 0 | 0 | 0 | 95.7% |
| built-ins/AsyncGeneratorPrototype | PARTIAL | 45 | 3 | 0 | 0 | 0 | 0 | 93.8% |
| built-ins/AsyncIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 10 | 0 | n/a |
| built-ins/Atomics | INCOMPLETE | 204 | 27 | 3 | 0 | 142 | 0 | 88.3% |
| built-ins/BigInt | PASS | 75 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Boolean | PASS | 51 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/DataView | PARTIAL | 547 | 3 | 0 | 0 | 0 | 0 | 99.5% |
| built-ins/Date | PASS | 594 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/DisposableStack | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Error | PASS | 53 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/FinalizationRegistry | PASS | 47 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Function | INCOMPLETE | 495 | 5 | 0 | 0 | 9 | 0 | 99.0% |
| built-ins/GeneratorFunction | PARTIAL | 22 | 1 | 0 | 0 | 0 | 0 | 95.7% |
| built-ins/GeneratorPrototype | PASS | 61 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Infinity | PENDING | 0 | 0 | 0 | 0 | 6 | 0 | n/a |
| built-ins/Iterator | PASS | 431 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/JSON | PARTIAL | 164 | 1 | 0 | 0 | 0 | 0 | 99.4% |
| built-ins/Map | PASS | 171 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/MapIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| built-ins/Math | PASS | 327 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/NaN | PENDING | 0 | 0 | 0 | 0 | 6 | 0 | n/a |
| built-ins/NativeErrors | PASS | 139 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Number | PASS | 335 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Object | PASS | 3410 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Promise | PASS | 631 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Proxy | PARTIAL | 304 | 7 | 0 | 0 | 0 | 0 | 97.7% |
| built-ins/Reflect | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp | PARTIAL | 481 | 7 | 0 | 0 | 0 | 0 | 98.6% |
| built-ins/RegExp/CharacterClassEscapes | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| built-ins/RegExp/Symbol.species | PENDING | 0 | 0 | 0 | 0 | 4 | 0 | n/a |
| built-ins/RegExp/dotall | PENDING | 0 | 0 | 0 | 0 | 4 | 0 | n/a |
| built-ins/RegExp/escape | PASS | 20 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/lookBehind | PARTIAL | 4 | 13 | 0 | 0 | 0 | 0 | 23.5% |
| built-ins/RegExp/match-indices | PENDING | 0 | 0 | 0 | 0 | 14 | 0 | n/a |
| built-ins/RegExp/named-groups | PARTIAL | 32 | 4 | 0 | 0 | 0 | 0 | 88.9% |
| built-ins/RegExp/property-escapes | PASS | 143 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/property-escapes/generated | RUNNING | 20 | 7 | 0 | 0 | 385 | 47 | 74.1% |
| built-ins/RegExp/prototype | PARTIAL | 473 | 14 | 0 | 0 | 0 | 0 | 97.1% |
| built-ins/RegExp/regexp-modifiers | PARTIAL | 60 | 10 | 0 | 0 | 0 | 0 | 85.7% |
| built-ins/RegExp/unicodeSets | PARTIAL | 85 | 28 | 0 | 0 | 0 | 0 | 75.2% |
| built-ins/RegExpStringIteratorPrototype | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Set | PASS | 381 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/SetIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| built-ins/ShadowRealm | PARTIAL | 55 | 9 | 0 | 0 | 0 | 0 | 85.9% |
| built-ins/SharedArrayBuffer | PASS | 104 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/String | PARTIAL | 1207 | 5 | 0 | 0 | 0 | 0 | 99.6% |
| built-ins/StringIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| built-ins/Symbol | PARTIAL | 92 | 2 | 0 | 0 | 0 | 0 | 97.9% |
| built-ins/Temporal | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| built-ins/Temporal/Duration | PASS | 473 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/Instant | PASS | 434 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/Now | PASS | 66 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainDate | PASS | 592 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainDateTime | PASS | 684 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainMonthDay | PASS | 184 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainTime | PASS | 457 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainYearMonth | PASS | 465 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/ZonedDateTime | PASS | 805 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/toStringTag | PENDING | 0 | 0 | 0 | 0 | 2 | 0 | n/a |
| built-ins/ThrowTypeError | PENDING | 0 | 0 | 0 | 0 | 14 | 0 | n/a |
| built-ins/TypedArray | PARTIAL | 1397 | 29 | 0 | 0 | 0 | 0 | 98.0% |
| built-ins/TypedArrayConstructors | PARTIAL | 733 | 3 | 0 | 0 | 0 | 0 | 99.6% |
| built-ins/Uint8Array | PARTIAL | 63 | 1 | 0 | 0 | 0 | 0 | 98.4% |
| built-ins/WeakMap | PASS | 102 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakRef | PASS | 29 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakSet | PASS | 85 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/decodeURI | INCOMPLETE | 27 | 0 | 0 | 0 | 28 | 0 | 100.0% |
| built-ins/decodeURIComponent | INCOMPLETE | 28 | 0 | 0 | 0 | 28 | 0 | 100.0% |
| built-ins/encodeURI | PASS | 31 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/encodeURIComponent | PASS | 31 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/eval | PENDING | 0 | 0 | 0 | 0 | 10 | 0 | n/a |
| built-ins/global | PASS | 29 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isFinite | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isNaN | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseFloat | PASS | 59 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseInt | PASS | 60 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/undefined | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| harness | PARTIAL | 114 | 2 | 0 | 0 | 0 | 0 | 98.3% |
| intl402 | PARTIAL | 21 | 1 | 0 | 0 | 0 | 0 | 95.5% |
| intl402/Array | PENDING | 0 | 0 | 0 | 0 | 2 | 0 | n/a |
| intl402/BigInt | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| intl402/Collator | PASS | 62 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Date | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| intl402/DateTimeFormat | PARTIAL | 185 | 3 | 0 | 0 | 0 | 0 | 98.4% |
| intl402/DisplayNames | PARTIAL | 56 | 1 | 0 | 0 | 0 | 0 | 98.2% |
| intl402/DurationFormat | PASS | 110 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Intl | PASS | 67 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/ListFormat | PASS | 81 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Locale | PASS | 147 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Number | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| intl402/NumberFormat | INCOMPLETE | 249 | 1 | 0 | 0 | 2 | 0 | 99.6% |
| intl402/PluralRules | PASS | 50 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/RelativeTimeFormat | PASS | 79 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Segmenter | PASS | 78 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/String | PARTIAL | 15 | 2 | 0 | 0 | 0 | 0 | 88.2% |
| intl402/Temporal | PARTIAL | 281 | 42 | 0 | 0 | 0 | 0 | 87.0% |
| intl402/TypedArray | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/arguments-object | INCOMPLETE | 250 | 0 | 0 | 0 | 13 | 0 | 100.0% |
| language/asi | PASS | 102 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/block-scope | PASS | 145 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/comments | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/computed-property-names | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/destructuring | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/directive-prologue | PASS | 62 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/eval-code | PARTIAL | 346 | 1 | 0 | 0 | 0 | 0 | 99.7% |
| language/export | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| language/expressions | PARTIAL | 10602 | 12 | 409 | 0 | 0 | 0 | 99.9% |
| language/function-code | PASS | 217 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/future-reserved-words | PASS | 55 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/global-code | PASS | 42 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifier-resolution | PENDING | 0 | 0 | 0 | 0 | 14 | 0 | n/a |
| language/identifiers | INCOMPLETE | 250 | 0 | 0 | 0 | 10 | 0 | 100.0% |
| language/import | PASS | 4 | 0 | 81 | 0 | 0 | 0 | 100.0% |
| language/keywords | PASS | 25 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/line-terminators | PASS | 41 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/literals | PARTIAL | 532 | 2 | 0 | 0 | 0 | 0 | 99.6% |
| language/module-code | PARTIAL | 564 | 5 | 14 | 0 | 0 | 0 | 99.1% |
| language/punctuators | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/reserved-words | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/rest-parameters | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/source-text | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/statementList | PASS | 80 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/statements | PARTIAL | 9144 | 10 | 0 | 0 | 0 | 0 | 99.9% |
| language/types | PARTIAL | 111 | 2 | 0 | 0 | 0 | 0 | 98.2% |
| language/white-space | PASS | 67 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/Intl402 | PARTIAL | 5 | 44 | 0 | 0 | 0 | 0 | 10.2% |
| staging/Temporal | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| staging/Uint8Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/built-ins | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| staging/decorators | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/explicit-resource-management | PARTIAL | 53 | 1 | 0 | 0 | 0 | 0 | 98.1% |
| staging/set-methods | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/sm | INCOMPLETE | 1111 | 107 | 0 | 0 | 210 | 0 | 91.2% |
| staging/source-phase-imports | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/upsert | PASS | 71 | 0 | 0 | 0 | 0 | 0 | 100.0% |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| language/literals | NORMAL | 65.751s | 250 | `test262/test/language/literals/bigint/binary-invalid-digit.js`<br>`test262/test/language/literals/bigint/exponent-part.js`<br>...<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T2.js` |
| intl402 | NORMAL | 44.230s | 22 | `test262/test/intl402/constructors-string-and-single-element-array.js`<br>`test262/test/intl402/constructors-taint-Object-prototype-2.js`<br>...<br>`test262/test/intl402/supportedLocalesOf-throws-if-element-not-string-or-object.js`<br>`test262/test/intl402/supportedLocalesOf-unicode-extensions-ignored.js` |
| built-ins/decodeURI | NORMAL | 39.794s | 27 | `test262/test/built-ins/decodeURI/S15.1.3.1_A1.10_T1.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.11_T1.js`<br>...<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.6_T1.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.7_T1.js` |
| built-ins/decodeURIComponent | NORMAL | 38.327s | 28 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.10_T1.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.11_T1.js`<br>...<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.7_T1.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.8_T1.js` |
| annexB/built-ins/RegExp | NORMAL | 36.719s | 62 | `test262/test/annexB/built-ins/RegExp/RegExp-control-escape-russian-letter.js`<br>`test262/test/annexB/built-ins/RegExp/RegExp-decimal-escape-class-range.js`<br>...<br>`test262/test/annexB/built-ins/RegExp/prototype/compile/this-subclass-instance.js`<br>`test262/test/annexB/built-ins/RegExp/prototype/flags/order-after-compile.js` |
| built-ins/Function | NORMAL | 34.352s | 250 | `test262/test/built-ins/Function/prototype/apply/S15.3.4.3_A7_T10.js`<br>`test262/test/built-ins/Function/prototype/apply/S15.3.4.3_A7_T2.js`<br>...<br>`test262/test/built-ins/Function/prototype/toString/proxy-function-expression.js`<br>`test262/test/built-ins/Function/prototype/toString/proxy-generator-function.js` |
| language/literals | NORMAL | 22.980s | 250 | `test262/test/language/literals/regexp/S7.8.5_A2.3_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.3_T2.js`<br>...<br>`test262/test/language/literals/string/S7.8.4_A7.2_T1.js`<br>`test262/test/language/literals/string/S7.8.4_A7.2_T2.js` |
| built-ins/Atomics | NORMAL | 21.463s | 62 | `test262/test/built-ins/Atomics/notify/null-bufferdata-throws.js`<br>`test262/test/built-ins/Atomics/notify/out-of-range-index-throws.js`<br>...<br>`test262/test/built-ins/Atomics/wait/bad-range.js`<br>`test262/test/built-ins/Atomics/wait/bigint/bad-range.js` |
| staging/sm | NORMAL | 21.032s | 250 | `test262/test/staging/sm/Iterator/prototype/find/descriptor.js`<br>`test262/test/staging/sm/Iterator/prototype/find/error-from-correct-realm.js`<br>...<br>`test262/test/staging/sm/Proxy/hasInstance.js`<br>`test262/test/staging/sm/Proxy/json-stringify-replacer-array-revocable-proxy.js` |
| intl402/Temporal | NORMAL | 20.702s | 250 | `test262/test/intl402/Temporal/Duration/compare/relativeto-hour.js`<br>`test262/test/intl402/Temporal/Duration/compare/relativeto-sub-minute-offset.js`<br>...<br>`test262/test/intl402/Temporal/ZonedDateTime/prototype/equals/different-calendar-not-equal.js`<br>`test262/test/intl402/Temporal/ZonedDateTime/prototype/equals/infinity-throws-rangeerror.js` |
| staging/sm | NORMAL | 20.659s | 16 | `test262/test/staging/sm/String/split-undefined-separator.js`<br>`test262/test/staging/sm/String/split-xregexp.js`<br>...<br>`test262/test/staging/sm/Symbol/enumeration-order.js`<br>`test262/test/staging/sm/Symbol/enumeration.js` |
| built-ins/encodeURI | NORMAL | 20.185s | 31 | `test262/test/built-ins/encodeURI/S15.1.3.3_A1.1_T1.js`<br>`test262/test/built-ins/encodeURI/S15.1.3.3_A1.1_T2.js`<br>...<br>`test262/test/built-ins/encodeURI/not-a-constructor.js`<br>`test262/test/built-ins/encodeURI/prop-desc.js` |
| language/expressions | NORMAL | 20.152s | 250 | `test262/test/language/expressions/async-generator/yield-star-next-not-callable-symbol-throw.js`<br>`test262/test/language/expressions/async-generator/yield-star-next-not-callable-undefined-throw.js`<br>...<br>`test262/test/language/expressions/class/accessor-name-inst/literal-numeric-zero.js`<br>`test262/test/language/expressions/class/accessor-name-inst/literal-string-char-escape.js` |
| built-ins/encodeURIComponent | NORMAL | 19.998s | 31 | `test262/test/built-ins/encodeURIComponent/S15.1.3.4_A1.1_T1.js`<br>`test262/test/built-ins/encodeURIComponent/S15.1.3.4_A1.1_T2.js`<br>...<br>`test262/test/built-ins/encodeURIComponent/not-a-constructor.js`<br>`test262/test/built-ins/encodeURIComponent/prop-desc.js` |
| language/identifiers | NORMAL | 17.921s | 250 | `test262/test/language/identifiers/other_id_continue-escaped.js`<br>`test262/test/language/identifiers/other_id_continue.js`<br>...<br>`test262/test/language/identifiers/vals-eng-alpha-upper-via-escape-hex4.js`<br>`test262/test/language/identifiers/vals-eng-alpha-upper.js` |
| intl402/NumberFormat | NORMAL | 17.830s | 250 | `test262/test/intl402/NumberFormat/builtin.js`<br>`test262/test/intl402/NumberFormat/casing-numbering-system-options.js`<br>...<br>`test262/test/intl402/NumberFormat/throws-for-maximumFractionDigits-over-limit.js`<br>`test262/test/intl402/NumberFormat/throws-for-maximumFractionDigits-under-limit.js` |
| built-ins/TypedArray | NORMAL | 16.911s | 250 | `test262/test/built-ins/TypedArray/prototype/set/BigInt/array-arg-targetbuffer-detached-throws.js`<br>`test262/test/built-ins/TypedArray/prototype/set/BigInt/bigint-tobigint64.js`<br>...<br>`test262/test/built-ins/TypedArray/prototype/sort/prop-desc.js`<br>`test262/test/built-ins/TypedArray/prototype/sort/resizable-buffer-default-comparator.js` |
| language/comments | NORMAL | 15.873s | 52 | `test262/test/language/comments/S7.4_A1_T1.js`<br>`test262/test/language/comments/S7.4_A1_T2.js`<br>...<br>`test262/test/language/comments/multi-line-html-close-extra.js`<br>`test262/test/language/comments/single-line-html-close-without-lt.js` |
| language/statements | NORMAL | 14.844s | 250 | `test262/test/language/statements/for/dstr/let-ary-ptrn-elem-ary-empty-iter.js`<br>`test262/test/language/statements/for/dstr/let-ary-ptrn-elem-ary-rest-init.js`<br>...<br>`test262/test/language/statements/function/13.2-18-1.js`<br>`test262/test/language/statements/function/13.2-18-s.js` |
| staging/sm | NORMAL | 14.587s | 125 | `test262/test/staging/sm/Date/fractions.js`<br>`test262/test/staging/sm/Date/makeday-year-month-is-infinity.js`<br>...<br>`test262/test/staging/sm/Iterator/prototype/find/check-fn-after-getting-iterator.js`<br>`test262/test/staging/sm/Iterator/prototype/find/coerce-result-to-boolean.js` |

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
