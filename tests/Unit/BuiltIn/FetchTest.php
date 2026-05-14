<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\BuiltIn\Fetch\TransportException;
use Phasis\Engine;
use Phasis\Value\JsObject;
use PHPUnit\Framework\TestCase;

class FetchTest extends TestCase
{
    /**
     * Drive the JS test scenario through a canned transport so we can
     * exercise the fetch state machine without hitting the network.
     * `$response` is the transport-output shape consumed by fetch().
     *
     * @param array<string,mixed> $response
     */
    private function engineWithCannedTransport(array $response, ?\Closure $captureDescriptor = null): Engine
    {
        $engine = new Engine();
        $engine->setFetchTransport(function (array $desc, ?JsObject $signal) use ($response, $captureDescriptor) {
            unset($signal);
            if ($captureDescriptor !== null) {
                $captureDescriptor($desc);
            }
            return $response;
        });
        return $engine;
    }

    public function testFetchIsAGlobalFunction(): void
    {
        $engine = new Engine();
        $this->assertSame('function', $engine->eval('typeof fetch;'));
    }

    public function testFetchPromiseResolvesToResponseViaMockTransport(): void
    {
        $engine = $this->engineWithCannedTransport([
            'status' => 200,
            'statusText' => 'OK',
            'headers' => [['Content-Type', 'text/plain']],
            'body' => 'hello',
        ]);
        $engine->eval(<<<'JS'
        (async () => {
            const r = await fetch("http://example.test/x");
            globalThis.__status = r.status;
            globalThis.__ok = r.ok;
            globalThis.__statusText = r.statusText;
            globalThis.__ct = r.headers.get("content-type");
            globalThis.__body = await r.text();
        })().then(() => { globalThis.__done = true; },
                  e => { globalThis.__err = String(e); });
        JS);
        $this->assertSame(true, $engine->eval('globalThis.__done;'));
        $this->assertSame(200, $engine->eval('globalThis.__status;'));
        $this->assertSame(true, $engine->eval('globalThis.__ok;'));
        $this->assertSame('OK', $engine->eval('globalThis.__statusText;'));
        $this->assertSame('text/plain', $engine->eval('globalThis.__ct;'));
        $this->assertSame('hello', $engine->eval('globalThis.__body;'));
    }

    public function testFetchStatusPropagation404NotOk(): void
    {
        $engine = $this->engineWithCannedTransport([
            'status' => 404,
            'statusText' => 'Not Found',
            'headers' => [],
            'body' => 'missing',
        ]);
        $engine->eval(<<<'JS'
        fetch("http://example.test/x").then(r => {
            globalThis.__status = r.status;
            globalThis.__ok = r.ok;
        });
        JS);
        $this->assertSame(404, $engine->eval('globalThis.__status;'));
        $this->assertSame(false, $engine->eval('globalThis.__ok;'));
    }

    public function testFetchPolicyCanDenyByThrowing(): void
    {
        $engine = $this->engineWithCannedTransport([
            'status' => 200,
            'statusText' => 'OK',
            'headers' => [],
            'body' => 'unreachable',
        ]);
        $engine->setFetchPolicy(function (JsObject $req) {
            unset($req);
            throw new \RuntimeException('fetch denied by policy');
        });
        $engine->eval(<<<'JS'
        fetch("http://example.test/blocked").then(
            r => { globalThis.__ok = "should-not-resolve"; },
            e => { globalThis.__err = e && e.name + ":" + e.message; }
        );
        JS);
        $this->assertNull($engine->eval('globalThis.__ok;'));
        // PHP-thrown exceptions get wrapped into a TypeError.
        $this->assertStringContainsString('TypeError', (string) $engine->eval('globalThis.__err;'));
        $this->assertStringContainsString('fetch denied by policy', (string) $engine->eval('globalThis.__err;'));
    }

