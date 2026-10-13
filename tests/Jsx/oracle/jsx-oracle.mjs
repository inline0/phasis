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
    react: reactSuite,
    project: projectSuite,
    html: htmlSuite,
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

/**
 * React render oracle: esbuild compiles the fixture (React's automatic
 * runtime, react and react-dom left external), and Node renders it with
 * react-dom@19.3.0 through tests/Jsx/react/_driver.mjs: renderToString,
 * renderToStaticMarkup and prerender, as JSON.
 */
async function reactSuite(fixture) {
    const outDir = path.join(here, '.out');
    fs.mkdirSync(outDir, { recursive: true });
    const entry = `import * as mod from ${JSON.stringify(fixture)};\n`
        + `import { run } from ${JSON.stringify(path.join(jsxDir, 'react', '_driver.mjs'))};\n`
        + `console.log(JSON.stringify(await run(mod), null, 2));\n`;
    const file = path.join(outDir, path.basename(fixture, '.jsx') + '.mjs');
    await esbuild.build({
        stdin: { contents: entry, resolveDir: here, sourcefile: 'entry.mjs' },
        outfile: file,
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        external: ['react', 'react/*', 'react-dom', 'react-dom/*'],
        logLevel: 'silent',
    });
    const env = { ...process.env, TZ: 'UTC', NODE_ENV: 'production' };
    return execFileSync(process.execPath, [file], { encoding: 'utf8', env });
}

/**
 * HTML render oracle: the fixture compiled with `phasis` as the JSX import
 * source, where `phasis` is the Preact-based shim in oracle/phasis-shim, and
 * rendered by preact-render-to-string 6.8.0 through tests/Jsx/html/_driver.mjs.
 */
async function htmlSuite(fixture) {
    const outDir = path.join(here, '.out', 'html');
    fs.mkdirSync(outDir, { recursive: true });
    const entry = `import * as mod from ${JSON.stringify(fixture)};\n`
        + `import { run } from ${JSON.stringify(path.join(jsxDir, 'html', '_driver.mjs'))};\n`
        + `console.log(JSON.stringify(await run(mod), null, 2));\n`;
    const file = path.join(outDir, path.basename(fixture, '.jsx') + '.mjs');
    await esbuild.build({
        stdin: { contents: entry, resolveDir: here, sourcefile: 'entry.mjs' },
        outfile: file,
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        jsxImportSource: 'phasis',
        alias: { phasis: path.join(here, 'phasis-shim') },
        logLevel: 'silent',
    });
    const env = { ...process.env, TZ: 'UTC', NODE_ENV: 'production' };
    return execFileSync(process.execPath, [file], { encoding: 'utf8', env });
}

/**
 * Goals project: the fixture app in oracle/project, bundled by esbuild the
 * way a client build would (jsconfig aliases, CSS as empty, assets as URLs,
 * React external), rendered with react-dom's prerender in Node. fetch() is
 * served from project/comments.json, like the mock transport on the PHP side.
 */
async function projectSuite() {
    const dir = path.join(here, 'project');
    const assets = {
        name: 'assets',
        setup(build) {
            build.onLoad({ filter: /\.svg$/ }, (args) => ({
                contents: `export default ${JSON.stringify('/assets/' + path.basename(args.path))};`,
                loader: 'js',
            }));
        },
    };
    const entry = `import { createElement } from 'react';\n`
        + `import { prerender } from 'react-dom/static';\n`
        + `import Page from ${JSON.stringify(path.join(dir, 'Page.jsx'))};\n`
        + `import props from ${JSON.stringify(path.join(dir, 'props.json'))};\n`
        + `import comments from ${JSON.stringify(path.join(dir, 'comments.json'))};\n`
        + `globalThis.fetch = async () => new Response(JSON.stringify(comments), { headers: { 'content-type': 'application/json' } });\n`
        + `const { prelude } = await prerender(createElement(Page, props));\n`
        + `process.stdout.write(await new Response(prelude).text());\n`;
    const file = path.join(here, '.out', 'project.mjs');
    fs.mkdirSync(path.dirname(file), { recursive: true });
    await esbuild.build({
        stdin: { contents: entry, resolveDir: dir, sourcefile: 'entry.mjs' },
        outfile: file,
        absWorkingDir: dir,
        bundle: true,
        format: 'esm',
        // A client-style build: ES module entry points and conditions.
        platform: 'neutral',
        mainFields: ['module', 'main'],
        conditions: ['module', 'production'],
        resolveExtensions: ['.jsx', '.js', '.mjs', '.cjs', '.json'],
        define: { 'process.env.NODE_ENV': '"production"' },
        jsx: 'automatic',
        external: ['react', 'react/*', 'react-dom', 'react-dom/*'],
        loader: { '.css': 'empty' },
        plugins: [assets],
        logLevel: 'silent',
    });
    const env = { ...process.env, TZ: 'UTC', NODE_ENV: 'production' };
    return execFileSync(process.execPath, [file], { encoding: 'utf8', env, cwd: here });
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
    if (suite === 'project') {
        const html = await projectSuite();
        fs.writeFileSync(path.join(here, 'project', 'expected.html'), html + '\n');
        console.log('project: wrote expected.html');
        return;
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
