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

    /** Captured at creation time per spec ProxyCreate step 7. */
    private bool $targetIsCallable;

    /** Captured at creation time: whether target has [[Construct]]. */
    private bool $targetIsConstructable;

    public function __construct(JsObject $target, JsObject $handler)
    {
        // Per spec ProxyCreate: the proxy does not read the target's [[Prototype]]
        // at construction time. It always delegates via its getPrototype() override.
        // Pass null to avoid triggering getPrototypeOf traps on proxy targets.
        parent::__construct(null);
        $this->target = $target;
        $this->handler = $handler;
        // Per spec ProxyCreate steps 6-7: capture [[Call]] and [[Construct]] existence.
        $this->targetIsCallable = $this->determineCallable($target);
        $this->targetIsConstructable = $this->determineConstructable($target);
    }

    /** Determine if a target has a [[Call]] internal slot. */
    private function determineCallable(JsObject $target): bool
    {
        if ($target instanceof JsFunction) {
            return true;
        }
        if ($target instanceof self) {
            return $target->isCallable();
        }
        return false;
    }

    /** Determine if a target has a [[Construct]] internal slot. */
    private function determineConstructable(JsObject $target): bool
    {
        if ($target instanceof JsFunction) {
            return $target->isConstructable();
        }
        if ($target instanceof self) {
            return $target->isConstructable();
        }
        return false;
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
        $this->assertNotRevoked('get');
        return $this->target;
    }

    /** Get the handler object. */
    public function getHandler(): JsObject
    {
        $this->assertNotRevoked('get');
        return $this->handler;
    }

    /** Whether the proxy's target has a [[Call]] slot (captured at creation time). */
    public function isCallable(): bool
    {
        return $this->targetIsCallable;
    }

    /** Whether the proxy's target has a [[Construct]] slot (captured at creation time). */
    public function isConstructable(): bool
    {
        return $this->targetIsConstructable;
    }

    private function assertNotRevoked(string $trap = 'get'): void
    {
        if ($this->target === null || $this->handler === null) {
            throw new TypeError("Cannot perform '{$trap}' on a proxy that has been revoked");
        }
    }

    /**
     * Format a symbol description for error messages.
     *
     * Per SpiderMonkey error message conventions, a symbol property name in a
     * proxy invariant TypeError is rendered as 'Symbol("desc")' (single quotes
     * around the entire form). When the symbol's description is null we emit
     * 'Symbol()'.
     */
    private static function formatSymbolForError(JsSymbol $sym): string
    {
        $desc = $sym->description;
        if ($desc === null) {
            return "'Symbol()'";
        }
        return "'Symbol(\"{$desc}\")'";
    }

    /**
     * Format a property key (JsString name, raw PHP string, or JsSymbol) for
     * inclusion in a proxy invariant TypeError message. Strings are wrapped in
     * double quotes; symbols pass through formatSymbolForError().
     */
    private static function formatKeyForError(string|JsSymbol $name): string
    {
        if ($name instanceof JsSymbol) {
            return self::formatSymbolForError($name);
        }
        return "\"{$name}\"";
    }

    /**
     * Try to get a trap function from the handler.
     * Returns null if the handler does not have the named trap.
     * Throws TypeError if the trap value exists but is not callable (per GetMethod spec).
     */
    private function getTrap(string $trapName): ?JsFunction
    {
        $this->assertNotRevoked($trapName);
        $trap = $this->handler->get($trapName);
        if ($trap instanceof JsFunction) {
            return $trap;
        }
        // A callable Proxy (target is callable) is itself callable per spec
        // IsCallable; GetMethod(handler, trapName) accepts it. Wrap the
        // proxy in a JsFunction that delegates to its [[Call]] internal
        // method via apply() so trap call sites can continue to use
        // ->call(thisArg, args) uniformly.
        if ($trap instanceof JsProxy && $trap->isCallable()) {
            $proxyTrap = $trap;
            return JsFunction::fromCallable(
                $trapName,
                static function (JsValue $thisArg, array $args) use ($proxyTrap): JsValue {
                    return $proxyTrap->apply($thisArg, $args);
                },
            );
        }
        if ($trap instanceof JsUndefined || $trap instanceof JsNull) {
            return null;
        }
        // Per spec 7.3.9 GetMethod step 5: if IsCallable(func) is false, throw TypeError.
        throw new TypeError("'{$trapName}' on proxy: trap is not a function: " . $trap->typeof());
    }

    // -- [[Get]] --

    public function get(string $name): JsValue
    {
        return $this->internalGet($name, $this);
    }

    /**
     * Override getWithReceiver so prototype chain lookups through a proxy
     * go through the proxy's [[Get]] trap rather than the JsObject property map.
     */
    protected function getWithReceiver(string $name, JsObject $receiver): JsValue
    {
        return $this->internalGet($name, $receiver);
    }

    /**
     * Override getWithValueReceiver so prototype chain walks through a proxy
     * (e.g. Object.create(proxy).prop) go through the [[Get]] trap. When the
     * receiver is a primitive (e.g. Reflect.get(proxy, "x", 37.2)), pass it
     * through to the trap as-is per spec [[Get]] semantics.
     */
    public function getWithValueReceiver(string $name, JsValue $receiver): JsValue
    {
        $this->assertNotRevoked('get');
        $trap = $this->getTrap('get');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target, new JsString($name), $receiver]);
            $this->validateGetInvariants($name, $result);
            return $result;
        }
        // No trap: forward to target.
        if ($this->target instanceof JsProxy) {
            return $this->target->getWithValueReceiver($name, $receiver);
        }
        return $this->target->getWithValueReceiver($name, $receiver);
    }

    /**
     * ES spec: [[Get]] ( P, Receiver ) for Proxy objects.
     *
     * Forwards the receiver correctly for prototype chain lookups
     * and validates invariants when a trap is present.
     */
    public function internalGet(string $name, JsObject $receiver): JsValue
    {
        // Internal slots bypass proxy traps but must still check revocation.
        if (self::isInternalSlot($name)) {
            $this->assertNotRevoked('get');
            return $this->target->internalGet($name, $this->target);
        }
        $target = $this->target;
        $handler = $this->handler;
        $trap = $this->getTrap('get');
        if ($trap !== null) {
            $result = $trap->call($handler, [$target, new JsString($name), $receiver]);
            // Validate invariants per spec 10.5.8 steps 10-11.
            $this->validateGetInvariants($name, $result, $target);
            return $result;
        }
        // No trap: forward to target.[[Get]](P, Receiver).
        $result = $target->internalGet($name, $receiver);
        // For callable proxies, return proxy-aware wrappers for call/apply/bind
        // so that p.call(...), p.apply(...), p.bind(...) invoke the proxy's
        // [[Call]] internal method correctly. This is needed because
        // JsFunction::get() provides call/apply/bind but internalGet() does not.
        if ($this->isCallable()) {
            $proxy = $receiver instanceof JsProxy ? $receiver : $this;
            if ($name === 'call') {
                return $this->getProxyCallMethod($proxy);
            }
            if ($name === 'apply') {
                return $this->getProxyApplyMethod($proxy);
            }
            if ($name === 'bind') {
                return $this->getProxyBindMethod($proxy);
            }
        }
        return $result;
    }

    /** Return a Function.prototype.call equivalent that invokes the proxy's apply trap. */
    private function getProxyCallMethod(JsProxy $proxy): JsFunction
    {
        return JsFunction::fromCallable('call', function (JsValue $this_, array $args) use ($proxy): JsValue {
            $thisArg = $args[0] ?? JsUndefined::instance();
            $callArgs = array_slice($args, 1);
            return $proxy->apply($thisArg, $callArgs);
        });
    }

    /** Return a Function.prototype.apply equivalent that invokes the proxy's apply trap. */
    private function getProxyApplyMethod(JsProxy $proxy): JsFunction
    {
        return JsFunction::fromCallable('apply', function (JsValue $this_, array $args) use ($proxy): JsValue {
            $thisArg = $args[0] ?? JsUndefined::instance();
            $argsArray = $args[1] ?? JsUndefined::instance();
            $callArgs = [];
            if ($argsArray instanceof JsArray) {
                for ($i = 0; $i < $argsArray->getLength(); $i++) {
                    $callArgs[] = $argsArray->get((string) $i);
                }
            } elseif ($argsArray instanceof JsObject) {
                $len = (int) \PhpJs\Spec\TypeConversion::toNumber($argsArray->get('length'));
                for ($i = 0; $i < $len; $i++) {
                    $callArgs[] = $argsArray->get((string) $i);
                }
            }
            return $proxy->apply($thisArg, $callArgs);
        });
    }

    /** Return a Function.prototype.bind equivalent that captures the proxy. */
    private function getProxyBindMethod(JsProxy $proxy): JsFunction
    {
        return JsFunction::fromCallable('bind', function (JsValue $this_, array $args) use ($proxy): JsValue {
            $boundThis = $args[0] ?? JsUndefined::instance();
            $boundArgs = array_slice($args, 1);
            return JsFunction::fromCallable(
                'bound',
                function (JsValue $this2_, array $callArgs) use ($proxy, $boundThis, $boundArgs): JsValue {
                    return $proxy->apply($boundThis, array_merge($boundArgs, $callArgs));
                },
            );
        });
    }

    /**
     * Validate get trap invariants per ES spec 10.5.8 steps 10-11.
     *
     * After the trap returns a value:
     * - If target property is non-configurable data with writable=false,
     *   trap result must be SameValue as the target's value.
     * - If target property is non-configurable accessor with get=undefined,
     *   trap result must be undefined.
     */
    private function validateGetInvariants(string|JsSymbol $name, JsValue $trapResult, ?JsObject $target = null): void
    {
        // A revoked proxy has a null target; the caller should have already
        // thrown TypeError before invoking a trap, but guard defensively so a
        // stale trap invocation doesn't crash the PHP runtime.
        $target ??= $this->target;
        if ($target === null) {
            return;
        }
        $targetDesc = $name instanceof JsSymbol
            ? $target->getSymbolPropertyDescriptor($name)
            : $target->getOwnPropertyDescriptor($name);
        if ($targetDesc === null) {
            return;
        }
        if ($targetDesc->configurable === false) {
            $key = self::formatKeyForError($name);
            if ($targetDesc->isDataDescriptor() && $targetDesc->writable === false) {
                $targetValue = $targetDesc->value ?? JsUndefined::instance();
                if (!$this->sameValue($trapResult, $targetValue)) {
                    throw new TypeError(
                        "'get' on proxy: property {$key} is a read-only and"
                        . " non-configurable data property on the proxy target"
                        . " but the proxy did not return its actual value"
                    );
                }
            }
            if ($targetDesc->isAccessorDescriptor() && $targetDesc->get === null) {
                if (!$trapResult instanceof JsUndefined) {
                    throw new TypeError(
                        "'get' on proxy: property {$key} is a non-configurable accessor"
                        . " property on the proxy target and does not have a getter function,"
                        . " but the trap did not return 'undefined'"
                    );
                }
            }
        }
    }

    // -- [[Set]] --

    public function set(string $name, JsValue $value, bool $strict = false): void
    {
        $success = $this->internalSet($name, $value, $this);
        if (!$success && $strict) {
            $key = self::formatKeyForError($name);
            throw new TypeError("'set' on proxy: trap returned falsish for property {$key}");
        }
    }

    /**
     * ES spec: [[Set]] ( P, V, Receiver ) for Proxy objects.
     *
     * Checks the handler for a "set" trap. When found, calls it with
     * (target, P, V, Receiver) and validates invariants. When missing,
     * forwards to target.[[Set]](P, V, Receiver).
     */
    public function internalSet(string $name, JsValue $value, JsObject $receiver): bool
    {
        return $this->setWithValueReceiver($name, $value, $receiver);
    }

    /**
     * Like internalSet but accepts any JsValue as receiver, so Reflect.set
     * can pass a primitive or undefined receiver through to the trap.
     */
    public function setWithValueReceiver(string $name, JsValue $value, JsValue $receiver): bool
    {
        $target = $this->target;
        $handler = $this->handler;
        $trap = $this->getTrap('set');
        if ($trap !== null) {
            $result = $trap->call($handler, [$target, new JsString($name), $value, $receiver]);
            $booleanTrapResult = \PhpJs\Spec\TypeConversion::toBoolean($result);
            if (!$booleanTrapResult) {
                return false;
            }
            // Validate invariants per spec step 14.
            $this->validateSetInvariants($name, $value, $target);
            return true;
        }
        // No trap: forward to target.[[Set]](P, V, Receiver). Target is
        // a JsObject which only accepts JsObject receivers; coerce the
        // (potentially primitive) receiver to itself by using the target
        // as the receiver when it's not an Object — the underlying spec
        // semantics for OrdinarySet then return false for primitive
        // receivers on data descriptors.
        if ($receiver instanceof JsObject) {
            return $target->internalSet($name, $value, $receiver);
        }
        return $target->internalSet($name, $value, $target);
    }

    /**
     * Validate set trap invariants per ES spec 10.5.9 step 14.
     *
     * After the trap returns true, if the target property is non-configurable:
     * - Data property with writable=false: value must be SameValue as V.
     * - Accessor property with set=undefined: must throw TypeError.
     */
    private function validateSetInvariants(string|JsSymbol $name, JsValue $value, ?JsObject $target = null): void
    {
        $target ??= $this->target;
        if ($target === null) {
            return;
        }
        $targetDesc = $name instanceof JsSymbol
            ? $target->getSymbolPropertyDescriptor($name)
            : $target->getOwnPropertyDescriptor($name);
        if ($targetDesc === null) {
            return;
        }
        if ($targetDesc->configurable === false) {
            $key = self::formatKeyForError($name);
            if ($targetDesc->isDataDescriptor() && $targetDesc->writable === false) {
                $targetValue = $targetDesc->value ?? JsUndefined::instance();
                if (!$this->sameValue($value, $targetValue)) {
                    throw new TypeError(
                        "'set' on proxy: trap returned truish for property"
                        . " {$key} which exists in the proxy target as a"
                        . " non-configurable and non-writable data property"
                        . " with a different value"
                    );
                }
            }
            if ($targetDesc->isAccessorDescriptor() && $targetDesc->set === null) {
                throw new TypeError(
                    "'set' on proxy: trap returned truish for property"
                    . " {$key} which exists in the proxy target as a"
                    . " non-configurable and non-writable accessor"
                    . " property without a setter"
                );
            }
        }
    }

    /** SameValue comparison per ES spec. */
    private function sameValue(JsValue $a, JsValue $b): bool
    {
        if ($a instanceof JsNumber && $b instanceof JsNumber) {
            $x = $a->toNumber();
            $y = $b->toNumber();
            if (is_nan($x) && is_nan($y)) {
                return true;
            }
            // Distinguish +0 and -0 via the IEEE 754 sign bit.
            if ($x === 0.0 && $y === 0.0) {
                $signX = (unpack('J', pack('E', $x))[1] ?? 0) >> 63;
                $signY = (unpack('J', pack('E', $y))[1] ?? 0) >> 63;
                return $signX === $signY;
            }
            return $x === $y;
        }
        if ($a instanceof JsString && $b instanceof JsString) {
            return $a->toJsString() === $b->toJsString();
        }
        if ($a instanceof JsBoolean && $b instanceof JsBoolean) {
            return $a->toBoolean() === $b->toBoolean();
        }
        if ($a instanceof JsUndefined && $b instanceof JsUndefined) {
            return true;
        }
        if ($a instanceof JsNull && $b instanceof JsNull) {
            return true;
        }
        // Object identity.
        return $a === $b;
    }

    // -- [[Has]] --

    public function has(string $name): bool
    {
        // Internal slots bypass proxy traps but must still check revocation.
        if (self::isInternalSlot($name)) {
            $this->assertNotRevoked('has');
            return $this->target->has($name);
        }
        $target = $this->target;
        $handler = $this->handler;
        $trap = $this->getTrap('has');
        if ($trap !== null) {
            $result = $trap->call($handler, [$target, new JsString($name)]);
            $booleanTrapResult = \PhpJs\Spec\TypeConversion::toBoolean($result);
            if (!$booleanTrapResult) {
                // Validate invariants per spec 10.5.7 step 11.
                $this->validateHasInvariants($name, $target);
            }
            return $booleanTrapResult;
        }
        return $target->has($name);
    }

    /**
     * Validate has trap invariants per ES spec 10.5.7 step 11.
     *
     * When the trap returns false:
     * - If property is non-configurable on target, throw TypeError.
     * - If target is not extensible and property exists on target, throw TypeError.
     */
    private function validateHasInvariants(string|JsSymbol $name, ?JsObject $target = null): void
    {
        $target ??= $this->target;
        if ($target === null) {
            return;
        }
        $targetDesc = $name instanceof JsSymbol
            ? $target->getSymbolPropertyDescriptor($name)
            : $target->getOwnPropertyDescriptor($name);
        if ($targetDesc !== null) {
            $key = self::formatKeyForError($name);
            if ($targetDesc->configurable === false) {
                throw new TypeError(
                    "'has' on proxy: property {$key} is a non-configurable own"
                    . " property of the proxy target but the has trap returned false"
                );
            }
            if (!$target->isExtensible()) {
                throw new TypeError(
                    "'has' on proxy: property {$key} is an own property of the"
                    . " non-extensible proxy target but the has trap returned false"
                );
            }
        }
    }

    // -- [[Delete]] --

    public function delete(string $name, bool $strict = false): bool
    {
        $target = $this->target;
        $handler = $this->handler;
        $trap = $this->getTrap('deleteProperty');
        if ($trap !== null) {
            $result = $trap->call($handler, [$target, new JsString($name)]);
            $booleanTrapResult = \PhpJs\Spec\TypeConversion::toBoolean($result);
            if ($booleanTrapResult) {
                // Validate invariants per spec 10.5.10 steps 13-15.
                $this->validateDeleteInvariants($name, $target);
            }
            if (!$booleanTrapResult && $strict) {
                $key = self::formatKeyForError($name);
                throw new TypeError("'deleteProperty' on proxy: property {$key} is non-configurable");
            }
            return $booleanTrapResult;
        }
        return $target->delete($name, $strict);
    }

    /**
     * Validate delete trap invariants per ES spec 10.5.10 steps 13-15.
     *
     * When the trap returns true:
     * - If target property is non-configurable, throw TypeError.
     * - If target property exists and target is not extensible, throw TypeError.
     */
    private function validateDeleteInvariants(string|JsSymbol $name, ?JsObject $target = null): void
    {
        $target ??= $this->target;
        if ($target === null) {
            return;
        }
        $targetDesc = $name instanceof JsSymbol
            ? $target->getSymbolPropertyDescriptor($name)
            : $target->getOwnPropertyDescriptor($name);
        if ($targetDesc !== null) {
            $key = self::formatKeyForError($name);
            if ($targetDesc->configurable === false) {
                throw new TypeError(
                    "'deleteProperty' on proxy: property {$key} is"
                    . " non-configurable and can't be deleted"
                );
            }
            if (!$target->isExtensible()) {
                throw new TypeError(
                    "'deleteProperty' on proxy: property {$key} exists on a"
                    . " non-extensible proxy target and can't be deleted"
                );
            }
        }
    }

    // -- [[OwnPropertyKeys]] --

    public function ownKeys(): array
    {
        $target = $this->target;
        $handler = $this->handler;
        $trap = $this->getTrap('ownKeys');
        if ($trap !== null) {
            $result = $trap->call($handler, [$target]);
            $trapResult = $this->trapResultToPropertyKeys($result);
            $this->validateOwnKeysInvariants($trapResult, $target);
            // Return string keys only (symbols handled via ordinaryOwnPropertyKeys).
            $stringKeys = [];
            foreach ($trapResult as $k) {
                if ($k instanceof JsString) {
                    $stringKeys[] = $k->value;
                }
            }
            return $stringKeys;
        }
        // Forward to target's [[OwnPropertyKeys]] in spec order.
        return $target->getOwnPropertyNames();
    }

    /**
     * Validate ownKeys trap invariants per ES spec 10.5.11 steps 17-25.
     *
     * @param list<JsValue> $trapResult List of JsString and JsSymbol values.
     */
    private function validateOwnKeysInvariants(array $trapResult, ?JsObject $target = null): void
    {
        // Step 17: Check for duplicate entries. We compare on a stable string
        // form: "S:..." for strings, "Y:<id>" for symbols (object identity).
        $seen = [];
        foreach ($trapResult as $k) {
            $tag = $k instanceof JsSymbol
                ? 'Y:' . $k->getId()
                : 'S:' . ($k instanceof JsString ? $k->value : \PhpJs\Spec\TypeConversion::toString($k));
            if (isset($seen[$tag])) {
                throw new TypeError("'ownKeys' on proxy: trap returned duplicate entries");
            }
            $seen[$tag] = true;
        }

        $target ??= $this->target;
        if ($target === null) {
            return;
        }

        $extensibleTarget = $target->isExtensible();
        $targetKeys = $target->ownKeys();

        // Find non-configurable string keys on target.
        $targetNonconfigurableKeys = [];
        $targetConfigurableKeys = [];
        foreach ($targetKeys as $key) {
            $desc = $target->getOwnPropertyDescriptor($key);
            if ($desc !== null && $desc->configurable === false) {
                $targetNonconfigurableKeys[] = $key;
            } else {
                $targetConfigurableKeys[] = $key;
            }
        }

        // Symbol keys on target.
        $targetSymbolPairs = $target->getOwnSymbolsWithDescriptors();
        $targetNonconfigurableSymbols = [];
        $targetConfigurableSymbols = [];
        foreach ($targetSymbolPairs as [$sym, $desc]) {
            if ($desc->configurable === false) {
                $targetNonconfigurableSymbols[] = $sym;
            } else {
                $targetConfigurableSymbols[] = $sym;
            }
        }

        // Build trap-result lookup sets for strings and symbols.
        $trapStringSet = [];
        $trapSymbolSet = [];
        foreach ($trapResult as $k) {
            if ($k instanceof JsString) {
                $trapStringSet[$k->value] = true;
            } elseif ($k instanceof JsSymbol) {
                $trapSymbolSet[$k->getId()] = true;
            }
        }

        // Step 21: All non-configurable target keys must appear in trap result.
        foreach ($targetNonconfigurableKeys as $key) {
            if (!isset($trapStringSet[$key])) {
                $formatted = self::formatKeyForError($key);
                throw new TypeError(
                    "'ownKeys' on proxy: trap result did not include {$formatted}"
                );
            }
        }
        foreach ($targetNonconfigurableSymbols as $sym) {
            if (!isset($trapSymbolSet[$sym->getId()])) {
                $formatted = self::formatKeyForError($sym);
                throw new TypeError(
                    "'ownKeys' on proxy: trap result did not include {$formatted}"
                );
            }
        }

        // Step 23: If target is non-extensible, all target keys must be in trap result
        // and no extra keys allowed.
        if (!$extensibleTarget) {
            foreach ($targetConfigurableKeys as $key) {
                if (!isset($trapStringSet[$key])) {
                    $formatted = self::formatKeyForError($key);
                    throw new TypeError(
                        "'ownKeys' on proxy: trap result did not include {$formatted}"
                    );
                }
            }
            foreach ($targetConfigurableSymbols as $sym) {
                if (!isset($trapSymbolSet[$sym->getId()])) {
                    $formatted = self::formatKeyForError($sym);
                    throw new TypeError(
                        "'ownKeys' on proxy: trap result did not include {$formatted}"
                    );
                }
            }
            $totalTargetKeys = count($targetKeys) + count($targetSymbolPairs);
            if (count($trapResult) !== $totalTargetKeys) {
                throw new TypeError(
                    "'ownKeys' on proxy: trap returned extra keys for non-extensible target"
                );
            }
        }
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

    /**
     * [[OwnPropertyKeys]] returning JsValue objects (strings and symbols).
     * Overrides JsObject::ordinaryOwnPropertyKeys to go through the ownKeys trap.
     */
    public function ordinaryOwnPropertyKeys(): array
    {
        $trap = $this->getTrap('ownKeys');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target]);
            $trapResult = $this->trapResultToPropertyKeys($result);
            $this->validateOwnKeysInvariants($trapResult);
            return $trapResult;
        }
        return $this->target->ordinaryOwnPropertyKeys();
    }

    // -- [[GetPrototypeOf]] --

    public function getPrototype(): ?JsObject
    {
        // Snapshot target/handler before trap dispatch: GetMethod can run
        // arbitrary code (e.g. another proxy's get trap) that revokes this
        // proxy mid-operation, after which $this->target is null.
        $target = $this->target;
        $handler = $this->handler;
        $trap = $this->getTrap('getPrototypeOf');
        if ($trap !== null) {
            $result = $trap->call($handler, [$target]);
            if ($result instanceof JsNull) {
                $handlerProto = null;
            } elseif ($result instanceof JsObject) {
                $handlerProto = $result;
            } else {
                throw new TypeError('\'getPrototypeOf\' on proxy: trap returned neither object nor null');
            }
            // Invariant: if target is not extensible, must return same as target proto.
            if (!$target->isExtensible()) {
                $targetProto = $target->getPrototype();
                if ($handlerProto !== $targetProto) {
                    throw new TypeError(
                        '\'getPrototypeOf\' on proxy: proxy target is non-extensible but the'
                        . ' trap did not return its actual prototype'
                    );
                }
            }
            return $handlerProto;
        }
        return $target->getPrototype();
    }

    // -- [[SetPrototypeOf]] --

    /**
     * Internal [[SetPrototypeOf]] returning bool, for Reflect.setPrototypeOf.
     */
    public function internalSetPrototypeOf(?JsObject $prototype): bool
    {
        $target = $this->target;
        $handler = $this->handler;
        $trap = $this->getTrap('setPrototypeOf');
        if ($trap !== null) {
            $protoArg = $prototype ?? JsNull::instance();
            $result = $trap->call($handler, [$target, $protoArg]);
            if (!\PhpJs\Spec\TypeConversion::toBoolean($result)) {
                return false;
            }
            // Per spec step 10-14: if target is extensible, return true.
            // Only check the invariant when target is NOT extensible.
            $extensibleTarget = $target->isExtensible();
            if ($extensibleTarget) {
                return true;
            }
            // Target is not extensible: the prototype must match target's current prototype.
            $targetProto = $target->getPrototype();
            if ($prototype !== $targetProto) {
                throw new TypeError(
                    '\'setPrototypeOf\' on proxy: trap returned truish for setting a'
                    . ' new prototype on a non-extensible proxy target'
                );
            }
            return true;
        }
        return $target->trySetPrototype($prototype);
    }

    public function setPrototype(?JsObject $prototype): void
    {
        if (!$this->internalSetPrototypeOf($prototype)) {
            throw new TypeError('\'setPrototypeOf\' on proxy: trap returned falsish');
        }
    }

    /**
     * Override trySetPrototype so Reflect.setPrototypeOf and
     * OrdinarySetPrototypeOf cycle detection work correctly.
     */
    public function trySetPrototype(?JsObject $prototype): bool
    {
        return $this->internalSetPrototypeOf($prototype);
    }

    // -- [[DefineOwnProperty]] --

    public function defineOwnProperty(string $name, PropertyDescriptor $desc): bool
    {
        $target = $this->target;
        $handler = $this->handler;
        $trap = $this->getTrap('defineProperty');
        if ($trap !== null) {
            $descObj = self::descriptorToObject($desc);
            $result = $trap->call($handler, [$target, new JsString($name), $descObj]);
            if (!\PhpJs\Spec\TypeConversion::toBoolean($result)) {
                return false;
            }
            // Per spec 10.5.6 steps 17-22: validate invariants.
            // Invariant violations throw TypeError (must propagate, not be caught).
            $this->validateDefinePropertyInvariants($name, $desc, $target);
            return true;
        }
        return $target->defineOwnProperty($name, $desc);
    }

    /**
     * Validate defineProperty invariants per spec 10.5.6 steps 17-22.
     *
     * Emits a SpiderMonkey-style detailed message for each invariant violation
     * so test262 / regress-1383630 can match on the specific cause via
     * `assertThrowsTypeErrorIncludes`. The detail strings mirror the SM
     * JSMSG_* table so cross-engine compat tests stay aligned.
     */
    private function validateDefinePropertyInvariants(
        string|JsSymbol $name,
        PropertyDescriptor $desc,
        ?JsObject $target = null,
    ): void {
        $target ??= $this->target;
        if ($target === null) {
            return;
        }
        $targetDesc = $name instanceof JsSymbol
            ? $target->getSymbolPropertyDescriptor($name)
            : $target->getOwnPropertyDescriptor($name);
        $extensibleTarget = $target->isExtensible();
        $key = self::formatKeyForError($name);

        // "settingConfigFalse" flag per spec step 16.
        $settingConfigFalse = $desc->configurable === false;

        // Step 19: If target property does not exist.
        if ($targetDesc === null) {
            if (!$extensibleTarget) {
                throw new TypeError(
                    "'defineProperty' on proxy: trap returned truish for adding"
                    . " property {$key}, but the proxy target is not extensible"
                );
            }
            if ($settingConfigFalse) {
                throw new TypeError(
                    "'defineProperty' on proxy: trap returned truish for"
                    . " defining non-configurable property {$key}"
                    . " on a target that does not have this property"
                );
            }
            return;
        }

        // From here, targetDesc !== null. Mirror the per-attribute checks in
        // ValidateAndApplyPropertyDescriptor so each violation surfaces a
        // specific message matching SpiderMonkey's JSMSG details.

        // Step 20b (DETAILS_CANT_REPORT_NC_AS_C): targetDesc non-configurable but
        // desc is configurable.
        if (
            ($targetDesc->configurable ?? false) === false
            && $desc->configurable === true
        ) {
            throw new TypeError(
                "'defineProperty' on proxy: trap returned truish for"
                . " property {$key}: proxy can't report an existing"
                . " non-configurable property as configurable"
            );
        }

        // Inverse: target configurable, desc forces non-configurable. The
        // spec only allows this when the target also exists as non-configurable.
        if (
            $settingConfigFalse
            && ($targetDesc->configurable ?? false) === true
        ) {
            throw new TypeError(
                "'defineProperty' on proxy: trap returned truish for"
                . " property {$key}: proxy can't define an existing"
                . " configurable property as non-configurable"
            );
        }

        // (DETAILS_ENUM_DIFFERENT): targetDesc non-configurable but desc has a
        // different enumerable.
        if (
            ($targetDesc->configurable ?? false) === false
            && $desc->enumerable !== null
            && $desc->enumerable !== ($targetDesc->enumerable ?? false)
        ) {
            throw new TypeError(
                "'defineProperty' on proxy: trap returned truish for"
                . " property {$key}: proxy can't report a different"
                . " 'enumerable' from target when target is not configurable"
            );
        }

        // (DETAILS_CURRENT_NC_DIFF_TYPE): targetDesc non-configurable but
        // descriptor type (data vs accessor) differs.
        if (
            ($targetDesc->configurable ?? false) === false
            && $targetDesc->isDataDescriptor() !== $desc->isDataDescriptor()
            && ($desc->isDataDescriptor() || $desc->isAccessorDescriptor())
        ) {
            throw new TypeError(
                "'defineProperty' on proxy: trap returned truish for"
                . " property {$key}: proxy can't report a different"
                . " descriptor type when target is not configurable"
            );
        }

        // Both data descriptors with targetDesc non-configurable.
        if (
            ($targetDesc->configurable ?? false) === false
            && $targetDesc->isDataDescriptor()
            && $desc->isDataDescriptor()
        ) {
            // (DETAILS_CANT_REPORT_NW_AS_W): targetDesc non-writable but desc
            // is writable.
            if (($targetDesc->writable ?? false) === false && $desc->writable === true) {
                throw new TypeError(
                    "'defineProperty' on proxy: trap returned truish for"
                    . " property {$key}: proxy can't report a"
                    . " non-configurable, non-writable property as writable"
                );
            }
            // SpiderMonkey also emits the W-as-NW form for readability when
            // the trap promotes a writable property to non-writable on a
            // non-configurable target. That's the legacy path covered below
            // (kept distinct from DETAILS_DIFFERENT_VALUE).
            if (($targetDesc->writable ?? false) === true && $desc->writable === false) {
                throw new TypeError(
                    "'defineProperty' on proxy: trap returned truish for"
                    . " property {$key}: proxy can't define a"
                    . " non-configurable non-writable property when the"
                    . " target's property is writable"
                );
            }
            // (DETAILS_DIFFERENT_VALUE): targetDesc non-writable, desc has
            // different value.
            if (($targetDesc->writable ?? false) === false && $desc->value !== null) {
                $curVal = $targetDesc->value ?? JsUndefined::instance();
                if (!$this->sameValue($desc->value, $curVal)) {
                    throw new TypeError(
                        "'defineProperty' on proxy: trap returned truish for"
                        . " property {$key}: proxy must report the same value"
                        . " for the non-writable, non-configurable property"
                    );
                }
            }
        }

        // Both accessor descriptors with targetDesc non-configurable.
        if (
            ($targetDesc->configurable ?? false) === false
            && $targetDesc->isAccessorDescriptor()
            && $desc->isAccessorDescriptor()
        ) {
            // (DETAILS_SETTERS_DIFFERENT)
            if ($desc->hasSet && $desc->set !== $targetDesc->set) {
                throw new TypeError(
                    "'defineProperty' on proxy: trap returned truish for"
                    . " property {$key}: proxy can't report different setters"
                    . " for a currently non-configurable property"
                );
            }
            // (DETAILS_GETTERS_DIFFERENT)
            if ($desc->hasGet && $desc->get !== $targetDesc->get) {
                throw new TypeError(
                    "'defineProperty' on proxy: trap returned truish for"
                    . " property {$key}: proxy can't report different getters"
                    . " for a currently non-configurable property"
                );
            }
        }
    }

    public function defineProperty(string $name, PropertyDescriptor $desc): void
    {
        if (!$this->defineOwnProperty($name, $desc)) {
            $key = self::formatKeyForError($name);
            throw new TypeError("'defineProperty' on proxy: trap returned falsish for property {$key}");
        }
    }

    /**
     * Symbol-keyed getOwnPropertyDescriptor that goes through the trap, with
     * the same invariant validation as the string-keyed path.
     */
    public function getSymbolPropertyDescriptor(JsSymbol $symbol): ?PropertyDescriptor
    {
        $target = $this->target;
        $handler = $this->handler;
        $trap = $this->getTrap('getOwnPropertyDescriptor');
        if ($trap !== null) {
            $result = $trap->call($handler, [$target, $symbol]);
            if ($result instanceof JsUndefined) {
                $this->validateGetOwnPropertyUndefinedInvariants($symbol, $target);
                return null;
            }
            if (!$result instanceof JsObject) {
                $key = self::formatKeyForError($symbol);
                throw new TypeError(
                    "'getOwnPropertyDescriptor' on proxy: trap returned"
                    . " neither Object nor undefined for property {$key}"
                );
            }
            $resultDesc = self::objectToDescriptor($result);
            $this->validateGetOwnPropertyInvariants($symbol, $resultDesc, $target);
            return $resultDesc;
        }
        return $target->getSymbolPropertyDescriptor($symbol);
    }

    /**
     * Symbol-keyed defineProperty that goes through the defineProperty trap,
     * including invariant validation.
     */
    public function definePropertyBySymbol(JsSymbol $symbol, PropertyDescriptor $desc): bool
    {
        $target = $this->target;
        $handler = $this->handler;
        $trap = $this->getTrap('defineProperty');
        if ($trap !== null) {
            $descObj = self::descriptorToObject($desc);
            $result = $trap->call($handler, [$target, $symbol, $descObj]);
            if (!\PhpJs\Spec\TypeConversion::toBoolean($result)) {
                return false;
            }
            $this->validateDefinePropertyInvariants($symbol, $desc, $target);
            return true;
        }
        return $target->definePropertyBySymbol($symbol, $desc);
    }

    // -- [[GetOwnProperty]] --

    public function getOwnPropertyDescriptor(string $name): ?PropertyDescriptor
    {
        // Internal slots (e.g. [[NewTarget]], [[PrimitiveValue]]) bypass proxy traps.
        // They are engine-internal markers, not observable JS properties.
        if (self::isInternalSlot($name)) {
            $this->assertNotRevoked('getOwnPropertyDescriptor');
            return $this->target->getOwnPropertyDescriptor($name);
        }
        $target = $this->target;
        $handler = $this->handler;
        $trap = $this->getTrap('getOwnPropertyDescriptor');
        if ($trap !== null) {
            $result = $trap->call($handler, [$target, new JsString($name)]);
            if ($result instanceof JsUndefined) {
                // Validate undefined result invariants per spec 10.5.5 step 14.
                $this->validateGetOwnPropertyUndefinedInvariants($name, $target);
                return null;
            }
            if (!$result instanceof JsObject) {
                // Step 11: result must be Object or Undefined.
                $key = self::formatKeyForError($name);
                throw new TypeError(
                    "'getOwnPropertyDescriptor' on proxy: trap returned"
                    . " neither Object nor undefined for property {$key}"
                );
            }
            $resultDesc = self::objectToDescriptor($result);
            // Validate result descriptor invariants per spec 10.5.5 steps 16-22.
            $this->validateGetOwnPropertyInvariants($name, $resultDesc, $target);
            return $resultDesc;
        }
        return $target->getOwnPropertyDescriptor($name);
    }

    /**
     * Validate getOwnPropertyDescriptor invariants when trap returns undefined.
     * Per spec 10.5.5 step 14.
     */
    private function validateGetOwnPropertyUndefinedInvariants(string|JsSymbol $name, ?JsObject $target = null): void
    {
        $target ??= $this->target;
        if ($target === null) {
            return;
        }
        $targetDesc = $name instanceof JsSymbol
            ? $target->getSymbolPropertyDescriptor($name)
            : $target->getOwnPropertyDescriptor($name);
        if ($targetDesc === null) {
            return;
        }
        $key = self::formatKeyForError($name);
        // 14b: non-configurable property cannot be reported as non-existent.
        if ($targetDesc->configurable === false) {
            throw new TypeError(
                "'getOwnPropertyDescriptor' on proxy: property {$key} is non-configurable"
                . " on the proxy target but the trap returned undefined"
            );
        }
        // 14e: if target is non-extensible, existing property cannot be reported as non-existent.
        if (!$target->isExtensible()) {
            throw new TypeError(
                "'getOwnPropertyDescriptor' on proxy: property {$key} exists on the"
                . " non-extensible proxy target but the trap returned undefined"
            );
        }
    }

    /**
     * Validate getOwnPropertyDescriptor invariants when trap returns a descriptor.
     * Per spec 10.5.5 steps 16-22.
     *
     * Emits SpiderMonkey-style detail messages for each specific invariant
     * violation so test262 / regress-1383630's
     * `assertThrowsTypeErrorIncludes` checks can match them.
     */
    private function validateGetOwnPropertyInvariants(
        string|JsSymbol $name,
        PropertyDescriptor $resultDesc,
        ?JsObject $target = null,
    ): void {
        $target ??= $this->target;
        if ($target === null) {
            return;
        }
        $targetDesc = $name instanceof JsSymbol
            ? $target->getSymbolPropertyDescriptor($name)
            : $target->getOwnPropertyDescriptor($name);
        $extensibleTarget = $target->isExtensible();
        $key = self::formatKeyForError($name);

        // (DETAILS_NOT_EXTENSIBLE): target non-extensible and target property
        // does not exist, but trap reported a descriptor for it.
        if (!$extensibleTarget && $targetDesc === null) {
            throw new TypeError(
                "'getOwnPropertyDescriptor' on proxy: trap reported descriptor for"
                . " property {$key}: proxy can't report an extensible object as"
                . " non-extensible"
            );
        }

        // (DETAILS_CANT_REPORT_NC_AS_C): target property is non-configurable but
        // trap reports it as configurable.
        if (
            $targetDesc !== null
            && ($targetDesc->configurable ?? false) === false
            && $resultDesc->configurable === true
        ) {
            throw new TypeError(
                "'getOwnPropertyDescriptor' on proxy: trap reported descriptor for"
                . " property {$key}: proxy can't report an existing"
                . " non-configurable property as configurable"
            );
        }

        // Inverse mismatch: result reported as non-configurable but the target
        // either has no own property or has it as configurable. The same
        // SpiderMonkey detail message ("non-configurable property as
        // configurable") covers both directions in the test corpus.
        if (
            $resultDesc->configurable === false
            && ($targetDesc === null || ($targetDesc->configurable ?? true) !== false)
        ) {
            throw new TypeError(
                "'getOwnPropertyDescriptor' on proxy: trap reported descriptor for"
                . " property {$key}: proxy can't report an existing"
                . " non-configurable property as configurable"
            );
        }

        // (DETAILS_ENUM_DIFFERENT): target property non-configurable but result
        // reports a different `enumerable`.
        if (
            $targetDesc !== null
            && ($targetDesc->configurable ?? false) === false
            && $resultDesc->enumerable !== null
            && $resultDesc->enumerable !== ($targetDesc->enumerable ?? false)
        ) {
            throw new TypeError(
                "'getOwnPropertyDescriptor' on proxy: trap reported descriptor for"
                . " property {$key}: proxy can't report a different 'enumerable'"
                . " from target when target is not configurable"
            );
        }

        // (DETAILS_CURRENT_NC_DIFF_TYPE): target property non-configurable but
        // descriptor type (data vs accessor) differs.
        if (
            $targetDesc !== null
            && ($targetDesc->configurable ?? false) === false
            && $targetDesc->isDataDescriptor() !== $resultDesc->isDataDescriptor()
            && ($resultDesc->isDataDescriptor() || $resultDesc->isAccessorDescriptor())
        ) {
            throw new TypeError(
                "'getOwnPropertyDescriptor' on proxy: trap reported descriptor for"
                . " property {$key}: proxy can't report a different descriptor"
                . " type when target is not configurable"
            );
        }

        // Both data descriptors with target non-configurable.
        if (
            $targetDesc !== null
            && ($targetDesc->configurable ?? false) === false
            && $targetDesc->isDataDescriptor()
            && $resultDesc->isDataDescriptor()
        ) {
            // (DETAILS_CANT_REPORT_NW_AS_W): target non-writable but result is
            // writable.
            if (($targetDesc->writable ?? false) === false && $resultDesc->writable === true) {
                throw new TypeError(
                    "'getOwnPropertyDescriptor' on proxy: trap reported descriptor"
                    . " for property {$key}: proxy can't report a"
                    . " non-configurable, non-writable property as writable"
                );
            }
            // 22b legacy: result non-writable but target is writable.
            if (
                $resultDesc->writable === false
                && ($targetDesc->writable ?? false) === true
            ) {
                throw new TypeError(
                    "'getOwnPropertyDescriptor' on proxy: trap reported descriptor"
                    . " for property {$key} as non-configurable and non-writable"
                    . " but is writable on the target"
                );
            }
            // (DETAILS_DIFFERENT_VALUE): both non-writable but value differs.
            if (
                ($targetDesc->writable ?? false) === false
                && $resultDesc->writable === false
                && $resultDesc->value !== null
            ) {
                $curVal = $targetDesc->value ?? JsUndefined::instance();
                if (!$this->sameValue($resultDesc->value, $curVal)) {
                    throw new TypeError(
                        "'getOwnPropertyDescriptor' on proxy: trap reported descriptor"
                        . " for property {$key}: proxy must report the same value"
                        . " for the non-writable, non-configurable property"
                    );
                }
            }
        }

        // Both accessor descriptors with target non-configurable.
        if (
            $targetDesc !== null
            && ($targetDesc->configurable ?? false) === false
            && $targetDesc->isAccessorDescriptor()
            && $resultDesc->isAccessorDescriptor()
        ) {
            // (DETAILS_SETTERS_DIFFERENT)
            if ($resultDesc->hasSet && $resultDesc->set !== $targetDesc->set) {
                throw new TypeError(
                    "'getOwnPropertyDescriptor' on proxy: trap reported descriptor"
                    . " for property {$key}: proxy can't report different setters"
                    . " for a currently non-configurable property"
                );
            }
            // (DETAILS_GETTERS_DIFFERENT)
            if ($resultDesc->hasGet && $resultDesc->get !== $targetDesc->get) {
                throw new TypeError(
                    "'getOwnPropertyDescriptor' on proxy: trap reported descriptor"
                    . " for property {$key}: proxy can't report different getters"
                    . " for a currently non-configurable property"
                );
            }
        }
    }

    // -- [[IsExtensible]] --

    public function isExtensible(): bool
    {
        $target = $this->target;
        $handler = $this->handler;
        $trap = $this->getTrap('isExtensible');
        if ($trap !== null) {
            $result = $trap->call($handler, [$target]);
            $booleanTrapResult = \PhpJs\Spec\TypeConversion::toBoolean($result);
            // Invariant: trap result must match target's extensible state.
            $targetResult = $target->isExtensible();
            if ($booleanTrapResult !== $targetResult) {
                throw new TypeError(
                    "'isExtensible' on proxy: trap result does not reflect"
                    . " extensibility of proxy target (which is"
                    . ($targetResult ? ' extensible' : ' not extensible') . ")"
                );
            }
            return $booleanTrapResult;
        }
        return $target->isExtensible();
    }

    // -- [[PreventExtensions]] --

    /**
     * ES spec: [[PreventExtensions]] for proxy.
     * Returns bool to allow Reflect.preventExtensions to return false.
     */
    public function internalPreventExtensions(): bool
    {
        $target = $this->target;
        $handler = $this->handler;
        $trap = $this->getTrap('preventExtensions');
        if ($trap !== null) {
            $result = $trap->call($handler, [$target]);
            $booleanTrapResult = \PhpJs\Spec\TypeConversion::toBoolean($result);
            if ($booleanTrapResult) {
                // Invariant: if trap returns true, target must actually be non-extensible.
                if ($target->isExtensible()) {
                    throw new TypeError(
                        "'preventExtensions' on proxy: trap returned truish but"
                        . " the proxy target is extensible"
                    );
                }
            }
            return $booleanTrapResult;
        }
        $target->preventExtensions();
        return true;
    }

    public function preventExtensions(): bool
    {
        $success = $this->internalPreventExtensions();
        if (!$success) {
            throw new TypeError('\'preventExtensions\' on proxy: trap returned falsish');
        }
        return true;
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
        return $this->getBySymbolWithReceiver($symbol, $this);
    }

    public function getBySymbolWithReceiver(JsSymbol $symbol, JsValue $receiver): JsValue
    {
        $this->assertNotRevoked('get');
        $target = $this->target;
        $handler = $this->handler;
        $trap = $this->getTrap('get');
        if ($trap !== null) {
            $result = $trap->call($handler, [$target, $symbol, $receiver]);
            // Symbol-keyed [[Get]] enforces the same invariants as the
            // string-keyed path per spec 10.5.8 steps 10-11.
            $this->validateGetInvariants($symbol, $result, $target);
            return $result;
        }
        // Without a trap, the proxy's [[Get]] forwards to the target's
        // [[Get]] with the receiver preserved. JsObject.getBySymbolWithReceiver
        // walks the target's symbol properties and prototype chain.
        return $target->getBySymbolWithReceiver($symbol, $receiver);
    }

    public function setBySymbol(JsSymbol $symbol, JsValue $value, bool $strict = false): void
    {
        $this->assertNotRevoked('set');
        $target = $this->target;
        $handler = $this->handler;
        $trap = $this->getTrap('set');
        if ($trap !== null) {
            $result = $trap->call($handler, [$target, $symbol, $value, $this]);
            $boolean = \PhpJs\Spec\TypeConversion::toBoolean($result);
            if ($boolean) {
                // Validate invariants per spec 10.5.9 step 14.
                $this->validateSetInvariants($symbol, $value, $target);
            }
            return;
        }
        $target->setBySymbol($symbol, $value);
    }

    public function hasBySymbol(JsSymbol $symbol): bool
    {
        $this->assertNotRevoked('has');
        $target = $this->target;
        $handler = $this->handler;
        $trap = $this->getTrap('has');
        if ($trap !== null) {
            $result = $trap->call($handler, [$target, $symbol]);
            $booleanTrapResult = \PhpJs\Spec\TypeConversion::toBoolean($result);
            if (!$booleanTrapResult) {
                $this->validateHasInvariants($symbol, $target);
            }
            return $booleanTrapResult;
        }
        return $target->hasBySymbol($symbol);
    }

    // -- typeof --

    public function typeof(): string
    {
        // typeof does NOT throw on revoked proxies per spec.
        // It returns based on the target's callability at creation time.
        if ($this->isCallable()) {
            return 'function';
        }
        return 'object';
    }

    // -- Conversion --

    public function toJsString(): string
    {
        $this->assertNotRevoked('toString');
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
        $this->assertNotRevoked('apply');
        $target = $this->target;
        $handler = $this->handler;
        $trap = $this->getTrap('apply');
        if ($trap !== null) {
            $argsArray = self::createArrayInCurrentRealm($args);
            return $trap->call($handler, [$target, $thisArg, $argsArray]);
        }
        // Forward to target. If target is also a Proxy, recursively invoke apply.
        if ($target instanceof JsProxy) {
            return $target->apply($thisArg, $args);
        }
        if ($target instanceof JsFunction) {
            return $target->call($thisArg, $args);
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
        $this->assertNotRevoked('construct');
        $nt = $newTarget ?? $this;
        $trap = $this->getTrap('construct');
        if ($trap !== null) {
            $argsArray = self::createArrayInCurrentRealm($args);
            $result = $trap->call($this->handler, [$this->target, $argsArray, $nt]);
            if (!$result instanceof JsObject) {
                throw new TypeError('\'construct\' on proxy: trap returned non-object');
            }
            return $result;
        }
        // Per spec 10.5.13 step 7: no trap, forward Construct(target, args, newTarget).
        if ($this->target instanceof JsProxy) {
            return $this->target->construct($args, $nt);
        }
        if ($this->target instanceof JsFunction && $this->target->isConstructable()) {
            // Resolve prototype by reading "prototype" through the newTarget
            // (which may itself be the proxy). When nt is the proxy, this
            // routes through the [[Get]] trap and validates spec invariants —
            // notably, returning a non-object/null for a non-configurable
            // non-writable target prototype property must throw TypeError.
            // Walk through proxy chains for non-self newTargets per
            // GetPrototypeFromConstructor semantics.
            if ($nt === $this) {
                $proto = $this->get('prototype');
            } else {
                $protoSource = $nt;
                if ($protoSource instanceof self) {
                    $inner = $protoSource;
                    while ($inner instanceof self && !$inner->isRevoked()) {
                        $inner = $inner->target;
                    }
                    $protoSource = $inner;
                }
                $proto = ($protoSource instanceof JsFunction) ? $protoSource->get('prototype') : null;
            }
            if (!$proto instanceof JsObject) {
                // Per GetPrototypeFromConstructor step 4.a: if Get returned a
                // non-Object, fall through to GetFunctionRealm. For a Proxy,
                // GetFunctionRealm calls ValidateNonRevokedProxy first — so a
                // get trap that revokes the proxy mid-flight must surface as
                // a TypeError, not as a null target dereference.
                if ($this->isRevoked()) {
                    throw new TypeError('Cannot perform Construct on a proxy that has been revoked');
                }
                // Per spec: GetFunctionRealm(newTarget).%Object.prototype%.
                $ntRealm = \PhpJs\Spec\AbstractOperations::getFunctionRealm($nt);
                if ($ntRealm !== null) {
                    $proto = \PhpJs\Spec\AbstractOperations::realmIntrinsicPrototype(
                        $ntRealm->getGlobalEnv(),
                        'Object',
                    );
                }
                if (!$proto instanceof JsObject) {
                    $proto = $this->target->get('prototype');
                }
            }
            // Construct manually so [[NewTarget]] is the user-supplied
            // newTarget rather than the underlying target. Mirrors what
            // OrdinaryCallBindThis observes inside the body, so new.target
            // matches the spec for `super()` from a derived class extending
            // a Proxy.
            $newObj = new JsObject($proto instanceof JsObject ? $proto : null);
            $newObj->defineOwnProperty(
                '[[NewTarget]]',
                \PhpJs\Object\PropertyDescriptor::data($nt, false, false, false),
            );
            $result = $this->target->call($newObj, $args);
            if ($result instanceof JsObject) {
                $result->forceDelete('[[NewTarget]]');
                return $result;
            }
            $newObj->forceDelete('[[NewTarget]]');
            return $newObj;
        }
        throw new TypeError('proxy target is not a constructor');
    }

    // -- Helpers --

    /**
     * Convert a trap result to an array of JsValue property keys (strings and symbols).
     *
     * @return list<JsValue>
     */
    private function trapResultToPropertyKeys(JsValue $result): array
    {
        if (!$result instanceof JsObject) {
            throw new TypeError('\'ownKeys\' on proxy: trap returned non-object');
        }
        $keys = [];
        // Per CreateListFromArrayLike: read "length" and iterate indexed elements.
        $lenVal = $result->get('length');
        $len = (int) \PhpJs\Spec\TypeConversion::toLength($lenVal);
        for ($i = 0; $i < $len; $i++) {
            $elem = $result->get((string) $i);
            if ($elem instanceof JsSymbol) {
                $keys[] = $elem;
            } elseif ($elem instanceof JsString) {
                $keys[] = $elem;
            } else {
                throw new TypeError(
                    \PhpJs\Spec\TypeConversion::toString($elem)
                    . ' is not a valid property name'
                );
            }
        }
        return $keys;
    }

    /**
     * Convert a PropertyDescriptor to a JsObject for passing to traps.
     * Mirrors spec FromPropertyDescriptor §6.2.5.4: only attaches the fields
     * the source descriptor actually had (uses hasGet / hasSet to distinguish
     * "explicitly undefined" from "not present"). Property insertion order
     * follows the spec sequence (value, writable, get, set, enumerable,
     * configurable), which is observable via Object.getOwnPropertyNames on
     * the trap argument.
     */
    private static function descriptorToObject(PropertyDescriptor $desc): JsObject
    {
        // Per spec 6.2.4.4 FromPropertyDescriptor: ObjectCreate(%ObjectPrototype%)
        // — the *current realm's* Object.prototype, not the proxy target's.
        $thisRealm = \PhpJs\Engine::getCurrentRealm();
        $objProto = null;
        if ($thisRealm !== null) {
            $objProto = \PhpJs\Spec\AbstractOperations::realmIntrinsicPrototype(
                $thisRealm->getGlobalEnv(),
                'Object',
            );
        }
        $obj = new JsObject($objProto);
        if ($desc->value !== null) {
            $obj->set('value', $desc->value);
        }
        if ($desc->writable !== null) {
            $obj->set('writable', new JsBoolean($desc->writable));
        }
        if ($desc->hasGet) {
            $obj->set('get', $desc->get ?? JsUndefined::instance());
        }
        if ($desc->hasSet) {
            $obj->set('set', $desc->set ?? JsUndefined::instance());
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
        // Per spec 6.2.5.5 ToPropertyDescriptor walks fields in the order
        // enumerable, configurable, value, writable, get, set. The order is
        // observable through Proxy has/get traps and so must be exact.
        $enumerable = $obj->has('enumerable')
            ? \PhpJs\Spec\TypeConversion::toBoolean($obj->get('enumerable'))
            : null;
        $configurable = $obj->has('configurable')
            ? \PhpJs\Spec\TypeConversion::toBoolean($obj->get('configurable'))
            : null;
        $value = $obj->has('value') ? $obj->get('value') : null;
        $writable = $obj->has('writable')
            ? \PhpJs\Spec\TypeConversion::toBoolean($obj->get('writable'))
            : null;
        $getter = null;
        $setter = null;
        $hasGetOrSet = false;
        if ($obj->has('get')) {
            $hasGetOrSet = true;
            $g = $obj->get('get');
            if ($g instanceof JsFunction) {
                $getter = $g;
            }
        }
        if ($obj->has('set')) {
            $hasGetOrSet = true;
            $s = $obj->get('set');
            if ($s instanceof JsFunction) {
                $setter = $s;
            }
        }

        if ($hasGetOrSet) {
            $desc = PropertyDescriptor::accessor(
                get: $getter,
                set: $setter,
                enumerable: $enumerable ?? false,
                configurable: $configurable ?? false,
            );
            // Preserve "explicitly present" markers so proxy invariant checks
            // can distinguish "field was supplied" from "field was omitted".
            $desc->hasGet = $obj->has('get');
            $desc->hasSet = $obj->has('set');
            return $desc;
        }

        return new PropertyDescriptor(
            value: $value,
            writable: $writable,
            enumerable: $enumerable,
            configurable: $configurable,
        );
    }

    /**
     * CreateArrayFromList in the current Realm. JsArray::fromArray uses
     * the process-wide globalPrototype static which a sibling realm may
     * have overwritten; the spec says proxy traps see the array built in
     * the *current* realm. Pin its [[Prototype]] to the realm-aware
     * %Array.prototype% to satisfy that invariant.
     *
     * @param list<JsValue> $args
     */
    private static function createArrayInCurrentRealm(array $args): JsArray
    {
        $thisRealm = \PhpJs\Engine::getCurrentRealm();
        $arrProto = null;
        if ($thisRealm !== null) {
            $arrProto = \PhpJs\Spec\AbstractOperations::realmIntrinsicPrototype(
                $thisRealm->getGlobalEnv(),
                'Array',
            );
        }
        return new JsArray($args, $arrProto);
    }
}
