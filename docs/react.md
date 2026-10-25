---
title: "React"
description: "The native React 19.3 server renderer: byte-identical to react-dom, hydratable on the client, with hooks, Suspense, useId and resource hints."
path: "react"
order: 86
section: "JSX"
meta_title: "React"
meta_description: "The native React 19.3 server renderer: byte-identical to react-dom, hydratable on the client, with hooks, Suspense, useId and resource hints."
---

# React

`react`, `react/jsx-runtime`, `react-dom`, `react-dom/server` and `react-dom/static` are built-in modules implemented in PHP. They port React 19.3's server renderer (Fizz), so the HTML is byte-identical to `react-dom@19.3.1`, and React 19.3 on the client hydrates it. Only your components run as JavaScript; no React source is parsed or executed.

## Rendering a component for hydration

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

For a matched server render and hydration payload, capture public data with
`renderForHydration()`. PHP needs no Node process:

```php
use Phasis\Engine;
use Phasis\Hydration\HydrationOptions;

$engine = new Engine();
try {
    $root = $engine->renderForHydration(
        __DIR__ . '/components/CommentsToggle.jsx',
        ['count' => 3, 'excerpt' => $excerpt],
        options: new HydrationOptions(
            rootId: 'comments',
            entryId: 'comments',
            buildId: $assetBuildId,
            identifierPrefix: 'comments-',
        ),
    );
    echo $root->toHtml();
} finally {
    $engine->close();
}
```

An external browser entry statically imports the same component module and the
shipped helper. Bundle this with the host's existing esbuild or other tooling:

```jsx
import * as Comments from './components/CommentsToggle.jsx';
import { hydrate } from './vendor/phasis/phasis/browser/hydrate.mjs';

hydrate(document.getElementById('comments'), Comments, {
  entryId: 'comments',
  buildId: ASSET_BUILD_ID, // Supplied by this build, matching PHP's identity.
  onError: reportTransportError,
  onRecoverableError: reportReactError,
  onCaughtError: reportReactError,
  onUncaughtError: reportReactError,
});
```

The helper is a runtime asset of the **Composer** package, at
`vendor/phasis/phasis/browser/hydrate.mjs`, with `hydrate.d.mts` declarations.
The example assumes an entry in the project root; adjust the relative path or
configure an explicit bundler alias for that installed file. There is no Phasis
npm package or implicit bare-specifier resolution. Its peer imports (`react`,
`react-dom`, `react-dom/client`) resolve through the consumer's bundler. Install
one compatible React/React DOM pair, currently **19.3.1**. The helper does not
bundle another copy. Node is development/build tooling, never the visitor's
execution backend.

`RenderedRoot` exposes readonly `html`, `payload` and `options`. The payload is
captured **before** the component module executes. Phasis renders from those
captured values; later PHP mutation, JS mutation during render, or a component's
`toJSON` cannot alter the stored snapshot. The component sees ordinary props and
can provide them through its own React context. No automatic context or global
application singleton is created. PHP-to-JS callable/object interop remains
available through the existing `render()` and `renderSource()` APIs.

### Data contract

- Root props are a PHP map or a plain `stdClass`; empty PHP root props become
  `{}`. A nonempty root list is rejected. Nested list arrays remain JSON arrays;
  maps become JSON objects. Use `new stdClass()` for a nested empty object.
- Values are null, booleans, valid UTF-8 strings, finite doubles, safe PHP
  integers, arrays and exact `stdClass` instances. Callable-looking strings and
  arrays stay data. Arbitrary objects, serializers, closures, resources, cycles,
  invalid UTF-8 and structures deeper than 128 levels are rejected before any
  component module executes. Shared acyclic objects are allowed.
- PHP integers must fit `[-9007199254740991, 9007199254740991]`; use strings for
  larger exact integers. PHP floats already have IEEE 754 double precision and
  keep that precision, including signed zero. They may represent approximate
  large numbers. Encoding and rendering temporarily use round-trip
  `serialize_precision=-1` and restore the host setting, including on failure.
- Data-only JSON conversion preserves object/array distinctions, null and keys
  without invoking PHP callbacks, JS `toJSON`, or JSON revivers. Normal React
  `createElement` rules then apply to the root: reserved props and class
  `defaultProps` behave as on the client. Use a named `data` prop for application
  fields that collide with React's own props.

### Transport, identity and diagnostics

The versioned payload contains only protocol version, public entry/export/build
identity, root ID, React version, identifier prefix and captured props. The server
component path and executable source are never included. Public identities are
provided by the host; do not use a secret or server path as an identity.

