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

    public function __construct(JsObject $target, JsObject $handler)
    {
        parent::__construct($target instanceof JsProxy && $target->isRevoked() ? null : $target->getPrototype());
        $this->target = $target;
        $this->handler = $handler;
        // Per spec ProxyCreate steps 6-7: capture [[Call]] slot existence at creation time.
        $this->targetIsCallable = $this->determineCallable($target);
    }

    /** Determine if a target has a [[Call]] internal slot. */
    private function determineCallable(JsObject $target): bool
    {
        if ($target instanceof JsFunction) {
            return true;
        }
        if ($target instanceof JsProxy) {
            return $target->isCallable();
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

    private function assertNotRevoked(string $trap = 'get'): void
    {
        if ($this->target === null || $this->handler === null) {
            throw new TypeError("Cannot perform '{$trap}' on a proxy that has been revoked");
        }
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
     * ES spec: [[Get]] ( P, Receiver ) for Proxy objects.
     *
     * Forwards the receiver correctly for prototype chain lookups
     * and validates invariants when a trap is present.
     */
    public function internalGet(string $name, JsObject $receiver): JsValue
    {
        $trap = $this->getTrap('get');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target, new JsString($name), $receiver]);
            // Validate invariants per spec 10.5.8 steps 10-11.
            $this->validateGetInvariants($name, $result);
            return $result;
        }
        // No trap: forward to target.[[Get]](P, Receiver).
        $result = $this->target->internalGet($name, $receiver);
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
    private function validateGetInvariants(string $name, JsValue $trapResult): void
    {
        $targetDesc = $this->target->getOwnPropertyDescriptor($name);
        if ($targetDesc === null) {
            return;
        }
        if ($targetDesc->configurable === false) {
            if ($targetDesc->isDataDescriptor() && $targetDesc->writable === false) {
                $targetValue = $targetDesc->value ?? JsUndefined::instance();
                if (!$this->sameValue($trapResult, $targetValue)) {
                    throw new TypeError(
                        "'get' on proxy: property '{$name}' is a read-only and"
                        . " non-configurable data property on the proxy target"
                        . " but the proxy did not return its actual value"
                    );
                }
            }
            if ($targetDesc->isAccessorDescriptor() && $targetDesc->get === null) {
                if (!$trapResult instanceof JsUndefined) {
                    throw new TypeError(
                        "'get' on proxy: property '{$name}' is a non-configurable accessor"
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
            throw new TypeError("'set' on proxy: trap returned falsish for property '{$name}'");
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
        $trap = $this->getTrap('set');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target, new JsString($name), $value, $receiver]);
            $booleanTrapResult = \PhpJs\Spec\TypeConversion::toBoolean($result);
            if (!$booleanTrapResult) {
                return false;
            }
            // Validate invariants per spec step 14.
            $this->validateSetInvariants($name, $value);
            return true;
        }
        // No trap: forward to target.[[Set]](P, V, Receiver).
        return $this->target->internalSet($name, $value, $receiver);
    }

    /**
     * Validate set trap invariants per ES spec 10.5.9 step 14.
     *
     * After the trap returns true, if the target property is non-configurable:
     * - Data property with writable=false: value must be SameValue as V.
     * - Accessor property with set=undefined: must throw TypeError.
     */
    private function validateSetInvariants(string $name, JsValue $value): void
    {
        $targetDesc = $this->target->getOwnPropertyDescriptor($name);
        if ($targetDesc === null) {
            return;
        }
        if ($targetDesc->configurable === false) {
            if ($targetDesc->isDataDescriptor() && $targetDesc->writable === false) {
                $targetValue = $targetDesc->value ?? JsUndefined::instance();
                if (!$this->sameValue($value, $targetValue)) {
                    throw new TypeError(
                        "'set' on proxy: trap returned truish for property"
                        . " '{$name}' which exists in the proxy target as a"
                        . " non-configurable and non-writable data property"
                        . " with a different value"
                    );
                }
            }
            if ($targetDesc->isAccessorDescriptor() && $targetDesc->set === null) {
                throw new TypeError(
                    "'set' on proxy: trap returned truish for property"
                    . " '{$name}' which exists in the proxy target as a"
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
            // Distinguish +0 and -0.
            if ($x === 0.0 && $y === 0.0) {
                return (1 / $x > 0) === (1 / $y > 0); // @phpstan-ignore binaryOp.invalid
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
        $trap = $this->getTrap('has');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target, new JsString($name)]);
            $booleanTrapResult = \PhpJs\Spec\TypeConversion::toBoolean($result);
            if (!$booleanTrapResult) {
                // Validate invariants per spec 10.5.7 step 11.
                $this->validateHasInvariants($name);
            }
            return $booleanTrapResult;
        }
        return $this->target->has($name);
    }

    /**
     * Validate has trap invariants per ES spec 10.5.7 step 11.
     *
     * When the trap returns false:
     * - If property is non-configurable on target, throw TypeError.
     * - If target is not extensible and property exists on target, throw TypeError.
     */
    private function validateHasInvariants(string $name): void
    {
        $targetDesc = $this->target->getOwnPropertyDescriptor($name);
        if ($targetDesc !== null) {
            if ($targetDesc->configurable === false) {
                throw new TypeError(
                    "'has' on proxy: property '{$name}' is a non-configurable own"
                    . " property of the proxy target but the has trap returned false"
                );
            }
            if (!$this->target->isExtensible()) {
                throw new TypeError(
                    "'has' on proxy: property '{$name}' is an own property of the"
                    . " non-extensible proxy target but the has trap returned false"
                );
            }
        }
    }

    // -- [[Delete]] --

    public function delete(string $name, bool $strict = false): bool
    {
        $trap = $this->getTrap('deleteProperty');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target, new JsString($name)]);
            $booleanTrapResult = \PhpJs\Spec\TypeConversion::toBoolean($result);
            if ($booleanTrapResult) {
                // Validate invariants per spec 10.5.10 steps 13-15.
                $this->validateDeleteInvariants($name);
            }
            if (!$booleanTrapResult && $strict) {
                throw new TypeError("'deleteProperty' on proxy: property '{$name}' is non-configurable");
            }
            return $booleanTrapResult;
        }
        return $this->target->delete($name, $strict);
    }

    /**
     * Validate delete trap invariants per ES spec 10.5.10 steps 13-15.
     *
     * When the trap returns true:
     * - If target property is non-configurable, throw TypeError.
     * - If target property exists and target is not extensible, throw TypeError.
     */
    private function validateDeleteInvariants(string $name): void
    {
        $targetDesc = $this->target->getOwnPropertyDescriptor($name);
        if ($targetDesc !== null) {
            if ($targetDesc->configurable === false) {
                throw new TypeError(
                    "'deleteProperty' on proxy: property '{$name}' is"
                    . " non-configurable and can't be deleted"
                );
            }
            if (!$this->target->isExtensible()) {
                throw new TypeError(
                    "'deleteProperty' on proxy: property '{$name}' exists on a"
                    . " non-extensible proxy target and can't be deleted"
                );
            }
        }
    }

    // -- [[OwnPropertyKeys]] --

    public function ownKeys(): array
    {
        $trap = $this->getTrap('ownKeys');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target]);
            $trapResult = $this->trapResultToStringArray($result);
            $this->validateOwnKeysInvariants($trapResult);
            return $trapResult;
        }
        return $this->target->ownKeys();
    }

    /**
     * Validate ownKeys trap invariants per ES spec 10.5.11 steps 17-25.
     *
     * @param list<string> $trapResult
     */
    private function validateOwnKeysInvariants(array $trapResult): void
    {
        // Step 17: Check for duplicate entries.
        if (count($trapResult) !== count(array_unique($trapResult))) {
            throw new TypeError("'ownKeys' on proxy: trap returned duplicate entries");
        }

        $extensibleTarget = $this->target->isExtensible();
        $targetKeys = $this->target->ownKeys();

        // Find non-configurable keys on target.
        $targetNonconfigurableKeys = [];
        $targetConfigurableKeys = [];
        foreach ($targetKeys as $key) {
            $desc = $this->target->getOwnPropertyDescriptor($key);
            if ($desc !== null && $desc->configurable === false) {
                $targetNonconfigurableKeys[] = $key;
            } else {
                $targetConfigurableKeys[] = $key;
            }
        }

        // Step 21: All non-configurable target keys must appear in trap result.
        $trapSet = array_flip($trapResult);
        foreach ($targetNonconfigurableKeys as $key) {
            if (!isset($trapSet[$key])) {
                throw new TypeError(
                    "'ownKeys' on proxy: trap result did not include '{$key}'"
                );
            }
        }

        // Step 23: If target is non-extensible, all target keys must be in trap result
        // and no extra keys allowed.
        if (!$extensibleTarget) {
            foreach ($targetConfigurableKeys as $key) {
                if (!isset($trapSet[$key])) {
                    throw new TypeError(
                        "'ownKeys' on proxy: trap result did not include '{$key}'"
                    );
                }
            }
            if (count($trapResult) !== count($targetKeys)) {
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
            // Validate invariants using string representation for duplicate checking.
            $stringified = [];
            foreach ($trapResult as $k) {
                $stringified[] = ($k instanceof JsSymbol)
                    ? 'Symbol(' . ($k->description ?? '') . ')#' . $k->getId()
                    : ($k instanceof JsString ? $k->value : (string) $k);
            }
            $this->validateOwnKeysInvariants($stringified);
            return $trapResult;
        }
        return $this->target->ordinaryOwnPropertyKeys();
    }

    // -- [[GetPrototypeOf]] --

    public function getPrototype(): ?JsObject
    {
        $trap = $this->getTrap('getPrototypeOf');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target]);
            if ($result instanceof JsNull) {
                $handlerProto = null;
            } elseif ($result instanceof JsObject) {
                $handlerProto = $result;
            } else {
                throw new TypeError('\'getPrototypeOf\' on proxy: trap returned neither object nor null');
            }
            // Invariant: if target is not extensible, must return same as target proto.
            if (!$this->target->isExtensible()) {
                $targetProto = $this->target->getPrototype();
                if ($handlerProto !== $targetProto) {
                    throw new TypeError(
                        '\'getPrototypeOf\' on proxy: proxy target is non-extensible but the'
                        . ' trap did not return its actual prototype'
                    );
                }
            }
            return $handlerProto;
        }
        return $this->target->getPrototype();
    }

    // -- [[SetPrototypeOf]] --

    /**
     * Internal [[SetPrototypeOf]] returning bool, for Reflect.setPrototypeOf.
     */
    public function internalSetPrototypeOf(?JsObject $prototype): bool
    {
        $trap = $this->getTrap('setPrototypeOf');
        if ($trap !== null) {
            $protoArg = $prototype ?? JsNull::instance();
            $result = $trap->call($this->handler, [$this->target, $protoArg]);
            if (!\PhpJs\Spec\TypeConversion::toBoolean($result)) {
                return false;
            }
            // Per spec step 10-14: if target is extensible, return true.
            // Only check the invariant when target is NOT extensible.
            $extensibleTarget = $this->target->isExtensible();
            if ($extensibleTarget) {
                return true;
            }
            // Target is not extensible: the prototype must match target's current prototype.
            $targetProto = $this->target->getPrototype();
            if ($prototype !== $targetProto) {
                throw new TypeError(
                    '\'setPrototypeOf\' on proxy: trap returned truish for setting a'
                    . ' new prototype on a non-extensible proxy target'
                );
            }
            return true;
        }
        return $this->target->trySetPrototype($prototype);
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
        $trap = $this->getTrap('defineProperty');
        if ($trap !== null) {
            $descObj = self::descriptorToObject($desc);
            $result = $trap->call($this->handler, [$this->target, new JsString($name), $descObj]);
            if (!\PhpJs\Spec\TypeConversion::toBoolean($result)) {
                throw new TypeError("'defineProperty' on proxy: trap returned falsish for property '{$name}'");
            }
            // Per spec 10.5.6 steps 17-22: validate invariants.
            $this->validateDefinePropertyInvariants($name, $desc);
            return true;
        }
        return $this->target->defineOwnProperty($name, $desc);
    }

    /**
     * Validate defineProperty invariants per spec 10.5.6 steps 17-22.
     */
    private function validateDefinePropertyInvariants(string $name, PropertyDescriptor $desc): void
    {
        $targetDesc = $this->target->getOwnPropertyDescriptor($name);
        $extensibleTarget = $this->target->isExtensible();

        // Step 19: If target property does not exist and target is not extensible, throw.
        if ($targetDesc === null && !$extensibleTarget) {
            throw new TypeError(
                "'defineProperty' on proxy: trap returned truish for defining"
                . " non-configurable property '{$name}' which cannot exist"
                . " on the non-extensible proxy target"
            );
        }

        // "settingConfigFalse" flag per spec step 16.
        $settingConfigFalse = $desc->configurable === false;

        if ($targetDesc !== null) {
            // Step 20a: IsCompatiblePropertyDescriptor check.
            if (!self::isCompatiblePropertyDescriptor($extensibleTarget, $desc, $targetDesc)) {
                throw new TypeError(
                    "'defineProperty' on proxy: trap returned truish for"
                    . " property '{$name}' which is incompatible with the"
                    . " existing property on the proxy target"
                );
            }

            // Step 20b: If settingConfigFalse is true and targetDesc.configurable is true, throw.
            if ($settingConfigFalse && ($targetDesc->configurable ?? false)) {
                throw new TypeError(
                    "'defineProperty' on proxy: trap returned truish for"
                    . " defining non-configurable property '{$name}' which"
                    . " is already configurable in the proxy target"
                );
            }

            // Step 20c: If targetDesc is non-configurable data and desc makes it non-writable
            // but targetDesc is writable, throw TypeError.
            if (
                ($targetDesc->configurable ?? false) === false
                && $targetDesc->isDataDescriptor()
                && ($targetDesc->writable ?? false)
                && $desc->isDataDescriptor()
                && $desc->writable === false
            ) {
                throw new TypeError(
                    "'defineProperty' on proxy: trap returned truish for"
                    . " defining non-configurable non-writable property '{$name}'"
                    . " which is writable in the proxy target"
                );
            }
        }

        // Step 19 variant: desc is non-configurable and target property does not exist.
        if ($settingConfigFalse && $targetDesc === null) {
            throw new TypeError(
                "'defineProperty' on proxy: trap returned truish for"
                . " defining non-configurable property '{$name}'"
                . " on a target that does not have this property"
            );
        }
    }

    /**
     * IsCompatiblePropertyDescriptor for proxy defineProperty invariant checking.
     * Per spec ValidateAndApplyPropertyDescriptor.
     */
    private function isCompatiblePropertyDescriptor(
        bool $extensible,
        PropertyDescriptor $desc,
        PropertyDescriptor $current,
    ): bool {
        // If current is not configurable, apply restrictions.
        if ($current->configurable === false) {
            if ($desc->configurable === true) {
                return false;
            }
            if ($desc->enumerable !== null && $desc->enumerable !== ($current->enumerable ?? false)) {
                return false;
            }
        }

        // Generic descriptor (no value/writable/get/set): always compatible.
        if (!$desc->isDataDescriptor() && !$desc->isAccessorDescriptor()) {
            return true;
        }

        // Switching between data and accessor.
        $currentIsData = $current->isDataDescriptor();
        $descIsData = $desc->isDataDescriptor();
        if ($currentIsData !== $descIsData) {
            return $current->configurable !== false;
        }

        // Both data descriptors.
        if ($currentIsData && $descIsData) {
            if ($current->configurable === false && $current->writable === false) {
                if ($desc->writable === true) {
                    return false;
                }
                $curVal = $current->value ?? JsUndefined::instance();
                if ($desc->value !== null && !$this->sameValue($desc->value, $curVal)) {
                    return false;
                }
            }
            return true;
        }

        // Both accessor descriptors.
        if ($current->configurable === false) {
            if ($desc->set !== null && $desc->set !== $current->set) {
                return false;
            }
            if ($desc->get !== null && $desc->get !== $current->get) {
                return false;
            }
        }
        return true;
    }

    public function defineProperty(string $name, PropertyDescriptor $desc): void
    {
        $this->defineOwnProperty($name, $desc);
    }

    /** Symbol-keyed getOwnPropertyDescriptor that goes through the trap. */
    public function getSymbolPropertyDescriptor(JsSymbol $symbol): ?PropertyDescriptor
    {
        $trap = $this->getTrap('getOwnPropertyDescriptor');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target, $symbol]);
            if ($result instanceof JsUndefined) {
                return null;
            }
            if (!$result instanceof JsObject) {
                throw new TypeError(
                    "'getOwnPropertyDescriptor' on proxy: trap returned"
                    . " neither Object nor undefined for symbol property"
                );
            }
            return self::objectToDescriptor($result);
        }
        return $this->target->getSymbolPropertyDescriptor($symbol);
    }

    /** Symbol-keyed defineProperty that goes through the defineProperty trap. */
    public function definePropertyBySymbol(JsSymbol $symbol, PropertyDescriptor $desc): bool
    {
        $trap = $this->getTrap('defineProperty');
        if ($trap !== null) {
            $descObj = self::descriptorToObject($desc);
            $result = $trap->call($this->handler, [$this->target, $symbol, $descObj]);
            if (!\PhpJs\Spec\TypeConversion::toBoolean($result)) {
                throw new TypeError("'defineProperty' on proxy: trap returned falsish for symbol property");
            }
            return true;
        }
        return $this->target->definePropertyBySymbol($symbol, $desc);
    }

    // -- [[GetOwnProperty]] --

    public function getOwnPropertyDescriptor(string $name): ?PropertyDescriptor
    {
        $trap = $this->getTrap('getOwnPropertyDescriptor');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target, new JsString($name)]);
            if ($result instanceof JsUndefined) {
                // Validate undefined result invariants per spec 10.5.5 step 14.
                $this->validateGetOwnPropertyUndefinedInvariants($name);
                return null;
            }
            if (!$result instanceof JsObject) {
                // Step 11: result must be Object or Undefined.
                throw new TypeError(
                    "'getOwnPropertyDescriptor' on proxy: trap returned"
                    . " neither Object nor undefined for property '{$name}'"
                );
            }
            $resultDesc = self::objectToDescriptor($result);
            // Validate result descriptor invariants per spec 10.5.5 steps 16-22.
            $this->validateGetOwnPropertyInvariants($name, $resultDesc);
            return $resultDesc;
        }
        return $this->target->getOwnPropertyDescriptor($name);
    }

    /**
     * Validate getOwnPropertyDescriptor invariants when trap returns undefined.
     * Per spec 10.5.5 step 14.
     */
    private function validateGetOwnPropertyUndefinedInvariants(string $name): void
    {
        $targetDesc = $this->target->getOwnPropertyDescriptor($name);
        if ($targetDesc === null) {
            return;
        }
        // 14b: non-configurable property cannot be reported as non-existent.
        if ($targetDesc->configurable === false) {
            throw new TypeError(
                "'getOwnPropertyDescriptor' on proxy: property '{$name}' is non-configurable"
                . " on the proxy target but the trap returned undefined"
            );
        }
        // 14e: if target is non-extensible, existing property cannot be reported as non-existent.
        if (!$this->target->isExtensible()) {
            throw new TypeError(
                "'getOwnPropertyDescriptor' on proxy: property '{$name}' exists on the"
                . " non-extensible proxy target but the trap returned undefined"
            );
        }
    }

    /**
     * Validate getOwnPropertyDescriptor invariants when trap returns a descriptor.
     * Per spec 10.5.5 steps 16-22.
     */
    private function validateGetOwnPropertyInvariants(string $name, PropertyDescriptor $resultDesc): void
    {
        $targetDesc = $this->target->getOwnPropertyDescriptor($name);
        $extensibleTarget = $this->target->isExtensible();

        // Step 20: IsCompatiblePropertyDescriptor check.
        // If target is non-extensible and target property does not exist, throw.
        if (!$extensibleTarget && $targetDesc === null) {
            throw new TypeError(
                "'getOwnPropertyDescriptor' on proxy: property '{$name}' is reported"
                . " but does not exist on the non-extensible proxy target"
            );
        }

        // Step 22: non-configurable result checks.
        if ($resultDesc->configurable === false) {
            // 22a: if target desc is undefined or configurable, throw.
            if ($targetDesc === null || $targetDesc->configurable !== false) {
                throw new TypeError(
                    "'getOwnPropertyDescriptor' on proxy: property '{$name}' is"
                    . " reported as non-configurable but is configurable or"
                    . " non-existent on the proxy target"
                );
            }
            // 22b: non-configurable non-writable in result, but target is writable.
            if (
                $resultDesc->isDataDescriptor()
                && $resultDesc->writable === false
                && $targetDesc->isDataDescriptor()
                && $targetDesc->writable === true
            ) {
                throw new TypeError(
                    "'getOwnPropertyDescriptor' on proxy: property '{$name}' is reported"
                    . " as non-configurable and non-writable but is writable on the target"
                );
            }
        }
    }

    // -- [[IsExtensible]] --

    public function isExtensible(): bool
    {
        $trap = $this->getTrap('isExtensible');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target]);
            $booleanTrapResult = \PhpJs\Spec\TypeConversion::toBoolean($result);
            // Invariant: trap result must match target's extensible state.
            $targetResult = $this->target->isExtensible();
            if ($booleanTrapResult !== $targetResult) {
                throw new TypeError(
                    "'isExtensible' on proxy: trap result does not reflect"
                    . " extensibility of proxy target (which is"
                    . ($targetResult ? ' extensible' : ' not extensible') . ")"
                );
            }
            return $booleanTrapResult;
        }
        return $this->target->isExtensible();
    }

    // -- [[PreventExtensions]] --

    /**
     * ES spec: [[PreventExtensions]] for proxy.
     * Returns bool to allow Reflect.preventExtensions to return false.
     */
    public function internalPreventExtensions(): bool
    {
        $trap = $this->getTrap('preventExtensions');
        if ($trap !== null) {
            $result = $trap->call($this->handler, [$this->target]);
            $booleanTrapResult = \PhpJs\Spec\TypeConversion::toBoolean($result);
            if ($booleanTrapResult) {
                // Invariant: if trap returns true, target must actually be non-extensible.
                if ($this->target->isExtensible()) {
                    throw new TypeError(
                        "'preventExtensions' on proxy: trap returned truish but"
                        . " the proxy target is extensible"
                    );
                }
            }
            return $booleanTrapResult;
        }
        $this->target->preventExtensions();
        return true;
    }

    public function preventExtensions(): void
    {
        $success = $this->internalPreventExtensions();
        if (!$success) {
            throw new TypeError('\'preventExtensions\' on proxy: trap returned falsish');
        }
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
        $this->assertNotRevoked('get');
        // Check for get trap using the symbol's description as a key proxy.
        $trap = $this->getTrap('get');
        if ($trap !== null) {
            return $trap->call($this->handler, [$this->target, $symbol, $this]);
        }
        return $this->target->getBySymbol($symbol);
    }

    public function setBySymbol(JsSymbol $symbol, JsValue $value, bool $strict = false): void
    {
        $this->assertNotRevoked('set');
        $trap = $this->getTrap('set');
        if ($trap !== null) {
            $trap->call($this->handler, [$this->target, $symbol, $value, $this]);
            return;
        }
        $this->target->setBySymbol($symbol, $value);
    }

    public function hasBySymbol(JsSymbol $symbol): bool
    {
        $this->assertNotRevoked('has');
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
        $trap = $this->getTrap('apply');
        if ($trap !== null) {
            $argsArray = JsArray::fromArray($args);
            return $trap->call($this->handler, [$this->target, $thisArg, $argsArray]);
        }
        // Forward to target. If target is also a Proxy, recursively invoke apply.
        if ($this->target instanceof JsProxy) {
            return $this->target->apply($thisArg, $args);
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
        $this->assertNotRevoked('construct');
        $nt = $newTarget ?? $this;
        $trap = $this->getTrap('construct');
        if ($trap !== null) {
            $argsArray = JsArray::fromArray($args);
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
            // Use newTarget's prototype (not target's) per spec Construct step 5.
            $proto = ($nt instanceof JsFunction) ? $nt->get('prototype') : null;
            $newObj = new JsObject($proto instanceof JsObject ? $proto : null);
            $newObj->defineOwnProperty(
                '[[NewTarget]]',
                \PhpJs\Object\PropertyDescriptor::data($nt instanceof JsFunction ? $nt : $this->target, false, false, false),
            );
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
                // Per spec CreateListFromArrayLike with elementTypes String,Symbol:
                // each element must be a String or Symbol, otherwise throw TypeError.
                if (!$elem instanceof JsString && !$elem instanceof JsSymbol) {
                    throw new TypeError(
                        \PhpJs\Spec\TypeConversion::toString($elem)
                        . ' is not a valid property name'
                    );
                }
                $keys[] = \PhpJs\Spec\TypeConversion::toString($elem);
            }
        } else {
            foreach ($result->getOwnPropertyNames() as $k) {
                $elem = $result->get($k);
                if (!$elem instanceof JsString && !$elem instanceof JsSymbol) {
                    throw new TypeError(
                        \PhpJs\Spec\TypeConversion::toString($elem)
                        . ' is not a valid property name'
                    );
                }
                $keys[] = \PhpJs\Spec\TypeConversion::toString($elem);
            }
        }
        return $keys;
    }

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
        if ($result instanceof JsArray) {
            $len = $result->getLength();
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
