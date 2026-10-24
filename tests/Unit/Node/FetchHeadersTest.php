<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class FetchHeadersTest extends TestCase
{
    public function testNodeRequestsPreserveServerHeadersInEveryMode(): void
    {
        $engine = new Engine(node: true);
        self::assertSame(['a=b|application/json|yes', 'a=b|application/json|yes', 'a=b|application/json|yes'], $engine->eval(<<<'JS'
['cors', 'no-cors', 'same-origin'].map(mode => {
    const request = new Request('https://example.com', {
        mode, headers: { Cookie: 'a=b', 'Content-Type': 'application/json', 'X-Test': 'yes' }
    }).clone();
    return ['cookie', 'content-type', 'x-test'].map(name => request.headers.get(name)).join('|');
});
JS));
    }

    public function testRequestInitHeadersReplaceTheSourceHeaders(): void
    {
        $engine = new Engine();
        self::assertSame(['original', null, 'replacement', 'original', 'TypeError'], $engine->eval(<<<'JS'
const source = new Request('https://example.com', {headers: {'x-method-override': 'original'}});
const empty = new Request(source, {headers: new Headers()});
const changed = new Request(source, {headers: {'x-method-override': 'replacement'}});
const inherited = new Request(source, {headers: undefined});
let error;
try { new Request(source, {headers: null}); } catch (caught) { error = caught.name; }
[source.headers.get('x-method-override'), empty.headers.get('x-method-override'),
 changed.headers.get('x-method-override'), inherited.headers.get('x-method-override'), error];
JS));
    }

    public function testNodeResponsesKeepCookiesAndImmutableClones(): void
    {
        $engine = new Engine(node: true);
        self::assertSame([['a=b', 'c=d'], 'TypeError'], $engine->eval(<<<'JS'
const response = new Response(null, {headers: {'set-cookie': 'a=b'}});
response.headers.append('set-cookie', 'c=d');
const immutable = Response.error().clone();
let error;
try { immutable.headers.set('x-test', 'value'); } catch (caught) { error = caught.name; }
[response.clone().headers.getSetCookie(), error];
JS));
    }

    public function testEnablingNodeDoesNotAlterExistingBrowserHeaders(): void
    {
        $engine = new Engine();
        $engine->eval('globalThis.response = new Response();');
        $engine->enableNode();
        self::assertSame([[], [], ['server']], $engine->eval(<<<'JS'
response.headers.append('set-cookie', 'old');
const copy = response.clone();
copy.headers.append('set-cookie', 'clone');
const server = new Response();
server.headers.append('set-cookie', 'server');
[response.headers.getSetCookie(), copy.headers.getSetCookie(), server.headers.getSetCookie()];
JS));
    }
}