`toHtml()` emits a div with an escaped ID and an adjacent inert
`<script type="application/json" data-phasis-state="…">`. Script-safe JSON uses
JSON escaping, not HTML entities. State stays outside React-managed children.
For custom element-root markup, place `html` inside the host's root and `payload`
unchanged in that same adjacent state tag; escape both attribute IDs separately.
Do not insert the state inside the root or format whitespace around its children.

The browser helper validates the protocol and metadata, unique root ID, exact
React/React DOM version, data shape, expected entry/build/export and an own
component export of the statically imported module. For a named export, supply the same
`export` name to PHP and the browser. No payload value drives an import or runs
as code. Duplicate initialization, including another helper bundle, is rejected.
After successful initialization the root carries `data-phasis-hydrated`.

Transport and identity failures throw `HydrationError` (with a `code`) and invoke
the optional `onError` callback, preserving server HTML. Actual React diagnostics
use the supplied `onRecoverableError`, `onCaughtError` and `onUncaughtError` hooks,
or React's own defaults. There is no silent `createRoot` fallback. Fix hydration
mismatches; React's documented recovery can replace mismatched nodes.

The root DOM ID and React's `identifierPrefix` serve different purposes.
`identifierPrefix` reaches native Fizz and `hydrateRoot` unchanged. Use distinct
root IDs **and** prefixes for independent roots in one document, and keep all
identities and browser assets paired when caching or deploying HTML.

### Element roots and CSP

The convenience API supports an element root only. Full HTML documents are
rejected; existing `render()` still supports them. It exposes no streaming,
bootstrap-script, nonce, or other renderer options. Do not pass extra options
expecting Fizz to consume them.

The state tag is inert, and the executable hydration entry should be an external
hashed asset. This alone is **not** a complete CSP guarantee: React can emit
executable instructions for Suspense or resource behavior. Nonce propagation
and strict-CSP qualification for those instructions are unsupported by this
API. The host owns response headers, asset policy and cache/nonce lifetimes.

## Exports

| Module | Exports |
|---|---|
| `react` | `Activity`, `Children`, `Component`, `Fragment`, `Profiler`, `PureComponent`, `StrictMode`, `Suspense`, `ViewTransition`, `addTransitionType`, `cache`, `cacheSignal`, `cloneElement`, `createContext`, `createElement`, `createRef`, `forwardRef`, `isValidElement`, `lazy`, `memo`, `startTransition`, `unstable_useCacheRefresh`, `use`, `useActionState`, `useCallback`, `useContext`, `useDebugValue`, `useDeferredValue`, `useEffect`, `useEffectEvent`, `useId`, `useImperativeHandle`, `useInsertionEffect`, `useLayoutEffect`, `useMemo`, `useOptimistic`, `useReducer`, `useRef`, `useState`, `useSyncExternalStore`, `useTransition`, `version` (`"19.3.1"`) |
| `react/jsx-runtime` | `jsx`, `jsxs`, `Fragment` |
| `react-dom` | `browser`, `createPortal`, `flushSync`, `preconnect`, `prefetchDNS`, `preinit`, `preinitModule`, `preload`, `preloadModule`, `requestFormReset`, `unstable_batchedUpdates`, `useFormState`, `useFormStatus`, `version` |
| `react-dom/server` | `renderToString`, `renderToStaticMarkup`, `version` |
| `react-dom/static` | `prerender` (resolves to `{ prelude }`, a `ReadableStream`), `version` |

Each module also has a default export equal to its export object, the way bundlers expose React's CommonJS packages (`import React from 'react'`). The streaming and resume APIs (`renderToReadableStream`, `renderToPipeableStream`, `resume`, `resumeToPipeableStream`, `prerenderToNodeStream`, `resumeAndPrerender`, `resumeAndPrerenderToNodeStream`) are exported so modules that import them still link, but calling one throws a `TypeError` naming the limitation.

## What renders