    public function testFetchPolicyCanRewriteRequest(): void
    {
        $captured = null;
        $engine = $this->engineWithCannedTransport(
            [
                'status' => 200,
                'statusText' => 'OK',
                'headers' => [],
                'body' => '',
            ],
            function (array $desc) use (&$captured) {
                $captured = $desc;
            },
        );
        // Rewrite: redirect every request to http://rewritten.test/y.
        $engine->setFetchPolicy(function (JsObject $req) use ($engine) {
            unset($req);
            return $engine->eval(<<<'JS'
            new Request("http://rewritten.test/y", { method: "PUT" });
            JS) === null
                ? null
                : null;
            // ^^ The PHP eval-helper returns null for JsObject results;
            //    we can't easily return a JsObject. Instead route via
            //    setFetchPolicy below in raw closure form so we keep the
            //    JsObject identity.
        });

        // Override with a hook that actually returns a JsObject:
        $engine->setFetchPolicy(function (JsObject $req) use ($engine) {
            unset($req);
            // Use the interpreter to construct a fresh Request from JS.
            $interp = $engine->getInterpreter();
            $globalEnv = $engine->getGlobalEnv();
            /** @var \Phasis\Value\JsFunction $ctor */
            $ctor = $globalEnv->get('Request');
            $req2 = $ctor->construct([
                new \Phasis\Value\JsString('http://rewritten.test/y'),
                $this->makeInitWithMethod($engine, 'PUT'),
            ]);
            return $req2;
        });

        $engine->eval(<<<'JS'
        fetch("http://example.test/x").then(
            r => { globalThis.__status = r.status; },
            e => { globalThis.__err = String(e); }
        );
        JS);
        $this->assertSame(200, $engine->eval('globalThis.__status;'));
        $this->assertIsArray($captured);
        $this->assertSame('http://rewritten.test/y', $captured['url']);
        $this->assertSame('PUT', $captured['method']);
    }

    private function makeInitWithMethod(Engine $engine, string $method): \Phasis\Value\JsObject
    {
        $obj = new \Phasis\Value\JsObject();
        $obj->defineOwnProperty(
            'method',
            \Phasis\Object\PropertyDescriptor::data(new \Phasis\Value\JsString($method), true, true, true),
        );
        unset($engine);
        return $obj;
    }

    public function testPreAbortedSignalRejectsImmediately(): void
    {
        $engine = $this->engineWithCannedTransport([
            'status' => 200,
            'statusText' => 'OK',
            'headers' => [],
            'body' => 'never-sent',
        ]);
        $engine->eval(<<<'JS'
        const c = new AbortController();
        c.abort();
        fetch("http://example.test/x", { signal: c.signal }).then(
            r => { globalThis.__ok = "should-not-resolve"; },
            e => {
                globalThis.__errName = e && e.name;
                globalThis.__errMsg = e && e.message;
            }
        );
        JS);
        $this->assertNull($engine->eval('globalThis.__ok;'));
        $this->assertSame('AbortError', $engine->eval('globalThis.__errName;'));
    }

    public function testNetworkErrorRejectsWithTypeError(): void
    {
        $engine = new Engine();
        $engine->setFetchTransport(function () {
            throw new TransportException('connection refused', 'network-error');
        });
        $engine->eval(<<<'JS'
        fetch("http://example.test/x").then(
            r => { globalThis.__ok = "should-not-resolve"; },
            e => { globalThis.__errName = e && e.name; globalThis.__errMsg = e && e.message; }
        );
        JS);
        $this->assertNull($engine->eval('globalThis.__ok;'));
        $this->assertSame('TypeError', $engine->eval('globalThis.__errName;'));
        $this->assertStringContainsString(
            'connection refused',
            (string) $engine->eval('globalThis.__errMsg;')
        );
    }

    public function testCookieJarInjectsCookieHeader(): void
    {
        $captured = null;
        $engine = $this->engineWithCannedTransport(
            [
                'status' => 200,
                'statusText' => 'OK',
                'headers' => [['Set-Cookie', 'jar=updated']],
                'body' => '',
            ],
            function (array $desc) use (&$captured) {
                $captured = $desc;
            },
        );
        // Minimal PHP-side jar.
        $jar = new class () {
            public string $captured = '';
            public string $lastSet = '';

            public function get(string $url): string
            {
                unset($url);
                return 'session=abc123';
            }

            public function set(string $url, string $header): void
            {
                $this->captured = $url;
                $this->lastSet = $header;
            }
        };
        $engine->setCookieJar($jar);

        $engine->eval(<<<'JS'
        fetch("http://example.test/path").then(r => { globalThis.__status = r.status; });
        JS);
        $this->assertSame(200, $engine->eval('globalThis.__status;'));
        $this->assertIsArray($captured);
        // The transport descriptor should contain a "Cookie: session=abc123" pair.
        $foundCookie = false;
        foreach ($captured['headers'] as $pair) {
            if (strtolower((string) $pair[0]) === 'cookie' && $pair[1] === 'session=abc123') {
                $foundCookie = true;
                break;
            }
        }
        $this->assertTrue($foundCookie, 'fetch() should inject Cookie header from jar');
        // The jar should have received the response Set-Cookie.
        $this->assertSame('jar=updated', $jar->lastSet);
        $this->assertSame('http://example.test/path', $jar->captured);
    }

