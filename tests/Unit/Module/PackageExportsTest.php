<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Module;

use Phasis\Exceptions\TypeError;
use Phasis\Module\ModuleOptions;
use Phasis\Module\ModuleResolver;
use PHPUnit\Framework\TestCase;

final class PackageExportsTest extends TestCase
{
    use TempProject;

    private function setUpPackage(): string
    {
        return $this->project([
            'main.mjs' => '',
            'node_modules/pkg/package.json' => json_encode([
                'name' => 'pkg',
                'main' => './legacy.js',
                'module' => './esm.js',
                'exports' => [
                    '.' => [
                        'phasis' => './phasis.js',
                        'import' => './esm.js',
                        'require' => './cjs.cjs',
                    ],
                    './feature' => ['production' => './feature.prod.js', 'default' => './feature.dev.js'],
                    './icons/*' => './dist/icons/*.js',
                    './internal/*' => null,
                    './package.json' => './package.json',
                ],
            ]),
            'node_modules/pkg/phasis.js' => '',
            'node_modules/pkg/esm.js' => '',
            'node_modules/pkg/cjs.cjs' => '',
            'node_modules/pkg/legacy.js' => '',
            'node_modules/pkg/feature.prod.js' => '',
            'node_modules/pkg/feature.dev.js' => '',
            'node_modules/pkg/dist/icons/star.js' => '',
            'node_modules/pkg/internal/secret.js' => '',
            'node_modules/old/package.json' => '{"name": "old", "module": "./m.js", "main": "./c.js"}',
            'node_modules/old/m.js' => '',
            'node_modules/old/c.js' => '',
        ]);
    }

    public function testConditionsInOrder(): void
    {
        $root = $this->setUpPackage();
        $resolver = new ModuleResolver(new ModuleOptions(), static fn (): bool => false);
        $from = $root . '/main.mjs';
        $this->assertSame($root . '/node_modules/pkg/phasis.js', $resolver->resolve('pkg', $from));
        $this->assertSame($root . '/node_modules/pkg/feature.prod.js', $resolver->resolve('pkg/feature', $from));
        $this->assertSame($root . '/node_modules/pkg/dist/icons/star.js', $resolver->resolve('pkg/icons/star', $from));
    }

    public function testRequireConditionWithoutPhasis(): void
    {
        $root = $this->setUpPackage();
        $resolver = new ModuleResolver(new ModuleOptions(conditions: ['module', 'production']), static fn (): bool => false);
        $this->assertSame($root . '/node_modules/pkg/cjs.cjs', $resolver->resolve('pkg', $root . '/main.mjs', 'require'));
        $this->assertSame($root . '/node_modules/pkg/esm.js', $resolver->resolve('pkg', $root . '/main.mjs', 'import'));
    }

    public function testModuleFieldWithoutExports(): void
    {
        $root = $this->setUpPackage();
        $resolver = new ModuleResolver(new ModuleOptions(), static fn (): bool => false);
        $this->assertSame($root . '/node_modules/old/m.js', $resolver->resolve('old', $root . '/main.mjs'));
    }

    public function testBlockedSubpathThrows(): void
    {
        $root = $this->setUpPackage();
        $resolver = new ModuleResolver(new ModuleOptions(), static fn (): bool => false);
        $this->expectException(TypeError::class);
        $resolver->resolve('pkg/internal/secret', $root . '/main.mjs');
    }
}
