<?php

declare(strict_types=1);

namespace Phasis\Ast;

final class Serializer
{
    private const INTERNAL_FIELDS = [
        'location',
        'verbatim',
        'cached',
        'bodyHasClosure',
        'hoistsLexicalCache',
        'hoistsVarOrFuncCache',
        'resolvedDepth',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function toArray(Node $node): array
    {
        $result = ['type' => $node->type()];
        $reflection = new \ReflectionObject($node);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            if (in_array($name, self::INTERNAL_FIELDS, true)) {
                continue;
            }
            $result[$name] = self::convertValue($prop->getValue($node));
        }
        ksort($result);
        return $result;
    }

    public static function toJson(Node $node, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return json_encode(self::toArray($node), $flags);
    }

    private static function convertValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_scalar($value)) {
            return $value;
        }
        if (is_array($value)) {
            return array_map(fn($v) => self::convertValue($v), $value);
        }
        if ($value instanceof Node) {
            return self::toArray($value);
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }
        return null;
    }
}
