<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\Runtime\Environment;

class JsFunction extends JsObject
{
    /** @var null|\Closure(JsFunction, JsValue, list<JsValue>): JsValue */
    private static ?\Closure $interpreterCallback = null;

    /** Function.prototype: the [[Prototype]] for all function instances. */
    private static ?JsObject $functionPrototype = null;

    public static function setFunctionPrototype(JsObject $proto): void
    {
        self::$functionPrototype = $proto;
    }

    public static function setInterpreterCallback(callable $callback): void
    {
        self::$interpreterCallback = $callback;
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
    ) {
        parent::__construct($prototype ?? self::$functionPrototype);
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

    public function isConstructable(): bool
    {
        // Arrow functions are not constructable.
        if ($this->isArrow) {
            return false;
        }
        return $this->constructable;
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

    public function get(string $name): JsValue
    {
        // Check own properties first (prototype, etc.)
        $own = parent::get($name);
        if (!$own instanceof JsUndefined) {
            return $own;
        }

        return match ($name) {
            'call' => self::getCallMethod(),
            'apply' => self::getApplyMethod(),
            'bind' => self::getBindMethod(),
            default => JsUndefined::instance(),
        };
    }

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

    public function typeof(): string
    {
        return 'function';
    }

    public function toJsString(): string
    {
        $name = $this->name !== '' ? $this->name : 'anonymous';
        return "function {$name}() { [native code] }";
    }

    public function display(): string
    {
        return $this->toJsString();
    }
}
