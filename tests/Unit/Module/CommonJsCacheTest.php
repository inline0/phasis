<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Module;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class CommonJsCacheTest extends TestCase
{
    use TempProject;

    public function testMainParentChildrenAndFailedRequiresFollowTheActualModuleGraph(): void
    {
        $root = $this->project([
            'entry.cjs' => <<<'JS'
const first = require('./child.cjs');
const second = require('./child.cjs');
try { require('./bad.cjs'); } catch {}
globalThis.result = [module.id, module.parent, require.main === module,
  module.children.length, module.children[0].exports === first,
  first.parent === module, first.loadedDuringExecution, first.module.loaded,
  module.require('./child.cjs') === second, module.paths[0] === __dirname + '/node_modules'];
JS,
            'child.cjs' => 'module.exports = {parent: module.parent, module, loadedDuringExecution: module.loaded};',
            'bad.cjs' => 'throw new Error("load failed");',
        ]);
        $engine = new Engine(node: true);
        $engine->execFile($root . '/entry.cjs');
        self::assertSame(['.', null, true, 1, true, true, false, true, true, true], $engine->eval('result'));
    }

    public function testDeletingCacheEntriesReexecutesModulesAndSharesCycles(): void
    {
        $root = $this->project([
            'entry.cjs' => <<<'JS'
const path = require.resolve('./count.cjs');
const first = require(path);
const metadata = require.cache[path];
delete require.cache[path];
const second = require(path);
globalThis.result = [first, second, metadata.loaded, metadata.filename === path,
  Object.getPrototypeOf(require.cache) === null, require('./cycle.cjs').same];
JS,
            'count.cjs' => 'globalThis.count = (globalThis.count || 0) + 1; module.exports = count;',
            'cycle.cjs' => 'exports.same = require.cache[__filename].exports === exports && require(__filename) === exports;',
        ]);
        $engine = new Engine(node: true);
        $engine->execFile($root . '/entry.cjs');
        self::assertSame([1, 2, true, true, true, true], $engine->eval('result'));
    }

    public function testRequireDoesNotReadExportGettersAndEsmImportCreatesSnapshotBindings(): void
    {
        $root = $this->project([
            'entry.cjs' => <<<'JS'
const throwing = require('./throwing.cjs');
const values = require('./values.cjs');
globalThis.result = [typeof throwing, globalThis.reads || 0];
JS,
            'throwing.cjs' => 'module.exports = {get unsafe() { throw new Error("must not read"); }};',
            'values.cjs' => <<<'JS'
Object.defineProperty(exports, '__esModule', {value: true});
exports.default = 'bundler default';
const source = {get value() { globalThis.reads = (globalThis.reads || 0) + 1; return 42; }};
Object.defineProperty(exports, 'value', {enumerable: true, get: function () { return source.value; }});
JS,
            'import.mjs' => <<<'JS'
import value, {value as named} from './values.cjs';
import * as namespace from './values.cjs';
globalThis.imported = [typeof value, named, named, globalThis.reads,
 namespace.default === value, namespace['module.exports'] === value];
JS,
        ]);
        $engine = new Engine(node: true);
        $engine->execFile($root . '/entry.cjs');
        self::assertSame(['object', 0], $engine->eval('result'));
        $engine->execFile($root . '/import.mjs');
        self::assertSame(['object', 42, 42, 1, true, true], $engine->eval('imported'));
    }

    public function testCacheOverridesAndFailedModuleRetriesMatchNode(): void
    {
        $root = $this->project([
            'entry.cjs' => <<<'JS'
const path = require.resolve('./value.json');
const initial = require(path);
require.cache[path] = {exports: 'override'};
const replacement = require(path);
delete require.cache[path];
const again = require(path);
require.cache.events = {exports: 'shadow'};
const results = [initial.value, replacement, again.value, require('events'), typeof require('node:events')];
for (let i = 0; i < 2; i++) {
  try { require('./throws.cjs'); } catch (error) { results.push(error.message); }
}
globalThis.result = results;
JS,
            'value.json' => '{"value": 42}',
            'throws.cjs' => 'globalThis.attempt = (globalThis.attempt || 0) + 1; throw new Error(String(attempt));',
        ]);
        $engine = new Engine(node: true);
        $engine->execFile($root . '/entry.cjs');
        self::assertSame([42, 'override', 42, 'shadow', 'function', '1', '2'], $engine->eval('result'));
    }
}
