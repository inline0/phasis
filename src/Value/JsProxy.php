<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\Exceptions\TypeError;
use PhpJs\Object\PropertyDescriptor;

/**
 * JavaScript Proxy object.
 *
 * Wraps a target object and a handler object. All fundamental
 * property operations check the handler for a corresponding trap
 * first, falling back to the target when no trap is defined.
 */
class JsProxy extends JsObject
{
    private ?JsObject $target;
    private ?JsObject $handler;

    public function __construct(JsObject $target, JsObject $handler)
    {
        parent::__construct($target->getPrototype());
        $this->target = $target;
        $this->handler = $handler;
    }

    /** Check whether this proxy has been revoked. */
    public function isRevoked(): bool
    {
        return $this->target === null;
    }

    /** Revoke this proxy so all further operations throw TypeError. */
    public function revoke(): void
    {
        $this->target = null;
        $this->handler = null;
    }

    /** Get the underlying target object (for Reflect operations). */
    public function getTarget(): JsObject
    {
        $this->assertNotRevoked();
        return $this->target;
    }

    /** Get the handler object. */
    public function getHandler(): JsObject
    {
        $this->assertNotRevoked();
        return $this->handler;
    }

    private function assertNotRevoked(): void
    {
        if ($this->target === null || $this->handler === null) {
            throw new TypeError('Cannot perform \'get\' on a proxy that has been revoked');
        }
    }

    /**
     * Try to get a trap function from the handler.
     * Returns null if the handler does not have the named trap.
     */
    private function getTrap(string $trapName): ?JsFunction
    {
        $this->assertNotRevoked();
        $trap = $this->handler->get($trapName);
        if ($trap instanceof JsFunction) {
            return $trap;
        }
        if ($trap instanceof JsUndefined || $trap instanceof \PhpJs\Value\JsNull) {
            return null;
        }
        return null;
    }

    // -- [[Get]] --

    public function get(string $name): JsValue
    {
        $trap = $this->getTrap('get');
        if ($trap !== null) {
            return $trap->call($this->handler, [$this->target, new JsString($name), $this]);
        }
        return $this->target->get($name);
    }

    // -- [[Set]] --

