# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-04-28T20:58:05+00:00`
- Chunk size: `25`
- Timeout: `300s`
- Jobs: `4`
- Groups: `159`
- Test files: `50506`
- Git: `main` @ `4b3a246` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 49249 | 368 | 403 | 16 | 807 | 0 | 49617 | 50036 | 50843 | 99.3% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | PASS | 1072 | 0 | 7 | 0 | 0 | 0 | 100.0% |
| built-ins | INCOMPLETE | 21852 | 131 | 279 | 8 | 571 | 0 | 99.4% |
| harness | PARTIAL | 111 | 3 | 2 | 0 | 0 | 0 | 97.4% |
| intl402 | PARTIAL | 1552 | 4 | 10 | 0 | 0 | 0 | 99.7% |
| language | INCOMPLETE | 23204 | 150 | 19 | 0 | 11 | 0 | 99.4% |
| staging | INCOMPLETE | 1458 | 80 | 86 | 8 | 225 | 0 | 94.8% |

## Group Coverage

| Group | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB/built-ins/Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Date | PASS | 24 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Function | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Object | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/RegExp | PASS | 55 | 0 | 7 | 0 | 0 | 0 | 100.0% |
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
| built-ins/AbstractModuleSource | FAIL | 0 | 8 | 0 | 0 | 0 | 0 | 0.0% |
| built-ins/Array | PARTIAL | 3051 | 8 | 16 | 0 | 0 | 0 | 99.7% |
| built-ins/ArrayBuffer | PARTIAL | 190 | 1 | 1 | 0 | 0 | 0 | 99.5% |
| built-ins/ArrayIteratorPrototype | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncDisposableStack | PASS | 51 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncFromSyncIteratorPrototype | PASS | 38 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncFunction | PASS | 17 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorFunction | PASS | 21 | 0 | 2 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorPrototype | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncIteratorPrototype | PASS | 10 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Atomics | PASS | 260 | 0 | 116 | 0 | 0 | 0 | 100.0% |
| built-ins/BigInt | PASS | 74 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/Boolean | PASS | 50 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/DataView | PARTIAL | 546 | 2 | 2 | 0 | 0 | 0 | 99.6% |
| built-ins/Date | PASS | 591 | 0 | 3 | 0 | 0 | 0 | 100.0% |
| built-ins/DisposableStack | PASS | 51 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/Error | PASS | 50 | 0 | 3 | 0 | 0 | 0 | 100.0% |
| built-ins/FinalizationRegistry | PASS | 46 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/Function | PASS | 496 | 0 | 13 | 0 | 0 | 0 | 100.0% |
| built-ins/GeneratorFunction | PASS | 21 | 0 | 2 | 0 | 0 | 0 | 100.0% |
| built-ins/GeneratorPrototype | PASS | 61 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Infinity | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Iterator | PASS | 430 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/JSON | PASS | 163 | 0 | 2 | 0 | 0 | 0 | 100.0% |
| built-ins/Map | PASS | 170 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/MapIteratorPrototype | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Math | PASS | 327 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/NaN | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/NativeErrors | PASS | 131 | 0 | 8 | 0 | 0 | 0 | 100.0% |
| built-ins/Number | PASS | 334 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/Object | PARTIAL | 3408 | 1 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/Promise | PASS | 630 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/Proxy | PARTIAL | 273 | 1 | 37 | 0 | 0 | 0 | 99.6% |
| built-ins/Reflect | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp | PARTIAL | 479 | 8 | 1 | 0 | 0 | 0 | 98.4% |
| built-ins/RegExp/CharacterClassEscapes | INCOMPLETE | 6 | 0 | 0 | 6 | 12 | 0 | 100.0% |
| built-ins/RegExp/Symbol.species | PASS | 4 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/dotall | FAIL | 0 | 4 | 0 | 0 | 0 | 0 | 0.0% |
| built-ins/RegExp/escape | PASS | 19 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/lookBehind | PARTIAL | 3 | 14 | 0 | 0 | 0 | 0 | 17.6% |
| built-ins/RegExp/match-indices | PARTIAL | 13 | 1 | 0 | 0 | 0 | 0 | 92.9% |
| built-ins/RegExp/named-groups | PARTIAL | 32 | 4 | 0 | 0 | 0 | 0 | 88.9% |
| built-ins/RegExp/property-escapes | PASS | 143 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/property-escapes/generated | PENDING | 0 | 0 | 0 | 0 | 459 | 0 | n/a |
| built-ins/RegExp/prototype | PARTIAL | 473 | 4 | 10 | 0 | 0 | 0 | 99.2% |
| built-ins/RegExp/regexp-modifiers | PARTIAL | 59 | 11 | 0 | 0 | 0 | 0 | 84.3% |
| built-ins/RegExp/unicodeSets | PARTIAL | 85 | 28 | 0 | 0 | 0 | 0 | 75.2% |
| built-ins/RegExpStringIteratorPrototype | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Set | PASS | 380 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/SetIteratorPrototype | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ShadowRealm | PARTIAL | 60 | 1 | 3 | 0 | 0 | 0 | 98.4% |
| built-ins/SharedArrayBuffer | PASS | 103 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/String | PARTIAL | 1204 | 5 | 3 | 0 | 0 | 0 | 99.6% |
| built-ins/StringIteratorPrototype | PASS | 7 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Symbol | PASS | 79 | 0 | 15 | 0 | 0 | 0 | 100.0% |
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
| built-ins/ThrowTypeError | PASS | 13 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/TypedArray | PARTIAL | 1399 | 27 | 0 | 0 | 0 | 0 | 98.1% |
| built-ins/TypedArrayConstructors | PARTIAL | 709 | 3 | 24 | 0 | 0 | 0 | 99.6% |
| built-ins/Uint8Array | PASS | 64 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakMap | PASS | 101 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakRef | PASS | 28 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakSet | PASS | 84 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/decodeURI | INCOMPLETE | 54 | 0 | 0 | 1 | 50 | 0 | 100.0% |
| built-ins/decodeURIComponent | INCOMPLETE | 55 | 0 | 0 | 1 | 50 | 0 | 100.0% |
| built-ins/encodeURI | PASS | 31 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/encodeURIComponent | PASS | 31 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/eval | PASS | 10 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/global | PASS | 29 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isFinite | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isNaN | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseFloat | PASS | 59 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseInt | PASS | 60 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/undefined | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| harness | PARTIAL | 111 | 3 | 2 | 0 | 0 | 0 | 97.4% |
| intl402 | PASS | 22 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Array | PASS | 2 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/BigInt | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Collator | PASS | 61 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| intl402/Date | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/DateTimeFormat | PARTIAL | 186 | 1 | 1 | 0 | 0 | 0 | 99.5% |
| intl402/DisplayNames | PASS | 56 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| intl402/DurationFormat | PASS | 110 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Intl | PASS | 67 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/ListFormat | PASS | 80 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| intl402/Locale | PASS | 146 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| intl402/Number | PASS | 7 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/NumberFormat | PASS | 251 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| intl402/PluralRules | PASS | 49 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| intl402/RelativeTimeFormat | PASS | 78 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| intl402/Segmenter | PASS | 76 | 0 | 2 | 0 | 0 | 0 | 100.0% |
| intl402/String | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/Temporal | PARTIAL | 320 | 3 | 0 | 0 | 0 | 0 | 99.1% |
| intl402/TypedArray | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/arguments-object | PASS | 263 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/asi | PASS | 102 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/block-scope | PARTIAL | 142 | 3 | 0 | 0 | 0 | 0 | 97.9% |
| language/comments | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/computed-property-names | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/destructuring | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/directive-prologue | PASS | 62 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/eval-code | PASS | 346 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| language/export | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/expressions | PARTIAL | 10962 | 45 | 16 | 0 | 0 | 0 | 99.6% |
| language/function-code | PARTIAL | 214 | 3 | 0 | 0 | 0 | 0 | 98.6% |
| language/future-reserved-words | PASS | 55 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/global-code | PASS | 42 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifier-resolution | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifiers | PASS | 260 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/import | PARTIAL | 19 | 66 | 0 | 0 | 0 | 0 | 22.4% |
| language/keywords | PASS | 25 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/line-terminators | PASS | 41 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/literals | PARTIAL | 532 | 2 | 0 | 0 | 0 | 0 | 99.6% |
| language/module-code | PARTIAL | 577 | 6 | 0 | 0 | 0 | 0 | 99.0% |
| language/punctuators | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/reserved-words | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/rest-parameters | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/source-text | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/statementList | PASS | 80 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/statements | PARTIAL | 9129 | 25 | 0 | 0 | 0 | 0 | 99.7% |
| language/types | PASS | 111 | 0 | 2 | 0 | 0 | 0 | 100.0% |
| language/white-space | PASS | 67 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Intl402 | PARTIAL | 44 | 5 | 0 | 0 | 0 | 0 | 89.8% |
| staging/Temporal | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Uint8Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/built-ins | PARTIAL | 2 | 5 | 0 | 0 | 0 | 0 | 28.6% |
| staging/decorators | PARTIAL | 2 | 1 | 0 | 0 | 0 | 0 | 66.7% |
| staging/explicit-resource-management | PARTIAL | 52 | 1 | 1 | 0 | 0 | 0 | 98.1% |
| staging/set-methods | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/sm | INCOMPLETE | 1268 | 67 | 85 | 8 | 225 | 0 | 95.0% |
| staging/source-phase-imports | FAIL | 0 | 1 | 0 | 0 | 0 | 0 | 0.0% |
| staging/upsert | PASS | 71 | 0 | 0 | 0 | 0 | 0 | 100.0% |

