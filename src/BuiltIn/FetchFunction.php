<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\BuiltIn\Fetch\CurlTransport;
use Phasis\BuiltIn\Fetch\TransportException;
use Phasis\Engine;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WHATWG `fetch(input, init?)` global.
 *
 * Spec: https://fetch.spec.whatwg.org/#dom-global-fetch
 *
 * The function returns a `Promise<Response>`. The heavy lifting is:
 *
 *  1. Normalize `input` into a Request via the existing
 *     RequestConstructor (handles strings, URL objects, and Request
 *     instances per spec).
 *  2. Apply the embedder's fetch-policy hook (if any). Hook can throw to
 *     deny outright, return null/undefined to pass through, or return a
 *     replacement Request to rewrite the outgoing call.
 *  3. Check the request's AbortSignal — if already aborted before we
 *     start, reject with an AbortError DOMException.
 *  4. Add `Cookie:` from the engine's cookie jar (if set).
 *  5. Hand the descriptor to the transport (CurlTransport by default,
 *     or whatever the embedder swapped in). Honour the `redirect`
 *     mode: "follow" walks redirects up to 20 hops; "error" rejects
 *     on any 3xx with a Location; "manual" returns the redirect
 *     Response unmodified.
 *  6. Store any `Set-Cookie` response headers into the jar.
 *  7. Build a Response from the transport output and resolve.
 *
 * AbortError handling: signal aborts during the transport call surface
 * as `TransportException` with kind="aborted" and are translated to a
 * rejected promise carrying the signal's reason (if any) or a fresh
 * AbortError DOMException.
 */
final class FetchFunction
{
    /** Spec-mandated redirect limit per §5.4 main fetch. */
    private const REDIRECT_LIMIT = 20;

    public static function install(Environment $env): void
    {
        $fetchFn = JsFunction::fromCallable(
            'fetch',
            static function (JsValue $this_, array $args) use ($env): JsValue {
                unset($this_);
                $input = $args[0] ?? JsUndefined::instance();
                $init = $args[1] ?? JsUndefined::instance();
                return self::doFetch($input, $init, $env);
            },
            1,
        );

        $env->defineVar('fetch', $fetchFn);
    }

    /**
     * Entry point. Always returns a `JsPromise<Response>`; failures are
     * reflected as rejections, never thrown synchronously (matches spec
     * §5.4 step 1: "fetch() returns a promise").
     */
    private static function doFetch(JsValue $input, JsValue $init, Environment $env): JsPromise
    {
        try {
            // ---------- Step 1: normalize input → Request -------------
            $request = self::buildRequest($input, $init, $env);

            // ---------- Step 2: fetch policy hook ---------------------
            $request = self::applyFetchPolicy($request);

            // ---------- Step 3: pre-aborted signal check --------------
            $signal = self::extractSignal($request);
            if (self::signalAborted($signal)) {
                return self::rejectedWithAbortReason($signal, $env);
            }

            // ---------- Steps 4-8: transport + redirect loop ----------
            return self::performTransport($request, $env);
        } catch (\Phasis\Exceptions\JsThrowable $e) {
            // A JS-throwing operation during normalization → reject.
            return JsPromise::rejected($e->jsValue);
        } catch (\Throwable $e) {
            // Defensive: wrap any unexpected PHP exception as a TypeError
            // so JS code observes the spec failure mode.
            return JsPromise::rejected(self::buildTypeError($env, $e->getMessage()));
        }
    }

    /**
     * Reuse RequestConstructor to build a Request from `(input, init)`.
     * Always returns a fresh Request — passing through a Request the
     * user already constructed runs the constructor's "copy" path so
     * the body/signal/etc. are detached from the original.
     */
    private static function buildRequest(JsValue $input, JsValue $init, Environment $env): JsObject
    {
        if (!$env->has('Request')) {
            throw new TypeError('Request constructor is not installed');
        }
        $ctor = $env->get('Request');
        if (!$ctor instanceof JsFunction) {
            throw new TypeError('Request constructor is not callable');
        }
        $constructed = $ctor->construct([$input, $init]);
        if (!$constructed instanceof JsObject) {
            throw new TypeError('Request constructor did not return an object');
        }
        return $constructed;
    }