    public function testDefaultUserAgentHeaderInjected(): void
    {
        $captured = null;
        $engine = $this->engineWithCannedTransport(
            [
                'status' => 200,
                'statusText' => 'OK',
                'headers' => [],
                'body' => '',
            ],
            function (array $desc) use (&$captured) {
                $captured = $desc;
            },
        );
        $engine->eval('fetch("http://example.test/x");');
        $this->assertIsArray($captured);
        $found = false;
        foreach ($captured['headers'] as $pair) {
            if (strtolower((string) $pair[0]) === 'user-agent') {
                $this->assertStringStartsWith('Phasis/', (string) $pair[1]);
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'fetch() should add a default User-Agent header');
    }

    /**
     * End-to-end round-trip against the bundled `tests/Wpt/fetch-server.php`
     * — verifies fetch can make a real HTTP request via the default
     * CurlTransport and that the response body parses correctly.
     */
    public function testEndToEndEchoContentRoundTrip(): void
    {
        if (!function_exists('proc_open') || !function_exists('curl_init')) {
            $this->markTestSkipped('proc_open / curl required for end-to-end fetch test');
        }
        $cwd = dirname(__DIR__, 3);
        $port = self::pickFreePort();
        $serverScript = $cwd . '/tests/Wpt/fetch-server.php';
        $cmd = ['php', '-S', "127.0.0.1:$port", '-t', $cwd . '/tests/Wpt', $serverScript];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($proc)) {
            $this->markTestSkipped('could not start local PHP test server');
        }
        try {
            // Give the built-in server a moment to bind.
            $ready = false;
            for ($i = 0; $i < 30; $i++) {
                $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if (is_resource($sock)) {
                    fclose($sock);
                    $ready = true;
                    break;
                }
                usleep(100_000);
            }
            if (!$ready) {
                $this->markTestSkipped('local PHP test server did not start listening');
            }

            $engine = new Engine();
            $engine->eval(<<<JS
            (async () => {
                const r = await fetch("http://127.0.0.1:$port/resources/echo-content", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ hello: "world" })
                });
                globalThis.__status = r.status;
                globalThis.__ok = r.ok;
                globalThis.__body = await r.text();
                globalThis.__contentType = r.headers.get("content-type");
            })().then(() => { globalThis.__done = true; },
                      e => { globalThis.__err = e && (e.name + ":" + e.message); });
            JS);
            $this->assertSame(true, $engine->eval('globalThis.__done;'), (string) $engine->eval('globalThis.__err;'));
            $this->assertSame(200, $engine->eval('globalThis.__status;'));
            $this->assertSame(true, $engine->eval('globalThis.__ok;'));
            $this->assertSame('{"hello":"world"}', $engine->eval('globalThis.__body;'));
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    private static function pickFreePort(): int
    {
        $sock = socket_create_listen(0);
        if ($sock === false) {
            return 8765;
        }
        socket_getsockname($sock, $addr, $port);
        socket_close($sock);
        return $port ?: 8765;
    }

    public function testTransportReceivesRequestBody(): void
    {
        $captured = null;
        $engine = $this->engineWithCannedTransport(
            [
                'status' => 200,
                'statusText' => 'OK',
                'headers' => [],
                'body' => '',
            ],
            function (array $desc) use (&$captured) {
                $captured = $desc;
            },
        );
        $engine->eval(<<<'JS'
        fetch("http://example.test/x", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ hello: "world" })
        });
        JS);
        $this->assertIsArray($captured);
        $this->assertSame('POST', $captured['method']);
        $this->assertSame('{"hello":"world"}', $captured['body']);
    }
}
