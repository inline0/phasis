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
3. **`node:` specifiers** are an error naming the module.
4. **`#` specifiers** through the nearest `package.json` `imports` field.
5. **Relative and absolute paths**, trying the extensions (`.jsx`, `.js`, `.mjs`, `.cjs`, `.json` by default), then `index` files.
6. **Path aliases** from the nearest `jsconfig.json` or `tsconfig.json` (`compilerOptions.paths` and `baseUrl`; comments and trailing commas allowed).
7. **`node_modules`**, walking up from the importing file: `package.json` `exports` (with conditions, subpath patterns and blocked `null` subpaths), then `module`, then `main`.

Bare Node built-in names (`fs`, `path`, ...) with no package of that name in `node_modules` are an error too. Stub them through the import map when a dependency imports one it does not use while rendering.

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
- Node's built-in modules and packages that need them at render time.
- TypeScript sources.
