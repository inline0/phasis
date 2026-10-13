<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Module;

use Phasis\Exceptions\TypeError;
use Phasis\Module\ModuleOptions;
use Phasis\Module\ModuleResolver;
use PHPUnit\Framework\TestCase;

final class ResolverTest extends TestCase
{
    use TempProject;

    private function resolver(string $root): ModuleResolver
    {
        return new ModuleResolver(new ModuleOptions(projectRoot: $root), static fn (string $s): bool => $s === 'react');
    }

    public function testProbesExtensionsAndIndexFiles(): void
    {
        $root = $this->project([
            'src/main.jsx' => '',
            'src/Button.jsx' => '',
            'src/util.js' => '',
            'src/Card/index.jsx' => '',
            'src/data.json' => '{}',
        ]);
        $resolver = $this->resolver($root);
        $from = $root . '/src/main.jsx';
        $this->assertSame($root . '/src/Button.jsx', $resolver->resolve('./Button', $from));
        $this->assertSame($root . '/src/util.js', $resolver->resolve('./util', $from));
        $this->assertSame($root . '/src/Card/index.jsx', $resolver->resolve('./Card', $from));
        $this->assertSame($root . '/src/data.json', $resolver->resolve('./data', $from));
    }

    public function testJsconfigAliasWithComments(): void
    {
        $root = $this->project([
            'jsconfig.json' => "{\n  // aliases\n  \"compilerOptions\": { \"baseUrl\": \".\", \"paths\": { \"@/*\": [\"./src/*\"] } },\n}",
            'src/components/Avatar.jsx' => '',
            'src/pages/Home.jsx' => '',
        ]);
        $resolver = $this->resolver($root);
        $this->assertSame($root . '/src/components/Avatar.jsx', $resolver->resolve('@/components/Avatar', $root . '/src/pages/Home.jsx'));
    }

    public function testBuiltinsAndMissingModules(): void
    {
        $root = $this->project(['main.mjs' => '']);
        $resolver = $this->resolver($root);
        $this->assertSame('builtin:react', $resolver->resolve('react', $root . '/main.mjs'));
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage("Cannot find module 'nope' imported from");
        $resolver->resolve('nope', $root . '/main.mjs');
    }

    public function testNodeBuiltinsAreRejected(): void
    {
        $root = $this->project(['main.mjs' => '']);
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage("Cannot load Node built-in module 'node:fs'");
        $this->resolver($root)->resolve('node:fs', $root . '/main.mjs');
    }

    public function testPackageImportsField(): void
    {
        $root = $this->project([
            'package.json' => '{"imports": {"#lib/*": "./lib/*.js", "#config": {"production": "./config.prod.js", "default": "./config.js"}}}',
            'lib/format.js' => '',
            'config.prod.js' => '',
            'config.js' => '',
            'main.mjs' => '',
        ]);
        $resolver = $this->resolver($root);
        $this->assertSame($root . '/lib/format.js', $resolver->resolve('#lib/format', $root . '/main.mjs'));
        $this->assertSame($root . '/config.prod.js', $resolver->resolve('#config', $root . '/main.mjs'));
    }
}
