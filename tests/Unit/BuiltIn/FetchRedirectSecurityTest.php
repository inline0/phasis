<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\BuiltIn\Fetch\CurlTransport;
use Phasis\BuiltIn\Fetch\TransportException;
use Phasis\Engine;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use PHPUnit\Framework\TestCase;

final class FetchRedirectSecurityTest extends TestCase
{
    /** @var list<array{url:string,headers:array<string,string>}> */
    private array $sent = [];

    /**
     * @param array<string, array{0:int,1:string}> $redirects
     */
    private function engine(array $redirects): Engine
    {
        $this->sent = [];
        $engine = new Engine();
        $engine->setFetchTransport(function (array $request) use ($redirects): array {
            $headers = [];
            foreach ($request['headers'] as [$name, $value]) {
                $headers[strtolower($name)] = $value;
            }
            $this->sent[] = ['url' => $request['url'], 'headers' => $headers];
            if (isset($redirects[$request['url']])) {
                [$status, $location] = $redirects[$request['url']];
                return ['status' => $status, 'headers' => [['location', $location]], 'body' => ''];
            }
            return ['status' => 200, 'headers' => [], 'body' => 'ok'];
        });
        return $engine;
    }

    private static function tokenPolicy(): \Closure
    {
        return static function (JsObject $request): ?JsObject {
            $url = $request->get('url');
            $url = $url instanceof JsString ? $url->value : '';
            if (parse_url($url, PHP_URL_HOST) === 'api.example.com') {
                $headers = $request->get('headers');
                $set = $headers instanceof JsObject ? $headers->get('set') : null;
                if ($set instanceof JsFunction) {
                    $set->call($headers, [new JsString('authorization'), new JsString('Bearer TOKEN')]);
                }
            }
            return null;
        };
    }

    public function testFetchDropsCredentialHeadersOnACrossOriginRedirect(): void
    {
        $engine = $this->engine(['https://api.example.com/go' => [302, 'https://evil.example.net/x']]);
        $engine->eval(<<<'JS'
        fetch("https://api.example.com/go", { headers: {
            authorization: "Bearer secret",
            "proxy-authorization": "Basic proxy",
            cookie: "sid=1",
            "x-trace": "1",
        } });
        JS);
        self::assertCount(2, $this->sent);
        self::assertSame('Bearer secret', $this->sent[0]['headers']['authorization']);
        self::assertSame('https://evil.example.net/x', $this->sent[1]['url']);
        self::assertArrayNotHasKey('authorization', $this->sent[1]['headers']);
        self::assertArrayNotHasKey('proxy-authorization', $this->sent[1]['headers']);
        self::assertArrayNotHasKey('cookie', $this->sent[1]['headers']);
        self::assertSame('1', $this->sent[1]['headers']['x-trace']);
    }

    public function testFetchKeepsCredentialHeadersOnASameOriginRedirect(): void
    {
        $engine = $this->engine(['https://api.example.com/go' => [302, '/next']]);
        $engine->eval('fetch("https://api.example.com/go", { headers: { authorization: "Bearer secret" } });');
        self::assertSame('https://api.example.com/next', $this->sent[1]['url']);
        self::assertSame('Bearer secret', $this->sent[1]['headers']['authorization']);
    }

    public function testFetchTreatsASchemeChangeAsAnOriginChange(): void
    {
        $engine = $this->engine(['https://api.example.com/go' => [302, 'http://api.example.com/plain']]);
        $engine->eval('fetch("https://api.example.com/go", { headers: { authorization: "Bearer secret" } });');
        self::assertSame('http://api.example.com/plain', $this->sent[1]['url']);
        self::assertArrayNotHasKey('authorization', $this->sent[1]['headers']);
    }

    public function testStrippedCredentialsStayStrippedWhenTheChainReturns(): void
    {
        $engine = $this->engine([
            'https://api.example.com/go' => [302, 'https://cdn.example.com/bounce'],
            'https://cdn.example.com/bounce' => [302, 'https://api.example.com/back'],
        ]);
        $engine->eval('fetch("https://api.example.com/go", { headers: { authorization: "Bearer secret" } });');
        self::assertCount(3, $this->sent);
        self::assertSame('https://api.example.com/back', $this->sent[2]['url']);
        self::assertArrayNotHasKey('authorization', $this->sent[2]['headers']);
    }

