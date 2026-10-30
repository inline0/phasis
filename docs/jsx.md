---
title: "JSX"
description: "Render JSX components to HTML from PHP: .jsx modules, Engine::render, the React and HTML profiles, and precompile."
path: "jsx"
order: 85
section: "JSX"
meta_title: "JSX"
meta_description: "Render JSX components to HTML from PHP: .jsx modules, Engine::render, the React and HTML profiles, and precompile."
---

# JSX

Phasis renders JSX components to HTML on the server, in pure PHP. Components import what they would import in the client bundle, and nothing is built first.

[TypeScript components](/docs/typescript) work automatically with `.tsx` files
through the same renderer and hydration APIs.

```jsx
// components/PostCard.jsx
import cx from 'classnames';                   // CommonJS package from node_modules
import { MessageCircle } from 'lucide-react';  // only this icon's modules load
import Avatar from '@/components/Avatar';      // jsconfig.json alias, no extension
import './PostCard.css';                       // an empty module on the server

export default function PostCard({ post }) {
  return (
    <article className={cx('post-card', { featured: post.featured })}>
      <Avatar name={post.author} />
      <h2>{post.title}</h2>
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

There are two renderers. The **React profile** is a native port of React 19.3's server renderer: its HTML is byte-identical to `react-dom@19.3.1`, so React hydrates it on the client (see [React](/docs/react)). The **HTML profile** is for server-only HTML such as emails and feeds: byte-identical to `preact-render-to-string` 6.8.1, with no hydration markers. Both share one element model and the same hooks.

## Which files get JSX

JSX is a module feature. Module paths ending in one of `JsxOptions::$extensions` (`.jsx` by default) are parsed with JSX, whether they are loaded by `render()`, `execFile()`, an `import`, or `evalAsModule()`. `.js` modules keep plain JavaScript lexing unless you opt in:

```php
use Phasis\Parser\Jsx\JsxOptions;

$engine->setJsxOptions(new JsxOptions(extensions: ['.jsx', '.js']));
```

`eval()`, `new Function()` and classic scripts never accept JSX.

## How JSX compiles

JSX is lowered to function calls at parse time; the interpreter never sees a JSX node. The output matches esbuild exactly:

| `JsxMode` | Output |
|---|---|
| `Automatic` (default) | `jsx()` / `jsxs()` imported from `<importSource>/jsx-runtime`, as in React 17+ |
| `Classic` | `React.createElement(...)`, or the factory you configure |
| `Preserve` | keeps JSX nodes in the AST, for tooling (see [Parser](/docs/parser)) |
| `Precompile` | static HTML becomes string templates (see below) |

```php
$engine->setJsxOptions(new JsxOptions(
    mode: JsxMode::Automatic,
    importSource: 'react',          // or 'phasis' for the HTML profile
    factory: 'React.createElement', // classic mode
    fragment: 'React.Fragment',     // classic mode
));
```

The usual per-file pragmas work in comments: `@jsxImportSource`, `@jsxRuntime classic`, `@jsx` and `@jsxFrag`.

## Rendering from PHP

```php
public function render(string $path, array $props = [], string $export = 'default', ?RenderProfile $profile = null): string
public function renderSource(string $source, array $props = [], string $export = 'default', ?string $path = null, ?RenderProfile $profile = null): string
```

`render()` loads the module, converts `$props` to JavaScript, renders the named export, and runs the event loop until the render settles.

- **Profile.** The given `RenderProfile`, else the module's JSX import source: `react` renders with the React profile, `phasis` with the HTML profile.
- **Waiting.** The React profile follows React's `prerender`: it waits for async components and for `use(promise)` inside `Suspense`. The HTML profile awaits async components.
- **Errors.** A render error rethrows as `Phasis\Exceptions\JsThrowable`. A render still pending once the event loop is empty throws `RuntimeError` ("render did not settle") instead of hanging.
- **`renderSource()`** does the same for module source in memory; `$path` anchors relative imports and decides whether the source gets JSX by its extension (a `.jsx` path in the working directory by default).

```php
use Phasis\RenderProfile;

$html = $engine->renderSource(
    "export default ({ name }) => <p>Hello {name}</p>;",
    ['name' => 'Ada'],
);
// <p>Hello <!-- -->Ada</p>

