<?php

declare(strict_types=1);

return [
    'test262_categories' => [
        'language/literals',
        'language/types',
        'language/expressions',
        'language/statements',
        'language/block-scope',
        'language/function-code',
        'language/arguments-object',
        'language/destructuring',
        'language/computed-property-names',
        'language/rest-parameters',
        'language/directive-prologue',
        'language/eval-code',
        'language/asi',
        'language/keywords',
        'language/white-space',
        'language/line-terminators',
        'language/comments',
        'language/future-reserved-words',
        'language/reserved-words',
        'language/identifier-resolution',
        'built-ins/Array',
        'built-ins/Boolean',
        'built-ins/Date',
        'built-ins/Error',
        'built-ins/Function',
        'built-ins/JSON',
        'built-ins/Map',
        'built-ins/Math',
        'built-ins/Number',
        'built-ins/Object',
        'built-ins/RegExp',
        'built-ins/Set',
        'built-ins/String',
        'built-ins/Symbol',
        'built-ins/parseInt',
        'built-ins/parseFloat',
        'built-ins/isNaN',
        'built-ins/isFinite',
        'annexB/built-ins/String',
        'annexB/built-ins/escape',
        'annexB/built-ins/unescape',
    ],
    // No skipped features. The goal is 100% of all 50,506 test262
    // tests passing — features the engine doesn't implement yet must
    // count as failures, not be hidden behind a skip filter.
    'test262_skipped_features' => [],
    // Tests that individually approach the per-test 30s engine budget
    // and reliably pass on their own but tip a 25-test chunk over its
    // wall budget when grouped with neighbours. Each path here is
    // promoted to its own single-file chunk during bin/compat-report
    // so it can use the full per-chunk timeout in isolation. Grouping
    // them here is purely a runner-config knob, not a skip: the test
    // still runs and contributes to compliance.
    'test262_isolated_tests' => [
        // Iterates ~290k 4-byte UTF-8 sequences through decodeURI.
        'built-ins/decodeURI/S15.1.3.1_A2.5_T1.js',
        'built-ins/decodeURI/S15.1.3.1_A1.10_T1.js',
        'built-ins/decodeURI/S15.1.3.1_A1.11_T1.js',
        'built-ins/decodeURI/S15.1.3.1_A1.4_T1.js',
        'built-ins/decodeURI/S15.1.3.1_A1.5_T1.js',
        'built-ins/decodeURIComponent/S15.1.3.2_A2.5_T1.js',
        'built-ins/decodeURIComponent/S15.1.3.2_A1.10_T1.js',
        'built-ins/decodeURIComponent/S15.1.3.2_A1.11_T1.js',
        'built-ins/decodeURIComponent/S15.1.3.2_A1.4_T1.js',
        'built-ins/decodeURIComponent/S15.1.3.2_A1.5_T1.js',
        // RegExp \d/\D/\s/\S/\w/\W full Unicode codepoint sweeps.
        'built-ins/RegExp/CharacterClassEscapes/character-class-digit-class-escape-negative-cases.js',
        'built-ins/RegExp/CharacterClassEscapes/character-class-non-digit-class-escape-positive-cases.js',
        'built-ins/RegExp/CharacterClassEscapes/character-class-non-whitespace-class-escape-positive-cases.js',
        'built-ins/RegExp/CharacterClassEscapes/character-class-whitespace-class-escape-negative-cases.js',
        'built-ins/RegExp/CharacterClassEscapes/character-class-word-class-escape-negative-cases.js',
        // staging/sm slow tests (full case-mapping sweep, deep recursion,
        // dense array fills, large counting-sort, 16 MiB regex literal).
        'staging/sm/String/string-upper-lower-mapping.js',
        'staging/sm/String/string-code-point-upper-lower-mapping.js',
        'staging/sm/String/string-pad-start-end.js',
        'staging/sm/Symbol/property-reflection.js',
        'staging/sm/Symbol/realms.js',
        'staging/sm/Array/toSpliced-dense.js',
        'staging/sm/regress/regress-1507322-deep-weakmap.js',
        'staging/sm/regress/regress-610026.js',
        'staging/sm/RegExp/unicode-braced.js',
        'staging/sm/RegExp/unicode-class-braced.js',
        'staging/sm/RegExp/unicode-ignoreCase.js',
        'staging/sm/TypedArray/sort_modifications.js',
        'staging/sm/TypedArray/sort_small.js',
        'staging/sm/TypedArray/sort_large_countingsort.js',
        'staging/sm/TypedArray/element-setting-converts-using-ToNumber.js',
        'staging/sm/TypedArray/reduce-and-reduceRight.js',
        'staging/sm/TypedArray/reverse.js',
        'staging/sm/TypedArray/sort_errors.js',
        'staging/sm/TypedArray/sort_globals.js',
        // Lunisolar Temporal calendar reference-ISO search is O(years).
        'staging/sm/Temporal/PlainMonthDay/from-chinese.js',
        'staging/sm/Temporal/PlainMonthDay/from-chinese-leap-month-common.js',
        'staging/sm/Temporal/PlainMonthDay/from-chinese-leap-month-uncommon.js',
        'staging/sm/Temporal/PlainDate/from-constrain-japanese.js',
        'staging/sm/Temporal/PlainDate/from-constrain-hebrew.js',
        // SM DST cache stress: O(n^4) probe of the JS-side DST cache.
        // Each shard sweeps a different millennium-scale window.
        'staging/sm/Date/dst-offset-caching-1-of-8.js',
        'staging/sm/Date/dst-offset-caching-2-of-8.js',
        'staging/sm/Date/dst-offset-caching-3-of-8.js',
        'staging/sm/Date/dst-offset-caching-4-of-8.js',
        'staging/sm/Date/dst-offset-caching-5-of-8.js',
        'staging/sm/Date/dst-offset-caching-6-of-8.js',
        'staging/sm/Date/dst-offset-caching-7-of-8.js',
        'staging/sm/Date/dst-offset-caching-8-of-8.js',
        // SM Temporal/Intl agreement test, 13K Temporal-vs-DateTimeFormat round-trips.
        'staging/sm/Temporal/Calendar/compare-to-datetimeformat.js',
        // SM nullish-coalescing assert loop, 1e5 iterations of the basic-cases bundle.
        'staging/sm/expressions/nullish-coalescing.js',
        // Module DFS verification builds a deep import graph.
        'language/module-code/verify-dfs.js',
        // Long regex literal in expressions sweep slows the chunk.
        'language/literals/regexp/7.8.5-2gs.js',
    ],
];
