<?php

declare(strict_types=1);

/**
 * Phasis WPT test server.
 *
 * A tiny single-file HTTP server that emulates the WPT fetch test
 * fixtures' Python endpoints (under wpt/fetch/api/resources/*.py).
 * Re-implementing the dozen-ish routes WPT fetch tests actually hit.
 *
 * Run: `php -S 127.0.0.1:8765 -t tests/Wpt tests/Wpt/fetch-server.php`
 *
 * The server is routed by `$_SERVER['REQUEST_URI']` — every request
 * dispatches into a single `main()` matcher on the path prefix
 * `/resources/<handler>`. Each handler is a static method below.
 *
 * Determinism: no randomness, no time-of-day. Headers, body, and
 * status are all derived from request shape.
 */

namespace Phasis\Wpt\TestServer;

ini_set('display_errors', '1');
error_reporting(E_ALL);

/** @return array{0:int,1:array<string,string>,2:string} */
function dispatch(): array
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $query = $_GET;
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    // PHP swallows multipart/form-data into $_POST + $_FILES — read
    // them back into a synthetic multipart body so handlers that
    // expect to parse the raw bytes still get them.
    $body = file_get_contents('php://input') ?: '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (
        $body === ''
        && str_starts_with(strtolower($contentType), 'multipart/form-data')
        && (!empty($_POST) || !empty($_FILES))
    ) {
        if (preg_match('/boundary="?([^";\s]+)"?/', $contentType, $bm)) {
            $boundary = $bm[1];
            $crlf = "\r\n";
            foreach ($_POST as $k => $v) {
                $body .= '--' . $boundary . $crlf
                    . 'Content-Disposition: form-data; name="' . $k . '"' . $crlf
                    . $crlf
                    . (is_array($v) ? '' : (string) $v) . $crlf;
            }
            foreach ($_FILES as $k => $f) {
                if (!isset($f['tmp_name'])) {
                    continue;
                }
                $content = is_string($f['tmp_name']) && is_file($f['tmp_name'])
                    ? (string) file_get_contents($f['tmp_name'])
                    : '';
                $name = (string) ($f['name'] ?? 'blob');
                $type = (string) ($f['type'] ?? 'application/octet-stream');
                $body .= '--' . $boundary . $crlf
                    . 'Content-Disposition: form-data; name="' . $k . '"; filename="' . $name . '"' . $crlf
                    . 'Content-Type: ' . $type . $crlf
                    . $crlf
                    . $content . $crlf;
            }
            $body .= '--' . $boundary . '--' . $crlf;
        }
    }
    $headers = collectRequestHeaders();

    // Strip any prefix WPT layouts the fixtures hardcode — both
    // top-level `/resources/X` and the nested `/fetch/api/resources/X`
    // (xhr-authorization-redirect, access-control-and-redirects-* etc.)
    // route to the same handlers.
    $normalizedPath = preg_replace(
        '#^(?:/xhr|/fetch/api(?:/[a-z0-9-]+)?)/resources/#',
        '/resources/',
        $path,
    ) ?? $path;
    // /resources/<handler>[?args]
    if (preg_match('#^/resources/([a-z0-9-]+)(?:\.py)?$#i', $normalizedPath, $m)) {
        $handler = strtolower($m[1]);
        $fn = 'Phasis\\Wpt\\TestServer\\handle_' . str_replace('-', '_', $handler);
        if (function_exists($fn)) {
            return $fn($method, $query, $headers, $body);
        }
    }
    $path = $normalizedPath;
    // /resources/bad-chunk-encoding.py — emit a Transfer-Encoding:
    // chunked body that's deliberately malformed (missing the
    // terminating 0-chunk + CRLF + trailers). XHR's curl-backed
    // transport should surface this as a network error.
    if ($path === '/resources/bad-chunk-encoding.py') {
        http_response_code(200);
        header('Content-Type: text/plain');
        header('Transfer-Encoding: chunked');
        header_remove('Content-Length');
        // Valid-looking first chunk header, but the payload claims
        // 1000 bytes while only 4 bytes follow before connection
        // close — curl flags `CURLE_PARTIAL_FILE`.
        echo "3e8\r\nbad";
        // Don't flush a terminating chunk; let the connection drop.
        exit;
    }
    // /resources/well-formed.xml — static fixture file XHR tests read.
    if ($path === '/resources/well-formed.xml') {
        return [
            200,
            ['content-type' => 'application/xml'],
            "<?xml version=\"1.0\"?>\n<root><child>data</child></root>\n",
        ];
    }
    // /resources/utf16-bom.json — UTF-16 LE-with-BOM JSON used by
    // the xhr/json fixture to verify the response parser rejects
    // non-UTF-8 JSON.
    if ($path === '/resources/utf16-bom.json') {
        $jsonUtf16 = mb_convert_encoding('{"hello":"world"}', 'UTF-16LE', 'UTF-8');
        return [
            200,
            ['content-type' => 'application/json'],
            "\xFF\xFE" . $jsonUtf16,
        ];
    }
    // /common/blank.html — pipe directives are ignored; we just
    // return a tiny HTML body so the load completes.
    if ($path === '/common/blank.html') {
        return [200, ['content-type' => 'text/html'], '<!doctype html>'];
    }
    // /resources/urlpatterntestdata.json — backing data for the
    // urlpattern WPT fixture. Streamed straight off the upstream
    // sparse-checkout tree.
    if (str_ends_with($path, '/urlpatterntestdata.json')) {
        $candidate = dirname(__DIR__, 1) . '/Wpt/upstream/urlpattern/resources/urlpatterntestdata.json';
        if (is_file($candidate)) {
            return [
                200,
                ['content-type' => 'application/json'],
                (string) file_get_contents($candidate),
            ];
        }
    }

    // /cors/resources/not-cors-safelisted.json — small fixture file the
    // WPT fetch headers tests load to discover non-CORS-safelisted names.
    if ($path === '/cors/resources/not-cors-safelisted.json') {
        // Each entry: [name, value]. The fixture concatenates this with a
        // few more locally-defined entries before iterating.
        $rows = [
            ['authorization', 'whatever'],
            ['foo', 'bar'],
            ['x-foo', 'x-bar'],
        ];
        return [200, ['content-type' => 'application/json'], (string) json_encode($rows)];
    }

    return [404, ['Content-Type' => 'text/plain'], "no route: {$path}"];
}

