<?php

declare(strict_types=1);

namespace PhpJs\Object;

use PhpJs\Value\JsValue;

class PropertyMap
{
    /** @var array<string, PropertyDescriptor> */
    private array $properties = [];

    public function get(string $key): ?PropertyDescriptor
    {
        return $this->properties[$key] ?? null;
    }

    public function set(string $key, PropertyDescriptor $desc): void
    {
        $this->properties[$key] = $desc;
    }

    public function has(string $key): bool
    {
        return isset($this->properties[$key]);
    }

    public function delete(string $key): bool
    {
        if (!isset($this->properties[$key])) {
            return true;
        }

        unset($this->properties[$key]);

        return true;
    }

    /** @return list<string> Keys in insertion order. */
    /** @return list<string> */
    public function keys(): array
    {
        return array_map('strval', array_keys($this->properties));
    }

    /** @return list<string> Only enumerable keys, in insertion order. */
    public function enumerableKeys(): array
    {
        $result = [];
        foreach ($this->properties as $key => $desc) {
            if ($desc->enumerable === true) {
                $result[] = (string) $key;
            }
        }

        return $result;
    }

    public function count(): int
    {
        return count($this->properties);
    }
}
