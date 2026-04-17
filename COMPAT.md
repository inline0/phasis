# Compatibility Snapshot

Generated from an in-progress `test262` pass. Do not edit by hand.

- Refresh: `./bin/compat-report`
- Resume: `./bin/compat-report`
- Snapshot time: `2026-04-17T06:31:13+00:00`
- Chunk size: `250`
- Timeout: `300s`
- Jobs: `4`
- Groups: `137`
- Test files: `50506`
- Git: `codex/support-report` @ `6aae099` (dirty)

## Summary

| Pass | Fail | Skip | Blocked | Pending | Running | Attempted | Known | Total | Pass Rate |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 876 | 198 | 1426 | 0 | 47256 | 750 | 1074 | 2500 | 50506 | 81.6% |

## Top-Level Areas

| Area | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB | PENDING | 0 | 0 | 0 | 0 | 1079 | 0 | n/a |
| built-ins | RUNNING | 649 | 177 | 674 | 0 | 20479 | 750 | 78.6% |
| harness | PENDING | 0 | 0 | 0 | 0 | 116 | 0 | n/a |
| intl402 | PENDING | 0 | 0 | 0 | 0 | 1566 | 0 | n/a |
| language | INCOMPLETE | 227 | 21 | 752 | 0 | 22384 | 0 | 91.5% |
| staging | PENDING | 0 | 0 | 0 | 0 | 1632 | 0 | n/a |

## Group Coverage

