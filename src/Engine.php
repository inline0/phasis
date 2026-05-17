<?php

declare(strict_types=1);

namespace Phasis;

use Phasis\BuiltIn\ConsoleObject;
use Phasis\BuiltIn\GlobalObject;
use Phasis\Interop\PhpToJs;
use Phasis\Parser\Parser;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\CallStack;
use Phasis\Runtime\Environment;
use Phasis\Runtime\EventLoop;
use Phasis\Runtime\Interpreter;
use Phasis\Runtime\LazyBuiltinRegistry;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

class Engine
{
    private static ?Interpreter $currentInterpreter = null;

    /**
     * Stack of `//# sourceURL=` directive values for currently-active eval
     * (or top-level) frames. The most recently pushed entry is the URL the
     * Error stack-trace formatter will surface for new Errors created while
     * that frame is on the stack. Maintained by `Engine::eval`,
     * `Engine::execFile`, and the JS-level `eval()` builtin in
     * `GlobalObject`.
     *
     * @var list<string>
     */
    private static array $sourceUrlStack = [];

    /** Push a sourceURL pragma value onto the active-source stack. */
    public static function pushSourceURL(string $url): void
    {
        self::$sourceUrlStack[] = $url;
    }

    /** Pop the most recently pushed sourceURL pragma value. */
    public static function popSourceURL(): void
    {
        array_pop(self::$sourceUrlStack);
    }

    /**
     * Returns the most recently pushed sourceURL pragma value, or null if
     * no eval/script frame currently advertises one. Used by the Error
     * stack-trace formatter so eval'd code with a `//# sourceURL=foo.js`
     * directive produces stack frames referencing `foo.js`.
     */
    public static function getCurrentSourceURL(): ?string
    {
        $n = count(self::$sourceUrlStack);
        return $n === 0 ? null : self::$sourceUrlStack[$n - 1];
    }

    private Environment $globalEnv;
    private Interpreter $interpreter;
    private ConsoleObject $console;
    private CallStack $callStack;

    /**
     * Optional embedder-supplied HTTP transport for `fetch()`. When null,
     * `fetch()` uses `\Phasis\BuiltIn\Fetch\CurlTransport::send()`. The
     * callable receives the request descriptor (method/url/headers/body/
     * redirect/timeout) and the JS AbortSignal (may be null), and must
     * return the response descriptor (status/statusText/headers/body).
     *
     * Set via `setFetchTransport()`.
     *
     * @var callable|null
     */
    private $fetchTransport = null;

    /**
     * Optional embedder-supplied WebSocket transport. The callable
     * receives `(string $url, list<string> $protocols, callable $emit)`
     * and returns `['send' => callable, 'close' => callable]`. The
     * `$emit` callback bridges back to JS-side events ('open',
     * 'message', 'close', 'error').
     *
     * Set via `setWebSocketTransport()`. There is no default — the
     * `WebSocket` constructor throws TypeError when no transport
     * is installed.
     *
     * @var callable|null
     */
    private $webSocketTransport = null;

    /**
     * Optional pre-flight policy hook for `fetch()`. Receives the Request
     * and may: throw to deny outright; return null/void to pass through;
     * return a Request to rewrite the outgoing call.
     *
     * Set via `setFetchPolicy()`.
     *
     * @var callable|null
     */
    private $fetchPolicy = null;

    /**
     * Optional cookie jar — any value with `get(url)` + `set(url, header)`
     * methods (either a JsObject with JS functions for those names, or a
     * PHP object implementing the same protocol). When set, `fetch()`
     * folds the jar's cookies into the outgoing `Cookie:` header and
     * pushes any `Set-Cookie` response headers back into the jar.
     *
     * @var mixed
     */
    private mixed $cookieJar = null;

    /**
     * When false (default), non-core built-ins (Map, Set, TypedArray,
     * Atomics, Proxy, Reflect, console, Intl, WeakMap/Set/Ref,
     * FinalizationRegistry, DisposableStack, ShadowRealm, Temporal,
     * the Web Platform Pack, and the Fetch Pack) are registered as
     * placeholder accessors on the global object and only materialize
     * on first read. Core intrinsics the language itself depends on
     * (Object/Function/Array/Promise/Symbol/Error/Number/String/RegExp/
     * Iterator/BigInt and their prototypes) are installed eagerly even
     * in lazy mode.
     *
     * When true, every built-in is installed eagerly at construction —
     * the pre-lazy behavior. The conformance harness (test262, WPT,
     * popular-packages, oracle regression) sets this so its tests see
     * the full surface from the first instruction.
     */
    private bool $eager;

    /** Tracks names whose accessor placeholders are still in place. */
    private LazyBuiltinRegistry $lazyRegistry;

    /**
     * Per-realm macrotask queue — drives setTimeout / setInterval /
     * clearTimeout / clearInterval. Microtasks remain on
     * `JsPromise::$microtaskQueue`; the loop hooks into the
     * post-drain callback so each microtask burst is followed by a
     * due-timer pass.
     */
    private EventLoop $eventLoop;

    public function __construct(bool $eager = false)
    {
        $this->eager = $eager;
        $this->lazyRegistry = new LazyBuiltinRegistry();
        // Clear any cached intrinsic prototypes from prior Engine instances
        // so each realm gets its own wired object graph.
        self::resetStaticIntrinsics();
        $this->callStack = new CallStack();
        $this->globalEnv = new Environment();
        $this->console = new ConsoleObject();
        $this->interpreter = new Interpreter($this->globalEnv, $this->callStack);
        $this->interpreter->setRealm($this);
        self::$currentInterpreter = $this->interpreter;

        $this->installBuiltins();

        // Global 'this' should be the global object
        $objProto = $this->globalEnv->has('__ObjectPrototype__')
            ? $this->globalEnv->get('__ObjectPrototype__')
            : null;
        $globalObj = new \Phasis\Value\JsObject(
            $objProto instanceof \Phasis\Value\JsObject ? $objProto : null,
        );

        // Install non-writable, non-configurable global value properties on the global object
        // per ES spec 19.1 (Value Properties of the Global Object).
        $globalObj->defineOwnProperty('Infinity', \Phasis\Object\PropertyDescriptor::data(
            new \Phasis\Value\JsNumber(INF),
            false,
            false,
            false,
        ));
        $globalObj->defineOwnProperty('NaN', \Phasis\Object\PropertyDescriptor::data(
            new \Phasis\Value\JsNumber(NAN),
            false,
            false,
            false,
        ));
        $globalObj->defineOwnProperty('undefined', \Phasis\Object\PropertyDescriptor::data(
            \Phasis\Value\JsUndefined::instance(),
            false,
            false,
            false,
        ));

        // Sync all environment bindings onto the global object so that
        // Object.getOwnPropertyDescriptor(this, "parseInt") etc. work.
        // Per ES spec, built-in function properties are writable, non-enumerable, configurable.
        $skipKeys = ['this', 'globalThis', 'Infinity', 'NaN', 'undefined',
            '__ObjectPrototype__', '__FunctionPrototype__', '__ArrayPrototype__',
            '__StringPrototype__', '__NumberPrototype__', '__ErrorPrototype__',
            '__TypeErrorPrototype__', '__RangeErrorPrototype__',
            '__ReferenceErrorPrototype__', '__SyntaxErrorPrototype__',
            '__URIErrorPrototype__', '__EvalErrorPrototype__',
            '__RegExpPrototype__', '__DatePrototype__',
            '__SymbolPrototype__', '__MapPrototype__', '__SetPrototype__',
        ];
        foreach ($this->globalEnv->allBindings() as $name => $value) {
            if (in_array($name, $skipKeys, true)) {
                continue;
            }
            if (str_starts_with($name, '__') && str_ends_with($name, 'Prototype__')) {
                continue;
            }
            if (!$globalObj->hasOwnProperty($name)) {
                $globalObj->defineOwnProperty(
                    $name,
                    \Phasis\Object\PropertyDescriptor::data($value, true, false, true),
                );
            }
        }

        $this->globalEnv->defineVar('this', $globalObj);
        $this->globalEnv->defineVar('globalThis', $globalObj);

        // Link the global environment to the global object so that
        // top-level var declarations and assignments create properties
        // on globalThis (per ES spec 9.1.1.1 Global Environment Records).
        $this->globalEnv->linkGlobalObject($globalObj);

        // Per spec §19.1: NaN, Infinity, undefined are non-writable
        // on the global object. Must be set AFTER linkGlobalObject.
        $ro = static fn ($v) => \Phasis\Object\PropertyDescriptor::data(
            $v,
            false,
            false,
            false,
        );
        $globalObj->defineOwnProperty('NaN', $ro(new \Phasis\Value\JsNumber(NAN)));
        $globalObj->defineOwnProperty('Infinity', $ro(new \Phasis\Value\JsNumber(INF)));
        $globalObj->defineOwnProperty('undefined', $ro(\Phasis\Value\JsUndefined::instance()));

        // globalThis: writable, non-enumerable, configurable per spec.
        // Set directly on the global object since the Environment filter
        // skips 'globalThis' for the linked object sync.
        $globalObj->defineOwnProperty(
            'globalThis',
            \Phasis\Object\PropertyDescriptor::data($globalObj, true, false, true),
        );

        // In lazy mode, install accessor placeholders on the global
        // object for every registered lazy module. Reading any of those
        // names — `Map`, `fetch`, `Temporal`, … — fires the accessor,
        // which runs the install factory and replaces the accessor with
        // the real data descriptor. Subsequent reads bypass the
        // accessor entirely.
        if (!$this->eager) {
            $this->installLazyAccessors($globalObj);
        }

        // Event loop must be constructed AFTER the post-drain hook
        // surface on JsPromise is clean (Engine never calls
        // `setPostDrainHook` before this point). Constructing the
        // loop also installs the hook — every microtask drain from
        // now on rolls into a timer-fire pass.
        $this->eventLoop = new EventLoop($this);
    }

