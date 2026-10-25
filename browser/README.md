# Browser hydration

This directory ships in `phasis/phasis`'s Composer archive. There is no Phasis
npm package. Resolve the helper's peer `react`, `react-dom` and `react-dom/client`
imports with the consumer's existing bundler, using one React/React DOM 19.3.1
pair. `hydrate.d.mts` supplies TypeScript declarations.

Render with `Engine::renderForHydration()` and explicit
`Phasis\Hydration\HydrationOptions`, then emit the returned `toHtml()`. From an
external browser entry in the project root:

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
