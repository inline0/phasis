# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-05-12T18:29:38+00:00`
- Chunk size: `25`
- Timeout: `300s`
- Jobs: `4`
- Groups: `159`
- Test files: `50506`
- Git: `main` @ `896554a` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 50358 | 9 | 113 | 26 | 602 | 0 | 50367 | 50506 | 51108 | 100.0% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | PASS | 1078 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins | INCOMPLETE | 22627 | 9 | 67 | 26 | 602 | 0 | 100.0% |
| harness | PASS | 116 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402 | PASS | 1564 | 0 | 2 | 0 | 0 | 0 | 100.0% |
| language | PASS | 23383 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| staging | PASS | 1590 | 0 | 42 | 0 | 0 | 0 | 100.0% |

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
| built-ins/Function | PASS | 506 | 0 | 3 | 0 | 0 | 0 | 100.0% |
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
| built-ins/RegExp | PASS | 487 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/CharacterClassEscapes | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/Symbol.species | PASS | 4 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/dotall | PASS | 4 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/escape | PASS | 20 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/lookBehind | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/match-indices | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/named-groups | PASS | 36 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/property-escapes | INCOMPLETE | 143 | 0 | 0 | 0 | 143 | 0 | 100.0% |
| built-ins/RegExp/property-escapes/generated | INCOMPLETE | 418 | 9 | 6 | 26 | 459 | 0 | 97.9% |
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
| intl402/Temporal | PASS | 321 | 0 | 2 | 0 | 0 | 0 | 100.0% |
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
| language/expressions | PASS | 11022 | 0 | 1 | 0 | 0 | 0 | 100.0% |
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
| staging/Intl402 | PASS | 43 | 0 | 6 | 0 | 0 | 0 | 100.0% |
| staging/Temporal | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Uint8Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/built-ins | PASS | 7 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/decorators | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/explicit-resource-management | PASS | 54 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/set-methods | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/sm | PASS | 1392 | 0 | 36 | 0 | 0 | 0 | 100.0% |
| staging/source-phase-imports | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/upsert | PASS | 71 | 0 | 0 | 0 | 0 | 0 | 100.0% |

## Blocked Chunks