## Blocked Chunks

| Group | Kind | Files | Sample | Log |
|---|---|---:|---|---|
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-digit-class-escape-positive-cases.js` | `.compat-state-builtins-RegExp-rest/logs/722abcc8bbca8f22a6f4e5029b251630a2d88ff7.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-digit-class-escape-negative-cases.js` | `.compat-state-builtins-RegExp-rest/logs/04ddd46937901b97333e1bad5a7183629e181178.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-whitespace-class-escape-positive-cases.js` | `.compat-state-builtins-RegExp-rest/logs/9022b8c5942fd25645927bb1658a3645cd98d06e.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-word-class-escape-negative-cases.js` | `.compat-state-builtins-RegExp-rest/logs/f81d5d8efb2c4dd1df1c2faa058ff85302c5855b.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-word-class-escape-positive-cases.js` | `.compat-state-builtins-RegExp-rest/logs/5af0de9dd6d6784c7156b505489408bfaf27e665.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-whitespace-class-escape-negative-cases.js` | `.compat-state-builtins-RegExp-rest/logs/f0ebbf279665e1ee7193d8369fb48c919e0422c2.log` |
| built-ins/decodeURI | CRASH | 1 | `test262/test/built-ins/decodeURI/S15.1.3.1_A2.5_T1.js` | `.compat-state-builtins-lower-de/logs/6687624bd5fb87a6a4ff167cd35af4b69d14f50d.log` |
| built-ins/decodeURIComponent | CRASH | 1 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A2.5_T1.js` | `.compat-state-builtins-lower-de/logs/5a3e033d7be8e84f9d36d0440da488ff5b100e26.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/regress/regress-1507322-deep-weakmap.js` | `.compat-state-staging/logs/59f611b1d8f6087de2cf8dd8fc9a3ce8ba03416e.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Array/toSpliced-dense.js` | `.compat-state-staging/logs/1d8bc8ec59afa4977eac427273dcca4bb1c34b5e.log` |
| staging/sm | OOM | 1 | `test262/test/staging/sm/extensions/recursion.js` | `.compat-state-staging/logs/3ad67b073406a0c3ef95b4d8a9815281b53dfe58.log` |
| staging/sm | OOM | 1 | `test262/test/staging/sm/String/replace-math.js` | `.compat-state-staging/logs/05d8c12e9055c0e405b4fc8ebd3aee35b95f8c6f.log` |
| staging/sm | OOM | 1 | `test262/test/staging/sm/regress/regress-610026.js` | `.compat-state-staging/logs/537ed06105d89d7ac3ee10433e9263eb05ce1aab.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/TypedArray/sort_large_countingsort.js` | `.compat-state-staging/logs/f1232104e4d338c7c76a723ace84621f84895ca1.log` |
| staging/sm | OOM | 1 | `test262/test/staging/sm/regress/regress-567152.js` | `.compat-state-staging/logs/f2fc29f0b2ecc140a7956dac1e2335e2f3b2da5e.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/TypedArray/element-setting-converts-using-ToNumber.js` | `.compat-state-staging/logs/655e0e3f8baf6b2fbdfb85776837eb8131d920b4.log` |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| staging/sm | NORMAL | 80.498s | 25 | `test262/test/staging/sm/expressions/destructuring-object-__proto__-2.js`<br>`test262/test/staging/sm/expressions/destructuring-pattern-parenthesized.js`<br>...<br>`test262/test/staging/sm/expressions/string-literal-escape-sequences.js`<br>`test262/test/staging/sm/expressions/tagged-template-constant-folding.js` |
| staging/sm | NORMAL | 70.255s | 2 | `test262/test/staging/sm/TypedArray/sort_modifications.js`<br>`test262/test/staging/sm/TypedArray/sort_small.js` |
| language/literals | NORMAL | 65.383s | 25 | `test262/test/language/literals/regexp/7.8.5-2gs.js`<br>`test262/test/language/literals/regexp/S7.8.5_A1.1_T1.js`<br>...<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T2.js` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 61.568s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-word-class-escape-negative-cases.js` |
| staging/sm | CRASH | 61.205s | 1 | `test262/test/staging/sm/TypedArray/sort_large_countingsort.js` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 60.955s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-digit-class-escape-negative-cases.js` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 60.829s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-whitespace-class-escape-positive-cases.js` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 60.811s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-whitespace-class-escape-negative-cases.js` |
| staging/sm | CRASH | 60.805s | 1 | `test262/test/staging/sm/regress/regress-1507322-deep-weakmap.js` |
| staging/sm | CRASH | 60.768s | 1 | `test262/test/staging/sm/Array/toSpliced-dense.js` |
| staging/sm | NORMAL | 60.754s | 25 | `test262/test/staging/sm/TypedArray/reduce-and-reduceRight.js`<br>`test262/test/staging/sm/TypedArray/reverse.js`<br>...<br>`test262/test/staging/sm/TypedArray/sort_errors.js`<br>`test262/test/staging/sm/TypedArray/sort_globals.js` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 60.217s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-digit-class-escape-positive-cases.js` |
| staging/sm | OOM | 56.086s | 1 | `test262/test/staging/sm/regress/regress-567152.js` |
| staging/sm | NORMAL | 53.444s | 13 | `test262/test/staging/sm/Temporal/PlainDate/from-constrain-hebrew.js`<br>`test262/test/staging/sm/Temporal/PlainDate/from-constrain-japanese.js`<br>...<br>`test262/test/staging/sm/Temporal/PlainMonthDay/from-coptic.js`<br>`test262/test/staging/sm/Temporal/PlainMonthDay/from-gregory.js` |
| language/expressions | NORMAL | 51.090s | 25 | `test262/test/language/expressions/call/spread-sngl-iter.js`<br>`test262/test/language/expressions/call/spread-sngl-literal.js`<br>...<br>`test262/test/language/expressions/class/accessor-name-inst/literal-numeric-zero.js`<br>`test262/test/language/expressions/class/accessor-name-inst/literal-string-char-escape.js` |
| built-ins/decodeURIComponent | NORMAL | 49.545s | 3 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.10_T1.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.11_T1.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.11_T2.js` |
| built-ins/decodeURI | NORMAL | 48.352s | 3 | `test262/test/built-ins/decodeURI/S15.1.3.1_A1.10_T1.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.11_T1.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.11_T2.js` |
| built-ins/decodeURIComponent | NORMAL | 47.799s | 3 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.12_T1.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.12_T2.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.12_T3.js` |
| staging/sm | NORMAL | 47.665s | 25 | `test262/test/staging/sm/String/string-pad-start-end.js`<br>`test262/test/staging/sm/String/string-space-trim.js`<br>...<br>`test262/test/staging/sm/Symbol/property-reflection.js`<br>`test262/test/staging/sm/Symbol/realms.js` |
| staging/sm | CRASH | 46.542s | 1 | `test262/test/staging/sm/TypedArray/element-setting-converts-using-ToNumber.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are executed as ES modules (always strict, import/export processed).
- Tests flagged as async are skipped by the current runner.
- Mixed-mode tests currently run only in sloppy mode unless onlyStrict or noStrict is set.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

