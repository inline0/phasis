<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Fetch;

use Phasis\BuiltIn\AbortControllerConstructor;
use Phasis\Value\JsObject;

/**
 * Default HTTP transport for `fetch()` — wraps PHP's libcurl bindings.
 *
 * The transport interface is a single static method:
 *
 *     CurlTransport::send($descriptor, $signal): array
 *
 * Embedders can swap in their own callable via
 * `$engine->setFetchTransport($callable)`. The callable receives the
 * same descriptor shape and must return the same response shape.
 *
 * Descriptor (input):
 *   method        : string  — uppercased HTTP verb
 *   url           : string  — absolute URL
 *   headers       : list<array{0:string,1:string}> — header pairs
 *   body          : string  — raw request body (empty for GET/HEAD)
 *   redirect      : string  — "follow" | "error" | "manual"
 *   timeout       : int     — seconds (overall), defaults to 30
 *   connectTimeout: int     — seconds (TCP connect), defaults to 10
 *
 * Response (output, on success):
 *   status     : int
 *   statusText : string
 *   headers    : list<array{0:string,1:string}>
 *   body       : string (raw bytes)
 *   finalUrl   : string (after any redirects — empty if no redirects)
 *   redirected : bool
 *
 * On failure we throw `CurlTransport\TransportException` carrying a
 * `kind` of `"network-error"`, `"timeout"`, or `"aborted"`. The fetch
 * caller translates these into TypeError / AbortError per spec.
 *
 * Redirect handling: we set `CURLOPT_FOLLOWLOCATION = false` and let
 * `fetch()` walk the redirect chain manually (per WHATWG §5.4 main
 * fetch). This is the only way to honor the `manual` redirect mode
 * and to give the cookie jar a chance to intercept each hop.
 */