    /**
     * Define an accessor descriptor on the global object for each
     * lazy-registered name. The accessor's getter realizes the owning
     * module, after which the binding lives on the env + global object
     * directly and the placeholder is gone.
     */
    private function installLazyAccessors(JsObject $globalObj): void
    {
        $registry = $this->lazyRegistry;
        $env = $this->globalEnv;
        foreach ($registry->names() as $name) {
            // Skip names already bound — happens when eager-core install
            // re-used a name a lazy module also claims, or when an
            // embedder pre-empted with setGlobal().
            if ($globalObj->hasOwnProperty($name)) {
                continue;
            }
            $getter = JsFunction::fromCallable(
                'get ' . $name,
                static function (
                    JsValue $this_,
                    array $args,
                ) use (
                    $name,
                    $registry,
                    $env,
                    $globalObj
                ): JsValue {
                    unset($this_, $args);
                    $registry->realize($name);
                    // Pull from the env's own bindings rather than
                    // env->get(), which would walk the linked object
                    // and re-enter this accessor when an install used
                    // defineDeletable (which doesn't sync to the
                    // linked global object).
                    $value = $env->getAtDepth($name, 0) ?? JsUndefined::instance();
                    // Make sure the accessor is fully gone from the
                    // global object after install. defineVar replaces
                    // it automatically; defineDeletable does not, so
                    // belt-and-suspenders here.
                    $current = $globalObj->getOwnPropertyDescriptor($name);
                    if ($current === null || !$current->isDataDescriptor()) {
                        $globalObj->defineOwnProperty(
                            $name,
                            PropertyDescriptor::data($value, true, false, true),
                        );
                    }
                    return $value;
                },
            );
            $globalObj->defineOwnProperty(
                $name,
                PropertyDescriptor::accessor(
                    get: $getter,
                    enumerable: false,
                    configurable: true,
                ),
            );
        }
    }

    /** Internal: the registry of lazy-installable modules for this realm. */
    public function getLazyBuiltinRegistry(): LazyBuiltinRegistry
    {
        return $this->lazyRegistry;
    }

    /**
     * The realm's event loop — drives timers and macrotasks. The
     * microtask queue continues to live on `JsPromise` and is shared
     * across all engines in the process; this loop is per-realm.
     */
    public function getEventLoop(): EventLoop
    {
        return $this->eventLoop;
    }

    /**
     * Drain microtasks plus every pending macrotask until both queues
     * are empty. Virtual-clock semantics — `setTimeout(cb, 5000)` does
     * NOT sleep PHP; the loop advances its clock to the deadline so
     * the program completes promptly. The right entry point for test
     * runners and for "run my JS to completion" use cases.
     */
    public function runEventLoop(): void
    {
        $this->eventLoop->runUntilEmpty();
    }

    /**
     * Pump one event-loop iteration: drain microtasks and fire any
     * timers whose wall-clock deadline has already passed. Returns
     * true if work was done. Embedders driving the loop themselves
     * (real-time scheduling, GUI integration, long-lived server)
     * call this in their own pump.
     */
    public function tickEventLoop(): bool
    {
        return $this->eventLoop->tick();
    }

    /** Number of pending macrotasks (timers). */
    public function pendingTaskCount(): int
    {
        return $this->eventLoop->pendingTaskCount();
    }

    /** Internal: whether this engine eagerly installs every built-in. */
    public function isEager(): bool
    {
        return $this->eager;
    }

