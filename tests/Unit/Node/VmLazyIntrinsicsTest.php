<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class VmLazyIntrinsicsTest extends TestCase
{
    public function testDeferredIntrinsicRealizationPreservesNodeSandboxAndDescriptorSemantics(): void
    {
        $engine = new Engine(node: true);
        self::assertSame([
            ['Uint8Array'],
            [true, true, false, true, false],
            [2, false, true, 'function'],
            [true, true, true],
            ['function', 'undefined'],
            ['undefined', false],
        ], $engine->eval(<<<'JS'
const vm = require('vm');
const marker = function CustomUint8Array() {};
const sandbox = vm.createContext({ Uint8Array: marker });
const observations = [Object.keys(sandbox)];
observations.push(vm.runInContext(`(() => {
  const listed = Object.getOwnPropertyNames(globalThis).includes('Map');
  const descriptor = Object.getOwnPropertyDescriptor(globalThis, 'Map');
  return [listed, descriptor.writable, descriptor.enumerable, descriptor.configurable, 'get' in descriptor];
})()`, sandbox));
observations.push([
  vm.runInContext('new Map([[1, 2]]).get(1)', sandbox),
  Object.hasOwn(sandbox, 'Map'),
  sandbox.Uint8Array === marker,
  vm.runInContext('typeof Uint16Array', sandbox)
]);
const firstMap = vm.runInContext('Map', sandbox);
const secondMap = vm.runInNewContext('Map');
observations.push([firstMap !== Map, secondMap !== firstMap, Object.getPrototypeOf(new firstMap()) === firstMap.prototype]);
observations.push(vm.runInContext('delete DisposableStack; [typeof AsyncDisposableStack, typeof DisposableStack]', sandbox));
vm.runInContext('Map = 1; delete Map', sandbox);
observations.push([vm.runInContext('typeof Map', sandbox), Object.hasOwn(sandbox, 'Map')]);
observations;
JS));
    }

    public function testOutsideDescriptorAccessMaterializesTheDefiningRealm(): void
    {
        $engine = new Engine(node: true);
        self::assertSame([true, true, true, false, true], $engine->eval(<<<'JS'
const vm = require('vm');
const sandbox = vm.createContext({});
const foreign = vm.runInContext('globalThis', sandbox);
const descriptor = Object.getOwnPropertyDescriptor(foreign, 'Intl');
const foreignObject = vm.runInContext('Object', sandbox);
[
  descriptor.value !== Intl,
  Object.getPrototypeOf(descriptor.value) === foreignObject.prototype,
  descriptor.value === vm.runInContext('Intl', sandbox),
  Object.hasOwn(sandbox, 'Intl'),
  Object.getPrototypeOf({}) === Object.prototype
];
JS));
    }
}