    public function set(string $name, JsValue $value, bool $strict = false): void
    {
        $trap = $this->getTrap('set');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target, new JsString($name), $value, $this]);
            if (!\PhpJs\Spec\TypeConversion::toBoolean($result)) {
                if ($strict) {
                    throw new TypeError("'set' on proxy: trap returned falsish for property '{$name}'");
                }
                return;
            }
            return;
        }
        $this->target->set($name, $value, $strict);
    }

    // -- [[Has]] --

    public function has(string $name): bool
    {
        $trap = $this->getTrap('has');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target, new JsString($name)]);
            return \PhpJs\Spec\TypeConversion::toBoolean($result);
        }
        return $this->target->has($name);
    }

    // -- [[Delete]] --

    public function delete(string $name, bool $strict = false): bool
    {
        $trap = $this->getTrap('deleteProperty');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target, new JsString($name)]);
            return \PhpJs\Spec\TypeConversion::toBoolean($result);
        }
        return $this->target->delete($name, $strict);
    }

    // -- [[OwnPropertyKeys]] --

    public function ownKeys(): array
    {
        $trap = $this->getTrap('ownKeys');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target]);
            return $this->trapResultToStringArray($result);
        }
        return $this->target->ownKeys();
    }

    public function getOwnPropertyNames(): array
    {
        return $this->ownKeys();
    }

    public function getOwnEnumerableKeys(): array
    {
        $keys = $this->ownKeys();
        // Filter to enumerable keys only.
        $result = [];
        foreach ($keys as $key) {
            $desc = $this->getOwnPropertyDescriptor($key);
            if ($desc !== null && $desc->enumerable) {
                $result[] = $key;
            }
        }
        return $result;
    }

    public function getEnumerableKeys(): array
    {
        // Proxy ownKeys controls the full enumerable list.
        return $this->getOwnEnumerableKeys();
    }

    // -- [[GetPrototypeOf]] --

    public function getPrototype(): ?JsObject
    {
        $trap = $this->getTrap('getPrototypeOf');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target]);
            if ($result instanceof JsNull) {
                return null;
            }
            if ($result instanceof JsObject) {
                return $result;
            }
            throw new TypeError('\'getPrototypeOf\' on proxy: trap returned neither object nor null');
        }
        return $this->target->getPrototype();
    }

    // -- [[SetPrototypeOf]] --

    public function setPrototype(?JsObject $prototype): void
    {
        $trap = $this->getTrap('setPrototypeOf');
        if ($trap !== null) {
            $protoArg = $prototype ?? \PhpJs\Value\JsNull::instance();
            $result = $trap->call($this->handler, [$this->target, $protoArg]);
            if (!\PhpJs\Spec\TypeConversion::toBoolean($result)) {
                throw new TypeError('\'setPrototypeOf\' on proxy: trap returned falsish');
            }
            return;
        }
        $this->target->setPrototype($prototype);
    }

    // -- [[DefineOwnProperty]] --

    public function defineOwnProperty(string $name, PropertyDescriptor $desc): void
    {
        $trap = $this->getTrap('defineProperty');
        if ($trap !== null) {
            $descObj = self::descriptorToObject($desc);
            $result = $trap->call($this->handler, [$this->target, new JsString($name), $descObj]);
            if (!\PhpJs\Spec\TypeConversion::toBoolean($result)) {
                throw new TypeError("'defineProperty' on proxy: trap returned falsish for property '{$name}'");
            }
            return;
        }
        $this->target->defineOwnProperty($name, $desc);
    }

    public function defineProperty(string $name, PropertyDescriptor $desc): void
    {
        $this->defineOwnProperty($name, $desc);
    }

    // -- [[GetOwnProperty]] --

    public function getOwnPropertyDescriptor(string $name): ?PropertyDescriptor
    {
        $trap = $this->getTrap('getOwnPropertyDescriptor');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target, new JsString($name)]);
            if ($result instanceof JsUndefined) {
                return null;
            }
            if ($result instanceof JsObject) {
                return self::objectToDescriptor($result);
            }
            return null;
        }
        return $this->target->getOwnPropertyDescriptor($name);
    }

    // -- [[IsExtensible]] --

    public function isExtensible(): bool
    {
        $trap = $this->getTrap('isExtensible');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target]);
            return \PhpJs\Spec\TypeConversion::toBoolean($result);
        }
        return $this->target->isExtensible();
    }

    // -- [[PreventExtensions]] --

    public function preventExtensions(): void
    {
        $trap = $this->getTrap('preventExtensions');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target]);
            if (!\PhpJs\Spec\TypeConversion::toBoolean($result)) {
                throw new TypeError('\'preventExtensions\' on proxy: trap returned falsish');
            }
            return;
        }
        $this->target->preventExtensions();
    }

    // -- hasOwnProperty --

    public function hasOwnProperty(string $name): bool
    {
        $desc = $this->getOwnPropertyDescriptor($name);
        return $desc !== null;
    }

    // -- Symbol-keyed access delegates to target --

    public function getBySymbol(JsSymbol $symbol): JsValue
    {
        $this->assertNotRevoked();
        // Check for get trap using the symbol's description as a key proxy.
        $trap = $this->getTrap('get');
        if ($trap !== null) {
            return $trap->call($this->handler, [$this->target, $symbol, $this]);
        }
        return $this->target->getBySymbol($symbol);
    }

    public function setBySymbol(JsSymbol $symbol, JsValue $value): void
    {
        $this->assertNotRevoked();
        $trap = $this->getTrap('set');
        if ($trap !== null) {
            $trap->call($this->handler, [$this->target, $symbol, $value, $this]);
            return;
        }
        $this->target->setBySymbol($symbol, $value);
    }

    public function hasBySymbol(JsSymbol $symbol): bool
    {
        $this->assertNotRevoked();
        $trap = $this->getTrap('has');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target, $symbol]);
            return \PhpJs\Spec\TypeConversion::toBoolean($result);
        }
        return $this->target->hasBySymbol($symbol);
    }

    // -- typeof --

    public function typeof(): string
    {
        $this->assertNotRevoked();
        if ($this->target instanceof JsFunction) {
            return 'function';
        }
        return 'object';
    }

    // -- Conversion --

    public function toJsString(): string
    {
        $this->assertNotRevoked();
        return $this->target->toJsString();
    }

    public function display(): string
    {
        if ($this->isRevoked()) {
            return '[revoked Proxy]';
        }
        return $this->target->display();
    }

    // -- apply trap (for function proxies) --

    /**
     * Invoke the apply trap if the target is callable.
     * Called by the interpreter when a proxy is invoked as a function.
     *
     * @param list<JsValue> $args
     */
    public function apply(JsValue $thisArg, array $args): JsValue
    {
        $this->assertNotRevoked();
        $trap = $this->getTrap('apply');
        if ($trap !== null) {
            $argsArray = JsArray::fromArray($args);
            return $trap->call($this->handler, [$this->target, $thisArg, $argsArray]);
        }
        if ($this->target instanceof JsFunction) {
            return $this->target->call($thisArg, $args);
        }
        throw new TypeError('proxy target is not a function');
    }

    /**
     * Invoke the construct trap.
     * Called by the interpreter when a proxy is used with new.
     *
     * @param list<JsValue> $args
     */
    public function construct(array $args, ?JsValue $newTarget = null): JsValue
    {
        $this->assertNotRevoked();
        $trap = $this->getTrap('construct');
        if ($trap !== null) {
            $argsArray = JsArray::fromArray($args);
            $nt = $newTarget ?? $this;
            $result = $trap->call($this->handler, [$this->target, $argsArray, $nt]);
            if (!$result instanceof JsObject) {
                throw new TypeError('\'construct\' on proxy: trap returned non-object');
            }
            return $result;
        }
        if ($this->target instanceof JsFunction && $this->target->isConstructable()) {
            // Delegate to the interpreter's new expression handling.
            // The target is the actual constructor, so we create a new object with its prototype.
            $proto = $this->target->get('prototype');
            $newObj = new JsObject($proto instanceof JsObject ? $proto : null);
            $result = $this->target->call($newObj, $args);
            return $result instanceof JsObject ? $result : $newObj;
        }
        throw new TypeError('proxy target is not a constructor');
    }

    // -- Helpers --

    /**
     * Convert a trap result (JsArray or JsObject with numeric keys) to a string array.
     *
     * @return list<string>
     */
    private function trapResultToStringArray(JsValue $result): array
    {
        if (!$result instanceof JsObject) {
            throw new TypeError('\'ownKeys\' on proxy: trap returned non-object');
        }
        $keys = [];
        if ($result instanceof JsArray) {
            $len = $result->getLength();
            for ($i = 0; $i < $len; $i++) {
                $elem = $result->get((string) $i);
                $keys[] = \PhpJs\Spec\TypeConversion::toString($elem);
            }
        } else {
            foreach ($result->getOwnPropertyNames() as $k) {
                $elem = $result->get($k);
                $keys[] = \PhpJs\Spec\TypeConversion::toString($elem);
            }
        }
        return $keys;
    }

    /** Convert a PropertyDescriptor to a JsObject for passing to traps. */
    private static function descriptorToObject(PropertyDescriptor $desc): JsObject
    {
        $obj = new JsObject();
        if ($desc->isAccessorDescriptor()) {
            $obj->set('get', $desc->get ?? JsUndefined::instance());
            $obj->set('set', $desc->set ?? JsUndefined::instance());
        } else {
            if ($desc->value !== null) {
                $obj->set('value', $desc->value);
            }
            if ($desc->writable !== null) {
                $obj->set('writable', new JsBoolean($desc->writable));
            }
        }
        if ($desc->enumerable !== null) {
            $obj->set('enumerable', new JsBoolean($desc->enumerable));
        }
        if ($desc->configurable !== null) {
            $obj->set('configurable', new JsBoolean($desc->configurable));
        }
        return $obj;
    }

    /** Convert a JsObject descriptor from a trap result to a PropertyDescriptor. */
    private static function objectToDescriptor(JsObject $obj): PropertyDescriptor
    {
        $value = $obj->has('value') ? $obj->get('value') : null;
        $writable = $obj->has('writable')
            ? \PhpJs\Spec\TypeConversion::toBoolean($obj->get('writable'))
            : null;
        $enumerable = $obj->has('enumerable')
            ? \PhpJs\Spec\TypeConversion::toBoolean($obj->get('enumerable'))
            : null;
        $configurable = $obj->has('configurable')
            ? \PhpJs\Spec\TypeConversion::toBoolean($obj->get('configurable'))
            : null;
        $getter = null;
        $setter = null;
        if ($obj->has('get')) {
            $g = $obj->get('get');
            if ($g instanceof JsFunction) {
                $getter = $g;
            }
        }
        if ($obj->has('set')) {
            $s = $obj->get('set');
            if ($s instanceof JsFunction) {
                $setter = $s;
            }
        }

        if ($getter !== null || $setter !== null) {
            return PropertyDescriptor::accessor(
                get: $getter,
                set: $setter,
                enumerable: $enumerable ?? false,
                configurable: $configurable ?? false,
            );
        }

        return new PropertyDescriptor(
            value: $value,
            writable: $writable,
            enumerable: $enumerable,
            configurable: $configurable,
        );
    }
}
