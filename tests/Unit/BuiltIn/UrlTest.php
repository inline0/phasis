<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class UrlTest extends TestCase
{
    private function eval(string $js): mixed
    {
        $engine = new Engine();
        return $engine->eval($js);
    }

    public function testAbsoluteParse(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("https://example.com:8080/p/q?a=1&b=2#frag");
        [u.protocol, u.host, u.hostname, u.port, u.pathname, u.search, u.hash, u.origin];
        JS);
        $this->assertSame(
            ['https:', 'example.com:8080', 'example.com', '8080', '/p/q', '?a=1&b=2', '#frag', 'https://example.com:8080'],
            $result,
        );
    }

    public function testRelativeWithBase(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("/path?q=v#h", "https://example.com:8080");
        [u.href, u.host, u.hostname, u.port, u.pathname, u.search, u.hash, u.searchParams.get("q")];
        JS);
        $this->assertSame(
            [
                'https://example.com:8080/path?q=v#h',
                'example.com:8080',
                'example.com',
                '8080',
                '/path',
                '?q=v',
                '#h',
                'v',
            ],
            $result,
        );
    }

    public function testFileUrl(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("file:///tmp/foo.txt");
        [u.protocol, u.host, u.pathname, u.href];
        JS);
        $this->assertSame(['file:', '', '/tmp/foo.txt', 'file:///tmp/foo.txt'], $result);
    }

    public function testOpaqueDataUrl(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("data:text/plain;charset=utf-8,hello");
        [u.protocol, u.pathname, u.host, u.origin];
        JS);
        $this->assertSame(['data:', 'text/plain;charset=utf-8,hello', '', 'null'], $result);
    }

    public function testOpaqueJavascriptUrl(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("javascript:alert(1)");
        [u.protocol, u.pathname];
        JS);
        $this->assertSame(['javascript:', 'alert(1)'], $result);
    }

    public function testUserinfo(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("https://user:pass@example.com/path");
        [u.username, u.password, u.host, u.href];
        JS);
        $this->assertSame(['user', 'pass', 'example.com', 'https://user:pass@example.com/path'], $result);
    }

    public function testPortPreservation(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("https://example.com:9999/");
        [u.port, u.host];
        JS);
        $this->assertSame(['9999', 'example.com:9999'], $result);
    }

    public function testDefaultPortStripped(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("https://example.com:443/");
        [u.port, u.host];
        JS);
        $this->assertSame(['', 'example.com'], $result);
    }

    public function testIpv4Host(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("http://192.168.1.1/x");
        [u.hostname, u.href];
        JS);
        $this->assertSame(['192.168.1.1', 'http://192.168.1.1/x'], $result);
    }

    public function testIpv6Host(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("http://[::1]:8080/x");
        [u.hostname, u.port, u.host];
        JS);
        $this->assertSame(['[::1]', '8080', '[::1]:8080'], $result);
    }

    public function testPercentEncodedPath(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("https://x.com/a b/c#d e");
        [u.pathname, u.hash];
        JS);
        $this->assertSame(['/a%20b/c', '#d%20e'], $result);
    }

    public function testHrefSetter(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("http://a.com/");
        u.href = "https://b.com/x?q=1";
        [u.protocol, u.host, u.pathname, u.search];
        JS);
        $this->assertSame(['https:', 'b.com', '/x', '?q=1'], $result);
    }

    public function testProtocolSetterAdjustsDefaultPort(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("http://x.com:80/");
        u.protocol = "https";
        [u.protocol, u.port, u.host];
        JS);
        // http with port 80 was stripped at parse time, so after upgrade
        // the port stays empty.
        $this->assertSame(['https:', '', 'x.com'], $result);
    }

    public function testHostSetterReparses(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("https://a.com:80/");
        u.host = "b.com:9000";
        [u.host, u.hostname, u.port];
        JS);
        $this->assertSame(['b.com:9000', 'b.com', '9000'], $result);
    }

    public function testPathnameSetter(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("https://x.com/old");
        u.pathname = "/new/path";
        u.href;
        JS);
        $this->assertSame('https://x.com/new/path', $result);
    }

    public function testSearchSetterUpdatesLiveSearchParams(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("https://x.com/?a=1");
        const sp = u.searchParams;
        u.search = "?b=2&c=3";
        [sp.get("a"), sp.get("b"), sp.get("c"), u.href];
        JS);
        $this->assertSame([null, '2', '3', 'https://x.com/?b=2&c=3'], $result);
    }

    public function testHashSetter(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("https://x.com/");
        u.hash = "section";
        const r1 = u.hash;
        u.hash = "";
        [r1, u.hash, u.href];
        JS);
        $this->assertSame(['#section', '', 'https://x.com/'], $result);
    }

    public function testCanParseValid(): void
    {
        $result = $this->eval('URL.canParse("https://example.com/")');
        $this->assertTrue($result);
    }

    public function testCanParseInvalid(): void
    {
        $result = $this->eval(<<<'JS'
        [
            URL.canParse("http://"),
            URL.canParse(null),
            URL.canParse(undefined),
            URL.canParse("not a url"),
        ];
        JS);
        $this->assertSame([false, false, false, false], $result);
    }

    public function testParseStaticReturnsUrlOrNull(): void
    {
        $result = $this->eval(<<<'JS'
        const a = URL.parse("https://x.com/y");
        const b = URL.parse("bad input");
        [a !== null ? a.href : null, b];
        JS);
        $this->assertSame(['https://x.com/y', null], $result);
    }

    public function testToStringAndToJSONReturnHref(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("https://x.com/p?q=v#h");
        [String(u), u.toString(), u.toJSON()];
        JS);
        $this->assertSame(
            ['https://x.com/p?q=v#h', 'https://x.com/p?q=v#h', 'https://x.com/p?q=v#h'],
            $result,
        );
    }

    public function testLiveSearchParamsPropagation(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("https://x.com/?a=1");
        u.searchParams.set("b", "2");
        u.search;
        JS);
        $this->assertSame('?a=1&b=2', $result);
    }

    public function testSearchParamsSameInstance(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("https://x.com/?a=1");
        u.searchParams === u.searchParams;
        JS);
        $this->assertTrue($result);
    }

    public function testDotDotPathResolution(): void
    {
        $result = $this->eval(<<<'JS'
        const u = new URL("../../c", "https://x.com/a/b/d");
        u.href;
        JS);
        $this->assertSame('https://x.com/c', $result);
    }

    public function testNonAsciiHostnameRejected(): void
    {
        // IDN out of scope for v1 — non-ASCII hostnames in special schemes
        // raise TypeError.
        $result = $this->eval(<<<'JS'
        let threw = false;
        try { new URL("https://例.com/"); } catch (e) { threw = e instanceof TypeError; }
        threw;
        JS);
        $this->assertTrue($result);
    }
}