$html = $engine->render($path, $props, 'Footer', RenderProfile::Html);
```

Props should be JSON-friendly (strings, numbers, booleans, arrays, plain objects). The client must hydrate with exactly the same props.

## The HTML profile

For HTML nobody hydrates, mark the file with the `phasis` import source:

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

- **`phasis/jsx-runtime`** exports `jsx`, `jsxs` and `Fragment`.
- **`phasis`** exports `createElement`, `Fragment`, `createContext`, the server hooks (`useState`, `useReducer`, `useMemo`, `useCallback`, `useRef`, `useContext`, `useId`, `use`, the effect hooks, which never run, and the rest), `renderToString`, `renderToStringAsync` and `raw`.
- **Output** is byte-identical to preact-render-to-string 6.8.1: HTML spellings (`class`, `for`, string `style`) work as written, camelCase HTML props are lowercased, `style` objects become `prop:value;` pairs with `px`, booleans render as bare attributes, void elements as `<br/>`, and text escapes `&`, `<` and `"`.

- **`raw(html)`** inserts trusted HTML verbatim. By default it and `dangerouslySetInnerHTML` are the only unescaped paths.
- **No URL filtering.** `javascript:` URLs pass through as written, as in Preact. Sanitize untrusted URLs yourself.
- **`renderToStringAsync`** waits for components that suspend on `use(promise)`, wrapping their output in preact's `<!--$s-->` markers. Awaiting async components is a Phasis extension; preact-render-to-string renders nothing for them.

For raw-text contexts where the spelling of an empty attribute matters, PHP
callers may opt in to quoting selected empty string attributes with
`new \Phasis\HtmlRenderOptions(['value'])` as the fifth argument to
`Engine::render()`, or the sixth argument to `renderSource()`. Boolean attributes
and other names retain their normal spelling. The default remains byte-identical
to Preact. `escapeNoscriptText: true` additionally serializes text below
`<noscript>` with HTML text escaping (`&`, `<` and `>`), matching HTML
parse-and-serialize pipelines. `reactScriptText: true` writes a script's single
string child as React does, including escaping embedded script-tag spellings;
other nonempty child types fail instead of silently producing unsafe script.
Only use this option for trusted script content. Options cannot be used with the
React profile or precompiled HTML templates whose static contents would be
affected.

## Precompile

`JsxMode::Precompile` (opt-in) serializes the static HTML in an element's children at parse time, as Deno's `jsx: "precompile"` does:

```jsx
<div className="card"><h2>{title}</h2><p>Static text</p></div>
```

```js
const $$tpl_1 = ['<h2>', '</h2><p>Static text</p>', /* metadata */];
jsx("div", { className: "card", children: jsxTemplate($$tpl_1, title) });
```

- The outermost element of each JSX expression stays a real element, and so does every element passed to a component, because components may inspect what they receive.
- Components, spread props and elements React special-cases (form fields, `img`, `link`, `script`, tables, SVG) stay real elements inside holes.
- Dynamic attributes become holes, with their names in the template's metadata; inline `on*` handlers on HTML elements are dropped, since server HTML never contains them.
- For the React profile, each template part records whether it starts or ends with text and each hole its tree position, so `<!-- -->` markers and `useId` values are exactly React's.

The output is byte-identical with and without precompile; the test suite checks every React and HTML fixture both ways. The gain depends on how much of a page is static markup rather than component code: the SSR bench page, where component JavaScript dominates, renders about 4% faster (1.01x to 1.05x in paired local runs), and a page of the same post cards without icons or tag components about 12% faster.

## Performance

- **Xdebug off.** Xdebug alone makes Phasis about 5x slower, and its 512-frame nesting guard can abort deep component trees. Use the OPcache tracing JIT in production.
- **Preload.** React and the HTML renderer are PHP code in Phasis. Point `opcache.preload` at `vendor/phasis/phasis/preload.php` (see [Benchmarks](/docs/advanced/benchmarks)) and no renderer code is compiled per request.
- **Worker servers** (FrankenPHP worker mode, RoadRunner, Swoole): keep one `Engine` per worker. `render()` loads each module once and reuses it for every request. Module-level state is shared across requests, as in Node SSR servers, so keep per-request data in props or context.
- **PHP-FPM.** Components and the packages they import are parsed per request; barrel pruning keeps that to the modules actually used, and `ModuleOptions(parseCacheDir: ...)` caches parsed ASTs on disk (see [Modules](/docs/modules)).

## CLI

```bash
./vendor/bin/phasis app.jsx                          # run a module (imports resolve)
./vendor/bin/phasis --render Card.jsx --props '{"title":"Hi"}'
./vendor/bin/phasis --ast Card.jsx                   # JSX AST (Preserve mode)
```

## Limits

- TypeScript and `.tsx` are not supported; strip types first or use a server bundle.
- Bundler-specific import features (Vite's `?raw` and `?url`, SVG-as-component imports, `import.meta.glob`, webpack loaders) are not supported. Use plain imports, or bundle with JSX kept as JSX and `react`/`react-dom` external.
- `Function.prototype.toString()` on a component returns its JSX source, where a bundler would show compiled code.
