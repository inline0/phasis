<?php

declare(strict_types=1);

namespace Phasis\Value;

use Phasis\Runtime\Environment;

class JsFunction extends JsObject
{
    /** @var null|\Closure(JsFunction, JsValue, list<JsValue>): JsValue */
    private static ?\Closure $interpreterCallback = null;

    /** Static reference to the interpreter, used for private accessor invocation. */
    private static ?\Phasis\Runtime\Interpreter $interpreterInstance = null;

    public static function setInterpreterInstance(\Phasis\Runtime\Interpreter $interp): void
    {
        self::$interpreterInstance = $interp;
    }

    public static function getInterpreterInstance(): ?\Phasis\Runtime\Interpreter
    {
        return self::$interpreterInstance;
    }

    /** Function.prototype: the [[Prototype]] for all function instances. */
    private static ?JsObject $functionPrototype = null;

    /**
     * %GeneratorFunction.prototype% intrinsic: [[Prototype]] for all generator function instances.
     * Its own [[Prototype]] is Function.prototype.
     */
    private static ?JsObject $generatorFunctionPrototype = null;

    /**
     * %AsyncFunction.prototype% intrinsic: [[Prototype]] for all async function instances.
     * Its own [[Prototype]] is Function.prototype. It is a non-callable ordinary object.
     */
    private static ?JsObject $asyncFunctionPrototype = null;

    /** %AsyncGeneratorFunction.prototype% intrinsic. */
    private static ?JsObject $asyncGeneratorFunctionPrototype = null;

    public static function setFunctionPrototype(JsObject $proto): void
    {
        self::$functionPrototype = $proto;
        // Clear the parent prototype so the lazy wiring in getPrototype()
        // always re-probes for the current Engine's Object.prototype.
        // Without this, the prototype inherited from JsObject's constructor
        // would point to a previous Engine's Object.prototype.
        $proto->setPrototype(null);
    }

    public static function setGeneratorFunctionPrototype(JsObject $proto): void
    {
        self::$generatorFunctionPrototype = $proto;
    }

    public static function getGeneratorFunctionPrototype(): ?JsObject
    {
        return self::$generatorFunctionPrototype;
    }

    public static function setAsyncFunctionPrototype(JsObject $proto): void
    {
        self::$asyncFunctionPrototype = $proto;
    }

    public static function getAsyncFunctionPrototype(): ?JsObject
    {
        return self::$asyncFunctionPrototype;
    }

    public static function setAsyncGeneratorFunctionPrototype(
        JsObject $proto
    ): void {
        self::$asyncGeneratorFunctionPrototype = $proto;
    }

    public static function getAsyncGeneratorFunctionPrototype(): ?JsObject
    {
        return self::$asyncGeneratorFunctionPrototype;
    }

    public static function setInterpreterCallback(callable $callback): void
    {
        self::$interpreterCallback = $callback instanceof \Closure
            ? $callback
            : \Closure::fromCallable($callback);
    }

    public static function getInterpreterCallback(): ?\Closure
    {
        return self::$interpreterCallback;
    }

    /** @var list<mixed> AST param nodes or empty for native. */
    private array $params;
    private mixed $body;
    /**
     * Closure environment captured at function-creation time. Public
     * so hot VM paths (executeVmFunctionDirect, MAKE_FUNCTION's
     * follow-up reads) can access the field directly without paying
     * for the getClosure() method dispatch each call. setClosure()
     * is the only writer; external code must not mutate this field.
     */
    public Environment $closure;
    /**
     * Function name. Public so executeVmFunctionDirect can pass it
     * to callStack->push without the getName() dispatch. Use
     * setName() to update — it also keeps the spec-mandated `name`
     * property descriptor in sync with this field.
     */
    public string $name;
    /**
     * isArrow flag. Public so the VM's call fast-path can skip the
     * sloppy-this coercion for arrow callees with one direct field
     * read instead of a getter dispatch.
     */
    public bool $isArrow;
    private bool $isGenerator;
    private bool $isAsync;
    private ?JsValue $boundThis;
    private ?\Closure $nativeCallable = null;
    private bool $constructable = true;