- **Components**: function components; class components (`Component`, `PureComponent`) with the constructor, `getDerivedStateFromProps`, `UNSAFE_componentWillMount`, `render`, `static contextType` and `defaultProps`; `memo`, `forwardRef` and `lazy`.
- **Hooks** follow React's server semantics: state hooks return their initial state and render-phase updates re-run the component; `useMemo` and `useCallback` compute once; effects never run; `useSyncExternalStore` reads `getServerSnapshot`; `useContext` and `use()` read context; `use()` also suspends on promises.
- **Context** in all three forms: `<Ctx value>`, `<Ctx.Provider value>` and `<Ctx.Consumer>`.
- **Suspense**: a resolved boundary renders `<!--$-->...<!--/$-->`; one that fell back to client rendering renders `<!--$!--><template></template>fallback<!--/$-->`.
- **Async components and `use(promise)`**: `Engine::render()` and `prerender` wait for them. Sync `renderToString` renders the fallback of the nearest `Suspense` boundary, and throws "A component suspended while responding to synchronous input" when there is none.
- **Client-only parts**: `use(browser(reason))` from `react-dom` (React 19.3) asks for a component to render only in the browser. Inside `Suspense` the server renders the fallback in a client-render boundary without reporting an error; outside one the render fails.
- **Documents**: rendering `<html>`, `<head>` and `<body>` hoists `<title>`, `<meta>`, `<link>`, stylesheets by `precedence` and async scripts the way React does, and `prerender` adds `<!DOCTYPE html>`.
- **Resource hints**: images emit `<link rel="preload" as="image">` ahead of the content, and the `react-dom` `preload`/`preinit` family emits its hints.
- **Attributes and escaping**: React's attribute table (`className`, `htmlFor`, `readOnly=""`), `style` objects without a trailing `;`, `&`, `<`, `>`, `"` and `'` (as `&#x27;`) escaped, function props dropped.
- **URL safety**: `javascript:` URLs in `href`, `src`, `action` and `formAction` become React's blocking URL, exactly as React does.

## Render APIs

From PHP, use `Engine::render()` (see [JSX](/docs/jsx)); it returns `prerender`'s HTML as a string. From JavaScript, the `react-dom` APIs behave as in Node:

```jsx
import { renderToString } from 'react-dom/server';
import { prerender } from 'react-dom/static';

const html = renderToString(<App />);
const { prelude } = await prerender(<App />);
const page = await new Response(prelude).text();
```

## Component guidelines

The fastest and safest components are presentational: props in, HTML out.

- Load data in PHP and pass it in as props. Async components work, but they move I/O into the slower JavaScript layer. React's development client logs "is an async Client Component" for them, so keep data-fetching components out of the parts the client hydrates.
- Keep props JSON-friendly, with dates as strings. The client must hydrate with exactly the same props.
- Render the same thing on server and client: no `window`, `document` or `localStorage` during render, no `Date.now()`, `Math.random()` or locale-dependent formatting. Put browser-only work in `useEffect`, or behind `use(browser(reason))` inside `Suspense`.
- Prefer packages that declare `"sideEffects": false` for icon sets and other large libraries; only the modules you use then load (see [Modules](/docs/modules)).

Identical props always give identical HTML, so the output can be cached keyed by the props.

## How it is verified

- **Render oracle**: every React fixture in `tests/Jsx/react` renders byte-identical to `react-dom@19.3.1` in Node with `renderToString`, `renderToStaticMarkup` and `prerender`, and through `Engine::render()`.
- **Hydration**: `tests/Jsx/oracle/hydrate.mjs` hydrates Phasis's output with React 19.3's client in jsdom, production and development builds, and requires zero recoverable errors, zero hydration warnings and every server node reused.
- **Three-way**: real React 19.3 running inside Phasis agrees with both.
- **Captured-data consumer**: `node tests/Jsx/oracle/hydration-contract.mjs`
  installs the committed Composer archive into a separate project, bundles its
  shipped helper with the same components and one peer React copy, and verifies
  development/production hydration, node reuse, connected interaction, prefixes,
  data mutation and rejection/diagnostic behavior. CI also runs it on PHP 8.2,
  8.3 and 8.4, without installing development-only PHP tooling.

## Exact fallback

Libraries that reach into React internals the native implementation does not cover can run against real React instead. Map the built-ins to an ES module build of React 19.3 with an import map:

```php
$dir = __DIR__ . '/react-19.3.1/'; // e.g. tests/Jsx/oracle/build-vendor.mjs output
$engine->setImportMap([
    'react' => $dir . 'react.mjs',
    'react/jsx-runtime' => $dir . 'jsx-runtime.mjs',
    'react-dom' => $dir . 'react-dom.mjs',
    'react-dom/server' => $dir . 'server.mjs',
    'react-dom/static' => $dir . 'static.mjs',
]);
$html = $engine->getExport(__DIR__ . '/render.mjs', 'html');
```

It is byte-identical but much slower (about 12x on the SSR bench), and `render()` keeps using the native renderer, so render through a module of your own as above. React's edge build produces its `prerender` prelude on pull; read it with a stream reader, since a `Response` built from it does not wait for pulled chunks.

## Not supported

- Streaming SSR and resuming (the APIs above throw).
- React Server Components: `"use client"` and `"use server"`, the Flight protocol, server actions.
- Aborting a `prerender` with a signal renders the aborted parts as client-rendered boundaries rather than tracking them as postponed.
- The `errorInfo` passed to `onError` has no `componentStack`.
- React versions other than 19.3.1.
