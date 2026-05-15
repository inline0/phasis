<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Engine;
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
            static function (JsValue $this_, array $args) use ($proto): JsValue {
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

                $this_->setPrototype($proto);
                self::initInstance($this_, $url);

                // Resolve the transport at construct time so the error
                // is observable on `new WebSocket(...)`, not later.
                $realm = Engine::getCurrentRealm();
                $transport = $realm?->getWebSocketTransport();
                if ($transport === null) {
                    throw new TypeError(
                        "WebSocket: no transport installed. Embedder must call "
                        . "\$engine->setWebSocketTransport() before constructing a WebSocket. "
                        . "The transport bridges to the underlying network library "
                        . "(pawl / ratchet / react-socket / custom)."
                    );
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

                if (!is_array($handle)
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

        self::definePrototypeMethods($proto);
        self::defineEventTargetMethods($proto);
        self::defineAccessors($proto);

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

    private static function definePrototypeMethods(JsObject $proto): void
    {
        self::method($proto, 'send', 1, static function (JsValue $this_, array $args): JsValue {
            $ws = self::requireWs($this_, 'send');
            $state = (int) ($ws->getInternalProperty('[[ReadyState]]') ?? 0);
            if ($state === 0) {
                throw new TypeError(
                    "Failed to execute 'send' on 'WebSocket': "
                    . "Still in CONNECTING state.",
                );
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
            // Approximate bufferedAmount — transports that surface
            // real backpressure can call back into the WebSocket via
            // a future internal API; for now, treat send as
            // immediately flushed.
            return JsUndefined::instance();
        });

        self::method($proto, 'close', 2, static function (JsValue $this_, array $args): JsValue {
            $ws = self::requireWs($this_, 'close');
            $state = (int) ($ws->getInternalProperty('[[ReadyState]]') ?? 0);
            if ($state >= 2) {
                return JsUndefined::instance();
            }
            $code = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 1000;
            $reason = isset($args[1]) ? TypeConversion::toString($args[1]) : '';
            // Per spec, code must be 1000 or in [3000, 4999]. We
            // accept the spec range and reject anything else.
            if ($code !== 1000 && ($code < 3000 || $code > 4999)) {
                throw new TypeError(
                    "Failed to execute 'close' on 'WebSocket': "
                    . "The code must be either 1000, or between 3000 and 4999."
                );
            }
            // Reason must be <= 123 bytes UTF-8.
            if (strlen($reason) > 123) {
                throw new TypeError(
                    "Failed to execute 'close' on 'WebSocket': "
                    . "The message must not be greater than 123 bytes."
                );
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
            // No Blob constructor wiring here — fall back to ArrayBuffer.
            // Embedders that need Blob can change binaryType and the
            // transport can do its own conversion.
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
