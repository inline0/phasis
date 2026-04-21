<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\Object\PropertyDescriptor;
use PhpJs\Runtime\Environment;

/**
 * Arguments exotic object per ES spec 10.4.4.
 *
 * Maintains a parameter map for live aliasing between arguments indices
 * and named parameters in sloppy mode. The indices are stored as ordinary
 * data properties; the map provides the read/write aliasing to the
 * function environment.
 */
class JsArgumentsObject extends JsObject
{
    /** @var array<string, string> Map of index => parameter name for mapped indices. */
    private array $parameterMap = [];

    private ?Environment $env = null;

    /**
     * Add a mapped index linking arguments[index] to the named parameter binding.
     */
    public function addMapping(string $index, string $paramName, Environment $env): void
    {
        $this->parameterMap[$index] = $paramName;
        $this->env = $env;
    }

    /**
     * Check if an index is currently mapped.
     */
    public function isMapped(string $index): bool
    {
        return isset($this->parameterMap[$index]);
    }

    /**
     * Remove the mapping for an index.
     */
    public function unmap(string $index): void
    {
        unset($this->parameterMap[$index]);
    }

    /**
     * ES spec 10.4.4.6: [[Delete]](P).
     *
     * If the property is successfully deleted and was mapped, remove the mapping.
     */
    public function delete(string $name, bool $strict = false): bool
    {
        $result = parent::delete($name, $strict);
        if ($result && isset($this->parameterMap[$name])) {
            $this->unmap($name);
        }
        return $result;
    }

    /**
     * ES spec 10.4.4.3: [[Get]](P, Receiver).
     *
     * If P is a mapped index, return the value from the environment binding.
     */
    protected function getWithReceiver(string $name, JsObject $receiver): JsValue
    {
        if (isset($this->parameterMap[$name]) && $this->env !== null) {
            return $this->env->get($this->parameterMap[$name]);
        }
        return parent::getWithReceiver($name, $receiver);
    }

    /**
     * ES spec 10.4.4.5: [[Set]](P, V, Receiver).
     *
     * If P is a mapped index and this is the receiver, update the
     * environment binding. Then proceed with ordinary set.
     */
    public function internalSet(string $name, JsValue $value, JsValue $receiver): bool
    {
        if ($receiver === $this && isset($this->parameterMap[$name]) && $this->env !== null) {
            $this->env->set($this->parameterMap[$name], $value);
        }
        return parent::internalSet($name, $value, $receiver);
    }

    /**
     * ES spec 10.4.4.2: [[GetOwnProperty]](P).
     *
     * Get the ordinary descriptor, then if mapped, replace value with
     * the current environment binding value.
     */
    public function getOwnPropertyDescriptor(string $name): ?PropertyDescriptor
    {
        $desc = parent::getOwnPropertyDescriptor($name);
        if ($desc === null) {
            return null;
        }
        if (isset($this->parameterMap[$name]) && $this->env !== null && $desc->isDataDescriptor()) {
            return new PropertyDescriptor(
                value: $this->env->get($this->parameterMap[$name]),
                writable: $desc->writable,
                enumerable: $desc->enumerable,
                configurable: $desc->configurable,
            );
        }
        return $desc;
    }

    /**
     * ES spec 10.4.4.4: [[DefineOwnProperty]](P, Desc).
     *
     * Implements the arguments exotic [[DefineOwnProperty]] with parameter
     * map interactions.
     */
    public function defineOwnProperty(string $name, PropertyDescriptor $desc): bool
    {
        $isMapped = isset($this->parameterMap[$name]);

        $newArgDesc = $desc;

        // Step 3: If mapped and data descriptor with writable=false but no value,
        // we need to fill in the current mapped value so the property retains it.
        if ($isMapped && $desc->isDataDescriptor() && !$desc->isAccessorDescriptor()) {
            if ($desc->value === null && $desc->writable === false) {
                $currentVal = $this->env !== null
                    ? $this->env->get($this->parameterMap[$name])
                    : JsUndefined::instance();
                $newArgDesc = new PropertyDescriptor(
                    value: $currentVal,
                    writable: $desc->writable,
                    enumerable: $desc->enumerable,
                    configurable: $desc->configurable,
                );
            }
        }

        // Step 4: OrdinaryDefineOwnProperty.
        $allowed = parent::defineOwnProperty($name, $newArgDesc);
        if (!$allowed) {
            return false;
        }

        // Step 6: Post-define map updates.
        if ($isMapped) {
            if ($desc->isAccessorDescriptor()) {
                // 6.a: Accessor descriptor removes the mapping.
                $this->unmap($name);
            } else {
                // 6.b.i: If Desc has [[Value]], update the mapped binding.
                if ($desc->value !== null && $this->env !== null) {
                    $this->env->set($this->parameterMap[$name], $desc->value);
                }
                // 6.b.ii: If Desc has [[Writable]] = false, remove the mapping.
                if ($desc->writable === false) {
                    $this->unmap($name);
                }
            }
        }

        return true;
    }
}