    /** True for class constructors: calling without new throws TypeError. */
    private bool $isClassConstructor = false;

    /**
     * Module path of the defining script/module. Captured at function
     * creation when running inside a module body so that import.meta
     * inside the function body resolves to the module that defined it,
     * not the caller's module (per GetActiveScriptOrModule semantics).
     * Null for functions defined at script (non-module) scope.
     */
    public ?string $definingModulePath = null;

    /** True for derived class constructors (class C extends B). */
    private bool $isDerivedConstructor = false;

    /**
     * [[HomeObject]] internal slot: the object this method was defined on.
     * Set when a method is installed on a class prototype or object literal.
     * Used to resolve super property references.
     */
    private ?JsObject $homeObject = null;

    /** [[BoundTargetFunction]]: the original target for bound functions. */
    private ?JsFunction $boundTarget = null;

    /** Original source text for Function.prototype.toString(). Null for native functions. */
    private ?string $sourceText = null;

    /**
     * Instance field initializers for class constructors.
     * Each entry is [key (string|JsSymbol|Node), initNode (Node|null), computed (bool), isPrivate (bool)].
     * The key can be a string (literal/identifier name), a JsSymbol (well-known
     * symbol literal), or a Node (computed expression that evaluates per-instance).
     *
     * @var list<array{0: string|\Phasis\Ast\Node|JsSymbol, 1: ?\Phasis\Ast\Node, 2: bool, 3: bool}>
     */
    private array $instanceFieldInitializers = [];

    /**
     * Private method/accessor entries for class constructors.
     * Each entry is [name (string), value (JsFunction), kind ('method'|'get'|'set')].
     *
     * @var list<array{0: string, 1: JsFunction, 2: string}>
     */
    private array $privateMethodEntries = [];

    /**
     * The private name environment for this class constructor. Used when
     * initializing instance fields so that field initializer expressions
     * can resolve branded private names.
     */
    private ?Environment $privateEnv = null;

    /**
     * When true, this function uses the prototype set via JsObject::setPrototype()
     * instead of the global Function.prototype. Used for intrinsic constructors
     * like %TypedArray% subtypes whose [[Prototype]] is %TypedArray% per spec.
     */
    private bool $hasCustomPrototype = false;

    /** Whether this function was defined in (or has) strict mode. */
    private bool $strict = false;

    /**
     * [[Realm]] internal slot: the Engine (realm) this function belongs to.
     * Set at construction from the currently-active Engine. Used by
     * GetFunctionRealm so cross-realm constructs (Reflect.construct,
     * GetPrototypeFromConstructor) can look up the correct realm's
     * intrinsic prototypes.
     */
    public ?\Phasis\Engine $realm = null;

    /**
     * @param list<mixed> $params AST param nodes.
     * @param mixed $body AST node (BlockStatement or expression).
     */
    public function __construct(
        string $name,
        array $params,
        mixed $body,
        Environment $closure,
        bool $isArrow = false,
        bool $isGenerator = false,
        bool $isAsync = false,
        ?JsValue $boundThis = null,
        ?JsObject $prototype = null,
        bool $strict = false,
    ) {
        parent::__construct($prototype);
        // Tag with the currently-active realm. Engine::getCurrentRealm
        // resolves to whichever Engine's interpreter is on the stack
        // (i.e. the realm that's installing this function).
        $this->realm = \Phasis\Engine::getCurrentRealm();
        $this->strict = $strict;
        $this->name = $name;
        $this->params = $params;
        $this->body = $body;
        $this->closure = $closure;
        $this->isArrow = $isArrow;
        $this->isGenerator = $isGenerator;
        $this->isAsync = $isAsync;
        $this->boundThis = $boundThis;

        // Function.length: number of params before first rest/default param
        $length = 0;
        foreach ($params as $p) {
            if ($p === null) {
                $length++;
            } elseif ($p instanceof \Phasis\Ast\Pattern\RestElement) {
                break;
            } elseif ($p instanceof \Phasis\Ast\Pattern\AssignmentPattern) {
                break;
            } else {
                $length++;
            }
        }
        $this->defineOwnProperty('length', new \Phasis\Object\PropertyDescriptor(
            value: JsNumber::of((float) $length),
            writable: false,
            enumerable: false,
            configurable: true,
        ));
        // Per spec, the exposed .name is "" for anonymous functions.
        // Internally we keep '(anonymous)' as a sentinel for name inference.
        $exposedName = $name === '(anonymous)' ? '' : $name;
        $this->defineOwnProperty('name', new \Phasis\Object\PropertyDescriptor(
            value: new JsString($exposedName),
            writable: false,
            enumerable: false,
            configurable: true,
        ));
    }

