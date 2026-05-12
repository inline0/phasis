# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-05-12T16:47:19+00:00`
- Chunk size: `25`
- Timeout: `300s`
- Jobs: `4`
- Groups: `159`
- Test files: `50506`
- Git: `main` @ `24d47d1` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 10512 | 4 | 9 | 0 | 40255 | 120 | 10516 | 10525 | 50900 | 100.0% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | INCOMPLETE | 1053 | 0 | 1 | 0 | 25 | 0 | 100.0% |
| built-ins | RUNNING | 5611 | 4 | 0 | 0 | 17388 | 120 | 99.9% |
| harness | PASS | 116 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402 | INCOMPLETE | 923 | 0 | 2 | 0 | 641 | 0 | 100.0% |
| language | INCOMPLETE | 2040 | 0 | 0 | 0 | 21344 | 0 | 100.0% |
| staging | INCOMPLETE | 769 | 0 | 6 | 0 | 857 | 0 | 100.0% |

## Group Coverage

| Group | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB/built-ins/Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Date | PASS | 24 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Function | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Object | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/RegExp | INCOMPLETE | 36 | 0 | 1 | 0 | 25 | 0 | 100.0% |
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
| built-ins/AbstractModuleSource | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| built-ins/Array | PENDING | 0 | 0 | 0 | 0 | 3075 | 0 | n/a |
| built-ins/ArrayBuffer | PENDING | 0 | 0 | 0 | 0 | 192 | 0 | n/a |
| built-ins/ArrayIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 27 | 0 | n/a |
| built-ins/AsyncDisposableStack | PENDING | 0 | 0 | 0 | 0 | 52 | 0 | n/a |
| built-ins/AsyncFromSyncIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 38 | 0 | n/a |
| built-ins/AsyncFunction | PENDING | 0 | 0 | 0 | 0 | 18 | 0 | n/a |
| built-ins/AsyncGeneratorFunction | PENDING | 0 | 0 | 0 | 0 | 23 | 0 | n/a |
| built-ins/AsyncGeneratorPrototype | PENDING | 0 | 0 | 0 | 0 | 48 | 0 | n/a |
| built-ins/AsyncIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 10 | 0 | n/a |
| built-ins/Atomics | PENDING | 0 | 0 | 0 | 0 | 376 | 0 | n/a |
| built-ins/BigInt | PASS | 75 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Boolean | PASS | 51 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/DataView | PASS | 550 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Date | PASS | 594 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/DisposableStack | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Error | PASS | 53 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/FinalizationRegistry | PENDING | 0 | 0 | 0 | 0 | 47 | 0 | n/a |
| built-ins/Function | PENDING | 0 | 0 | 0 | 0 | 509 | 0 | n/a |
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
| built-ins/Object | PENDING | 0 | 0 | 0 | 0 | 3410 | 0 | n/a |
| built-ins/Promise | PENDING | 0 | 0 | 0 | 0 | 631 | 0 | n/a |
| built-ins/Proxy | PENDING | 0 | 0 | 0 | 0 | 311 | 0 | n/a |
| built-ins/Reflect | PENDING | 0 | 0 | 0 | 0 | 153 | 0 | n/a |
| built-ins/RegExp | PENDING | 0 | 0 | 0 | 0 | 488 | 0 | n/a |
| built-ins/RegExp/CharacterClassEscapes | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| built-ins/RegExp/Symbol.species | PENDING | 0 | 0 | 0 | 0 | 4 | 0 | n/a |
| built-ins/RegExp/dotall | PENDING | 0 | 0 | 0 | 0 | 4 | 0 | n/a |
| built-ins/RegExp/escape | PENDING | 0 | 0 | 0 | 0 | 20 | 0 | n/a |
| built-ins/RegExp/lookBehind | PENDING | 0 | 0 | 0 | 0 | 17 | 0 | n/a |
| built-ins/RegExp/match-indices | PENDING | 0 | 0 | 0 | 0 | 14 | 0 | n/a |
| built-ins/RegExp/named-groups | PENDING | 0 | 0 | 0 | 0 | 36 | 0 | n/a |
| built-ins/RegExp/property-escapes | INCOMPLETE | 120 | 0 | 0 | 0 | 166 | 0 | 100.0% |
| built-ins/RegExp/property-escapes/generated | RUNNING | 16 | 4 | 0 | 0 | 570 | 120 | 80.0% |
| built-ins/RegExp/prototype | PENDING | 0 | 0 | 0 | 0 | 487 | 0 | n/a |
| built-ins/RegExp/regexp-modifiers | PENDING | 0 | 0 | 0 | 0 | 70 | 0 | n/a |
| built-ins/RegExp/unicodeSets | PENDING | 0 | 0 | 0 | 0 | 113 | 0 | n/a |
| built-ins/RegExpStringIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 17 | 0 | n/a |
| built-ins/Set | INCOMPLETE | 300 | 0 | 0 | 0 | 81 | 0 | 100.0% |
| built-ins/SetIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| built-ins/ShadowRealm | INCOMPLETE | 50 | 0 | 0 | 0 | 14 | 0 | 100.0% |
| built-ins/SharedArrayBuffer | INCOMPLETE | 75 | 0 | 0 | 0 | 29 | 0 | 100.0% |
| built-ins/String | INCOMPLETE | 1075 | 0 | 0 | 0 | 137 | 0 | 100.0% |
| built-ins/StringIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| built-ins/Symbol | INCOMPLETE | 50 | 0 | 0 | 0 | 44 | 0 | 100.0% |
| built-ins/Temporal | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| built-ins/Temporal/Duration | PENDING | 0 | 0 | 0 | 0 | 473 | 0 | n/a |
| built-ins/Temporal/Instant | PENDING | 0 | 0 | 0 | 0 | 434 | 0 | n/a |
| built-ins/Temporal/Now | PENDING | 0 | 0 | 0 | 0 | 66 | 0 | n/a |
| built-ins/Temporal/PlainDate | PENDING | 0 | 0 | 0 | 0 | 592 | 0 | n/a |
| built-ins/Temporal/PlainDateTime | PASS | 684 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Temporal/PlainMonthDay | PENDING | 0 | 0 | 0 | 0 | 184 | 0 | n/a |
| built-ins/Temporal/PlainTime | PENDING | 0 | 0 | 0 | 0 | 457 | 0 | n/a |
| built-ins/Temporal/PlainYearMonth | PENDING | 0 | 0 | 0 | 0 | 465 | 0 | n/a |
| built-ins/Temporal/ZonedDateTime | PENDING | 0 | 0 | 0 | 0 | 805 | 0 | n/a |
| built-ins/Temporal/toStringTag | PENDING | 0 | 0 | 0 | 0 | 2 | 0 | n/a |
| built-ins/ThrowTypeError | PENDING | 0 | 0 | 0 | 0 | 14 | 0 | n/a |
| built-ins/TypedArray | PENDING | 0 | 0 | 0 | 0 | 1426 | 0 | n/a |
| built-ins/TypedArrayConstructors | PENDING | 0 | 0 | 0 | 0 | 736 | 0 | n/a |
| built-ins/Uint8Array | PASS | 64 | 0 | 0 | 0 | 0 | 0 | 100.0% |
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
| built-ins/parseFloat | PASS | 59 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseInt | PASS | 60 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/undefined | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| harness | PASS | 116 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402 | PENDING | 0 | 0 | 0 | 0 | 22 | 0 | n/a |
| intl402/Array | PENDING | 0 | 0 | 0 | 0 | 2 | 0 | n/a |
| intl402/BigInt | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| intl402/Collator | INCOMPLETE | 25 | 0 | 0 | 0 | 37 | 0 | 100.0% |
| intl402/Date | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| intl402/DateTimeFormat | INCOMPLETE | 150 | 0 | 0 | 0 | 38 | 0 | 100.0% |
| intl402/DisplayNames | INCOMPLETE | 50 | 0 | 0 | 0 | 7 | 0 | 100.0% |
| intl402/DurationFormat | INCOMPLETE | 25 | 0 | 0 | 0 | 85 | 0 | 100.0% |
| intl402/Intl | INCOMPLETE | 25 | 0 | 0 | 0 | 42 | 0 | 100.0% |
| intl402/ListFormat | INCOMPLETE | 75 | 0 | 0 | 0 | 6 | 0 | 100.0% |
| intl402/Locale | INCOMPLETE | 125 | 0 | 0 | 0 | 22 | 0 | 100.0% |
| intl402/Number | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| intl402/NumberFormat | INCOMPLETE | 150 | 0 | 0 | 0 | 102 | 0 | 100.0% |
| intl402/PluralRules | INCOMPLETE | 25 | 0 | 0 | 0 | 25 | 0 | 100.0% |
| intl402/RelativeTimeFormat | PENDING | 0 | 0 | 0 | 0 | 79 | 0 | n/a |
| intl402/Segmenter | INCOMPLETE | 25 | 0 | 0 | 0 | 53 | 0 | 100.0% |
| intl402/String | PENDING | 0 | 0 | 0 | 0 | 17 | 0 | n/a |
| intl402/Temporal | INCOMPLETE | 248 | 0 | 2 | 0 | 73 | 0 | 100.0% |
| intl402/TypedArray | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/arguments-object | PENDING | 0 | 0 | 0 | 0 | 263 | 0 | n/a |
| language/asi | PENDING | 0 | 0 | 0 | 0 | 102 | 0 | n/a |
| language/block-scope | PENDING | 0 | 0 | 0 | 0 | 145 | 0 | n/a |
| language/comments | PENDING | 0 | 0 | 0 | 0 | 52 | 0 | n/a |
| language/computed-property-names | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
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
| language/punctuators | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/reserved-words | PENDING | 0 | 0 | 0 | 0 | 27 | 0 | n/a |
| language/rest-parameters | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/source-text | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/statementList | PENDING | 0 | 0 | 0 | 0 | 80 | 0 | n/a |
| language/statements | INCOMPLETE | 1925 | 0 | 0 | 0 | 7229 | 0 | 100.0% |
| language/types | PENDING | 0 | 0 | 0 | 0 | 113 | 0 | n/a |
| language/white-space | PASS | 67 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/Intl402 | PENDING | 0 | 0 | 0 | 0 | 49 | 0 | n/a |
| staging/Temporal | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| staging/Uint8Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/built-ins | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| staging/decorators | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/explicit-resource-management | INCOMPLETE | 50 | 0 | 0 | 0 | 4 | 0 | 100.0% |
| staging/set-methods | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/sm | INCOMPLETE | 669 | 0 | 6 | 0 | 753 | 0 | 100.0% |
| staging/source-phase-imports | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/upsert | INCOMPLETE | 50 | 0 | 0 | 0 | 21 | 0 | 100.0% |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| intl402/Temporal | NORMAL | 15.860s | 25 | `test262/test/intl402/Temporal/ZonedDateTime/prototype/equals/offset-and-iana.js`<br>`test262/test/intl402/Temporal/ZonedDateTime/prototype/equals/sub-minute-offset.js`<br>...<br>`test262/test/intl402/Temporal/ZonedDateTime/prototype/since/sub-minute-offset.js`<br>`test262/test/intl402/Temporal/ZonedDateTime/prototype/startOfDay/dst-skipped-cross-midnight.js` |
| intl402/NumberFormat | NORMAL | 12.570s | 25 | `test262/test/intl402/NumberFormat/prototype/this-value-numberformat-prototype.js`<br>`test262/test/intl402/NumberFormat/prototype/toStringTag/configurable.js`<br>...<br>`test262/test/intl402/NumberFormat/throws-for-maximumFractionDigits-over-limit.js`<br>`test262/test/intl402/NumberFormat/throws-for-maximumFractionDigits-under-limit.js` |
| staging/sm | NORMAL | 8.708s | 25 | `test262/test/staging/sm/expressions/destructuring-array-done.js`<br>`test262/test/staging/sm/expressions/destructuring-array-lexical.js`<br>...<br>`test262/test/staging/sm/expressions/short-circuit-compound-assignment-property-key-evaluation.js`<br>`test262/test/staging/sm/expressions/short-circuit-compound-assignment-tdz.js` |
| intl402/NumberFormat | NORMAL | 8.688s | 25 | `test262/test/intl402/NumberFormat/prototype/builtin.js`<br>`test262/test/intl402/NumberFormat/prototype/constructor/prop-desc.js`<br>...<br>`test262/test/intl402/NumberFormat/prototype/format/format-rounding-increment-1000.js`<br>`test262/test/intl402/NumberFormat/prototype/format/format-rounding-increment-2.js` |
| intl402/NumberFormat | NORMAL | 8.165s | 25 | `test262/test/intl402/NumberFormat/prototype/format/signDisplay-negative-en-US.js`<br>`test262/test/intl402/NumberFormat/prototype/format/signDisplay-negative-ja-JP.js`<br>...<br>`test262/test/intl402/NumberFormat/prototype/formatRange/argument-to-Intlmathematicalvalue-throws.js`<br>`test262/test/intl402/NumberFormat/prototype/formatRange/builtin.js` |
| annexB/built-ins/String | NORMAL | 8.071s | 25 | `test262/test/annexB/built-ins/String/prototype/strike/this-val-tostring-err.js`<br>`test262/test/annexB/built-ins/String/prototype/sub/B.2.3.13.js`<br>...<br>`test262/test/annexB/built-ins/String/prototype/sup/length.js`<br>`test262/test/annexB/built-ins/String/prototype/sup/name.js` |
| staging/sm | NORMAL | 7.915s | 25 | `test262/test/staging/sm/class/innerBinding.js`<br>`test262/test/staging/sm/class/methDefn.js`<br>...<br>`test262/test/staging/sm/class/stringConstructor.js`<br>`test262/test/staging/sm/class/subclassedArrayUnboxed.js` |
| staging/sm | NORMAL | 6.927s | 25 | `test262/test/staging/sm/RegExp/split-trace.js`<br>`test262/test/staging/sm/RegExp/split.js`<br>...<br>`test262/test/staging/sm/Set/intersection.js`<br>`test262/test/staging/sm/Set/is-disjoint-from.js` |
| staging/sm | NORMAL | 6.796s | 25 | `test262/test/staging/sm/Math/atanh-approx.js`<br>`test262/test/staging/sm/Math/atanh-exact.js`<br>...<br>`test262/test/staging/sm/Math/tanh-exact.js`<br>`test262/test/staging/sm/Math/trunc.js` |
| intl402/Intl | NORMAL | 6.672s | 25 | `test262/test/intl402/Intl/DateTimeFormat/prototype/formatRange/fails-on-distinct-temporal-types.js`<br>`test262/test/intl402/Intl/DateTimeFormat/prototype/formatRangeToParts/fails-on-distinct-temporal-types.js`<br>...<br>`test262/test/intl402/Intl/getCanonicalLocales/overriden-push.js`<br>`test262/test/intl402/Intl/getCanonicalLocales/preferred-grandfathered.js` |
| staging/sm | NORMAL | 6.651s | 25 | `test262/test/staging/sm/Array/redefine-length-frozen-array.js`<br>`test262/test/staging/sm/Array/redefine-length-frozen-dictionarymode-array.js`<br>...<br>`test262/test/staging/sm/Array/species.js`<br>`test262/test/staging/sm/Array/splice-return-array-elements-defined-not-set.js` |
| intl402/DurationFormat | NORMAL | 6.354s | 25 | `test262/test/intl402/DurationFormat/prototype/formatToParts/invalid-negative-duration-throws.js`<br>`test262/test/intl402/DurationFormat/prototype/formatToParts/length.js`<br>...<br>`test262/test/intl402/DurationFormat/prototype/resolvedOptions/return-keys-order-default.js`<br>`test262/test/intl402/DurationFormat/prototype/resolvedOptions/throw-invoked-as-func.js` |
| language/statements | NORMAL | 6.029s | 25 | `test262/test/language/statements/await-using/throws-if-initializer-not-object.js`<br>`test262/test/language/statements/block/12.1-1.js`<br>...<br>`test262/test/language/statements/break/S12.8_A1_T1.js`<br>`test262/test/language/statements/break/S12.8_A1_T2.js` |
| built-ins/Temporal/PlainDateTime | NORMAL | 5.951s | 25 | `test262/test/built-ins/Temporal/PlainDateTime/prototype/round/roundingincrement-out-of-range.js`<br>`test262/test/built-ins/Temporal/PlainDateTime/prototype/round/roundingincrement-undefined.js`<br>...<br>`test262/test/built-ins/Temporal/PlainDateTime/prototype/round/throws-argument-object-insufficient-data.js`<br>`test262/test/built-ins/Temporal/PlainDateTime/prototype/round/throws-argument-object.js` |
| built-ins/Temporal/PlainDateTime | NORMAL | 5.888s | 25 | `test262/test/built-ins/Temporal/PlainDateTime/from/overflow-wrong-type.js`<br>`test262/test/built-ins/Temporal/PlainDateTime/from/parser.js`<br>...<br>`test262/test/built-ins/Temporal/PlainDateTime/prototype/add/argument-invalid-property.js`<br>`test262/test/built-ins/Temporal/PlainDateTime/prototype/add/argument-mixed-sign.js` |
| staging/sm | NORMAL | 5.823s | 25 | `test262/test/staging/sm/Array/array-length-set-during-for-in.js`<br>`test262/test/staging/sm/Array/array-length-set-on-nonarray.js`<br>...<br>`test262/test/staging/sm/Array/from_string.js`<br>`test262/test/staging/sm/Array/from_surfaces.js` |
| intl402/Segmenter | NORMAL | 5.782s | 25 | `test262/test/intl402/Segmenter/prototype/resolvedOptions/order.js`<br>`test262/test/intl402/Segmenter/prototype/resolvedOptions/prop-desc.js`<br>...<br>`test262/test/intl402/Segmenter/prototype/segment/segment-sentence-iterable.js`<br>`test262/test/intl402/Segmenter/prototype/segment/segment-tostring.js` |
| built-ins/Temporal/PlainDateTime | NORMAL | 5.643s | 25 | `test262/test/built-ins/Temporal/PlainDateTime/prototype/until/order-of-operations.js`<br>`test262/test/built-ins/Temporal/PlainDateTime/prototype/until/prop-desc.js`<br>...<br>`test262/test/built-ins/Temporal/PlainDateTime/prototype/until/roundingmode-invalid-string.js`<br>`test262/test/built-ins/Temporal/PlainDateTime/prototype/until/roundingmode-trunc-is-default.js` |
| staging/sm | NORMAL | 5.322s | 25 | `test262/test/staging/sm/JSON/stringify-replacer-array-boxed-elements.js`<br>`test262/test/staging/sm/JSON/stringify-replacer-array-duplicated-element.js`<br>...<br>`test262/test/staging/sm/Math/asinh-approx.js`<br>`test262/test/staging/sm/Math/asinh-exact.js` |
| built-ins/Temporal/PlainDateTime | NORMAL | 5.258s | 25 | `test262/test/built-ins/Temporal/PlainDateTime/prototype/since/roundingmode-trunc-is-default.js`<br>`test262/test/built-ins/Temporal/PlainDateTime/prototype/since/roundingmode-trunc.js`<br>...<br>`test262/test/built-ins/Temporal/PlainDateTime/prototype/subtract/argument-string.js`<br>`test262/test/built-ins/Temporal/PlainDateTime/prototype/subtract/balance-negative-time-units.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are executed as ES modules (always strict, import/export processed).
- Tests flagged as async are skipped by the current runner.
- Mixed-mode tests currently run only in sloppy mode unless onlyStrict or noStrict is set.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

