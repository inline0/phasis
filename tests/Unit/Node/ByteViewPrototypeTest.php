<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ByteViewPrototypeTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function globalModes(): iterable
    {
        yield 'ordinary globals' => [false];
        yield 'replaced globals' => [true];
    }

    public function testNormalNodeInstallationHasByteArrayIntrinsicsReady(): void
    {
        $engine = new Engine(node: true);
        try {
            $runtime = $engine->getNodeRuntime();
            self::assertNotNull($runtime);
            $bytes = $runtime->byteArray("\x00\xffa");
            self::assertSame($engine->getGlobalEnv()->getIntrinsic('Uint8Array.prototype'), $bytes->getPrototype());
            self::assertSame($engine->getGlobalEnv()->getIntrinsic('ArrayBuffer.prototype'), $bytes->getBuffer()->getPrototype());
            self::assertSame('00ff61', bin2hex($bytes->getBuffer()->getData()));
        } finally {
            $engine->getNodeRuntime()?->close();
        }
    }

    #[DataProvider('globalModes')]
    public function testSocketBytesUseTheOwningRealmForTheViewAndBuffer(bool $replaceGlobals): void
    {
        $owner = new Engine(node: true);
        $sibling = null;
        try {
            $owner->eval(<<<'JS'
const ByteConstructor = Uint8Array;
const BufferConstructor = ArrayBuffer;
const bytePrototype = ByteConstructor.prototype;
const bufferPrototype = BufferConstructor.prototype;
const payload = new ByteConstructor([0, 255, 97]);
let constructorReads = 0;
const observations = [];
JS);
            $sibling = new Engine(node: true);
            $sibling->eval('new Uint8Array(1);');
            $owner->setExecutionBudget(3);
            if ($replaceGlobals) {
                $owner->eval(<<<'JS'
for (const name of ['Uint8Array', 'ArrayBuffer']) {
  Object.defineProperty(globalThis, name, {configurable: true, get() {
    constructorReads++;
    throw new Error('Host bytes must use their intrinsic prototypes');
  }});
}
JS);
            }
            $owner->eval(<<<'JS'
const host = __phasisNodeHost.net;
let listener, client, accepted;
listener = host.call('listen', {host: '127.0.0.1', port: 0}, (event, value) => {
  if (event !== 'connection') return;
  accepted = value;
  host.call('callback', accepted, (kind, bytes) => {
    if (kind !== 'data') return;
    observations.push(Object.getPrototypeOf(bytes) === bytePrototype,
      Object.getPrototypeOf(bytes.buffer) === bufferPrototype,
      BufferConstructor.isView(bytes), bytes instanceof ByteConstructor,
      Array.from(bytes), constructorReads);
    host.call('close', accepted);
    host.call('close', client.value);
    host.call('close', listener.value);
  });
});
const address = host.call('address', listener.value).value;
client = host.call('connect', {host: '127.0.0.1', port: address.port}, event => {
  if (event === 'connect') host.call('write', client.value, payload, () => {});
});
JS);
            self::assertSame([true, true, true, true, [0, 255, 97], 0], $owner->eval('observations'));
        } finally {
            $owner->getNodeRuntime()?->close();
            $sibling?->getNodeRuntime()?->close();
        }
    }

    public function testStdinBytesUseTheOwningRealmAfterSiblingAndGlobalChanges(): void
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/byte-view-stdio.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            ['XDEBUG_MODE' => 'off'],
        );
        self::assertIsResource($process);
        $output = $errors = '';
        try {
            fwrite($pipes[0], "\x00\xffa");
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $deadline = microtime(true) + 5;
            do {
                $output .= stream_get_contents($pipes[1]);
                $errors .= stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    self::fail('The bounded stdin prototype probe did not finish.');
                }
                usleep(1000);
            } while (true);
            $output .= stream_get_contents($pipes[1]);
            $errors .= stream_get_contents($pipes[2]);
            self::assertSame(0, $status['exitcode'], $errors);
            self::assertSame('', $errors);
            self::assertSame([true, true, true, true, [0, 255, 97], 0], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($process);
            proc_close($process);
        }
    }
}