    private function installBuiltins(): void
    {
        // Reset global prototype before installing builtins so that objects
        // created during GlobalObject::install (e.g. Boolean.prototype) get a
        // null [[Prototype]]. The wiring step after ObjectConstructor::install
        // will set the correct prototype chain. Without this reset, a stale
        // globalPrototype from a previous Engine instance would leak in.
        JsObject::resetGlobalPrototype();

        GlobalObject::install($this->globalEnv);
        \Phasis\BuiltIn\ObjectConstructor::install($this->globalEnv);

        // Wire prototype objects created in GlobalObject::install to Object.prototype now
        // that both exist. ObjectConstructor::install set the global prototype, so any
        // new JsObject() will inherit it — but objects created earlier need explicit wiring.
        if ($this->globalEnv->has('__ObjectPrototype__')) {
            $objProto = $this->globalEnv->get('__ObjectPrototype__');
            if ($objProto instanceof JsObject) {
                // Function.prototype -> Object.prototype
                if ($this->globalEnv->has('Function')) {
                    $fnCtor = $this->globalEnv->get('Function');
                    if ($fnCtor instanceof JsFunction) {
                        $fnProto = $fnCtor->get('prototype');
                        if ($fnProto instanceof JsObject && $fnProto->getPrototype() === null) {
                            $fnProto->setPrototype($objProto);
                        }
                    }
                }
                // Boolean.prototype -> Object.prototype
                if ($this->globalEnv->has('Boolean')) {
                    $boolCtor = $this->globalEnv->get('Boolean');
                    if ($boolCtor instanceof JsFunction) {
                        $boolProto = $boolCtor->get('prototype');
                        if ($boolProto instanceof JsObject && $boolProto->getPrototype() === null) {
                            $boolProto->setPrototype($objProto);
                        }
                    }
                }
                // %IteratorPrototype% -> Object.prototype (per spec 27.1.2)
                if ($this->globalEnv->has('__IteratorPrototype__')) {
                    $iterProto = $this->globalEnv->get('__IteratorPrototype__');
                    if ($iterProto instanceof JsObject && $iterProto->getPrototype() === null) {
                        $iterProto->setPrototype($objProto);
                    }
                }
                // %AsyncIteratorPrototype% -> Object.prototype (per spec 27.1.3)
                if ($this->globalEnv->has('__AsyncIteratorPrototype__')) {
                    $aiProto = $this->globalEnv->get('__AsyncIteratorPrototype__');
                    if ($aiProto instanceof JsObject && $aiProto->getPrototype() === null) {
                        $aiProto->setPrototype($objProto);
                    }
                }
            }
        }
        // Eager core — every built-in in this block is load-bearing for
        // the language itself (errors thrown by the runtime, primitive
        // wrappers, the for-of iterator protocol, async/await's Promise,
        // Symbol.iterator and friends). Lazifying any of these creates
        // bootstrap cycles or breaks code that never names them.
        \Phasis\BuiltIn\ErrorConstructor::install($this->globalEnv);
        \Phasis\BuiltIn\NumberConstructor::install($this->globalEnv);
        \Phasis\BuiltIn\ArrayConstructor::install($this->globalEnv);
        \Phasis\BuiltIn\StringPrototype::install($this->globalEnv);
        \Phasis\BuiltIn\SymbolConstructor::install($this->globalEnv);
        \Phasis\BuiltIn\IteratorConstructor::install($this->globalEnv);
        \Phasis\BuiltIn\PromiseConstructor::install($this->globalEnv);

        // Everything below is the "lazy surface": in lazy mode each
        // module gets a placeholder accessor on the global object that
        // realizes the install on first read. In eager mode (test262 /
        // WPT / popular-packages / oracle harnesses, or any embedder
        // that passed `eager: true`) we install everything inline so
        // the visible surface and descriptor shapes match the legacy
        // behavior exactly.
        if ($this->eager) {
            \Phasis\BuiltIn\MathObject::install($this->globalEnv);
            \Phasis\BuiltIn\JsonObject::install($this->globalEnv);
            \Phasis\BuiltIn\MapConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\SetConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\TypedArrayConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\AtomicsObject::install($this->globalEnv);
            \Phasis\BuiltIn\ProxyConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\ReflectObject::install($this->globalEnv);
            $this->globalEnv->defineVar('console', $this->console->create());

            \Phasis\BuiltIn\IntlObject::install($this->globalEnv);

            \Phasis\BuiltIn\WeakMapConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\WeakSetConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\WeakRefConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\FinalizationRegistryConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\DisposableStackConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\ShadowRealmConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\TemporalObject::install($this->globalEnv);

            // Web Platform Pack (WHATWG / W3C, not TC39).
            \Phasis\BuiltIn\DomExceptionConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\Base64Functions::install($this->globalEnv);
            \Phasis\BuiltIn\PerformanceObject::install($this->globalEnv);
            \Phasis\BuiltIn\TextEncoderConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\TextDecoderConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\UrlConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\StructuredCloneFunction::install($this->globalEnv);

            // Fetch Pack — round 1 foundations (independent value types).
            \Phasis\BuiltIn\EventTargetConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\BlobConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\FormDataConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\HeadersConstructor::install($this->globalEnv);

            // Fetch Pack — round 2: AbortController (needs EventTarget) +
            // full WHATWG Streams (needs EventTarget for signal integration).
            \Phasis\BuiltIn\AbortControllerConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\StreamsConstructor::install($this->globalEnv);

            // Fetch Pack — round 3: Request + Response value types (need
            // Headers/Blob/FormData/Streams/AbortSignal for body extract).
            \Phasis\BuiltIn\RequestConstructor::install($this->globalEnv);
            \Phasis\BuiltIn\ResponseConstructor::install($this->globalEnv);

            // Fetch Pack — round 4: the actual fetch() global + navigator.
            \Phasis\BuiltIn\NavigatorObject::install($this->globalEnv);
            \Phasis\BuiltIn\FetchFunction::install($this->globalEnv);

            // WindowOrWorkerGlobalScope timers + queueMicrotask. Not
            // strictly part of the Fetch Pack but the natural sibling
            // for event-loop-aware Web APIs.
            \Phasis\BuiltIn\TimerFunctions::install($this->globalEnv);

            // WebCrypto — `crypto.getRandomValues`, `crypto.randomUUID`,
            // `crypto.subtle.{digest, sign, verify, encrypt, decrypt,
            // generateKey, importKey, exportKey}`.
            \Phasis\BuiltIn\CryptoObject::install($this->globalEnv);

            // XMLHttpRequest — legacy HTTP client, layered on the same
            // pluggable transport that `fetch()` uses.
            \Phasis\BuiltIn\XMLHttpRequestConstructor::install($this->globalEnv);

            // WebSocket — RFC 6455 client. Pluggable transport via
            // setWebSocketTransport(); throws on `new WebSocket(...)`
            // if no transport is installed.
            \Phasis\BuiltIn\WebSocketConstructor::install($this->globalEnv);

            // AsyncContext (TC39 Stage 3) — Variable + Snapshot.
            \Phasis\BuiltIn\AsyncContextConstructor::install($this->globalEnv);
        } else {
            $this->registerLazyBuiltins();
        }

        // BigInt constructor: callable but not intended for `new`.
        // Per spec 21.2.1, when called with `new`, throws TypeError.
        // When called as function, converts value to BigInt.
        $bigIntFn = \Phasis\Value\JsFunction::fromCallable(
            'BigInt',
            function (\Phasis\Value\JsValue $this_, array $args): \Phasis\Value\JsValue {
                if ($this_ instanceof \Phasis\Value\JsObject && $this_->has('[[NewTarget]]')) {
                    throw new \Phasis\Exceptions\TypeError('BigInt is not a constructor');
                }
                $val = $args[0] ?? \Phasis\Value\JsUndefined::instance();

                // Per spec BigInt(value) step 2: prim = ToPrimitive(value, number).
                $prim = \Phasis\Spec\TypeConversion::toPrimitive($val, 'number');

                // Step 3: If Type(prim) is Number, return ? NumberToBigInt(prim).
                if ($prim instanceof \Phasis\Value\JsNumber) {
                    $n = $prim->value;
                    if (!is_finite($n) || floor($n) !== $n) {
                        throw new \Phasis\Exceptions\RangeError(
                            'The number ' . $n . ' cannot be converted to a BigInt'
                            . ' because it is not an integer'
                        );
                    }
                    // Use string representation for large integers beyond PHP_INT_MAX.
                    if ($n > PHP_INT_MAX || $n < PHP_INT_MIN) {
                        return new \Phasis\Value\JsBigInt(number_format($n, 0, '.', ''));
                    }
                    return new \Phasis\Value\JsBigInt((string) (int) $n);
                }

                // Step 4: Otherwise, return ? ToBigInt(prim).
                return \Phasis\Spec\TypeConversion::toBigInt($prim);
            },
        );
        // BigInt has [[Construct]] per spec (just throws TypeError when called as constructor).
        $bigIntFn->setConstructable();

        // BigInt.length = 1 per spec (writable: false, enumerable: false, configurable: true).
        $bigIntFn->defineOwnProperty('length', new \Phasis\Object\PropertyDescriptor(
            value: new \Phasis\Value\JsNumber(1.0),
            writable: false,
            enumerable: false,
            configurable: true,
        ));

        // BigInt.prototype: allows attaching methods to all BigInt primitives.
        // Per spec 21.2.2, BigInt.prototype is an ordinary object (not a BigInt value).
        $bigIntProto = new \Phasis\Value\JsObject();

        // BigInt.prototype.toString([radix])
        $bigIntToStr = \Phasis\Value\JsFunction::fromCallable(
            'toString',
            function (\Phasis\Value\JsValue $this_, array $args): \Phasis\Value\JsValue {
                $bigint = $this_ instanceof \Phasis\Value\JsBigInt ? $this_ : null;
                if ($bigint === null && $this_ instanceof \Phasis\Value\JsObject) {
                    // BigInt wrapper object.
                    $v = $this_->get('[[BigIntData]]');
                    $bigint = $v instanceof \Phasis\Value\JsBigInt ? $v : null;
                }
                if ($bigint === null) {
                    throw new \Phasis\Exceptions\TypeError('BigInt.prototype.toString called on non-BigInt');
                }
                $radix = 10;
                if (isset($args[0]) && !($args[0] instanceof \Phasis\Value\JsUndefined)) {
                    $radix = (int) $args[0]->toNumber();
                    if ($radix < 2 || $radix > 36) {
                        throw new \Phasis\Exceptions\RangeError('toString() radix must be between 2 and 36');
                    }
                }
                if ($radix === 10) {
                    return new \Phasis\Value\JsString($bigint->value);
                }
                // For non-decimal radix, convert the decimal string to the target base.
                $negative = isset($bigint->value[0]) && $bigint->value[0] === '-';
                $abs = $negative ? substr($bigint->value, 1) : $bigint->value;
                if ($abs === '0' || $abs === '') {
                    return new \Phasis\Value\JsString('0');
                }
                $digitChars = '0123456789abcdefghijklmnopqrstuvwxyz';
                $result = '';
                $radixStr = (string) $radix;
                $n = $abs;
                // Fast path for values that fit in PHP int.
                if (strlen($n) < 18) {
                    $nInt = (int) $n;
                    while ($nInt > 0) {
                        $result = $digitChars[$nInt % $radix] . $result;
                        $nInt = intdiv($nInt, $radix);
                    }
                } else {
                    // Use pure-PHP string division for large values.
                    while ($n !== '0') {
                        $remInt = 0;
                        $q = '';
                        for ($di = 0; $di < strlen($n); $di++) {
                            $cur = $remInt * 10 + (int) $n[$di];
                            $q .= (string) intdiv($cur, $radix);
                            $remInt = $cur % $radix;
                        }
                        $result = $digitChars[$remInt] . $result;
                        $n = ltrim($q, '0') ?: '0';
                    }
                }
                return new \Phasis\Value\JsString($negative ? '-' . $result : $result);
            },
        );
        $bigIntProto->defineOwnProperty(
            'toString',
            \Phasis\Object\PropertyDescriptor::data($bigIntToStr, true, false, true),
        );

        // BigInt.prototype.valueOf()
        $bigIntValOf = \Phasis\Value\JsFunction::fromCallable(
            'valueOf',
            function (\Phasis\Value\JsValue $this_, array $args): \Phasis\Value\JsValue {
                if ($this_ instanceof \Phasis\Value\JsBigInt) {
                    return $this_;
                }
                if ($this_ instanceof \Phasis\Value\JsObject) {
                    $v = $this_->get('[[BigIntData]]');
                    if ($v instanceof \Phasis\Value\JsBigInt) {
                        return $v;
                    }
                }
                throw new \Phasis\Exceptions\TypeError('BigInt.prototype.valueOf called on non-BigInt');
            },
        );
        $bigIntProto->defineOwnProperty(
            'valueOf',
            \Phasis\Object\PropertyDescriptor::data($bigIntValOf, true, false, true),
        );

        // BigInt.prototype.toLocaleString() — delegates to Intl.NumberFormat
        // when intl is available, matching V8's behaviour. Falls back to
        // the raw decimal string in non-intl environments.
        $bigIntLocale = \Phasis\Value\JsFunction::fromCallable(
            'toLocaleString',
            function (\Phasis\Value\JsValue $this_, array $args): \Phasis\Value\JsValue {
                $bigint = $this_ instanceof \Phasis\Value\JsBigInt ? $this_ : null;
                if ($bigint === null && $this_ instanceof \Phasis\Value\JsObject) {
                    $v = $this_->get('[[BigIntData]]');
                    $bigint = $v instanceof \Phasis\Value\JsBigInt ? $v : null;
                }
                if ($bigint === null) {
                    throw new \Phasis\Exceptions\TypeError('BigInt.prototype.toLocaleString called on non-BigInt');
                }
                $this_ = $bigint;
                if (extension_loaded('intl')) {
                    $env = self::getCurrentInterpreter()?->getGlobalEnv();
                    $intlObj = $env?->get('Intl', false);
                    if ($intlObj instanceof \Phasis\Value\JsObject) {
                        $nfCtor = $intlObj->get('NumberFormat');
                        if ($nfCtor instanceof \Phasis\Value\JsFunction) {
                            $nfProto = $nfCtor->get('prototype');
                            $nfObj = new \Phasis\Value\JsObject(
                                $nfProto instanceof \Phasis\Value\JsObject ? $nfProto : null,
                            );
                            $nfObj->defineOwnProperty(
                                '[[NewTarget]]',
                                \Phasis\Object\PropertyDescriptor::data($nfCtor, false, false, false),
                            );
                            ($nfCtor->getNativeCallable())($nfObj, [
                                $args[0] ?? \Phasis\Value\JsUndefined::instance(),
                                $args[1] ?? \Phasis\Value\JsUndefined::instance(),
                            ]);
                            $interp = self::getCurrentInterpreter();
                            $formatGetter = $nfProto instanceof \Phasis\Value\JsObject
                                ? $nfProto->getOwnPropertyDescriptor('format')
                                : null;
                            if (
                                $interp !== null
                                && $formatGetter !== null
                                && $formatGetter->get instanceof \Phasis\Value\JsFunction
                            ) {
                                $bound = $interp->callFunction(
                                    $formatGetter->get,
                                    $nfObj,
                                    [],
                                );
                                if ($bound instanceof \Phasis\Value\JsFunction) {
                                    return $interp->callFunction(
                                        $bound,
                                        \Phasis\Value\JsUndefined::instance(),
                                        [$this_],
                                    );
                                }
                            }
                        }
                    }
                }
                return new \Phasis\Value\JsString($this_->value);
            },
        );
        $bigIntProto->defineOwnProperty(
            'toLocaleString',
            \Phasis\Object\PropertyDescriptor::data($bigIntLocale, true, false, true),
        );

        // BigInt.prototype[Symbol.toStringTag] = "BigInt"
        $bigIntProto->definePropertyBySymbol(
            \Phasis\BuiltIn\SymbolConstructor::toStringTag(),
            new \Phasis\Object\PropertyDescriptor(
                value: new \Phasis\Value\JsString('BigInt'),
                writable: false,
                enumerable: false,
                configurable: true,
            )
        );

        // BigInt.prototype.constructor = BigInt
        $bigIntProto->defineOwnProperty('constructor', \Phasis\Object\PropertyDescriptor::data(
            $bigIntFn,
            true,
            false,
            true
        ));

        $bigIntFn->defineOwnProperty('prototype', new \Phasis\Object\PropertyDescriptor(
            value: $bigIntProto,
            writable: false,
            enumerable: false,
            configurable: false,
        ));

        // Pure-PHP helpers for asIntN/asUintN.
        // Compute 2^n as a decimal string.
        $pow2str = static function (int $n): string {
            if ($n === 0) {
                return '1';
            }
            // Start with 1 and double n times.
            $result = '1';
            for ($i = 0; $i < $n; $i++) {
                // Double the string: multiply each digit by 2 with carry.
                $carry = 0;
                $out = '';
                for ($j = strlen($result) - 1; $j >= 0; $j--) {
                    $d = (int) $result[$j] * 2 + $carry;
                    $carry = intdiv($d, 10);
                    $out = ($d % 10) . $out;
                }
                if ($carry) {
                    $out = $carry . $out;
                }
                $result = $out;
            }
            return $result;
        };
        // Compare two non-negative decimal strings. Returns -1, 0, 1.
        $bigCmpUns = static function (string $a, string $b): int {
            $la = strlen($a);
            $lb = strlen($b);
            if ($la !== $lb) {
                return $la < $lb ? -1 : 1;
            }
            return strcmp($a, $b) <=> 0;
        };
        // Unsigned subtract: a >= b assumed.
        $bigSubUns = static function (string $a, string $b): string {
            $result = '';
            $borrow = 0;
            $i = strlen($a) - 1;
            $j = strlen($b) - 1;
            while ($i >= 0) {
                $diff = (int) $a[$i--] - ($j >= 0 ? (int) $b[$j--] : 0) - $borrow;
                if ($diff < 0) {
                    $diff += 10;
                    $borrow = 1;
                } else {
                    $borrow = 0;
                }
                $result = $diff . $result;
            }
            return ltrim($result, '0') ?: '0';
        };
        // Unsigned string division: returns remainder when dividing $a by $d (small int).
        $bigModSmall = static function (string $a, int $d): int {
            $rem = 0;
            for ($i = 0; $i < strlen($a); $i++) {
                $rem = ($rem * 10 + (int) $a[$i]) % $d;
            }
            return $rem;
        };
        // Compute bigint mod 2^width (result is non-negative decimal string).
        // We do this by computing the binary representation of |bigint| mod 2^width,
        // then adjusting for sign.
        $bigUintN = static function (
            string $val,
            int $width,
        ) use (
            $pow2str,
            $bigSubUns,
        ): string {
            if ($width === 0) {
                return '0';
            }
            $neg = isset($val[0]) && $val[0] === '-';
            $abs = ltrim($val, '-');
            if ($abs === '0') {
                return '0';
            }
            // Fast path: when |val|'s bit length is provably less than $width,
            // the modulo operation is a no-op for non-negative values and a
            // single subtraction (2^width - |val|) for negatives. Skip the
            // O(width²) pow2str computation that would otherwise be triggered
            // for huge widths like 2^32 (sm/BigInt/large-bit-length.js).
            // log2(10) ≈ 3.322; ceil(digits * 3.322) is an upper bound on the
            // number of bits required to represent |val|.
            $maxBits = (int) ceil(strlen($abs) * 3.3219281);
            if ($maxBits < $width) {
                if (!$neg) {
                    return $abs;
                }
                // pow2 = 2^width; result = pow2 - |val|. We only need this
                // when width is small enough that pow2str is tractable; for
                // huge widths we fall through to the slow path which has its
                // own large-width handling. In practice, when $maxBits is a
                // few dozen and $width is in the millions/billions, the
                // negative case is also short-circuited in the caller (e.g.
                // asIntN of -1n with huge bits returns -1n, never going
                // through the >= half check that needs pow2str).
                if ($width <= 4096) {
                    $pow2Big = $pow2str($width);
                    return $bigSubUns($pow2Big, $abs);
                }
                // For huge widths we cannot represent pow2 - |val| as a
                // finite-length decimal cheaply. Mark the value's negation
                // by computing pow2 - |val| symbolically: pow2 - 1 is 2^width-1
                // which is a width-bit string of all 1s (in binary) → 2^width - n
                // expressed in decimal would still be huge. The asIntN caller
                // will compare this against half (2^(width-1)) and subtract
                // pow2; the intermediate large strings make asIntN intractable.
                // To keep that asIntN call tractable we produce a marker the
                // asIntN path detects via $bigCmpUns (>= half) and instead
                // returns the original signed value directly. Here we just
                // recognise the impossibility and let the caller's wider
                // logic (or fallthrough) handle it.
                // Fall through to slow path:
            }
            // pow2 = 2^width
            $pow2 = $pow2str($width);
            // Compute abs mod pow2 using repeated division by 2 (get binary bits), then reconstruct.
            // Actually easier: compute $abs % $pow2 using digit-by-digit mod.
            // Use fast path if pow2 fits in PHP int.
            if ($width <= 62) {
                $mask = (1 << $width) - 1;
                // Compute abs mod 2^width.
                $rem = 0;
                for ($i = 0; $i < strlen($abs); $i++) {
                    $rem = (($rem * 10) + (int) $abs[$i]) & $mask;
                }
                $rem = $rem & $mask;
                if ($neg && $rem !== 0) {
                    $rem = (1 << $width) - $rem;
                }
                return (string) $rem;
            }
            // Large width: use string-based computation.
            // Compute abs mod pow2 using long division.
            // abs mod pow2 = abs - pow2 * floor(abs / pow2)
            // We can compute this as: take last width bits of abs in binary.
            // 1. Convert abs to binary string by repeated halving.
            $bin = '';
            $n = $abs;
            while ($n !== '0') {
                // Last bit = n % 2
                $rem2 = 0;
                $q = '';
                for ($i = 0; $i < strlen($n); $i++) {
                    $cur = $rem2 * 10 + (int) $n[$i];
                    $q .= (string) intdiv($cur, 2);
                    $rem2 = $cur % 2;
                }
                $bin = $rem2 . $bin;
                $n = ltrim($q, '0') ?: '0';
            }
            // Take last $width bits.
            if (strlen($bin) <= $width) {
                $bits = str_pad($bin, $width, '0', STR_PAD_LEFT);
            } else {
                $bits = substr($bin, -$width);
            }
            // If negative, compute two's complement: flip bits and add 1.
            if ($neg) {
                $flipped = '';
                for ($i = 0; $i < strlen($bits); $i++) {
                    $flipped .= $bits[$i] === '0' ? '1' : '0';
                }
                // Add 1 to flipped.
                $carry = 1;
                $result = '';
                for ($i = strlen($flipped) - 1; $i >= 0; $i--) {
                    $d = (int) $flipped[$i] + $carry;
                    $result = ($d % 2) . $result;
                    $carry = intdiv($d, 2);
                }
                // Discard overflow carry (wraps around).
                $bits = $result;
            }
            // Convert bits back to decimal.
            $dec = '0';
            for ($i = 0; $i < strlen($bits); $i++) {
                // dec = dec * 2 + bit
                $carry2 = (int) $bits[$i];
                $out2 = '';
                for ($j = strlen($dec) - 1; $j >= 0; $j--) {
                    $d = (int) $dec[$j] * 2 + $carry2;
                    $carry2 = intdiv($d, 10);
                    $out2 = ($d % 10) . $out2;
                }
                while ($carry2 > 0) {
                    $out2 = ($carry2 % 10) . $out2;
                    $carry2 = intdiv($carry2, 10);
                }
                $dec = $out2;
            }
            return $dec;
        };

        // BigInt.asUintN(width, bigint): modulo 2^width, unsigned.
        $asUintNFn = \Phasis\Value\JsFunction::fromCallable(
            'asUintN',
            function (\Phasis\Value\JsValue $this_, array $args) use ($bigUintN): \Phasis\Value\JsValue {
                $width = isset($args[0]) ? \Phasis\Spec\TypeConversion::toIndex($args[0]) : 0;
                $bigint = \Phasis\Spec\TypeConversion::toBigInt(
                    $args[1] ?? \Phasis\Value\JsUndefined::instance(),
                );
                $mod = $bigUintN($bigint->value, $width);
                return new \Phasis\Value\JsBigInt($mod);
            },
            2,
        );
        $bigIntFn->defineOwnProperty(
            'asUintN',
            \Phasis\Object\PropertyDescriptor::data($asUintNFn, true, false, true),
        );

        // BigInt.asIntN(width, bigint): modulo 2^width, signed.
        $asIntNCb = function (
            \Phasis\Value\JsValue $this_,
            array $args,
        ) use (
            $bigUintN,
            $pow2str,
            $bigCmpUns,
            $bigSubUns,
        ): \Phasis\Value\JsValue {
            $width = isset($args[0]) ? \Phasis\Spec\TypeConversion::toIndex($args[0]) : 0;
            $bigint = \Phasis\Spec\TypeConversion::toBigInt(
                $args[1] ?? \Phasis\Value\JsUndefined::instance(),
            );
            if ($width === 0) {
                return new \Phasis\Value\JsBigInt('0');
            }
            // Fast path: when |bigint|'s bit length is provably less than
            // (width - 1), the value fits unchanged into the signed range
            // [-2^(width-1), 2^(width-1)) and the result is just the input.
            // Skips the O(width²) pow2str pow2 computation for huge widths
            // (sm/BigInt/large-bit-length.js with bits up to MAX_SAFE_INT).
            $abs = ltrim($bigint->value, '-');
            $maxBits = (int) ceil(strlen($abs) * 3.3219281);
            if ($maxBits < $width - 1) {
                return $bigint;
            }
            $mod = $bigUintN($bigint->value, $width);
            // If mod >= 2^(width-1), result = mod - 2^width (i.e. negative).
            $half = $pow2str($width - 1);
            if ($bigCmpUns($mod, $half) >= 0) {
                $pow2 = $pow2str($width);
                $diff = $bigSubUns($pow2, $mod);
                return new \Phasis\Value\JsBigInt($diff === '0' ? '0' : '-' . $diff);
            }
            return new \Phasis\Value\JsBigInt($mod);
        };
        $asIntNFn = \Phasis\Value\JsFunction::fromCallable('asIntN', $asIntNCb, 2);
        $bigIntFn->defineOwnProperty(
            'asIntN',
            \Phasis\Object\PropertyDescriptor::data($asIntNFn, true, false, true),
        );

        // Store the prototype so JsBigInt primitive lookups can find it.
        \Phasis\Value\JsBigInt::setPrototype($bigIntProto);

        $this->globalEnv->defineVar('BigInt', $bigIntFn);

        if ($this->eager) {
            \Phasis\BuiltIn\DateConstructor::install($this->globalEnv);
        } else {
            $env = $this->globalEnv;
            $this->lazyRegistry->register(
                ['Date'],
                [],
                static fn () => \Phasis\BuiltIn\DateConstructor::install($env),
            );
        }

        $interp = $this->interpreter;
        $globalEnv = $this->globalEnv;
        $regExpCb = function (
            \Phasis\Value\JsValue $this_,
            array $args,
        ) use (
            $interp,
            $globalEnv
): \Phasis\Value\JsValue {
            $arg0 = $args[0] ?? \Phasis\Value\JsUndefined::instance();
            $arg1 = $args[1] ?? \Phasis\Value\JsUndefined::instance();

            $calledAsNew = $this_ instanceof \Phasis\Value\JsObject && $this_->has('[[NewTarget]]');

            // Per spec 22.2.3.1: IsRegExp check using @@match. If matcher is
            // undefined, fall back to whether the argument has the
            // [[RegExpMatcher]] internal slot (we use [[PCREPattern]] for
            // that purpose). Per spec, internal slots are NOT inherited
            // through Proxy targets: a Proxy wrapping a regex does not
            // itself have [[RegExpMatcher]]. Restrict the fallback to
            // direct JsObject receivers so a proxy whose @@match handler
            // returns undefined fails IsRegExp (spec sec-isregexp step 5).
            $patternIsRegExp = false;
            if ($arg0 instanceof \Phasis\Value\JsObject) {
                $matchSymbol = \Phasis\BuiltIn\SymbolConstructor::match();
                $matchProp = $arg0->getBySymbol($matchSymbol);
                if ($matchProp instanceof \Phasis\Value\JsUndefined) {
                    if ($arg0 instanceof \Phasis\Value\JsProxy) {
                        $patternIsRegExp = false;
                    } else {
                        $patternIsRegExp = $arg0->getOwnPropertyDescriptor('[[PCREPattern]]') !== null;
                    }
                } else {
                    $patternIsRegExp = \Phasis\Spec\TypeConversion::toBoolean($matchProp);
                }
            }

            // Spec step 4: When called as function (not new), if pattern is regexp-like
            // with flags undefined and pattern.constructor === RegExp, return pattern as-is.
            if (!$calledAsNew && $patternIsRegExp && $arg1 instanceof \Phasis\Value\JsUndefined) {
                if ($arg0 instanceof \Phasis\Value\JsObject) {
                    $patternCtor = $arg0->get('constructor');
                    if ($globalEnv->has('RegExp') && $patternCtor === $globalEnv->get('RegExp')) {
                        return $arg0;
                    }
                }
            }

            // Detect subclass: if [[NewTarget]] is not the base RegExp constructor.
            // $calledAsNew already narrows $this_ to JsObject.
            $isSubclass = false;
            $newTarget = null;
            if ($calledAsNew) {
                $ntd = $this_->getOwnPropertyDescriptor("[[NewTarget]]");
                $baseRegExp = $globalEnv->has("RegExp") ? $globalEnv->get("RegExp") : null;
                if ($ntd !== null && $ntd->value !== null && $ntd->value !== $baseRegExp) {
                    $isSubclass = true;
                    $newTarget = $ntd->value;
                }
            }

            // Per spec 22.2.3.1 step 4 (when pattern is a RegExp): capture
            // [[OriginalSource]] / [[OriginalFlags]] BEFORE doing the
            // prototype lookup, so a `prototype` accessor on the newTarget
            // that mutates the source regex (e.g. via re.compile()) does
            // not retroactively change the source we use.
            $cachedPattern = null;
            $cachedFlags = null;
            if ($patternIsRegExp && $arg0 instanceof \Phasis\Value\JsObject) {
                $srcDesc = $arg0->getOwnPropertyDescriptor('[[OriginalSource]]');
                if ($srcDesc !== null && $srcDesc->value instanceof \Phasis\Value\JsString) {
                    $cachedPattern = $srcDesc->value->value;
                }
                if ($arg1 instanceof \Phasis\Value\JsUndefined) {
                    $flgDesc = $arg0->getOwnPropertyDescriptor('[[OriginalFlags]]');
                    if ($flgDesc !== null && $flgDesc->value instanceof \Phasis\Value\JsString) {
                        $cachedFlags = $flgDesc->value->value;
                    }
                }
            }

            // For subclass instances, resolve newTarget.prototype up front
            // so a `prototype` accessor on newTarget runs at the spec-
            // mandated step (RegExpAlloc -> GetPrototypeFromConstructor),
            // not after argument ToString.
            $subProto = null;
            if ($isSubclass && $newTarget instanceof JsFunction) {
                $maybeProto = $newTarget->get('prototype');
                if ($maybeProto instanceof \Phasis\Value\JsObject) {
                    $subProto = $maybeProto;
                }
            }

            // Per spec 22.2.3.1 step 4-5: only treat the argument as a
            // RegExp source/flags pair when IsRegExp(pattern) was true. An
            // arbitrary object with `source`/`flags` properties but a falsy
            // @@match must be coerced via ToString instead.
            if ($patternIsRegExp && $arg0 instanceof \Phasis\Value\JsObject) {
                if ($cachedPattern !== null) {
                    $pattern = $cachedPattern;
                } else {
                    $pattern = \Phasis\Spec\TypeConversion::toString($arg0->get('source'));
                    if ($pattern === '(?:)') {
                        $pattern = '';
                    }
                }
                if ($cachedFlags !== null) {
                    $flags = $cachedFlags;
                } else {
                    $flags = $arg1 instanceof \Phasis\Value\JsUndefined
                        ? \Phasis\Spec\TypeConversion::toString($arg0->get('flags'))
                        : \Phasis\Spec\TypeConversion::toString($arg1);
                }
                $result = $interp->createRegExpFromConstructor($pattern, $flags, $isSubclass);
                if ($subProto !== null) {
                    $result->setPrototype($subProto);
                }
                return $result;
            }

            $pattern = $arg0 instanceof \Phasis\Value\JsUndefined
                ? ''
                : \Phasis\Spec\TypeConversion::toString($arg0);
            $flags = $arg1 instanceof \Phasis\Value\JsUndefined
                ? ''
                : \Phasis\Spec\TypeConversion::toString($arg1);
            $result = $interp->createRegExpFromConstructor($pattern, $flags, $isSubclass);
            if ($subProto !== null) {
                $result->setPrototype($subProto);
            }
            return $result;
        };
        $this->installStubConstructor('RegExp', $regExpCb, 2);

        // Install Symbol methods on RegExp.prototype.
        /** @var \Phasis\Value\JsObject $regexpProto */
        $regexpProto = $this->globalEnv->get('__RegExpPrototype__');
        \Phasis\BuiltIn\RegExpPrototype::install($regexpProto);

        // Annex B: Legacy RegExp static properties.
        $regExpCtor = $this->globalEnv->get('RegExp');
        if ($regExpCtor instanceof JsFunction) {
            $this->installLegacyRegExpStatics($regExpCtor);

            // RegExp[@@species] per spec: accessor property, getter returns `this`.
            $speciesGetter = JsFunction::fromCallable(
                'get [Symbol.species]',
                function (\Phasis\Value\JsValue $this_): \Phasis\Value\JsValue {
                    return $this_;
                },
                0,
            );
            $regExpCtor->definePropertyBySymbol(
                \Phasis\BuiltIn\SymbolConstructor::species(),
                \Phasis\Object\PropertyDescriptor::accessor(
                    get: $speciesGetter,
                    set: null,
                    enumerable: false,
                    configurable: true,
                ),
            );

            // RegExp.escape(string) per spec proposal.
            \Phasis\BuiltIn\RegExpEscape::install($regExpCtor);
        }

        // %AsyncFunction% intrinsic: the constructor for async functions.
        // Not exposed as a global, but accessible via (async function(){}).constructor.
        $this->installAsyncFunctionIntrinsic();
    }

