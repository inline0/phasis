<?php

declare(strict_types=1);

namespace Phasis\Bytecode;

use Phasis\Ast\Node;
use Phasis\Value\JsFunction;

/** Bytecode layouts shared by factories without sharing runtime inline caches. */
final class CompilationTemplates
{
    /** @var ?\WeakMap<Node, array<string, CompiledFunction>> */
    private static ?\WeakMap $templates = null;

    /** Return an independent function cache over an immutable compiled layout. */
    public static function get(JsFunction $function): ?CompiledFunction
    {
        $body = $function->getBody();
        if (!$body instanceof Node || self::$templates === null) {
            return null;
        }
        $template = self::$templates[$body][CompilationFailures::signature($function, 'bytecode')] ?? null;
        return $template !== null ? clone $template : null;
    }

    /** Save a freshly compiled layout before its first execution. */
    public static function remember(JsFunction $function, CompiledFunction $compiled): void
    {
        $body = $function->getBody();
        if (!$body instanceof Node) {
            return;
        }
        self::$templates ??= new \WeakMap();
        $entries = self::$templates[$body] ?? [];
        $entries[CompilationFailures::signature($function, 'bytecode')] = clone $compiled;
        self::$templates[$body] = $entries;
    }
}
