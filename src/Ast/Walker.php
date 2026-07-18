<?php

declare(strict_types=1);

namespace Phasis\Ast;

final class Walker
{
    public const SKIP = 'phasis.walker.skip';

    public static function walk(Node $node, callable $enter, ?callable $leave = null): void
    {
        self::visit($node, null, $enter, $leave);
    }

    private static function visit(Node $node, ?Node $parent, callable $enter, ?callable $leave): void
    {
        if ($enter($node, $parent) !== self::SKIP) {
            foreach (self::children($node) as $child) {
                self::visit($child, $node, $enter, $leave);
            }
        }
        if ($leave !== null) {
            $leave($node, $parent);
        }
    }

    /**
     * @return list<Node>
     */
    private static function children(Node $node): array
    {
        $children = [];
        $reflection = new \ReflectionObject($node);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                $children[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $children[] = $item;
                    }
                }
            }
        }
        return $children;
    }
}
