# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-04-27T00:43:15+00:00`
- Chunk size: `25`
- Timeout: `300s`
- Jobs: `4`
- Groups: `159`
- Test files: `50506`
- Git: `main` @ `b86a65f` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 47744 | 185 | 2075 | 32 | 907 | 0 | 47929 | 50036 | 50943 | 99.6% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | PARTIAL | 1069 | 1 | 9 | 0 | 0 | 0 | 99.9% |
| built-ins | INCOMPLETE | 21155 | 40 | 1067 | 8 | 571 | 0 | 99.8% |
| harness | PASS | 114 | 0 | 2 | 0 | 0 | 0 | 100.0% |
| intl402 | PARTIAL | 1554 | 2 | 10 | 0 | 0 | 0 | 99.9% |
| language | INCOMPLETE | 22450 | 17 | 902 | 4 | 61 | 0 | 99.9% |
| staging | INCOMPLETE | 1402 | 125 | 85 | 20 | 275 | 0 | 91.8% |

## Group Coverage

| Group | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB/built-ins/Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Date | PASS | 24 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Function | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Object | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/RegExp | PASS | 53 | 0 | 9 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/String | PASS | 111 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/TypedArrayConstructors | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/escape | PASS | 16 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/unescape | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/comments | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/eval-code | PASS | 469 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/expressions | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/function-code | PARTIAL | 158 | 1 | 0 | 0 | 0 | 0 | 99.4% |
| annexB/language/global-code | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/literals | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/statements | PASS | 22 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AbstractModuleSource | SKIPPED | 0 | 0 | 8 | 0 | 0 | 0 | n/a |
| built-ins/Array | PARTIAL | 2969 | 8 | 98 | 0 | 0 | 0 | 99.7% |
| built-ins/ArrayBuffer | PASS | 122 | 0 | 70 | 0 | 0 | 0 | 100.0% |
| built-ins/ArrayIteratorPrototype | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncDisposableStack | PASS | 51 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncFromSyncIteratorPrototype | PASS | 38 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncFunction | PASS | 17 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorFunction | PASS | 21 | 0 | 2 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorPrototype | PARTIAL | 45 | 3 | 0 | 0 | 0 | 0 | 93.8% |
| built-ins/AsyncIteratorPrototype | PASS | 10 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Atomics | PARTIAL | 258 | 2 | 116 | 0 | 0 | 0 | 99.2% |
| built-ins/BigInt | PASS | 74 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/Boolean | PASS | 50 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/DataView | PARTIAL | 517 | 1 | 32 | 0 | 0 | 0 | 99.8% |
| built-ins/Date | PASS | 591 | 0 | 3 | 0 | 0 | 0 | 100.0% |
| built-ins/DisposableStack | PASS | 51 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/Error | PASS | 50 | 0 | 3 | 0 | 0 | 0 | 100.0% |
| built-ins/FinalizationRegistry | PASS | 46 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/Function | PASS | 495 | 0 | 14 | 0 | 0 | 0 | 100.0% |
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
| built-ins/Object | PASS | 3404 | 0 | 6 | 0 | 0 | 0 | 100.0% |
| built-ins/Promise | PASS | 630 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/Proxy | PASS | 274 | 0 | 37 | 0 | 0 | 0 | 100.0% |
| built-ins/Reflect | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp | PARTIAL | 401 | 8 | 79 | 0 | 0 | 0 | 98.0% |
| built-ins/RegExp/CharacterClassEscapes | INCOMPLETE | 6 | 0 | 0 | 6 | 12 | 0 | 100.0% |
| built-ins/RegExp/Symbol.species | PASS | 4 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/dotall | FAIL | 0 | 4 | 0 | 0 | 0 | 0 | 0.0% |
| built-ins/RegExp/escape | PASS | 19 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/lookBehind | SKIPPED | 0 | 0 | 17 | 0 | 0 | 0 | n/a |
| built-ins/RegExp/match-indices | PARTIAL | 13 | 1 | 0 | 0 | 0 | 0 | 92.9% |
| built-ins/RegExp/named-groups | PASS | 25 | 0 | 11 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/property-escapes | PASS | 143 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/property-escapes/generated | PENDING | 0 | 0 | 0 | 0 | 459 | 0 | n/a |
| built-ins/RegExp/prototype | PARTIAL | 434 | 2 | 51 | 0 | 0 | 0 | 99.5% |
| built-ins/RegExp/regexp-modifiers | SKIPPED | 0 | 0 | 70 | 0 | 0 | 0 | n/a |
| built-ins/RegExp/unicodeSets | SKIPPED | 0 | 0 | 113 | 0 | 0 | 0 | n/a |
| built-ins/RegExpStringIteratorPrototype | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Set | PASS | 380 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/SetIteratorPrototype | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ShadowRealm | PARTIAL | 53 | 8 | 3 | 0 | 0 | 0 | 86.9% |
| built-ins/SharedArrayBuffer | PASS | 59 | 0 | 45 | 0 | 0 | 0 | 100.0% |
| built-ins/String | PASS | 1202 | 0 | 10 | 0 | 0 | 0 | 100.0% |
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
| built-ins/TypedArray | PASS | 1222 | 0 | 204 | 0 | 0 | 0 | 100.0% |
| built-ins/TypedArrayConstructors | PARTIAL | 702 | 2 | 32 | 0 | 0 | 0 | 99.7% |
| built-ins/Uint8Array | PARTIAL | 63 | 1 | 0 | 0 | 0 | 0 | 98.4% |
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
| harness | PASS | 114 | 0 | 2 | 0 | 0 | 0 | 100.0% |
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
| intl402/Temporal | PARTIAL | 322 | 1 | 0 | 0 | 0 | 0 | 99.7% |
| intl402/TypedArray | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/arguments-object | PASS | 263 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/asi | PASS | 102 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/block-scope | PASS | 145 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/comments | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/computed-property-names | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/destructuring | PASS | 18 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| language/directive-prologue | PASS | 62 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/eval-code | PASS | 346 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| language/export | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/expressions | PARTIAL | 10569 | 8 | 446 | 0 | 0 | 0 | 99.9% |
| language/function-code | PASS | 217 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/future-reserved-words | PASS | 55 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/global-code | PASS | 42 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifier-resolution | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifiers | PASS | 260 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/import | PASS | 4 | 0 | 81 | 0 | 0 | 0 | 100.0% |
| language/keywords | PASS | 25 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/line-terminators | PASS | 41 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/literals | INCOMPLETE | 445 | 2 | 83 | 4 | 50 | 0 | 99.6% |
| language/module-code | PASS | 323 | 0 | 260 | 0 | 0 | 0 | 100.0% |
| language/punctuators | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/reserved-words | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/rest-parameters | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/source-text | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/statementList | PASS | 80 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/statements | PARTIAL | 9119 | 7 | 28 | 0 | 0 | 0 | 99.9% |
| language/types | PASS | 111 | 0 | 2 | 0 | 0 | 0 | 100.0% |
| language/white-space | PASS | 67 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Intl402 | PARTIAL | 11 | 38 | 0 | 0 | 0 | 0 | 22.4% |
| staging/Temporal | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Uint8Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/built-ins | PASS | 1 | 0 | 6 | 0 | 0 | 0 | 100.0% |
| staging/decorators | PARTIAL | 2 | 1 | 0 | 0 | 0 | 0 | 66.7% |
| staging/explicit-resource-management | PARTIAL | 52 | 1 | 1 | 0 | 0 | 0 | 98.1% |
| staging/set-methods | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/sm | INCOMPLETE | 1246 | 85 | 77 | 20 | 275 | 0 | 93.6% |
| staging/source-phase-imports | SKIPPED | 0 | 0 | 1 | 0 | 0 | 0 | n/a |
| staging/upsert | PASS | 71 | 0 | 0 | 0 | 0 | 0 | 100.0% |