/** @return array<string,string> */
function collectRequestHeaders(): array
{
    $out = [];
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with($k, 'HTTP_')) {
            $name = strtolower(str_replace('_', '-', substr($k, 5)));
            $out[$name] = $v;
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) {
        $out['content-type'] = $_SERVER['CONTENT_TYPE'];
    }
    if (isset($_SERVER['CONTENT_LENGTH'])) {
        $out['content-length'] = $_SERVER['CONTENT_LENGTH'];
    }
    return $out;
}

// -----------------------------------------------------------------------
// Handlers — one per WPT Python resource Phasis-side fixtures hit.
// -----------------------------------------------------------------------

/**
 * /resources/inspect-headers?headers=a|b|c[&cors]
 * Echoes named request headers back as x-request-<header>.
 *
 * @param array<string,string> $query
 * @param array<string,string> $headers
 * @return array{0:int,1:array<string,string>,2:string}
 */
function handle_inspect_headers(string $method, array $query, array $headers, string $body): array
{
    $resp = ['content-type' => 'text/plain'];
    if (isset($query['headers'])) {
        foreach (explode('|', $query['headers']) as $h) {
            $key = strtolower($h);
            if (isset($headers[$key])) {
                $resp['x-request-' . $key] = $headers[$key];
            }
        }
    }
    return [200, $resp, ''];
}

/**
 * /resources/echo-content
 * Returns the request body, with X-Request-Method / Content-Length /
 * Content-Type echoed.
 *
 * @param array<string,string> $query
 * @param array<string,string> $headers
 * @return array{0:int,1:array<string,string>,2:string}
 */
function handle_echo_content(string $method, array $query, array $headers, string $body): array
{
    $resp = [
        'content-type' => 'text/plain',
        'x-request-method' => $method,
        'x-request-content-length' => $headers['content-length'] ?? 'NO',
        'x-request-content-type' => $headers['content-type'] ?? 'NO',
    ];
    return [200, $resp, $body];
}

