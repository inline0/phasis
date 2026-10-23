<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use Phasis\Node\NodeOptions;
use PHPUnit\Framework\TestCase;

final class PortableModulesTest extends TestCase
{
    public function testPortableModulesMatchPinnedNodeOracle(): void
    {
        $this->assertOracle('portable', 'portableResult');
    }

    public function testCryptographicOperationsMatchPinnedNodeOracle(): void
    {
        $this->assertOracle('crypto', 'cryptoResult');
    }

    public function testPathNormalizationMatchesDeterministicNodeCorpus(): void
    {
        $this->assertOracle('path-normalization', 'pathNormalizationResult');
    }

    public function testOsUsesRealmEnvironmentAndProvidesEncodedUserInfo(): void
    {
        $engine = new Engine();
        $engine->enableNode(new NodeOptions(env: ['HOME' => '/configured/home', 'TMPDIR' => '/configured/tmp/']));
        self::assertSame('/configured/home:/configured/tmp', $engine->eval('require("os").homedir() + ":" + require("os").tmpdir()'));
        self::assertTrue($engine->eval(<<<'JS'
const os = require('os');
const plain = os.userInfo();
const encoded = os.userInfo({ encoding: 'buffer' });
Buffer.isBuffer(encoded.username) && encoded.username.toString() === plain.username &&
  encoded.homedir.toString() === plain.homedir && encoded.uid === plain.uid;
JS));
    }

    private function assertOracle(string $fixture, string $global): void
    {
        $expected = json_decode((string) file_get_contents(__DIR__ . '/fixtures/' . $fixture . '.json'), true, 512, JSON_THROW_ON_ERROR);
        $engine = new Engine(node: true);
        $engine->execFile(__DIR__ . '/fixtures/' . $fixture . '.cjs');
        $actual = json_decode((string) $engine->eval('JSON.stringify(' . $global . ')'), true, 512, JSON_THROW_ON_ERROR);
        if (isset($actual['async'])) {
            usort($actual['async'], static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        }
        self::assertSame($expected, $actual);
    }
}
