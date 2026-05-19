<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\BuiltIn\DomExceptionConstructor;
use Phasis\BuiltIn\Url\UrlParser;
use Phasis\BuiltIn\Url\UrlRecord;
use Phasis\BuiltIn\Url\UrlSerializer;
use Phasis\BuiltIn\WebSocket\StreamSocketTransport;
use Phasis\Engine;
use Phasis\Exceptions\JsThrowable;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsString;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WebSocket — RFC 6455 client API on the global object.
 *
 * Wire transport is pluggable. The realm's WebSocket transport
 * (installed via `Engine::setWebSocketTransport()`) is invoked when
 * the JS code does `new WebSocket(url, protocols)`. The transport
 * receives the URL + protocols + an event-emitter and returns a
 * handle exposing `send()` and `close()` PHP closures. Phasis owns
 * the JS-visible state machine, event dispatch, and protocol
 * conversions; the transport owns sockets, framing, masking, and
 * any TLS handling.
 *
 * Default transport: NOT shipped in this batch. Embedders install
 * their own (pawl, ratchet, react-socket, etc.). Without a
 * transport the constructor throws — same shape fetch had before
 * `CurlTransport` was the default.
 *
 * Transport contract (PHP side):
 *
 *   $transport: callable(string $url, list<string> $protocols, callable $emit): array
 *     where $emit is callable(string $type, array $data): void
 *     and the returned array has keys:
 *       'send'  => callable(string|JsArrayBuffer|JsTypedArray $data): void
 *       'close' => callable(int $code, string $reason): void
 *
 * The transport invokes `$emit('open', [])` when the socket finishes
 * the WebSocket handshake, `$emit('message', ['data' => string|bytes,
 * 'isBinary' => bool])` for each frame, `$emit('close', ['code' =>
 * int, 'reason' => string, 'wasClean' => bool])` on close, and
 * `$emit('error', ['message' => string])` on a transport error.
 *
 * JS state machine values match the spec: CONNECTING=0, OPEN=1,
 * CLOSING=2, CLOSED=3.
 *
 * Events: open, message, close, error. Fired via the inline
 * EventTarget mixin (addEventListener / removeEventListener /
 * dispatchEvent) plus the on<event> handler attributes.
 *
 * `binaryType` controls how binary frames surface — 'blob' (default
 * per spec but rare in Node-flavored use) or 'arraybuffer'. We
 * default to 'arraybuffer' to match the most common Node-style
 * usage; embedders can change it.
 */