/**
 * /resources/status?code=NNN[&text=STATUS-TEXT][&content=BODY][&type=CT]
 *
 * @param array<string,string> $query
 * @param array<string,string> $headers
 * @return array{0:int,1:array<string,string>,2:string}
 */
function handle_status(string $method, array $query, array $headers, string $body): array
{
    $code = (int) ($query['code'] ?? 200);
    $type = $query['type'] ?? 'text/plain';
    $content = $query['content'] ?? '';
    return [$code, ['content-type' => $type, 'x-request-method' => $method], $content];
}

/**
 * /resources/redirect?location=...[&status=302]
 *
 * @param array<string,string> $query
 * @param array<string,string> $headers
 * @return array{0:int,1:array<string,string>,2:string}
 */
function handle_redirect(string $method, array $query, array $headers, string $body): array
{
    $status = (int) ($query['status'] ?? 302);
    $location = $query['location'] ?? '/';
    return [$status, ['location' => $location, 'content-type' => 'text/plain'], ''];
}

/**
 * /resources/set-cookie?name=k&value=v[&path=/][&domain=example.com]
 *
 * @param array<string,string> $query
 * @param array<string,string> $headers
 * @return array{0:int,1:array<string,string>,2:string}
 */
function handle_set_cookie(string $method, array $query, array $headers, string $body): array
{
    $name = $query['name'] ?? 'k';
    $value = $query['value'] ?? 'v';
    $extras = '';
    if (isset($query['path'])) {
        $extras .= '; Path=' . $query['path'];
    }
    if (isset($query['domain'])) {
        $extras .= '; Domain=' . $query['domain'];
    }
    return [
        200,
        ['content-type' => 'text/plain', 'set-cookie' => $name . '=' . $value . $extras],
        '',
    ];
}

/**
 * /resources/dump-headers — return all request headers as JSON.
 *
 * @param array<string,string> $query
 * @param array<string,string> $headers
 * @return array{0:int,1:array<string,string>,2:string}
 */
function handle_dump_headers(string $method, array $query, array $headers, string $body): array
{
    return [
        200,
        ['content-type' => 'application/json'],
        (string) json_encode($headers, JSON_UNESCAPED_SLASHES),
    ];
}

/**
 * /resources/delay?ms=NNN
 *
 * @param array<string,string> $query
 * @param array<string,string> $headers
 * @return array{0:int,1:array<string,string>,2:string}
 */
function handle_delay(string $method, array $query, array $headers, string $body): array
{
    $ms = (int) ($query['ms'] ?? 0);
    if ($ms > 0 && $ms < 30000) {
        usleep($ms * 1000);
    }
    return [200, ['content-type' => 'text/plain'], 'ok'];
}

/**
 * /resources/json?data=...
 *
 * @param array<string,string> $query
 * @param array<string,string> $headers
 * @return array{0:int,1:array<string,string>,2:string}
 */
function handle_json(string $method, array $query, array $headers, string $body): array
{
    $payload = $query['data'] ?? '{"ok":true}';
    return [200, ['content-type' => 'application/json'], $payload];
}

/**
 * /resources/content.py — echoes the request body verbatim.
 * XHR send-data-* fixtures POST a payload here and assert the
 * response body matches. The Content-Type echo lets the upload
 * fixtures verify the type they sent.
 *
 * @param array<string,string> $query
 * @param array<string,string> $headers
 * @return array{0:int,1:array<string,string>,2:string}
 */
function handle_content(string $method, array $query, array $headers, string $body): array
{
    $type = $headers['content-type'] ?? 'text/plain';
    return [200, ['content-type' => $type], $body];
}

/**
 * /resources/echo-content-type.py — body is the request's Content-Type.
 */
function handle_echo_content_type(string $method, array $query, array $headers, string $body): array
{
    $type = $headers['content-type'] ?? '';
    return [200, ['content-type' => 'text/plain'], $type];
}

/**
 * /resources/echo-headers.py — body is the request headers in
 * `Name: Value\r\n` form. Used by request-content-length.any.js
 * and other fixtures that string-match against the request shape.
 */
