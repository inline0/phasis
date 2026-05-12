# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-05-12T07:46:29+00:00`
- Chunk size: `25`
- Timeout: `300s`
- Jobs: `4`
- Groups: `159`
- Test files: `50506`
- Git: `main` @ `3046196` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 49933 | 43 | 114 | 0 | 996 | 22 | 49976 | 50090 | 51108 | 99.9% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | PASS | 1078 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins | RUNNING | 22215 | 40 | 58 | 0 | 996 | 22 | 99.8% |
| harness | PASS | 116 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402 | PARTIAL | 1565 | 1 | 0 | 0 | 0 | 0 | 99.9% |
| language | PASS | 23377 | 0 | 7 | 0 | 0 | 0 | 100.0% |
| staging | PARTIAL | 1582 | 2 | 48 | 0 | 0 | 0 | 99.9% |

## Group Coverage

| Group | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB/built-ins/Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Date | PASS | 24 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Function | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Object | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/RegExp | PASS | 61 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/String | PASS | 111 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/TypedArrayConstructors | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/escape | PASS | 16 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/unescape | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/comments | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/eval-code | PASS | 469 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/expressions | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/function-code | PASS | 159 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/global-code | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/literals | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/statements | PASS | 22 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AbstractModuleSource | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Array | PASS | 3075 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ArrayBuffer | PASS | 192 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ArrayIteratorPrototype | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncDisposableStack | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncFromSyncIteratorPrototype | PASS | 38 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncFunction | PASS | 18 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorFunction | PASS | 23 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorPrototype | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncIteratorPrototype | PASS | 10 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Atomics | PASS | 322 | 0 | 54 | 0 | 0 | 0 | 100.0% |
| built-ins/BigInt | PASS | 75 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Boolean | PASS | 51 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/DataView | PASS | 550 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Date | PASS | 594 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/DisposableStack | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Error | PASS | 53 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/FinalizationRegistry | PASS | 47 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Function | PASS | 508 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/GeneratorFunction | PASS | 23 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/GeneratorPrototype | PASS | 61 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Infinity | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Iterator | PASS | 431 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/JSON | PASS | 165 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Map | PASS | 171 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/MapIteratorPrototype | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Math | PASS | 327 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/NaN | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/NativeErrors | PASS | 139 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Number | PASS | 335 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Object | PASS | 3410 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Promise | PASS | 631 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Proxy | PASS | 310 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/Reflect | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp | PARTIAL | 487 | 1 | 0 | 0 | 0 | 0 | 99.8% |
| built-ins/RegExp/CharacterClassEscapes | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/Symbol.species | PASS | 4 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/dotall | PASS | 4 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/escape | PASS | 20 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/lookBehind | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/match-indices | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/named-groups | PASS | 36 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/property-escapes | INCOMPLETE | 121 | 0 | 0 | 0 | 165 | 0 | 100.0% |
| built-ins/RegExp/property-escapes/generated | RUNNING | 26 | 39 | 0 | 0 | 831 | 22 | 40.0% |
| built-ins/RegExp/prototype | PASS | 487 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/regexp-modifiers | PASS | 70 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/unicodeSets | PASS | 113 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExpStringIteratorPrototype | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Set | PASS | 381 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/SetIteratorPrototype | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ShadowRealm | PASS | 64 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/SharedArrayBuffer | PASS | 104 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/String | PASS | 1212 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/StringIteratorPrototype | PASS | 7 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Symbol | PASS | 94 | 0 | 0 | 0 | 0 | 0 | 100.0% |
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
| built-ins/TypedArrayConstructors | PASS | 736 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Uint8Array | PASS | 64 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakMap | PASS | 102 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakRef | PASS | 29 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakSet | PASS | 85 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/decodeURI | PASS | 54 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/decodeURIComponent | PASS | 55 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/encodeURI | PASS | 31 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/encodeURIComponent | PASS | 31 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/eval | PASS | 10 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/global | PASS | 29 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isFinite | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isNaN | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseFloat | PASS | 59 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseInt | PASS | 60 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/undefined | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| harness | PASS | 116 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402 | PASS | 22 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Array | PASS | 2 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/BigInt | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Collator | PASS | 62 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Date | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/DateTimeFormat | PASS | 188 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/DisplayNames | PASS | 57 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/DurationFormat | PASS | 110 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Intl | PASS | 67 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/ListFormat | PASS | 81 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Locale | PASS | 147 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Number | PASS | 7 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/NumberFormat | PASS | 252 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/PluralRules | PASS | 50 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/RelativeTimeFormat | PASS | 79 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Segmenter | PASS | 78 | 0 | 0 | 0 | 0 | 0 | 100.0% |
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
| language/eval-code | PASS | 347 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/export | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/expressions | PASS | 11016 | 0 | 7 | 0 | 0 | 0 | 100.0% |
| language/function-code | PASS | 217 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/future-reserved-words | PASS | 55 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/global-code | PASS | 42 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifier-resolution | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
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
| language/statements | PASS | 9154 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/types | PASS | 113 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/white-space | PASS | 67 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Intl402 | PARTIAL | 45 | 2 | 2 | 0 | 0 | 0 | 95.7% |
| staging/Temporal | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Uint8Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/built-ins | PASS | 7 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/decorators | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/explicit-resource-management | PASS | 54 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/set-methods | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/sm | PASS | 1382 | 0 | 46 | 0 | 0 | 0 | 100.0% |
| staging/source-phase-imports | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/upsert | PASS | 71 | 0 | 0 | 0 | 0 | 0 | 100.0% |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| built-ins/decodeURIComponent | NORMAL | 79.798s | 25 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.11_T2.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.12_T1.js`<br>...<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.8_T1.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.8_T2.js` |
| built-ins/decodeURI | NORMAL | 79.247s | 25 | `test262/test/built-ins/decodeURI/S15.1.3.1_A1.11_T2.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.12_T1.js`<br>...<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.8_T1.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.8_T2.js` |
| language/expressions | NORMAL | 65.285s | 25 | `test262/test/language/expressions/call/spread-sngl-iter.js`<br>`test262/test/language/expressions/call/spread-sngl-literal.js`<br>...<br>`test262/test/language/expressions/class/accessor-name-inst/literal-numeric-zero.js`<br>`test262/test/language/expressions/class/accessor-name-inst/literal-string-char-escape.js` |
| language/literals | NORMAL | 60.485s | 25 | `test262/test/language/literals/regexp/S7.8.5_A1.1_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A1.1_T2.js`<br>...<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T2.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.3_T1.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 59.182s | 3 | `test262/test/built-ins/RegExp/property-escapes/generated/Sentence_Terminal.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Soft_Dotted.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Terminal_Punctuation.js` |
| built-ins/RegExp/property-escapes | NORMAL | 57.361s | 3 | `test262/test/built-ins/RegExp/property-escapes/grammar-extension-separator-and-value-only-negated.js`<br>`test262/test/built-ins/RegExp/property-escapes/grammar-extension-separator-and-value-only.js`<br>`test262/test/built-ins/RegExp/property-escapes/grammar-extension-separator-negated.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 56.025s | 3 | `test262/test/built-ins/RegExp/property-escapes/generated/Variation_Selector.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/White_Space.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/XID_Continue.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 51.869s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Lisu.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Lycian.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Lydian.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Mahajani.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Makasar.js` |
| staging/sm | NORMAL | 51.866s | 25 | `test262/test/staging/sm/TypedArray/iterator-next-with-detached.js`<br>`test262/test/staging/sm/TypedArray/iterator.js`<br>...<br>`test262/test/staging/sm/TypedArray/slice-memcpy.js`<br>`test262/test/staging/sm/TypedArray/slice-species.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 50.517s | 3 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Samaritan.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Saurashtra.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Sharada.js` |
| built-ins/RegExp/property-escapes | NORMAL | 46.534s | 5 | `test262/test/built-ins/RegExp/property-escapes/unsupported-binary-property-Composition_Exclusion.js`<br>`test262/test/built-ins/RegExp/property-escapes/unsupported-binary-property-Expands_On_NFC-negated.js`<br>`test262/test/built-ins/RegExp/property-escapes/unsupported-binary-property-Expands_On_NFC.js`<br>`test262/test/built-ins/RegExp/property-escapes/unsupported-binary-property-Expands_On_NFD-negated.js`<br>`test262/test/built-ins/RegExp/property-escapes/unsupported-binary-property-Expands_On_NFD.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 45.324s | 3 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Egyptian_Hieroglyphs.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Elbasan.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Elymaic.js` |
| annexB/built-ins/RegExp | NORMAL | 42.531s | 25 | `test262/test/annexB/built-ins/RegExp/RegExp-control-escape-russian-letter.js`<br>`test262/test/annexB/built-ins/RegExp/RegExp-decimal-escape-class-range.js`<br>...<br>`test262/test/annexB/built-ins/RegExp/legacy-accessors/lastParen/this-cross-realm-constructor.js`<br>`test262/test/annexB/built-ins/RegExp/legacy-accessors/lastParen/this-not-regexp-constructor.js` |
| built-ins/RegExp/property-escapes | NORMAL | 41.298s | 3 | `test262/test/built-ins/RegExp/property-escapes/grammar-extension-In-prefix-Script-implicit.js`<br>`test262/test/built-ins/RegExp/property-escapes/grammar-extension-In-prefix-Script-negated.js`<br>`test262/test/built-ins/RegExp/property-escapes/grammar-extension-In-prefix-Script.js` |
| intl402 | NORMAL | 38.733s | 22 | `test262/test/intl402/constructors-string-and-single-element-array.js`<br>`test262/test/intl402/constructors-taint-Object-prototype-2.js`<br>...<br>`test262/test/intl402/supportedLocalesOf-throws-if-element-not-string-or-object.js`<br>`test262/test/intl402/supportedLocalesOf-unicode-extensions-ignored.js` |
| staging/sm | NORMAL | 38.526s | 25 | `test262/test/staging/sm/Set/is-subset-of.js`<br>`test262/test/staging/sm/Set/is-superset-of.js`<br>...<br>`test262/test/staging/sm/String/normalize-generateddata-input.js`<br>`test262/test/staging/sm/String/normalize-generic.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 36.379s | 3 | `test262/test/built-ins/RegExp/property-escapes/generated/Deprecated.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Diacritic.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Emoji.js` |
| staging/sm | NORMAL | 35.756s | 25 | `test262/test/staging/sm/TypedArray/constructor_bad-args.js`<br>`test262/test/staging/sm/TypedArray/detached-array-buffer-checks.js`<br>...<br>`test262/test/staging/sm/TypedArray/indexOf-and-lastIndexOf.js`<br>`test262/test/staging/sm/TypedArray/indexOf-never-returns-negative-zero.js` |
| built-ins/RegExp/property-escapes | NORMAL | 34.534s | 3 | `test262/test/built-ins/RegExp/property-escapes/loose-matching-03.js`<br>`test262/test/built-ins/RegExp/property-escapes/loose-matching-04-negated.js`<br>`test262/test/built-ins/RegExp/property-escapes/loose-matching-04.js` |
| built-ins/RegExp/property-escapes | NORMAL | 34.263s | 3 | `test262/test/built-ins/RegExp/property-escapes/unsupported-binary-property-Expands_On_NFKD-negated.js`<br>`test262/test/built-ins/RegExp/property-escapes/unsupported-binary-property-Expands_On_NFKD.js`<br>`test262/test/built-ins/RegExp/property-escapes/unsupported-binary-property-FC_NFKC_Closure-negated.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are executed as ES modules (always strict, import/export processed).
- Tests flagged as async are skipped by the current runner.
- Mixed-mode tests currently run only in sloppy mode unless onlyStrict or noStrict is set.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

