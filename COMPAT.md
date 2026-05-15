# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-05-15T20:04:52+00:00`
- Chunk size: `25`
- Timeout: `300s`
- Jobs: `4`
- Groups: `159`
- Test files: `50506`
- Git: `fix/load-member-ic-skip-proxy` @ `92d2687` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 50506 | 0 | 0 | 0 | 0 | 0 | 50506 | 50506 | 50506 | 100.0% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | PASS | 1079 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins | PASS | 22729 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| harness | PASS | 116 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402 | PASS | 1566 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language | PASS | 23384 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging | PASS | 1632 | 0 | 0 | 0 | 0 | 0 | 100.0% |

## Group Coverage

| Group | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB/built-ins/Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Date | PASS | 24 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Function | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Object | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/RegExp | PASS | 62 | 0 | 0 | 0 | 0 | 0 | 100.0% |
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
| built-ins/Atomics | PASS | 376 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/BigInt | PASS | 75 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Boolean | PASS | 51 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/DataView | PASS | 550 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Date | PASS | 594 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/DisposableStack | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Error | PASS | 53 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/FinalizationRegistry | PASS | 47 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Function | PASS | 509 | 0 | 0 | 0 | 0 | 0 | 100.0% |
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
| built-ins/Proxy | PASS | 311 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Reflect | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp | PASS | 488 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/CharacterClassEscapes | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/Symbol.species | PASS | 4 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/dotall | PASS | 4 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/escape | PASS | 20 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/lookBehind | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/match-indices | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/named-groups | PASS | 36 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/property-escapes | PASS | 143 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp/property-escapes/generated | PASS | 459 | 0 | 0 | 0 | 0 | 0 | 100.0% |
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
| built-ins/decodeURI | PASS | 55 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/decodeURIComponent | PASS | 56 | 0 | 0 | 0 | 0 | 0 | 100.0% |
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
| intl402/Temporal | PASS | 323 | 0 | 0 | 0 | 0 | 0 | 100.0% |
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
| language/expressions | PASS | 11023 | 0 | 0 | 0 | 0 | 0 | 100.0% |
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
| staging/Intl402 | PASS | 49 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Temporal | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Uint8Array | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/built-ins | PASS | 7 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/decorators | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/explicit-resource-management | PASS | 54 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/set-methods | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/sm | PASS | 1428 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/source-phase-imports | PASS | 1 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/upsert | PASS | 71 | 0 | 0 | 0 | 0 | 0 | 100.0% |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| staging/sm | NORMAL | 131.125s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-7-of-8.js` |
| staging/sm | NORMAL | 130.345s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-8-of-8.js` |
| staging/sm | NORMAL | 129.837s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-4-of-8.js` |
| staging/sm | NORMAL | 129.707s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-3-of-8.js` |
| staging/sm | NORMAL | 129.602s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-2-of-8.js` |
| staging/sm | NORMAL | 118.176s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-5-of-8.js` |
| staging/sm | NORMAL | 104.700s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-1-of-8.js` |
| staging/sm | NORMAL | 102.009s | 1 | `test262/test/staging/sm/Date/dst-offset-caching-6-of-8.js` |
| staging/sm | NORMAL | 78.724s | 1 | `test262/test/staging/sm/TypedArray/sort_large_countingsort.js` |
| built-ins/decodeURIComponent | NORMAL | 63.703s | 1 | `test262/test/built-ins/decodeURIComponent/S15.1.3.2_A2.5_T1.js` |
| staging/sm | NORMAL | 61.013s | 1 | `test262/test/staging/sm/TypedArray/element-setting-converts-using-ToNumber.js` |
| built-ins/decodeURI | NORMAL | 52.530s | 1 | `test262/test/built-ins/decodeURI/S15.1.3.1_A2.5_T1.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 51.955s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Phoenician.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Psalter_Pahlavi.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Rejang.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Runic.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Samaritan.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 51.753s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Hiragana.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Imperial_Aramaic.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Inherited.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Inscriptional_Pahlavi.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Inscriptional_Parthian.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 51.515s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Tai_Le.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Tai_Tham.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Tai_Viet.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Takri.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Tamil.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 51.354s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Nandinagari.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_New_Tai_Lue.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Newa.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Nko.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Nushu.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 50.915s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Number.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Open_Punctuation.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Other.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Other_Letter.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Other_Number.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 50.769s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Other_Punctuation.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Other_Symbol.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Paragraph_Separator.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Private_Use.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Punctuation.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 50.728s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Mro.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Multani.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Myanmar.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Nabataean.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Nag_Mundari.js` |
| built-ins/RegExp/property-escapes/generated | NORMAL | 50.618s | 5 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Dash_Punctuation.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Decimal_Number.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Enclosing_Mark.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Final_Punctuation.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Format.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are executed as ES modules (always strict, import/export processed).
- Tests flagged as async are skipped by the current runner.
- Mixed-mode tests currently run only in sloppy mode unless onlyStrict or noStrict is set.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

