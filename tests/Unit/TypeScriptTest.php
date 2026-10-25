<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit;

use Phasis\Engine;
use Phasis\Ast\Serializer;
use Phasis\Parser\Parser;
use Phasis\Parser\Jsx\JsxMode;
use Phasis\Parser\Jsx\JsxOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TypeScriptTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function untypedJsxFixtures(): iterable
    {
        foreach (glob(__DIR__ . '/../Jsx/{react,html}/*.jsx', GLOB_BRACE) ?: [] as $file) {
            yield basename(dirname($file)) . '/' . basename($file) => [$file];
        }
    }

    #[DataProvider('untypedJsxFixtures')]
    public function testTypeScriptModePreservesExistingJavaScriptAndJsxAst(string $file): void
    {
        $source = (string) file_get_contents($file);
        $javascript = new Parser($source, $file);
        $typescript = new Parser($source, substr($file, 0, -4) . '.tsx');
        foreach ([$javascript, $typescript] as $parser) {
            $parser->setModuleMode(true);
            $parser->setJsxOptions(new JsxOptions(mode: JsxMode::Preserve));
        }
        self::assertSame(
            Serializer::toArray($javascript->parse()),
            Serializer::toArray($typescript->parse()),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        foreach (glob(__DIR__ . '/../TypeScript/fixtures/*.{ts,mts,cts}', GLOB_BRACE) ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    #[DataProvider('fixtures')]
    public function testAutomaticTypeScriptExecutionMatchesReference(string $file): void
    {
        $engine = new Engine();
        try {
            $engine->execFile($file);
            $expected = (string) file_get_contents($file . '.expected.txt');
            self::assertStringEndsWith("\n", $expected);
            // Console output stores lines without console.log's final delimiter.
            self::assertSame(substr($expected, 0, -1), $engine->getConsoleOutput());
        } finally {
            $engine->close();
        }
    }

    public function testNodeModeRunsTypedChildrenWithThePhpRuntime(): void
    {
        $engine = new Engine(node: true);
        try {
            $engine->setGlobal('typedChild', realpath(__DIR__ . '/../TypeScript/fixtures/module.mts'));
            self::assertSame("module\n", $engine->eval(<<<'JS'
require('child_process').execFileSync(process.execPath, [typedChild], {
  encoding: 'utf8', timeout: 10000,
});
JS));
        } finally {
            $engine->close();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function strictDirectives(): iterable
    {
        yield 'after type alias' => ["type X = number; 'use strict'; const legacy = 010;"];
        yield 'after ambient binding' => ["declare const X: number; 'use strict'; const legacy = 010;"];
        yield 'after assertion' => ["'first' as string; 'use strict'; const legacy = 010;"];
        yield 'inside function' => ["function f() { type X = number; 'use strict'; const legacy = 010; }"];
    }

    #[DataProvider('strictDirectives')]
    public function testErasureRetainsStrictDirectiveEarlyErrors(string $source): void
    {
        $this->expectException(\Phasis\Exceptions\SyntaxError::class);
        (new Parser($source, '/strict.cts'))->parse();
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedOrMalformed(): iterable
    {
        foreach (
            [
            'runtime enum' => 'enum Status { Ready, Done }',
            'const enum' => 'const enum Status { Ready }',
            'parameter property' => 'class User { constructor(public value: string) {} }',
            'readonly parameter property' => 'class User { constructor(readonly value: string) {} }',
            'runtime namespace' => 'namespace App { export const value = sideEffect(); }',
            'import assignment' => 'import fs = require("fs");',
            'export assignment' => 'export = { value: 1 };',
            'missing annotation' => 'const value: = sideEffect();',
            'missing generic close' => 'function f<T(value: T) { return value; }',
            'unclosed interface' => 'interface User { name: string',
            'unclosed type object' => 'type User = { name: string',
            'invalid type member' => 'type User = { name: string sideEffect() }',
            'abstract initializer' => 'abstract class A { abstract value = sideEffect(); }',
            'invalid ambient statement' => 'declare sideEffect();',
            'typed import in function' => 'function f() { import type X from "missing"; }',
            'typed export in function' => 'function f() { export type X = string; }',
            ] as $name => $source
        ) {
            yield $name => [$source];
        }
    }

    #[DataProvider('unsupportedOrMalformed')]
    public function testUnsupportedSyntaxFailsBeforeAnyExecution(string $source): void
    {
        $engine = new Engine();
        $effects = 0;
        $engine->setGlobal('sideEffect', static function () use (&$effects): int {
            return ++$effects;
        });
        try {
            $engine->evalAsModule('sideEffect(); ' . $source, '/invalid.ts');
            self::fail('Unsupported or malformed syntax was accepted.');
        } catch (\Phasis\Exceptions\SyntaxError) {
            self::assertSame(0, $effects);
        } finally {
            $engine->close();
        }
    }
}