    public function testAPolicyTokenForOneHostIsNotCarriedToTheRedirectTarget(): void
    {
        $engine = $this->engine(['https://api.example.com/go' => [302, 'https://evil.example.net/x']]);
        $engine->setFetchPolicy(self::tokenPolicy());
        $engine->eval('fetch("https://api.example.com/go");');
        self::assertSame('Bearer TOKEN', $this->sent[0]['headers']['authorization']);
        self::assertSame('https://evil.example.net/x', $this->sent[1]['url']);
        self::assertArrayNotHasKey('authorization', $this->sent[1]['headers']);
    }

    public function testFetchResolvesABackslashLocationWithTheUrlParser(): void
    {
        $engine = $this->engine(['https://api.example.com/bs' => [302, 'https://evil.example.net\\@api.example.com/x']]);
        $engine->eval('fetch("https://api.example.com/bs", { headers: { authorization: "Bearer secret" } });');
        self::assertSame('https://evil.example.net/@api.example.com/x', $this->sent[1]['url']);
        self::assertArrayNotHasKey('authorization', $this->sent[1]['headers']);
    }

    public function testFetchDropsBodyHeadersWhenARedirectSwitchesToGet(): void
    {
        $engine = $this->engine(['https://api.example.com/post' => [303, '/after']]);
        $engine->eval('fetch("https://api.example.com/post", { method: "POST", body: "payload" });');
        self::assertSame('text/plain;charset=UTF-8', $this->sent[0]['headers']['content-type']);
        self::assertArrayNotHasKey('content-type', $this->sent[1]['headers']);
    }

    public function testFetchRejectsARedirectToANonHttpScheme(): void
    {
        foreach (['file:///etc/hosts', 'ftp://example.com/file', 'data:text/plain,hi', 'blob:phasis//x'] as $location) {
            $engine = $this->engine(['https://api.example.com/go' => [302, $location]]);
            $engine->eval(<<<'JS'
            globalThis.result = [];
            fetch("https://api.example.com/go").then(
                (response) => result.push("resolved " + response.url),
                (error) => result.push(error.name + ": " + error.message),
            );
            JS);
            $result = $engine->eval('result');
            self::assertIsArray($result);
            self::assertStringStartsWith('TypeError: fetch failed: redirect to unsupported URL scheme', (string) $result[0], $location);
            self::assertCount(1, $this->sent, $location);
        }
    }

    public function testFetchRejectsANonHttpUrlBeforeTheTransport(): void
    {
        $engine = $this->engine([]);
        $engine->eval(<<<'JS'
        globalThis.result = [];
        fetch("file:///etc/hosts").catch((error) => result.push(error.name + ": " + error.message));
        JS);
        self::assertSame(['TypeError: fetch failed: unsupported URL scheme "file:"'], $engine->eval('result'));
        self::assertSame([], $this->sent);
    }

    public function testThePolicyCannotRedirectAHopToANonHttpScheme(): void
    {
        $engine = $this->engine(['https://api.example.com/go' => [302, '/next']]);
        $engine->setFetchPolicy(static function (JsObject $request): ?JsObject {
            $url = $request->get('url');
            if ($url instanceof JsString && str_ends_with($url->value, '/next')) {
                $ctor = $request->get('constructor');
                $replacement = $ctor instanceof JsFunction ? $ctor->construct([new JsString('file:///etc/hosts')]) : null;
                return $replacement instanceof JsObject ? $replacement : null;
            }
            return null;
        });
        $engine->eval('globalThis.result = []; fetch("https://api.example.com/go").catch((error) => result.push(error.message));');
        self::assertSame(['fetch failed: redirect to unsupported URL scheme "file:"'], $engine->eval('result'));
        self::assertCount(1, $this->sent);
    }