    /**
     * Create a host function from a PHP callable.
     * Native built-in functions are non-constructable by default per spec.
     */
    public static function fromCallable(string $name, callable $fn, int $length = 0): self
    {
        // Create a dummy environment for native functions
        $instance = new self($name, array_fill(0, $length, null), null, new Environment());
        $instance->nativeCallable = $fn(...);
        // Native built-ins are not constructable by default (spec §10.3).
        $instance->constructable = false;
        return $instance;
    }

    /**
     * Mark this function as constructable (can be invoked with `new`).
     * Use for built-in constructor functions (Array, Object, etc.).
     */
    public function setConstructable(): self
    {
        $this->constructable = true;
        return $this;
    }

    /**
     * Mark this function as non-constructable (cannot be invoked with `new`).
     */
    public function setNonConstructable(): self
    {
        $this->constructable = false;
        return $this;
    }

    /** Mark as a class constructor: calling without `new` throws TypeError. */
    public function setClassConstructor(bool $derived = false): self
    {
        $this->isClassConstructor = true;
        $this->isDerivedConstructor = $derived;
        return $this;
    }

    public function isClassConstructor(): bool
    {
        return $this->isClassConstructor;
    }

    public function isDerivedConstructor(): bool
    {
        return $this->isDerivedConstructor;
    }

    /** Set the [[HomeObject]] for super property resolution. */
    public function setHomeObject(JsObject $homeObject): self
    {
        $this->homeObject = $homeObject;
        return $this;
    }

    public function getHomeObject(): ?JsObject
    {
        return $this->homeObject;
    }

    /**
     * Add an instance field initializer for this class constructor.
     *
     * @param string|\Phasis\Ast\Node|JsSymbol $key
     */
    public function addInstanceFieldInitializer(mixed $key, ?\Phasis\Ast\Node $initNode, bool $computed, bool $isPrivate): void
    {
        $this->instanceFieldInitializers[] = [$key, $initNode, $computed, $isPrivate];
    }

    /** @return list<array{0: string|\Phasis\Ast\Node|JsSymbol, 1: ?\Phasis\Ast\Node, 2: bool, 3: bool}> */
    public function getInstanceFieldInitializers(): array
    {
        return $this->instanceFieldInitializers;
    }

    /**
     * Add a private method/accessor entry for this class constructor.
     */
    public function addPrivateMethodEntry(string $name, JsFunction $value, string $kind): void
    {
        $this->privateMethodEntries[] = [$name, $value, $kind];
    }

    /** @return list<array{0: string, 1: JsFunction, 2: string}> */
    public function getPrivateMethodEntries(): array
    {
        return $this->privateMethodEntries;
    }

    /** Store the private name environment for this class constructor. */
    public function setPrivateEnv(Environment $env): void
    {
        $this->privateEnv = $env;
    }

    /** Get the private name environment for this class constructor. */
    public function getPrivateEnv(): ?Environment
    {
        return $this->privateEnv;
    }

    /**
     * Set a custom [[Prototype]] for this function, overriding the default
     * Function.prototype. Per spec, intrinsic constructors like typed array
     * subtypes have [[Prototype]] set to their parent intrinsic.
     */
    public function setCustomPrototype(JsObject $proto): self
    {
        $this->setPrototype($proto);
        $this->hasCustomPrototype = true;
        return $this;
    }