    /**
     * Lazy-mode counterpart of `installBuiltins`'s heavy block: instead
     * of running each install up front, record (names → factory + deps)
     * in the lazy registry. The factories run from inside the accessor
     * placeholder on first read of any of the module's names. Deps run
     * first, mirroring the install order the eager branch uses.
     */
    private function registerLazyBuiltins(): void
    {
        $env = $this->globalEnv;
        $reg = $this->lazyRegistry;
        $console = $this->console;

        // --- ECMAScript built-ins that aren't part of the eager core ---
        $reg->register(['Math'], [], static fn () => \Phasis\BuiltIn\MathObject::install($env));
        $reg->register(['JSON'], [], static fn () => \Phasis\BuiltIn\JsonObject::install($env));
        $reg->register(['Map'], [], static fn () => \Phasis\BuiltIn\MapConstructor::install($env));
        $reg->register(['Set'], [], static fn () => \Phasis\BuiltIn\SetConstructor::install($env));
        $reg->register(
            [
                'Int8Array', 'Uint8Array', 'Uint8ClampedArray',
                'Int16Array', 'Uint16Array',
                'Int32Array', 'Uint32Array',
                'Float16Array', 'Float32Array', 'Float64Array',
                'BigInt64Array', 'BigUint64Array',
                'ArrayBuffer', 'SharedArrayBuffer', 'DataView',
            ],
            [],
            static fn () => \Phasis\BuiltIn\TypedArrayConstructor::install($env),
        );
        $reg->register(
            ['Atomics'],
            ['Int32Array'],
            static fn () => \Phasis\BuiltIn\AtomicsObject::install($env),
        );
        $reg->register(['Proxy'], [], static fn () => \Phasis\BuiltIn\ProxyConstructor::install($env));
        $reg->register(['Reflect'], [], static fn () => \Phasis\BuiltIn\ReflectObject::install($env));
        $reg->register(
            ['console'],
            [],
            static fn () => $env->defineVar('console', $console->create()),
        );
        $reg->register(['Intl'], [], static fn () => \Phasis\BuiltIn\IntlObject::install($env));
        $reg->register(['WeakMap'], [], static fn () => \Phasis\BuiltIn\WeakMapConstructor::install($env));
        $reg->register(['WeakSet'], [], static fn () => \Phasis\BuiltIn\WeakSetConstructor::install($env));
        $reg->register(['WeakRef'], [], static fn () => \Phasis\BuiltIn\WeakRefConstructor::install($env));
        $reg->register(
            ['FinalizationRegistry'],
            [],
            static fn () => \Phasis\BuiltIn\FinalizationRegistryConstructor::install($env),
        );
        $reg->register(
            ['DisposableStack', 'AsyncDisposableStack', 'SuppressedError'],
            [],
            static fn () => \Phasis\BuiltIn\DisposableStackConstructor::install($env),
        );
        $reg->register(
            ['ShadowRealm'],
            [],
            static fn () => \Phasis\BuiltIn\ShadowRealmConstructor::install($env),
        );
        $reg->register(['Temporal'], [], static fn () => \Phasis\BuiltIn\TemporalObject::install($env));

        // --- Web Platform Pack ---
        $reg->register(
            ['DOMException'],
            [],
            static fn () => \Phasis\BuiltIn\DomExceptionConstructor::install($env),
        );
        $reg->register(['atob', 'btoa'], [], static fn () => \Phasis\BuiltIn\Base64Functions::install($env));
        $reg->register(
            ['performance'],
            [],
            static fn () => \Phasis\BuiltIn\PerformanceObject::install($env),
        );
        $reg->register(
            ['TextEncoder'],
            [],
            static fn () => \Phasis\BuiltIn\TextEncoderConstructor::install($env),
        );
        $reg->register(
            ['TextDecoder'],
            [],
            static fn () => \Phasis\BuiltIn\TextDecoderConstructor::install($env),
        );
        $reg->register(
            ['URL', 'URLSearchParams'],
            [],
            static fn () => \Phasis\BuiltIn\UrlConstructor::install($env),
        );
        $reg->register(
            ['structuredClone'],
            // ArrayBuffer because cloning AB/TypedArray copies into a
            // fresh JsArrayBuffer whose prototype comes from the
            // TypedArray module's static cache.
            ['DOMException', 'ArrayBuffer'],
            static fn () => \Phasis\BuiltIn\StructuredCloneFunction::install($env),
        );

        // --- Fetch Pack ---
        $reg->register(
            ['EventTarget', 'Event', 'CustomEvent'],
            [],
            static fn () => \Phasis\BuiltIn\EventTargetConstructor::install($env),
        );
        $reg->register(
            ['Blob', 'File'],
            // Blob.arrayBuffer() / .bytes() / .stream() create
            // JsArrayBuffer + Uint8Array + ReadableStream directly,
            // so realize those modules' prototype caches first.
            ['ArrayBuffer', 'ReadableStream'],
            static fn () => \Phasis\BuiltIn\BlobConstructor::install($env),
        );
        $reg->register(
            ['FormData'],
            [],
            static fn () => \Phasis\BuiltIn\FormDataConstructor::install($env),
        );
        $reg->register(
            ['Headers'],
            [],
            static fn () => \Phasis\BuiltIn\HeadersConstructor::install($env),
        );
        $reg->register(
            ['AbortController', 'AbortSignal'],
            ['EventTarget'],
            static fn () => \Phasis\BuiltIn\AbortControllerConstructor::install($env),
        );
        $reg->register(
            [
                'ReadableStream',
                'ReadableStreamDefaultController',
                'ReadableStreamDefaultReader',
                'ReadableByteStreamController',
                'ReadableStreamBYOBReader',
                'ReadableStreamBYOBRequest',
                'WritableStream',
                'WritableStreamDefaultController',
                'WritableStreamDefaultWriter',
                'TransformStream',
                'TransformStreamDefaultController',
                'ByteLengthQueuingStrategy',
                'CountQueuingStrategy',
            ],
            // ArrayBuffer because ByteStream allocates JsArrayBuffer
            // and Uint8Array views directly during read/pipe paths.
            ['EventTarget', 'ArrayBuffer'],
            static fn () => \Phasis\BuiltIn\StreamsConstructor::install($env),
        );
        $reg->register(
            ['Request'],
            // ArrayBuffer because BodyMixin allocates one when the
            // body bytes are consumed via .arrayBuffer().
            ['Headers', 'Blob', 'FormData', 'AbortController', 'ReadableStream', 'ArrayBuffer'],
            static fn () => \Phasis\BuiltIn\RequestConstructor::install($env),
        );
        $reg->register(
            ['Response'],
            ['Headers', 'Blob', 'FormData', 'ReadableStream', 'ArrayBuffer'],
            static fn () => \Phasis\BuiltIn\ResponseConstructor::install($env),
        );
        $reg->register(
            ['navigator'],
            [],
            static fn () => \Phasis\BuiltIn\NavigatorObject::install($env),
        );
        $reg->register(
            ['fetch'],
            ['Request', 'Response', 'URL', 'Headers', 'AbortController', 'ReadableStream', 'Blob'],
            static fn () => \Phasis\BuiltIn\FetchFunction::install($env),
        );

        // --- Event loop: timers + queueMicrotask ---
        // No deps — TimerFunctions reaches into the realm's
        // EventLoop, which the Engine constructor builds before
        // these accessors can fire.
        $reg->register(
            ['setTimeout', 'setInterval', 'clearTimeout', 'clearInterval', 'queueMicrotask'],
            [],
            static fn () => \Phasis\BuiltIn\TimerFunctions::install($env),
        );

        // --- WebCrypto ---
        $reg->register(
            ['crypto'],
            // ArrayBuffer because digest / sign / encrypt return fresh
            // ArrayBuffers whose prototype lives in the TypedArray
            // module's static cache.
            ['ArrayBuffer'],
            static fn () => \Phasis\BuiltIn\CryptoObject::install($env),
        );

        // --- XMLHttpRequest + ProgressEvent ---
        $reg->register(
            ['XMLHttpRequest', 'ProgressEvent'],
            // Uses the realm's fetch transport on send().
            [],
            static fn () => \Phasis\BuiltIn\XMLHttpRequestConstructor::install($env),
        );

        // --- WebSocket ---
        $reg->register(
            ['WebSocket'],
            // ArrayBuffer for binary message data.
            ['ArrayBuffer'],
            static fn () => \Phasis\BuiltIn\WebSocketConstructor::install($env),
        );

        // --- AsyncContext (TC39 Stage 3) ---
        $reg->register(
            ['AsyncContext'],
            [],
            static fn () => \Phasis\BuiltIn\AsyncContextConstructor::install($env),
        );
    }

