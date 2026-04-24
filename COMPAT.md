# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-04-24T20:13:26+00:00`
- Chunk size: `250`
- Timeout: `300s`
- Jobs: `4`
- Groups: `137`
- Test files: `50506`
- Git: `main` @ `c7142b9` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 48081 | 2079 | 6 | 0 | 334 | 6 | 50160 | 50166 | 50506 | 95.9% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | INCOMPLETE | 1064 | 12 | 0 | 0 | 3 | 0 | 98.9% |
| built-ins | RUNNING | 21718 | 705 | 6 | 0 | 294 | 6 | 96.9% |
| harness | PARTIAL | 112 | 4 | 0 | 0 | 0 | 0 | 96.6% |
| intl402 | INCOMPLETE | 683 | 882 | 0 | 0 | 1 | 0 | 43.6% |
| language | INCOMPLETE | 23239 | 144 | 0 | 0 | 1 | 0 | 99.4% |
| staging | INCOMPLETE | 1265 | 332 | 0 | 0 | 35 | 0 | 79.2% |

## Group Coverage

| Group | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB/built-ins/Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/Date | PASS | 24 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Function | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/Object | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/RegExp | PARTIAL | 54 | 8 | 0 | 0 | 0 | 0 | 87.1% |
| annexB/built-ins/String | PASS | 111 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/TypedArrayConstructors | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/escape | PASS | 16 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/built-ins/unescape | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/comments | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/eval-code | PASS | 469 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/expressions | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/function-code | PASS | 159 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/global-code | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| annexB/language/literals | PARTIAL | 4 | 4 | 0 | 0 | 0 | 0 | 50.0% |
| annexB/language/statements | PASS | 22 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AbstractModuleSource | FAIL | 0 | 8 | 0 | 0 | 0 | 0 | 0.0% |
| built-ins/Array | INCOMPLETE | 3014 | 59 | 0 | 0 | 2 | 0 | 98.1% |
| built-ins/ArrayBuffer | PARTIAL | 191 | 1 | 0 | 0 | 0 | 0 | 99.5% |
| built-ins/ArrayIteratorPrototype | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncDisposableStack | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncFromSyncIteratorPrototype | PARTIAL | 35 | 3 | 0 | 0 | 0 | 0 | 92.1% |
| built-ins/AsyncFunction | PASS | 18 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/AsyncGeneratorFunction | PARTIAL | 22 | 1 | 0 | 0 | 0 | 0 | 95.7% |
| built-ins/AsyncGeneratorPrototype | PARTIAL | 45 | 3 | 0 | 0 | 0 | 0 | 93.8% |
| built-ins/AsyncIteratorPrototype | PASS | 10 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Atomics | INCOMPLETE | 279 | 67 | 6 | 0 | 24 | 0 | 80.6% |
| built-ins/BigInt | PASS | 75 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Boolean | PASS | 51 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/DataView | PARTIAL | 547 | 3 | 0 | 0 | 0 | 0 | 99.5% |
| built-ins/Date | PASS | 594 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/DisposableStack | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Error | PASS | 53 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/FinalizationRegistry | PASS | 47 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Function | PARTIAL | 500 | 9 | 0 | 0 | 0 | 0 | 98.2% |
| built-ins/GeneratorFunction | PARTIAL | 22 | 1 | 0 | 0 | 0 | 0 | 95.7% |
| built-ins/GeneratorPrototype | PASS | 61 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Infinity | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Iterator | PASS | 431 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/JSON | PARTIAL | 164 | 1 | 0 | 0 | 0 | 0 | 99.4% |
| built-ins/Map | PASS | 171 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/MapIteratorPrototype | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Math | PASS | 327 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/NaN | PASS | 6 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/NativeErrors | PASS | 139 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Number | PASS | 335 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Object | PASS | 3410 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Promise | INCOMPLETE | 503 | 108 | 0 | 0 | 20 | 0 | 82.3% |
| built-ins/Proxy | PARTIAL | 304 | 7 | 0 | 0 | 0 | 0 | 97.7% |
| built-ins/Reflect | PASS | 153 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/RegExp | RUNNING | 1237 | 382 | 0 | 0 | 242 | 6 | 76.4% |
| built-ins/RegExpStringIteratorPrototype | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Set | PASS | 381 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/SetIteratorPrototype | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ShadowRealm | PARTIAL | 52 | 12 | 0 | 0 | 0 | 0 | 81.3% |
| built-ins/SharedArrayBuffer | PASS | 104 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/String | PARTIAL | 1205 | 7 | 0 | 0 | 0 | 0 | 99.4% |
| built-ins/StringIteratorPrototype | PASS | 7 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/Symbol | PARTIAL | 92 | 2 | 0 | 0 | 0 | 0 | 97.9% |
| built-ins/Temporal | PASS | 4165 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/ThrowTypeError | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/TypedArray | PARTIAL | 1399 | 27 | 0 | 0 | 0 | 0 | 98.1% |
| built-ins/TypedArrayConstructors | PARTIAL | 733 | 3 | 0 | 0 | 0 | 0 | 99.6% |
| built-ins/Uint8Array | PARTIAL | 63 | 1 | 0 | 0 | 0 | 0 | 98.4% |
| built-ins/WeakMap | PASS | 102 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakRef | PASS | 29 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/WeakSet | PASS | 85 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/decodeURI | INCOMPLETE | 52 | 0 | 0 | 0 | 3 | 0 | 100.0% |
| built-ins/decodeURIComponent | INCOMPLETE | 53 | 0 | 0 | 0 | 3 | 0 | 100.0% |
| built-ins/encodeURI | PASS | 31 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/encodeURIComponent | PASS | 31 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/eval | PASS | 10 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/global | PASS | 29 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isFinite | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/isNaN | PASS | 17 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseFloat | PASS | 59 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/parseInt | PASS | 60 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| built-ins/undefined | PASS | 8 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| harness | PARTIAL | 112 | 4 | 0 | 0 | 0 | 0 | 96.6% |
| intl402 | PARTIAL | 8 | 14 | 0 | 0 | 0 | 0 | 36.4% |
| intl402/Array | PASS | 2 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| intl402/BigInt | PARTIAL | 6 | 5 | 0 | 0 | 0 | 0 | 54.5% |
| intl402/Collator | PARTIAL | 44 | 18 | 0 | 0 | 0 | 0 | 71.0% |
| intl402/Date | PARTIAL | 10 | 2 | 0 | 0 | 0 | 0 | 83.3% |
| intl402/DateTimeFormat | PARTIAL | 73 | 115 | 0 | 0 | 0 | 0 | 38.8% |
| intl402/DisplayNames | PARTIAL | 41 | 16 | 0 | 0 | 0 | 0 | 71.9% |
| intl402/DurationFormat | FAIL | 0 | 110 | 0 | 0 | 0 | 0 | 0.0% |
| intl402/Intl | PARTIAL | 33 | 34 | 0 | 0 | 0 | 0 | 49.3% |
| intl402/ListFormat | PARTIAL | 37 | 44 | 0 | 0 | 0 | 0 | 45.7% |
| intl402/Locale | PARTIAL | 81 | 66 | 0 | 0 | 0 | 0 | 55.1% |
| intl402/Number | PARTIAL | 5 | 2 | 0 | 0 | 0 | 0 | 71.4% |
| intl402/NumberFormat | PARTIAL | 103 | 149 | 0 | 0 | 0 | 0 | 40.9% |
| intl402/PluralRules | PARTIAL | 39 | 11 | 0 | 0 | 0 | 0 | 78.0% |
| intl402/RelativeTimeFormat | PARTIAL | 41 | 38 | 0 | 0 | 0 | 0 | 51.9% |
| intl402/Segmenter | PARTIAL | 50 | 28 | 0 | 0 | 0 | 0 | 64.1% |
| intl402/String | PARTIAL | 8 | 9 | 0 | 0 | 0 | 0 | 47.1% |
| intl402/Temporal | PARTIAL | 102 | 221 | 0 | 0 | 0 | 0 | 31.6% |
| intl402/TypedArray | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/arguments-object | PASS | 263 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/asi | PASS | 102 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/block-scope | PASS | 145 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/comments | PASS | 52 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/computed-property-names | PASS | 48 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/destructuring | PASS | 19 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/directive-prologue | PASS | 62 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/eval-code | PARTIAL | 346 | 1 | 0 | 0 | 0 | 0 | 99.7% |
| language/export | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/expressions | PARTIAL | 10969 | 54 | 0 | 0 | 0 | 0 | 99.5% |
| language/function-code | PASS | 217 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/future-reserved-words | PASS | 55 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/global-code | PASS | 42 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifier-resolution | PASS | 14 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/identifiers | PASS | 260 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/import | PARTIAL | 19 | 66 | 0 | 0 | 0 | 0 | 22.4% |
| language/keywords | PASS | 25 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/line-terminators | PASS | 41 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/literals | PARTIAL | 531 | 3 | 0 | 0 | 0 | 0 | 99.4% |
| language/module-code | PARTIAL | 577 | 6 | 0 | 0 | 0 | 0 | 99.0% |
| language/punctuators | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/reserved-words | PASS | 27 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/rest-parameters | PASS | 11 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/source-text | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/statementList | PASS | 80 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| language/statements | PARTIAL | 9142 | 12 | 0 | 0 | 0 | 0 | 99.9% |
| language/types | PARTIAL | 111 | 2 | 0 | 0 | 0 | 0 | 98.2% |
| language/white-space | PASS | 67 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Intl402 | PARTIAL | 2 | 47 | 0 | 0 | 0 | 0 | 4.1% |
| staging/Temporal | PASS | 12 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/Uint8Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/built-ins | PARTIAL | 3 | 4 | 0 | 0 | 0 | 0 | 42.9% |
| staging/decorators | PARTIAL | 2 | 1 | 0 | 0 | 0 | 0 | 66.7% |
| staging/explicit-resource-management | PARTIAL | 51 | 3 | 0 | 0 | 0 | 0 | 94.4% |
| staging/set-methods | PASS | 3 | 0 | 0 | 0 | 0 | 0 | 100.0% |
| staging/sm | INCOMPLETE | 1118 | 277 | 0 | 0 | 33 | 0 | 80.1% |
| staging/source-phase-imports | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/upsert | PASS | 71 | 0 | 0 | 0 | 0 | 0 | 100.0% |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| built-ins/RegExp | NORMAL | 232.677s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Syriac.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Tagalog.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Tagbanwa.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Tai_Le.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Tai_Tham.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Tai_Viet.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Takri.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Tamil.js` |
| built-ins/RegExp | NORMAL | 231.802s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Inscriptional_Parthian.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Javanese.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Kaithi.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Kannada.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Katakana.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Kawi.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Kayah_Li.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Kharoshthi.js` |
| built-ins/RegExp | NORMAL | 231.668s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Cypriot.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Cypro_Minoan.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Cyrillic.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Deseret.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Devanagari.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Dives_Akuru.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Dogra.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Duployan.js` |
| built-ins/RegExp | NORMAL | 231.137s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Mende_Kikakui.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Meroitic_Cursive.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Meroitic_Hieroglyphs.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Miao.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Modi.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Mongolian.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Mro.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Multani.js` |
| built-ins/RegExp | NORMAL | 230.841s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Limbu.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Linear_A.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Linear_B.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Lisu.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Lycian.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Lydian.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Mahajani.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Makasar.js` |
| built-ins/RegExp | NORMAL | 227.766s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Bengali.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Bhaiksuki.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Bopomofo.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Brahmi.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Braille.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Buginese.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Buhid.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Canadian_Aboriginal.js` |
| built-ins/RegExp | NORMAL | 226.509s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/Pattern_Syntax.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Pattern_White_Space.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Quotation_Mark.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Radical.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Regional_Indicator.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Adlam.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Ahom.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Anatolian_Hieroglyphs.js` |
| built-ins/RegExp | NORMAL | 225.915s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Han.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Hangul.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Hanifi_Rohingya.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Hanunoo.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Hatran.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Hebrew.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Hiragana.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Imperial_Aramaic.js` |
| built-ins/RegExp | NORMAL | 225.622s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/ID_Continue.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/ID_Start.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Ideographic.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Join_Control.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Logical_Order_Exception.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Lowercase.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Math.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Noncharacter_Code_Point.js` |
| built-ins/RegExp | NORMAL | 223.734s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Nonspacing_Mark.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Number.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Open_Punctuation.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Other.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Other_Letter.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Other_Number.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Other_Punctuation.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Other_Symbol.js` |
| built-ins/RegExp | NORMAL | 221.965s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Titlecase_Letter.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Unassigned.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Uppercase_Letter.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Grapheme_Base.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Grapheme_Extend.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Hex_Digit.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/IDS_Binary_Operator.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/IDS_Trinary_Operator.js` |
| built-ins/RegExp | NORMAL | 221.791s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Grantha.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Greek.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Gujarati.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Gunjala_Gondi.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Gurmukhi.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Gurung_Khema.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Han.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Hangul.js` |
| built-ins/RegExp | NORMAL | 221.588s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Medefaidrin.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Meetei_Mayek.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Mende_Kikakui.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Meroitic_Cursive.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Meroitic_Hieroglyphs.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Miao.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Modi.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Mongolian.js` |
| built-ins/RegExp | NORMAL | 221.568s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Tangut.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Telugu.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Thaana.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Thai.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Tibetan.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Tifinagh.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Tirhuta.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Todhri.js` |
| built-ins/RegExp | NORMAL | 219.762s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Control.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Currency_Symbol.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Dash_Punctuation.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Decimal_Number.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Enclosing_Mark.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Final_Punctuation.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Format.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/General_Category_-_Initial_Punctuation.js` |
| built-ins/RegExp | NORMAL | 219.760s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_-_SignWriting.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Sinhala.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Sogdian.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Sora_Sompeng.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Soyombo.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Sundanese.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Sunuwar.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_-_Syloti_Nagri.js` |
| built-ins/RegExp | NORMAL | 218.963s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Sogdian.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Sora_Sompeng.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Soyombo.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Sundanese.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Sunuwar.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Syloti_Nagri.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Syriac.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Tagalog.js` |
| built-ins/RegExp | NORMAL | 218.113s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Bopomofo.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Brahmi.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Braille.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Buginese.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Buhid.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Canadian_Aboriginal.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Carian.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Caucasian_Albanian.js` |
| built-ins/RegExp | NORMAL | 217.906s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Nyiakeng_Puachue_Hmong.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Ogham.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Ol_Chiki.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Ol_Onal.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Old_Hungarian.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Old_Italic.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Old_North_Arabian.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Old_Permic.js` |
| built-ins/RegExp | NORMAL | 216.670s | 8 | `test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Armenian.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Avestan.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Balinese.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Bamum.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Bassa_Vah.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Batak.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Bengali.js`<br>`test262/test/built-ins/RegExp/property-escapes/generated/Script_Extensions_-_Bhaiksuki.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are executed as ES modules (always strict, import/export processed).
- Tests flagged as async are skipped by the current runner.
- Mixed-mode tests currently run only in sloppy mode unless onlyStrict or noStrict is set.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

