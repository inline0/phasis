<?php

declare(strict_types=1);

namespace PhpJs\Value;

/**
 * Deferred module namespace exotic object per the import-defer
 * Stage 3 proposal.
 *
 * Behaves like a frozen module-namespace object, but lazily evaluates
 * the underlying module the first time any of its observable MOP traps
 * fires (Get, HasProperty, OwnPropertyKeys, GetOwnProperty). The
 * trigger is a single closure registered by the module loader; once
 * fired, it copies the eagerly-evaluated namespace's own properties
 * into this object so subsequent reads short-circuit.
 *
 * Spec references:
 *   - sec-deferred-module-namespace-objects (Stage 3 proposal)
 *   - sec-getmodulenamespace step 4 (deferred branch)
 */
class JsDeferredModuleNamespace extends JsObject
{
    /** @var ?\Closure(self): void */
    private ?\Closure $trigger;

    /** Has the trigger already fired? */
    private bool $evaluated = false;

    /**
     * Whether finalizeDeferredNamespaces has already mirrored the
     * eager namespace's exports and locked this object down. Mirror
     * runs once the module graph is fully linked.
     */
    private bool $finalized = false;

    /**
     * When true, MOP traps go straight to JsObject's implementation
     * without firing the trigger. The module loader sets this during
     * the construction-time mirror pass (where we copy export
     * accessors into the deferred namespace) so the mirror itself
     * doesn't accidentally trigger evaluation.
     */
    private bool $bypassTrigger = false;

    /**
     * Eager namespace whose own-property keys correspond to the
     * module's export bindings. Held so the IsSymbolLikeNamespaceKey
     * fast-path can probe export presence without firing the
     * evaluation trigger when it later needs to.
     *
     * @phpstan-ignore-next-line property.onlyWritten
     */
    private ?JsObject $eagerNamespace = null;

    public function __construct(?\Closure $trigger = null)
    {
        parent::__construct(null);
        // Per spec ModuleNamespaceCreate, [[GetPrototypeOf]] returns
        // null. JsObject's constructor coalesces a null argument to
        // the global prototype, so reset it explicitly.
        $this->setPrototype(null);
        $this->trigger = $trigger;
    }

    public function setEagerNamespace(JsObject $ns): void
    {
        $this->eagerNamespace = $ns;
    }

    public function setBypassTrigger(bool $bypass): void
    {
        $this->bypassTrigger = $bypass;
    }

    public function isFinalized(): bool
    {
        return $this->finalized;
    }

    public function markFinalized(): void
    {
        $this->finalized = true;
    }

    /** Mark the namespace as already populated; skips trigger on access. */
    public function markEvaluated(): void
    {
        $this->evaluated = true;
        $this->trigger = null;
    }

    /**
     * Attach the trigger closure. The closure receives this object so
     * the loader can populate it (defineOwnProperty for each export).
     *
     * @param \Closure(self): void $trigger
     */
    public function setTrigger(\Closure $trigger): void
    {
        $this->trigger = $trigger;
    }

    private function ensureEvaluated(): void
    {
        if ($this->evaluated) {
            return;
        }
        $trigger = $this->trigger;
        if ($trigger === null) {
            $this->evaluated = true;
            return;
        }
        // Run the trigger first, then mark evaluated only if it
        // completed normally. A trigger that throws (e.g. because
        // the module is still on stack) should be re-runnable: the
        // next access must throw the same TypeError, not silently
        // see undefined exports.
        $trigger($this);
        $this->evaluated = true;
        $this->trigger = null;
    }

    /**
     * Per spec IsSymbolLikeNamespaceKey: on a deferred namespace, the
     * key "then" is treated like a Symbol regardless of whether the
     * module exports a name "then" — its observation never fires the
     * evaluation trigger so `await deferredNs` /
     * Promise.resolve(deferredNs) auto-thenable checks stay inert.
     */
    private function shouldSkipTrigger(string $name): bool
    {
        if (self::isInternalSlot($name)) {
            return true;
        }
        if ($name === 'then') {
            return true;
        }
        return false;
    }

    public function get(string $name): JsValue
    {
        if (!$this->bypassTrigger && !$this->shouldSkipTrigger($name)) {
            $this->ensureEvaluated();
        }
        return parent::get($name);
    }

    public function has(string $name): bool
    {
        if (!$this->bypassTrigger && !$this->shouldSkipTrigger($name)) {
            $this->ensureEvaluated();
        }
        return parent::has($name);
    }

    public function hasOwnProperty(string $name): bool
    {
        if (!$this->bypassTrigger && !$this->shouldSkipTrigger($name)) {
            $this->ensureEvaluated();
        }
        return parent::hasOwnProperty($name);
    }

    public function getOwnPropertyDescriptor(
        string $name,
    ): ?\PhpJs\Object\PropertyDescriptor {
        if (!$this->bypassTrigger && !$this->shouldSkipTrigger($name)) {
            $this->ensureEvaluated();
        }
        return parent::getOwnPropertyDescriptor($name);
    }

    public function ownKeys(): array
    {
        if (!$this->bypassTrigger) {
            $this->ensureEvaluated();
        }
        return parent::ownKeys();
    }

    public function ordinaryOwnPropertyKeys(): array
    {
        if (!$this->bypassTrigger) {
            $this->ensureEvaluated();
        }
        return parent::ordinaryOwnPropertyKeys();
    }

    public function getOwnPropertyNames(): array
    {
        if (!$this->bypassTrigger) {
            $this->ensureEvaluated();
        }
        return parent::getOwnPropertyNames();
    }

    public function delete(string $name, bool $strict = false): bool
    {
        if (!$this->bypassTrigger && !$this->shouldSkipTrigger($name)) {
            $this->ensureEvaluated();
        }
        return parent::delete($name, $strict);
    }

    public function defineOwnProperty(string $name, \PhpJs\Object\PropertyDescriptor $desc): bool
    {
        if (!$this->bypassTrigger && !$this->shouldSkipTrigger($name)) {
            $this->ensureEvaluated();
        }
        return parent::defineOwnProperty($name, $desc);
    }
}