function handle_echo_headers(string $method, array $query, array $headers, string $body): array
{
    $out = '';
    foreach ($headers as $k => $v) {
        // Re-canonicalize: "content-type" → "Content-Type"
        $name = implode('-', array_map('ucfirst', explode('-', $k)));
        $out .= $name . ': ' . $v . "\r\n";
    }
    return [200, ['content-type' => 'text/plain'], $out];
}

/**
 * /resources/echo-method.py — body is the HTTP method.
 */
function handle_echo_method(string $method, array $query, array $headers, string $body): array
{
    return [200, ['content-type' => 'text/plain'], $method];
}

/**
 * /resources/headers.py — set arbitrary response headers from query.
 * `?Name1=Value1&Name2=Value2` → those headers come back on the
 * response. Used by setrequestheader / getresponseheader fixtures.
 */
function handle_headers(string $method, array $query, array $headers, string $body): array
{
    $resp = ['content-type' => 'text/plain'];
    foreach ($query as $k => $v) {
        if (is_string($k) && is_string($v)) {
            $resp[strtolower($k)] = $v;
        }
    }
    return [200, $resp, ''];
}

/**
 * /resources/requri.py — body is the resolved request URI. Used
 * by open-url-* fixtures that verify the URL the server actually
 * sees matches the URL the test passed to open().
 */
function handle_requri(string $method, array $query, array $headers, string $body): array
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    return [200, ['content-type' => 'text/plain'], $uri];
}

/**
 * /resources/last-modified.py — return a fixed Last-Modified header.
 * Used by conformance tests for header parsing.
 */
function handle_last_modified(string $method, array $query, array $headers, string $body): array
{
    return [
        200,
        [
            'content-type' => 'text/plain',
            'last-modified' => 'Wed, 21 Oct 2015 07:28:00 GMT',
        ],
        '',
    ];
}

/**
 * /resources/parse-headers.py — request headers as JSON object.
 */
function handle_parse_headers(string $method, array $query, array $headers, string $body): array
{
    return [
        200,
        ['content-type' => 'application/json'],
        (string) json_encode($headers),
    ];
}

/**
 * /resources/accept.py — echo the Accept header.
 */
function handle_accept(string $method, array $query, array $headers, string $body): array
{
    return [200, ['content-type' => 'text/plain'], $headers['accept'] ?? ''];
}

/**
 * /resources/trickle.py?ms=N&count=M — stream M small chunks with
 * a ms-second delay between them. With ms*count > some threshold,
 * any request with a built-in timeout will fire it; otherwise the
 * full body arrives. Used by sync-no-timeout and abort-after-*.
 */
function handle_trickle(string $method, array $query, array $headers, string $body): array
{
    $ms = (int) ($query['ms'] ?? 100);
    $count = (int) ($query['count'] ?? 5);
    // PHP built-in server has no real streaming; emulate by
    // sleeping the full duration once and returning a single body
    // of `count` chunks. Fixtures only assert on completion timing.
    $total = $ms * $count;
    if ($total > 0 && $total < 30000) {
        usleep($total * 1000);
    }
    return [
        200,
        ['content-type' => 'text/plain'],
        // Match upstream: "TEST_TRICKLE\n" per chunk (13 bytes each).
        str_repeat("TEST_TRICKLE\n", $count),
    ];
}

/**
 * /resources/authentication.py — challenges with WWW-Authenticate
 * unless the request carries an Authorization header that matches
 * `?username=...&password=...`. Used by access-control-auth-basic
 * and related fixtures.
 */
function handle_authentication(string $method, array $query, array $headers, string $body): array
{
    $user = $query['username'] ?? 'user';
    $pass = $query['password'] ?? 'password';
    $expected = 'Basic ' . base64_encode($user . ':' . $pass);
    $authz = $headers['authorization'] ?? '';
    if ($authz === $expected) {
        return [200, ['content-type' => 'text/plain'], 'authenticated as ' . $user];
    }
    return [
        401,
        [
            'content-type' => 'text/plain',
            'www-authenticate' => 'Basic realm="test"',
        ],
        'authentication required',
    ];
}

/**
 * /resources/conditional.py — supports If-Modified-Since by always
 * returning 304 when the header is present, 200 + body otherwise.
 * Sufficient for the conformance tests that exercise this header.
 */
