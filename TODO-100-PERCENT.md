# Road to 100% test262 Compliance

Last compat snapshot: **87.2%** (43,332 / 49,708 attempted) on commit c30a2e3.
With latest fixes (post-snapshot), estimated **89-90%**.

## Top Failure Areas (sorted by fail count)

### Tier 1: Massive Impact (100+ failures each)
- [ ] **built-ins/Temporal** — 2000 fail / 4162 total (51.9%) — BIGGEST area
  - PlainDate (48→69%), PlainDateTime (40→58%), PlainYearMonth (28→77%)
  - ZonedDateTime (25%), Instant (64→81%), Duration (79→94%)
  - Missing: `until`/`since` on PlainDateTime, `round` on many types
  - Order-of-operations (alphabetical property reads)
  - Calendar object handling (Temporal objects as calendar)
  - String validation edge cases
- [ ] **language/expressions** — 992 fail / 10964 (91.0%)
  - ~900 are `dynamic-import` (module namespace, import() semantics)
  - ~50 are TCO (tail call optimization)
  - ~20 are async/await microtask ordering
  - Rest: class fields, yield edge cases
- [ ] **language/statements** — 762 fail / 9151 (91.7%)
  - ~500 are `for-of`/`for-in` destructuring edge cases
  - ~100 are class declaration edge cases
  - ~50 are `with` statement + Proxy interactions
  - ~50 are TCO in various statement contexts
- [ ] **staging/sm** — 351 fail / 1367 (74.3%) — SpiderMonkey staging tests
- [ ] **intl402/Temporal** — 269 fail / 323 (16.7%) — Intl + Temporal combo
- [ ] **built-ins/Promise** — 263 fail / 584 (55.0%) — mostly timeouts
  - Microtask ordering (sync execution model limitation)
  - resolve-self, thenable chains
  - Species constructor realm tests
- [ ] **built-ins/RegExp** — 210 fail / 1431 (85.3%)
  - Regex modifiers (`(?ims-ims:...)`)
  - Duplicate named capturing groups
  - Unicode property escapes edge cases
  - PCRE2 vs ES regex differences

### Tier 2: Medium Impact (20-150 failures)
- [ ] **intl402/NumberFormat** — 149 fail / 252 (40.9%)
- [ ] **language/module-code** — 119 fail / 583 (79.6%) — ES modules
- [ ] **intl402/DateTimeFormat** — 115 fail / 188 (38.8%)
- [ ] **built-ins/Array** — 111 fail / 3022 (96.3%)
  - Resizable ArrayBuffer backed arrays
  - Cross-realm tests
  - Species constructor
- [ ] **intl402/DurationFormat** — 110 fail / 110 (0.0%) — not implemented
- [ ] **built-ins/TypedArrayConstructors** — 98 fail / 736 (86.7%)
- [ ] **language/identifiers** — 94 fail / 260 (63.8%)
  - Unicode identifier edge cases
- [ ] **language/import** — 73 fail / 85 (14.1%) — import statements
- [ ] **intl402/Locale** — 66 fail / 147 (55.1%)
- [ ] **built-ins/Atomics** — 63 fail / 318 (80.2%)
  - $262.agent threading tests
- [ ] **staging/Intl402** — 49 fail (0.0%)
- [ ] **staging/upsert** — 48 fail / 71 (32.4%)
- [ ] **built-ins/TypedArray** — 44 fail / 1426 (96.9%)
- [ ] **intl402/ListFormat** — 44 fail / 81 (45.7%)

### Tier 3: Small Impact (< 20 failures)
- [ ] **built-ins/ShadowRealm** — 12 fail / 64 (81.2%)
- [ ] **built-ins/Function** — 11 fail / 503 (97.8%)
- [ ] **built-ins/String** — 10 fail / 1212 (99.2%)
- [ ] **harness** — 9 fail / 116 (92.2%)
- [ ] **annexB/built-ins/RegExp** — 8 fail / 54 (85.2%)
- [ ] **built-ins/Proxy** — 8 fail / 311 (97.4%)
- [ ] **built-ins/DataView** — 7 fail / 550 (98.7%)
- [ ] **built-ins/AsyncFromSyncIteratorPrototype** — 5 fail / 38 (86.8%)
- [ ] **built-ins/Object** — 5 fail / 3410 (99.9%)
- [ ] **built-ins/ArrayBuffer** — 5 fail / 192 (97.4%)
- [ ] **built-ins/Iterator** — 4 fail / 431 (99.1%)

## Already at 100% (full scale)
Number(335), Boolean(51), Error(53), Map(171), BigInt(75), Reflect(153),
WeakMap(102), WeakSet(85), WeakRef(29), FinalizationRegistry(47),
DisposableStack(52), AsyncDisposableStack(52), parseInt(60), parseFloat(59),
isNaN(17), isFinite(17), encodeURI(31), encodeURIComponent(31), eval(10),
ThrowTypeError(14), undefined(8), Infinity(6), NaN(6), GeneratorPrototype(61),
AsyncFunction(18), MapIteratorPrototype(11), SetIteratorPrototype(11),
ArrayIteratorPrototype(27), StringIteratorPrototype(7)

## Strategy for 100%

### Phase 1: Temporal (biggest single area, ~2000 failures)
1. Add missing prototype methods: `until`, `since`, `round` on PlainDateTime
2. ZonedDateTime: timezone resolution, offset handling
3. Calendar object support (Temporal objects as calendar argument)
4. String parsing: all edge cases per spec
5. Order-of-operations: alphabetical property reads everywhere
6. Range validation: nanosecond-level PlainDateTime limits
7. Duration: total(), round() with relativeTo

### Phase 2: Intl API (~928 failures across intl402)
1. DurationFormat (110 tests, 0% — needs full implementation)
2. NumberFormat (149 tests — option handling, formatting)
3. DateTimeFormat (115 tests — pattern formatting)
4. Locale (66 tests — subtag parsing)
5. ListFormat, RelativeTimeFormat, PluralRules, Segmenter, Collator, DisplayNames

### Phase 3: Language features (~1800 failures)
1. ES modules: import/export, dynamic import() (119+73 tests)
2. TCO: tail call optimization (~100 tests across multiple categories)
3. Async microtask ordering (~50 tests)
4. Unicode identifiers (94 tests)
5. Class field edge cases

### Phase 4: Built-ins cleanup (~500 failures)
1. Promise: fix timeouts, self-resolution (~263 tests)
2. RegExp: modifiers, duplicate names (~210 tests)
3. Array: resizable buffer, species (~111 tests)
4. TypedArray/TypedArrayConstructors: buffer edge cases (~142 tests)
5. Atomics: agent threading (~63 tests)

### Phase 5: Staging (~400 failures)
1. staging/sm: SpiderMonkey-specific tests
2. staging/upsert: Map.prototype.getOrInsertComputed etc.
3. staging/Intl402, staging/Temporal, staging/built-ins

## Structural Gaps (may never reach 100%)
- **Cross-realm ($262.createRealm)**: ~50-100 tests need multi-realm support
- **$262.agent threading**: ~60 tests need actual threads
- **$262.detachArrayBuffer**: ~20 tests need host detach API
- **PCRE2 vs ES regex**: ~30 tests have regex behavior differences
- **Proper tail calls**: ~100 tests (V8 doesn't pass these either)
- **True async suspension**: ~50 tests need real event loop

## CI Notes
- Compat report needs ~3-4 hours on GitHub Actions
- Current timeout: 300min job, 240min step
- Run with: `gh workflow run compat.yml`
- Results: COMPAT.md + compat.json artifacts