    public function testBlobUrlsStillFetchOnTheFirstRequest(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
        globalThis.result = [];
        fetch(URL.createObjectURL(new Blob(["from memory"]))).then((response) => response.text()).then((text) => result.push(text));
        JS);
        self::assertSame(['from memory'], $engine->eval('result'));
    }

    public function testCurlTransportRefusesFileUrls(): void
    {
        $this->expectException(TransportException::class);
        CurlTransport::send(['method' => 'GET', 'url' => 'file:///etc/hosts', 'headers' => []]);
    }

    public function testCurlTransportLimitsProtocolsToHttpAndHttps(): void
    {
        $options = CurlTransport::protocolOptions();
        self::assertCount(2, $options);
        foreach ($options as $value) {
            self::assertContains($value, ['http,https', CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        }
    }

    public function testXhrComparesOriginsWithTheUrlParserOnABackslashLocation(): void
    {
        $engine = $this->engine(['https://api.example.com/bs' => [302, 'https://evil.example.net\\@api.example.com/x']]);
        $engine->setFetchPolicy(self::tokenPolicy());
        $engine->eval(<<<'JS'
        const xhr = new XMLHttpRequest();
        xhr.open("GET", "https://api.example.com/bs");
        xhr.setRequestHeader("cookie", "sid=1");
        xhr.send();
        JS);
        self::assertCount(2, $this->sent);
        self::assertSame('Bearer TOKEN', $this->sent[0]['headers']['authorization']);
        self::assertSame('https://evil.example.net/@api.example.com/x', $this->sent[1]['url']);
        self::assertArrayNotHasKey('authorization', $this->sent[1]['headers']);
        self::assertArrayNotHasKey('cookie', $this->sent[1]['headers']);
    }

    public function testXhrFailsARedirectToANonHttpScheme(): void
    {
        $engine = $this->engine(['https://api.example.com/go' => [302, 'file:///etc/hosts']]);
        $engine->setFetchPolicy(static fn (JsObject $request): ?JsObject => null);
        $engine->eval(<<<'JS'
        globalThis.result = [];
        const xhr = new XMLHttpRequest();
        xhr.open("GET", "https://api.example.com/go");
        xhr.onload = () => result.push("load " + xhr.status);
        xhr.onerror = () => result.push("error " + xhr.status);
        xhr.send();
        JS);
        self::assertSame(['error 0'], $engine->eval('result'));
        self::assertCount(1, $this->sent);
    }

    public function testXhrRejectsANonHttpUrlBeforeTheTransport(): void
    {
        $engine = $this->engine([]);
        $engine->eval(<<<'JS'
        globalThis.result = [];
        const xhr = new XMLHttpRequest();
        xhr.open("GET", "file:///etc/hosts", false);
        try { xhr.send(); result.push("sent " + xhr.status); } catch (error) { result.push(error.name); }
        JS);
        self::assertSame(['NetworkError'], $engine->eval('result'));
        self::assertSame([], $this->sent);
    }

    /**
     * @param list<string> $connected
     */
    private function webSocketEngine(array &$connected): Engine
    {
        $engine = new Engine();
        $engine->setWebSocketTransport(static function (string $url, array $protocols, callable $emit) use (&$connected): array {
            $connected[] = $url;
            return ['send' => static function (): void {
            }, 'close' => static function (): void {
            }];
        });
        return $engine;
    }

    public function testADenyingPolicyStopsTheWebSocketHandshake(): void
    {
        $connected = [];
        $engine = $this->webSocketEngine($connected);
        $engine->setFetchPolicy(static function (JsObject $request): ?JsObject {
            throw new \RuntimeException('network access is disabled');
        });
        $engine->eval(<<<'JS'
        globalThis.result = [];
        const ws = new WebSocket("wss://chat.example.com/socket");
        ws.onerror = () => result.push("error");
        ws.onclose = (event) => result.push("close " + event.code + " " + ws.readyState);
        JS);
        self::assertSame(['error', 'close 1006 3'], $engine->eval('result'));
        self::assertSame([], $connected);
    }

    public function testThePolicySeesTheWebSocketHandshakeAsAnHttpRequest(): void
    {
        $connected = [];
        $seen = [];
        $engine = $this->webSocketEngine($connected);
        $engine->setFetchPolicy(static function (JsObject $request) use (&$seen): ?JsObject {
            $url = $request->get('url');
            $method = $request->get('method');
            $seen[] = ($method instanceof JsString ? $method->value : '') . ' ' . ($url instanceof JsString ? $url->value : '');
            return null;
        });
        $engine->eval('new WebSocket("wss://chat.example.com/socket?room=1"); new WebSocket("ws://chat.example.com/plain");');
        self::assertSame(['GET https://chat.example.com/socket?room=1', 'GET http://chat.example.com/plain'], $seen);
        self::assertSame(['wss://chat.example.com/socket?room=1', 'ws://chat.example.com/plain'], $connected);
    }

    public function testThePolicyCanRewriteTheWebSocketUrl(): void
    {
        $connected = [];
        $engine = $this->webSocketEngine($connected);
        $engine->setFetchPolicy(static function (JsObject $request): ?JsObject {
            $ctor = $request->get('constructor');
            $replacement = $ctor instanceof JsFunction ? $ctor->construct([new JsString('https://relay.example.com/socket')]) : null;
            return $replacement instanceof JsObject ? $replacement : null;
        });
        $engine->eval('globalThis.socket = new WebSocket("wss://chat.example.com/socket");');
        self::assertSame(['wss://relay.example.com/socket'], $connected);
        self::assertSame('wss://chat.example.com/socket', $engine->eval('socket.url'));
    }
}
