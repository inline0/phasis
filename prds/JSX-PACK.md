# PRD: JSX Pack

**Status**: Implemented on `feat/jsx-pack`, Phases 1 to 10, with the deviations recorded in "Deviations in the implementation"

**Sign-off decisions (2026-10-13)**:
1. The client framework is **React**, and the server targets **React 19.3.0** exactly.
2. **Speed first**: React's server rendering is built into Phasis natively, in PHP. No React bundle is loaded or interpreted.
3. A **general JSX renderer** ships alongside it for server-only HTML.
4. All six open questions are resolved as recommended (see "Resolved questions").
5. **Toolchain pin and vendored React** confirmed. `tests/Jsx/oracle/` pins the reference tools and holds the ~580 KB real React 19.3 bundle, used as the exact fallback and the three-way oracle.
6. **Imports must work with no build step**, whatever bundler the client uses. Phasis resolves imports the way bundlers do, including `node_modules`, `package.json` `exports` and CommonJS packages. This is the most important requirement.
7. **Components are mostly presentational**: data comes from PHP as props (see "Component guidelines").
8. **Phase order**: syntax and imports first, then React (Phases 1 to 6), with the HTML profile (Phase 7) built alongside Phase 6.

**Author**: this session
**Last updated**: 2026-10-13
**Builds on**: the ES module loader (`src/Module/ModuleLoader.php`), the event loop, and FETCH-PACK.md (components that call `fetch`)

## Summary

Render JSX components to HTML on the server, in pure PHP, fast enough for real pages, with output that React 19.3 on the client hydrates without errors. Five pieces:

1. **Syntax**: JSX in `.jsx` modules, lowered at parse time to plain function calls. The interpreter, bytecode compiler, VM and JsToPhp emitter never see a JSX node.
2. **Module resolution**: imports resolve the way bundlers resolve them, with no build step. That covers missing extensions, `index` files, path aliases, `node_modules`, `package.json` `exports`, CommonJS packages, and style and asset imports.
3. **Native React 19.3 SSR**: `react`, `react/jsx-runtime`, `react-dom`, `react-dom/server` and `react-dom/static` are built-in modules implemented in PHP. Their HTML is byte-identical to React 19.3's own server renderer. Only the application's components run as JavaScript.
4. **General JSX renderer**: `phasis` and `phasis/jsx-runtime` for server-only templates (emails, feeds, static pages), byte-identical to `preact-render-to-string` 6.8, plus `raw()`.
5. **Host plumbing**: built-in modules, import maps, `.jsx` routing, and `Engine::render()` so PHP renders a component with PHP props in one call.

Both renderers share one element model (React 19's) and one render core. They differ only in how they serialize HTML.

## Goals

### Components rendered from PHP, with no build step

Components import what they would import in the client bundle:

```jsx
// components/PostCard.jsx
import cx from 'classnames';                   // CommonJS package from node_modules
import { MessageCircle } from 'lucide-react';  // ES module package; only this icon's module loads
import Avatar from '@/components/Avatar';      // alias from jsconfig.json, no extension
import './PostCard.css';                       // an empty module on the server

export default function PostCard({ post }) {
  return (
    <article className={cx('post-card', { featured: post.featured })}>
      <Avatar name={post.author} />
      <h2>{post.title}</h2>
      <p>{post.excerpt}</p>
      <span><MessageCircle size={16} /> {post.commentCount} comments</span>
    </article>
  );
}
```

```php
use Phasis\Engine;

$engine = new Engine();
echo $engine->render(__DIR__ . '/components/PostCard.jsx', ['post' => $post]);
```

Nothing is built first. Phasis resolves each import the way the client bundler does (Layer 4).

### Interactive parts, hydrated by React 19.3 on the client

```jsx
// components/CommentsToggle.jsx
import { useId, useState } from 'react';

export default function CommentsToggle({ count, excerpt }) {
  const [open, setOpen] = useState(false);
  const panelId = useId();
  return (
    <div>
      <button aria-controls={panelId} onClick={() => setOpen(!open)}>
        {count} comments
      </button>
      <section id={panelId} hidden={!open}>{excerpt}</section>
    </div>
  );
}
```

PHP renders it and embeds the props, so React on the client renders the same tree:

```php
$props = ['count' => 3, 'excerpt' => $excerpt];
echo '<div id="comments">' . $engine->render(__DIR__ . '/components/CommentsToggle.jsx', $props) . '</div>';
echo '<script>window.__COMMENTS__ = ' . json_encode($props, JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>';
```

The client side is standard React 19.3 tooling, not part of Phasis:

```jsx
hydrateRoot(document.getElementById('comments'), <CommentsToggle {...window.__COMMENTS__} />);
```

Two details make this hydrate cleanly. The text markers React needs (`<!-- -->`) are present, and `useId()` produces the same `_R_..._` ids React 19.3 generates on the client.

### Data loading

Components written to the guidelines get their data from PHP as props. For the cases where a component has to fetch, `Engine::render()` follows React 19.3's `prerender` semantics: it waits for async components and for `use(promise)` inside `<Suspense>`.

```jsx
export default async function Comments({ postId }) {
  const res = await fetch(`https://api.example.com/posts/${postId}/comments`);
  const comments = await res.json();
  return <ul>{comments.map((c) => <li key={c.id}>{c.body}</li>)}</ul>;
}
```

React 19.3's client hydrates async components in production. Its development build logs "is an async Client Component", because React officially supports async components only as Server Components. Keep data-fetching components in parts of the page React does not hydrate.

### Server-only templates with the general renderer

```jsx
/** @jsxImportSource phasis */
import { raw } from 'phasis';

