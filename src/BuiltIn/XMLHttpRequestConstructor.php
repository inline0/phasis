<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\BuiltIn\Fetch\CurlTransport;
use Phasis\BuiltIn\Fetch\TransportException;
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
 * XMLHttpRequest — the legacy XHR interface, layered over the same
 * pluggable fetch transport that `fetch()` uses. Behavior model:
 *
 *   1. `new XMLHttpRequest()` — creates an UNSENT (0) instance.
 *   2. `xhr.open(method, url, async?, user?, password?)` — moves to
 *       OPENED (1); resets headers + response.
 *   3. `xhr.setRequestHeader(name, value)` — appends a header.
 *   4. `xhr.send(body?)` — kicks off the request via the realm's
 *       installed fetch transport (or the default CurlTransport).
 *       Schedules a microtask that runs the transport, then steps
 *       through HEADERS_RECEIVED (2), LOADING (3), DONE (4),
 *       firing readystatechange + the lifecycle events at each
 *       transition.
 *   5. `xhr.abort()` — flips to DONE with status=0 and fires
 *       'abort' + 'loadend' if the transition is observable.
 *
 * Events fired on the xhr itself (which acts as an EventTarget):
 *   - 'loadstart' before the transport is invoked.
 *   - 'progress' once after the response body is available (we
 *      don't stream byte-by-byte; the embedder's transport can
 *      decide if it wants to).
 *   - 'load' on successful DONE.
 *   - 'error' on transport failure / network error.
 *   - 'abort' when aborted.
 *   - 'timeout' when the configured timeout elapses (xhr.timeout).
 *   - 'loadend' as the final terminal event in all cases.
 *
 * `responseType` controls how `response` is materialized:
 *   - '' (default) or 'text' — UTF-8 decoded string.
 *   - 'arraybuffer' — JsArrayBuffer of the raw bytes.
 *   - 'json' — JSON.parse of the text (null on parse error).
 *   - 'blob' — a Blob wrapping the bytes (best-effort; Blob ctor
 *      is in the Fetch Pack and available lazily).
 *
 * Not implemented: 'document' responseType (no DOM), XML parsing
 * (responseXML is always null), upload progress events (the
 * single-shot transport doesn't surface byte-level upload state).
 * `withCredentials` is accepted and stored but has no effect —
 * cookies are controlled by the realm's setCookieJar().
 */
final class XMLHttpRequestConstructor
{
    public static function install(Environment $env): void
    {
        $proto = new JsObject();

        $ctor = JsFunction::fromCallable(
            'XMLHttpRequest',
            static function (JsValue $this_, array $args) use ($proto): JsValue {
                unset($args);
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor XMLHttpRequest requires 'new'");
                }
                $this_->setPrototype($proto);
                self::initInstance($this_);
                return $this_;
            },
            0,
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

        // readyState constants on both the constructor and prototype.
        foreach (
            [
                'UNSENT' => 0,
                'OPENED' => 1,
                'HEADERS_RECEIVED' => 2,
                'LOADING' => 3,
                'DONE' => 4,
            ] as $name => $val
        ) {
            $desc = PropertyDescriptor::data(JsNumber::of((float) $val), false, true, false);
            $ctor->defineOwnProperty($name, $desc);
            $proto->defineOwnProperty($name, $desc);
        }

        self::definePrototypeMethods($proto, $env);
        self::defineEventTargetMethods($proto);
        self::defineAccessors($proto, $env);

        $env->defineVar('XMLHttpRequest', $ctor);

        // Expose XMLHttpRequestUpload as a global ctor. Instances
        // created via `xhr.upload` carry the [[IsXHRUpload]] brand;
        // direct construction throws to match the spec (only the XHR
        // pipeline can mint these). The class is used by fixtures for
        // `evt.target instanceof XMLHttpRequestUpload` discrimination.
        $uploadCtorProto = new JsObject();
        $uploadCtor = JsFunction::fromCallable(
            'XMLHttpRequestUpload',
            static function (JsValue $this_): JsValue {
                throw new TypeError(
                    'Illegal constructor: XMLHttpRequestUpload is only '
                    . 'obtainable via `XMLHttpRequest.upload`.',
                );
            },
            0,
        );
        $uploadCtor->setConstructable();
        $uploadCtor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($uploadCtorProto, false, false, false),
        );
        $uploadCtorProto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($uploadCtor, true, false, true),
        );
        $env->defineVar('XMLHttpRequestUpload', $uploadCtor);

        // Expose ProgressEvent as a global so fixtures using
        // `evt instanceof ProgressEvent` succeed. We don't ship a
        // full Event/ProgressEvent class hierarchy; this is a minimal
        // brand-checked stand-in that the upload-side events claim
        // membership in via [[IsProgressEvent]].
        $peProto = new JsObject();
        $peCtor = JsFunction::fromCallable(
            'ProgressEvent',
            static function (JsValue $this_, array $args) use ($peProto): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor ProgressEvent requires 'new'");
                }
                $type = TypeConversion::toString($args[0] ?? JsUndefined::instance());
                $init = $args[1] ?? null;
                $lengthComputable = false;
                $loaded = 0;
                $total = 0;
                if ($init instanceof JsObject) {
                    $lengthComputable = TypeConversion::toBoolean($init->get('lengthComputable'));
                    $loaded = (int) TypeConversion::toNumber($init->get('loaded'));
                    $total = (int) TypeConversion::toNumber($init->get('total'));
                }
                $this_->setPrototype($peProto);
                $this_->setInternalProperty('[[IsEvent]]', true);
                $this_->setInternalProperty('[[IsProgressEvent]]', true);
                $this_->defineOwnProperty('type', PropertyDescriptor::data(new JsString($type), false, true, false));
                $this_->defineOwnProperty(
                    'lengthComputable',
                    PropertyDescriptor::data(JsBoolean::of($lengthComputable), false, true, false),
                );
                $this_->defineOwnProperty(
                    'loaded',
                    PropertyDescriptor::data(JsNumber::of((float) $loaded), false, true, false),
                );
                $this_->defineOwnProperty(
                    'total',
                    PropertyDescriptor::data(JsNumber::of((float) $total), false, true, false),
                );
                return $this_;
            },
            1,
        );
        $peCtor->setConstructable();
        $peCtor->defineOwnProperty('prototype', PropertyDescriptor::data($peProto, false, false, false));
        $peProto->defineOwnProperty('constructor', PropertyDescriptor::data($peCtor, true, false, true));
        $env->defineVar('ProgressEvent', $peCtor);
    }

    /**
     * Track the ProgressEvent prototype so makeProgressEvent can set
     * it on instances. Lazily resolved against the realm — we don't
     * have a stable accessor from a static helper, but the prototype
     * is keyed by name on the global object.
     */
    private static function progressEventProto(): ?JsObject
    {
        $realm = Engine::getCurrentRealm();
        if ($realm === null) {
            return null;
        }
        try {
            $ctor = $realm->getGlobalEnv()->get('ProgressEvent');
        } catch (\Throwable) {
            return null;
        }
        if (!$ctor instanceof JsObject) {
            return null;
        }
        $proto = $ctor->get('prototype');
        return $proto instanceof JsObject ? $proto : null;
    }

    private static function initInstance(JsObject $xhr): void
    {
        $xhr->setInternalProperty('[[IsXHR]]', true);
        $xhr->setInternalProperty('[[IsEventTarget]]', true);
        $xhr->setInternalProperty('[[EventListeners]]', []);
        $xhr->setInternalProperty('[[ReadyState]]', 0);
        $xhr->setInternalProperty('[[Method]]', '');
        $xhr->setInternalProperty('[[Url]]', '');
        $xhr->setInternalProperty('[[Async]]', true);
        $xhr->setInternalProperty('[[RequestHeaders]]', []);
        $xhr->setInternalProperty('[[ResponseType]]', '');
        $xhr->setInternalProperty('[[ResponseHeaders]]', []);
        $xhr->setInternalProperty('[[ResponseBody]]', '');
        $xhr->setInternalProperty('[[ResponseUrl]]', '');
        $xhr->setInternalProperty('[[Status]]', 0);
        $xhr->setInternalProperty('[[StatusText]]', '');
        $xhr->setInternalProperty('[[Timeout]]', 0);
        $xhr->setInternalProperty('[[WithCredentials]]', false);
        $xhr->setInternalProperty('[[Aborted]]', false);
        $xhr->setInternalProperty('[[Upload]]', self::makeUploadObject());
    }

    /**
     * Build the XMLHttpRequestUpload sibling object exposed as
     * `xhr.upload`. It's a minimal EventTarget that the request
     * pipeline fires loadstart / progress / load / loadend / error /
     * abort against during the upload phase.
     */
    private static function makeUploadObject(): JsObject
    {
        $upload = new JsObject();
        // Inherit from XMLHttpRequestUpload.prototype so
        // `evt.target instanceof XMLHttpRequestUpload` works in fixtures.
        $realm = Engine::getCurrentRealm();
        if ($realm !== null) {
            try {
                $ctor = $realm->getGlobalEnv()->get('XMLHttpRequestUpload');
                if ($ctor instanceof JsObject) {
                    $proto = $ctor->get('prototype');
                    if ($proto instanceof JsObject) {
                        $upload->setPrototype($proto);
                    }
                }
            } catch (\Throwable) {
                // Pre-install — leave on default Object proto.
            }
        }
        $upload->setInternalProperty('[[IsXHRUpload]]', true);
        $upload->setInternalProperty('[[IsEventTarget]]', true);
        $upload->setInternalProperty('[[EventListeners]]', []);

        $addEventListener = JsFunction::fromCallable(
            'addEventListener',
            static function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $type = TypeConversion::toString($args[0] ?? JsUndefined::instance());
                $cb = $args[1] ?? JsUndefined::instance();
                if (!$cb instanceof JsFunction) {
                    return JsUndefined::instance();
                }
                $listeners = $this_->getInternalProperty('[[EventListeners]]');
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
                $this_->setInternalProperty('[[EventListeners]]', $listeners);
                return JsUndefined::instance();
            },
            2,
        );
        $removeEventListener = JsFunction::fromCallable(
            'removeEventListener',
            static function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    return JsUndefined::instance();
                }
                $type = TypeConversion::toString($args[0] ?? JsUndefined::instance());
                $cb = $args[1] ?? JsUndefined::instance();
                $listeners = $this_->getInternalProperty('[[EventListeners]]');
                if (!is_array($listeners) || !isset($listeners[$type])) {
                    return JsUndefined::instance();
                }
                $listeners[$type] = array_values(array_filter(
                    $listeners[$type],
                    static fn ($entry) => ($entry['callback'] ?? null) !== $cb,
                ));
                $this_->setInternalProperty('[[EventListeners]]', $listeners);
                return JsUndefined::instance();
            },
            2,
        );
        $upload->defineOwnProperty(
            'addEventListener',
            PropertyDescriptor::data($addEventListener, true, false, true),
        );
        $upload->defineOwnProperty(
            'removeEventListener',
            PropertyDescriptor::data($removeEventListener, true, false, true),
        );

        // on<event> accessors for the upload events the spec requires:
        // loadstart, progress, load, loadend, error, abort, timeout.
        foreach (['loadstart', 'progress', 'load', 'loadend', 'error', 'abort', 'timeout'] as $event) {
            $key = '[[On' . $event . ']]';
            $upload->defineOwnProperty(
                'on' . $event,
                PropertyDescriptor::accessor(
                    JsFunction::fromCallable(
                        'get on' . $event,
                        static function (JsValue $this_) use ($key): JsValue {
                            if (!$this_ instanceof JsObject) {
                                return JsNull::instance();
                            }
                            $h = $this_->getInternalProperty($key);
                            return $h instanceof JsFunction ? $h : JsNull::instance();
                        },
                        0,
                    ),
                    JsFunction::fromCallable(
                        'set on' . $event,
                        static function (JsValue $this_, array $args) use ($key): JsValue {
                            if (!$this_ instanceof JsObject) {
                                return JsUndefined::instance();
                            }
                            $val = $args[0] ?? JsUndefined::instance();
                            $this_->setInternalProperty($key, $val instanceof JsFunction ? $val : null);
                            return JsUndefined::instance();
                        },
                        1,
                    ),
                    false,
                    true,
                ),
            );
        }

        return $upload;
    }

    /**
     * Fire an event on the XMLHttpRequestUpload sibling object —
     * dispatches to the on<event> handler attribute (if set) and to
     * any addEventListener listeners. Always emits a ProgressEvent-
     * shaped object with `lengthComputable`, `loaded`, and `total`
     * fields populated from the current upload state.
     */
    private static function fireUploadEvent(JsObject $xhr, string $name, int $loaded, int $total): void
    {
        $upload = $xhr->getInternalProperty('[[Upload]]');
        if (!$upload instanceof JsObject) {
            return;
        }
        $lengthComputable = $total > 0;
        $event = self::makeProgressEvent($name, $upload, $lengthComputable, $loaded, $total);
        $handler = $upload->getInternalProperty('[[On' . $name . ']]');
        if ($handler instanceof JsFunction) {
            try {
                Engine::getCurrentRealm()?->getInterpreter()->callFunction(
                    $handler,
                    $upload,
                    [$event],
                );
            } catch (\Throwable $e) {
                error_log('Phasis: uncaught in XHR.upload.' . $name . ': ' . $e->getMessage());
            }
        }
        $listeners = $upload->getInternalProperty('[[EventListeners]]');
        if (is_array($listeners) && isset($listeners[$name])) {
            foreach ($listeners[$name] as $entry) {
                $fn = $entry['callback'] ?? null;
                if ($fn instanceof JsFunction) {
                    try {
                        Engine::getCurrentRealm()?->getInterpreter()->callFunction(
                            $fn,
                            $upload,
                            [$event],
                        );
                    } catch (\Throwable $e) {
                        error_log('Phasis: uncaught in XHR.upload.' . $name . ' listener: ' . $e->getMessage());
                    }
                }
            }
        }
    }

    private static function makeProgressEvent(
        string $type,
        JsObject $target,
        bool $lengthComputable,
        int $loaded,
        int $total,
    ): JsObject {
        $event = new JsObject();
        $proto = self::progressEventProto();
        if ($proto !== null) {
            $event->setPrototype($proto);
        }
        $event->setInternalProperty('[[IsEvent]]', true);
        $event->setInternalProperty('[[IsProgressEvent]]', true);
        $event->defineOwnProperty('type', PropertyDescriptor::data(new JsString($type), false, true, false));
        $event->defineOwnProperty('target', PropertyDescriptor::data($target, false, true, false));
        $event->defineOwnProperty('currentTarget', PropertyDescriptor::data($target, false, true, false));
        $event->defineOwnProperty('bubbles', PropertyDescriptor::data(JsBoolean::of(false), false, true, false));
        $event->defineOwnProperty('cancelable', PropertyDescriptor::data(JsBoolean::of(false), false, true, false));
        $event->defineOwnProperty(
            'lengthComputable',
            PropertyDescriptor::data(JsBoolean::of($lengthComputable), false, true, false),
        );
        $event->defineOwnProperty(
            'loaded',
            PropertyDescriptor::data(JsNumber::of((float) $loaded), false, true, false),
        );
        $event->defineOwnProperty(
            'total',
            PropertyDescriptor::data(JsNumber::of((float) $total), false, true, false),
        );
        return $event;
    }

    private static function definePrototypeMethods(JsObject $proto, Environment $env): void
    {
        self::method($proto, 'open', 5, self::openImpl());
        self::method($proto, 'setRequestHeader', 2, self::setRequestHeaderImpl());
        self::method($proto, 'send', 1, self::sendImpl($env));
        self::method($proto, 'abort', 0, self::abortImpl());
        self::method($proto, 'getResponseHeader', 1, self::getResponseHeaderImpl());
        self::method($proto, 'getAllResponseHeaders', 0, self::getAllResponseHeadersImpl());
        self::method($proto, 'overrideMimeType', 1, self::overrideMimeTypeImpl());
    }

    /**
     * Local mini-EventTarget so XHR works without depending on a
     * shared EventTarget mixin. Stores listeners in [[EventListeners]]
     * by type, called by fireEvent during the request lifecycle.
     */
    private static function defineEventTargetMethods(JsObject $proto): void
    {
        self::method($proto, 'addEventListener', 2, static function (JsValue $this_, array $args): JsValue {
            $xhr = self::requireXhr($this_, 'addEventListener');
            $type = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            $cb = $args[1] ?? JsUndefined::instance();
            if (!$cb instanceof JsFunction) {
                return JsUndefined::instance();
            }
            $listeners = $xhr->getInternalProperty('[[EventListeners]]');
            if (!is_array($listeners)) {
                $listeners = [];
            }
            if (!isset($listeners[$type])) {
                $listeners[$type] = [];
            }
            // De-dupe — adding the same callable twice is a no-op per spec.
            foreach ($listeners[$type] as $existing) {
                if (($existing['callback'] ?? null) === $cb) {
                    return JsUndefined::instance();
                }
            }
            $listeners[$type][] = ['callback' => $cb];
            $xhr->setInternalProperty('[[EventListeners]]', $listeners);
            return JsUndefined::instance();
        });

        self::method($proto, 'removeEventListener', 2, static function (JsValue $this_, array $args): JsValue {
            $xhr = self::requireXhr($this_, 'removeEventListener');
            $type = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            $cb = $args[1] ?? JsUndefined::instance();
            $listeners = $xhr->getInternalProperty('[[EventListeners]]');
            if (!is_array($listeners) || !isset($listeners[$type])) {
                return JsUndefined::instance();
            }
            $listeners[$type] = array_values(array_filter(
                $listeners[$type],
                static fn ($entry) => ($entry['callback'] ?? null) !== $cb,
            ));
            $xhr->setInternalProperty('[[EventListeners]]', $listeners);
            return JsUndefined::instance();
        });

        self::method($proto, 'dispatchEvent', 1, static function (JsValue $this_, array $args): JsValue {
            $xhr = self::requireXhr($this_, 'dispatchEvent');
            $event = $args[0] ?? JsUndefined::instance();
            $type = $event instanceof JsObject ? TypeConversion::toString($event->get('type')) : '';
            if ($type === '') {
                throw new TypeError("Failed to execute 'dispatchEvent' on 'XMLHttpRequest': event has no type");
            }
            self::fireEvent($xhr, $type);
            return JsBoolean::of(true);
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

    private static function defineAccessors(JsObject $proto, Environment $env): void
    {
        $accessor = static function (string $name, \Closure $getter, ?\Closure $setter = null) use ($proto): void {
            $g = JsFunction::fromCallable('get ' . $name, $getter, 0);
            $s = $setter !== null
                ? JsFunction::fromCallable('set ' . $name, $setter, 1)
                : null;
            $proto->defineOwnProperty(
                $name,
                PropertyDescriptor::accessor($g, $s, false, true),
            );
        };

        $accessor('readyState', static function (JsValue $this_): JsValue {
            $xhr = self::requireXhr($this_, 'readyState');
            return JsNumber::of((float) ($xhr->getInternalProperty('[[ReadyState]]') ?? 0));
        });
        $accessor('status', static function (JsValue $this_): JsValue {
            $xhr = self::requireXhr($this_, 'status');
            return JsNumber::of((float) ($xhr->getInternalProperty('[[Status]]') ?? 0));
        });
        $accessor('statusText', static function (JsValue $this_): JsValue {
            $xhr = self::requireXhr($this_, 'statusText');
            return new JsString((string) ($xhr->getInternalProperty('[[StatusText]]') ?? ''));
        });
        $accessor('responseURL', static function (JsValue $this_): JsValue {
            $xhr = self::requireXhr($this_, 'responseURL');
            return new JsString((string) ($xhr->getInternalProperty('[[ResponseUrl]]') ?? ''));
        });
        $accessor(
            'responseType',
            static function (JsValue $this_): JsValue {
                $xhr = self::requireXhr($this_, 'responseType');
                return new JsString((string) ($xhr->getInternalProperty('[[ResponseType]]') ?? ''));
            },
            static function (JsValue $this_, array $args) use ($env): JsValue {
                $xhr = self::requireXhr($this_, 'responseType');
                $val = TypeConversion::toString($args[0] ?? JsUndefined::instance());
                // WebIDL: XMLHttpRequestResponseType is an enum.
                // Values outside the enum are silently ignored —
                // the spec setter steps don't even run, so no state
                // check, no throw.
                $validEnum = ['', 'arraybuffer', 'blob', 'document', 'json', 'text'];
                if (!in_array($val, $validEnum, true)) {
                    return JsUndefined::instance();
                }
                // "document" is only meaningful in a Window context.
                // Phasis runs JS in worker-equivalent realms (no DOM),
                // so a "document" assignment short-circuits without
                // throwing or setting anything.
                if ($val === 'document') {
                    return JsUndefined::instance();
                }
                // Per spec §4.5.4: setting responseType after the
                // request has progressed past HEADERS_RECEIVED throws
                // InvalidStateError.
                $rs = (int) ($xhr->getInternalProperty('[[ReadyState]]') ?? 0);
                if ($rs >= 3) {
                    throw new \Phasis\Exceptions\JsThrowable(
                        DomExceptionConstructor::create(
                            $env,
                            "Failed to set the 'responseType' property on 'XMLHttpRequest': "
                            . 'The response type cannot be set if the object\'s state is LOADING or DONE.',
                            'InvalidStateError',
                        ),
                    );
                }
                $xhr->setInternalProperty('[[ResponseType]]', $val);
                return JsUndefined::instance();
            },
        );
        $accessor('responseText', static function (JsValue $this_): JsValue {
            $xhr = self::requireXhr($this_, 'responseText');
            $rt = $xhr->getInternalProperty('[[ResponseType]]');
            if ($rt !== '' && $rt !== 'text') {
                // Per spec, accessing responseText with a non-text
                // responseType throws InvalidStateError. We surface as
                // a plain TypeError since Phasis doesn't ship the full
                // DOMException family beyond what's already there.
                throw new TypeError(
                    "Failed to read 'responseText': value is only available "
                    . "if 'responseType' is '' or 'text'",
                );
            }
            return new JsString((string) ($xhr->getInternalProperty('[[ResponseBody]]') ?? ''));
        });
        $accessor('responseXML', static function (JsValue $this_): JsValue {
            // No DOM in Phasis — always null.
            self::requireXhr($this_, 'responseXML');
            return JsNull::instance();
        });
        $accessor('response', static function (JsValue $this_): JsValue {
            $xhr = self::requireXhr($this_, 'response');
            $body = (string) ($xhr->getInternalProperty('[[ResponseBody]]') ?? '');
            $rt = $xhr->getInternalProperty('[[ResponseType]]');
            $readyState = (int) ($xhr->getInternalProperty('[[ReadyState]]') ?? 0);
            if ($readyState < 2 && $rt !== '' && $rt !== 'text') {
                return JsNull::instance();
            }
            return match ($rt) {
                '', 'text' => new JsString($body),
                'arraybuffer' => self::bufferFromBytes($body),
                'json' => self::parseJsonOrNull($body),
                'blob' => self::makeBlob($body),
                default => new JsString($body),
            };
        });
        $accessor(
            'timeout',
            static function (JsValue $this_): JsValue {
                $xhr = self::requireXhr($this_, 'timeout');
                return JsNumber::of((float) ($xhr->getInternalProperty('[[Timeout]]') ?? 0));
            },
            static function (JsValue $this_, array $args): JsValue {
                $xhr = self::requireXhr($this_, 'timeout');
                $val = (int) TypeConversion::toNumber($args[0] ?? JsUndefined::instance());
                if ($val < 0) {
                    $val = 0;
                }
                $xhr->setInternalProperty('[[Timeout]]', $val);
                return JsUndefined::instance();
            },
        );
        $accessor(
            'withCredentials',
            static function (JsValue $this_): JsValue {
                $xhr = self::requireXhr($this_, 'withCredentials');
                return JsBoolean::of((bool) ($xhr->getInternalProperty('[[WithCredentials]]') ?? false));
            },
            static function (JsValue $this_, array $args) use ($env): JsValue {
                $xhr = self::requireXhr($this_, 'withCredentials');
                // Spec §4.5.4: throw InvalidStateError if state is
                // not UNSENT or OPENED, or if the send flag is set.
                $rs = (int) ($xhr->getInternalProperty('[[ReadyState]]') ?? 0);
                $sendFlag = (bool) ($xhr->getInternalProperty('[[SendFlag]]') ?? false);
                if (($rs !== 0 && $rs !== 1) || $sendFlag) {
                    throw new \Phasis\Exceptions\JsThrowable(
                        DomExceptionConstructor::create(
                            $env,
                            "Failed to set the 'withCredentials' property on 'XMLHttpRequest': "
                            . 'The value may only be set if the object\'s state is UNSENT or OPENED.',
                            'InvalidStateError',
                        ),
                    );
                }
                $xhr->setInternalProperty(
                    '[[WithCredentials]]',
                    TypeConversion::toBoolean($args[0] ?? JsUndefined::instance()),
                );
                return JsUndefined::instance();
            },
        );
        // xhr.upload — returns the XMLHttpRequestUpload sibling.
        $accessor('upload', static function (JsValue $this_): JsValue {
            $xhr = self::requireXhr($this_, 'upload');
            $upload = $xhr->getInternalProperty('[[Upload]]');
            return $upload instanceof JsObject ? $upload : JsNull::instance();
        });

        // Event handler IDL attributes — onreadystatechange, onload, etc.
        foreach (
            [
                'onreadystatechange',
                'onload',
                'onerror',
                'onabort',
                'ontimeout',
                'onloadstart',
                'onloadend',
                'onprogress',
            ] as $handlerName
        ) {
            $slot = '[[On' . substr($handlerName, 2) . ']]';
            $captured = $handlerName;
            $capturedSlot = $slot;
            $accessor(
                $handlerName,
                static function (JsValue $this_) use ($capturedSlot): JsValue {
                    $xhr = self::requireXhr($this_, 'event handler');
                    $h = $xhr->getInternalProperty($capturedSlot);
                    return $h instanceof JsValue ? $h : JsNull::instance();
                },
                static function (JsValue $this_, array $args) use ($capturedSlot): JsValue {
                    $xhr = self::requireXhr($this_, 'event handler');
                    $val = $args[0] ?? JsNull::instance();
                    $xhr->setInternalProperty(
                        $capturedSlot,
                        $val instanceof JsFunction ? $val : JsNull::instance(),
                    );
                    return JsUndefined::instance();
                },
            );
        }
    }

    // -----------------------------------------------------------------
    // open / setRequestHeader / send / abort
    // -----------------------------------------------------------------

    private static function openImpl(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            $xhr = self::requireXhr($this_, 'open');
            $method = strtoupper(TypeConversion::toString($args[0] ?? JsUndefined::instance()));
            $rawUrl = TypeConversion::toString($args[1] ?? JsUndefined::instance());
            $async = isset($args[2]) ? TypeConversion::toBoolean($args[2]) : true;
            if ($method === '' || $rawUrl === '') {
                throw new TypeError("Failed to execute 'open' on 'XMLHttpRequest': method and url required");
            }
            // Resolve relative URLs against the realm's base URL:
            //   1. globalThis.__phasisRequestBaseUrl if installed
            //      (the WPT runner sets this so fixtures hitting
            //      `resources/foo.py` land on the fetch-server),
            //   2. globalThis.location.href otherwise,
            //   3. no base — pass through (the fetch transport will
            //      fail loudly, which matches browser behavior for
            //      an unresolved relative URL).
            $url = self::resolveRequestUrl($rawUrl);
            // Per XHR spec, open() resets the request/response state
            // but PRESERVES event listeners and the responseType.
            $xhr->setInternalProperty('[[Method]]', $method);
            $xhr->setInternalProperty('[[Url]]', $url);
            $xhr->setInternalProperty('[[Async]]', $async);
            $xhr->setInternalProperty('[[RequestHeaders]]', []);
            $xhr->setInternalProperty('[[ResponseHeaders]]', []);
            $xhr->setInternalProperty('[[ResponseBody]]', '');
            $xhr->setInternalProperty('[[ResponseUrl]]', '');
            $xhr->setInternalProperty('[[Status]]', 0);
            $xhr->setInternalProperty('[[StatusText]]', '');
            $xhr->setInternalProperty('[[Aborted]]', false);
            self::setReadyState($xhr, 1);
            return JsUndefined::instance();
        };
    }

    /**
     * Derive an `Origin: scheme://host[:port]` header value from the
     * realm's base URL. Returns null when nothing resembling a base
     * URL is configured.
     */
    private static function deriveOrigin(?Engine $realm): ?string
    {
        if ($realm === null) {
            return null;
        }
        $base = null;
        try {
            $hook = $realm->getGlobalEnv()->get('__phasisRequestBaseUrl');
            if ($hook instanceof JsString && $hook->value !== '') {
                $base = $hook->value;
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
                        $base = $href->value;
                    }
                }
            } catch (\Throwable) {
                // ignore
            }
        }
        if ($base === null) {
            return null;
        }
        $rec = \Phasis\BuiltIn\Url\UrlParser::parse($base);
        if ($rec === null || $rec->scheme === '') {
            return null;
        }
        $origin = $rec->scheme . '://' . \Phasis\BuiltIn\Url\UrlSerializer::serializeHost($rec->host ?? '');
        if ($rec->port !== null) {
            $origin .= ':' . $rec->port;
        }
        return $origin;
    }

    /**
     * Resolve a possibly-relative URL against the realm's base URL.
     * Mirrors what `new URL(input, base)` does on the JS side, but
     * picks the base from realm-level hooks so embedders don't have
     * to set `location` directly.
     */
    private static function resolveRequestUrl(string $input): string
    {
        // Already absolute? Short-circuit.
        $rec = \Phasis\BuiltIn\Url\UrlParser::parse($input);
        if ($rec !== null && $rec->scheme !== '' && !($input !== '' && $input[0] === '/')) {
            return \Phasis\BuiltIn\Url\UrlSerializer::serializeUrl($rec);
        }
        $base = null;
        $realm = Engine::getCurrentRealm();
        if ($realm !== null) {
            $globalEnv = $realm->getGlobalEnv();
            try {
                $hook = $globalEnv->get('__phasisRequestBaseUrl');
                if ($hook instanceof JsString && $hook->value !== '') {
                    $base = $hook->value;
                }
            } catch (\Throwable) {
                // binding missing — fall through to location lookup
            }
            if ($base === null) {
                try {
                    $loc = $globalEnv->get('location');
                    if ($loc instanceof JsObject) {
                        $href = $loc->get('href');
                        if ($href instanceof JsString && $href->value !== '') {
                            $base = $href->value;
                        }
                    }
                } catch (\Throwable) {
                    // no location — leave $base null
                }
            }
        }
        if ($base === null) {
            return $input;
        }
        $baseRec = \Phasis\BuiltIn\Url\UrlParser::parse($base);
        if ($baseRec === null) {
            return $input;
        }
        $resolved = \Phasis\BuiltIn\Url\UrlParser::parse($input, $baseRec);
        if ($resolved === null) {
            return $input;
        }
        return \Phasis\BuiltIn\Url\UrlSerializer::serializeUrl($resolved);
    }

    private static function setRequestHeaderImpl(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            $xhr = self::requireXhr($this_, 'setRequestHeader');
            if ((int) ($xhr->getInternalProperty('[[ReadyState]]') ?? 0) < 1) {
                throw new TypeError(
                    "Failed to execute 'setRequestHeader' on 'XMLHttpRequest': state is not OPENED",
                );
            }
            $name = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            $value = TypeConversion::toString($args[1] ?? JsUndefined::instance());
            $headers = $xhr->getInternalProperty('[[RequestHeaders]]');
            if (!is_array($headers)) {
                $headers = [];
            }
            $headers[] = [$name, $value];
            $xhr->setInternalProperty('[[RequestHeaders]]', $headers);
            return JsUndefined::instance();
        };
    }

    private static function sendImpl(Environment $env): \Closure
    {
        return static function (JsValue $this_, array $args) use ($env): JsValue {
            $xhr = self::requireXhr($this_, 'send');
            $rs = (int) ($xhr->getInternalProperty('[[ReadyState]]') ?? 0);
            $sendFlag = (bool) ($xhr->getInternalProperty('[[SendFlag]]') ?? false);
            // Spec §4.5.6: if state is not OPENED, or send flag is
            // already set (i.e. a previous send() is in flight), throw
            // InvalidStateError DOMException.
            if ($rs !== 1 || $sendFlag) {
                throw new \Phasis\Exceptions\JsThrowable(
                    DomExceptionConstructor::create(
                        $env,
                        "Failed to execute 'send' on 'XMLHttpRequest': "
                        . "The object's state must be OPENED.",
                        'InvalidStateError',
                    ),
                );
            }
            $bodyArg = $args[0] ?? JsUndefined::instance();
            $extracted = self::bodyArgToBytes($bodyArg);
            $body = $extracted['bytes'];
            // If the body shape implies a Content-Type and the JS
            // code didn't already set one via setRequestHeader, set
            // it now. Spec §4.5.6 step 4: "If author request header
            // does not contain a header named `Content-Type`, then
            // append one with the [extracted MIME type] value."
            if ($extracted['contentType'] !== null) {
                $headers = $xhr->getInternalProperty('[[RequestHeaders]]');
                if (!is_array($headers)) {
                    $headers = [];
                }
                $hasContentType = false;
                foreach ($headers as $h) {
                    if (strcasecmp((string) ($h[0] ?? ''), 'content-type') === 0) {
                        $hasContentType = true;
                        break;
                    }
                }
                if (!$hasContentType) {
                    $headers[] = ['Content-Type', $extracted['contentType']];
                    $xhr->setInternalProperty('[[RequestHeaders]]', $headers);
                }
            }
            $xhr->setInternalProperty('[[Aborted]]', false);
            $xhr->setInternalProperty('[[SendFlag]]', true);
            $xhr->setInternalProperty('[[UploadBodyLength]]', strlen($body));

            // Dispatch loadstart before queuing the transport.
            self::fireEvent($xhr, 'loadstart');
            // Per spec, the upload-listener flag is set if any listener
            // was attached to `xhr.upload` before send(). Fire the
            // upload's loadstart only when there's a body to upload —
            // otherwise the upload algorithm short-circuits.
            if ($body !== '') {
                self::fireUploadEvent($xhr, 'loadstart', 0, strlen($body));
            }

            $realm = Engine::getCurrentRealm();
            if ($realm === null) {
                // No realm? Treat as immediate error.
                self::finishError($xhr, 'no realm');
                return JsUndefined::instance();
            }

            // Sync XHR (`xhr.open(method, url, false)`): run the
            // transport inline so `send()` blocks until the request
            // completes and readyState advances to DONE. Spec
            // §4.5.6 step 11: synchronous XHR runs the fetch with
            // the JS event loop frozen, and any network error
            // surfaces as a NetworkError DOMException out of send().
            $async = (bool) ($xhr->getInternalProperty('[[Async]]') ?? true);
            if (!$async) {
                self::runTransport($xhr, $body, $realm);
                $errKind = $xhr->getInternalProperty('[[LastErrorKind]]');
                if (is_string($errKind) && $errKind !== '') {
                    throw new \Phasis\Exceptions\JsThrowable(
                        DomExceptionConstructor::create(
                            $env,
                            'Failed to execute \'send\' on \'XMLHttpRequest\': '
                            . ($xhr->getInternalProperty('[[LastErrorMessage]]') ?? $errKind),
                            $errKind === 'timeout' ? 'TimeoutError' : 'NetworkError',
                        ),
                    );
                }
                return JsUndefined::instance();
            }

            // Async XHR: hand the transport off to a microtask so
            // send() returns synchronously and listeners can attach
            // mid-line.
            JsPromise::scheduleCallback(static function () use ($xhr, $body, $realm): void {
                self::runTransport($xhr, $body, $realm);
            });
            return JsUndefined::instance();
        };
    }

    private static function abortImpl(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            unset($args);
            $xhr = self::requireXhr($this_, 'abort');
            $xhr->setInternalProperty('[[Aborted]]', true);
            $rs = (int) ($xhr->getInternalProperty('[[ReadyState]]') ?? 0);
            $sendFlag = (bool) ($xhr->getInternalProperty('[[SendFlag]]') ?? false);
            // Spec §4.5.7:
            //   - If state is (OPENED with send flag set) OR
            //     HEADERS_RECEIVED OR LOADING:
            //       run request-error steps with "abort", set state
            //       to DONE, fire `abort` + `loadend`, then set state
            //       to UNSENT.
            //   - If state is DONE: set state to UNSENT.
            //   - Else (UNSENT, or OPENED with send flag NOT set):
            //     do nothing observable. State stays put.
            if (($rs === 1 && $sendFlag) || $rs === 2 || $rs === 3) {
                $xhr->setInternalProperty('[[Status]]', 0);
                $xhr->setInternalProperty('[[StatusText]]', '');
                // If the upload phase was still in progress (no
                // HEADERS_RECEIVED yet), the spec requires upload's
                // abort + loadend before the request-side events.
                $uploadLen = (int) ($xhr->getInternalProperty('[[UploadBodyLength]]') ?? 0);
                if ($rs === 1 && $uploadLen > 0) {
                    self::fireUploadEvent($xhr, 'abort', 0, $uploadLen);
                    self::fireUploadEvent($xhr, 'loadend', 0, $uploadLen);
                }
                self::setReadyState($xhr, 4);
                self::fireEvent($xhr, 'abort');
                self::fireEvent($xhr, 'loadend');
                $xhr->setInternalProperty('[[ReadyState]]', 0);
                $xhr->setInternalProperty('[[SendFlag]]', false);
            } elseif ($rs === 4) {
                $xhr->setInternalProperty('[[ReadyState]]', 0);
                $xhr->setInternalProperty('[[SendFlag]]', false);
            }
            // OPENED without send flag, or UNSENT — leave readyState
            // alone. The spec is explicit that no events fire here.
            return JsUndefined::instance();
        };
    }

    private static function getResponseHeaderImpl(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            $xhr = self::requireXhr($this_, 'getResponseHeader');
            $name = strtolower(TypeConversion::toString($args[0] ?? JsUndefined::instance()));
            $headers = $xhr->getInternalProperty('[[ResponseHeaders]]');
            if (!is_array($headers)) {
                return JsNull::instance();
            }
            $values = [];
            foreach ($headers as [$h, $v]) {
                if (strtolower($h) === $name) {
                    $values[] = $v;
                }
            }
            return $values === [] ? JsNull::instance() : new JsString(implode(', ', $values));
        };
    }

    private static function getAllResponseHeadersImpl(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            unset($args);
            $xhr = self::requireXhr($this_, 'getAllResponseHeaders');
            $headers = $xhr->getInternalProperty('[[ResponseHeaders]]');
            if (!is_array($headers) || $headers === []) {
                return new JsString('');
            }
            $combined = [];
            foreach ($headers as [$h, $v]) {
                $key = strtolower($h);
                $combined[$key] = isset($combined[$key]) ? $combined[$key] . ', ' . $v : $v;
            }
            $out = '';
            foreach ($combined as $name => $value) {
                $out .= $name . ': ' . $value . "\r\n";
            }
            return new JsString($out);
        };
    }

    private static function overrideMimeTypeImpl(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            // Spec lets the caller force a response Content-Type.
            // We accept and ignore — our text decoding is always UTF-8.
            self::requireXhr($this_, 'overrideMimeType');
            unset($args);
            return JsUndefined::instance();
        };
    }

    // -----------------------------------------------------------------
    // Transport driver
    // -----------------------------------------------------------------

    private static function runTransport(JsObject $xhr, string $body, Engine $realm): void
    {
        if ($xhr->getInternalProperty('[[Aborted]]') === true) {
            return;
        }
        $method = (string) $xhr->getInternalProperty('[[Method]]');
        $url = (string) $xhr->getInternalProperty('[[Url]]');
        $rawHeaders = $xhr->getInternalProperty('[[RequestHeaders]]');
        $headers = is_array($rawHeaders) ? $rawHeaders : [];
        $timeoutMs = (int) $xhr->getInternalProperty('[[Timeout]]');

        // Per Fetch spec, request inherits Origin from the realm's
        // base URL when it's not already present. We derive it from
        // the realm's __phasisRequestBaseUrl (set by the WPT runner)
        // or location.href so fixtures that test CORS / Origin echo
        // see a meaningful value rather than an empty header.
        $hasOrigin = false;
        foreach ($headers as $h) {
            if (strcasecmp((string) ($h[0] ?? ''), 'origin') === 0) {
                $hasOrigin = true;
                break;
            }
        }
        if (!$hasOrigin) {
            $origin = self::deriveOrigin($realm);
            if ($origin !== null) {
                $headers[] = ['Origin', $origin];
            }
        }

        $descriptor = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'redirect' => 'follow',
            // XHR follows redirects automatically (spec §4.5.6 step
            // 12). We delegate the walk to libcurl rather than
            // reimplementing here so Authorization and other auth
            // headers persist across same-origin redirects.
            'followRedirects' => true,
            'timeout' => $timeoutMs > 0 ? (int) ceil($timeoutMs / 1000.0) : 30,
            'connectTimeout' => 10,
            'credentials' => $xhr->getInternalProperty('[[WithCredentials]]') === true ? 'include' : 'same-origin',
        ];

        $transport = $realm->getFetchTransport();

        try {
            $response = $transport !== null
                ? $transport($descriptor, null)
                : CurlTransport::send($descriptor, null);
        } catch (TransportException $e) {
            $kind = $e->kind;
            if ($kind === 'timeout') {
                self::finishTimeout($xhr);
            } elseif ($kind === 'aborted') {
                // Mid-flight abort — treat like our own abort.
                $xhr->setInternalProperty('[[Aborted]]', true);
                self::setReadyState($xhr, 4);
                self::fireEvent($xhr, 'abort');
                self::fireEvent($xhr, 'loadend');
            } else {
                self::finishError($xhr, $e->getMessage());
            }
            return;
        } catch (\Throwable $e) {
            self::finishError($xhr, $e->getMessage());
            return;
        }

        if ($xhr->getInternalProperty('[[Aborted]]')) {
            return;
        }

        $status = (int) ($response['status'] ?? 0);
        $statusText = (string) ($response['statusText'] ?? '');
        $respHeaders = $response['headers'] ?? [];
        $respBody = (string) ($response['body'] ?? '');
        $finalUrl = (string) ($response['finalUrl'] ?? $url);

        // Upload phase completed — fire the upload progress / load /
        // loadend events. The real network has streamed the body to
        // the wire by the time we see headers; our curl-driven
        // transport returns synchronously after the full request
        // and response, so we emit the upload events in a single
        // batch right before the response phase starts.
        $uploadLen = (int) ($xhr->getInternalProperty('[[UploadBodyLength]]') ?? 0);
        if ($uploadLen > 0) {
            self::fireUploadEvent($xhr, 'progress', $uploadLen, $uploadLen);
            self::fireUploadEvent($xhr, 'load', $uploadLen, $uploadLen);
            self::fireUploadEvent($xhr, 'loadend', $uploadLen, $uploadLen);
        }

        $xhr->setInternalProperty('[[Status]]', $status);
        $xhr->setInternalProperty('[[StatusText]]', $statusText);
        $xhr->setInternalProperty('[[ResponseHeaders]]', is_array($respHeaders) ? $respHeaders : []);
        $xhr->setInternalProperty('[[ResponseUrl]]', $finalUrl);

        self::setReadyState($xhr, 2); // HEADERS_RECEIVED

        $xhr->setInternalProperty('[[ResponseBody]]', $respBody);
        self::setReadyState($xhr, 3); // LOADING
        self::fireEvent($xhr, 'progress');

        self::setReadyState($xhr, 4); // DONE
        self::fireEvent($xhr, 'load');
        self::fireEvent($xhr, 'loadend');
    }

    private static function finishError(JsObject $xhr, string $message): void
    {
        $xhr->setInternalProperty('[[Status]]', 0);
        $xhr->setInternalProperty('[[StatusText]]', '');
        // Remember the failure kind so the sync send() path can
        // surface it as a NetworkError DOMException per spec.
        $xhr->setInternalProperty('[[LastErrorKind]]', 'network');
        $xhr->setInternalProperty('[[LastErrorMessage]]', $message);
        self::setReadyState($xhr, 4);
        self::fireEvent($xhr, 'error');
        self::fireEvent($xhr, 'loadend');
    }

    private static function finishTimeout(JsObject $xhr): void
    {
        $xhr->setInternalProperty('[[Status]]', 0);
        $xhr->setInternalProperty('[[StatusText]]', '');
        $xhr->setInternalProperty('[[LastErrorKind]]', 'timeout');
        self::setReadyState($xhr, 4);
        self::fireEvent($xhr, 'timeout');
        self::fireEvent($xhr, 'loadend');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private static function setReadyState(JsObject $xhr, int $state): void
    {
        $xhr->setInternalProperty('[[ReadyState]]', $state);
        self::fireEvent($xhr, 'readystatechange');
    }

    private static function fireEvent(JsObject $xhr, string $name): void
    {
        // Per XHR spec, every event the request lifecycle fires —
        // except readystatechange — is a ProgressEvent. Build one
        // event object up-front; both the on<event> handler and the
        // addEventListener listeners receive the same shape.
        $isProgressEvent = $name !== 'readystatechange';
        if ($isProgressEvent) {
            $loaded = strlen((string) ($xhr->getInternalProperty('[[ResponseBody]]') ?? ''));
            $event = self::makeProgressEvent($name, $xhr, false, $loaded, 0);
        } else {
            $event = self::makeEventObject($name, $xhr);
        }
        // 1. Invoke the on<event> handler attribute if set.
        $handler = $xhr->getInternalProperty('[[On' . $name . ']]');
        if ($handler instanceof JsFunction) {
            try {
                Engine::getCurrentRealm()?->getInterpreter()->callFunction(
                    $handler,
                    $xhr,
                    [$event],
                );
            } catch (\Throwable $e) {
                error_log('Phasis: uncaught in XHR.' . $name . ': ' . $e->getMessage());
            }
        }
        // 2. Dispatch to addEventListener listeners via EventTarget.
        $listeners = $xhr->getInternalProperty('[[EventListeners]]');
        if (is_array($listeners) && isset($listeners[$name])) {
            foreach ($listeners[$name] as $entry) {
                $fn = $entry['callback'] ?? null;
                if ($fn instanceof JsFunction) {
                    try {
                        Engine::getCurrentRealm()?->getInterpreter()->callFunction(
                            $fn,
                            $xhr,
                            [$event],
                        );
                    } catch (\Throwable $e) {
                        error_log('Phasis: uncaught in XHR.' . $name . ' listener: ' . $e->getMessage());
                    }
                }
            }
        }
    }

    private static function makeEventObject(string $type, JsObject $target): JsObject
    {
        $event = new JsObject();
        $event->setInternalProperty('[[IsEvent]]', true);
        $event->defineOwnProperty('type', PropertyDescriptor::data(new JsString($type), false, true, false));
        $event->defineOwnProperty('target', PropertyDescriptor::data($target, false, true, false));
        $event->defineOwnProperty('currentTarget', PropertyDescriptor::data($target, false, true, false));
        $event->defineOwnProperty('bubbles', PropertyDescriptor::data(JsBoolean::of(false), false, true, false));
        $event->defineOwnProperty('cancelable', PropertyDescriptor::data(JsBoolean::of(false), false, true, false));
        return $event;
    }

    /**
     * Reduce a JS body argument to wire bytes plus the matching
     * Content-Type. The Content-Type is `null` when the body shape
     * does not imply one (e.g. ArrayBuffer / TypedArray / strings —
     * the caller's setRequestHeader wins). FormData generates its
     * own boundary; URLSearchParams / Blob carry their own MIME.
     *
     * @return array{bytes: string, contentType: ?string}
     */
    private static function bodyArgToBytes(JsValue $val): array
    {
        if ($val instanceof JsUndefined || $val instanceof JsNull) {
            return ['bytes' => '', 'contentType' => null];
        }
        if ($val instanceof JsString) {
            return [
                'bytes' => $val->value,
                'contentType' => 'text/plain;charset=UTF-8',
            ];
        }
        if ($val instanceof JsArrayBuffer) {
            return [
                'bytes' => $val->readBytes(0, $val->getByteLength()),
                'contentType' => null,
            ];
        }
        if ($val instanceof JsTypedArray) {
            return [
                'bytes' => $val->getBuffer()->readBytes(
                    $val->getByteOffset(),
                    $val->getLength() * $val->getBytesPerElement(),
                ),
                'contentType' => null,
            ];
        }
        if ($val instanceof JsObject) {
            // Blob / File — bytes are stored directly.
            if (BlobConstructor::isBlob($val)) {
                $bytes = BlobConstructor::getBytes($val);
                $type = BlobConstructor::getType($val);
                return [
                    'bytes' => $bytes,
                    'contentType' => $type !== '' ? $type : null,
                ];
            }
            // FormData → multipart/form-data with a generated boundary.
            if (\Phasis\BuiltIn\FormDataConstructor::isFormData($val)) {
                $boundary = '----PhasisFormBoundary' . bin2hex(random_bytes(8));
                $bytes = \Phasis\BuiltIn\Fetch\BodyExtraction::serializeFormData($val, $boundary);
                return [
                    'bytes' => $bytes,
                    'contentType' => 'multipart/form-data; boundary=' . $boundary,
                ];
            }
            // URLSearchParams stringifies to its urlencoded form via
            // toString(). The send-usp.any.js fixtures already round-
            // trip the bytes correctly through the fallback below, so
            // we only need to set the right Content-Type here.
            if ($val->getInternalProperty('[[SearchParamsList]]') !== null) {
                return [
                    'bytes' => TypeConversion::toString($val),
                    'contentType' => 'application/x-www-form-urlencoded;charset=UTF-8',
                ];
            }
        }
        return [
            'bytes' => TypeConversion::toString($val),
            'contentType' => null,
        ];
    }

    private static function bufferFromBytes(string $bytes): JsArrayBuffer
    {
        $buf = new JsArrayBuffer(strlen($bytes), JsArrayBuffer::getDefaultPrototype());
        if ($bytes !== '') {
            $buf->writeBytes(0, $bytes);
        }
        return $buf;
    }

    private static function parseJsonOrNull(string $body): JsValue
    {
        if ($body === '') {
            return JsNull::instance();
        }
        // Decode in PHP, then convert via PhpToJs so we end up with a
        // proper JsObject / JsArray graph (not a PHP-side array that
        // user code can't introspect through the JS surface).
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return JsNull::instance();
        }
        return \Phasis\Interop\PhpToJs::convert($decoded);
    }

    private static function makeBlob(string $bytes): JsValue
    {
        // Best-effort: try to find Blob via the current realm. Falls
        // back to a plain object exposing { size, type, text() }.
        $realm = Engine::getCurrentRealm();
        if ($realm !== null) {
            try {
                $blobCtor = $realm->getGlobalEnv()->get('Blob');
                if ($blobCtor instanceof JsFunction) {
                    $arr = new \Phasis\Value\JsArray();
                    $arr->defineOwnProperty('0', PropertyDescriptor::data(new JsString($bytes), true, true, true));
                    $arr->setLength(1);
                    return $blobCtor->construct([$arr]);
                }
            } catch (\Throwable) {
                // fall through
            }
        }
        return new JsString($bytes);
    }

    private static function requireXhr(JsValue $val, string $op): JsObject
    {
        if (!$val instanceof JsObject || $val->getInternalProperty('[[IsXHR]]') !== true) {
            throw new TypeError("Failed to call '{$op}' on a non-XMLHttpRequest");
        }
        return $val;
    }
}