function handle_conditional(string $method, array $query, array $headers, string $body): array
{
    if (isset($headers['if-modified-since']) || isset($headers['if-none-match'])) {
        return [304, [], ''];
    }
    return [
        200,
        [
            'content-type' => 'text/plain',
            'last-modified' => 'Wed, 21 Oct 2015 07:28:00 GMT',
            'etag' => '"abc123"',
        ],
        'conditional body',
    ];
}

/**
 * /resources/get-set-cookie.py — sets a cookie via Set-Cookie and
 * echoes the request Cookie header.
 */
function handle_get_set_cookie(string $method, array $query, array $headers, string $body): array
{
    return [
        200,
        [
            'content-type' => 'text/plain',
            'set-cookie' => ($query['name'] ?? 'session') . '=' . ($query['value'] ?? 'v'),
        ],
        $headers['cookie'] ?? '',
    ];
}

/**
 * /resources/form.py — parse the form body (urlencoded or multipart)
 * and return entries as `name:value;` for each field. Matches what
 * the upstream Python handler does. Used by send-data-formdata.
 */
function handle_form(string $method, array $query, array $headers, string $body): array
{
    $ct = $headers['content-type'] ?? '';
    $entries = [];
    if (str_starts_with($ct, 'multipart/form-data')) {
        if (preg_match('/boundary="?([^";\s]+)"?/', $ct, $m)) {
            $boundary = '--' . $m[1];
            $parts = explode($boundary, $body);
            foreach ($parts as $part) {
                $part = trim($part, "\r\n");
                if ($part === '' || $part === '--') {
                    continue;
                }
                $sep = strpos($part, "\r\n\r\n");
                if ($sep === false) {
                    continue;
                }
                $head = substr($part, 0, $sep);
                $value = substr($part, $sep + 4);
                $value = preg_replace("/\r\n$/", '', $value) ?? $value;
                if (preg_match('/name="([^"]+)"/', $head, $nm)) {
                    $entries[] = [$nm[1], $value];
                }
            }
        }
    } elseif (str_starts_with($ct, 'application/x-www-form-urlencoded')) {
        parse_str($body, $parsed);
        foreach ($parsed as $k => $v) {
            $entries[] = [(string) $k, (string) $v];
        }
    }
    $out = '';
    foreach ($entries as [$k, $v]) {
        $out .= $k . ':' . $v . ';';
    }
    return [200, ['content-type' => 'text/plain'], $out];
}

/**
 * /resources/dump-authorization-header.py — body is the request's
 * Authorization header value (empty if absent). Used by
 * xhr-authorization-redirect.
 */
function handle_dump_authorization_header(string $method, array $query, array $headers, string $body): array
{
    $resp = [
        'content-type' => 'text/html',
        'cache-control' => 'no-cache',
        'access-control-allow-headers' => 'Authorization',
    ];
    if (isset($headers['origin'])) {
        $resp['access-control-allow-origin'] = $headers['origin'];
        $resp['access-control-allow-credentials'] = 'true';
    } else {
        $resp['access-control-allow-origin'] = '*';
    }
    if (isset($headers['authorization'])) {
        return [200, $resp, $headers['authorization']];
    }
    return [200, $resp, 'none'];
}

// ---------------------------------------------------------------------
// Access-control / CORS handlers — these are the XHR-suite cousins of
// the fetch-suite ones. Each emits the headers the spec requires for
// the corresponding scenario plus a body that the fixture string-
// matches against (typically "PASS: Cross-domain access allowed.").
// ---------------------------------------------------------------------

function handle_access_control_basic_allow(string $method, array $query, array $headers, string $body): array
{
    $extra = '';
    if ($method !== 'GET' && $method !== 'HEAD' && $body !== '') {
        $extra = "\nPASS: " . $method . ' data received';
    }
    return [
        200,
        [
            'content-type' => 'text/plain',
            'access-control-allow-origin' => $headers['origin'] ?? '*',
            'access-control-allow-credentials' => 'true',
        ],
        'PASS: Cross-domain access allowed.' . $extra,
    ];
}