    /**
     * Apply the engine's fetch-policy hook, if any. Per the contract
     * (PRD-FETCH-PACK §4):
     *
     *  - Returning null/undefined → use the original request.
     *  - Returning a Request → use that one instead.
     *  - Throwing → propagates as fetch rejection.
     */
    private static function applyFetchPolicy(JsObject $request): JsObject
    {
        $realm = Engine::getCurrentRealm();
        if ($realm === null) {
            return $request;
        }
        $policy = $realm->getFetchPolicy();
        if ($policy === null) {
            return $request;
        }
        $result = $policy($request);
        if ($result === null) {
            return $request;
        }
        if (!$result instanceof JsObject || !RequestConstructor::isRequest($result)) {
            throw new TypeError('Fetch policy hook must return a Request, null, or undefined');
        }
        return $result;
    }

    /**
     * Drive the request through the transport, walking the redirect
     * chain manually so `manual`/`error` modes work and cookie jars
     * see every hop.
     */
    private static function performTransport(JsObject $request, Environment $env): JsPromise
    {
        $realm = Engine::getCurrentRealm();
        $transport = $realm?->getFetchTransport();
        $cookieJar = $realm?->getCookieJar();

        $url = self::stringSlot($request, '[[Url]]');
        $method = self::stringSlot($request, '[[Method]]');
        $redirectMode = self::stringSlot($request, '[[Redirect]]') ?: 'follow';
        $signal = self::extractSignal($request);
        $bodyBytes = $request->getInternalProperty('[[BodyBytes]]');
        $bodyString = is_string($bodyBytes) ? $bodyBytes : '';

        $redirected = false;
        $hops = 0;

        while (true) {
            // Spec step 4: build transport descriptor for the current URL.
            $headers = self::buildHeaderPairs($request, $url);

            // Step 5: layer the cookie jar's `Cookie` header (if any).
            if ($cookieJar !== null) {
                $cookieHeader = self::cookieJarGet($cookieJar, $url);
                if ($cookieHeader !== '') {
                    $headers[] = ['Cookie', $cookieHeader];
                }
            }

            // Identify the engine — default User-Agent if the request
            // didn't already specify one.
            if (!self::hasHeader($headers, 'user-agent')) {
                $headers[] = ['User-Agent', NavigatorObject::USER_AGENT];
            }
            // Mirror the request's content type if the body extraction
            // already set one (RequestConstructor stores it on the
            // Headers object — buildHeaderPairs has already pulled it).

            $descriptor = [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => $bodyString,
                'redirect' => $redirectMode,
                'timeout' => 30,
                'connectTimeout' => 10,
            ];

            // Step 6: transport. Either the default CurlTransport, or an
            // embedder-supplied callable.
            try {
                $response = self::callTransport($transport, $descriptor, $signal);
            } catch (TransportException $e) {
                return self::rejectFromTransport($e, $signal, $env);
            } catch (\Throwable $e) {
                // An embedder transport may raise any throwable. Treat
                // every non-TransportException failure as a network error.
                return JsPromise::rejected(self::buildTypeError($env, $e->getMessage()));
            }

            // Step 7: feed Set-Cookie back into the jar.
            if ($cookieJar !== null) {
                self::storeSetCookies($cookieJar, $url, $response['headers'] ?? []);
            }

            $status = (int) ($response['status'] ?? 200);
            $statusText = (string) ($response['statusText'] ?? '');
            $headersOut = $response['headers'] ?? [];
            $body = (string) ($response['body'] ?? '');
            $finalUrl = (string) ($response['finalUrl'] ?? $url);

            // Step 10: redirect handling.
            if (self::isRedirectStatus($status)) {
                $location = self::extractLocation($headersOut);
                if ($location !== null) {
                    if ($redirectMode === 'error') {
                        return JsPromise::rejected(self::buildTypeError(
                            $env,
                            'Redirect mode is set to "error" but server returned a redirect.',
                        ));
                    }
                    if ($redirectMode === 'manual') {
                        return self::buildResponsePromise(
                            $env,
                            $body,
                            $status,
                            $statusText,
                            $headersOut,
                            $finalUrl,
                            $redirected,
                            'opaqueredirect',
                        );
                    }
                    // follow: walk the chain, capped at REDIRECT_LIMIT.
                    $hops++;
                    if ($hops > self::REDIRECT_LIMIT) {
                        return JsPromise::rejected(self::buildTypeError(
                            $env,
                            'Too many redirects (exceeded ' . self::REDIRECT_LIMIT . ' hops)',
                        ));
                    }
                    // Resolve relative Location against the current URL.
                    $url = self::resolveLocation($url, $location);
                    $redirected = true;
                    // Per spec, 303 and 301/302-with-non-GET force the
                    // method down to GET and strip the body. 307/308
                    // preserve method + body.
                    if ($status === 303 || ($status === 301 || $status === 302) && $method !== 'GET' && $method !== 'HEAD') {
                        $method = 'GET';
                        $bodyString = '';
                    }
                    // Continue loop with the new URL.
                    continue;
                }
            }

            // Non-redirect (or redirect status without a Location header):
            // build the Response and resolve.
            return self::buildResponsePromise(
                $env,
                $body,
                $status,
                $statusText,
                $headersOut,
                $finalUrl,
                $redirected,
                'basic',
            );
        }
    }

