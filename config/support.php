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
    'test262_skipped_features' => [
        // Module proposals not implemented. Tests for these features
        // dynamic-import all variants of import.defer / import.source /
        // import-attributes / JSON modules.
        'import-defer',
        'source-phase-imports',
        'import-attributes',
        'json-modules',
        // Multi-realm support is not implemented; tests using
        // $262.createRealm() can't run.
        'cross-realm',
        // Top-level await ticks ordering for ES modules is not
        // implemented at the spec-precise tick granularity that the
        // module-code tests assert against.
        'top-level-await',
        // Resizable ArrayBuffer (transfer + resize semantics) and the
        // matching SharedArrayBuffer.grow are not implemented; tests
        // that exercise resize during iteration would need a fresh
        // backing-store impl.
        'resizable-arraybuffer',
        // Duplicate named capture groups (?<x>...|...(?<x>...)) require
        // a regex engine that lets two groups share a name in different
        // alternatives. PCRE2 doesn't expose this directly.
        'regexp-duplicate-named-groups',
    ],
];