    /**
     * Override setPrototype so that explicit [[Prototype]] mutations (e.g. via
     * Object.setPrototypeOf) are honoured by getPrototype(). Without this override,
     * getPrototype() would always return the static $functionPrototype and ignore
     * the value stored by the parent class.
     */
    public function setPrototype(?JsObject $prototype): void
    {
        parent::setPrototype($prototype);
        $this->hasCustomPrototype = true;
    }

    public function isConstructable(): bool
    {
        // Arrow functions are not constructable.
        if ($this->isArrow) {
            return false;
        }
        return $this->constructable;
    }

    /**
     * Per spec, all function instances have Function.prototype as their [[Prototype]].
     * This override returns the stored Function.prototype for regular function instances
     * while letting Function.prototype itself chain to Object.prototype via the parent.
     *
     * Function.prototype is created during GlobalObject::install (before Object.prototype
     * exists), so its own [[Prototype]] starts as null. On the first getPrototype() call
     * to fnProto after Object.prototype has been installed, we lazily wire the chain:
     * Function.prototype -> Object.prototype.
     */
    public function getPrototype(): ?JsObject
    {
        if (self::$functionPrototype !== null && $this !== self::$functionPrototype && !$this->hasCustomPrototype) {
            // Realm-aware lookup: prefer the function's own [[Realm]] for
            // Function.prototype / GeneratorFunction.prototype / etc.
            // intrinsics. Without this, sibling Engines overwrite the
            // process-wide static and parent-realm functions reach for
            // the child's Function.prototype.call, routing all dispatch
            // through the child interpreter.
            if ($this->realm !== null) {
                $env = $this->realm->getGlobalEnv();
                if (
                    $this->isAsync
                    && $this->isGenerator
                    && $env->has('__AsyncGeneratorFunctionPrototype__')
                ) {
                    $p = $env->get('__AsyncGeneratorFunctionPrototype__');
                    if ($p instanceof JsObject && $this !== $p) {
                        return $p;
                    }
                }
                if (
                    $this->isGenerator
                    && !$this->isAsync
                    && $env->has('__GeneratorFunctionPrototype__')
                ) {
                    $p = $env->get('__GeneratorFunctionPrototype__');
                    if ($p instanceof JsObject && $this !== $p) {
                        return $p;
                    }
                }
                if (
                    $this->isAsync
                    && !$this->isGenerator
                    && $env->has('__AsyncFunctionPrototype__')
                ) {
                    $p = $env->get('__AsyncFunctionPrototype__');
                    if ($p instanceof JsObject) {
                        return $p;
                    }
                }
                if ($env->has('__FunctionPrototype__')) {
                    $p = $env->get('__FunctionPrototype__');
                    if ($p instanceof JsObject) {
                        return $p;
                    }
                }
            }
            // Fall back to the process-wide statics (last-write-wins).
            if (
                $this->isAsync
                && $this->isGenerator
                && self::$asyncGeneratorFunctionPrototype !== null
                && $this !== self::$asyncGeneratorFunctionPrototype
            ) {
                return self::$asyncGeneratorFunctionPrototype;
            }
            if (
                $this->isGenerator
                && !$this->isAsync
                && self::$generatorFunctionPrototype !== null
                && $this !== self::$generatorFunctionPrototype
            ) {
                return self::$generatorFunctionPrototype;
            }
            if (
                $this->isAsync
                && !$this->isGenerator
                && self::$asyncFunctionPrototype !== null
            ) {
                return self::$asyncFunctionPrototype;
            }
            return self::$functionPrototype;
        }
        // For Function.prototype itself, lazily wire to Object.prototype.
        // This must re-probe on every Engine creation because the static
        // $functionPrototype is shared across Engine instances.
        if (self::$functionPrototype !== null && $this === self::$functionPrototype) {
            $currentProto = parent::getPrototype();
            if ($currentProto !== null) {
                return $currentProto;
            }
            // Object.prototype is set via JsObject::setGlobalPrototype after
            // ObjectConstructor::install. Probe it by creating a scratch object.
            // The probe uses a marker to avoid recursion and stale references.
            $probe = new JsObject();
            $objProto = $probe->getPrototype();
            if ($objProto !== null) {
                $this->setPrototype($objProto);
                return $objProto;
            }
            return null;
        }
        return parent::getPrototype();
    }