export default function Newsletter({ name, articleHtml }) {
  return (
    <table class="newsletter">
      <tr><td>Hello {name}</td></tr>
      <tr><td>{raw(articleHtml)}</td></tr>
    </table>
  );
}
```

### Optional server bundle

Teams that run a bundler can also build one server file and hand that to Phasis. Any bundler works if it meets three conditions:

- It outputs an ES module.
- It leaves `react` and `react-dom` external.
- It keeps JSX as JSX, or compiles it against `react/jsx-runtime`.

Verified with esbuild, using `jsx: 'preserve'`, `external: ['react', 'react/*', 'react-dom', 'react-dom/*']` and the `empty` loader for CSS. A sample app with extensionless imports, an `@/` alias, `clsx`, a CSS import and an SVG import became one `entry-server.jsx`:

- `clsx` was inlined and the CSS import was removed.
- The SVG import was replaced by its hashed URL.
- `import { useState } from "react"` was left for Phasis.

Keeping JSX as JSX lets Phasis apply precompile. The bundle is optional: Layer 4 makes the unbundled source work.

### Exact fallback

Some libraries depend on React internals that the native implementation doesn't cover. For those, a vendored ESM build of real React 19.3 can be mapped over the built-ins with an import map. It is byte-identical but roughly 15x slower (see "Why a native React renderer").

### Tooling

`Parser::parseSource($src, 'module', new JsxOptions(mode: JsxMode::Preserve))` returns the JSX AST, which can be exported as standard ESTree JSX nodes.

## Component guidelines

The app's components are mostly presentational: props in, HTML out. That shape is the fastest to render and the safest to hydrate.

- **Load data in PHP and pass it in as props.** PHP already has the data (database, WordPress). Async components work, but they move I/O into the slower JS layer.
- **Keep props JSON-friendly**: strings, numbers, booleans, arrays and plain objects, with dates as strings. The client must hydrate with exactly the same props.
- **Render the same thing on server and client.**
  - Don't touch `window`, `document` or `localStorage` during render.
  - Avoid values that differ between server and client: `Date.now()`, `Math.random()`, and time-zone or locale-dependent formatting.
  - Put browser-only work in `useEffect`, or behind `use(browser(reason))` inside `<Suspense>` (Layer 7).
- **Keep interactive parts small and separate.** Only they need hydrating; purely presentational sections don't.
- **Use plain class names or Tailwind**, not CSS modules, unless a CSS-module resolver is configured (Layer 4).
- **Import images through the asset resolver, or use public URLs** (Layer 4).
- **Prefer packages that declare `"sideEffects": false`** for large libraries such as icon sets. Only the modules actually used then load (Layer 4).

The payoff:

- There is no hook bookkeeping.
- The markup is mostly static, and precompile turns it into string appends.
- Identical props always give identical HTML, so PHP can cache the output keyed by the props.

## Non-goals

- **TypeScript and `.tsx`**. Needs type stripping first; separate PRD.
- **JSX outside modules in the Engine**. `eval()`, `new Function()`, `ShadowRealm` and classic scripts stay plain JavaScript. JSX is a compile-time, module-goal feature, as in Babel, esbuild, Bun and Deno. This keeps every test262 code path untouched. The standalone parser API still accepts JSX in the script goal for tooling.
- **Client-side React**: the DOM, events, effects, state updates. The browser runs the real React.
- **Streaming SSR**: `renderToReadableStream`, `renderToPipeableStream`, `resume*` and `prerenderToNodeStream` (shell-first output with inline swap scripts). v1 renders complete documents.
- **React Server Components**: `"use client"` / `"use server"` directives, the Flight protocol, server actions.
- **Other React versions.** The profile design allows adding 19.x versions later; v1 ships 19.3.0 only.
- **A props-embedding or islands helper**. App-level in v1, as in the Goals example.
- **Bundler-specific import features**: query suffixes such as Vite's `?raw` and `?url`, SVG-as-component imports (SVGR), `import.meta.glob`, `import.meta.env`, and webpack loaders. Use plain imports, or the optional server bundle.
- **Node's built-in modules** (`fs`, `path`, `node:*`) and packages that need them. A dependency that imports one without using it while rendering can be stubbed through the import map.
- **Dev runtime and dev-only warnings** (`jsxDEV`, `__source`, `__self`).
- **JSX output in the Formatter**. Follow-up; the Formatter keeps rejecting JSX until then.

## Current state (verified 2026-10-13)

- **JSX dies in the lexer today.** `Parser::ensureTokenized()` (`src/Parser/Parser.php:160`) tokenizes the whole file before parsing starts. `<div>Don't</div>` fails with "Unterminated string literal", and `</div>` lexes as the start of a regex (`RegExp(/div>;/)`). The regex fix-up approach (`rescanSlashAsRegExp()`) cannot recover from a lexer that has already thrown, so the lexer has to recognize JSX itself.
- **The lexer already knows where JSX can start.** `canStartRegExp()` (`src/Lexer/Lexer.php:1820`) answers "may an expression start here?", including the paren walk-back that tells `if (...)` apart from a call. Plain JavaScript never starts an expression with `<`, so a `<` in that position followed by a name or `>` is unambiguously JSX.
- **Only three parse sites matter.** `Engine::eval()` (`src/Engine.php:1659`), `Engine::execFile()` (`:1718`) and `ModuleLoader::linkModule()` (`src/Module/ModuleLoader.php:376`). Nothing re-parses source later; the AST is retained.
- **Only exact relative and absolute paths resolve.** `ModuleLoader::resolve()` (`:1284`) throws for bare specifiers, and `import Card from './Card'` throws `Cannot find module './Card'`.
- **CommonJS can't load.** Phasis has no `require` or `module.exports`. Of 15 packages common in React components:
  - 6 ship only CommonJS: `classnames`, `dayjs`, `lodash`, `prop-types`, `react-is` and `use-sync-external-store`.
  - 9 ship ES modules: `clsx`, `tailwind-merge`, `class-variance-authority`, `date-fns`, `lodash-es`, `lucide-react`, `react-icons`, `@heroicons/react` and `react-markdown`.
- **Barrel files are large.** These are rough local timings.
  - `lucide-react`: importing one icon loads an entry file with 1,883 re-exports. Parsing the entry and its icon modules (2,144 files, 1.9 MB) took about 660 ms.
  - `react-icons/fa`: a single 1.3 MB file, about 290 ms.
  - `date-fns`: re-exports 245 modules.
  - `@heroicons/react/24/solid`: re-exports 324 modules.

  All four declare `"sideEffects": false`.
