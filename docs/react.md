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

PHP renders it and embeds the props, so the client renders the same tree:

```php
$props = ['count' => 3, 'excerpt' => $excerpt];
echo '<div id="comments">' . $engine->render(__DIR__ . '/components/CommentsToggle.jsx', $props) . '</div>';
echo '<script>window.__COMMENTS__ = ' . json_encode($props, JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>';
```

On the client, standard React 19.3 tooling:

```jsx
hydrateRoot(document.getElementById('comments'), <CommentsToggle {...window.__COMMENTS__} />);
```

The text markers React needs (`<!-- -->` between adjacent text) are in the output, and `useId()` produces the same `_R_..._` ids the client generates. The client must run React 19.3: other versions generate different ids and markup.

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
