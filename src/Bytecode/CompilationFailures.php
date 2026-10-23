<?php

declare(strict_types=1);

namespace Phasis\Bytecode;

use Phasis\Ast\Node;
use Phasis\Value\JsFunction;

/** Structural compiler bailouts shared by closures created from the same syntax. */
final class CompilationFailures
{
    /** @var ?\WeakMap<Node, array<string, string>> */
    private static ?\WeakMap $failures = null;
    /** @var ?\WeakMap<Node, int> */
    private static ?\WeakMap $parameterIds = null;
    private static int $nextParameterId = 0;

    /** Return a prior structural bailout without retaining any closure environment. */
    public static function get(JsFunction $function, string $compiler): ?string
    {
        $body = $function->getBody();
        if (!$body instanceof Node || self::$failures === null) {
            return null;
        }
        return self::$failures[$body][self::signature($function, $compiler)] ?? null;
    }

    /** Remember only syntax-based failures, never a runtime specialization failure. */
    public static function remember(JsFunction $function, string $compiler, string $reason): void
    {
        $body = $function->getBody();
        if (!$body instanceof Node) {
            return;
        }
        self::$failures ??= new \WeakMap();
        $entries = self::$failures[$body] ?? [];
        $entries[self::signature($function, $compiler)] = $reason;
        self::$failures[$body] = $entries;
    }

    /** Describe syntax and immutable function flags without retaining a closure. */
    public static function signature(JsFunction $function, string $compiler): string
    {
        $parameters = [];
        foreach ($function->getParams() as $parameter) {
            if ($parameter instanceof Node) {
                self::$parameterIds ??= new \WeakMap();
                self::$parameterIds[$parameter] ??= ++self::$nextParameterId;
                $parameters[] = self::$parameterIds[$parameter];
            } else {
                $parameters[] = null;
            }
        }
        return $compiler . ':' . json_encode([
            $parameters, $function->isStrict(), $function->isArrow(), $function->isGenerator(),
            $function->isAsync(), $function->isClassConstructor(), $function->isDerivedConstructor(),
            $function->getHomeObject() !== null,
        ], JSON_THROW_ON_ERROR);
    }
}
