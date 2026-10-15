import { Suspense, lazy } from 'react';
const Lazy = lazy(() => new Promise((r) => setTimeout(() => r({ default: ({ t }) => <b>lazy {t}</b> }), 2)));
export default function App() {
  return <div><Suspense fallback="waiting"><Lazy t="x" /></Suspense></div>;
}
