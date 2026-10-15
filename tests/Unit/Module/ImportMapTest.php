<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Module;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class ImportMapTest extends TestCase
{
    use TempProject;

    public function testExactPrefixAndStubEntries(): void
    {
        $root = $this->project([
            'main.mjs' => "import a from 'alias';\nimport b from 'lib/b.js';\nimport * as stub from 'heavy';\nimport react from 'react';\n"
                . "console.log(a, b, Object.keys(stub).length, react);",
            'vendor/a.js' => "export default 'mapped-a';",
            'vendor/lib/b.js' => "export default 'mapped-b';",
            'my-react.js' => "export default 'not-the-builtin';",
        ]);
        $engine = new Engine();
        $engine->setImportMap([
            'alias' => $root . '/vendor/a.js',
            'lib/' => $root . '/vendor/lib/',
            'heavy' => null,
            'react' => $root . '/my-react.js',
        ]);
        $engine->execFile($root . '/main.mjs');
        $this->assertSame('mapped-a mapped-b 0 not-the-builtin', $engine->getConsoleOutput());
    }

    public function testStylesAreEmptyAndAssetsNeedAResolver(): void
    {
        $root = $this->project([
            'main.mjs' => "import './site.css';\nimport logo from './logo.png';\nconsole.log(logo);",
            'site.css' => 'body { color: red }',
            'logo.png' => 'png',
        ]);
        $engine = new Engine();
        $engine->setAssetResolver(static fn (string $file): string => '/static/' . basename($file) . '?v=1');
        $engine->execFile($root . '/main.mjs');
        $this->assertSame('/static/logo.png?v=1', $engine->getConsoleOutput());

        $this->expectExceptionMessageMatches('/logo\.png/');
        (new Engine())->execFile($root . '/main.mjs');
    }
}