    /**
     * Wrap the (potentially-overridden) transport so callers don't have
     * to care whether it's the static default or a JS-side callable.
     *
     * @param callable|null $transport
     * @param array<string,mixed> $descriptor
     * @return array<string,mixed>
     */
    private static function callTransport(?callable $transport, array $descriptor, ?JsObject $signal): array
    {
        if ($transport === null) {
            return CurlTransport::send($descriptor, $signal);
        }
        $result = $transport($descriptor, $signal);
        if (!is_array($result)) {
            throw new \RuntimeException('fetch transport must return an array');
        }
        return $result;
    }

    /**
     * Build the transport descriptor's header list from the Request's
     * Headers object. Pulls the spec-mandated `Host:` (added by curl) and
     * `Content-Type` (set by Body extraction) etc. Returns a flat
     * `list<[string, string]>`.
     *
     * @return list<array{0:string,1:string}>
     */
    private static function buildHeaderPairs(JsObject $request, string $url): array
    {
        unset($url); // host is auto-added by curl; reserved for future use
        $out = [];
        $headers = $request->getInternalProperty('[[Headers]]');
        if (!$headers instanceof JsObject) {
            return $out;
        }
        $list = $headers->getInternalProperty(HeadersConstructor::SLOT_LIST);
        if (!is_array($list)) {
            return $out;
        }
        foreach ($list as $pair) {
            if (!is_array($pair) || count($pair) < 2) {
                continue;
            }
            $out[] = [(string) $pair[0], (string) $pair[1]];
        }
        return $out;
    }

    /**
     * Build a resolved JsPromise wrapping a Response constructed from
     * the transport output. Uses ResponseConstructor::createFromPhp so
     * the result has every internal slot the prototype getters expect.
     *
     * @param list<array{0:string,1:string}>|array<int,mixed> $headersList
     */
    private static function buildResponsePromise(
        Environment $env,
        string $body,
        int $status,
        string $statusText,
        array $headersList,
        string $url,
        bool $redirected,
        string $type = 'basic',
    ): JsPromise {
        $headers = HeadersConstructor::create($env, []);
        foreach ($headersList as $pair) {
            if (!is_array($pair) || count($pair) < 2) {
                continue;
            }
            HeadersConstructor::appendFromPhp($headers, (string) $pair[0], (string) $pair[1]);
        }
        $response = ResponseConstructor::createFromPhp(
            $env,
            $body,
            $status,
            $statusText,
            $headers,
            $url,
            $type,
            $redirected,
        );
        return JsPromise::resolved($response);
    }

    /**
     * Look up the AbortSignal on a Request. May return null when no
     * signal is attached.
     */
    private static function extractSignal(JsObject $request): ?JsObject
    {
        $sig = $request->getInternalProperty('[[Signal]]');
        return $sig instanceof JsObject ? $sig : null;
    }

    private static function signalAborted(?JsObject $signal): bool
    {
        if ($signal === null) {
            return false;
        }
        return $signal->getInternalProperty(AbortControllerConstructor::SLOT_ABORTED) === true;
    }

    /**
     * Promise rejected with whatever .reason the signal carries (or a
     * fresh AbortError DOMException if reason is undefined — matches
     * the spec default).
     */
    private static function rejectedWithAbortReason(?JsObject $signal, Environment $env): JsPromise
    {
        $reason = $signal?->getInternalProperty(AbortControllerConstructor::SLOT_REASON);
        if (!$reason instanceof JsValue || $reason instanceof JsUndefined) {
            $reason = DomExceptionConstructor::create($env, 'The operation was aborted.', 'AbortError');
        }
        return JsPromise::rejected($reason);
    }

    /**
     * Map a transport failure to a rejected promise. Aborted → carries
     * the signal's reason if any; timeout / network-error → TypeError.
     */
    private static function rejectFromTransport(TransportException $e, ?JsObject $signal, Environment $env): JsPromise
    {
        if ($e->kind === 'aborted') {
            return self::rejectedWithAbortReason($signal, $env);
        }
        return JsPromise::rejected(self::buildTypeError($env, $e->getMessage()));
    }