## Blocked Chunks

| Group | Kind | Files | Sample | Log |
|---|---|---:|---|---|
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-digit-class-escape-negative-cases.js` | `.compat-state-builtins-RegExp-rest/logs/04ddd46937901b97333e1bad5a7183629e181178.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-digit-class-escape-positive-cases.js` | `.compat-state-builtins-RegExp-rest/logs/722abcc8bbca8f22a6f4e5029b251630a2d88ff7.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-whitespace-class-escape-positive-cases.js` | `.compat-state-builtins-RegExp-rest/logs/9022b8c5942fd25645927bb1658a3645cd98d06e.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-word-class-escape-positive-cases.js` | `.compat-state-builtins-RegExp-rest/logs/5af0de9dd6d6784c7156b505489408bfaf27e665.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-whitespace-class-escape-negative-cases.js` | `.compat-state-builtins-RegExp-rest/logs/f0ebbf279665e1ee7193d8369fb48c919e0422c2.log` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-word-class-escape-negative-cases.js` | `.compat-state-builtins-RegExp-rest/logs/f81d5d8efb2c4dd1df1c2faa058ff85302c5855b.log` |
| built-ins/decodeURIComponent | CRASH | 1 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A2.5_T1.js` | `.compat-state-builtins-lower-de/logs/5a3e033d7be8e84f9d36d0440da488ff5b100e26.log` |
| built-ins/decodeURI | CRASH | 1 | `test262/test/built-ins/decodeURI/S15.1.3.1_A2.5_T1.js` | `.compat-state-builtins-lower-de/logs/6687624bd5fb87a6a4ff167cd35af4b69d14f50d.log` |
| language/literals | CRASH | 1 | `test262/test/language/literals/regexp/S7.8.5_A2.4_T2.js` | `.compat-state-language-literals/logs/2d9ce6285a09a880c93e91b65081fd963e13707a.log` |
| language/literals | CRASH | 1 | `test262/test/language/literals/regexp/S7.8.5_A2.1_T2.js` | `.compat-state-language-literals/logs/d9e1bf9de472dab2910e641eec8172033ad31a6c.log` |
| language/literals | CRASH | 1 | `test262/test/language/literals/regexp/S7.8.5_A1.4_T2.js` | `.compat-state-language-literals/logs/a53fea2e0fecfcecd6532ebd795cb1dae3744df8.log` |
| language/literals | CRASH | 1 | `test262/test/language/literals/regexp/S7.8.5_A1.1_T2.js` | `.compat-state-language-literals/logs/d05363a5be24ca4fcae715d01678920a9b9dd8f2.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/BigInt/large-bit-length.js` | `.compat-state-staging/logs/384b2e0bfc2b466c4c57a4a23d7c3f82c656ddac.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Date/dst-offset-caching-3-of-8.js` | `.compat-state-staging/logs/f77d96875630465a6823161d465877fc4c60f9ae.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/expressions/nullish-coalescing.js` | `.compat-state-staging/logs/fad83b5e2409a84effda05416199c0acf9fa5248.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/regress/regress-1507322-deep-weakmap.js` | `.compat-state-staging/logs/59f611b1d8f6087de2cf8dd8fc9a3ce8ba03416e.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Array/toSpliced-dense.js` | `.compat-state-staging/logs/1d8bc8ec59afa4977eac427273dcca4bb1c34b5e.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/TypedArray/set-same-buffer-different-source-target-types.js` | `.compat-state-staging/logs/f31cff08aae8050007f42d47f3ce1d7c29321ebb.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/TypedArray/sort_large_countingsort.js` | `.compat-state-staging/logs/f1232104e4d338c7c76a723ace84621f84895ca1.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Date/dst-offset-caching-4-of-8.js` | `.compat-state-staging/logs/dd2d25d5cc94da8b81ed7a27013584a93402ab8e.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Date/dst-offset-caching-5-of-8.js` | `.compat-state-staging/logs/c58e8146c6f3a001cf0781adbce015d2d9682c2d.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Date/dst-offset-caching-1-of-8.js` | `.compat-state-staging/logs/f72507a466dd0056e8a719c4d2fad7b060dc5a34.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Date/dst-offset-caching-2-of-8.js` | `.compat-state-staging/logs/42df9b8c4fc0d995dcf4cb51158e13f63ab55e1e.log` |
| staging/sm | OOM | 1 | `test262/test/staging/sm/String/replace-math.js` | `.compat-state-staging/logs/05d8c12e9055c0e405b4fc8ebd3aee35b95f8c6f.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/TypedArray/sort_modifications.js` | `.compat-state-staging/logs/ea3a9224766eb7444ed6f90eb1c8f521ae9dc56c.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/String/fromCodePoint.js` | `.compat-state-staging/logs/b7a6467e0fd7aca48f43b52e9e2e76feff030b05.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Date/dst-offset-caching-6-of-8.js` | `.compat-state-staging/logs/fc434724163390adbcf5051f147ab7c4fd54a93b.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Date/dst-offset-caching-7-of-8.js` | `.compat-state-staging/logs/931d7d3921a965471071a964166aad45b1d595f4.log` |
| staging/sm | OOM | 1 | `test262/test/staging/sm/regress/regress-567152.js` | `.compat-state-staging/logs/f2fc29f0b2ecc140a7956dac1e2335e2f3b2da5e.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Date/dst-offset-caching-8-of-8.js` | `.compat-state-staging/logs/af18b1bdadaf3999fb864b5776bc2b53ed5c7dcb.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/Proxy/ownkeys-linear.js` | `.compat-state-staging/logs/a263da00063883c2fe17b886920ad7c7186a6463.log` |
| staging/sm | CRASH | 1 | `test262/test/staging/sm/TypedArray/element-setting-converts-using-ToNumber.js` | `.compat-state-staging/logs/655e0e3f8baf6b2fbdfb85776837eb8131d920b4.log` |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| built-ins/decodeURIComponent | NORMAL | 80.060s | 12 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.10_T1.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.11_T1.js`<br>...<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.14_T3.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.14_T4.js` |
| built-ins/decodeURI | NORMAL | 75.095s | 12 | `test262/test/built-ins/decodeURI/S15.1.3.1_A1.10_T1.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.11_T1.js`<br>...<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.14_T3.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.14_T4.js` |
| staging/sm | NORMAL | 71.926s | 25 | `test262/test/staging/sm/String/string-pad-start-end.js`<br>`test262/test/staging/sm/String/string-space-trim.js`<br>...<br>`test262/test/staging/sm/Symbol/property-reflection.js`<br>`test262/test/staging/sm/Symbol/realms.js` |
| intl402 | NORMAL | 64.030s | 22 | `test262/test/intl402/constructors-string-and-single-element-array.js`<br>`test262/test/intl402/constructors-taint-Object-prototype-2.js`<br>...<br>`test262/test/intl402/supportedLocalesOf-throws-if-element-not-string-or-object.js`<br>`test262/test/intl402/supportedLocalesOf-unicode-extensions-ignored.js` |
| staging/sm | CRASH | 61.965s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-6-of-8.js` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 61.572s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-whitespace-class-escape-positive-cases.js` |
| staging/sm | CRASH | 61.473s | 1 | `test262/test/staging/sm/expressions/nullish-coalescing.js` |
| staging/sm | CRASH | 61.430s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-1-of-8.js` |
| staging/sm | CRASH | 61.370s | 1 | `test262/test/staging/sm/TypedArray/set-same-buffer-different-source-target-types.js` |
| language/literals | CRASH | 61.197s | 1 | `test262/test/language/literals/regexp/S7.8.5_A2.1_T2.js` |
| staging/sm | CRASH | 61.193s | 1 | `test262/test/staging/sm/TypedArray/sort_modifications.js` |
| staging/sm | CRASH | 61.097s | 1 | `test262/test/staging/sm/TypedArray/sort_large_countingsort.js` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 61.087s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-non-digit-class-escape-positive-cases.js` |
| staging/sm | CRASH | 61.079s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-7-of-8.js` |
| staging/sm | CRASH | 61.078s | 1 | `test262/test/staging/sm/BigInt/large-bit-length.js` |
| staging/sm | CRASH | 61.025s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-4-of-8.js` |
| language/literals | CRASH | 60.816s | 1 | `test262/test/language/literals/regexp/S7.8.5_A1.4_T2.js` |
| built-ins/RegExp/CharacterClassEscapes | CRASH | 60.813s | 1 | `test262/test/built-ins/RegExp/CharacterClassEscapes/character-class-whitespace-class-escape-negative-cases.js` |
| staging/sm | CRASH | 60.807s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-8-of-8.js` |
| staging/sm | CRASH | 60.755s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-5-of-8.js` |

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
- `cross-realm`
- `top-level-await`
- `resizable-arraybuffer`
- `regexp-duplicate-named-groups`
- `tail-call-optimization`
- `regexp-v-flag`
- `regexp-modifiers`
- `regexp-lookbehind`
