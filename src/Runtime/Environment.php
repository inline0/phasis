<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Exceptions\ReferenceError;
use PhpJs\Exceptions\TypeError;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class Environment
{
    /** @var array<string, JsValue> */
    private array $bindings = [];

    /** @var array<string, bool> Track which bindings are const. */
    private array $constants = [];

    /** @var array<string, bool> Track which bindings are in the temporal dead zone. */
    private array $tdz = [];

    /** @var array<string, bool> Track which bindings are deletable (implicit globals). */
    private array $deletable = [];

    /**
     * When set, var declarations and assignments in this environment
     * also create/update properties on the linked object. Used for
     * the global environment to keep globalThis in sync with var bindings.
     */
    private ?\PhpJs\Value\JsObject $linkedObject = null;

    public function __construct(
        private readonly ?Environment $parent = null,
    ) {
    }

    /**
     * Link this environment to a global object so that var declarations
     * and assignments are mirrored as own properties on the object.
     */
    public function linkGlobalObject(\PhpJs\Value\JsObject $obj): void
    {
        $this->linkedObject = $obj;
    }

    public function getLinkedObject(): ?\PhpJs\Value\JsObject
    {
        return $this->linkedObject;
    }

    /** Define a var-declared variable in the current environment. */
    public function defineVar(string $name, JsValue $value): void
    {
        $this->bindings[$name] = $value;
        // Sync to the linked global object if present.
        // Skip internal bindings that start with __ (prototypes, etc.)
        // and the special 'this'/'globalThis' bindings.
        if ($this->linkedObject !== null
            && $name !== 'this' && $name !== 'globalThis'
            && !(str_starts_with($name, '__') && str_ends_with($name, '__'))
        ) {
            $this->linkedObject->defineOwnProperty(
                $name,
                \PhpJs\Object\PropertyDescriptor::data($value, true, false, true),
            );
        }
    }

    /** Define a binding that can be removed with `delete`. Used for built-in globals. */
    public function defineDeletable(string $name, JsValue $value): void
    {
        $this->bindings[$name] = $value;
        $this->deletable[$name] = true;
    }

    /** Define a let-declared variable (block-scoped, initialized). */
    public function defineLet(string $name, JsValue $value): void
    {
        unset($this->tdz[$name]);
        $this->bindings[$name] = $value;
    }

    /** Define a const-declared variable (block-scoped, initialized, immutable). */
    public function defineConst(string $name, JsValue $value): void
    {
        unset($this->tdz[$name]);
        $this->bindings[$name] = $value;
        $this->constants[$name] = true;
    }

    /** Declare a let binding without initializing it (enters TDZ). */
    public function declareLet(string $name): void
    {
        $this->tdz[$name] = true;
        $this->bindings[$name] = JsUndefined::instance();
    }

    /** Declare a const binding without initializing it (enters TDZ). */
    public function declareConst(string $name): void
    {
        $this->tdz[$name] = true;
        $this->constants[$name] = true;
        $this->bindings[$name] = JsUndefined::instance();
    }

    /** Initialize a previously declared TDZ binding. */
    public function initialize(string $name, JsValue $value): void
    {
        if (!isset($this->tdz[$name])) {
            throw new ReferenceError("Binding '{$name}' is not in the temporal dead zone");
        }

        unset($this->tdz[$name]);
        $this->bindings[$name] = $value;
    }

    /** Get a binding value, walking the scope chain. Throws on TDZ access or missing binding. */
    public function get(string $name): JsValue
    {
        if (array_key_exists($name, $this->bindings)) {
            if (isset($this->tdz[$name])) {
                throw new ReferenceError("Cannot access '{$name}' before initialization");
            }

            return $this->bindings[$name];
        }

        if ($this->parent !== null) {
            return $this->parent->get($name);
        }

        // At the global (root) environment. If a linked global object exists,
        // check it for properties set directly (e.g. this.color = "red").
        // This mirrors the ES spec behavior where properties on the global
        // object are accessible as global variables.
        if ($this->linkedObject !== null && $this->linkedObject->hasOwnProperty($name)) {
            return $this->linkedObject->get($name);
        }

        throw new ReferenceError("{$name} is not defined");
    }

    /**
     * Set a binding value, walking the scope chain.
     *
     * In strict mode, throws ReferenceError if the binding is not found.
     * In sloppy mode, creates the binding on the global (root) environment
     * when not found anywhere in the chain.
     */
    public function set(string $name, JsValue $value, bool $strict = true): void
    {
        if (array_key_exists($name, $this->bindings)) {
            if (isset($this->tdz[$name])) {
                throw new ReferenceError("Cannot access '{$name}' before initialization");
            }

            if (isset($this->constants[$name])) {
                throw new TypeError('Assignment to constant variable');
            }

            $this->bindings[$name] = $value;
            // Sync to the linked global object when the binding is in the global env.
            if ($this->linkedObject !== null
                && $name !== 'this' && $name !== 'globalThis'
                && !(str_starts_with($name, '__') && str_ends_with($name, '__'))
            ) {
                if ($this->linkedObject->hasOwnProperty($name)) {
                    $this->linkedObject->set($name, $value);
                } else {
                    $this->linkedObject->defineOwnProperty(
                        $name,
                        \PhpJs\Object\PropertyDescriptor::data($value, true, false, true),
                    );
                }
            }
            return;
        }

        if ($this->parent !== null) {
            $this->parent->set($name, $value, $strict);
            return;
        }

        // At the global (root) environment and the binding was not found.
        if ($strict) {
            throw new ReferenceError("{$name} is not defined");
        }

        // Sloppy mode: implicitly create a global variable (deletable).
        $this->bindings[$name] = $value;
        $this->deletable[$name] = true;
        if ($this->linkedObject !== null) {
            $this->linkedObject->defineOwnProperty(
                $name,
                \PhpJs\Object\PropertyDescriptor::data($value, true, true, true),
            );
        }
    }

    /** Check whether a binding exists anywhere in the scope chain. */
    public function has(string $name): bool
    {
        if (array_key_exists($name, $this->bindings)) {
            return true;
        }

        if ($this->parent !== null) {
            return $this->parent->has($name);
        }

        // Check the linked global object for directly-set properties.
        if ($this->linkedObject !== null && $this->linkedObject->hasOwnProperty($name)) {
            return true;
        }

        return false;
    }

    /**
     * Delete a binding, if it is deletable (implicit globals only).
     *
     * Returns true if the binding was deleted or didn't exist.
     * Returns false if the binding exists but is not deletable (declared vars/funcs).
     */
    public function deleteBinding(string $name): bool
    {
        if (array_key_exists($name, $this->bindings)) {
            if (isset($this->deletable[$name])) {
                unset($this->bindings[$name], $this->deletable[$name]);
                return true;
            }
            // Declared bindings are not deletable.
            return false;
        }

        if ($this->parent !== null) {
            return $this->parent->deleteBinding($name);
        }

        // Not found anywhere: returning true per spec (unresolvable reference).
        return true;
    }

    public function getParent(): ?Environment
    {
        return $this->parent;
    }

    /** @return array<string, JsValue> All bindings in this scope (not parents). */
    public function allBindings(): array
    {
        return $this->bindings;
    }

    /** Create a child environment with this environment as its parent. */
    public function createChild(): self
    {
        return new self($this);
    }
}
