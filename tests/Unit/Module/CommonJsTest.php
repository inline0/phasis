<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Module;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class CommonJsTest extends TestCase
{
    use TempProject;

    public function testModuleExportsAndInterop(): void
    {
        $code = "import plain from 'plain';\nimport flagged, { named } from 'flagged';\nimport data from './data.json';\n"
            . "console.log(plain.kind, typeof plain.fn, typeof flagged === 'string' ? flagged : 'module.exports', named, data.answer);";
        $root = $this->project([
            // App code gets bundler interop (__esModule: default is exports.default).
            'main.jsx' => $code,
            // A .mjs importer gets Node's rule: default is module.exports.
            'main.mjs' => $code,
            'data.json' => '{"answer": 42}',
            'node_modules/plain/package.json' => '{"name": "plain", "main": "index.js"}',
            'node_modules/plain/index.js' => "module.exports = { kind: 'cjs', fn() {} };",
            'node_modules/flagged/package.json' => '{"name": "flagged", "main": "index.js"}',
            'node_modules/flagged/index.js' => "Object.defineProperty(exports, '__esModule', { value: true });\n"
                . "exports.default = 'default-export';\nexports.named = 'named-export';",
        ]);
        $engine = new Engine();
        $engine->execFile($root . '/main.jsx');
        $this->assertSame('cjs function default-export named-export 42', $engine->getConsoleOutput());
        $engine = new Engine();
        $engine->execFile($root . '/main.mjs');
        $this->assertSame('cjs function module.exports named-export 42', $engine->getConsoleOutput());
    }

    public function testRequireCyclesSeePartialExports(): void
    {
        $root = $this->project([
            'main.mjs' => "import a from 'cycle/a.js';\nconsole.log(a.done, a.sawB);",
            'node_modules/cycle/package.json' => '{"name": "cycle"}',
            'node_modules/cycle/a.js' => "exports.done = false;\nconst b = require('./b.js');\nexports.sawB = b.done;\nexports.done = true;",
            'node_modules/cycle/b.js' => "const a = require('./a.js');\nexports.sawADone = a.done;\nexports.done = true;",
        ]);
        $engine = new Engine();
        $engine->execFile($root . '/main.mjs');
        $this->assertSame('true true', $engine->getConsoleOutput());
    }

    public function testNodeEnvAndRequireOfEsm(): void
    {
        $root = $this->project([
            'main.mjs' => "import env from 'envy';\nconsole.log(env.mode, env.esm);",
            'node_modules/envy/package.json' => '{"name": "envy"}',
            'node_modules/envy/index.js' => "exports.mode = process.env.NODE_ENV;\nexports.esm = require('./esm.mjs').value;",
            'node_modules/envy/esm.mjs' => "export const value = 'from-esm';",
        ]);
        $engine = new Engine();
        $engine->execFile($root . '/main.mjs');
        $this->assertSame('production from-esm', $engine->getConsoleOutput());
    }
}
