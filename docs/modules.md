---
title: "Modules"
description: "How imports resolve with no build step: import maps, node_modules and package exports, CommonJS, aliases, styles, assets and barrel pruning."
path: "modules"
order: 87
section: "JSX"
meta_title: "Modules"
meta_description: "How imports resolve with no build step: import maps, node_modules and package exports, CommonJS, aliases, styles, assets and barrel pruning."
---

# Modules

ES modules in Phasis resolve imports the way bundlers do, so components can import what they would import in the client bundle, unbundled: missing extensions, `index` files, path aliases, `node_modules` with `package.json` `exports`, CommonJS packages, and style and asset imports.

## Resolution order

For each specifier, the first rule that applies wins:

1. **Import map** (`Engine::setImportMap()`): exact keys, then keys ending in `/` as prefixes. A `null` value loads the module as an empty stub.
2. **Built-in modules**: `react`, `react/jsx-runtime`, `react-dom`, `react-dom/server`, `react-dom/static`, `phasis`, `phasis/jsx-runtime`.
3. **`node:` specifiers** resolve supported built-ins when [Node compatibility](#node-compatibility) is enabled; otherwise they are an error naming the module.
4. **`#` specifiers** through the nearest `package.json` `imports` field.
5. **Relative and absolute paths**, trying the extensions (`.jsx`, `.js`, `.mjs`, `.cjs`, `.json` by default), then `index` files.
6. **Path aliases** from the nearest `jsconfig.json` or `tsconfig.json` (`compilerOptions.paths` and `baseUrl`; comments and trailing commas allowed).
7. **`node_modules`**, walking up from the importing file: `package.json` `exports` (with conditions, subpath patterns and blocked `null` subpaths), then `module`, then `main`.

In the default mode, bare Node built-in names (`fs`, `path`, ...) with no package of that name in `node_modules` are an error too. An import-map stub can represent a dependency that is unused while rendering. Node mode resolves the supported built-ins under both their bare and `node:` names.

```php
$engine->setImportMap([
    'design-system' => __DIR__ . '/vendor-js/design-system/index.mjs',
    'icons/' => __DIR__ . '/vendor-js/icons/',
    'analytics' => null, // an empty module on the server
]);
```

## Options

```php
use Phasis\Module\ModuleOptions;

$engine->setModuleOptions(new ModuleOptions(
    projectRoot: __DIR__,                          // where jsconfig.json is read; default: nearest one
    extensions: ['.jsx', '.js', '.mjs', '.cjs', '.json'],
    conditions: ['phasis', 'module', 'production'],
    nodeEnv: 'production',                         // process.env.NODE_ENV
    styleExtensions: ['.css', '.scss', '.sass', '.less', '.styl'],
    assetExtensions: ['.svg', '.png', '.jpg', /* ... */],
    parseCacheDir: null,                           // see "Parse cache"
    deferSideEffectFreeModules: false,             // see "Deferred loading"
));
```

`exports` conditions are matched in the package's order against `import` or `require`, `default`, and the active `conditions`. The `production` condition follows `nodeEnv`, and `phasis` lets a package ship a build for Phasis.

## CommonJS

Packages that ship only CommonJS (`classnames`, `dayjs`, `lodash`, `prop-types`, ...) load without a bundler.

- `.cjs` files are CommonJS and `.mjs` files are ES modules. A `.js` file in `node_modules` is an ES module when its package says `"type": "module"`, else CommonJS unless it uses `import` or `export`. Application files outside `node_modules` are always ES modules.
- Each file runs in Node's wrapper with `require`, `module`, `exports`, `__filename`, `__dirname` and a minimal `process` (`process.env.NODE_ENV`). `require` cycles see the partially filled exports, `require()` of JSON returns the value, and `require()` of an ES module returns its namespace.
- **Interop** follows bundlers: importing a CommonJS module gives `module.exports` as the default export, or its `default` property when `__esModule` is set, plus every property as a named export. Importers with Node semantics (`.mjs` files, or `.js` files in `"type": "module"` packages) always get `module.exports` as the default, as in Node.

In ES modules, `process.env.NODE_ENV` is replaced with the configured value at parse time, the way bundlers define it, so `if (process.env.NODE_ENV !== 'production')` branches work without a `process` object.

## Node compatibility

Express and Hono support is **experimental**. Enable Node mode explicitly for
packages that use Node APIs:

```php
use Phasis\Engine;
use Phasis\Node\NodeOptions;

$engine = new Engine();
$engine->enableNode(new NodeOptions(
    cwd: __DIR__,
    env: ['NODE_ENV' => 'production'],
    argv: ['phasis', __DIR__ . '/server.cjs'],
));
try {
    $engine->execFile(__DIR__ . '/server.cjs');
} finally {
    $engine->close();
}
```

`new Engine(node: true)` enables the same mode with default options. The default
directory and environment are inherited from PHP; an explicit `env` replaces the
inherited environment. `process.chdir()` changes the realm's directory without
changing PHP's working directory. Node mode uses the PHP host's filesystem,
network and subprocess permissions; `cwd` is a starting directory, not an access
restriction.

Entry files and packages use Node-aware module resolution, the `node` package
condition, and a live `process.env`. CommonJS wrappers expose `module.parent`,
`module.children`, `require.main`, `require.resolve` and `require.cache`.
Deleting a cache entry causes the next require to execute the module again;
cycles see partial exports. Built-in bare and `node:` aliases share identity.
ES module imports of CommonJS retain the complete `module.exports` as their
default export.

The built-in registry includes these modules and their listed subpaths:

| Area | Modules |
|---|---|
| Binary data and streams | `buffer`, `string_decoder`, `events`, `stream`, `stream/promises`, `stream/consumers`, `stream/web` |
| Files and paths | `fs`, `fs/promises`, `path`, `path/posix`, `path/win32`, `os` |
| Networking | `net`, `http`, `https`, `tls`, `http2` |
| Encoding and security | `url`, `querystring`, `zlib`, `crypto` |
| Utilities | `util`, `util/types`, `assert`, `assert/strict` |
| Execution | `process`, `module`, `vm`, `child_process`, `tty`, `timers`, `timers/promises`, `async_hooks` |

These modules combine interpreted JavaScript implementations with PHP host
operations. TCP, Unix sockets, files and child pipes use real host resources.
HTTP2 uses real frames and HPACK, including multiplexing and TLS ALPN. Node-mode
`fetch` uses asynchronous HTTP transports so a client and server can share one
engine. `AsyncLocalStorage` follows Promise reactions, timers and I/O callbacks.
`BroadcastChannel` communicates between channels inside the same engine.

PHP's OpenSSL extension supplies TLS and AES/RSA/EC cryptography; sodium supplies
Ed25519; zlib supplies incremental gzip/deflate codecs. Brotli is bundled
JavaScript. No Node installation is needed to execute JavaScript. An explicit
`child_process` request launches the requested host program through PHP;
launching `process.execPath` with a JavaScript file starts another Phasis PHP
process.

Framework coverage is measured by the pinned test suites; the registry is a
subset of Node's API surface. Current host limits include filesystem watching and portable
subsecond/birth timestamps, raw TTY mode, optional unverified TLS client
certificates, Worker threads, child-process IPC/detached sessions, V8 cache
serialization and VM code-generation controls. Brotli transforms buffer a full
frame; partial flush and changing zlib compression parameters after construction
are unavailable. Asymmetric Node key/sign APIs are not exposed; WebCrypto
provides its documented algorithms. Some OS memory/uptime queries require Linux
`/proc` and report unavailability on other hosts. Process memory figures describe PHP allocations. Unsupported
host operations report errors or remain absent from the module surface.

The pinned original Express and Hono test runners live in
`tests/Frameworks`. Their manifests retain discovered cases, upstream skips,
failures, blocked tests, source hashes and process timeouts. Framework versions
and suite revisions are recorded there; passing a framework suite does not imply
that the complete Node test suite or Hono's Bun/Deno/Fastly/workerd hosts pass.

Framework CI passes `--timeout-scale 10` to the Express and Hono runners so slow
PHP workloads can complete their original assertions. Reports retain the
original and effective timeouts; scaled results do not establish upstream
performance parity. The default scale is 1 for checking the original deadlines.
This configuration does not affect test262 or engine execution budgets.

## Styles and assets

- **Style imports** (`import './Card.css'`) load as empty modules on the server.
- **CSS modules** (`*.module.css`) need a resolver that returns the class names the client build generates; without one the import throws.
- **Asset imports** (`import logo from './logo.svg'`) default-export the URL your resolver returns, for example from the client build's manifest; without one the import throws, so Phasis never renders a guessed URL.

```php
$engine->setAssetResolver(fn (string $file): string => $manifest[$file] ?? throw new RuntimeException($file));
$engine->setCssModuleResolver(fn (string $file): array => $cssModules[$file]);
```

## Barrel pruning

Icon sets and utility libraries often have an entry file that only re-exports hundreds of modules. When a package declares `"sideEffects": false` (or lists the files with side effects), Phasis rewrites `import { Alpha } from 'pkg'` into an import of the module that defines `Alpha`, following `export { }`, `export * from` and `export * as ns` chains. The barrel and the modules you do not use are never parsed or run, the same tree-shaking bundlers do.

Importing one `lucide-react` icon loads that icon's own import closure: the icon module and its 12 shared helpers in lucide-react 1.56.0, instead of the 1,883-icon barrel. Anything Phasis cannot prove safe, such as namespace imports or a barrel with real code in it, keeps the original import.

## Deferred loading

Pruning drops the modules nobody imports. With `deferSideEffectFreeModules: true`, Phasis goes further for packages that declare `"sideEffects": false`: a module imported from one loads when one of its imported bindings is first read, instead of before the importing module runs. Modules a render never touches are never parsed.

```php
$engine->setModuleOptions(new ModuleOptions(deferSideEffectFreeModules: true));
```

With it, importing one `lucide-react` icon loads 4 modules instead of 13, and rendering it loads 11: the 2 helpers for icons built from a name never load.

It is off by default because it changes *when* a module's top-level code runs. A package that declares `"sideEffects": false` but logs, registers something or reads global state as it loads behaves differently than in the client bundle, which runs every module up front. Turn it on for packages you trust to be truthful. These imports always load with the importer:

- namespace imports (`import * as ns`) and side-effect imports (`import 'pkg/x.mjs'`)
- application files outside `node_modules`, CommonJS modules, and modules that may use top-level `await`

The path still resolves when the importer loads, so a missing module fails at the same time as before. A missing export throws its `SyntaxError` on the first read, and an error thrown while the module evaluates is thrown on every read.

## Reading exports from PHP

```php
public function getExport(string $path, string $name = 'default'): mixed
```

Loads a module and returns one export converted to PHP, after running the event loop. Unlike `evalAsModule()`, which returns `null` because a namespace object can be self-referential, `getExport()` hands back a value:

```php
$config = $engine->getExport(__DIR__ . '/site.config.mjs');
$html = $engine->getExport(__DIR__ . '/render.mjs', 'html');
```

`execFile()` runs `.mjs` files and JSX files through the module loader (and returns `null`); `.js` files stay classic scripts.

## Parse cache

Under PHP-FPM, each request parses the modules it imports again. Set `parseCacheDir` to cache parsed ASTs on disk across engines and requests:

```php
$engine->setModuleOptions(new ModuleOptions(parseCacheDir: '/var/cache/phasis-ast'));
```

Entries are keyed by the source text and the options that change parsing (JSX options, `NODE_ENV`), so an edited file misses and is parsed again. In local tests a cached AST took roughly 20x the source size on disk and unserialized about twice as fast as parsing.

## Not supported

- Bundler-specific import features: query suffixes (`?raw`, `?url`), SVG-as-component imports, `import.meta.glob`, `import.meta.env`, webpack loaders.
- Node built-ins outside the opt-in registry above, and native Node addons.
- TypeScript sources.
