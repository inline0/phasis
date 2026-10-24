<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$owner = new Phasis\Engine(node: true);
$sibling = null;
try {
    $owner->eval(<<<'JS'
const ByteConstructor = Uint8Array;
const BufferConstructor = ArrayBuffer;
const bytePrototype = ByteConstructor.prototype;
const bufferPrototype = BufferConstructor.prototype;
let constructorReads = 0;
JS);
    $sibling = new Phasis\Engine(node: true);
    $sibling->eval('new Uint8Array(1);');
    echo json_encode($owner->eval(<<<'JS'
for (const name of ['Uint8Array', 'ArrayBuffer']) {
  Object.defineProperty(globalThis, name, {configurable: true, get() {
    constructorReads++;
    throw new Error('Host bytes must use their intrinsic prototypes');
  }});
}
const bytes = __phasisNodeHost.stdioRead(0);
[Object.getPrototypeOf(bytes) === bytePrototype,
 Object.getPrototypeOf(bytes.buffer) === bufferPrototype,
 BufferConstructor.isView(bytes), bytes instanceof ByteConstructor,
 Array.from(bytes), constructorReads];
JS), JSON_THROW_ON_ERROR), "\n";
} finally {
    $owner->getNodeRuntime()?->close();
    $sibling?->getNodeRuntime()?->close();
}