final class CurlTransport
{
    /**
     * @param array<string,mixed> $descriptor
     * @return array{
     *     status:int,
     *     statusText:string,
     *     headers:list<array{0:string,1:string}>,
     *     body:string,
     *     finalUrl:string,
     *     redirected:bool
     * }
     * @throws TransportException
     */
    public static function send(array $descriptor, ?JsObject $signal = null): array
    {
        if (!function_exists('curl_init')) {
            throw new TransportException('curl extension is not available', 'network-error');
        }

        // Pre-flight abort check: if signal was already aborted before we
        // got here, surface "aborted" without opening a socket.
        if (self::signalAborted($signal)) {
            throw new TransportException('The operation was aborted.', 'aborted');
        }

        $method = (string) ($descriptor['method'] ?? 'GET');
        $url = (string) ($descriptor['url'] ?? '');
        $headers = $descriptor['headers'] ?? [];
        $body = (string) ($descriptor['body'] ?? '');
        $timeout = (int) ($descriptor['timeout'] ?? 30);
        $connectTimeout = (int) ($descriptor['connectTimeout'] ?? 10);

        // Header storage, populated by CURLOPT_HEADERFUNCTION.
        $responseHeaders = [];
        $statusLine = '';

        $ch = curl_init();
        if ($ch === false) {
            throw new TransportException('Failed to initialise curl handle', 'network-error');
        }

        $bodyBuf = '';

        // Translate headers list-of-pairs to curl's flat header array. If
        // the same header appears multiple times curl forwards each one.
        $headerLines = [];
        if (is_array($headers)) {
            foreach ($headers as $pair) {
                if (!is_array($pair) || count($pair) < 2) {
                    continue;
                }
                $headerLines[] = $pair[0] . ': ' . $pair[1];
            }
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            // We capture the response body via WRITEFUNCTION rather than
            // returning a buffer, so streaming consumers can later swap
            // to chunked delivery without changing the contract.
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            // We walk redirects manually so fetch() can honour
            // `manual` / `error` modes and let cookie jars intercept.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$responseHeaders, &$statusLine) {
                unset($curl);
                $len = strlen($line);
                $trimmed = rtrim($line, "\r\n");
                if ($trimmed === '') {
                    return $len;
                }
                if (stripos($trimmed, 'HTTP/') === 0) {
                    // New status line: reset accumulator (libcurl re-emits
                    // headers per hop, even though we disabled follow).
                    $statusLine = $trimmed;
                    $responseHeaders = [];
                    return $len;
                }
                $colon = strpos($trimmed, ':');
                if ($colon !== false) {
                    $name = substr($trimmed, 0, $colon);
                    $value = ltrim(substr($trimmed, $colon + 1));
                    $responseHeaders[] = [$name, $value];
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$bodyBuf) {
                unset($curl);
                $bodyBuf .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_NOPROGRESS => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        // Body payload: only attach when the method allows one. curl's
        // POSTFIELDS auto-sets Content-Length if not present and copies
        // bytes verbatim (binary-safe when given a string).
        if ($body !== '' && $method !== 'GET' && $method !== 'HEAD') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        // Abort polling via progress function. libcurl calls this several
        // times a second during transfers. Returning non-zero aborts the
        // transfer (curl_exec returns false with CURLE_ABORTED_BY_CALLBACK).
        if ($signal !== null) {
            $signalRef = $signal;
            $options[CURLOPT_PROGRESSFUNCTION] = static function (
                $curl,
                $downloadTotal,
                $downloadSoFar,
                $uploadTotal,
                $uploadSoFar
            ) use ($signalRef) {
                unset($curl, $downloadTotal, $downloadSoFar, $uploadTotal, $uploadSoFar);
                return self::signalAborted($signalRef) ? 1 : 0;
            };
        }

        curl_setopt_array($ch, $options);

        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $errstr = curl_error($ch);
        // PHP 8.0+: curl handles are CurlHandle objects, freed when they
        // fall out of scope. curl_close() is deprecated as of PHP 8.5 and
        // had no effect since 8.0 — drop $ch and let PHP reclaim it.
        unset($ch, $result); // we accumulated into $bodyBuf via WRITEFUNCTION

        if ($errno !== 0) {
            // Map a few well-known curl codes to spec-meaningful kinds.
            // Anything else maps to "network-error" → TypeError per fetch
            // spec. Re-check the signal because the progress callback may
            // have fired between the last in-loop check and the final
            // curl_exec return; treat that as an abort.
            if ($errno === CURLE_ABORTED_BY_CALLBACK || self::signalAborted($signal)) {
                throw new TransportException('The operation was aborted.', 'aborted');
            }
            if ($errno === CURLE_OPERATION_TIMEOUTED) {
                throw new TransportException('Request timed out: ' . $errstr, 'timeout');
            }
            throw new TransportException('Network error: ' . $errstr, 'network-error');
        }

        // Parse the captured status line. The spec status is the integer,
        // statusText is the optional reason-phrase. HTTP/2 responses don't
        // carry a reason phrase; we synthesise an empty one in that case.
        [$statusCode, $statusText] = self::parseStatusLine($statusLine);

        return [
            'status' => $statusCode,
            'statusText' => $statusText,
            'headers' => $responseHeaders,
            'body' => $bodyBuf,
            // The default transport never follows redirects, so the URL
            // never changes during transport. fetch() updates this when
            // it walks the chain.
            'finalUrl' => $url,
            'redirected' => false,
        ];
    }

    /**
     * Parse an HTTP status line `HTTP/1.1 200 OK` into `[code, text]`.
     * Defaults to `[200, '']` if the line is malformed (defensive — curl
     * doesn't surface the response body without a valid status line, so
     * this only matters for very exotic servers).
     *
     * @return array{0:int,1:string}
     */
    private static function parseStatusLine(string $line): array
    {
        if ($line === '') {
            return [200, ''];
        }
        // Expected shape: HTTP/<ver> <code> <reason...>
        $parts = explode(' ', $line, 3);
        if (count($parts) < 2) {
            return [200, ''];
        }
        $code = (int) $parts[1];
        if ($code < 100 || $code > 599) {
            $code = 200;
        }
        $reason = isset($parts[2]) ? trim($parts[2]) : '';
        return [$code, $reason];
    }

    /**
     * Read AbortSignal.aborted from a JS signal object without going
     * through the JS accessor (we don't have an interpreter handy here).
     * Marked impure because the signal's [[Aborted]] slot can be flipped
     * asynchronously by AbortController.abort() (called from JS or PHP)
     * between two reads — PHPStan can't see that otherwise.
     *
     * @phpstan-impure
     */
    private static function signalAborted(?JsObject $signal): bool
    {
        if ($signal === null) {
            return false;
        }
        return $signal->getInternalProperty(AbortControllerConstructor::SLOT_ABORTED) === true;
    }
}