final class WebSocketConstructor
{
    public static function install(Environment $env): void
    {
        $proto = new JsObject();

        $ctor = JsFunction::fromCallable(
            'WebSocket',
            static function (JsValue $this_, array $args) use ($proto, $env): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor WebSocket requires 'new'");
                }
                $url = TypeConversion::toString($args[0] ?? JsUndefined::instance());
                if ($url === '') {
                    throw new TypeError(
                        "Failed to construct 'WebSocket': URL argument is missing",
                    );
                }
                $protocols = self::collectProtocols($args[1] ?? JsUndefined::instance());

                // Spec validation:
                //   1. Parse URL (scheme is rewritten http→ws, https→wss).
                //   2. If parse fails or scheme is not ws/wss → SyntaxError.
                //   3. If the URL has a fragment → SyntaxError.
                //   4. Each protocol must be a valid HTTP token, and the
                //      list must have no case-insensitive duplicates.
                $url = self::validateAndNormalizeUrl($url, $env);
                self::validateProtocols($protocols, $env);

                $this_->setPrototype($proto);
                self::initInstance($this_, $url);

                // Resolve the transport at construct time so the error
                // is observable on `new WebSocket(...)`, not later.
                // The realm-installed transport wins; otherwise we fall
                // back to the pure-PHP default
                // (stream_socket_client + RFC 6455 framing).
                $realm = Engine::getCurrentRealm();
                $transport = $realm?->getWebSocketTransport();
                if ($transport === null) {
                    $transport = StreamSocketTransport::create();
                }

                // The emitter the transport calls with lifecycle events.
                // Bound to this instance.
                $ws = $this_;
                $emit = static function (string $type, array $data = []) use ($ws): void {
                    self::handleTransportEvent($ws, $type, $data);
                };

                try {
                    $handle = $transport($url, $protocols, $emit);
                } catch (\Throwable $e) {
                    // Surface transport errors as an asynchronous 'error'
                    // + 'close' rather than throwing — matches browser
                    // behavior where `new WebSocket(bad-url)` resolves
                    // to closed with no JS-side throw.
                    JsPromise::scheduleCallback(static function () use ($ws, $e): void {
                        self::handleTransportEvent($ws, 'error', ['message' => $e->getMessage()]);
                        self::handleTransportEvent($ws, 'close', [
                            'code' => 1006,
                            'reason' => $e->getMessage(),
                            'wasClean' => false,
                        ]);
                    });
                    return $this_;
                }

                if (
                    !is_array($handle)
                    || !isset($handle['send'], $handle['close'])
                    || !is_callable($handle['send'])
                    || !is_callable($handle['close'])
                ) {
                    throw new TypeError(
                        "WebSocket transport returned an invalid handle. "
                        . "Expected ['send' => callable, 'close' => callable]."
                    );
                }
                $this_->setInternalProperty('[[WsSend]]', $handle['send']);
                $this_->setInternalProperty('[[WsClose]]', $handle['close']);

                return $this_;
            },
            2,
        );
        $ctor->setConstructable();
        $ctor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($proto, false, false, false),
        );
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($ctor, true, false, true),
        );

        foreach (
            [
                'CONNECTING' => 0,
                'OPEN' => 1,
                'CLOSING' => 2,
                'CLOSED' => 3,
            ] as $name => $val
        ) {
            $desc = PropertyDescriptor::data(JsNumber::of((float) $val), false, true, false);
            $ctor->defineOwnProperty($name, $desc);
            $proto->defineOwnProperty($name, $desc);
        }

        self::definePrototypeMethods($proto, $env);
        self::defineEventTargetMethods($proto);
        self::defineAccessors($proto);

        // Symbol.toStringTag = "WebSocket" per the WebSocket Standard.
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('WebSocket'), false, false, true),
        );

        $env->defineVar('WebSocket', $ctor);
    }

    private static function initInstance(JsObject $ws, string $url): void
    {
        $ws->setInternalProperty('[[IsWebSocket]]', true);
        $ws->setInternalProperty('[[IsEventTarget]]', true);
        $ws->setInternalProperty('[[EventListeners]]', []);
        $ws->setInternalProperty('[[ReadyState]]', 0); // CONNECTING
        $ws->setInternalProperty('[[Url]]', $url);
        $ws->setInternalProperty('[[Protocol]]', '');
        $ws->setInternalProperty('[[Extensions]]', '');
        $ws->setInternalProperty('[[BinaryType]]', 'arraybuffer');
        $ws->setInternalProperty('[[BufferedAmount]]', 0);
    }

    /**
     * Spec §3.1 step 2: parse the URL, rewrite http→ws / https→wss,
     * reject anything else with a SyntaxError DOMException, reject
     * a non-null fragment with SyntaxError. Returns the serialized
     * absolute URL ready to use as the wire URL.
     *
     * Relative URLs resolve against the realm's base URL — first
     * `globalThis.__phasisRequestBaseUrl` (set by the WPT runner),
     * then `globalThis.location.href`. The base URL must use a
     * special scheme (http/https/ws/wss) for the resolution to
     * carry; otherwise the parse fails and we surface SyntaxError
     * exactly like the spec.
     */
    private static function validateAndNormalizeUrl(string $input, Environment $env): string
    {
        $base = null;
        $realm = Engine::getCurrentRealm();
        if ($realm !== null) {
            try {
                $hook = $realm->getGlobalEnv()->get('__phasisRequestBaseUrl');
                if ($hook instanceof JsString && $hook->value !== '') {
                    $base = UrlParser::parse($hook->value);
                }
            } catch (\Throwable) {
                // ignore
            }
            if ($base === null) {
                try {
                    $loc = $realm->getGlobalEnv()->get('location');
                    if ($loc instanceof JsObject) {
                        $href = $loc->get('href');
                        if ($href instanceof JsString && $href->value !== '') {
                            $base = UrlParser::parse($href->value);
                        }
                    }
                } catch (\Throwable) {
                    // ignore
                }
            }
        }
        $record = UrlParser::parse($input, $base);
        if ($record === null) {
            throw new JsThrowable(DomExceptionConstructor::create(
                $env,
                "Failed to construct 'WebSocket': The URL '" . $input . "' is invalid.",
                'SyntaxError',
            ));
        }
        // Rewrite http(s) to the WebSocket scheme. Browsers accept
        // http/https as input and rewrite for forgiving parsing.
        if ($record->scheme === 'http') {
            $record->scheme = 'ws';
        } elseif ($record->scheme === 'https') {
            $record->scheme = 'wss';
        }
        if ($record->scheme !== 'ws' && $record->scheme !== 'wss') {
            throw new JsThrowable(DomExceptionConstructor::create(
                $env,
                "Failed to construct 'WebSocket': The URL's scheme must be either 'ws' or 'wss'. '"
                . $record->scheme . ":' is not allowed.",
                'SyntaxError',
            ));
        }
        if ($record->fragment !== null) {
            throw new JsThrowable(DomExceptionConstructor::create(
                $env,
                "Failed to construct 'WebSocket': The URL contains a fragment identifier ('#"
                . $record->fragment . "'). Fragment identifiers are not allowed in WebSocket URLs.",
                'SyntaxError',
            ));
        }
        return UrlSerializer::serializeUrl($record);
    }

    /**
     * Spec §3.1 step 3: each protocol string must be a non-empty
     * sequence of HTTP token characters (RFC 7230 §3.2.6). The list
     * must not contain case-insensitive duplicates. Both violations
     * raise a SyntaxError DOMException.
     *
     * @param list<string> $protocols
     */
    private static function validateProtocols(array $protocols, Environment $env): void
    {
        $seen = [];
        foreach ($protocols as $p) {
            if ($p === '' || !self::isHttpToken($p)) {
                throw new JsThrowable(DomExceptionConstructor::create(
                    $env,
                    "Failed to construct 'WebSocket': The subprotocol '" . $p
                    . "' is invalid.",
                    'SyntaxError',
                ));
            }
            $lc = strtolower($p);
            if (isset($seen[$lc])) {
                throw new JsThrowable(DomExceptionConstructor::create(
                    $env,
                    "Failed to construct 'WebSocket': The subprotocol '" . $p
                    . "' is duplicated.",
                    'SyntaxError',
                ));
            }
            $seen[$lc] = true;
        }
    }

    /**
     * HTTP token grammar (RFC 7230 §3.2.6):
     *   token = 1*tchar
     *   tchar = "!" / "#" / "$" / "%" / "&" / "'" / "*" / "+" / "-"
     *         / "." / "^" / "_" / "`" / "|" / "~" / DIGIT / ALPHA
     * Anything else (separators, spaces, non-ASCII, controls) fails.
     */
    private static function isHttpToken(string $s): bool
    {
        if ($s === '') {
            return false;
        }
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($s[$i]);
            $ok = ($c >= 0x30 && $c <= 0x39) // 0-9
                || ($c >= 0x41 && $c <= 0x5A) // A-Z
                || ($c >= 0x61 && $c <= 0x7A) // a-z
                || $c === 0x21 || $c === 0x23 || $c === 0x24
                || $c === 0x25 || $c === 0x26 || $c === 0x27
                || $c === 0x2A || $c === 0x2B || $c === 0x2D
                || $c === 0x2E || $c === 0x5E || $c === 0x5F
                || $c === 0x60 || $c === 0x7C || $c === 0x7E;
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    private static function collectProtocols(JsValue $val): array
    {
        if ($val instanceof JsUndefined || $val instanceof JsNull) {
            return [];
        }
        if ($val instanceof JsString) {
            return [$val->value];
        }
        if ($val instanceof JsObject) {
            $out = [];
            $len = $val->get('length');
            if (!$len instanceof JsUndefined) {
                $n = (int) TypeConversion::toNumber($len);
                for ($i = 0; $i < $n; $i++) {
                    $entry = $val->get((string) $i);
                    $out[] = TypeConversion::toString($entry);
                }
            }
            return $out;
        }
        return [];
    }

    private static function definePrototypeMethods(JsObject $proto, Environment $env): void
    {
        self::method($proto, 'send', 1, static function (JsValue $this_, array $args) use ($env): JsValue {
            $ws = self::requireWs($this_, 'send');
            $state = (int) ($ws->getInternalProperty('[[ReadyState]]') ?? 0);
            if ($state === 0) {
                // Spec: still in CONNECTING → InvalidStateError DOMException.
                throw new JsThrowable(DomExceptionConstructor::create(
                    $env,
                    "Failed to execute 'send' on 'WebSocket': Still in CONNECTING state.",
                    'InvalidStateError',
                ));
            }
            if ($state !== 1) {
                // CLOSING / CLOSED — per spec, the data is discarded
                // (no throw), but bufferedAmount stays unchanged.
                return JsUndefined::instance();
            }
            $send = $ws->getInternalProperty('[[WsSend]]');
            if (!is_callable($send)) {
                return JsUndefined::instance();
            }
            $payload = $args[0] ?? JsUndefined::instance();
            $bytes = self::payloadToBytes($payload);
            $send($bytes['data'], $bytes['isBinary']);
            // Per spec, bufferedAmount reflects bytes queued by
            // send() that have not yet been transmitted. We add the
            // payload byte count synchronously so JS that reads
            // bufferedAmount immediately after send() observes the
            // queued amount. Transports that surface real
            // backpressure can drive a future flush callback that
            // subtracts; the default transport effectively flushes
            // by the time the JS yield returns, but the spec-aligned
            // snapshot is what WPT asserts on.
            $previous = (int) ($ws->getInternalProperty('[[BufferedAmount]]') ?? 0);
            $ws->setInternalProperty('[[BufferedAmount]]', $previous + strlen($bytes['data']));
            return JsUndefined::instance();
        });

        self::method($proto, 'close', 2, static function (JsValue $this_, array $args) use ($env): JsValue {
            $ws = self::requireWs($this_, 'close');
            $state = (int) ($ws->getInternalProperty('[[ReadyState]]') ?? 0);
            if ($state >= 2) {
                return JsUndefined::instance();
            }
            // Spec §10.5.4: code is optional. When omitted (or
            // undefined) treat as "no code given" — no range check.
            // When provided, WebIDL coerces to unsigned short via
            // ToNumber → ToInteger → wrap to 16 bits.
            $codeArg = $args[0] ?? null;
            $codeProvided = $codeArg !== null && !$codeArg instanceof JsUndefined;
            if ($codeProvided) {
                // WebIDL `[Clamp] unsigned short` semantics: NaN
                // becomes 0, otherwise clamp to [0, 65535].
                $num = TypeConversion::toNumber($codeArg);
                if (is_nan($num)) {
                    $code = 0;
                } else {
                    $code = max(0, min(65535, (int) $num));
                }
            } else {
                // Per spec, close() with no code sends a frame with
                // no status code on the wire. We use 0 as a sentinel
                // when calling the transport — the transport sends an
                // empty close payload, and surfaces the receiver-side
                // close event with code 1005 ("No Status Received").
                $code = 0;
            }
            $reason = isset($args[1]) ? TypeConversion::toString($args[1]) : '';
            // Per spec, if code is provided, it must be 1000 or in
            // [3000, 4999]; otherwise throw InvalidAccessError.
            if ($codeProvided && $code !== 1000 && ($code < 3000 || $code > 4999)) {
                throw new JsThrowable(DomExceptionConstructor::create(
                    $env,
                    "Failed to execute 'close' on 'WebSocket': The code must be "
                    . "either 1000, or between 3000 and 4999.",
                    'InvalidAccessError',
                ));
            }
            // Reason must be <= 123 bytes UTF-8; otherwise SyntaxError.
            if (strlen($reason) > 123) {
                throw new JsThrowable(DomExceptionConstructor::create(
                    $env,
                    "Failed to execute 'close' on 'WebSocket': The close reason "
                    . "must not be greater than 123 bytes.",
                    'SyntaxError',
                ));
            }
            $ws->setInternalProperty('[[ReadyState]]', 2); // CLOSING
            $close = $ws->getInternalProperty('[[WsClose]]');
            if (is_callable($close)) {
                try {
                    $close($code, $reason);
                } catch (\Throwable $e) {
                    self::handleTransportEvent($ws, 'error', ['message' => $e->getMessage()]);
                    self::handleTransportEvent($ws, 'close', [
                        'code' => 1006,
                        'reason' => $e->getMessage(),
                        'wasClean' => false,
                    ]);
                }
            }
            return JsUndefined::instance();
        });
    }

    private static function method(JsObject $proto, string $name, int $length, \Closure $impl): void
    {
        $proto->defineOwnProperty(
            $name,
            PropertyDescriptor::data(
                JsFunction::fromCallable($name, $impl, $length),
                true,
                false,
                true,
            ),
        );
    }

    private static function defineEventTargetMethods(JsObject $proto): void
    {
        self::method($proto, 'addEventListener', 2, static function (JsValue $this_, array $args): JsValue {
            $ws = self::requireWs($this_, 'addEventListener');
            $type = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            $cb = $args[1] ?? JsUndefined::instance();
            if (!$cb instanceof JsFunction) {
                return JsUndefined::instance();
            }
            $listeners = $ws->getInternalProperty('[[EventListeners]]');
            if (!is_array($listeners)) {
                $listeners = [];
            }
            if (!isset($listeners[$type])) {
                $listeners[$type] = [];
            }
            foreach ($listeners[$type] as $existing) {
                if (($existing['callback'] ?? null) === $cb) {
                    return JsUndefined::instance();
                }
            }
            $listeners[$type][] = ['callback' => $cb];
            $ws->setInternalProperty('[[EventListeners]]', $listeners);
            return JsUndefined::instance();
        });

        self::method($proto, 'removeEventListener', 2, static function (JsValue $this_, array $args): JsValue {
            $ws = self::requireWs($this_, 'removeEventListener');
            $type = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            $cb = $args[1] ?? JsUndefined::instance();
            $listeners = $ws->getInternalProperty('[[EventListeners]]');
            if (!is_array($listeners) || !isset($listeners[$type])) {
                return JsUndefined::instance();
            }
            $listeners[$type] = array_values(array_filter(
                $listeners[$type],
                static fn ($entry) => ($entry['callback'] ?? null) !== $cb,
            ));
            $ws->setInternalProperty('[[EventListeners]]', $listeners);
            return JsUndefined::instance();
        });

        self::method($proto, 'dispatchEvent', 1, static function (JsValue $this_, array $args): JsValue {
            $ws = self::requireWs($this_, 'dispatchEvent');
            $event = $args[0] ?? JsUndefined::instance();
            $type = $event instanceof JsObject ? TypeConversion::toString($event->get('type')) : '';
            if ($type === '') {
                throw new TypeError("dispatchEvent: event has no type");
            }
            self::fireEvent($ws, $type, $event instanceof JsObject ? $event : null);
            return JsBoolean::of(true);
        });
    }

    private static function defineAccessors(JsObject $proto): void
    {
        $get = static function (string $name, \Closure $impl) use ($proto): void {
            $proto->defineOwnProperty(
                $name,
                PropertyDescriptor::accessor(
                    JsFunction::fromCallable('get ' . $name, $impl, 0),
                    null,
                    false,
                    true,
                ),
            );
        };
        $accessor = static function (string $name, \Closure $getter, \Closure $setter) use ($proto): void {
            $proto->defineOwnProperty(
                $name,
                PropertyDescriptor::accessor(
                    JsFunction::fromCallable('get ' . $name, $getter, 0),
                    JsFunction::fromCallable('set ' . $name, $setter, 1),
                    false,
                    true,
                ),
            );
        };

        $get('url', static function (JsValue $this_): JsValue {
            $ws = self::requireWs($this_, 'url');
            return new JsString((string) ($ws->getInternalProperty('[[Url]]') ?? ''));
        });
        $get('readyState', static function (JsValue $this_): JsValue {
            $ws = self::requireWs($this_, 'readyState');
            return JsNumber::of((float) ($ws->getInternalProperty('[[ReadyState]]') ?? 0));
        });
        $get('protocol', static function (JsValue $this_): JsValue {
            $ws = self::requireWs($this_, 'protocol');
            return new JsString((string) ($ws->getInternalProperty('[[Protocol]]') ?? ''));
        });
        $get('extensions', static function (JsValue $this_): JsValue {
            $ws = self::requireWs($this_, 'extensions');
            return new JsString((string) ($ws->getInternalProperty('[[Extensions]]') ?? ''));
        });
        $get('bufferedAmount', static function (JsValue $this_): JsValue {
            $ws = self::requireWs($this_, 'bufferedAmount');
            return JsNumber::of((float) ($ws->getInternalProperty('[[BufferedAmount]]') ?? 0));
        });
        $accessor(
            'binaryType',
            static function (JsValue $this_): JsValue {
                $ws = self::requireWs($this_, 'binaryType');
                return new JsString((string) ($ws->getInternalProperty('[[BinaryType]]') ?? 'arraybuffer'));
            },
            static function (JsValue $this_, array $args): JsValue {
                $ws = self::requireWs($this_, 'binaryType');
                $val = TypeConversion::toString($args[0] ?? JsUndefined::instance());
                if ($val === 'blob' || $val === 'arraybuffer') {
                    $ws->setInternalProperty('[[BinaryType]]', $val);
                }
                return JsUndefined::instance();
            },
        );

        // Event handler attributes — onopen, onmessage, onclose, onerror.
        foreach (['onopen', 'onmessage', 'onclose', 'onerror'] as $h) {
            $captured = $h;
            $slot = '[[On' . substr($h, 2) . ']]';
            $accessor(
                $captured,
                static function (JsValue $this_) use ($slot): JsValue {
                    $ws = self::requireWs($this_, 'event handler');
                    $h = $ws->getInternalProperty($slot);
                    return $h instanceof JsValue ? $h : JsNull::instance();
                },
                static function (JsValue $this_, array $args) use ($slot): JsValue {
                    $ws = self::requireWs($this_, 'event handler');
                    $val = $args[0] ?? JsNull::instance();
                    $ws->setInternalProperty(
                        $slot,
                        $val instanceof JsFunction ? $val : JsNull::instance(),
                    );
                    return JsUndefined::instance();
                },
            );
        }
    }

    /**
     * Bridge from the PHP-side transport into the JS event surface.
     * The transport calls this (via the $emit closure passed at
     * construct time) to surface lifecycle events to JS.
     *
     * @param array<string, mixed> $data
     */
    private static function handleTransportEvent(JsObject $ws, string $type, array $data): void
    {
        switch ($type) {
            case 'open':
                $ws->setInternalProperty('[[ReadyState]]', 1); // OPEN
                if (isset($data['protocol'])) {
                    $ws->setInternalProperty('[[Protocol]]', (string) $data['protocol']);
                }
                if (isset($data['extensions'])) {
                    $ws->setInternalProperty('[[Extensions]]', (string) $data['extensions']);
                }
                self::fireEvent($ws, 'open', self::makeEvent($ws, 'open'));
                break;
            case 'message':
                $raw = $data['data'] ?? '';
                $isBinary = !empty($data['isBinary']);
                $jsData = self::messageDataToJs($ws, $raw, $isBinary);
                $event = self::makeEvent($ws, 'message', ['data' => $jsData]);
                self::fireEvent($ws, 'message', $event);
                break;
            case 'close':
                $ws->setInternalProperty('[[ReadyState]]', 3); // CLOSED
                $event = self::makeEvent($ws, 'close', [
                    'code' => JsNumber::of((float) ($data['code'] ?? 1005)),
                    'reason' => new JsString((string) ($data['reason'] ?? '')),
                    'wasClean' => JsBoolean::of((bool) ($data['wasClean'] ?? true)),
                ]);
                self::fireEvent($ws, 'close', $event);
                break;
            case 'error':
                $event = self::makeEvent($ws, 'error', [
                    'message' => new JsString((string) ($data['message'] ?? 'error')),
                ]);
                self::fireEvent($ws, 'error', $event);
                break;
        }
    }

    private static function fireEvent(JsObject $ws, string $name, ?JsObject $event = null): void
    {
        $event ??= self::makeEvent($ws, $name);
        // on<event> handler attribute.
        $handler = $ws->getInternalProperty('[[On' . $name . ']]');
        if ($handler instanceof JsFunction) {
            try {
                Engine::getCurrentRealm()?->getInterpreter()->callFunction($handler, $ws, [$event]);
            } catch (\Throwable $e) {
                error_log('Phasis: uncaught in WebSocket.on' . $name . ': ' . $e->getMessage());
            }
        }
        $listeners = $ws->getInternalProperty('[[EventListeners]]');
        if (is_array($listeners) && isset($listeners[$name])) {
            foreach ($listeners[$name] as $entry) {
                $fn = $entry['callback'] ?? null;
                if ($fn instanceof JsFunction) {
                    try {
                        Engine::getCurrentRealm()?->getInterpreter()->callFunction($fn, $ws, [$event]);
                    } catch (\Throwable $e) {
                        error_log('Phasis: uncaught in WebSocket ' . $name . ' listener: ' . $e->getMessage());
                    }
                }
            }
        }
    }

    /** @param array<string, JsValue> $extra */
    private static function makeEvent(JsObject $target, string $type, array $extra = []): JsObject
    {
        $event = new JsObject();
        $event->setInternalProperty('[[IsEvent]]', true);
        $event->defineOwnProperty('type', PropertyDescriptor::data(new JsString($type), false, true, false));
        $event->defineOwnProperty('target', PropertyDescriptor::data($target, false, true, false));
        $event->defineOwnProperty('currentTarget', PropertyDescriptor::data($target, false, true, false));
        $event->defineOwnProperty('bubbles', PropertyDescriptor::data(JsBoolean::of(false), false, true, false));
        $event->defineOwnProperty('cancelable', PropertyDescriptor::data(JsBoolean::of(false), false, true, false));
        foreach ($extra as $key => $value) {
            $event->defineOwnProperty($key, PropertyDescriptor::data($value, false, true, false));
        }
        return $event;
    }

    private static function messageDataToJs(JsObject $ws, mixed $raw, bool $isBinary): JsValue
    {
        if (!$isBinary) {
            return new JsString(is_string($raw) ? $raw : (string) $raw);
        }
        $bytes = is_string($raw) ? $raw : (string) $raw;
        $binaryType = $ws->getInternalProperty('[[BinaryType]]');
        if ($binaryType === 'blob') {
            return BlobConstructor::createBlob($bytes);
        }
        $buf = new JsArrayBuffer(strlen($bytes), JsArrayBuffer::getDefaultPrototype());
        if ($bytes !== '') {
            $buf->writeBytes(0, $bytes);
        }
        return $buf;
    }

    /**
     * @return array{data: string, isBinary: bool}
     */
    private static function payloadToBytes(JsValue $val): array
    {
        if ($val instanceof JsString) {
            return ['data' => $val->value, 'isBinary' => false];
        }
        if ($val instanceof JsArrayBuffer) {
            return [
                'data' => $val->readBytes(0, $val->getByteLength()),
                'isBinary' => true,
            ];
        }
        if ($val instanceof JsTypedArray) {
            return [
                'data' => $val->getBuffer()->readBytes(
                    $val->getByteOffset(),
                    $val->getLength() * $val->getBytesPerElement(),
                ),
                'isBinary' => true,
            ];
        }
        // Blob: extract its raw bytes synchronously.
        if ($val instanceof JsObject && BlobConstructor::isBlob($val)) {
            return [
                'data' => BlobConstructor::getBytes($val),
                'isBinary' => true,
            ];
        }
        // Fallback: stringify.
        return ['data' => TypeConversion::toString($val), 'isBinary' => false];
    }

    private static function requireWs(JsValue $val, string $op): JsObject
    {
        if (!$val instanceof JsObject || $val->getInternalProperty('[[IsWebSocket]]') !== true) {
            throw new TypeError("Failed to call '{$op}' on a non-WebSocket");
        }
        return $val;
    }
}
