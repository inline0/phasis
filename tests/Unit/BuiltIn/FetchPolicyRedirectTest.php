<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use PHPUnit\Framework\TestCase;

final class FetchPolicyRedirectTest extends TestCase
{
    /** @var list<string> */
    private array $sent = [];

    private function engine(): Engine
    {
        $this->sent = [];
        $engine = new Engine();
        $engine->setFetchTransport(function (array $request): array {
            $this->sent[] = $request['method'] . ' ' . $request['url'] . ' ' . $request['body'];
            $path = parse_url($request['url'], PHP_URL_PATH);
            return match ($path) {
                '/to-denied' => ['status' => 302, 'headers' => [['location', 'https://denied.test/secret']], 'body' => ''],
                '/to-allowed' => ['status' => 307, 'headers' => [['location', '/final']], 'body' => ''],
                '/see-other' => ['status' => 303, 'headers' => [['location', 'https://allowed.test/after']], 'body' => ''],
                default => ['status' => 200, 'statusText' => 'OK', 'headers' => [], 'body' => 'reached ' . $path],
            };
        });
        $engine->setFetchPolicy(static function (JsObject $request): ?JsObject {
            $url = $request->get('url');
            $host = parse_url($url instanceof JsString ? $url->value : '', PHP_URL_HOST);
            if ($host !== 'allowed.test') {
                throw new \RuntimeException('host not allowed: ' . (is_string($host) ? $host : ''));
            }
            return null;
        });
        return $engine;
    }

    public function testFetchRejectsARedirectToADisallowedHost(): void
    {
        $engine = $this->engine();
        $engine->eval(<<<'JS'
        globalThis.result = [];
        fetch("https://allowed.test/to-denied").then(
            (response) => result.push("resolved " + response.url),
            (error) => result.push(error.name + ": " + error.message),
        );
        JS);
        self::assertSame(['TypeError: host not allowed: denied.test'], $engine->eval('result'));
        self::assertSame(['GET https://allowed.test/to-denied '], $this->sent);
    }

    public function testFetchRejectsTheRedirectLikeADirectRequest(): void
    {
        $engine = $this->engine();
        $engine->eval(<<<'JS'
        globalThis.result = [];
        const describe = (error) => error.constructor.name + ": " + error.message;
        fetch("https://denied.test/secret").catch((error) => result.push(describe(error)));
        fetch("https://allowed.test/to-denied").catch((error) => result.push(describe(error)));
        JS);
        $result = $engine->eval('result');
        self::assertIsArray($result);
        self::assertCount(2, $result);
        self::assertSame($result[0], $result[1]);
    }

    public function testFetchFollowsARedirectBetweenAllowedHosts(): void
    {
        $engine = $this->engine();
        $engine->eval(<<<'JS'
        globalThis.result = [];
        fetch("https://allowed.test/to-allowed", { method: "POST", body: "payload" }).then(
            (response) => response.text().then((text) => result.push(response.status, response.url, response.redirected, text)),
        );
        JS);
        self::assertSame([200, 'https://allowed.test/final', true, 'reached /final'], $engine->eval('result'));
        self::assertSame([
            'POST https://allowed.test/to-allowed payload',
            'POST https://allowed.test/final payload',
        ], $this->sent);
    }

    public function testThePolicySeesEveryRedirectHopWithItsMethod(): void
    {
        $engine = $this->engine();
        $seen = [];
        $engine->setFetchPolicy(static function (JsObject $request) use (&$seen): ?JsObject {
            $url = $request->get('url');
            $method = $request->get('method');
            $seen[] = ($method instanceof JsString ? $method->value : '') . ' ' . ($url instanceof JsString ? $url->value : '');
            return null;
        });
        $engine->eval('fetch("https://allowed.test/see-other", { method: "POST", body: "payload" });');
        self::assertSame([
            'POST https://allowed.test/see-other',
            'GET https://allowed.test/after',
        ], $seen);
        self::assertSame([
            'POST https://allowed.test/see-other payload',
            'GET https://allowed.test/after ',
        ], $this->sent);
    }

