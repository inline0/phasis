<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\Object\PropertyDescriptor;
use PhpJs\Object\PropertyMap;

class JsObject implements JsValue
{
    protected PropertyMap $properties;
    private ?JsObject $prototype;

    public function __construct(?JsObject $prototype = null)
    {
        $this->properties = new PropertyMap();
        $this->prototype = $prototype;
    }

    public function get(string $name): JsValue
    {
        $desc = $this->properties->get($name);
        if ($desc !== null) {
            if ($desc->get !== null) {
                return $desc->get->call($this, []);
            }

            return $desc->value ?? JsUndefined::instance();
        }

        if ($this->prototype !== null) {
            return $this->prototype->get($name);
        }

        return JsUndefined::instance();
    }

    public function set(string $name, JsValue $value): void
    {
        $desc = $this->properties->get($name);
        if ($desc !== null) {
            if ($desc->set !== null) {
                $desc->set->call($this, [$value]);
                return;
            }

            if ($desc->writable === false) {
                return;
            }

            $desc->value = $value;
            return;
        }

        $this->properties->set($name, PropertyDescriptor::data($value));
    }

    public function has(string $name): bool
    {
        if ($this->properties->has($name)) {
            return true;
        }

        if ($this->prototype !== null) {
            return $this->prototype->has($name);
        }

        return false;
    }

    public function delete(string $name): bool
    {
        $desc = $this->properties->get($name);
        if ($desc === null) {
            return true;
        }

        if ($desc->configurable === false) {
            return false;
        }

        return $this->properties->delete($name);
    }

    public function defineProperty(string $name, PropertyDescriptor $desc): void
    {
        $this->properties->set($name, $desc);
    }

    public function getOwnPropertyDescriptor(string $name): ?PropertyDescriptor
    {
        return $this->properties->get($name);
    }

    public function hasOwnProperty(string $name): bool
    {
        return $this->properties->has($name);
    }

    /** @return list<string> */
    public function ownKeys(): array
    {
        return $this->properties->keys();
    }

    /** @return list<string> */
    public function getOwnPropertyNames(): array
    {
        return $this->properties->keys();
    }

    /** @return list<string> */
    public function getEnumerableKeys(): array
    {
        return $this->properties->enumerableKeys();
    }

    /**
     * Alias for defineProperty, matching the spec name OrdinaryDefineOwnProperty.
     */
    public function defineOwnProperty(string $name, PropertyDescriptor $desc): void
    {
        $this->properties->set($name, $desc);
    }

    public function getPrototype(): ?JsObject
    {
        return $this->prototype;
    }

    public function setPrototype(?JsObject $prototype): void
    {
        $this->prototype = $prototype;
    }

    public function getProperties(): PropertyMap
    {
        return $this->properties;
    }

    public function typeof(): string
    {
        return 'object';
    }

    public function toBoolean(): bool
    {
        return true;
    }

    public function toNumber(): float
    {
        return NAN;
    }

    public function toInt32(): int
    {
        return 0;
    }

    public function toUint32(): int
    {
        return 0;
    }

    public function toJsString(): string
    {
        return '[object Object]';
    }

    public function display(): string
    {
        return '[object Object]';
    }
}
