# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-05-12T23:09:53+00:00`
- Chunk size: `25`
- Timeout: `300s`
- Jobs: `4`
- Groups: `159`
- Test files: `50506`
- Git: `main` @ `9e7008a` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 50304 | 0 | 167 | 35 | 636 | 0 | 50304 | 50506 | 51142 | 100.0% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | PASS | 1078 | 0 | 1 | 0 | 0 | 0 | 100.0% |
| built-ins | INCOMPLETE | 22573 | 0 | 121 | 35 | 636 | 0 | 100.0% |
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
| built-ins/RegExp/property-escapes/generated | INCOMPLETE | 398 | 0 | 60 | 1 | 459 | 0 | 100.0% |
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
| built-ins/isFinite | INCOMPLETE | 0 | 0 | 0 | 17 | 17 | 0 | n/a |
| built-ins/isNaN | INCOMPLETE | 0 | 0 | 0 | 17 | 17 | 0 | n/a |
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
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Tibetan.js` | `.compat-state-builtins-RegExp-property-escapes-script-mz/logs/e27388077f3bed3484251a2411b41916d3546c79.log` |
| built-ins/isFinite | CRASH | 1 | `test262/test/built-ins/isFinite/toprimitive-result-is-object-throws.js` | `.compat-state-builtins-lower-is/logs/245888a628b99bac3e60ef946e359270c82ae62f.log` |
| built-ins/isNaN | CRASH | 1 | `test262/test/built-ins/isNaN/toprimitive-result-is-object-throws.js` | `.compat-state-builtins-lower-is/logs/5f787bc5234ff1f7f554210ca83b5f02c1d2cfbc.log` |
| built-ins/isFinite | CRASH | 1 | `test262/test/built-ins/isFinite/S15.1.2.5_A2.6.js` | `.compat-state-builtins-lower-is/logs/44003e32c0e7a8d3cd138456d9783a242af6d281.log` |
| built-ins/isFinite | CRASH | 1 | `test262/test/built-ins/isFinite/S15.1.2.5_A2.7.js` | `.compat-state-builtins-lower-is/logs/81b2bfea7937a10d44240e3134bcf6198be91755.log` |
| built-ins/isFinite | CRASH | 1 | `test262/test/built-ins/isFinite/tonumber-operations.js` | `.compat-state-builtins-lower-is/logs/f12e1790bc1e6ef5f185b5d1c55704933c0f464c.log` |
| built-ins/isFinite | CRASH | 1 | `test262/test/built-ins/isFinite/toprimitive-call-abrupt.js` | `.compat-state-builtins-lower-is/logs/53c47bd356ab17f74b42d1c08c54cd8837587d9e.log` |
| built-ins/isFinite | CRASH | 1 | `test262/test/built-ins/isFinite/length.js` | `.compat-state-builtins-lower-is/logs/464fdf14a565a3eafdb6594a1d4cedca82843546.log` |
| built-ins/isFinite | CRASH | 1 | `test262/test/built-ins/isFinite/name.js` | `.compat-state-builtins-lower-is/logs/97b6e8c891dfa31075c8f0519601a821db4d08eb.log` |
| built-ins/isNaN | CRASH | 1 | `test262/test/built-ins/isNaN/return-false-not-nan-numbers.js` | `.compat-state-builtins-lower-is/logs/d0b1fc99f153c200a207294cd3bf6252948b8fa0.log` |
| built-ins/isNaN | CRASH | 1 | `test262/test/built-ins/isNaN/return-true-nan.js` | `.compat-state-builtins-lower-is/logs/697fb6b4ac57b9c1f4894189e8bc4f04de6f6e33.log` |
| built-ins/isNaN | CRASH | 1 | `test262/test/built-ins/isNaN/S15.1.2.4_A2.6.js` | `.compat-state-builtins-lower-is/logs/9c51de21e771220ac7aff5fcf92a5ae97f796566.log` |
| built-ins/isNaN | CRASH | 1 | `test262/test/built-ins/isNaN/S15.1.2.4_A2.7.js` | `.compat-state-builtins-lower-is/logs/137cd8a9de2fd4c22a7354c11a7facedf6dc5904.log` |
| built-ins/isNaN | CRASH | 1 | `test262/test/built-ins/isNaN/not-a-constructor.js` | `.compat-state-builtins-lower-is/logs/9660dcace1045c8653599ff98800106f7da3b01f.log` |
| built-ins/isNaN | CRASH | 1 | `test262/test/built-ins/isNaN/prop-desc.js` | `.compat-state-builtins-lower-is/logs/82d15123f86f19cfb5cac64b785569e0c0d662a0.log` |
| built-ins/isNaN | CRASH | 1 | `test262/test/built-ins/isNaN/return-abrupt-from-tonumber-number-symbol.js` | `.compat-state-builtins-lower-is/logs/b810e65d12fbf7d2f69494433ddcfa6db07c99fa.log` |
| built-ins/isNaN | CRASH | 1 | `test262/test/built-ins/isNaN/return-abrupt-from-tonumber-number.js` | `.compat-state-builtins-lower-is/logs/d4fb120bb10501fe860b2c91cbdd06d98e0dcaaf.log` |
| built-ins/isNaN | CRASH | 1 | `test262/test/built-ins/isNaN/length.js` | `.compat-state-builtins-lower-is/logs/5ef68ae477f064662a371c97164ba8b186ebfc2d.log` |
| built-ins/isNaN | CRASH | 1 | `test262/test/built-ins/isNaN/name.js` | `.compat-state-builtins-lower-is/logs/69a1e5f1a6347c0fa685a7a47eaa4784bb0dfccd.log` |
| built-ins/isFinite | CRASH | 1 | `test262/test/built-ins/isFinite/return-abrupt-from-tonumber-number-symbol.js` | `.compat-state-builtins-lower-is/logs/fe078cc291ebd76fd43d7464b5121575eff8c25e.log` |
| built-ins/isFinite | CRASH | 1 | `test262/test/built-ins/isFinite/return-abrupt-from-tonumber-number.js` | `.compat-state-builtins-lower-is/logs/a374b4532cb380acd09d7147629bc5470a4aaf00.log` |
| built-ins/isNaN | CRASH | 1 | `test262/test/built-ins/isNaN/toprimitive-get-abrupt.js` | `.compat-state-builtins-lower-is/logs/d034968d15b519308a4fc084f3f82b173c1277a1.log` |
| built-ins/isNaN | CRASH | 1 | `test262/test/built-ins/isNaN/toprimitive-not-callable-throws.js` | `.compat-state-builtins-lower-is/logs/e9ea5a478fde05edc2243c8084e79d2b695d3117.log` |
| built-ins/isFinite | CRASH | 1 | `test262/test/built-ins/isFinite/return-false-on-nan-or-infinities.js` | `.compat-state-builtins-lower-is/logs/fee30cc025fa11744963e61d1a14d3f67cf300ca.log` |
| built-ins/isFinite | CRASH | 1 | `test262/test/built-ins/isFinite/return-true-for-valid-finite-numbers.js` | `.compat-state-builtins-lower-is/logs/4582ab9a7178f1ad024bdbdf5fd5cbaebfa62a81.log` |
| built-ins/isNaN | CRASH | 1 | `test262/test/built-ins/isNaN/tonumber-operations.js` | `.compat-state-builtins-lower-is/logs/fcfc75ceb832faeb35d96a166f005a7119cc42c9.log` |
| built-ins/isNaN | CRASH | 1 | `test262/test/built-ins/isNaN/toprimitive-call-abrupt.js` | `.compat-state-builtins-lower-is/logs/421bbddc207b339e02cf104920cf712732258874.log` |
| built-ins/isFinite | CRASH | 1 | `test262/test/built-ins/isFinite/toprimitive-get-abrupt.js` | `.compat-state-builtins-lower-is/logs/ad326a49cd56f007d1b2e654093920b65f3c875c.log` |
| built-ins/isFinite | CRASH | 1 | `test262/test/built-ins/isFinite/toprimitive-not-callable-throws.js` | `.compat-state-builtins-lower-is/logs/d05a6a4aeed9474c85c0f3029fd4eb56af15297c.log` |
| built-ins/isFinite | CRASH | 1 | `test262/test/built-ins/isFinite/not-a-constructor.js` | `.compat-state-builtins-lower-is/logs/c32ca687198d5baf762429c86fa9efd09ffa95ec.log` |
| built-ins/isFinite | CRASH | 1 | `test262/test/built-ins/isFinite/prop-desc.js` | `.compat-state-builtins-lower-is/logs/0a8e4bf86710693971e2bce3d84931518bbf3615.log` |
| built-ins/isFinite | CRASH | 1 | `test262/test/built-ins/isFinite/toprimitive-result-is-symbol-throws.js` | `.compat-state-builtins-lower-is/logs/1049e4d25b7a314d30cdbb0e21d659ec9bba869e.log` |
| built-ins/isFinite | CRASH | 1 | `test262/test/built-ins/isFinite/toprimitive-valid-result.js` | `.compat-state-builtins-lower-is/logs/b3c59fa17a3bebf54eef509779d2d149d16a8e83.log` |
| built-ins/isNaN | CRASH | 1 | `test262/test/built-ins/isNaN/toprimitive-result-is-symbol-throws.js` | `.compat-state-builtins-lower-is/logs/3d229eefbeef057eb03db284be1e2da9fd6417c3.log` |
| built-ins/isNaN | CRASH | 1 | `test262/test/built-ins/isNaN/toprimitive-valid-result.js` | `.compat-state-builtins-lower-is/logs/0460f303b800b64fde8b8bf8121e59bc7d1b1b25.log` |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| built-ins/RegExp/property-escapes/generated | TIMEOUT | 82.751s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Tibetan.js` |
| built-ins/decodeURI | NORMAL | 71.795s | 25 | `test262/test/built-ins/decodeURI/S15.1.3.1_A1.11_T2.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.12_T1.js`<br>...<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.8_T1.js`<br>`test262/test/built-ins/decodeURI/S15.1.3.1_A1.8_T2.js` |
| language/expressions | NORMAL | 71.387s | 25 | `test262/test/language/expressions/call/spread-sngl-iter.js`<br>`test262/test/language/expressions/call/spread-sngl-literal.js`<br>...<br>`test262/test/language/expressions/class/accessor-name-inst/literal-numeric-zero.js`<br>`test262/test/language/expressions/class/accessor-name-inst/literal-string-char-escape.js` |
| built-ins/decodeURIComponent | NORMAL | 71.179s | 25 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.11_T2.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.12_T1.js`<br>...<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.8_T1.js`<br>`test262/test/built-ins/decodeURIComponent/S15.1.3.2_A1.8_T2.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 59.302s | 2 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Soyombo.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Sundanese.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 58.689s | 2 | `test262/test/built-ins/RegExp/property-escapes/generated/Hex_Digit.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/IDS_Binary_Operator.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 58.373s | 2 | `test262/test/built-ins/RegExp/property-escapes/generated/Diacritic.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Emoji.js` |
| staging/sm | NORMAL | 58.311s | 25 | `test262/test/staging/sm/TypedArray/iterator-next-with-detached.js`<br>`test262/test/staging/sm/TypedArray/iterator.js`<br>...<br>`test262/test/staging/sm/TypedArray/slice-memcpy.js`<br>`test262/test/staging/sm/TypedArray/slice-species.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 57.654s | 3 | `test262/test/built-ins/RegExp/property-escapes/generated/Radical.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Regional_Indicator.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Sentence_Terminal.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 57.356s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Latin.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 56.415s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Greek.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 56.291s | 2 | `test262/test/built-ins/RegExp/property-escapes/generated/Emoji_Component.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Emoji_Modifier.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 56.038s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Arabic.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 55.661s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Garay.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 55.026s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Glagolitic.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 55.018s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Gurmukhi.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 54.797s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Bengali.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 53.787s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Dives_Akuru.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 53.487s | 2 | `test262/test/built-ins/RegExp/property-escapes/generated/Bidi_Control.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Bidi_Mirrored.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 53.410s | 1 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Lao.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are executed as ES modules (always strict, import/export processed).
- Tests flagged as async are skipped by the current runner.
- Mixed-mode tests currently run only in sloppy mode unless onlyStrict or noStrict is set.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