| Group | Kind | Files | Sample | Log |
|---|---|---:|---|---|
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Letter_Number.js` | `.compat-state-builtins-RegExp-property-escapes-gc/logs/727065b7c7433624d4212f6ded760f5e747d5cb8.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Spacing_Mark.js` | `.compat-state-builtins-RegExp-property-escapes-gc/logs/aefbc419f12ac4b1987eb70cda72449c4ca42216.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Modifier_Letter.js` | `.compat-state-builtins-RegExp-property-escapes-gc/logs/7a550cac3dd77d1f0e5f196432659f5ff7a7ab97.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Space_Separator.js` | `.compat-state-builtins-RegExp-property-escapes-gc/logs/722856d8a2f769fdf992dd74baffc39ef5b2398d.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Initial_Punctuation.js` | `.compat-state-builtins-RegExp-property-escapes-gc/logs/ffddb7ec4437550c8b88d09a899c5acbc54f73f5.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Letter.js` | `.compat-state-builtins-RegExp-property-escapes-gc/logs/f189bccd8e2494fba1fc20e302c6bb9c6ddc8396.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Number.js` | `.compat-state-builtins-RegExp-property-escapes-gc/logs/ddb9b84844f800b71d967d9cbfa1a8a0ec51775f.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Decimal_Number.js` | `.compat-state-builtins-RegExp-property-escapes-gc/logs/740b3e83e620769e06b6e9adfefe1dc82d041a25.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Other_Punctuation.js` | `.compat-state-builtins-RegExp-property-escapes-gc/logs/2a94d7056c9fb7cf5b43b268af4f0178b13bed95.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Unassigned.js` | `.compat-state-builtins-RegExp-property-escapes-gc/logs/0ac7155c5710773c923a8f4aa88e27655bf778af.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Uppercase_Letter.js` | `.compat-state-builtins-RegExp-property-escapes-gc/logs/a18481051f18c40a58f47ab6b876ef6f7c2b4d9c.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Cased_Letter.js` | `.compat-state-builtins-RegExp-property-escapes-gc/logs/5d38a1e8d95c8b34ab37e39393fcc1c7cdc38ad9.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Close_Punctuation.js` | `.compat-state-builtins-RegExp-property-escapes-gc/logs/8e239b30c417464de7bf971ff0260627422b3f14.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Mark.js` | `.compat-state-builtins-RegExp-property-escapes-gc/logs/3bbc5b4a55e01eb4aeee5d509270817c17d2ca22.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Math_Symbol.js` | `.compat-state-builtins-RegExp-property-escapes-gc/logs/ab24a0f4f1cc7f468ef4fc4c2a345672e90a48e4.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Punctuation.js` | `.compat-state-builtins-RegExp-property-escapes-gc/logs/bd0de27d112e7d31a61cbb558911fdd3df0c1e8b.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Final_Punctuation.js` | `.compat-state-builtins-RegExp-property-escapes-gc/logs/839ca0fbf1057e0c4288b4a5ebad161c9587dc37.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Modifier_Symbol.js` | `.compat-state-builtins-RegExp-property-escapes-gc/logs/fc71bcd9b57881f78bef2b598626dfe9f431d71e.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Inherited.js` | `.compat-state-builtins-RegExp-property-escapes-script-al/logs/9996e44fe6f0389dafeae9df985d344a9ba6ca34.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Sinhala.js` | `.compat-state-builtins-RegExp-property-escapes-script-mz/logs/fb132212d7c2f3e95517e46d31706b5d5022e637.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Lydian.js` | `.compat-state-builtins-RegExp-property-escapes-scriptext-al/logs/6ebe5fc7d892d4da2ddc6082aa4130e3e30a9e09.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Anatolian_Hieroglyphs.js` | `.compat-state-builtins-RegExp-property-escapes-scriptext-al/logs/ffb34a5f70dee70d9cf286e66d4ec85fcb1f339d.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Inherited.js` | `.compat-state-builtins-RegExp-property-escapes-scriptext-al/logs/608b0e9700e60dc84cd8334449f2e77c2ebd0206.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Lao.js` | `.compat-state-builtins-RegExp-property-escapes-scriptext-al/logs/fe8a027189fdca642763d9a9a574472054ab3cf4.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Coptic.js` | `.compat-state-builtins-RegExp-property-escapes-scriptext-al/logs/cb64f85dba9fe63e9cc123fc682725eaf5cd679a.log` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Psalter_Pahlavi.js` | `.compat-state-builtins-RegExp-property-escapes-scriptext-mz/logs/ffd81c8e5c9678f0efc07bab3db9e005698bb451.log` |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 88.308s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Psalter_Pahlavi.js` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 86.674s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Sinhala.js` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 82.628s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Mark.js` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 81.349s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Other_Punctuation.js` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 81.286s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Unassigned.js` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 79.426s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Space_Separator.js` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 79.097s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Letter_Number.js` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 79.004s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Decimal_Number.js` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 76.194s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Lydian.js` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 75.971s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Anatolian_Hieroglyphs.js` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 75.497s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Modifier_Symbol.js` |
| language/expressions | NORMAL | 74.131s | 25 | `test262/test/language/expressions/call/spread-sngl-iter.js`<br>`test262/test/language/expressions/call/spread-sngl-literal.js`<br>...<br>`test262/test/language/expressions/class/accessor-name-inst/literal-numeric-zero.js`<br>`test262/test/language/expressions/class/accessor-name-inst/literal-string-char-escape.js` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 74.128s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Punctuation.js` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 71.553s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Final_Punctuation.js` |
| built-ins/decodeURI | NORMAL | 70.490s | 25 | `test262/test/built-ins/decodeURI/S15.1.3.1_A1.11_T2.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.12_T1.js`<br>...<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.8_T1.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.8_T2.js` |
| built-ins/decodeURIComponent | NORMAL | 69.666s | 25 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.11_T2.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.12_T1.js`<br>...<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.8_T1.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.8_T2.js` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 69.642s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Inherited.js` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 68.286s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Number.js` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 68.093s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Spacing_Mark.js` |
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 67.423s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Uppercase_Letter.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are executed as ES modules (always strict, import/export processed).
- Tests flagged as async are skipped by the current runner.
- Mixed-mode tests currently run only in sloppy mode unless onlyStrict or noStrict is set.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

