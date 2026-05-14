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
    $body = file_get_contents('php://input') ?: '';
    $headers = collectRequestHeaders();

    // /resources/<handler>[?args]
    if (preg_match('#^/resources/([a-z0-9-]+)(?:\.py)?$#i', $path, $m)) {
        $handler = strtolower($m[1]);
        $fn = 'Phasis\\Wpt\\TestServer\\handle_' . str_replace('-', '_', $handler);
        if (function_exists($fn)) {
            return $fn($method, $query, $headers, $body);
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

// -----------------------------------------------------------------------
// Server dispatch.
// -----------------------------------------------------------------------

[$status, $respHeaders, $respBody] = dispatch();

http_response_code($status);
foreach ($respHeaders as $name => $value) {
    header($name . ': ' . $value);
}
echo $respBody;
