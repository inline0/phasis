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

    public function __construct(
        private readonly ?Environment $parent = null,
    ) {
    }

    /** Define a var-declared variable in the current environment. */
    public function defineVar(string $name, JsValue $value): void
    {
        $this->bindings[$name] = $value;
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

        throw new ReferenceError("{$name} is not defined");
    }

    /** Set a binding value, walking the scope chain. Throws on const or missing binding. */
    public function set(string $name, JsValue $value): void
    {
        if (array_key_exists($name, $this->bindings)) {
            if (isset($this->tdz[$name])) {
                throw new ReferenceError("Cannot access '{$name}' before initialization");
            }

            if (isset($this->constants[$name])) {
                throw new TypeError('Assignment to constant variable');
            }

            $this->bindings[$name] = $value;
            return;
        }

        if ($this->parent !== null) {
            $this->parent->set($name, $value);
            return;
        }

        throw new ReferenceError("{$name} is not defined");
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

        return false;
    }

    public function getParent(): ?Environment
    {
        return $this->parent;
    }

    /** Create a child environment with this environment as its parent. */
    public function createChild(): self
    {
        return new self($this);
    }
}