    /**
     * Set up the %AsyncFunction% constructor and %AsyncFunction.prototype%.
     * Per spec 25.7: AsyncFunction is not directly exposed as a global property,
     * but is reachable via the constructor property of any async function instance.
     */
    private function installAsyncFunctionIntrinsic(): void
    {
        $fnProto = null;
        if ($this->globalEnv->has('Function')) {
            $fnCtor = $this->globalEnv->get('Function');
            if ($fnCtor instanceof JsFunction) {
                $fnProto = $fnCtor->get('prototype');
            }
        }

        // %AsyncFunction.prototype% is a non-callable ordinary object.
        // Its [[Prototype]] is Function.prototype.
        $asyncFuncProto = new JsObject($fnProto instanceof JsObject ? $fnProto : null);

        // Symbol.toStringTag = "AsyncFunction", non-writable, non-enumerable, configurable.
        $toStringTagSym = \Phasis\BuiltIn\SymbolConstructor::toStringTag();
        $asyncFuncProto->definePropertyBySymbol(
            $toStringTagSym,
            \Phasis\Object\PropertyDescriptor::data(new JsString('AsyncFunction'), false, false, true),
        );

        // Create the %AsyncFunction% constructor itself.
        // Per spec 25.7.1, AsyncFunction can be called with or without new.
        // It creates a new async function from source text, like Function() for regular functions.
        $interp = $this->interpreter;
        $globalEnv = $this->globalEnv;

        $asyncFuncCtor = JsFunction::fromCallable('AsyncFunction', static function (
            JsValue $this_,
            array $args,
        ) use (
            $interp,
        ): JsValue {
            // Build async function source from arguments, same as Function constructor.
            $bodyArg = count($args) > 0 ? array_pop($args) : JsUndefined::instance();
            $paramParts = [];
            foreach ($args as $a) {
                $paramParts[] = TypeConversion::toString($a);
            }
            $paramStr = implode(',', $paramParts);
            $bodyStr = TypeConversion::toString($bodyArg);
            // Per Function.prototype.toString spec, dynamic-function source is
            // formatted exactly as: `async function anonymous(<params>\n) {\n<body>\n}`.
            $source = "async function anonymous({$paramStr}\n) {\n{$bodyStr}\n}";
            // Parse by wrapping in parentheses so the function expression
            // is the whole program result.
            $parseSource = "(" . $source . ")";
            $parser = new Parser($parseSource);
            $ast = $parser->parse();
            // Per spec 25.7.1 step 29: params for async functions must not
            // contain AwaitExpression. YieldExpression is also rejected since
            // it's never a valid binding initializer.
            \Phasis\BuiltIn\GlobalObject::rejectYieldAwaitInParamsPublic($ast);

            $fn = $interp->execute($ast);
            if ($fn instanceof JsFunction) {
                $fn->setSourceText($source);
            }
            return $fn;
        }, 1);
        $asyncFuncCtor->setConstructable();

        // %AsyncFunction%.prototype = %AsyncFunction.prototype%
        $asyncFuncCtor->defineOwnProperty('prototype', \Phasis\Object\PropertyDescriptor::data(
            $asyncFuncProto,
            false,
            false,
            false,
        ));

        // %AsyncFunction.prototype%.constructor = %AsyncFunction%
        $asyncFuncProto->defineOwnProperty('constructor', \Phasis\Object\PropertyDescriptor::data(
            $asyncFuncCtor,
            true,
            false,
            true,
        ));

        // Per spec, AsyncFunction.__proto__ === Function (the Function constructor).
        if ($this->globalEnv->has('Function')) {
            $fnCtorVal = $this->globalEnv->get('Function');
            if ($fnCtorVal instanceof JsFunction) {
                $asyncFuncCtor->setCustomPrototype($fnCtorVal);
            }
        }

        // Store AsyncFunction.prototype so async function instances can find their constructor.
        JsFunction::setAsyncFunctionPrototype($asyncFuncProto);
        // Stash on globalEnv so the realm-aware JsFunction::getPrototype
        // lookup can find the per-realm copy after sibling Engines have
        // overwritten the process-wide static.
        $this->globalEnv->defineVar('__AsyncFunctionPrototype__', $asyncFuncProto);
    }

