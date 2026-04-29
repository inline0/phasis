# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-04-29T00:04:27+00:00`
- Chunk size: `25`
- Timeout: `300s`
- Jobs: `4`
- Groups: `159`
- Test files: `50506`
- Git: `main` @ `11d330c` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 49351 | 266 | 403 | 16 | 807 | 0 | 49617 | 50036 | 50843 | 99.5% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | PASS | 1072 | 0 | 7 | 0 | 0 | 0 | 100.0% |
| built-ins | INCOMPLETE | 21894 | 89 | 279 | 8 | 571 | 0 | 99.6% |
| harness | PARTIAL | 111 | 3 | 2 | 0 | 0 | 0 | 97.4% |
| intl402 | PARTIAL | 1552 | 4 | 10 | 0 | 0 | 0 | 99.7% |
| language | INCOMPLETE | 23255 | 99 | 19 | 0 | 11 | 0 | 99.6% |
| staging | INCOMPLETE | 1467 | 71 | 86 | 8 | 225 | 0 | 95.4% |

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
| built-ins/Array | PARTIAL | 3058 | 1 | 16 | 0 | 0 | 0 | 100.0% |
| built-ins/ArrayBuffer | PASS | 191 | 0 | 1 | 0 | 0 | 0 | 100.0% |
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
| built-ins/DataView | PASS | 548 | 0 | 2 | 0 | 0 | 0 | 100.0% |
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
| built-ins/Object | PASS | 3409 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/Promise | PASS | 630 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/Proxy | PASS | 274 | 0 | 37 | 0 | 0 | 0 | 100.0% |
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
| built-ins/TypedArray | PASS | 1426 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/TypedArrayConstructors | PASS | 712 | 0 | 24 | 0 | 0 | 0 | 100.0% |
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
| language/block-scope | PASS | 145 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/comments | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/computed-property-names | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/destructuring | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/directive-prologue | PASS | 62 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/eval-code | PASS | 346 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| language/export | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/expressions | PARTIAL | 10972 | 35 | 16 | 0 | 0 | 0 | 99.7% |
| language/function-code | PASS | 217 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/future-reserved-words | PASS | 55 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/global-code | PASS | 42 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifier-resolution | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifiers | PASS | 260 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/import | PARTIAL | 29 | 56 | 0 | 0 | 0 | 0 | 34.1% |
| language/keywords | PASS | 25 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/line-terminators | PASS | 41 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/literals | PARTIAL | 532 | 2 | 0 | 0 | 0 | 0 | 99.6% |
| language/module-code | PARTIAL | 577 | 6 | 0 | 0 | 0 | 0 | 99.0% |
| language/punctuators | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/reserved-words | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/rest-parameters | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/source-text | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/statementList | PASS | 80 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/statements | PASS | 9154 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/types | PASS | 111 | 0 | 2 | 0 | 0 | 0 | 100.0% |
| language/white-space | PASS | 67 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Intl402 | PARTIAL | 44 | 5 | 0 | 0 | 0 | 0 | 89.8% |
| staging/Temporal | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Uint8Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/built-ins | PARTIAL | 5 | 2 | 0 | 0 | 0 | 0 | 71.4% |
| staging/decorators | PARTIAL | 2 | 1 | 0 | 0 | 0 | 0 | 66.7% |
| staging/explicit-resource-management | PASS | 53 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| staging/set-methods | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/sm | INCOMPLETE | 1273 | 62 | 85 | 8 | 225 | 0 | 95.4% |
| staging/source-phase-imports | FAIL | 0 | 1 | 0 | 0 | 0 | 0 | 0.0% |
| staging/upsert | PASS | 71 | 0 | 0 | 0 | 0 | 0 | 100.0% |

## Blocked Chunks

