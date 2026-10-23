<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class FunctionProxyCallTest extends TestCase
{
    public function testCallAndApplyInvokeCallableProxyTraps(): void
    {
        $engine = new Engine();
        self::assertSame(
            ['receiver:call', 'receiver:dense', 'receiver:sparse', 'receiver:', 'receiver:'],
            $engine->eval(<<<'JS'
const receiver = { name: 'receiver' };
const callable = new Proxy(function () {}, {
    apply(target, value, args) { return value.name + ':' + args.join(','); }
});
[
    callable.call(receiver, 'call'),
    callable.apply(receiver, ['dense']),
    callable.apply(receiver, { 0: 'sparse', length: 1 }),
    callable.apply(receiver, []),
    callable.apply(receiver, null),
];
JS),
        );
    }

    public function testRevokedCallableProxyThrowsAfterApplyReadsItsArguments(): void
    {
        $engine = new Engine();
        self::assertSame(['length', 'TypeError', 'TypeError'], $engine->eval(<<<'JS'
const {proxy, revoke} = Proxy.revocable(function () {}, {});
const log = [];
revoke();
try {
    Function.prototype.apply.call(proxy, null, {get length() { log.push('length'); return 0; }});
} catch (error) { log.push(error.name); }
try { Function.prototype.call.call(proxy, null); } catch (error) { log.push(error.name); }
log;
JS));
    }
}
