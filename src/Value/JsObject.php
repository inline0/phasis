<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\Object\PropertyDescriptor;
use PhpJs\Object\PropertyMap;

class JsObject implements JsValue
{
    protected PropertyMap $properties;
    private ?JsObject $prototype;
    private static ?JsObject $globalPrototype = null;
    private bool $extensible = true;

    /** @var array<int, PropertyDescriptor> Symbol-keyed properties, indexed by JsSymbol id. */
    protected array $symbolProperties = [];

    public static function setGlobalPrototype(JsObject $proto): void
    {
        self::$globalPrototype = $proto;
    }

    public function __construct(?JsObject $prototype = null)
    {
        $this->properties = new PropertyMap();
        $this->prototype = $prototype ?? self::$globalPrototype;
    }

    public function isExtensible(): bool
    {
        return $this->extensible;
    }

    public function preventExtensions(): void
    {
        $this->extensible = false;
    }

    public function get(string $name): JsValue
    {
        return $this->getWithReceiver($name, $this);
    }

    /**
     * Internal property lookup that preserves the original receiver.
     *
     * When a getter accessor is found on a prototype, it must be called
     * with the original receiver (the object property access started on),
     * not the prototype where the descriptor lives.
     */
    protected function getWithReceiver(string $name, JsObject $receiver): JsValue
    {
        $desc = $this->properties->get($name);
        if ($desc !== null) {
            if ($desc->get !== null) {
                return $desc->get->call($receiver, []);
            }

            return $desc->value ?? JsUndefined::instance();
        }

        if ($this->prototype !== null) {
            return $this->prototype->getWithReceiver($name, $receiver);
        }

        return JsUndefined::instance();
    }

    public function set(string $name, JsValue $value, bool $strict = false): void
    {
        $desc = $this->properties->get($name);
        if ($desc !== null) {
            if ($desc->set !== null) {
                $desc->set->call($this, [$value]);
                return;
            }

            // Accessor descriptor without a setter: reject the assignment.
            if ($desc->get !== null) {
                if ($strict) {
                    throw new \PhpJs\Exceptions\TypeError(
                        "Cannot set property {$name} of #<Object> which has only a getter"
                    );
                }
                return;
            }

            if ($desc->writable === false) {
                if ($strict) {
                    throw new \PhpJs\Exceptions\TypeError(
                        "Cannot assign to read only property '{$name}' of object '#<Object>'"
                    );
                }
                return;
            }

            $desc->value = $value;
            return;
        }

        // Check prototype chain for non-writable data property or accessor without setter.
        $proto = $this->prototype;
        while ($proto !== null) {
            $protoDesc = $proto->properties->get($name);
            if ($protoDesc !== null) {
                if ($protoDesc->set !== null) {
                    // Inherited setter: invoke it with this object as receiver.
                    $protoDesc->set->call($this, [$value]);
                    return;
                }
                if ($protoDesc->get !== null && $protoDesc->set === null) {
                    // Inherited accessor with getter only (no setter).
                    if ($strict) {
                        throw new \PhpJs\Exceptions\TypeError(
                            "Cannot set property {$name} of #<Object> which has only a getter"
                        );
                    }
                    return;
                }
                if ($protoDesc->writable === false) {
                    if ($strict) {
                        throw new \PhpJs\Exceptions\TypeError(
                            "Cannot assign to read only property '{$name}' of object '#<Object>'"
                        );
                    }
                    return;
                }
                break;
            }
            $proto = $proto->prototype;
        }

        // If the object is not extensible, reject adding new properties.
        if (!$this->extensible) {
            if ($strict) {
                throw new \PhpJs\Exceptions\TypeError(
                    "Cannot add property {$name}, object is not extensible"
                );
            }
            return;
        }

        $this->properties->set($name, PropertyDescriptor::data($value));
    }

    /** Get a property value by symbol key. */
    public function getBySymbol(JsSymbol $symbol): JsValue
    {
        return $this->getBySymbolWithReceiver($symbol, $this);
    }

    /**
     * Internal symbol property lookup that preserves the original receiver.
     */
    protected function getBySymbolWithReceiver(JsSymbol $symbol, JsObject $receiver): JsValue
    {
        $id = $symbol->getId();
        if (isset($this->symbolProperties[$id])) {
            $desc = $this->symbolProperties[$id];
            if ($desc->get !== null) {
                return $desc->get->call($receiver, []);
            }
            return $desc->value ?? JsUndefined::instance();
        }

        if ($this->prototype !== null) {
            return $this->prototype->getBySymbolWithReceiver($symbol, $receiver);
        }

        return JsUndefined::instance();
    }

    /** Set a property value by symbol key. */
    public function setBySymbol(JsSymbol $symbol, JsValue $value): void
    {
        $id = $symbol->getId();
        if (isset($this->symbolProperties[$id])) {
            $desc = $this->symbolProperties[$id];
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

        $this->symbolProperties[$id] = PropertyDescriptor::data($value);
    }

    /** Check if the object has a symbol-keyed property. */
    public function hasBySymbol(JsSymbol $symbol): bool
    {
        if (isset($this->symbolProperties[$symbol->getId()])) {
            return true;
        }

        if ($this->prototype !== null) {
            return $this->prototype->hasBySymbol($symbol);
        }

        return false;
    }

    /** Define a property descriptor by symbol key. */
    public function definePropertyBySymbol(JsSymbol $symbol, PropertyDescriptor $desc): void
    {
        $this->symbolProperties[$symbol->getId()] = $desc;
    }

    /** Get own property descriptor by symbol key (does not walk prototype chain). */
    public function getSymbolPropertyDescriptor(JsSymbol $symbol): ?PropertyDescriptor
    {
        return $this->symbolProperties[$symbol->getId()] ?? null;
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

    public function delete(string $name, bool $strict = false): bool
    {
        $desc = $this->properties->get($name);
        if ($desc === null) {
            return true;
        }

        if ($desc->configurable === false) {
            if ($strict) {
                throw new \PhpJs\Exceptions\TypeError(
                    "Cannot delete property '{$name}' of #<Object>"
                );
            }
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
    /** @return list<string> Own enumerable keys. */
    public function getOwnEnumerableKeys(): array
    {
        return $this->properties->enumerableKeys();
    }

    /**
     * @return list<string> All enumerable keys including inherited.
     * Used by for-in. Walks prototype chain.
     */
    public function getEnumerableKeys(): array
    {
        $seen = [];
        $keys = [];
        $obj = $this;
        while ($obj !== null) {
            foreach ($obj->properties->enumerableKeys() as $key) {
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $keys[] = $key;
                }
            }
            $obj = $obj->prototype;
        }
        return $keys;
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
