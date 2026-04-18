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

    /** @var array<string, bool> Track which bindings are lexical (let/const/class). */
    private array $lexical = [];

    /** @var array<string, bool> Track which bindings are deletable (implicit globals). */
    private array $deletable = [];

    /** @var array<string, bool> Track Annex B block-function hoisted var bindings. */
    private array $annexBHoisted = [];

    /**
     * When set, var declarations and assignments in this environment
     * also create/update properties on the linked object. Used for
     * the global environment to keep globalThis in sync with var bindings.
     */
    private ?\PhpJs\Value\JsObject $linkedObject = null;

    /**
     * When set, this environment is a "with" object environment record.
     * Variable lookups first check this object (using [[Has]]) before
     * falling through to the parent scope. This enables Proxy has traps.
     */
    private ?\PhpJs\Value\JsObject $withObject = null;

    /**
     * Tracks the kind of function this environment belongs to.
     * Used by EvalDeclarationInstantiation to enforce restrictions on
     * var arguments declarations in generators and arrow functions.
     * Possible values: null, 'generator', 'async-generator', 'arrow', 'function'.
     */
    private ?string $functionKind = null;

    /**
     * Maps source-level private names (#name) to branded private names (#name@{brandId}).
     * Each evaluation of a class body creates a unique brand, so that private fields
     * from different evaluations of the same class expression are distinct.
     *
     * @var array<string, string>
     */
    private array $privateNameMap = [];

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

    /** Check whether a binding exists in this scope only (not parents). */
    public function hasOwnBinding(string $name): bool
    {
        return array_key_exists($name, $this->bindings);
    }

    /** Check whether a lexical (let/const/class) binding exists in this scope only. */
    public function hasLexicalBinding(string $name): bool
    {
        return isset($this->lexical[$name]);
    }

    /** Define a var-declared variable in the current environment. */
    public function defineVar(string $name, JsValue $value): void
    {
        unset($this->tdz[$name]);
        $this->bindings[$name] = $value;
        // Sync to the linked global object if present.
        // Skip internal bindings that start with __ (prototypes, etc.)
        // and the special 'this'/'globalThis' bindings.
        if (
            $this->linkedObject !== null
            && $name !== 'this' && $name !== 'globalThis'
            && !(str_starts_with($name, '__') && str_ends_with($name, '__'))
        ) {
            $this->linkedObject->defineOwnProperty(
                $name,
                \PhpJs\Object\PropertyDescriptor::data($value, true, false, true),
            );
        }
    }

    /**
     * Define a user-declared var/function in global scope.
     *
     * Per spec (CreateGlobalVarBinding / CreateGlobalFunctionBinding), variables
     * declared with `var` or `function` at the top level create enumerable,
     * non-configurable properties on the global object (unlike built-ins which are
     * non-enumerable and configurable). If the property already exists, only the
     * value is updated, preserving the existing descriptor.
     */
    public function defineGlobalVar(string $name, JsValue $value, bool $configurable = false): void
    {
        $this->bindings[$name] = $value;
        if ($this->linkedObject !== null) {
            $existingProp = $this->linkedObject->getOwnPropertyDescriptor($name);
            if ($existingProp === null) {
                // New property: {[[Writable]]: true, [[Enumerable]]: true, [[Configurable]]: D}
                // D is false for script-level declarations, true for eval-created ones.
                $this->linkedObject->defineOwnProperty(
                    $name,
                    \PhpJs\Object\PropertyDescriptor::data($value, true, true, $configurable),
                );
            } elseif ($existingProp->configurable) {
                // Per spec CreateGlobalFunctionBinding step 5: if existing prop is
                // configurable, replace with full descriptor.
                $this->linkedObject->defineOwnProperty(
                    $name,
                    \PhpJs\Object\PropertyDescriptor::data($value, true, true, $configurable),
                );
            } else {
                // Per spec step 6: non-configurable existing property. Just update value.
                $this->linkedObject->set($name, $value);
            }
        }
        // Track configurability for deletability.
        if ($configurable) {
            $this->deletable[$name] = true;
        }
    }

    /** Define a binding that can be removed with `delete`. Used for built-in globals. */
    public function defineDeletable(string $name, JsValue $value): void
    {
        $this->bindings[$name] = $value;
        $this->deletable[$name] = true;
    }

    /**
     * Define a var binding created by Annex B block-function hoisting.
     *
     * @param bool $configurable For global scope: whether the property should be
     *     configurable. Scripts use false, eval uses true.
     */
    public function defineAnnexBVar(string $name, JsValue $value, bool $configurable = false): void
    {
        if (!array_key_exists($name, $this->bindings)) {
            $this->bindings[$name] = $value;
            // For global scope (linked object), also create the property.
            // Per spec, scripts use CreateGlobalFunctionBinding(F, undefined, false)
            // and eval uses CreateGlobalVarBinding(F, true).
            if ($this->linkedObject !== null && !$this->linkedObject->hasOwnProperty($name)) {
                $this->linkedObject->defineOwnProperty(
                    $name,
                    \PhpJs\Object\PropertyDescriptor::data($value, true, true, $configurable),
                );
                if ($configurable) {
                    $this->deletable[$name] = true;
                }
            }
        }
        $this->annexBHoisted[$name] = true;
    }

    /** Check whether a binding was created by Annex B block-function hoisting. */
    public function isAnnexBHoisted(string $name): bool
    {
        return isset($this->annexBHoisted[$name]);
    }

    /** Mark an existing binding as Annex B hoisted without changing its value. */
    public function markAnnexBHoisted(string $name): void
    {
        $this->annexBHoisted[$name] = true;
    }

    /** Define a let-declared variable (block-scoped, initialized). */
    public function defineLet(string $name, JsValue $value): void
    {
        unset($this->tdz[$name]);
        $this->bindings[$name] = $value;
        $this->lexical[$name] = true;
    }

    /** Define a const-declared variable (block-scoped, initialized, immutable). */
    public function defineConst(string $name, JsValue $value): void
    {
        unset($this->tdz[$name]);
        $this->bindings[$name] = $value;
        $this->constants[$name] = true;
        $this->lexical[$name] = true;
    }

    /** Declare a let binding without initializing it (enters TDZ). */
    public function declareLet(string $name): void
    {
        $this->tdz[$name] = true;
        $this->lexical[$name] = true;
        $this->bindings[$name] = JsUndefined::instance();
    }

    /** Declare a const binding without initializing it (enters TDZ). */
    public function declareConst(string $name): void
    {
        $this->tdz[$name] = true;
        $this->constants[$name] = true;
        $this->lexical[$name] = true;
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

    /**
     * Initialize a binding if it's currently in TDZ, otherwise define it as a var.
     * Used during parameter binding when params were pre-declared as TDZ.
     */
    public function initializeTdz(string $name, JsValue $value): void
    {
        if (isset($this->tdz[$name])) {
            unset($this->tdz[$name]);
            $this->bindings[$name] = $value;
            if (
                $this->linkedObject !== null
                && $name !== 'this' && $name !== 'globalThis'
                && !(str_starts_with($name, '__') && str_ends_with($name, '__'))
            ) {
                $this->linkedObject->defineOwnProperty(
                    $name,
                    \PhpJs\Object\PropertyDescriptor::data($value, true, false, true),
                );
            }
        } else {
            $this->defineVar($name, $value);
        }
    }

    /**
     * Get a binding value, walking the scope chain.
     * The optional $strict parameter controls the GetBindingValue
     * behavior for Object Environment Records (with statements):
     * when true, a missing binding throws ReferenceError per spec
     * 9.1.1.2.6 step 3a.
     */
    public function get(string $name, bool $strict = false): JsValue
    {
        // "with" object environment record: HasBinding + GetBindingValue.
        if ($this->withObject !== null) {
            // HasBinding: HasProperty then @@unscopables.
            if ($this->withObject->has($name) && !$this->isUnscopable($name)) {
                // GetBindingValue (9.1.1.2.6): a separate HasProperty check
                // is required per spec. The @@unscopables getter above can
                // have side effects (e.g. deleting the property), and Proxy
                // traps must fire independently for each spec step.
                return $this->getBindingValueFromWith($name, $strict);
            }
            // Not found or unscopable: fall through to parent.
            if ($this->parent !== null) {
                return $this->parent->get($name, $strict);
            }
            throw new ReferenceError("{$name} is not defined");
        }

        if (array_key_exists($name, $this->bindings)) {
            if (isset($this->tdz[$name])) {
                throw new ReferenceError("Cannot access '{$name}' before initialization");
            }

            // Per ES spec, the global environment uses an Object Environment Record
            // for var/function bindings. These are backed by the global object.
            // When the global object property is updated directly (e.g. this.x = ...),
            // the environment binding may be stale. Prefer the global object value
            // for non-const non-TDZ non-lexical bindings that have a matching property.
            // Lexical bindings (let/const) take precedence over the global object.
            if (
                $this->linkedObject !== null
                && $this->parent === null
                && !isset($this->constants[$name])
                && !isset($this->lexical[$name])
                && $this->linkedObject->hasOwnProperty($name)
            ) {
                return $this->linkedObject->get($name);
            }

            return $this->bindings[$name];
        }

        if ($this->parent !== null) {
            return $this->parent->get($name, $strict);
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
        // "with" object environment record: delegate to the binding object.
        if ($this->withObject !== null) {
            if ($this->withObject->has($name) && !$this->isUnscopable($name)) {
                $this->withObject->set($name, $value, $strict);
                return;
            }
            if ($this->parent !== null) {
                $this->parent->set($name, $value, $strict);
                return;
            }
            if ($strict) {
                throw new ReferenceError("{$name} is not defined");
            }
            return;
        }

        if (array_key_exists($name, $this->bindings)) {
            if (isset($this->tdz[$name])) {
                throw new ReferenceError("Cannot access '{$name}' before initialization");
            }

            if (isset($this->constants[$name])) {
                throw new TypeError('Assignment to constant variable');
            }

            // Check global object writability before updating the binding.
            // Per spec, non-writable global properties (NaN, Infinity, undefined)
            // silently fail in sloppy mode and throw TypeError in strict mode.
            if (
                $this->linkedObject !== null
                && $name !== 'this' && $name !== 'globalThis'
                && !(str_starts_with($name, '__') && str_ends_with($name, '__'))
                && $this->linkedObject->hasOwnProperty($name)
            ) {
                $desc = $this->linkedObject->getOwnPropertyDescriptor($name);
                if ($desc !== null && $desc->writable === false) {
                    if ($strict) {
                        throw new TypeError(
                            "Cannot assign to read only property '{$name}'"
                        );
                    }
                    return; // Silently fail in sloppy mode
                }
                $this->bindings[$name] = $value;
                $this->linkedObject->set($name, $value);
            } else {
                $this->bindings[$name] = $value;
            }
            return;
        }

        if ($this->parent !== null) {
            $this->parent->set($name, $value, $strict);
            return;
        }

        // At the global (root) environment and the binding was not found
        // in $this->bindings. Check if the linked global object has this
        // property (e.g. set via Object.defineProperty on the global object).
        if ($this->linkedObject !== null && $this->linkedObject->hasOwnProperty($name)) {
            $desc = $this->linkedObject->getOwnPropertyDescriptor($name);
            if ($desc !== null && $desc->writable === false) {
                if ($strict) {
                    throw new TypeError(
                        "Cannot assign to read only property '{$name}'"
                    );
                }
                return; // Silently fail in sloppy mode
            }
            $this->bindings[$name] = $value;
            $this->linkedObject->set($name, $value);
            return;
        }

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
        // "with" object environment record: delegate to the binding object using [[Has]].
        if ($this->withObject !== null) {
            if ($this->withObject->has($name) && !$this->isUnscopable($name)) {
                return true;
            }
            if ($this->parent !== null) {
                return $this->parent->has($name);
            }
            return false;
        }

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
        // "with" object environment record: delegate delete to the binding object.
        if ($this->withObject !== null) {
            if ($this->withObject->has($name)) {
                return $this->withObject->delete($name);
            }
            if ($this->parent !== null) {
                return $this->parent->deleteBinding($name);
            }
            return true;
        }

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

    /**
     * Per ES spec HasBinding for Object Environment Records (9.1.1.2.1):
     * After checking the binding object has the property, check @@unscopables.
     * If unscopables[name] is truthy, the binding is considered not present.
     */
    private function isUnscopable(string $name): bool
    {
        if ($this->withObject === null) {
            return false;
        }
        $unscopables = $this->withObject->getBySymbol(
            \PhpJs\BuiltIn\SymbolConstructor::unscopables()
        );
        if ($unscopables instanceof \PhpJs\Value\JsObject) {
            $value = $unscopables->get($name);
            return \PhpJs\Spec\TypeConversion::toBoolean($value);
        }
        return false;
    }

    /**
     * Per spec 9.1.1.2.6 GetBindingValue for Object Environment Records:
     * 1. HasProperty(bindingObject, N) - if false, return undefined
     * 2. Get(bindingObject, N)
     * Extracted to a separate method so PHPStan does not flag the
     * second HasProperty check as "always false" (the @@unscopables
     * getter in HasBinding can delete the property as a side effect).
     */
    private function getBindingValueFromWith(
        string $name,
        bool $strict = false,
    ): JsValue {
        assert($this->withObject !== null);
        if (!$this->withObject->has($name)) {
            if ($strict) {
                throw new ReferenceError("{$name} is not defined");
            }
            return JsUndefined::instance();
        }
        return $this->withObject->get($name);
    }

    /** @return array<string, JsValue> All bindings in this scope (not parents). */
    public function allBindings(): array
    {
        return $this->bindings;
    }

    /** Set the function kind for this environment (generator, async-generator, arrow, function). */
    public function setFunctionKind(string $kind): void
    {
        $this->functionKind = $kind;
    }

    /** Get the function kind for this environment. */
    public function getFunctionKind(): ?string
    {
        return $this->functionKind;
    }

    /**
     * Walk the scope chain to find the enclosing function kind.
     * Returns the functionKind of the nearest ancestor environment that has one set,
     * or null if we are at global scope.
     */
    public function getEnclosingFunctionKind(): ?string
    {
        $env = $this;
        while ($env !== null) {
            if ($env->functionKind !== null) {
                return $env->functionKind;
            }
            $env = $env->parent;
        }
        return null;
    }

    /**
     * Find the nearest variable environment (function scope or global scope).
     * Per spec, the VariableEnvironment is the enclosing function scope or
     * the global scope. Block scopes (if, for, try, etc.) are lexical
     * environments, not variable environments.
     */
    public function getVariableEnvironment(): self
    {
        $env = $this;
        while ($env->parent !== null && $env->functionKind === null && $env->linkedObject === null) {
            $env = $env->parent;
        }
        return $env;
    }

    /**
     * Check whether any environment in the scope chain has 'arguments' as a
     * lexically-bound (let/const) or constant binding. Used by
     * EvalDeclarationInstantiation to determine if var arguments is allowed.
     */
    public function hasLexicalArgumentsBinding(): bool
    {
        $env = $this;
        while ($env !== null) {
            if (isset($env->lexical['arguments'])) {
                return true;
            }
            // If this environment is a function boundary, stop.
            if ($env->functionKind !== null) {
                return false;
            }
            $env = $env->parent;
        }
        return false;
    }

    /**
     * Check if a name has a TDZ (let/const) binding in the scope chain
     * between the current environment and the enclosing function's varEnv.
     * Per EvalDeclarationInstantiation, a var declaration in eval must not
     * conflict with existing lexical declarations.
     */
    public function hasLexicalBindingInScope(string $name): bool
    {
        $env = $this;
        while (true) {
            if (isset($env->lexical[$name])) {
                return true;
            }
            // Stop at function boundaries or at the global environment.
            if ($env->functionKind !== null || $env->parent === null) {
                return false;
            }
            $env = $env->parent;
        }
    }

    /**
     * Check whether "arguments" exists as a non-lexical (var) own
     * binding in this environment or any ancestor up to the function
     * boundary. Used by EvalDeclarationInstantiation to detect when
     * eval("var arguments") would conflict with the function's
     * arguments object binding.
     */
    public function hasArgumentsVarBinding(): bool
    {
        $env = $this;
        while ($env !== null) {
            if (
                array_key_exists('arguments', $env->bindings)
                && !isset($env->lexical['arguments'])
            ) {
                return true;
            }
            if ($env->functionKind !== null) {
                return false;
            }
            $env = $env->parent;
        }
        return false;
    }

    /** Create a child environment with this environment as its parent. */
    public function createChild(): self
    {
        return new self($this);
    }

    /**
     * Create a child "with" environment that delegates lookups to
     * the given object. Per ES spec, a with statement creates an
     * Object Environment Record whose binding object is the
     * with-expression value. Variable lookups use [[Has]] on this
     * object, which enables Proxy has/get traps.
     */
    public function createWithEnvironment(\PhpJs\Value\JsObject $obj): self
    {
        $child = new self($this);
        $child->withObject = $obj;
        return $child;
    }

    /**
     * Define private name mappings for a class body. Maps source-level
     * private names to branded names (e.g., #m -> #m@42).
     *
     * @param array<string, string> $map
     */
    public function setPrivateNameMap(array $map): void
    {
        $this->privateNameMap = $map;
    }

    /**
     * Resolve a source-level private name to its branded name.
     * Walks up the scope chain to find the closest class body
     * that defined this private name.
     */
    public function resolvePrivateName(string $sourceName): string
    {
        $env = $this;
        while ($env !== null) {
            if (isset($env->privateNameMap[$sourceName])) {
                return $env->privateNameMap[$sourceName];
            }
            $env = $env->parent;
        }
        // Fallback: no brand found, return the source name as-is.
        return $sourceName;
    }
}