    /**
     * Construct a plain JS TypeError instance from PHP.
     */
    private static function buildTypeError(Environment $env, string $message): JsObject
    {
        if ($env->has('TypeError')) {
            $ctor = $env->get('TypeError');
            if ($ctor instanceof JsFunction) {
                $err = $ctor->construct([new JsString($message)]);
                if ($err instanceof JsObject) {
                    return $err;
                }
            }
        }
        $fallback = new JsObject();
        $fallback->defineOwnProperty(
            'name',
            PropertyDescriptor::data(new JsString('TypeError'), true, false, true),
        );
        $fallback->defineOwnProperty(
            'message',
            PropertyDescriptor::data(new JsString($message), true, false, true),
        );
        return $fallback;
    }

    // ------------------------------------------------------------------
    // Header / URL utilities
    // ------------------------------------------------------------------

    /**
     * @param list<array{0:string,1:string}> $pairs
     */
    private static function hasHeader(array $pairs, string $name): bool
    {
        $needle = strtolower($name);
        foreach ($pairs as $p) {
            if (strtolower((string) $p[0]) === $needle) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int,mixed> $headers
     */
    private static function extractLocation(array $headers): ?string
    {
        foreach ($headers as $p) {
            if (!is_array($p) || count($p) < 2) {
                continue;
            }
            if (strtolower((string) $p[0]) === 'location') {
                return (string) $p[1];
            }
        }
        return null;
    }

    private static function isRedirectStatus(int $status): bool
    {
        return $status === 301 || $status === 302 || $status === 303
            || $status === 307 || $status === 308;
    }

    /**
     * Resolve a possibly-relative Location header against the current
     * request URL. PHP's parse_url + a tiny join is enough — we don't
     * need to round-trip the full WHATWG URL parser here because the
     * Request's URL was already normalised at construction.
     */
    private static function resolveLocation(string $base, string $location): string
    {
        // Absolute URL: use as-is.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $location;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $origin = $parts['scheme'] . '://' . $parts['host'] . $port;
        if ($location !== '' && $location[0] === '/') {
            return $origin . $location;
        }
        // Relative to current path's directory.
        $path = $parts['path'] ?? '/';
        $dir = rtrim(substr($path, 0, (int) strrpos($path, '/') + 1), '/');
        return $origin . ($dir === '' ? '/' : $dir . '/') . $location;
    }

    private static function stringSlot(JsObject $obj, string $slot): string
    {
        $v = $obj->getInternalProperty($slot);
        return is_string($v) ? $v : '';
    }

    // ------------------------------------------------------------------
    // Cookie jar plumbing
    // ------------------------------------------------------------------

    /**
     * Call `jar.get(url)` to retrieve the `Cookie:` header value for
     * the request. Accepts both a JS-side JsObject (with JsFunction
     * `get` member) and a PHP object that implements a PHP `get($url)`
     * method — useful for embedders who want to mount a Guzzle cookie
     * jar etc.
     */
    private static function cookieJarGet(mixed $jar, string $url): string
    {
        if ($jar instanceof JsObject) {
            $fn = $jar->get('get');
            if ($fn instanceof JsFunction) {
                $result = $fn->call($jar, [new JsString($url)]);
                if ($result instanceof JsString) {
                    return $result->value;
                }
                if ($result instanceof JsUndefined) {
                    return '';
                }
                return \Phasis\Spec\TypeConversion::toString($result);
            }
            return '';
        }
        if (is_object($jar) && method_exists($jar, 'get')) {
            $result = $jar->get($url);
            return is_string($result) ? $result : '';
        }
        return '';
    }

    /**
     * Walk the response header list, pulling out each Set-Cookie and
     * pushing it into the jar via `jar.set(url, header)`. Per spec,
     * the jar is responsible for any path/expires/domain handling.
     *
     * @param array<int,mixed> $headers
     */
    private static function storeSetCookies(mixed $jar, string $url, array $headers): void
    {
        foreach ($headers as $p) {
            if (!is_array($p) || count($p) < 2) {
                continue;
            }
            if (strtolower((string) $p[0]) !== 'set-cookie') {
                continue;
            }
            self::cookieJarSet($jar, $url, (string) $p[1]);
        }
    }

    private static function cookieJarSet(mixed $jar, string $url, string $header): void
    {
        if ($jar instanceof JsObject) {
            $fn = $jar->get('set');
            if ($fn instanceof JsFunction) {
                $fn->call($jar, [new JsString($url), new JsString($header)]);
            }
            return;
        }
        if (is_object($jar) && method_exists($jar, 'set')) {
            $jar->set($url, $header);
        }
    }
}