function handle_access_control_basic_allow_star(string $method, array $query, array $headers, string $body): array
{
    return [
        200,
        [
            'content-type' => 'text/plain',
            'access-control-allow-origin' => '*',
        ],
        'PASS: Cross-domain access allowed.',
    ];
}

function handle_access_control_basic_allow_async(string $method, array $query, array $headers, string $body): array
{
    return handle_access_control_basic_allow($method, $query, $headers, $body);
}

function handle_access_control_basic_allow_no_credentials(string $method, array $query, array $headers, string $body): array
{
    return [
        200,
        [
            'content-type' => 'text/plain',
            'access-control-allow-origin' => $headers['origin'] ?? '*',
        ],
        'PASS: Cross-domain access allowed.',
    ];
}

function handle_access_control_basic_allow_non_cors_safelisted_method(string $method, array $query, array $headers, string $body): array
{
    $allowMethods = $query['allow-methods'] ?? 'PUT,DELETE,PATCH';
    if ($method === 'OPTIONS') {
        return [
            200,
            [
                'access-control-allow-origin' => $headers['origin'] ?? '*',
                'access-control-allow-methods' => $allowMethods,
                'access-control-allow-credentials' => 'true',
            ],
            '',
        ];
    }
    return [
        200,
        [
            'content-type' => 'text/plain',
            'access-control-allow-origin' => $headers['origin'] ?? '*',
            'access-control-allow-credentials' => 'true',
        ],
        'PASS: Cross-domain access allowed.' . ($body !== '' ? "\nPASS: " . $method . ' data received' : ''),
    ];
}

function handle_access_control_basic_put_allow(string $method, array $query, array $headers, string $body): array
{
    return handle_access_control_basic_allow_non_cors_safelisted_method($method, $query, $headers, $body);
}

function handle_access_control_origin_header(string $method, array $query, array $headers, string $body): array
{
    $origin = $headers['origin'] ?? '';
    return [
        200,
        [
            'content-type' => 'text/plain',
            'access-control-allow-origin' => $origin !== '' ? $origin : '*',
        ],
        "PASS: Cross-domain access allowed.\nHTTP_ORIGIN: " . $origin,
    ];
}

function handle_access_control_basic_cors_safelisted_request_headers(string $method, array $query, array $headers, string $body): array
{
    return [
        200,
        [
            'content-type' => 'text/plain',
            'access-control-allow-origin' => $headers['origin'] ?? '*',
        ],
        'PASS',
    ];
}

function handle_access_control_basic_cors_safelisted_response_headers(string $method, array $query, array $headers, string $body): array
{
    return [
        200,
        [
            'content-type' => 'text/plain',
            'access-control-allow-origin' => $headers['origin'] ?? '*',
            'access-control-expose-headers' => 'X-Custom',
            'x-custom' => 'value',
        ],
        'body',
    ];
}

function handle_access_control_preflight_request_allow_headers_returns_star(string $method, array $query, array $headers, string $body): array
{
    if ($method === 'OPTIONS') {
        return [
            200,
            [
                'access-control-allow-origin' => $headers['origin'] ?? '*',
                'access-control-allow-headers' => '*',
            ],
            '',
        ];
    }
    return [
        200,
        [
            'content-type' => 'text/plain',
            'access-control-allow-origin' => $headers['origin'] ?? '*',
        ],
        'PASS',
    ];
}

function handle_access_control_preflight_request_header_returns_origin(string $method, array $query, array $headers, string $body): array
{
    if ($method === 'OPTIONS') {
        return [
            200,
            [
                'access-control-allow-origin' => $headers['origin'] ?? '*',
                'access-control-allow-headers' => 'x-custom',
            ],
            '',
        ];
    }
    return [
        200,
        [
            'content-type' => 'text/plain',
            'access-control-allow-origin' => $headers['origin'] ?? '*',
        ],
        $headers['origin'] ?? '',
    ];
}

// -----------------------------------------------------------------------
// Server dispatch.
// -----------------------------------------------------------------------

[$status, $respHeaders, $respBody] = dispatch();

http_response_code($status);
foreach ($respHeaders as $name => $value) {
    header($name . ': ' . $value);
}
echo $respBody;
