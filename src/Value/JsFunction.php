<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\Runtime\Environment;

class JsFunction extends JsObject
{
    /** @var null|\Closure(JsFunction, JsValue, list<JsValue>): JsValue */
    private static ?\Closure $interpreterCallback = null;

    /** Static reference to the interpreter, used for private accessor invocation. */
    private static ?\PhpJs\Runtime\Interpreter $interpreterInstance = null;

    public static function setInterpreterInstance(\PhpJs\Runtime\Interpreter $interp): void
    {
        self::$interpreterInstance = $interp;
    }

    public static function getInterpreterInstance(): ?\PhpJs\Runtime\Interpreter
    {
        return self::$interpreterInstance;
    }

    /** Function.prototype: the [[Prototype]] for all function instances. */
    private static ?JsObject $functionPrototype = null;

    /** Lazily resolved: true once fnProto's [[Prototype]] has been wired to Object.prototype. */
    private static bool $functionPrototypeChainWired = false;

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
        self::$functionPrototypeChainWired = false;
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
    private Environment $closure;
    private string $name;
    private bool $isArrow;
    private bool $isGenerator;
    private bool $isAsync;
    private ?JsValue $boundThis;
    private ?\Closure $nativeCallable = null;
    private bool $constructable = true;

    /** True for class constructors: calling without new throws TypeError. */
    private bool $isClassConstructor = false;

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
     * Each entry is [key (string|JsSymbol), initNode (Node|null), computed (bool), isPrivate (bool)].
     *
     * @var list<array{0: string|\PhpJs\Ast\Node, 1: ?\PhpJs\Ast\Node, 2: bool, 3: bool}>
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
            } elseif ($p instanceof \PhpJs\Ast\Pattern\RestElement) {
                break;
            } elseif ($p instanceof \PhpJs\Ast\Pattern\AssignmentPattern) {
                break;
            } else {
                $length++;
            }
        }
        $this->defineOwnProperty('length', new \PhpJs\Object\PropertyDescriptor(
            value: new JsNumber((float) $length),
            writable: false,
            enumerable: false,
            configurable: true,
        ));
        // Per spec, the exposed .name is "" for anonymous functions.
        // Internally we keep '(anonymous)' as a sentinel for name inference.
        $exposedName = $name === '(anonymous)' ? '' : $name;
        $this->defineOwnProperty('name', new \PhpJs\Object\PropertyDescriptor(
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
     * @param string|\PhpJs\Ast\Node $key
     */
    public function addInstanceFieldInitializer(mixed $key, ?\PhpJs\Ast\Node $initNode, bool $computed, bool $isPrivate): void
    {
        $this->instanceFieldInitializers[] = [$key, $initNode, $computed, $isPrivate];
    }

    /** @return list<array{0: string|\PhpJs\Ast\Node, 1: ?\PhpJs\Ast\Node, 2: bool, 3: bool}> */
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
            // Async generator functions use %AsyncGeneratorFunction.prototype%.
            if (
                $this->isAsync
                && $this->isGenerator
                && self::$asyncGeneratorFunctionPrototype !== null
                && $this !== self::$asyncGeneratorFunctionPrototype
            ) {
                return self::$asyncGeneratorFunctionPrototype;
            }
            // Generator functions use %GeneratorFunction.prototype%.
            if (
                $this->isGenerator
                && !$this->isAsync
                && self::$generatorFunctionPrototype !== null
                && $this !== self::$generatorFunctionPrototype
            ) {
                return self::$generatorFunctionPrototype;
            }
            // Async functions use %AsyncFunction.prototype%.
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
        $this->defineOwnProperty('name', new \PhpJs\Object\PropertyDescriptor(
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
        if ($this->isClassConstructor) {
            $calledAsNew = $thisValue instanceof JsObject
                && !($thisValue->get('[[NewTarget]]') instanceof JsUndefined);
            if (!$calledAsNew) {
                throw new \PhpJs\Exceptions\TypeError(
                    "Class constructor {$this->name} cannot be invoked without 'new'"
                );
            }
        }

        if ($this->nativeCallable !== null) {
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
        $proto = $this->get('prototype');
        $newObj = new JsObject($proto instanceof JsObject ? $proto : null);
        $newObj->defineOwnProperty(
            '[[NewTarget]]',
            \PhpJs\Object\PropertyDescriptor::data($this, false, false, false),
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

    private static function getCallMethod(): self
    {
        return self::fromCallable('call', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsFunction) {
                return JsUndefined::instance();
            }
            $thisArg = $args[0] ?? JsUndefined::instance();
            $callArgs = array_slice($args, 1);
            return $this_->call($thisArg, $callArgs);
        });
    }

    private static function getApplyMethod(): self
    {
        return self::fromCallable('apply', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsFunction) {
                return JsUndefined::instance();
            }
            $thisArg = $args[0] ?? JsUndefined::instance();
            $argsArray = $args[1] ?? JsUndefined::instance();
            $callArgs = [];
            if ($argsArray instanceof JsArray) {
                for ($i = 0; $i < $argsArray->getLength(); $i++) {
                    $callArgs[] = $argsArray->get((string) $i);
                }
            }
            return $this_->call($thisArg, $callArgs);
        });
    }

    private static function getBindMethod(): self
    {
        return self::fromCallable('bind', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsFunction) {
                return JsUndefined::instance();
            }
            $boundThis = $args[0] ?? JsUndefined::instance();
            $boundArgs = array_slice($args, 1);
            $target = $this_;
            return self::fromCallable(
                'bound ' . $target->getName(),
                function (JsValue $this2_, array $callArgs) use ($target, $boundThis, $boundArgs): JsValue {
                    return $target->call($boundThis, array_merge($boundArgs, $callArgs));
                },
            );
        });
    }

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