- **`execFile()` never loads modules.** It always parses as a script, so an `.mjs` file with an `import` throws `'import' is only valid in module code`. `docs/api.md` claims otherwise. The CLI calls `eval(file_get_contents(...))` and loses the path.
- **AST tooling.** `Walker` and `Serializer` are reflection-based and pick up new node classes automatically. `EstreeSerializer` (`src/Ast/EstreeSerializer.php:104`) and `JsPrinter` (`src/Formatter/JsPrinter.php:1069`) dispatch explicitly.
- **Real React 19.3 already runs inside Phasis.**
  - `react` plus `react-dom/server.edge`, bundled into one ESM file with esbuild (npm's React is CommonJS), renders byte-identical HTML in Phasis and in Node. That includes context, `useState`, `useMemo` and the text markers.
  - The default browser server build creates a `MessageChannel` at load time, which Phasis doesn't have. The edge build doesn't.
- **Real Preact 11.0.1 and preact-render-to-string 6.8.0** also render byte-identically in Phasis and Node.
- **A parse oracle is already vendored.** `tests/Popular/babel-parser/babel-parser.js` parses JSX with `plugins: ['jsx', 'estree']` and emits every ESTree JSX node type.

## Why a native React renderer

The same page was rendered four ways inside Phasis: header, nav, 100 post cards with interpolated text, a context consumer, 1,316 elements in all. These are rough local measurements with Xdebug off and the tracing JIT on. They are directional only; the CI bench is the authoritative number.

| Variant | Load per fresh engine | Render |
|---|---:|---:|
| Real React 19.3 (bundled edge build) | ~565 ms | ~1,850 ms |
| Real Preact 11 + preact-render-to-string | ~165 ms | ~870 ms |
| Minimal hand-written JS renderer | none | ~270 ms |
| The components alone, producing no HTML | none | ~110 ms |

Node renders the same page in about 3 ms.

- **The cost is React, not the components.** About 94% of real React's render time is React's own code. The components themselves account for the rest.
- **What the native path keeps.** Element creation, hooks and serialization move into PHP, so the cost approaches the components-only floor. That is an estimated 15x faster render than real React on this page.
- **No library load.** The ~565 ms load disappears, because the library is PHP code that OPcache keeps compiled. It can also be loaded at server start with `opcache.preload`.

## Why byte-identical output, pinned to 19.3

**Hydration depends on React's exact text markers.** Both server formats were hydrated in jsdom, against the React 19.3 client and the Preact 11 client:

| Server HTML | Preact client | React client |
|---|---|---|
| Preact format: `<p>Hello Dennis</p>` | hydrates | **fails** |
| React format: `<p>Hello <!-- -->Dennis</p>` | hydrates | hydrates |

When text pieces sit next to each other (`Hello {name}`, `{n} comments`), React's server output separates them with `<!-- -->`. Without the markers, React reports "Hydration failed because the server rendered text didn't match the client", discards the server DOM and renders the tree again in the browser. This happens in production and in development.

Other format differences produce the same DOM and hydrate fine, including `disabled` vs `disabled=""` and `'` vs `&#x27;`. The one exception: a trailing `;` in `style` triggers a development-only attribute warning.

**React's server output changes between minor versions:**

| React | `useId()` | `<img>` |
|---|---|---|
| 18.3.1 | `:R1:` | no preload link |
| 19.0.0 | `:R1:` | adds `<link rel="preload" as="image">` |
| 19.1.0 | `«R1»` | preload link |
| 19.3.0 | `_R_1_` | preload link |

The ids the server generates must equal the ids the client generates, or `htmlFor` and `aria-*` links break after hydration. The native renderer therefore targets one exact version, React 19.3.0, behind a version profile. Moving the client to a later 19.x means a profile update and regenerated oracles.

## Architecture

### Layer 1: Lexer (JSX mode)

`Lexer::setJsx(bool)` is set by the parser when JSX is enabled. With it off, tokenization is unchanged. JSX scanning lives in a trait so `Lexer.php` (already ~2,000 lines) doesn't grow further.

The lexer keeps a context stack:

| Frame | Entered by | Lexes | Left by |
|---|---|---|---|
| Tag | `<` where `canStartRegExp()` is true and the next non-whitespace character is an identifier start or `>`; or `<` inside Children | JSX names (identifier characters plus `-`; keywords such as `class` and `for` are plain names), attribute strings (no escapes, may span lines), `= : . / { >`; whitespace and both comment styles skipped | `>` |
| Children | `>` ending an opening tag | `JsxText`: raw text up to `{` or `<`, with no whitespace or comment skipping | the closing tag's `>` |
| Expression | `{` inside Tag or Children | normal JavaScript with brace counting; nested JSX allowed | `}` at depth 0 |

JSX and template literals nest in both directions (`` `${<b />}` `` and `` {`${x}`} ``). The existing template handling (`Lexer.php:174`) runs before `readToken()` and assumes it owns every `}` at depth 0 inside a substitution, so template frames and JSX frames must share one ordering.

New token types: `JsxTagStart`, `JsxTagEnd`, `JsxName`, `JsxText`, `JsxString`. The `JsxTagEnd` that closes a root element counts as value-producing in `canStartRegExp()`.

Accepted as esbuild and Babel accept them: whitespace after `<` (`< div />`), comments inside tags, and literal `>` and `}` in text.

Known gap: after `}` the lexer treats `<` as less-than, the same rule it applies to `/` today. A JSX element used as an expression statement directly after a block (`if (a) {} <p />`) therefore doesn't lex as JSX. Its value would be discarded anyway; documented.

### Layer 2: Parser and AST

- **`Parser\Parts\JsxParser` trait**, following the existing `Parts/*` pattern, entered from `parsePrimaryExpression()` (`src/Parser/Parts/ExpressionParser.php:878`) on `JsxTagStart`.
- **AST nodes in `src/Ast/Jsx/`**, named per the codebase convention, with `EstreeSerializer` mapping them to ESTree names (`JsxElement` to `JSXElement` and so on): `JsxElement`, `JsxFragment`, `JsxOpeningElement`, `JsxClosingElement`, `JsxOpeningFragment`, `JsxClosingFragment`, `JsxAttribute`, `JsxSpreadAttribute`, `JsxExpressionContainer`, `JsxEmptyExpression`, `JsxSpreadChild`, `JsxText`, `JsxIdentifier`, `JsxMemberExpression`, `JsxNamespacedName`. `docs/parser.md` gains rows in its deviation table.
- **Errors use Babel's wording**, since Babel is the parse oracle:
  - `Expected corresponding JSX closing tag for <a>.`
  - `Adjacent JSX elements must be wrapped in an enclosing tag. Did you want a JSX fragment <>...</>?`
  - `JSX attributes must only be assigned a non-empty expression.`
- **Parsing stays side-effect free.** `parseParenthesizedOrArrow()` backtracks (`ExpressionParser.php:2539`), so a JSX element inside parentheses may be parsed twice.

Parser API:

```php
public static function parseSource(string $source, string $sourceType = 'script', ?JsxOptions $jsx = null): Program
public function setJsxOptions(?JsxOptions $options): void
```

`Preserve` and `Classic` work in either goal. `Automatic` and `Precompile` inject imports and require the module goal.

### Layer 3: Lowering at parse time

`Parser\Jsx\JsxLowering` lowers each element as soon as it is parsed (bottom-up). Unless the mode is `Preserve`, the parser only ever returns ECMAScript nodes, each carrying the JSX node's `SourceLocation` so stack traces point at the JSX.

```php
enum JsxMode { case Automatic; case Classic; case Preserve; case Precompile; }

final readonly class JsxOptions
{
    public function __construct(
        public JsxMode $mode = JsxMode::Automatic,
        public string $importSource = 'react',
        public string $factory = 'React.createElement',
        public string $fragment = 'React.Fragment',
        /** @var list<string> Module path suffixes the Engine parses with JSX. */
        public array $extensions = ['.jsx'],
    ) {
    }
}
```

The default import source is `react`, the same setting the client bundle compiles with. `react/jsx-runtime` resolves to the native built-in, so server and client compile the same files the same way.

The output must match esbuild exactly. Verified against esbuild 0.28.2 (`/* @__PURE__ */` annotations omitted; shown with `importSource: 'react'`):

| JSX | Automatic | Classic (`h`, `Fragment`) |
|---|---|---|
| `<div />` | `jsx("div", {})` | `h("div", null)` |
| `<p>{name}</p>` | `jsx("p", { children: name })` | `h("p", null, name)` |
| `<Card title="Hi" key={id}>Hello {name}!</Card>` | `jsxs(Card, { title: "Hi", children: ["Hello ", name, "!"] }, id)` | `h(Card, { title: "Hi", key: id }, "Hello ", name, "!")` |
| `<><br /></>` | `jsx(Fragment, { children: jsx("br", {}) })` | `h(Fragment, null, h("br", null))` |
| `<input disabled />` | `jsx("input", { disabled: true })` | `h("input", { disabled: true })` |
| `<div key="x" {...props} />` | `jsx("div", { ...props }, "x")` | `h("div", { key: "x", ...props })` |
| `<div {...props} key="x" />` | `createElement("div", { ...props, key: "x" })`, imported from `"react"` | `h("div", { ...props, key: "x" })` |
| `<div>{...xs}</div>` | `jsxs("div", { children: [...xs] })` | `h("div", null, ...xs)` |
| `<use xlink:href="#a" />` | `jsx("use", { "xlink:href": "#a" })` | `h("use", { "xlink:href": "#a" })` |

Rules behind the table:

- **Tag names.** A lowercase first letter, a `-`, or a namespace (`svg:rect`) makes a string. Everything else is a reference: `Foo`, `_x`, `$y`, `Foo.Bar.Baz`, `this.Comp`.
- **Key after a spread** falls back to `createElement` from the **package root** (`"react"`, or `"phasis"` for the general runtime), not from the `jsx-runtime` module.
- **`jsxs`** is used when `children` is an array: two or more children, or any spread child.
- **Attribute strings**: HTML entities decoded, no backslash escapes, newlines kept as written.
- **Text children**: entities decoded (XHTML named entities plus numeric references; unknown ones such as `&unknown;` stay literal). Whitespace follows the shared JSX rule: split on line terminators, drop whitespace-only lines, trim leading whitespace on every line but the first and trailing whitespace on every line but the last, join what's left with single spaces.

  ```jsx
  <div>
      Hello,
         world   {name}
      <b>bold</b>   tail
  </div>
  // jsxs("div", { children: ["Hello, world   ", name, jsx("b", { children: "bold" }), "   tail"] })
  ```
- **Empty containers** `{}` and `{/* comment */}` produce no child.
- **Injected imports.** Automatic mode prepends `import { jsx, jsxs, Fragment } from "react/jsx-runtime"`, importing only the names actually used. Local names are chosen so they don't appear as any identifier token in the file.
- **Pragmas** in any comment override the options per file, as in esbuild: `@jsx`, `@jsxFrag`, `@jsxRuntime`, `@jsxImportSource`. The lexer already scans comments for `sourceURL`; pragma capture piggybacks on that scan. The module records its effective import source, which `Engine::render()` uses to pick a profile.

### Layer 4: Module resolution

Any import that works in the client bundler works on the server, with no build step. Resolution follows bundler semantics, because that is what the client build does, and Node's rules where bundlers defer to them.

```php
final readonly class ModuleOptions
{
    public function __construct(
        /** Where jsconfig.json or tsconfig.json is read; null means the nearest one above the entry module. */
        public ?string $projectRoot = null,
        /** @var list<string> Tried in order for paths without an extension and for index files. */
        public array $extensions = ['.jsx', '.js', '.mjs', '.cjs', '.json'],
        /** @var list<string> Active package.json conditions, in addition to "import" or "require" and "default". */
        public array $conditions = ['phasis', 'module', 'production'],
        /** The value process.env.NODE_ENV reads as. */
        public string $nodeEnv = 'production',
        /** @var list<string> Imports with these extensions load as empty modules. */
        public array $styleExtensions = ['.css', '.scss', '.sass', '.less', '.styl'],
        /** @var list<string> Imports with these extensions go through the asset resolver. */
        public array $assetExtensions = ['.svg', '.png', '.jpg', '.jpeg', '.gif', '.webp', '.avif', '.ico', '.woff', '.woff2', '.ttf', '.mp4', '.webm'],
    ) {
    }
}
```

`ModuleOptions::$extensions` decides which files resolution finds. `JsxOptions::$extensions` separately decides which of them parse with JSX.

**Resolution order.** For each specifier, the first match wins:

1. **Import map**: the exact key, then the longest key ending in `/` that prefixes the specifier. A `null` value stubs the module (see Stubs).
2. **Built-in modules**: `react`, `react-dom` and the other built-ins (Layer 5). A `react` package installed in `node_modules` for the client build is never used on the server.
3. **Package imports**: `#name` specifiers through the importing package's `package.json` `imports` field.
4. **Path aliases**: `compilerOptions.paths` (with `baseUrl`) from `jsconfig.json` or `tsconfig.json`, following local `extends`.
5. **Relative and absolute paths.**
6. **Packages**: `node_modules` directories, walking up from the importing file.

Anything else throws an error naming the specifier and the importing file.

**Files.** A path resolves to:

1. the exact file
2. the file with each of `$extensions` appended
3. for a directory, its `index` file with the same extensions

Symlinks resolve to real paths (pnpm layouts), and module identity is the real path, as today.

**Packages.**
- `package.json` `exports` follows Node's rules:
  - strings and subpath maps, with `*` patterns
  - conditional objects, where the first key that is an active condition wins, in the package's own key order
  - `null` targets, which block a subpath
- Active conditions: `import` for ES imports or `require` for `require()`, plus `phasis`, `module`, `production` (which follows `$nodeEnv`) and `default`. `node` and `browser` are off by default, because those builds assume Node APIs or a DOM; both can be switched on.
- Without `exports`: the `module` field, then `main`, then `index.js`. Deep imports such as `date-fns/format` use file resolution inside the package.
- The legacy `browser` field is ignored.

**Module formats.**
- `.mjs` and JSX extensions are ES modules, `.cjs` is CommonJS, and `.json` is a JSON module (the existing loader branch).
- For `.js`, the nearest `package.json` `type` decides. Without `type`, a file with top-level `import` or `export` is an ES module and anything else is CommonJS. That is Node 22's syntax detection. It also covers packages whose `module` field points at ES module `.js` files without `"type": "module"`.

**CommonJS.**
- **The wrapper.** Files run inside the usual wrapper with `exports`, `require`, `module`, `__filename` and `__dirname`, in sloppy mode unless they say `"use strict"`. `require.resolve` is available.
- **`require()`.**
  - It resolves with the `require` condition and caches by real path.
  - On a cycle it returns the partially filled `module.exports`, as Node does.
  - It loads JSON.
  - For an ES module without top-level await, it returns the module namespace, as Node 22 does.
- **Importing CommonJS from an ES module** follows bundler interop, matching the client build:
  - The default import is `module.exports`, or `module.exports.default` when `__esModule` is set.
  - Named imports read the matching properties of `module.exports`.
  - A CommonJS module runs when it is first imported or required.
- **`process.env.NODE_ENV`** reads as `$nodeEnv` (default `"production"`).
  - CommonJS modules get a minimal `process` object.
  - ES modules get the constant substituted at parse time, as bundlers do.
  - There is no global `process`, so code that detects Node with `typeof process` still sees a non-Node environment.
- **Node's built-in modules** (`fs`, `path`, `node:*`) don't exist. Importing or requiring one throws, unless the import map stubs it.

**Styles and assets.**
- **Style imports** (`$styleExtensions`) load as empty modules.
- **CSS modules** (`*.module.css` and similar) need the class names the client bundler generates. Importing one throws an error saying so, unless `Engine::setCssModuleResolver()` supplies the class map, for example from the client build's output.
- **Asset imports** (`$assetExtensions`) default-export a URL from `Engine::setAssetResolver()`, for example a lookup in the client build's manifest so hashed filenames match. Without a resolver they throw an error naming the file, instead of rendering a URL that won't match the client.
- **Any other unknown extension** throws an error naming the file.

**Stubs.** An import map entry with a `null` value loads as an empty module: the default export and every named import are `undefined`. This is for browser-only packages (charts, maps, editors) whose components don't render on the server, because they sit behind `use(browser(reason))` or a `typeof window` check.

**Barrel pruning.** Packages that declare `"sideEffects": false` (or list the files that do have side effects) are pruned at module level, the way bundlers tree-shake them.
- When an import names specific exports of a module that only re-exports, only the modules that provide those names are loaded. One `lucide-react` icon then loads a handful of modules instead of the whole barrel.
- `export { X } from` is followed directly. Names reached through `export *` are found with a quick scan of each starred module's export list, which is cached and doesn't evaluate the module.
- Namespace imports (`import * as icons`) and dynamic `import()` load the full module.
- Single-file libraries such as `react-icons` can't be pruned. They rely on the warm engine or the parsed-module cache (Layer 10), or on the optional server bundle.

**Caching.** Resolution results and `package.json` / `jsconfig.json` reads are cached per engine, next to the existing module cache.

### Layer 5: Host plumbing

1. **Built-in module registry.** `ModuleLoader::registerBuiltinModule(string $specifier, \Closure $factory)`. `resolve()` returns a reserved key for registered specifiers, and `linkModule()` builds a synthetic namespace from the factory, mirroring the `.json` branch at `ModuleLoader.php:352`. Registered: `react`, `react/jsx-runtime`, `react-dom`, `react-dom/server`, `react-dom/static`, `phasis`, `phasis/jsx-runtime`, and the internal `phasis/jsx-precompile`.
2. **Import maps.** `Engine::setImportMap(array $imports)`, following the WHATWG import map `imports` table: exact keys, plus keys ending in `/` as prefixes. Values are absolute paths, or `null` for a stub. The import map is consulted first (Layer 4), which is how the exact-React fallback replaces the built-ins.
3. **Which sources get JSX.** Module paths ending in one of `JsxOptions::$extensions` (default `['.jsx']`). `linkModule()` passes the options to the parser for those paths, and `evalAsModule($source, $path)` follows the same rule. `.js` files opt in by adding `.js` to the list.
4. **`execFile()`** routes `.mjs` and JSX extensions through the module loader and returns `null` for them, like `evalAsModule()`. `.js` keeps today's script behavior, and `docs/api.md` is corrected.
5. **PHP entry points:**

   ```php
   enum RenderProfile { case React; case Html; }

   public function setJsxOptions(JsxOptions $options): void
   public function setModuleOptions(ModuleOptions $options): void
   public function setImportMap(array $imports): void
   public function setAssetResolver(callable $resolver): void      // fn (string $file): string
   public function setCssModuleResolver(callable $resolver): void  // fn (string $file): array
   public function render(string $path, array $props = [], string $export = 'default', ?RenderProfile $profile = null): string
   public function renderSource(string $source, array $props = [], string $export = 'default', ?string $path = null, ?RenderProfile $profile = null): string
   public function getExport(string $path, string $name = 'default'): mixed
   ```

   - **Profile choice.** `render()` uses the given profile, else the entry module's effective JSX import source (`react` gives `React`, `phasis` gives `Html`).
   - **Steps.** It loads the module, converts `$props` with `PhpToJs`, renders the export, and runs the event loop until the render settles.
   - **Waiting semantics.** The React profile follows React 19.3's `prerender` and waits for every async component and `use(promise)`. The HTML profile awaits async components.
   - **Failures.** A rejected render rethrows as `JsThrowable`. A render still pending after the loop drains throws `RuntimeError` ("render did not settle") instead of hanging.
   - **`renderSource()`** does the same for an in-memory module; `$path` anchors relative imports.
   - **`getExport()`** returns one export converted to PHP. This is how the exact-React fallback hands HTML back to PHP, and it avoids `evalAsModule()`'s self-referential namespace problem.
6. **CLI.**
   - `bin/phasis file.jsx` goes through `execFile()`.
   - `--ast` uses `Preserve` for `.jsx` files.
   - Optional: `bin/phasis --render file.jsx --props '{"id":1}'`.

### Layer 6: Render core (shared)

`src/BuiltIn/Jsx/` holds one PHP render core, used by both profiles.

**Element model.** One element model, React 19's:

- Both runtimes create `Phasis\Value\JsElement` instances through native `jsx`, `jsxs` and `createElement`.
- JS sees React 19.3's shape: own properties `$$typeof` (`Symbol.for('react.transitional.element')`), `type`, `key`, `ref` and `props`.
- Special types use React's own `Symbol.for` keys: `react.fragment`, `react.suspense`, `react.memo`, `react.forward_ref`, `react.lazy`, `react.context`, `react.consumer` and the others. Libraries that check `$$typeof` (react-is style) keep working.
- The renderer accepts the PHP class (fast path) and objects carrying the element symbol, as React does. Objects from `JSON.parse` can't carry symbols, so they never render as elements.

**Components.**
- Function components.
- Class components (`Component`, `PureComponent`): constructor, `getDerivedStateFromProps`, `UNSAFE_componentWillMount`, `render`, `static contextType`, and `defaultProps` (class components only, as in React 19).
- `memo`, `forwardRef` and `lazy`.

**Hooks dispatcher.** Server semantics per React 19.3, ported from React's Fizz hooks:
- `useState` and `useReducer` return the initial state. Render-phase updates re-render the component, as React does.
- `useMemo` and `useCallback` compute once. `useRef` returns `{ current }`.
- `useEffect`, `useLayoutEffect`, `useInsertionEffect` and `useImperativeHandle` never run.
- `useSyncExternalStore` reads `getServerSnapshot`.
- `useTransition`, `useDeferredValue`, `useOptimistic`, `useActionState` and `useEffectEvent` behave as React's server renderer does.
- `useContext` and `use()` cover both context and promises.

**`useId`.** Ids come from React's tree-position algorithm (fork indexes encoded per tree level) and use React 19.3's `_R_..._` format.

**Context.** Three forms are supported, each read with `useContext` or `use`: `<Ctx value>` (React 19), `<Ctx.Provider>` and `<Ctx.Consumer>`.

**Thenables.** Async components, `use(promise)` and `lazy` suspend the render through a PHP Fiber, the mechanism generators already use (`src/Value/JsGenerator.php:14`). The PHP host drives the event loop until the promise settles. Each component's context and tree position are captured, so suspension can't leak state between components.

**Profiles.** Profiles only decide serialization: text, attributes, markers and hoisting.

### Layer 7: React 19.3 profile

Modules match React 19.3.0's export lists.

The streaming and resume APIs are out of scope (see Non-goals): `renderToReadableStream`, `renderToPipeableStream`, `resume`, `resumeToPipeableStream`, `prerenderToNodeStream`, `resumeAndPrerender` and `resumeAndPrerenderToNodeStream`. They are still exported, but calling one throws a `TypeError` naming the limitation. That way a module that merely imports them still links.

| Module | Exports |
|---|---|
| `react` | `Activity`, `Children`, `Component`, `Fragment`, `Profiler`, `PureComponent`, `StrictMode`, `Suspense`, `ViewTransition`, `addTransitionType`, `cache`, `cacheSignal`, `cloneElement`, `createContext`, `createElement`, `createRef`, `forwardRef`, `isValidElement`, `lazy`, `memo`, `startTransition`, `unstable_useCacheRefresh`, `use`, `useActionState`, `useCallback`, `useContext`, `useDebugValue`, `useDeferredValue`, `useEffect`, `useEffectEvent`, `useId`, `useImperativeHandle`, `useInsertionEffect`, `useLayoutEffect`, `useMemo`, `useOptimistic`, `useReducer`, `useRef`, `useState`, `useSyncExternalStore`, `useTransition`, `version` (`"19.3.0"`) |
| `react/jsx-runtime` | `jsx`, `jsxs`, `Fragment` |
| `react-dom` | `browser`, `createPortal`, `flushSync`, `preconnect`, `prefetchDNS`, `preinit`, `preinitModule`, `preload`, `preloadModule`, `requestFormReset`, `unstable_batchedUpdates`, `useFormState`, `useFormStatus`, `version` |
| `react-dom/server` | `renderToString`, `renderToStaticMarkup`, `version` (plus the throwing streaming stubs) |
| `react-dom/static` | `prerender` (returns `{ prelude }` as a `ReadableStream`, like React), `version` (plus the throwing stubs) |

Each export behaves as React 19.3's server renderer does, as pinned by the oracle. Examples: `createPortal` throws the way React's server renderer does, and the `preload` family emits resource hints.

Output rules. The oracle is the source of truth; the following are already verified against React 19.3.0:

- **Text markers.** `<!-- -->` goes between adjacent text pieces: `<p>Hello <!-- -->Dennis</p>`.
- **Suspense markers.**
  - A resolved boundary is `<!--$-->...<!--/$-->`.
  - A boundary that falls back to client rendering (sync `renderToString`, or an error) is `<!--$!--><template></template>fallback<!--/$-->`.
  - Sync `renderToString` throws "A component suspended while responding to synchronous input" when nothing catches the suspension. `prerender` and `Engine::render()` wait instead.
- **Client-only components.** `use(browser(reason))` (react-dom 19.3, undocumented) asks for a component to render only in the browser.
  - Inside a `<Suspense>`, the server renders the fallback in a client-render boundary, `<!--$!--><template data-dgst=""></template>fallback<!--/$-->`, without reporting an error, and the browser renders the real component after hydration.
  - Outside a boundary, the render fails with React's error ("client rendering was requested outside a Suspense boundary").
  - On the client, the call does nothing.
- **Escaping.** `&`, `<`, `>`, `"` and `'` are escaped, with `'` as `&#x27;`.
- **Attributes.**
  - Booleans render as `disabled=""`.
  - React's attribute naming table applies (`readOnly=""`, `tabindex`).
  - Function values are dropped.
  - `aria-*` and `data-*` booleans are stringified.
- **Style.** `style` objects serialize without a trailing `;` (`color:red;font-size:12px`). String `style` values throw, as in React.
- **URL safety.** `javascript:` URLs in `href`, `src`, `action` and `formAction` become React's blocking URL, `javascript:throw new Error('React has blocked a javascript: URL as a security precaution.')`.
- **Resource hints and hoisting.** Images emit `<link rel="preload" as="image" href>` ahead of the content. Rendering `<html>`, `<head>` and `<body>` hoists `<title>`, `<meta>`, `<link>`, stylesheet precedence and async scripts the way React 19.3 does.
- **Forms.** `value`, `defaultValue`, `checked` and `defaultChecked` follow React's handling, and `<select value>` marks the matching `<option>` as `selected=""`.
- **`useId`** renders `_R_1_`-style ids.

**Implementation approach.** Port the synchronous and prerender paths from React's MIT-licensed Fizz sources (`ReactFizzServer`, `ReactFizzHooks`, `ReactFizzConfigDOM`) rather than reverse-engineering the output. Ported files keep React's copyright notice.

### Layer 8: General HTML profile

`phasis/jsx-runtime` exports `jsx`, `jsxs` and `Fragment`. `phasis` exports:

- `createElement` and `Fragment`
- `createContext`, `useContext` and the shared server hooks
- `renderToString` and `renderToStringAsync`
- `raw`

Output is byte-identical to preact-render-to-string 6.8.0. HTML spellings (`class`, `for`, string `style`) work as written. Behaviors verified against it:

- **Text**
  - Escapes `&`, `<` and `"` only.
  - Numbers use JS `ToString` (`-0` becomes `0`, `1e21` becomes `1e+21`).
  - `null`, `undefined` and booleans render nothing.
  - Arrays and fragments flatten; adjacent text joins with no markers.
- **Elements.** Void elements render as `<br/>`.
- **Attribute names**
  - `className` becomes `class` and `htmlFor` becomes `for`.
  - HTML camelCase props are lowercased (`readonly`, `maxlength`).
  - Inside SVG, presentation attributes are kebab-cased (`stroke-width`), and `xlinkHref` becomes `xlink:href`.
- **Attribute values**
  - `true` or `""` render as a bare attribute (`disabled`, `alt`).
  - `false`, `null`, `undefined` and functions are omitted.
  - `aria-*` and `data-*` booleans are stringified.
  - `style` objects become `prop:value;` pairs with a trailing `;`, and `px` is added to numbers except unitless and custom properties.
- **Special cases**
  - Unsafe attribute names are dropped.
  - `dangerouslySetInnerHTML.__html` is inserted unescaped.
  - `<textarea value>` renders the value as escaped content.
  - `<select value>` marks the matching option `selected`.
- **`raw(html)`** renders trusted HTML from PHP verbatim. It is a Phasis extension, excluded from the byte-compat oracle.
- **No URL filtering.** `javascript:` URLs pass through, as in Preact; embedders sanitize untrusted URLs.

This profile has no hydration markers, so React cannot hydrate its output. Use it for server-only HTML.

### Layer 9: Precompile mode

`JsxMode::Precompile` is opt-in and valid with the built-in runtimes. It turns static HTML into string arrays at parse time, as Deno's `jsx: "precompile"` does. React profile example:

```jsx
<div className="card"><h2>{title}</h2><p>Static text</p></div>
```

```js
const $$tpl_1 = ['<h2>', '</h2><p>Static text</p>'];
jsx("div", { className: "card", children: jsxTemplate($$tpl_1, title) });
```

- **Where it applies.** Only HTML elements nested inside another HTML element become templates. The outermost element of each JSX expression stays a real element, and so does every element passed to a component, because components may inspect or clone what they receive (`cloneElement`, `Children.map`, `asChild`-style slots).
- **Helpers module.** Precompiled modules import `jsxTemplate` and `jsxAttr` from the internal built-in `phasis/jsx-precompile`, so `react/jsx-runtime` keeps React's exact export list.
- **Dynamic parts.** Dynamic attributes become `jsxAttr(name, value)` holes, using the profile's serialization rules.
- **What stays a call.** Components and elements with spread props stay `jsx()` calls inside holes.
- **Handlers are skipped.** Inside templates, `on*` props on HTML elements are left out when their value is an arrow function or function expression, so those closures are never created. React drops them from server output anyway.
- **Text markers at holes.** For the React profile, each template part records whether it starts or ends with text. The `<!-- -->` markers are then emitted at holes exactly where React would put them: static text next to a hole that renders text gets a marker, and static text next to a hole that renders an element doesn't.
- **`useId` positions.** Tree positions of components inside holes are preserved, so `useId` stays identical.
- **Correctness bar.** Output must be byte-identical with and without precompile. Static markup then costs one string append per template, instead of a `jsx()` call, an element object and a renderer visit per node.

### Layer 10: Performance and preloading

- **React is preloaded.** The native React implementation is PHP code in Phasis, so OPcache keeps it compiled. Phasis ships a preload script for `opcache.preload` that loads the render core and the built-in modules at server start. No React source is parsed or executed per request.
- **Warm engine in worker servers.** On FrankenPHP worker mode, RoadRunner or Swoole, keep one `Engine` per worker. `render()` loads each module once, including everything from `node_modules`, and reuses it for every request. As in Node SSR servers, module-level state is shared across requests; components should keep per-request data in props or context.
- **PHP-FPM.** Components and the packages they import are parsed once per request, and barrel pruning (Layer 4) keeps that to the modules actually used. An optional on-disk parsed-module cache sits behind a flag. It matters most for large single-file libraries. In a local test, unserializing a cached AST was about 2.3x faster than parsing, at about 20x the source size on disk.
- **Precompile (Layer 9)** removes most per-node work for static markup.
- **Later: lazy function bodies.** Parsing function bodies on first call, as V8 does, would cut the load cost of large libraries further. It's a candidate after v1, to be measured in the CI bench first.
- **Xdebug off.** Docs state that Xdebug must be off in production. In local tests Xdebug alone made Phasis about 5x slower, and its 512-frame nesting guard aborted a real-React render outright. The tracing JIT added another 1.2x to 1.4x on top of turning Xdebug off.
- **CI bench.** `bench/ssr.jsx`, the 1,316-element page above, joins `bench.yml` (tracing JIT, per CLAUDE.md).
  - The page imports a CommonJS package and a `lucide-react` icon, so module loading is part of the measurement.
  - It runs four variants: native React, real React in Phasis, components-only, and the HTML profile.
  - Targets are expressed as ratios, so they hold across machines.

## Testing strategy

Node can't run JSX, so each oracle is Node plus a pinned reference tool:

- **Pinned toolchain.** The reference tools live in `tests/Jsx/oracle/package.json`, pinned to:
  - `esbuild@0.28.2`
  - `react@19.3.0` and `react-dom@19.3.0`
  - `jsdom@26.1.0`
  - `preact@11.0.1` and `preact-render-to-string@6.8.0`
  - the 15 packages from "Current state", at the versions checked on 2026-10-13
- **Committed outputs.** Oracle outputs are committed, so the main CI stays npm-free, the same contract as `tests/Popular`.
- **Hydration job.** The hydration check needs Node at test time, so it runs in its own workflow (`jsx.yml`), which installs the pinned toolchain.

| Oracle | Reference (Node) | Phasis side | Compared |
|---|---|---|---|
| Parse | vendored `tests/Popular/babel-parser`, `plugins: ['jsx', 'estree']` | `JsxMode::Preserve` plus `EstreeSerializer::summarize()` | one-line-per-node summary |
| Lowering | esbuild in automatic and classic mode, executed with a recording runtime | the same `.jsx` with the recording runtime via import map | trace of every `jsx` / `jsxs` / `createElement` / `h` call: function, type, props in key order, key |
| Module resolution | the fixture app bundled by esbuild and run in Node | the unbundled fixture app loaded by Phasis | identical output |
| Package smoke | small components using each of the 15 packages, rendered by React 19.3 | the same components, unbundled, through Layer 4 | byte-equal HTML |
| React render | `react-dom@19.3.0`: `renderToString`, `renderToStaticMarkup`, `prerender` | React profile | byte-equal HTML |
| React hydration | `react-dom@19.3.0/client` in jsdom, production and development builds | React profile output for every React fixture | zero recoverable errors, zero hydration warnings, every server node reused |
| React three-way | none | vendored real React 19.3 (edge build) running inside Phasis | byte-equal with the native profile and with Node |
| HTML render | preact-render-to-string 6.8.0 | HTML profile | byte-equal HTML |
| Precompile | none | both profiles in `JsxMode::Precompile` | byte-equal with non-precompiled output |

**Fixture layout.**

- JSX fixtures live in `scenarios/jsx/<area>/<name>/`, with `scenario.json` modes `jsx-parse`, `jsx-lower`, `jsx-render-react`, `jsx-render-html` and `jsx-hydrate`.
- Resolution fixtures live in `tests/Jsx/resolution/<name>/`: each is a small app with a committed `node_modules` of synthetic packages.
- `bin/oracle` dispatches the new modes to a new `bin/jsx-oracle` Node script.
- Note: `bin/oracle`'s existing `parse` mode calls `bin/parse-oracle`, which isn't in the repo, so the JSX parse oracle doesn't build on it.

**Corpus areas.**

- **Syntax**: names, attributes, entities, whitespace, comments, template nesting in both directions, Babel error messages.
- **Lowering**: both runtimes, pragmas, key and spread ordering, fragments, spread children.
- **Module resolution**:
  - extensions and `index` files, aliases, `imports` fields
  - `exports` conditions, patterns and blocked subpaths
  - `type` and syntax detection
  - CommonJS interop with and without `__esModule`, require cycles, `require()` of ES modules, JSON
  - styles, assets with and without a resolver, CSS modules, stubs
  - barrel pruning through `export { }` and `export *`, Node built-ins
- **React profile**:
  - every hook, every context form, and `use()`
  - `useId` in nested, forked and list positions
  - class components, `memo`, `forwardRef`, `lazy`
  - Suspense resolved, fallback and error; async components; `browser()` inside and outside `Suspense`
  - resource APIs, and document rendering with hoisting
  - forms, SVG and MathML, custom elements
  - `dangerouslySetInnerHTML`, `javascript:` URLs, error propagation
- **HTML profile**: every rule in Layer 8.

**Unit tests.**
- `tests/Unit/Lexer/JsxLexerTest.php`
- `tests/Unit/Parser/JsxParserTest.php`
- `tests/Unit/Jsx/JsxLoweringTest.php`
- `tests/Unit/Module/ResolverTest.php`
- `tests/Unit/Module/PackageExportsTest.php`
- `tests/Unit/Module/CommonJsTest.php`
- `tests/Unit/Module/BarrelPruningTest.php`
- `tests/Unit/Module/ImportMapTest.php`
- `tests/Unit/Jsx/RenderCoreTest.php`
- `tests/Unit/Jsx/ReactProfileTest.php`
- `tests/Unit/Jsx/HtmlProfileTest.php`

**Plain JS must not move.** JSX only activates for JSX module paths, but the lexer's main loop and the module loader change for every source:

- `./bin/verify-all`
- test262 sweeps on `language/expressions/template-literal`, `language/literals/regexp`, `language/comments`, `annexB/language/comments` and `language/module-code`
- `gh workflow run compat-matrix.yml` before Phases 1 and 3 merge
- the CI bench as the perf gate

## Phased delivery

| Phase | Scope | Est. lines | Gate |
|---|---|---:|---|
| 1 | Lexer JSX mode, token types, `src/Ast/Jsx/` nodes, `JsxParser` trait, `Preserve` mode, `EstreeSerializer` arms | ~1,200 | parse oracle 100%, test262 sweep and compat matrix, CI bench |
| 2 | `JsxLowering` (automatic and classic), pragmas, entity table, `JsxOptions` | ~700 | lowering oracle 100% |
| 3 | Module resolution, part A, and host plumbing: built-in registry, import maps with stubs, extensions and `index` files, aliases, `node_modules` with `exports`, `imports`, `module` and `main`, format detection, styles and assets, `.jsx` routing, `execFile()` and `evalAsModule()`, `getExport()`, CLI | ~1,200 | resolution oracle 100% on ES module fixtures; the exact-React fallback renders `.jsx` files byte-equal with Node |
| 4 | Module resolution, part B: CommonJS loader and interop, `process.env.NODE_ENV`, barrel pruning | ~1,200 | full resolution oracle 100%; all 15 smoke packages work; one `lucide-react` icon loads fewer than 10 modules |
| 5 | Render core and React 19.3 profile, part A: `JsElement`; `react`, `react/jsx-runtime`, `react-dom/server` (sync); function components, all hooks, context, `useId`, `memo`, `forwardRef`; text, attributes, escaping, forms, SVG; `Engine::render()` | ~2,500 | React render and hydration oracles 100% on part-A fixtures |
| 6 | React 19.3 profile, part B: Suspense, `use()`, `browser()`, `lazy`, async components, `prerender` and `react-dom/static`, waiting in `Engine::render()`, class components, `react-dom` resource APIs, document hoisting, error semantics | ~2,500 | full React oracles 100%, three-way agreement |
| 7 | HTML profile: `phasis` and `phasis/jsx-runtime`, Preact-format serialization, `raw()`, `renderToStringAsync` | ~600 | HTML render oracle 100% |
| 8 | Precompile for both profiles | ~700 | precompile oracle 100%, SSR bench improvement in CI |
| 9 | Performance: `bench/ssr.jsx` in CI, `opcache.preload` script, worker-mode docs, optional parsed-module cache | ~300 | targets in Acceptance criteria met in CI |
| 10 | Docs: new `docs/jsx.md`, `docs/react.md` and `docs/modules.md` (registered in `docs/meta.json`), `docs/api.md` (new methods, `execFile()` correction), `docs/parser.md` (JSX nodes), README feature list | n/a | docs-content workflow |

Total estimate: ~10,900 lines of engine code plus ~1,200 lines of oracle tooling and fixtures.

- **Milestones.**
  - After Phase 3, React SSR works end to end through the exact-React fallback. It is correct but slow, and it serves as the three-way oracle from then on.
  - After Phase 4, components can import CommonJS packages and large icon libraries.
- **Ordering.**
  - Phases 3 and 4 don't depend on JSX, so imports work can start alongside Phases 1 and 2.
  - Phase 7 reuses the core and can run alongside Phase 6.

## Acceptance criteria

1. **Goals examples work end to end**:
   - `PostCard` with its imports
   - the hydrated `CommentsToggle`
   - async data loading, with `fetch` served by a mock transport in tests
   - the general-renderer template
2. **Imports work with no build step.** A fixture React project renders through `Engine::render()` byte-identical to the same project bundled by esbuild and rendered by React 19.3 in Node. The project uses:
   - extensionless imports and `index` files
   - a `jsconfig.json` alias
   - ES module and CommonJS packages from `node_modules`
   - `package.json` `exports` conditions
   - style imports, and asset imports with a resolver
3. **Popular packages.** The 15 packages listed in "Current state" work inside components.
4. **Barrels.** Importing one `lucide-react` icon loads fewer than 10 modules.
5. **React parity.** The React profile is byte-identical to `react-dom@19.3.0` (`renderToString`, `renderToStaticMarkup`, `prerender`) on 100% of React fixtures.
6. **Hydration.** React 19.3's client hydrates 100% of React fixtures, in production and development builds, with zero recoverable errors and zero hydration warnings, reusing every server node. Fixtures that deliberately use async components only expect React's "async Client Component" development message.
7. **HTML profile parity.** Byte-identical to preact-render-to-string 6.8.0 on 100% of HTML fixtures.
8. **Other oracles.** Parse, lowering, resolution, three-way and precompile oracles: 100%.
9. **Performance** (CI bench, `bench/ssr.jsx`):
   - the native React profile renders at least 10x faster than real React 19.3 running in Phasis on the same page
   - it stays within 1.5x of the components-only floor
   - there is no per-engine library load
10. **Plain JavaScript is unaffected**:
    - test262 50,506 / 50,506
    - WPT 100%
    - popular packages byte-equal
    - `./bin/verify-all` green
    - no CI bench regression on `bench/microbench.js`
11. **Safety.**
    - Strings are escaped on every path.
    - Only elements created by the runtime, or objects carrying React's element symbol, render as elements.
    - `raw()` and `dangerouslySetInnerHTML` are the only unescaped paths.
    - The React profile blocks `javascript:` URLs exactly like React 19.3.
    - Asset and CSS-module imports never render a guessed URL or class name: without a resolver they throw.
12. No new Composer requirements.
13. Docs updated as listed in Phase 10.

## Risks

1. **Size of the React surface.** The React profile is a port of React's server renderer (Fizz) for the synchronous and prerender paths. Mitigations: an oracle fixture per API; porting from the source instead of reverse-engineering; and the exact-React fallback for anything not yet covered.
2. **Byte-exactness hot spots**: `useId` tree ids, resource hoisting order, Suspense markers, and attribute edge cases. All are oracle-pinned. The hydration check catches anything that renders identically in bytes but would still fail in a browser.
3. **React upgrades.**
   - The profile is pinned to 19.3.0, and a client on another version breaks hydration (ids differ).
   - `react.version` reports `"19.3.0"`, so applications can assert that client and server match.
   - Supporting a newer 19.x is a profile update plus regenerated oracles.
4. **Resolution breadth.** Node's resolution and bundler interop have many corners: `exports` patterns and condition order, `__esModule`, require cycles, and `require()` of ES modules. Mitigation: follow bundler semantics, which is what the client build does, and pin them with the resolution oracle and the package smoke tests.
5. **Packages that assume Node or a browser.** Some packages touch Node APIs or the DOM as soon as they load. Mitigation: errors that name the package, stubs through the import map, and the component guidelines.
6. **Barrel pruning trusts `sideEffects`.** Pruning relies on packages declaring it truthfully, the same trust bundlers place in it. A package that lies breaks in the client bundle too.
7. **Lexer risks.**
   - `<` ambiguity: mitigated by reusing `canStartRegExp()`, which test262 exercises fully for `/`.
   - Template and JSX nesting: the template code assumes it owns `}`, so fixtures cover both nesting directions.
   - Hot path: the one extra branch per token when JSX is off is checked in the CI bench.
8. **Async rendering.** Fiber suspension must cooperate with the event loop and with generators, which also run on Fibers. Never-settling renders fail fast (Layer 5). Existing limits (`maxLoopIterations`, call depth, execution time) still apply inside components.
9. **Precompile.**
   - Templates must preserve the tree positions React uses for ids.
   - A component that inspects the children of an HTML element it received would see a template object instead of elements.

   Both are covered by running the whole React corpus with and without precompile, and precompile stays opt-in.
10. **License.** React is MIT. Ported files keep React's copyright and license notice, and the repo lists it with third-party notices.
11. **`Function.prototype.toString()`** on a component returns the JSX source, where Node plus a bundler shows compiled code. Documented divergence; nothing tests it.

## Deviations in the implementation

Recorded while implementing on `feat/jsx-pack`. Where the code and the sections above disagree, this list is what shipped.

1. **Fixture layout.** JSX fixtures live in `tests/Jsx/<suite>/` (`parse`, `lower`, `resolution`, `react`, `html`) and run in a `jsx` PHPUnit suite, instead of `scenarios/jsx/` with new `scenario.json` modes. The vendored real React 19.3 build lives in `tests/Jsx/vendor/react-19.3.0/` (generated by `tests/Jsx/oracle/build-vendor.mjs`), next to the pinned toolchain rather than inside it. The oracle script is `tests/Jsx/oracle/jsx-oracle.mjs` (suites `parse`, `lower`, `resolution`, `smoke`, `react`, `project`, `html`), not `bin/jsx-oracle`, and `bin/oracle` is unchanged. Package smoke components live in `tests/Jsx/oracle/smoke/` and the Goals fixture project in `tests/Jsx/oracle/project/`. The `jsx.yml` workflow runs the `jsx` suite and `hydrate.mjs`; the main CI keeps running only the `unit` suite.
2. **JSX after `}`.** The Layer 1 known gap is closed: in JSX mode `<` after a block's `}` starts JSX when it is followed by a tag name or `>`, as in Babel and esbuild (`if (a) {} <p />`). A parse fixture pins it against Babel.
3. **Application `.js` files are always ES modules.** Format detection (`type`, then syntax detection) applies inside `node_modules` only. A `.js` file of the application is an ES module even without `import` or `export`, as in the client bundle.
4. **Node-semantics importers.** Bundler interop applies to `.js` and JSX importers. An `.mjs` file, or a `.js` file in a `"type": "module"` package, gets `module.exports` as the default import of a CommonJS module, as in Node and as esbuild does for those files.
5. **Default export of the React built-ins.** `react`, `react/jsx-runtime`, `react-dom`, `react-dom/server` and `react-dom/static` also have a `default` export equal to their export object, so `import React from 'react'` works as it does through a bundler. The named export lists are React's own.
6. **Barrels: fewer than 10 modules with deferred loading, which is opt-in.** One `lucide-react` 1.56.0 icon statically imports 12 shared modules, and esbuild's bundle of one icon contains the same 13. Under bundle semantics every one of them runs up front, so by default Phasis loads all 13 (never the 1,883-icon barrel), and `JsxPackageSmokeTest` pins that closure. Acceptance criterion 4 is met by `ModuleOptions(deferSideEffectFreeModules: true)`: modules of packages that declare `"sideEffects": false` load when one of their bindings is first read, so importing one icon loads 4 modules and rendering it 11. It is opt-in, signed off on 2026-10-14, because it changes when a module's top-level code runs: the resolution oracle's `barrel-pkg`, which declares `"sideEffects": false` but logs as it loads, logs at first use instead of at startup. The 15 package smoke tests match Node in both modes.
7. **Suspension uses Fizz's task model, not Fibers.** Layer 6 planned to suspend components on a PHP Fiber. The port follows React instead: a suspended component throws React's `SuspenseException`, the task records the thenable, and a ping from the event loop retries it, exactly as `ReactFizzServer` works. That also gives React's ordering of pings and microtasks, which byte-exactness of async renders depends on.
8. **Not ported from Fizz**, all outside the synchronous and prerender paths of `Engine::render()`:
   - resuming and postponing (`resume*` APIs, postponed state); aborting a `prerender` renders the aborted parts as client-rendered boundaries
   - `onHeaders` and its `Link` header
   - component stacks in the `errorInfo` passed to `onError`
   - `SuspenseList` row coordination (React 19.3 does not export `SuspenseList`; its children render in order)
9. **Three-way oracle and errors.** React's edge build minifies error messages in its legacy `renderToString` path. For fixtures that throw, the three-way test compares that both sides throw, not the messages; the messages are compared against Node by the React render oracle.
10. **Real React's `prerender` prelude.** React's edge build produces the prelude on pull. A Fetch Pack `Response` built from a pull-based stream snapshots the queue and misses pulled chunks, so the exact fallback and the three-way driver read the prelude with a stream reader. The native `prerender` prelude has all of its chunks enqueued and works with `new Response(prelude).text()`.
11. **Hydration exemptions.** Fixtures that React itself cannot hydrate (invalid nesting, deliberately throwing renders, attributes React drops on the client) export `hydrate = false` and stay in the render oracles. The hydration check fails on hydration warnings and lists React's other development warnings (deprecated lifecycles, controlled fields) without failing; fixtures with async components list the expected "async Client Component" message in `hydrateWarnings`; nodes inside client-rendered (`<!--$!-->`) boundaries and `<textarea>` text are not required to be reused, since React replaces them by design.
12. **Template metadata.** A precompiled template carries a trailing metadata entry after its string parts: the profile, per part whether it starts or ends with text, and per hole its tree steps. The example in Layer 9 omits it.
13. **Async components in the HTML profile** are awaited (Layer 5). preact-render-to-string 6.8.0 renders nothing for them, so the HTML oracle fixtures avoid async components and `HtmlProfileTest` covers awaiting them.
14. **Precompile speed, and attribute holes without `jsxAttr`.** The Phase 8 gate asks for an SSR bench improvement. On `bench/ssr.jsx`, where component JavaScript dominates (the 125 lucide icons take about two thirds of a render), precompile's gain is about 4%, smaller than the drift between variants timed one after the other: measured that way it read 0.98x to 1.04x. `bench/ssr.php` therefore times precompile in a paired run (native and precompiled engines rendering back to back in alternating order, median of the per-round ratios) and requires it to beat native: 1.01x, 1.04x and 1.05x in three local runs, median 1.04x. On the same post cards without icons or tag components precompile is about 1.12x faster. To get there, attribute holes no longer allocate a `jsxAttr()` marker per render: the attribute's name is static, so it goes in the template's metadata (`a`) and the hole is the bare value. Layer 9's "Helpers module" and "Dynamic parts" describe the old shape; `jsxAttr` stays exported so templates in an existing parse cache still render. Templating the outermost elements too was measured and gave no further gain, so they stay real elements as Layer 9 specifies.
15. **Gates run locally.** Per the release instructions for this branch, the "in CI" gates (compat matrix, CI bench, docs-content workflow) were run locally with the same commands and the tracing JIT: test262 and `./bin/compat-report`, `bench/ssr.php` and `bench/microbench.js` as medians of three runs, and the docs-content check.

## Resolved questions

Signed off 2026-10-13, each as recommended:

1. **Hooks in `phasis`**: the general profile **shares the React-compatible dispatcher**, so both profiles run the same hooks with the same semantics.
2. **Default JSX import source**: **`react`**, matching the client bundle's compile settings. General templates opt in per file with `/** @jsxImportSource phasis */` or through `JsxOptions`.
3. **JSX in `.js` files**: **off by default**, opt in through `JsxOptions::$extensions`. Existing `.js` modules keep lexing exactly as today.
4. **Precompile default**: **opt-in for v1**. Reconsider default-on after the precompile oracle has been green for a release.
5. **Streaming**: **not in v1**. PHP can flush output buffers, so revisit `renderToReadableStream` if pages need early flush.
6. **Props-embedding helper**: **app-level in v1**. Consider `Engine::renderIsland()` later, rendering the HTML plus a props script with the right escaping.

## Next step

Release: merge `feat/jsx-pack`, then run the `compat-matrix`, `bench`, `jsx` and `docs-content` workflows in CI, since every gate on this branch was run locally.
