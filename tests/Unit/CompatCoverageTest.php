<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CompatCoverageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('COMPAT_REPORT_LIB_ONLY')) {
            define('COMPAT_REPORT_LIB_ONLY', true);
        }
        require_once dirname(__DIR__, 2) . '/bin/compat-report';
    }

    public function testCompletedSplitChildrenCoverExactlyTheExpectedFiles(): void
    {
        $left = $this->completeChunk(['a', 'b']);
        $right = $this->completeChunk(['c']);
        $parent = \createCompatChunk('language/example', ['a', 'b', 'c'], 0);
        $parent['status'] = 'split';
        $parent['children'] = [$left['id'], $right['id']];
        $state = $this->state([$parent, $left, $right]);

        $coverage = \reconcileCompatCoverage($state, ['language/example' => ['a', 'b', 'c']]);

        self::assertTrue($state['completed']);
        self::assertSame(['covered' => 3, 'missing' => 0], $coverage);
        self::assertCount(2, \compatLeafChunks($state));
    }

    public function testPartialGroupCannotHideAnUnreportedTest(): void
    {
        $state = $this->state([$this->completeChunk(['a'])]);

        $coverage = \reconcileCompatCoverage($state, ['language/example' => ['a', 'b', 'c']]);

        self::assertFalse($state['completed']);
        self::assertSame(['covered' => 1, 'missing' => 2], $coverage);
        $pending = array_values(array_filter($state['chunks'], static fn(array $chunk): bool => $chunk['status'] === 'pending'));
        self::assertSame(['b', 'c'], $pending[0]['files']);
    }

    public function testOverlappingManifestsAreRejected(): void
    {
        $state = $this->state([$this->completeChunk(['a', 'b']), $this->completeChunk(['b', 'c'])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Overlapping shard coverage');
        \reconcileCompatCoverage($state, ['language/example' => ['a', 'b', 'c']]);
    }

    public function testPassingCountMustMatchFullManifest(): void
    {
        $chunk = $this->completeChunk(['a', 'b']);
        $chunk['summary']['pass'] = 1;
        $state = $this->state([$chunk]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Result count does not match manifest');
        \reconcileCompatCoverage($state, ['language/example' => ['a', 'b']]);
    }

    public function testSamplesAloneCannotProveCoverage(): void
    {
        $chunk = $this->completeChunk(['a', 'b']);
        unset($chunk['files']);
        $state = $this->state([$chunk]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing or invalid full manifest');
        \reconcileCompatCoverage($state, ['language/example' => ['a', 'b']]);
    }

    public function testConflictingDuplicateArtifactsCannotDiscardAFailure(): void
    {
        $pass = $this->completeChunk(['/shard/test262/test/a.js']);
        $fail = $pass;
        $fail['summary']['pass'] = 0;
        $fail['summary']['fail'] = 1;
        $state = $this->state([]);
        $first = $this->state([$pass]);
        $first['rootDir'] = '/shard';
        $second = $this->state([$fail]);
        $second['rootDir'] = '/shard';
        \mergeCompatShardChunks($state, $first, '/merged');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate chunk ID across shard artifacts');
        \mergeCompatShardChunks($state, $second, '/merged');
    }

    public function testStaleRunIdentityIsRejectedBeforeMerging(): void
    {
        $state = $this->state([]);
        $shard = $this->state([$this->completeChunk(['a'])]);
        $shard['runIdentity'] = 'previous-runner-or-suite';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Shard run identity does not match');
        \mergeCompatShardChunks($state, $shard, '/merged');
    }

    public function testArtifactsWithoutRunIdentityCannotProveCurrentCoverage(): void
    {
        $state = $this->state([]);
        $shard = $this->state([$this->completeChunk(['a'])]);
        unset($shard['runIdentity']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Shard run identity does not match');
        \mergeCompatShardChunks($state, $shard, '/merged');
    }

    public function testMatchingRunIdentityAllowsDifferentShardSchedulingAndRoots(): void
    {
        $state = $this->state([]);
        $left = $this->state([$this->completeChunk(['/left/test262/test/a.js'])]);
        $left['rootDir'] = '/left';
        $left['options'] = ['chunkSize' => 5, 'match' => 'language', 'jobs' => 1];
        $right = $this->state([$this->completeChunk(['/right/test262/test/b.js'])]);
        $right['rootDir'] = '/right';
        $right['options'] = ['chunkSize' => 25, 'match' => 'other-filter', 'jobs' => 4];

        self::assertSame(1, \mergeCompatShardChunks($state, $left, '/merged'));
        self::assertSame(1, \mergeCompatShardChunks($state, $right, '/merged'));
        $coverage = \reconcileCompatCoverage($state, [
            'language/example' => ['/merged/test262/test/a.js', '/merged/test262/test/b.js'],
        ]);

        self::assertTrue($state['completed']);
        self::assertSame(['covered' => 2, 'missing' => 0], $coverage);
    }

    public function testRunIdentityAndCacheFingerprintTrackAllExecutedSources(): void
    {
        $root = sys_get_temp_dir() . '/phasis-compat-identity-' . bin2hex(random_bytes(8));
        $files = [
            'src/Engine.php',
            'src/BuiltIn/Intl/Data/numberingSystems.xml',
            'config/regex_property_strings.php',
            'config/runtime-data.json',
            'bin/compat-report',
            'bin/compat-merge-shards',
            'bin/test262',
            'tests/Oracle/Test262Runner.php',
            'tests/Oracle/AgentHost.php',
            'test262/harness/assert.js',
            'test262/test/example.js',
            'test262/test/dependency_FIXTURE.js',
        ];
        try {
            foreach ($files as $file) {
                $directory = dirname($root . '/' . $file);
                if (!is_dir($directory)) {
                    mkdir($directory, 0700, true);
                }
                file_put_contents($root . '/' . $file, 'original');
            }
            $identity = \buildCompatRunIdentity($root);
            $options = ['match' => null, 'chunkSize' => 25];
            $discovery = ['groups' => ['language/example' => [$root . '/test262/test/example.js']]];
            $fingerprint = \buildCompatFingerprint($root, $options, [], $discovery);
            foreach ($files as $file) {
                file_put_contents($root . '/' . $file, 'changed');
                self::assertNotSame($identity, \buildCompatRunIdentity($root), $file);
                self::assertNotSame($fingerprint, \buildCompatFingerprint($root, $options, [], $discovery), $file);
                file_put_contents($root . '/' . $file, 'original');
            }
            self::assertSame($identity, \buildCompatRunIdentity($root));
        } finally {
            \removeDirectory($root);
        }
    }

    public function testPassingResultArtifactsRetainTheCompleteManifest(): void
    {
        $directory = sys_get_temp_dir() . '/phasis-compat-manifest-' . bin2hex(random_bytes(8));
        $files = array_map(static fn(int $number): string => 'test-' . $number . '.js', range(1, 25));
        $chunk = $this->completeChunk($files);
        $state = $this->state([$chunk]);
        $summary = $chunk['summary'] + ['kind' => 'normal'];
        try {
            mkdir($directory . '/results', 0700, true);
            \finalizeCompatChunk($state, $chunk['id'], $summary, $directory);
            $result = json_decode(file_get_contents($directory . '/results/' . $chunk['id'] . '.json'), true);

            self::assertSame($files, $state['chunks'][$chunk['id']]['files']);
            self::assertSame($files, $result['files']);
        } finally {
            \removeDirectory($directory);
        }
    }

    public function testSnapshotExposesTheVerifiedRunIdentity(): void
    {
        $state = $this->state([]) + [
            'updatedAt' => '2026-01-01T00:00:00+00:00',
            'completed' => true,
            'stateDir' => '.compat-state',
            'fingerprint' => 'cache-fingerprint',
            'discovery' => ['groups' => []],
        ];
        $state['options'] += ['match' => null, 'jobs' => 1, 'timeoutSec' => 1];
        $snapshot = \buildCompatSnapshot(dirname(__DIR__, 2), $state, ['test262_skipped_features' => []], true);

        self::assertSame($state['runIdentity'], $snapshot['state']['runIdentity']);
    }

    /** @param list<string> $files
     * @return array<string, mixed>
     */
    private function completeChunk(array $files): array
    {
        $chunk = \createCompatChunk('language/example', $files, 1);
        $chunk['status'] = 'complete';
        $chunk['summary'] = ['pass' => count($files), 'fail' => 0, 'skip' => 0, 'blocked' => 0, 'total' => count($files)];
        return $chunk;
    }

    /** @param list<array<string, mixed>> $chunks
     * @return array<string, mixed>
     */
    private function state(array $chunks): array
    {
        return [
            'runIdentity' => 'current-run',
            'options' => ['chunkSize' => 25],
            'chunks' => array_column($chunks, null, 'id'),
        ];
    }
}