    public function testHeadersThePolicySetsOnARedirectHopAreSent(): void
    {
        $engine = $this->engine();
        $headers = [];
        $engine->setFetchTransport(function (array $request) use (&$headers): array {
            $headers[] = $request['headers'];
            if (parse_url($request['url'], PHP_URL_PATH) === '/to-allowed') {
                return ['status' => 302, 'headers' => [['location', '/final']], 'body' => ''];
            }
            return ['status' => 200, 'headers' => [], 'body' => ''];
        });
        $engine->setFetchPolicy(static function (JsObject $request): ?JsObject {
            $list = $request->get('headers');
            $set = $list instanceof JsObject ? $list->get('set') : null;
            $url = $request->get('url');
            if ($set instanceof JsFunction && $url instanceof JsString) {
                $set->call($list, [new JsString('x-hop'), new JsString((string) parse_url($url->value, PHP_URL_PATH))]);
            }
            return null;
        });
        $engine->eval('fetch("https://allowed.test/to-allowed");');
        self::assertCount(2, $headers);
        self::assertContains(['x-hop', '/to-allowed'], $headers[0]);
        self::assertContains(['x-hop', '/final'], $headers[1]);
    }

    public function testManualRedirectModeDoesNotFollowOrConsultThePolicyAgain(): void
    {
        $engine = $this->engine();
        $engine->eval(<<<'JS'
        globalThis.result = [];
        fetch("https://allowed.test/to-denied", { redirect: "manual" }).then(
            (response) => result.push(response.type, response.status),
            (error) => result.push(error.name),
        );
        JS);
        self::assertSame(['opaqueredirect', 302], $engine->eval('result'));
        self::assertSame(['GET https://allowed.test/to-denied '], $this->sent);
    }

    public function testXhrFailsOnARedirectToADisallowedHost(): void
    {
        $engine = $this->engine();
        $engine->eval(<<<'JS'
        globalThis.result = [];
        const xhr = new XMLHttpRequest();
        xhr.open("GET", "https://allowed.test/to-denied");
        xhr.onload = () => result.push("load " + xhr.status);
        xhr.onerror = () => result.push("error " + xhr.status);
        xhr.send();
        JS);
        self::assertSame(['error 0'], $engine->eval('result'));
        self::assertSame(['GET https://allowed.test/to-denied '], $this->sent);
    }

    public function testXhrRunsThePolicyBeforeTheFirstRequest(): void
    {
        $engine = $this->engine();
        $engine->eval(<<<'JS'
        globalThis.result = [];
        const xhr = new XMLHttpRequest();
        xhr.open("GET", "https://denied.test/secret", false);
        try { xhr.send(); result.push("sent " + xhr.status); } catch (error) { result.push(error.name); }
        JS);
        self::assertSame(['NetworkError'], $engine->eval('result'));
        self::assertSame([], $this->sent);
    }

    public function testXhrFollowsARedirectBetweenAllowedHosts(): void
    {
        $engine = $this->engine();
        $engine->eval(<<<'JS'
        globalThis.result = [];
        const xhr = new XMLHttpRequest();
        xhr.open("POST", "https://allowed.test/to-allowed");
        xhr.onload = () => result.push(xhr.status, xhr.responseURL, xhr.responseText);
        xhr.onerror = () => result.push("error");
        xhr.send("payload");
        JS);
        self::assertSame([200, 'https://allowed.test/final', 'reached /final'], $engine->eval('result'));
        self::assertSame([
            'POST https://allowed.test/to-allowed payload',
            'POST https://allowed.test/final payload',
        ], $this->sent);
    }

    public function testXhrStillLetsTheTransportFollowRedirectsWithoutAPolicy(): void
    {
        $engine = new Engine();
        $follow = [];
        $engine->setFetchTransport(static function (array $request) use (&$follow): array {
            $follow[] = $request['followRedirects'] ?? null;
            return ['status' => 200, 'headers' => [], 'body' => ''];
        });
        $engine->eval('const xhr = new XMLHttpRequest(); xhr.open("GET", "https://allowed.test/to-denied"); xhr.send();');
        self::assertSame([true], $follow);
    }
}
