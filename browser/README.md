# Browser hydration

Render with `Engine::renderForHydration()` and explicit
`Phasis\Hydration\HydrationOptions`, then emit the returned `toHtml()`. Hydrate
with standard React; no Phasis browser import is required:

```js
import { createElement } from 'react';
import { hydrateRoot } from 'react-dom/client';
import Root from './components/Root.jsx';

const state = JSON.parse(
    document.querySelector('script[data-phasis-state="site"]').textContent,
);

hydrateRoot(document.getElementById('site'), createElement(Root, state.props), {
    identifierPrefix: state.identifierPrefix,
});
```

Bundle the external entry with the consumer's existing tooling and one
React/React DOM 19.3.1 pair. Use the exact captured props and identifier prefix
with the same component and matching browser build. The application's bootstrap
owns validation, initialization and React error handling.

## Optional helper

This directory ships an optional bootstrap helper in `phasis/phasis`'s Composer
archive. It wraps React's `hydrateRoot` with transport and identity checks.
There is no Phasis npm package. Its peer React imports resolve through the same
consumer bundler and React pair; `hydrate.d.mts` supplies TypeScript declarations.
From an external entry in the project root, the helper can replace the direct
React call above:

```js
import * as Components from './components/Root.jsx';
import { hydrate } from './vendor/phasis/phasis/browser/hydrate.mjs';

hydrate(document.getElementById('site'), Components, {
    entryId: 'site',
    buildId: BUILD_ID,
    onError: reportTransportError,
    onRecoverableError: reportReactError,
});
```

The entry/build/export identities must match PHP. The helper consumes the
adjacent inert state tag, validates its metadata and calls `hydrateRoot` with
the captured props and identifier prefix. Transport failures throw
`HydrationError` and preserve server HTML. React hydration failures use React's
diagnostic hooks. No payload-driven imports or `createRoot` fallback occur.

Only element roots are qualified. Renderer nonce options and full-document
convenience transport are unsupported. Inert JSON alone does not qualify all
React-emitted instructions for a strict CSP. The host owns headers and caching.

See the [complete PHP, data, transport and lifecycle contract](https://github.com/inline0/phasis/blob/main/docs/react.md).