    /**
     * Install Annex B legacy static properties on the RegExp constructor.
     * These track the state of the last successful regexp match.
     */
    private function installLegacyRegExpStatics(JsFunction $ctor): void
    {
        $stateKeyMap = [
            'input' => 'legacyInput',
            'lastMatch' => 'legacyLastMatch',
            'lastParen' => 'legacyLastParen',
            'leftContext' => 'legacyLeftContext',
            'rightContext' => 'legacyRightContext',
        ];

        $makeGetter = function (string $stateKey) use ($ctor, $stateKeyMap): JsFunction {
            return JsFunction::fromCallable(
                'get ' . $stateKey,
                function (JsValue $this_) use ($ctor, $stateKey, $stateKeyMap): JsValue {
                    if ($this_ !== $ctor) {
                        throw new \Phasis\Exceptions\TypeError(
                            'Method get RegExp.' . $stateKey . ' called on incompatible receiver',
                        );
                    }
                    $field = $stateKeyMap[$stateKey];
                    return new JsString(\Phasis\BuiltIn\RegExpPrototype::$$field);
                },
                0,
            );
        };

        $makeSetter = function (string $stateKey) use ($ctor, $stateKeyMap): JsFunction {
            return JsFunction::fromCallable(
                'set ' . $stateKey,
                function (JsValue $this_, array $args) use ($ctor, $stateKey, $stateKeyMap): JsValue {
                    if ($this_ !== $ctor) {
                        throw new \Phasis\Exceptions\TypeError(
                            'Method set RegExp.' . $stateKey . ' called on incompatible receiver',
                        );
                    }
                    $field = $stateKeyMap[$stateKey];
                    \Phasis\BuiltIn\RegExpPrototype::$$field = isset($args[0])
                        ? TypeConversion::toString($args[0])
                        : '';
                    return JsUndefined::instance();
                },
                1,
            );
        };

        $accessor = function (
            string $prop,
            string $alias,
            string $key,
            bool $hasSetter
        ) use (
            $ctor,
            $makeGetter,
            $makeSetter,
        ): void {
            $getter = $makeGetter($key);
            $setter = $hasSetter ? $makeSetter($key) : null;
            $desc = \Phasis\Object\PropertyDescriptor::accessor(
                get: $getter,
                set: $setter,
                enumerable: false,
                configurable: true,
            );
            $ctor->defineOwnProperty($prop, $desc);
            $ctor->defineOwnProperty($alias, $desc);
        };

        $accessor('input', '$_', 'input', true);
        $accessor('lastMatch', '$&', 'lastMatch', false);
        $accessor('rightContext', "\$'", 'rightContext', false);
        $accessor('leftContext', '$`', 'leftContext', false);
        $accessor('lastParen', '$+', 'lastParen', false);

        for ($i = 1; $i <= 9; $i++) {
            $idx = $i;
            $getter = JsFunction::fromCallable(
                'get $' . $i,
                function (JsValue $this_) use ($ctor, $idx): JsValue {
                    if ($this_ !== $ctor) {
                        throw new \Phasis\Exceptions\TypeError(
                            'Method get RegExp.$' . $idx . ' called on incompatible receiver',
                        );
                    }
                    return new JsString(\Phasis\BuiltIn\RegExpPrototype::$legacyGroups[$idx - 1] ?? '');
                },
                0,
            );
            $ctor->defineOwnProperty(
                '$' . $i,
                \Phasis\Object\PropertyDescriptor::accessor(
                    get: $getter,
                    set: null,
                    enumerable: false,
                    configurable: true,
                ),
            );
        }
    }