| Group | Status | Pass | Fail | Skip | Blocked | Pending | Running | Pass Rate |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| annexB/built-ins/Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/Date | PENDING | 0 | 0 | 0 | 0 | 24 | 0 | n/a |
| annexB/built-ins/Function | PENDING | 0 | 0 | 0 | 0 | 6 | 0 | n/a |
| annexB/built-ins/Object | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/RegExp | PENDING | 0 | 0 | 0 | 0 | 62 | 0 | n/a |
| annexB/built-ins/String | PENDING | 0 | 0 | 0 | 0 | 111 | 0 | n/a |
| annexB/built-ins/TypedArrayConstructors | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| annexB/built-ins/escape | PENDING | 0 | 0 | 0 | 0 | 16 | 0 | n/a |
| annexB/built-ins/unescape | PENDING | 0 | 0 | 0 | 0 | 19 | 0 | n/a |
| annexB/language/comments | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| annexB/language/eval-code | PENDING | 0 | 0 | 0 | 0 | 469 | 0 | n/a |
| annexB/language/expressions | PENDING | 0 | 0 | 0 | 0 | 19 | 0 | n/a |
| annexB/language/function-code | PENDING | 0 | 0 | 0 | 0 | 159 | 0 | n/a |
| annexB/language/global-code | PENDING | 0 | 0 | 0 | 0 | 153 | 0 | n/a |
| annexB/language/literals | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| annexB/language/statements | PENDING | 0 | 0 | 0 | 0 | 22 | 0 | n/a |
| built-ins/AbstractModuleSource | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| built-ins/Array | RUNNING | 0 | 0 | 0 | 0 | 2825 | 250 | n/a |
| built-ins/ArrayBuffer | PENDING | 0 | 0 | 0 | 0 | 192 | 0 | n/a |
| built-ins/ArrayIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 27 | 0 | n/a |
| built-ins/AsyncDisposableStack | PENDING | 0 | 0 | 0 | 0 | 52 | 0 | n/a |
| built-ins/AsyncFromSyncIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 38 | 0 | n/a |
| built-ins/AsyncFunction | PENDING | 0 | 0 | 0 | 0 | 18 | 0 | n/a |
| built-ins/AsyncGeneratorFunction | PENDING | 0 | 0 | 0 | 0 | 23 | 0 | n/a |
| built-ins/AsyncGeneratorPrototype | PENDING | 0 | 0 | 0 | 0 | 48 | 0 | n/a |
| built-ins/AsyncIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 10 | 0 | n/a |
| built-ins/Atomics | INCOMPLETE | 0 | 6 | 244 | 0 | 126 | 0 | 0.0% |
| built-ins/BigInt | PENDING | 0 | 0 | 0 | 0 | 75 | 0 | n/a |
| built-ins/Boolean | PENDING | 0 | 0 | 0 | 0 | 51 | 0 | n/a |
| built-ins/DataView | PENDING | 0 | 0 | 0 | 0 | 550 | 0 | n/a |
| built-ins/Date | PENDING | 0 | 0 | 0 | 0 | 594 | 0 | n/a |
| built-ins/DisposableStack | PENDING | 0 | 0 | 0 | 0 | 52 | 0 | n/a |
| built-ins/Error | PENDING | 0 | 0 | 0 | 0 | 53 | 0 | n/a |
| built-ins/FinalizationRegistry | PENDING | 0 | 0 | 0 | 0 | 47 | 0 | n/a |
| built-ins/Function | PENDING | 0 | 0 | 0 | 0 | 509 | 0 | n/a |
| built-ins/GeneratorFunction | PENDING | 0 | 0 | 0 | 0 | 23 | 0 | n/a |
| built-ins/GeneratorPrototype | PENDING | 0 | 0 | 0 | 0 | 61 | 0 | n/a |
| built-ins/Infinity | PENDING | 0 | 0 | 0 | 0 | 6 | 0 | n/a |
| built-ins/Iterator | PENDING | 0 | 0 | 0 | 0 | 431 | 0 | n/a |
| built-ins/JSON | PENDING | 0 | 0 | 0 | 0 | 165 | 0 | n/a |
| built-ins/Map | PENDING | 0 | 0 | 0 | 0 | 171 | 0 | n/a |
| built-ins/MapIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| built-ins/Math | PENDING | 0 | 0 | 0 | 0 | 327 | 0 | n/a |
| built-ins/NaN | PENDING | 0 | 0 | 0 | 0 | 6 | 0 | n/a |
| built-ins/NativeErrors | PENDING | 0 | 0 | 0 | 0 | 139 | 0 | n/a |
| built-ins/Number | PENDING | 0 | 0 | 0 | 0 | 335 | 0 | n/a |
| built-ins/Object | INCOMPLETE | 370 | 127 | 3 | 0 | 2910 | 0 | 74.4% |
| built-ins/Promise | RUNNING | 0 | 0 | 0 | 0 | 381 | 250 | n/a |
| built-ins/Proxy | PENDING | 0 | 0 | 0 | 0 | 311 | 0 | n/a |
| built-ins/Reflect | PENDING | 0 | 0 | 0 | 0 | 153 | 0 | n/a |
| built-ins/RegExp | RUNNING | 48 | 26 | 176 | 0 | 1367 | 250 | 64.9% |
| built-ins/RegExpStringIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 17 | 0 | n/a |
| built-ins/Set | PENDING | 0 | 0 | 0 | 0 | 381 | 0 | n/a |
| built-ins/SetIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| built-ins/ShadowRealm | PENDING | 0 | 0 | 0 | 0 | 64 | 0 | n/a |
| built-ins/SharedArrayBuffer | PENDING | 0 | 0 | 0 | 0 | 104 | 0 | n/a |
| built-ins/String | INCOMPLETE | 231 | 18 | 1 | 0 | 962 | 0 | 92.8% |
| built-ins/StringIteratorPrototype | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| built-ins/Symbol | PENDING | 0 | 0 | 0 | 0 | 94 | 0 | n/a |
| built-ins/Temporal | INCOMPLETE | 0 | 0 | 250 | 0 | 3915 | 0 | n/a |
| built-ins/ThrowTypeError | PENDING | 0 | 0 | 0 | 0 | 14 | 0 | n/a |
| built-ins/TypedArray | PENDING | 0 | 0 | 0 | 0 | 1426 | 0 | n/a |
| built-ins/TypedArrayConstructors | PENDING | 0 | 0 | 0 | 0 | 736 | 0 | n/a |
| built-ins/Uint8Array | PENDING | 0 | 0 | 0 | 0 | 64 | 0 | n/a |
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
| built-ins/parseFloat | PENDING | 0 | 0 | 0 | 0 | 59 | 0 | n/a |
| built-ins/parseInt | PENDING | 0 | 0 | 0 | 0 | 60 | 0 | n/a |
| built-ins/undefined | PENDING | 0 | 0 | 0 | 0 | 8 | 0 | n/a |
| harness | PENDING | 0 | 0 | 0 | 0 | 116 | 0 | n/a |
| intl402 | PENDING | 0 | 0 | 0 | 0 | 22 | 0 | n/a |
| intl402/Array | PENDING | 0 | 0 | 0 | 0 | 2 | 0 | n/a |
| intl402/BigInt | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| intl402/Collator | PENDING | 0 | 0 | 0 | 0 | 62 | 0 | n/a |
| intl402/Date | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| intl402/DateTimeFormat | PENDING | 0 | 0 | 0 | 0 | 188 | 0 | n/a |
| intl402/DisplayNames | PENDING | 0 | 0 | 0 | 0 | 57 | 0 | n/a |
| intl402/DurationFormat | PENDING | 0 | 0 | 0 | 0 | 110 | 0 | n/a |
| intl402/Intl | PENDING | 0 | 0 | 0 | 0 | 67 | 0 | n/a |
| intl402/ListFormat | PENDING | 0 | 0 | 0 | 0 | 81 | 0 | n/a |
| intl402/Locale | PENDING | 0 | 0 | 0 | 0 | 147 | 0 | n/a |
| intl402/Number | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| intl402/NumberFormat | PENDING | 0 | 0 | 0 | 0 | 252 | 0 | n/a |
| intl402/PluralRules | PENDING | 0 | 0 | 0 | 0 | 50 | 0 | n/a |
| intl402/RelativeTimeFormat | PENDING | 0 | 0 | 0 | 0 | 79 | 0 | n/a |
| intl402/Segmenter | PENDING | 0 | 0 | 0 | 0 | 78 | 0 | n/a |
| intl402/String | PENDING | 0 | 0 | 0 | 0 | 17 | 0 | n/a |
| intl402/Temporal | PENDING | 0 | 0 | 0 | 0 | 323 | 0 | n/a |
| intl402/TypedArray | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/arguments-object | PENDING | 0 | 0 | 0 | 0 | 263 | 0 | n/a |
| language/asi | PENDING | 0 | 0 | 0 | 0 | 102 | 0 | n/a |
| language/block-scope | PENDING | 0 | 0 | 0 | 0 | 145 | 0 | n/a |
| language/comments | PENDING | 0 | 0 | 0 | 0 | 52 | 0 | n/a |
| language/computed-property-names | PENDING | 0 | 0 | 0 | 0 | 48 | 0 | n/a |
| language/destructuring | PENDING | 0 | 0 | 0 | 0 | 19 | 0 | n/a |
| language/directive-prologue | PENDING | 0 | 0 | 0 | 0 | 62 | 0 | n/a |
| language/eval-code | PENDING | 0 | 0 | 0 | 0 | 347 | 0 | n/a |
| language/export | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| language/expressions | INCOMPLETE | 227 | 21 | 502 | 0 | 10273 | 0 | 91.5% |
| language/function-code | PENDING | 0 | 0 | 0 | 0 | 217 | 0 | n/a |
| language/future-reserved-words | PENDING | 0 | 0 | 0 | 0 | 55 | 0 | n/a |
| language/global-code | PENDING | 0 | 0 | 0 | 0 | 42 | 0 | n/a |
| language/identifier-resolution | PENDING | 0 | 0 | 0 | 0 | 14 | 0 | n/a |
| language/identifiers | PENDING | 0 | 0 | 0 | 0 | 260 | 0 | n/a |
| language/import | PENDING | 0 | 0 | 0 | 0 | 85 | 0 | n/a |
| language/keywords | PENDING | 0 | 0 | 0 | 0 | 25 | 0 | n/a |
| language/line-terminators | PENDING | 0 | 0 | 0 | 0 | 41 | 0 | n/a |
| language/literals | PENDING | 0 | 0 | 0 | 0 | 534 | 0 | n/a |
| language/module-code | INCOMPLETE | 0 | 0 | 250 | 0 | 333 | 0 | n/a |
| language/punctuators | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/reserved-words | PENDING | 0 | 0 | 0 | 0 | 27 | 0 | n/a |
| language/rest-parameters | PENDING | 0 | 0 | 0 | 0 | 11 | 0 | n/a |
| language/source-text | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| language/statementList | PENDING | 0 | 0 | 0 | 0 | 80 | 0 | n/a |
| language/statements | PENDING | 0 | 0 | 0 | 0 | 9154 | 0 | n/a |
| language/types | PENDING | 0 | 0 | 0 | 0 | 113 | 0 | n/a |
| language/white-space | PENDING | 0 | 0 | 0 | 0 | 67 | 0 | n/a |
| staging | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/Intl402 | PENDING | 0 | 0 | 0 | 0 | 49 | 0 | n/a |
| staging/Temporal | PENDING | 0 | 0 | 0 | 0 | 12 | 0 | n/a |
| staging/Uint8Array | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/built-ins | PENDING | 0 | 0 | 0 | 0 | 7 | 0 | n/a |
| staging/decorators | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/explicit-resource-management | PENDING | 0 | 0 | 0 | 0 | 54 | 0 | n/a |
| staging/set-methods | PENDING | 0 | 0 | 0 | 0 | 3 | 0 | n/a |
| staging/sm | PENDING | 0 | 0 | 0 | 0 | 1428 | 0 | n/a |
| staging/source-phase-imports | PENDING | 0 | 0 | 0 | 0 | 1 | 0 | n/a |
| staging/upsert | PENDING | 0 | 0 | 0 | 0 | 71 | 0 | n/a |

