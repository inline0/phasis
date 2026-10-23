<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use Phasis\Value\JsObject;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsWeakMap;
use PHPUnit\Framework\TestCase;

final class WeakMapLifetimeTest extends TestCase
{
    public function testValuesAndKeysCanCollectTogetherWhileTheMapRemainsAlive(): void
    {
        $map = new JsWeakMap();
        $key = new JsObject();
        $value = new JsObject();
        $value->set('key', $key);
        $map->weakMapSet($key, $value);
        $keyReference = \WeakReference::create($key);
        $valueReference = \WeakReference::create($value);
        unset($key, $value);
        gc_collect_cycles();
        self::assertNull($keyReference->get());
        self::assertNull($valueReference->get());
        self::assertInstanceOf(JsUndefined::class, $map->weakMapGet(new JsObject()));
    }

    public function testAReferencedKeyRetainsItsValueUntilDeleteOrCollectionOfTheMap(): void
    {
        foreach ([new JsObject(), new JsSymbol('key')] as $key) {
            $map = new JsWeakMap();
            $value = new JsObject();
            $valueReference = \WeakReference::create($value);
            $map->weakMapSet($key, $value);
            unset($value);
            gc_collect_cycles();
            self::assertSame($valueReference->get(), $map->weakMapGet($key));
            self::assertNotNull($valueReference->get());
            self::assertTrue($map->weakMapDelete($key));
            self::assertNull($valueReference->get());
            self::assertFalse($map->weakMapDelete($key));

            $value = new JsObject();
            $valueReference = \WeakReference::create($value);
            $mapReference = \WeakReference::create($map);
            $map->weakMapSet($key, $value);
            unset($map, $value);
            gc_collect_cycles();
            self::assertNull($mapReference->get());
            self::assertNull($valueReference->get());
        }
    }

    public function testIndependentCollectionsAndReplacementPreserveNodeSemantics(): void
    {
        $engine = new Engine();
        self::assertSame([true, false, true, 9, 12, true, false, true, false, true], $engine->eval(<<<'JS'
const key = Object.freeze({}), symbol = Symbol('key');
const first = new WeakMap(), second = new WeakMap();
first.set(key, undefined).set(symbol, 7);
second.set(key, 12);
const observations = [first.has(key), first.get(key) !== undefined, second.has(key)];
first.set(key, 9);
observations.push(first.get(key), second.get(key), first.delete(symbol), first.has(symbol));
let invalid = false;
try { first.set(Symbol.for('registered'), 1); } catch (error) { invalid = error instanceof TypeError; }
observations.push(invalid, first.has(Symbol.for('registered')), first.get({}) === undefined);
observations;
JS));
    }

    public function testAnExternallyReferencedKeyDoesNotKeepAValueToMapCycleAlive(): void
    {
        $key = new JsObject();
        $map = new JsWeakMap();
        $value = new JsObject();
        $value->set('map', $map);
        $map->weakMapSet($key, $value);
        $mapReference = \WeakReference::create($map);
        $valueReference = \WeakReference::create($value);
        unset($map, $value);
        gc_collect_cycles();
        self::assertNull($mapReference->get());
        self::assertNull($valueReference->get());
    }
}