    private function installStubConstructor(string $name, callable $fn, int $length = 0): void
    {
        $constructor = \Phasis\Value\JsFunction::fromCallable($name, $fn, $length);
        $constructor->setConstructable();
        $proto = new \Phasis\Value\JsObject();
        // Per spec, constructor is writable, non-enumerable, configurable.
        $proto->defineOwnProperty(
            'constructor',
            \Phasis\Object\PropertyDescriptor::data($constructor, true, false, true),
        );
        // Per spec, built-in constructor .prototype is non-writable, non-enumerable, non-configurable.
        $constructor->defineOwnProperty('prototype', \Phasis\Object\PropertyDescriptor::data(
            $proto,
            false,
            false,
            false,
        ));
        $this->globalEnv->defineVar($name, $constructor);
        // Store prototype for internal use (e.g. Interpreter::createRegExpObject).
        $this->globalEnv->defineVar("__{$name}Prototype__", $proto);
    }

    public function eval(string $source): mixed
    {
        $parser = new Parser($source);
        $program = $parser->parse();
        // Capture any `//# sourceURL=URL` directive so stack traces emitted
        // by Errors thrown from this evaluation point at the advertised URL
        // rather than the caller's location.
        $sourceUrl = $parser->getSourceURL();
        if ($sourceUrl !== null) {
            self::pushSourceURL($sourceUrl);
        }
        // Restore this engine's interpreter as the active one so realm-
        // sensitive lookups (Symbol.prototype etc.) resolve against this
        // realm's globals rather than a sibling Engine that wrote the
        // static last (e.g. a ShadowRealm constructed earlier).
        $previousInterpreter = self::$currentInterpreter;
        self::$currentInterpreter = $this->interpreter;
        try {
            $result = $this->interpreter->execute($program);
            // Drain the full event loop: microtasks first, then any
            // due timers, then advance the virtual clock to fire
            // pending timers — same shape as `node script.js` running
            // a script to completion. Programs that schedule a
            // `setTimeout(cb, 100)` get the callback to fire before
            // eval returns; programs with leaked setIntervals trip
            // the runUntilEmpty iteration cap.
            $this->eventLoop->runUntilEmpty();
            return $this->toPhp($result);
        } finally {
            self::$currentInterpreter = $previousInterpreter;
            if ($sourceUrl !== null) {
                self::popSourceURL();
            }
        }
    }

    /**
     * Evaluate source text as an ES module.
     *
     * Modules are always strict. Import/export declarations are processed.
     * For file-based imports, the current module path must be set beforehand
     * so that relative specifiers resolve correctly.
     *
     * @param string $source The module source text.
     * @param string|null $path Optional absolute path for this module (used for import resolution).
     */
    public function evalAsModule(string $source, ?string $path = null): mixed
    {
        $modulePath = $path ?? $this->interpreter->getCurrentModulePath() ?? '/virtual-module.mjs';
        $loader = $this->interpreter->getModuleLoader();
        $loader->evaluateModule($modulePath, $source);
        // Drain the full event loop, matching eval() semantics.
        $this->eventLoop->runUntilEmpty();
        // Module namespace objects can be self-referential (export * from self),
        // so converting to PHP would cause infinite recursion. Return null instead.
        // Callers that need the namespace should use the ModuleLoader directly.
        return null;
    }