## Slowest Chunks

| Group | Kind | Duration | Files | Sample |
|---|---|---:|---:|---|
| built-ins/Object | NORMAL | 3.869s | 250 | `test262/test/built-ins/Object/preventExtensions/15.2.3.10-3-1.js`<br>`test262/test/built-ins/Object/preventExtensions/15.2.3.10-3-10.js`<br>...<br>`test262/test/built-ins/Object/prototype/toString/symbol-tag-map-builtin.js`<br>`test262/test/built-ins/Object/prototype/toString/symbol-tag-non-str-bigint.js` |
| built-ins/String | NORMAL | 3.615s | 250 | `test262/test/built-ins/String/15.5.5.5.2-1-1.js`<br>`test262/test/built-ins/String/15.5.5.5.2-1-2.js`<br>...<br>`test262/test/built-ins/String/prototype/endsWith/return-abrupt-from-searchstring-as-symbol.js`<br>`test262/test/built-ins/String/prototype/endsWith/return-abrupt-from-searchstring-regexp-test.js` |
| built-ins/Object | NORMAL | 3.364s | 250 | `test262/test/built-ins/Object/defineProperty/15.2.3.6-3-218.js`<br>`test262/test/built-ins/Object/defineProperty/15.2.3.6-3-219-1.js`<br>...<br>`test262/test/built-ins/Object/defineProperty/15.2.3.6-4-174.js`<br>`test262/test/built-ins/Object/defineProperty/15.2.3.6-4-175.js` |
| language/expressions | NORMAL | 3.257s | 250 | `test262/test/language/expressions/arrow-function/dstr/dflt-obj-ptrn-id-init-unresolvable.js`<br>`test262/test/language/expressions/arrow-function/dstr/dflt-obj-ptrn-id-trailing-comma.js`<br>...<br>`test262/test/language/expressions/assignment/dstr/array-elem-iter-nrml-close-skip.js`<br>`test262/test/language/expressions/assignment/dstr/array-elem-iter-nrml-close.js` |
| built-ins/RegExp | NORMAL | 1.233s | 250 | `test262/test/built-ins/RegExp/prototype/source/value-slash.js`<br>`test262/test/built-ins/RegExp/prototype/source/value-u.js`<br>...<br>`test262/test/built-ins/RegExp/unicodeSets/generated/character-class-escape-difference-character.js`<br>`test262/test/built-ins/RegExp/unicodeSets/generated/character-class-escape-difference-property-of-strings-escape.js` |
| built-ins/Temporal | NORMAL | 0.643s | 250 | `test262/test/built-ins/Temporal/PlainTime/from/options-wrong-type.js`<br>`test262/test/built-ins/Temporal/PlainTime/from/order-of-operations.js`<br>...<br>`test262/test/built-ins/Temporal/PlainTime/prototype/toString/basic.js`<br>`test262/test/built-ins/Temporal/PlainTime/prototype/toString/branding.js` |
| built-ins/Atomics | NORMAL | 0.275s | 250 | `test262/test/built-ins/Atomics/Symbol.toStringTag.js`<br>`test262/test/built-ins/Atomics/add/bad-range.js`<br>...<br>`test262/test/built-ins/Atomics/wait/symbol-for-value-throws.js`<br>`test262/test/built-ins/Atomics/wait/true-for-timeout-agent.js` |
| language/module-code | NORMAL | 0.226s | 250 | `test262/test/language/module-code/parse-err-decl-pos-export-if-if.js`<br>`test262/test/language/module-code/parse-err-decl-pos-export-labeled.js`<br>...<br>`test262/test/language/module-code/top-level-await/syntax/if-block-await-expr-literal-string.js`<br>`test262/test/language/module-code/top-level-await/syntax/if-block-await-expr-nested.js` |
| language/expressions | NORMAL | 0.220s | 250 | `test262/test/language/expressions/class/elements/nested-private-derived-cls-indirect-eval-err-contains-supercall.js`<br>`test262/test/language/expressions/class/elements/nested-private-direct-eval-err-contains-arguments.js`<br>...<br>`test262/test/language/expressions/class/elements/prod-private-getter-before-super-return-in-field-initializer.js`<br>`test262/test/language/expressions/class/elements/prod-private-method-before-super-return-in-constructor.js` |
| language/expressions | NORMAL | 0.217s | 250 | `test262/test/language/expressions/class/dstr/private-gen-meth-static-ary-ptrn-rest-obj-id.js`<br>`test262/test/language/expressions/class/dstr/private-gen-meth-static-ary-ptrn-rest-obj-prop-id.js`<br>...<br>`test262/test/language/expressions/class/dstr/private-meth-static-ary-ptrn-elem-obj-id.js`<br>`test262/test/language/expressions/class/dstr/private-meth-static-ary-ptrn-elem-obj-prop-id-init.js` |