| Group | Kind | Files | Sample | Log |
|---|---|---:|---|---|
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-digit-class-escape-negative-cases.js` | `.compat-state-builtins-RegExp-rest/logs/04ddd46937901b97333e1bad5a7183629e181178.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-digit-class-escape-positive-cases.js` | `.compat-state-builtins-RegExp-rest/logs/722abcc8bbca8f22a6f4e5029b251630a2d88ff7.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-whitespace-class-escape-positive-cases.js` | `.compat-state-builtins-RegExp-rest/logs/9022b8c5942fd25645927bb1658a3645cd98d06e.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-word-class-escape-negative-cases.js` | `.compat-state-builtins-RegExp-rest/logs/f81d5d8efb2c4dd1df1c2faa058ff85302c5855b.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-word-class-escape-positive-cases.js` | `.compat-state-builtins-RegExp-rest/logs/5af0de9dd6d6784c7156b505489408bfaf27e665.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-whitespace-class-escape-negative-cases.js` | `.compat-state-builtins-RegExp-rest/logs/f0ebbf279665e1ee7193d8369fb48c919e0422c2.log` |
| built-ins/decodeURIComponent | CRASH | 1 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A2.5_T1.js` | `.compat-state-builtins-lower-de/logs/5a3e033d7be8e84f9d36d0440da488ff5b100e26.log` |
| built-ins/decodeURI | CRASH | 1 | `test262/test/built-ins/decodeURI/S15.1.3.1_A2.5_T1.js` | `.compat-state-builtins-lower-de/logs/6687624bd5fb87a6a4ff167cd35af4b69d14f50d.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/regress/regress-1507322-deep-weakmap.js` | `.compat-state-staging/logs/59f611b1d8f6087de2cf8dd8fc9a3ce8ba03416e.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Array/toSpliced-dense.js` | `.compat-state-staging/logs/1d8bc8ec59afa4977eac427273dcca4bb1c34b5e.log` |
| staging/sm | OOM | 1 | `test262/test/staging/sm/String/replace-math.js` | `.compat-state-staging/logs/05d8c12e9055c0e405b4fc8ebd3aee35b95f8c6f.log` |
| staging/sm | OOM | 1 | `test262/test/staging/sm/extensions/recursion.js` | `.compat-state-staging/logs/3ad67b073406a0c3ef95b4d8a9815281b53dfe58.log` |
| staging/sm | OOM | 1 | `test262/test/staging/sm/regress/regress-610026.js` | `.compat-state-staging/logs/537ed06105d89d7ac3ee10433e9263eb05ce1aab.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/TypedArray/sort_large_countingsort.js` | `.compat-state-staging/logs/f1232104e4d338c7c76a723ace84621f84895ca1.log` |
| staging/sm | OOM | 1 | `test262/test/staging/sm/regress/regress-567152.js` | `.compat-state-staging/logs/f2fc29f0b2ecc140a7956dac1e2335e2f3b2da5e.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/TypedArray/element-setting-converts-using-ToNumber.js` | `.compat-state-staging/logs/655e0e3f8baf6b2fbdfb85776837eb8131d920b4.log` |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| built-ins/decodeURIComponent | NORMAL | 79.899s | 12 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.10_T1.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.11_T1.js`<br>...<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.14_T3.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.14_T4.js` |
| built-ins/decodeURI | NORMAL | 72.527s | 12 | `test262/test/built-ins/decodeURI/S15.1.3.1_A1.10_T1.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.11_T1.js`<br>...<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.14_T3.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.14_T4.js` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 61.393s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-digit-class-escape-positive-cases.js` |
| staging/sm | CRASH | 61.157s | 1 | `test262/test/staging/sm/regress/regress-1507322-deep-weakmap.js` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 61.021s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-whitespace-class-escape-positive-cases.js` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 60.805s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-whitespace-class-escape-negative-cases.js` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 60.777s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-word-class-escape-negative-cases.js` |
| staging/sm | CRASH | 60.515s | 1 | `test262/test/staging/sm/Array/toSpliced-dense.js` |
| staging/sm | NORMAL | 60.315s | 12 | `test262/test/staging/sm/Symbol/species.js`<br>`test262/test/staging/sm/Symbol/surfaces.js`<br>...<br>`test262/test/staging/sm/Symbol/well-known.js`<br>`test262/test/staging/sm/Temporal/Calendar/compare-to-datetimeformat.js` |
| staging/sm | CRASH | 60.310s | 1 | `test262/test/staging/sm/TypedArray/sort_large_countingsort.js` |
| staging/sm | NORMAL | 60.240s | 2 | `test262/test/staging/sm/TypedArray/sort_modifications.js`<br>`test262/test/staging/sm/TypedArray/sort_small.js` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 59.875s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-digit-class-escape-negative-cases.js` |
| language/literals | NORMAL | 58.088s | 25 | `test262/test/language/literals/regexp/7.8.5-2gs.js`<br>`test262/test/language/literals/regexp/S7.8.5_A1.1_T1.js`<br>...<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T1.js`<br>`test262/test/language/literals/regexp/S7.8.5_A2.2_T2.js` |
| staging/sm | NORMAL | 54.032s | 25 | `test262/test/staging/sm/expressions/destructuring-object-__proto__-2.js`<br>`test262/test/staging/sm/expressions/destructuring-pattern-parenthesized.js`<br>...<br>`test262/test/staging/sm/expressions/string-literal-escape-sequences.js`<br>`test262/test/staging/sm/expressions/tagged-template-constant-folding.js` |
| language/expressions | NORMAL | 53.059s | 25 | `test262/test/language/expressions/call/spread-sngl-iter.js`<br>`test262/test/language/expressions/call/spread-sngl-literal.js`<br>...<br>`test262/test/language/expressions/class/accessor-name-inst/literal-numeric-zero.js`<br>`test262/test/language/expressions/class/accessor-name-inst/literal-string-char-escape.js` |
| staging/sm | NORMAL | 50.643s | 13 | `test262/test/staging/sm/Temporal/PlainDate/from-constrain-hebrew.js`<br>`test262/test/staging/sm/Temporal/PlainDate/from-constrain-japanese.js`<br>...<br>`test262/test/staging/sm/Temporal/PlainMonthDay/from-coptic.js`<br>`test262/test/staging/sm/Temporal/PlainMonthDay/from-gregory.js` |
| staging/sm | OOM | 50.457s | 1 | `test262/test/staging/sm/regress/regress-567152.js` |
| annexB/built-ins/RegExp | NORMAL | 47.224s | 25 | `test262/test/annexB/built-ins/RegExp/RegExp-control-escape-russian-letter.js`<br>`test262/test/annexB/built-ins/RegExp/RegExp-decimal-escape-class-range.js`<br>...<br>`test262/test/annexB/built-ins/RegExp/legacy-accessors/lastParen/this-cross-realm-constructor.js`<br>`test262/test/annexB/built-ins/RegExp/legacy-accessors/lastParen/this-not-regexp-constructor.js` |
| staging/sm | NORMAL | 42.841s | 25 | `test262/test/staging/sm/TypedArray/reduce-and-reduceRight.js`<br>`test262/test/staging/sm/TypedArray/reverse.js`<br>...<br>`test262/test/staging/sm/TypedArray/sort_errors.js`<br>`test262/test/staging/sm/TypedArray/sort_globals.js` |
| staging/sm | CRASH | 42.425s | 1 | `test262/test/staging/sm/TypedArray/element-setting-converts-using-ToNumber.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are executed as ES modules (always strict, import/export processed).
- Tests flagged as async are skipped by the current runner.
- Mixed-mode tests currently run only in sloppy mode unless onlyStrict or noStrict is set.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