    public function execFile(string $path): mixed
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("Cannot read file: {$path}");
        }
        $realPath = realpath($path);
        if ($realPath !== false) {
            $this->interpreter->setCurrentModulePath($realPath);
        }
        return $this->eval($source);
    }

    /**
     * Set the current module path for resolving relative import specifiers.
     * Used by the test262 runner to make import() resolve relative to the test file.
     */
    public function setCurrentModulePath(string $path): void
    {
        $this->interpreter->setCurrentModulePath($path);
    }

    public function setGlobal(string $name, mixed $value): void
    {
        $jsValue = PhpToJs::convert($value);
        $this->globalEnv->defineVar($name, $jsValue);
    }

    /**
     * Set a global variable to a raw JsValue without PHP-to-JS conversion.
     * Used by test harness to inject special values like IsHTMLDDA.
     */
    public function setGlobalJsValue(string $name, JsValue $value): void
    {
        $this->globalEnv->defineVar($name, $value);
    }

    public function call(string $name, mixed ...$args): mixed
    {
        $fn = $this->globalEnv->get($name);
        if (!$fn instanceof JsFunction) {
            throw new Exceptions\TypeError("{$name} is not a function");
        }

        $jsArgs = array_map(fn($a) => PhpToJs::convert($a), $args);
        $result = $this->interpreter->callFunction($fn, JsUndefined::instance(), $jsArgs);
        return $this->toPhp($result);
    }

    public function getConsoleOutput(): string
    {
        return $this->console->getOutputString();
    }

    /** @return list<string> */
    public function getConsoleLines(): array
    {
        return $this->console->getOutput();
    }

    public function clearConsole(): void
    {
        $this->console->clear();
    }

    /**
     * Get the interpreter for ShadowRealm evaluation.
     */
    public function getInterpreter(): Interpreter
    {
        return $this->interpreter;
    }

    /**
     * Get the global environment for ShadowRealm evaluation.
     */
    public function getGlobalEnv(): Environment
    {
        return $this->globalEnv;
    }

    /**
     * Swap in a custom HTTP transport for `fetch()`. The callable receives
     * `(array $descriptor, ?\Phasis\Value\JsObject $signal)` and must
     * return an array shaped `['status' => int, 'statusText' => string,
     * 'headers' => list<[string,string]>, 'body' => string]`.
     *
     * Throwing from the callable rejects the fetch promise. To indicate
     * an abort, throw a `\Phasis\BuiltIn\Fetch\TransportException` with
     * kind="aborted"; anything else maps to TypeError.
     */
    public function setFetchTransport(callable $t): void
    {
        $this->fetchTransport = $t;
    }

    /**
     * Install the WebSocket transport. The callable receives:
     *
     *   `(string $url, list<string> $protocols, callable $emit): array`
     *
     * where `$emit(string $type, array $data = []): void` is the
     * bridge to the JS event surface. The supported types are
     * 'open', 'message' (with data + isBinary keys), 'close' (with
     * code, reason, wasClean), and 'error' (with message).
     *
     * The returned array must carry `'send' => callable(mixed $data,
     * bool $isBinary): void` and `'close' => callable(int $code,
     * string $reason): void`.
     *
     * Without this, `new WebSocket(...)` throws TypeError — there is
     * no default transport in this build.
     */
    public function setWebSocketTransport(callable $t): void
    {
        $this->webSocketTransport = $t;
    }

    /**
     * Install a pre-flight policy hook. The hook is called with the
     * Request JsObject before the transport call:
     *
     *  - Return null/void → use the request as-is.
     *  - Return a Request → replace the outgoing request.
     *  - Throw → reject the fetch promise.
     */
    public function setFetchPolicy(callable $hook): void
    {
        $this->fetchPolicy = $hook;
    }

    /**
     * Mount a cookie jar. The jar must respond to `get(url): string`
     * (returning a `Cookie:` header value, e.g. "k=v; k2=v2") and
     * `set(url, header): void` (receiving a single `Set-Cookie` line).
     *
     * Both JS-side JsObject (with JsFunction members named "get"/"set")
     * and plain PHP objects with matching method names are accepted.
     * Passing null clears the jar.
     */
    public function setCookieJar(mixed $jar): void
    {
        $this->cookieJar = $jar;
    }

    /**
     * Read the currently installed fetch transport callable. Returns
     * null when the default `CurlTransport::send()` should be used.
     */
    public function getFetchTransport(): ?callable
    {
        return $this->fetchTransport;
    }

    /** Read the currently installed WebSocket transport (may be null). */
    public function getWebSocketTransport(): ?callable
    {
        return $this->webSocketTransport;
    }

    /** Read the currently installed fetch policy hook (may be null). */
    public function getFetchPolicy(): ?callable
    {
        return $this->fetchPolicy;
    }

    /** Read the currently mounted cookie jar (may be null). */
    public function getCookieJar(): mixed
    {
        return $this->cookieJar;
    }

    public function reset(): void
    {
        // Detach the old event loop's post-drain hook before clearing
        // the microtask queue. clearMicrotasks() also nulls the hook
        // but doing it explicitly via the loop keeps the contract
        // ownership crisp.
        $this->eventLoop->detach();
        \Phasis\Value\JsPromise::clearMicrotasks();
        \Phasis\Runtime\AsyncContextStorage::reset();
        self::resetStaticIntrinsics();
        $this->globalEnv = new Environment();
        $this->console = new ConsoleObject();
        $this->callStack = new CallStack();
        $this->interpreter = new Interpreter($this->globalEnv, $this->callStack);
        $this->interpreter->setRealm($this);
        self::$currentInterpreter = $this->interpreter;
        $this->lazyRegistry = new LazyBuiltinRegistry();

        $this->installBuiltins();
        $this->eventLoop = new EventLoop($this);
    }

    /**
     * Reset cached intrinsic prototypes shared across BuiltIn classes.
     * Each new Engine instance must rebuild its own prototype graph so
     * test262 cross-realm tests see fresh object identities.
     */
    private static function resetStaticIntrinsics(): void
    {
        \Phasis\BuiltIn\RegExpPrototype::resetStringIteratorProto();
        \Phasis\BuiltIn\Streams\ReadableStream::resetAsyncIteratorPrototype();
    }

    /**
     * Create a RegExp object using the current interpreter.
     * Used by String.prototype methods to create RegExp from string arguments per spec.
     */
    public static function createRegExp(string $pattern, string $flags): ?\Phasis\Value\JsObject
    {
        if (self::$currentInterpreter === null) {
            return null;
        }
        try {
            return self::$currentInterpreter->createRegExpFromConstructor($pattern, $flags);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get the current interpreter for built-in methods.
     */
    public static function getCurrentInterpreter(): ?\Phasis\Runtime\Interpreter
    {
        return self::$currentInterpreter;
    }

    /**
     * The Engine (realm) backing the currently-active interpreter. Used
     * by GetFunctionRealm so new JsFunction instances and host built-ins
     * are tagged with their creating realm. Returns null only when no
     * interpreter is bound (e.g. during very early static initialisation).
     */
    public static function getCurrentRealm(): ?\Phasis\Engine
    {
        return self::$currentInterpreter?->getRealm();
    }

    /**
     * Restore the active interpreter after a sibling Engine
     * (typically a ShadowRealm) wrote the static during construction.
     */
    public static function setCurrentInterpreter(?\Phasis\Runtime\Interpreter $interpreter): void
    {
        self::$currentInterpreter = $interpreter;
    }

    /**
     * Create a RegExp object, propagating exceptions.
     * Used by RegExp.prototype.compile where errors must be visible to JS code.
     */
    public static function createRegExpOrThrow(string $pattern, string $flags): JsObject
    {
        if (self::$currentInterpreter === null) {
            throw new \Phasis\Exceptions\TypeError('Cannot compile RegExp');
        }
        return self::$currentInterpreter->createRegExpFromConstructor($pattern, $flags);
    }

    public function setLimit(string $name, int $value): void
    {
        if ($name === 'maxLoopIterations') {
            $this->interpreter->setMaxLoopIterations($value);
        }
    }

    /** @param array<int, true> $seen Object-id set used to break self-referential graphs (globalThis). */
    private function toPhp(JsValue $value, array $seen = []): mixed
    {
        if ($value instanceof JsUndefined || $value instanceof \Phasis\Value\JsNull) {
            return null;
        }
        if ($value instanceof \Phasis\Value\JsBoolean) {
            return $value->toBoolean();
        }
        if ($value instanceof \Phasis\Value\JsNumber) {
            $num = $value->value;
            if (is_nan($num)) {
                return NAN;
            }
            if ($num === INF) {
                return INF;
            }
            if ($num === -INF) {
                return -INF;
            }
            if ($num == (int) $num && abs($num) < PHP_INT_MAX) {
                return (int) $num;
            }
            return $num;
        }
        if ($value instanceof \Phasis\Value\JsString) {
            return $value->value;
        }
        if ($value instanceof \Phasis\Value\JsFunction) {
            return null; // Functions don't convert to PHP values
        }
        if ($value instanceof \Phasis\Value\JsArray) {
            $oid = spl_object_id($value);
            if (isset($seen[$oid])) {
                return null;
            }
            $seen[$oid] = true;
            $result = [];
            $len = $value->getLength();
            for ($i = 0; $i < $len; $i++) {
                $result[] = $this->toPhp($value->get((string) $i), $seen);
            }
            return $result;
        }
        if ($value instanceof \Phasis\Value\JsTypedArray) {
            $result = [];
            $len = $value->getLength();
            for ($i = 0; $i < $len; $i++) {
                $result[] = $this->toPhp($value->getIndex($i), $seen);
            }
            return $result;
        }
        if ($value instanceof JsObject) {
            $oid = spl_object_id($value);
            if (isset($seen[$oid])) {
                return null;
            }
            $seen[$oid] = true;
            $result = [];
            // Revoked Proxy: any trap (including ownKeys) throws TypeError.
            // Returning an empty result keeps Engine::eval() from surfacing
            // that throw to the PHP caller for tests whose final expression
            // happens to evaluate to a revoked proxy. JS-side behaviour is
            // unchanged: the proxy is still observably revoked.
            if ($value instanceof \Phasis\Value\JsProxy && $value->isRevoked()) {
                return $result;
            }
            // Reading a live proxy's keys / properties is observable and
            // could throw from user-supplied traps; same as a getter, we
            // skip conversion entirely to avoid surfacing host-side side
            // effects through the eval result.
            if ($value instanceof \Phasis\Value\JsProxy) {
                return $result;
            }
            foreach ($value->getOwnPropertyNames() as $key) {
                // Use the property descriptor so an accessor whose
                // get() throws does not propagate up through the
                // eval-result conversion. Data slots and accessors
                // both convert via $value->get($key), which fires the
                // user-supplied getter and can fail; reading the
                // descriptor first lets us skip accessors safely.
                $desc = $value->getOwnPropertyDescriptor($key);
                if ($desc === null) {
                    continue;
                }
                if ($desc->isAccessorDescriptor()) {
                    continue;
                }
                $val = $desc->value ?? $value->get($key);
                if ($val instanceof \Phasis\Value\JsFunction) {
                    continue; // Skip function properties
                }
                $result[$key] = $this->toPhp($val, $seen);
            }
            return $result;
        }
        return null;
    }
}
