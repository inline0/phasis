// Regenerates the committed JSX oracle outputs from the pinned reference tools.
//
// Usage: node tests/Jsx/oracle/jsx-oracle.mjs <suite> [fixture ...]
//
// Each suite reads tests/Jsx/<suite>/*.jsx (or the given fixtures) and writes
// the reference output next to each fixture as <name>.expected.txt. The PHP
// tests in tests/Jsx compare Phasis against those files, so CI and local
// runs never need Node; only refreshing the oracle does.

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import os from 'node:os';
import esbuild from 'esbuild';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../../..');
const jsxDir = path.resolve(here, '..');

const suites = {
    parse: parseSuite,
    lower: lowerSuite,
    resolution: resolutionSuite,
    smoke: smokeSuite,
};

/** Where each suite's fixtures live, relative to tests/Jsx. */
const suiteDirs = { smoke: 'oracle/smoke' };

/** File extension of each suite's fixtures. */
const suiteExtensions = { smoke: '.mjs' };

/** Suites whose fixtures are directories holding a main.mjs entry. */
const directorySuites = new Set(['resolution']);

/**
 * esbuild compiles the fixture (automatic runtime, import source `recorder`;
 * pragmas may switch it to classic), bundles it with the recording runtime,
 * and Node runs it. The output is the recorder's trace of every call.
 */
async function lowerSuite(fixture) {
    let result;
    try {
        result = await esbuild.build({
        entryPoints: [fixture],
        bundle: true,
        write: false,
        format: 'esm',
        platform: 'neutral',
        jsx: 'automatic',
        jsxImportSource: 'recorder',
        alias: { recorder: path.join(here, 'recorder') },
        // Keep every call: compiled JSX is marked pure and would otherwise be dropped.
        treeShaking: false,
        ignoreAnnotations: true,
        logLevel: 'silent',
        });
    } catch (error) {
        return 'SyntaxError: ' + error.errors[0].text.replace(/:$/, '');
    }
    return runModule(result.outputFiles[0].text);
}

function runModule(code) {
    const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'jsx-oracle-')), 'fixture.mjs');
    fs.writeFileSync(file, code);
    try {
        return execFileSync(process.execPath, [file], { encoding: 'utf8', env: { ...process.env, TZ: 'UTC' } });
    } catch (error) {
        return (error.stdout ?? '') + 'Error: ' + String(error.stderr).split('\n').find((l) => /Error/.test(l));
    } finally {
        fs.rmSync(path.dirname(file), { recursive: true, force: true });
    }
}

/**
 * Bundle a fixture app with esbuild, configured like Phasis's defaults
 * (main fields, conditions, extensions, NODE_ENV, CSS as empty, assets as
 * deterministic URLs), and run it with Node.
 */
async function resolutionSuite(dir) {
    const assets = {
        name: 'assets',
        setup(build) {
            build.onLoad({ filter: /\.(svg|png|jpg|woff2)$/ }, (args) => ({
                contents: `export default ${JSON.stringify('/assets/' + path.basename(args.path))};`,
                loader: 'js',
            }));
        },
    };
    const result = await esbuild.build({
        entryPoints: [path.join(dir, 'main.mjs')],
        absWorkingDir: dir,
        bundle: true,
        write: false,
        format: 'esm',
        platform: 'neutral',
        mainFields: ['module', 'main'],
        conditions: ['phasis', 'module', 'production'],
        resolveExtensions: ['.jsx', '.js', '.mjs', '.cjs', '.json'],
        define: { 'process.env.NODE_ENV': '"production"' },
        jsx: 'automatic',
        loader: { '.css': 'empty' },
        plugins: [assets],
        logLevel: 'silent',
    });
    return runModule(result.outputFiles[0].text);
}

/** Package smoke tests: Node runs the module directly against the pinned packages. */
function smokeSuite(fixture) {
    // Phasis defaults NODE_ENV to production, as bundlers do for server builds.
    const env = { ...process.env, TZ: 'UTC', NODE_ENV: 'production' };
    return execFileSync(process.execPath, [fixture], { encoding: 'utf8', env });
}

/** Babel parser (the copy vendored for tests/Popular) with the jsx and estree plugins. */
function parseSuite(fixture) {
    const parser = loadBabelParser();
    const source = fs.readFileSync(fixture, 'utf8');
    try {
        const ast = parser.parse(source, { sourceType: 'module', plugins: ['jsx', 'estree'] });
        return summarize(ast.program, 0).join('\n');
    } catch (error) {
        return 'SyntaxError: ' + String(error.message).replace(/ \(\d+:\d+\)$/, '');
    }
}

let babelParser = null;
function loadBabelParser() {
    if (babelParser === null) {
        const context = {};
        vm.createContext(context);
        vm.runInContext(fs.readFileSync(path.join(root, 'tests/Popular/babel-parser/babel-parser.js'), 'utf8'), context);
        babelParser = context.BabelParser;
    }
    return babelParser;
}

/** Same format as Phasis\Ast\EstreeSerializer::summarize(). */
function summarize(node, depth) {
    const extra = [];
    if (typeof node.name === 'string' && node.name !== '') extra.push(`name=${node.name}`);
    if (node.value !== undefined && node.value !== null && typeof node.value !== 'object') {
        extra.push(`value=${JSON.stringify(node.value)}`);
    }
    if (typeof node.operator === 'string' && node.operator !== '') extra.push(`op=${node.operator}`);
    if (typeof node.kind === 'string' && node.kind !== '') extra.push(`kind=${node.kind}`);
    const lines = ['  '.repeat(depth) + node.type + (extra.length ? ' ' + extra.join(' ') : '')];
    for (const key of Object.keys(node)) {
        if (key === 'loc' || key === 'range' || key === 'extra' || key.endsWith('Comments')) continue;
        const child = node[key];
        if (child && typeof child === 'object' && typeof child.type === 'string') {
            lines.push(...summarize(child, depth + 1));
        } else if (Array.isArray(child)) {
            for (const item of child) {
                if (item && typeof item === 'object' && typeof item.type === 'string') {
                    lines.push(...summarize(item, depth + 1));
                }
            }
        }
    }
    return lines;
}

async function main() {
    const [suite, ...files] = process.argv.slice(2);
    if (!suites[suite]) {
        console.error(`usage: jsx-oracle.mjs <${Object.keys(suites).join('|')}> [fixture ...]`);
        process.exit(1);
    }
    const isDirectory = directorySuites.has(suite);
    const extension = suiteExtensions[suite] ?? '.jsx';
    const dir = path.join(jsxDir, suiteDirs[suite] ?? suite);
    const fixtures = files.length > 0
        ? files.map((f) => path.resolve(f))
        : fs.readdirSync(dir)
            .filter((f) => isDirectory
                ? fs.statSync(path.join(dir, f)).isDirectory()
                : f.endsWith(extension))
            .sort()
            .map((f) => path.join(dir, f));

    for (const fixture of fixtures) {
        const output = await suites[suite](fixture);
        const target = isDirectory ? fixture + '.expected.txt' : fixture.slice(0, -extension.length) + '.expected.txt';
        fs.writeFileSync(target, output.endsWith('\n') ? output : output + '\n');
    }
    console.log(`${suite}: wrote ${fixtures.length} expected outputs`);
}

await main();