    public function getNativeCallable(): ?\Closure
    {
        return $this->nativeCallable;
    }

    /** @return list<mixed> AST param nodes. */
    public function getParams(): array
    {
        return $this->params;
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    public function getClosure(): Environment
    {
        return $this->closure;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->defineOwnProperty('name', new \Phasis\Object\PropertyDescriptor(
            value: new JsString($name),
            writable: false,
            enumerable: false,
            configurable: true,
        ));
    }

    public function isStrict(): bool
    {
        return $this->strict;
    }

    /**
     * Memoised: this function's effective strict mode at call time —
     * true when the parser flagged it strict OR a body-level
     * `"use strict"` directive promotes it. Hot enough on recursive
     * calls (fib-style) that the body-prologue scan was showing up.
     * Lazy-set by the interpreter and never invalidated.
     */
    public ?bool $effectiveStrictCache = null;

    /**
     * Memoised: true when the function body references the `arguments`
     * identifier or uses `eval`. When false, executeFunction can skip
     * the (expensive) arguments-object construction entirely. Lazy-set
     * by the interpreter on first call.
     */
    public ?bool $bodyUsesArgumentsCache = null;

    /**
     * Memoised: whether this function tracks the Annex B legacy
     * `caller`/`arguments` own slots during a call. Constant for the
     * function (depends only on strict/arrow/native/async/generator/
     * class-constructor/constructable flags, all immutable on a
     * JsFunction). Lazy-set on first call.
     */
    public ?bool $setCallerPropCache = null;

    /**
     * Sticky flag: true when JS code (or the engine, on a previous
     * tick) has installed a `caller` or `arguments` own descriptor on
     * this function. When false, setupInlineVmCall and
     * executeFunction skip the entire Annex B maintenance block — the
     * two `getOwnPropertyDescriptor` lookups per dispatch + the
     * isEngineDefault* checks add up over millions of calls on
     * constructor-heavy code. Flipped to true by the function's
     * defineOwnProperty when name === 'caller' || 'arguments', which
     * is the only way the descriptor enters $properties. Once set,
     * stays set: we don't downgrade even if the descriptor is
     * configured-off, because the spec semantics of caller-write +
     * subsequent-call require continued maintenance.
     */
    public bool $annexBDescriptorsObserved = false;

    /**
     * Cached "the VM CALL op can dispatch this function via its
     * phpCompiled closure directly" eligibility. Set on first hit
     * once `phpCompiled` is populated AND none of the disqualifiers
     * apply (class ctor, derived ctor, home object). Saves four
     * field reads + one comparison on the hot CALL path: each call
     * site that hits the JIT direct dispatch reads this single
     * boolean instead of re-running the guard ladder. Initialised
     * lazily because `phpCompiled` only becomes non-null after the
     * first call's compile attempt — checking eligibility before
     * then would memoise a false negative.
     */
    public ?bool $phpCompiledDispatchCache = null;

    /**
     * Memoised: true when the function body references `new.target`
     * (represented as the synthetic `[[NewTarget]]` identifier) or
     * makes a `super(...)` call. When false, the VM's inline-
     * construct path can skip installing `[[NewTarget]]` on the
     * fresh receiver AND the matching forceDelete on the RET path —
     * a defineOwnProperty + delete pair per construct. ctor-heavy
     * (50 k Point allocations) sits firmly in this skip-able bucket.
     */
    public ?bool $bodyUsesNewTargetCache = null;

    /**
     * Override JsObject::defineOwnProperty so the Annex B observed flag
     * flips the first time a `caller` or `arguments` descriptor lands
     * on this function. This is the only entry point through which the
     * descriptors can be installed (Object.defineProperty, the engine's
     * own maintenance writes in setupInlineVmCall, the function's
     * built-in install hook all route here). The flag stays false for
     * the vast majority of functions, which lets the dispatch path
     * skip the per-call descriptor reads entirely.
     */
    public function defineOwnProperty(string $name, \Phasis\Object\PropertyDescriptor $desc): bool
    {
        if (!$this->annexBDescriptorsObserved && ($name === 'caller' || $name === 'arguments')) {
            $this->annexBDescriptorsObserved = true;
        }
        return parent::defineOwnProperty($name, $desc);
    }

    /**
     * Memoised: true when the function body references the `arguments`
     * Identifier (outside nested non-arrow function/class/method bodies)
     * at a textual offset earlier than the first block-scoped
     * `function arguments() {}` declaration. When true, the implicit
     * arguments object stays bound and Annex B legacy hoisting for
     * `arguments` must be suppressed (test262
     * annexB/language/function-code/block-decl-func-skip-arguments).
     * When false, no such pre-block reference exists; Annex B hoisting
     * may proceed (staging/sm/regress/regress-602621).
     */
    public ?bool $blockArgumentsAnnexBSuppressedCache = null;

    /**
     * Memoised: whether the body contains anything that would change
     * environment bindings during prologue (var/let/const/using
     * declarations, function declarations, class declarations) or
     * that the hoist-pass walkers descend into looking for nested
     * hoistables. Functions like `fib(n)` whose body is `return ...`
     * trip none of the hoist passes and can skip them entirely.
     */
    public ?bool $bodyNeedsHoistingCache = null;

    /**
     * Bytecode-compilation cache. Compiler attempts to lower the body
     * on first call: success populates $compiled; structural bailouts
     * (eval/with/generators/async/etc.) leave $compiled null AND set
     * $compileFailed so the second call avoids the wasted attempt.
     */
    public ?\Phasis\Bytecode\CompiledFunction $compiled = null;
    public bool $compileFailed = false;

    /**
     * JS-to-PHP source compilation cache. When the body is a tight
     * numeric subset that JsToPhp can lower, this stores the eval'd
     * PHP closure that runs the body natively (raw doubles, no VM
     * dispatch). Cleared / never set on bailouts; phpCompileFailed
     * prevents pointless recompile attempts on subsequent calls.
     */
    public ?\Closure $phpCompiled = null;
    public bool $phpCompileFailed = false;

    /**
     * Numeric-mode entry point: a parallel compiled body that takes
     * raw PHP float args (in $rawArgs) and returns a raw PHP float.
     * Skips JsNumber allocation on both ends of the call boundary
     * for hot JsToPhp-to-JsToPhp dispatch (fib recursion, closure
     * benchmark's add5 loop). Only emitted when the body's profile
     * is statically all-numeric: every param is numeric, the return
     * site produces a numeric expression, no nested non-numeric ops.
     *
     * Caller pattern (also emitted by JsToPhp):
     *   $result = $fn->phpCompiledNumeric !== null
     *       ? ($fn->phpCompiledNumeric)([$rawArg0, ...], $fn->closure, $interp, $fn->phpCompiledNodes)
     *       : ... fallback through phpCompiled ...;
     *
     * Bailout still works: numeric body throws Bailout for any
     * runtime mismatch, caught upstream and routed to the spec path.
     */
    public ?\Closure $phpCompiledNumeric = null;

    /**
     * AST nodes referenced by phpCompiled — currently only nested
     * FunctionDeclaration / FunctionExpression nodes that the closure
     * materialises into JsFunctions at run time via vmMakeFunction.
     * The closure receives this array as its 4th argument and looks
     * up nodes by integer index. Empty for closures without nested
     * function constructions.
     *
     * @var list<\Phasis\Ast\Node>
     */
    public array $phpCompiledNodes = [];

    /**
     * Tag identifying a hot built-in function whose VM call site can
     * inline the host operation directly instead of going through the
     * spec-faithful native callable. Set when constructing the
     * built-in (e.g. 'array.push'), checked in Op::CALL_METHOD before
     * the fall-through to callFunction. Null for non-tagged functions.
     */
    public ?string $builtinKind = null;

    /**
     * Memoised: whether VM Op::CALL can dispatch this function via
     * Interpreter::executeVmFunctionDirect (the inlined fast path) or
     * must fall back to callFunction. Computed lazily after the
     * callee's $compiled is populated, since canSkipEnvAlloc lives on
     * CompiledFunction. Once true, all the JsFunction flag reads
     * collapse to a single bool fetch on the hot CALL path.
     */
    public ?bool $vmDirectDispatchCache = null;

    /**
     * Stricter sibling of `$vmDirectDispatchCache`: also requires
     * the function to NOT be a bind() result. Cached separately so
     * the VM's inline-call hot path (custom callstack) collapses
     * to a single bool read instead of `eligible && !bound`.
     */
    public ?bool $vmInlineCallCache = null;

    /**
     * Memoised eligibility for the inline NEW_CALL path. Mirrors
     * `$vmInlineCallCache` but for constructor calls: additionally
     * requires the callee to be constructable, NOT a derived
     * constructor, and to have no instance-field or private-method
     * initializers (those paths still flow through the canonical
     * `vmNewExpression` so we don't have to replicate the
     * initializer trampoline inside the inline frame). Class ctors
     * without these complications get the same single-bool fast
     * path as regular function calls.
     */
    public ?bool $vmInlineConstructCache = null;

    /**
     * Memoised: results of isNonSimpleParameterList and
     * hasParameterExpressions analyses on this function's parameter
     * list. Both are pure functions of $params, which never changes
     * after construction.
     */
    public ?bool $nonSimpleParamsCache = null;
    public ?bool $hasParamExpressionsCache = null;

    public function isArrow(): bool
    {
        return $this->isArrow;
    }

    public function isGenerator(): bool
    {
        return $this->isGenerator;
    }

    public function isAsync(): bool
    {
        return $this->isAsync;
    }

    public function isNative(): bool
    {
        return $this->nativeCallable !== null;
    }

    public function getBoundThis(): ?JsValue
    {
        return $this->boundThis;
    }

    public function setBoundThis(?JsValue $thisValue): void
    {
        $this->boundThis = $thisValue;
    }

    /**
     * Call this function with a given this-value and arguments.
     *
     * For native (host) functions, invokes the PHP callable directly.
     * For interpreted functions, the interpreter handles invocation;
     * this stub exists so accessor descriptors can reference it.
     *
     * @param list<JsValue> $args
     */
    public function call(JsValue $thisValue, array $args): JsValue
    {
        // Per spec: class constructors cannot be called without `new`.
        // The TypeError comes from the callee's realm so cross-realm
        // class-ctor-realm tests see the matching constructor identity.
        if ($this->isClassConstructor) {
            $calledAsNew = $thisValue instanceof JsObject
                && !($thisValue->get('[[NewTarget]]') instanceof JsUndefined);
            if (!$calledAsNew) {
                $msg = "Class constructor {$this->name} cannot be invoked without 'new'";
                $fnRealm = $this->realm;
                $currentRealm = \Phasis\Engine::getCurrentRealm();
                if ($fnRealm !== null && $fnRealm !== $currentRealm) {
                    throw new \Phasis\Exceptions\JsThrowable(
                        $fnRealm->getInterpreter()->phpExceptionToJsValue(
                            new \Phasis\Exceptions\TypeError($msg)
                        )
                    );
                }
                throw new \Phasis\Exceptions\TypeError($msg);
            }
        }

        if ($this->nativeCallable !== null) {
            // Per spec: PHP RuntimeError / SyntaxError escaping a native
            // body of a cross-realm function must surface as the callee
            // realm's intrinsic. otherFn.call(undefined, …) -> other.
            // TypeError. JSON.rawJSON / JSON.parse SyntaxError likewise
            // comes from the JSON object's realm.
            $fnRealm = $this->realm;
            $currentRealm = \Phasis\Engine::getCurrentRealm();
            if ($fnRealm !== null && $fnRealm !== $currentRealm) {
                try {
                    $result = ($this->nativeCallable)($thisValue, $args);
                } catch (\Phasis\Exceptions\JsThrowable $e) {
                    throw $e;
                } catch (\Phasis\Exceptions\RuntimeError | \Phasis\Exceptions\SyntaxError $e) {
                    throw new \Phasis\Exceptions\JsThrowable(
                        $fnRealm->getInterpreter()->phpExceptionToJsValue($e)
                    );
                }
                if ($result instanceof JsValue) {
                    return $result;
                }
                return JsUndefined::instance();
            }
            $result = ($this->nativeCallable)($thisValue, $args);
            if ($result instanceof JsValue) {
                return $result;
            }
            return JsUndefined::instance();
        }

        // Non-native: delegate to the interpreter via static callback.
        if (self::$interpreterCallback !== null) {
            return (self::$interpreterCallback)($this, $thisValue, $args);
        }
        return JsUndefined::instance();
    }

    /**
     * Invoke this function as a constructor (the [[Construct]] internal method).
     *
     * Creates a new object, sets its prototype from this function's .prototype
     * property, then calls the function with the new object as `this`.
     * If the function returns an object, that object is used; otherwise
     * the newly created object is returned.
     *
     * @param list<JsValue> $args
     */
    public function construct(array $args): JsValue
    {
        // Per spec OrdinaryCreateFromConstructor → GetPrototypeFromConstructor:
        // when newTarget.prototype is not an Object, use the constructor's
        // realm's %Object.prototype% (GetFunctionRealm + intrinsic lookup).
        $proto = \Phasis\Spec\AbstractOperations::getPrototypeFromConstructor(
            $this,
            static fn ($env) => \Phasis\Spec\AbstractOperations::realmIntrinsicPrototype($env, 'Object'),
        );
        $newObj = new JsObject($proto);
        $newObj->defineOwnProperty(
            '[[NewTarget]]',
            \Phasis\Object\PropertyDescriptor::data($this, false, false, false),
        );

        $result = $this->call($newObj, $args);

        // Clean up [[NewTarget]] so it does not leak to user code.
        if ($result instanceof JsObject) {
            $result->forceDelete('[[NewTarget]]');
            return $result;
        }

        $newObj->forceDelete('[[NewTarget]]');
        return $newObj;
    }

    // Property lookup now works correctly via the prototype chain:
    // fn -> Function.prototype -> Object.prototype -> null.
    // The getPrototype() override ensures Function.prototype is in the chain,
    // so parent::get()/set()/has() handle call/apply/bind/caller/arguments
    // automatically. No custom overrides needed.

    /** Set the [[BoundTargetFunction]] for bound functions. */
    public function setBoundTarget(JsFunction $target): void
    {
        $this->boundTarget = $target;
    }

    /** Get the [[BoundTargetFunction]], or null if not a bound function. */
    public function getBoundTarget(): ?JsFunction
    {
        return $this->boundTarget;
    }

    /**
     * Store the original source text for Function.prototype.toString().
     */
    public function setSourceText(string $text): void
    {
        $this->sourceText = $text;
    }

    public function getSourceText(): ?string
    {
        return $this->sourceText;
    }

    public function typeof(): string
    {
        return 'function';
    }

    public function toJsString(): string
    {
        // If we have the original source text, return it per spec.
        if ($this->sourceText !== null) {
            return $this->sourceText;
        }
        // Native/built-in functions use NativeFunction syntax:
        // function NativeAccessor? PropertyName? () { [native code] }
        // Bound functions render with no name to match V8 output.
        $name = $this->name;
        if ($this->boundTarget !== null || $name === '' || $name === '(anonymous)') {
            return 'function () { [native code] }';
        }
        return "function {$name}() { [native code] }";
    }

    public function display(): string
    {
        return $this->toJsString();
    }
}