## Runner Caveats

- Feature families listed in config/support.php are skipped before execution.
- Tests flagged as module are skipped by the current runner.
- Tests flagged as async are skipped by the current runner.
- Mixed-mode tests currently run only in sloppy mode unless onlyStrict or noStrict is set.
- Chunks that timeout, OOM, or crash are split automatically until a single-file blocker is isolated.

## Explicitly Skipped Feature Families

- `async-iteration`
- `top-level-await`
- `Atomics`
- `SharedArrayBuffer`
- `resizable-arraybuffer`
- `arraybuffer-transfer`
- `Float16Array`
- `WeakRef`
- `FinalizationRegistry`
- `symbols-as-weakmap-keys`
- `import-assertions`
- `import-attributes`
- `dynamic-import`
- `arbitrary-module-namespace-names`
- `json-modules`
- `source-phase-imports`
- `Symbol.species`
- `Symbol.asyncIterator`
- `regexp-lookbehind`
- `regexp-named-groups`
- `regexp-dotall`
- `regexp-unicode-property-escapes`
- `regexp-match-indices`
- `regexp-v-flag`
- `regexp-modifiers`
- `regexp-duplicate-named-groups`
- `RegExp.escape`
- `class-fields-public`
- `class-fields-private`
- `class-static-fields-public`
- `class-static-fields-private`
- `class-methods-private`
- `class-static-methods-private`
- `class-static-block`
- `caller`
- `cross-realm`
- `Intl`
- `tail-call-optimization`
- `ShadowRealm`
- `Temporal`
- `decorators`
- `explicit-resource-management`
- `hashbang`
- `IsHTMLDDA`
- `iterator-helpers`
- `set-methods`
- `Array.fromAsync`
- `change-array-by-copy`
- `Math.sumPrecise`
- `well-formed-json-stringify`
- `json-parse-with-source`
- `String.prototype.isWellFormed`
- `String.prototype.toWellFormed`
