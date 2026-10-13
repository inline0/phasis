import { Suspense } from 'react';
const wait = (ms, v) => new Promise((r) => setTimeout(() => r(v), ms));
async function A() { const v = await wait(3, 'A'); return <div>{v}<Suspense fallback="inner loading"><B /></Suspense></div>; }
async function B() { const v = await wait(2, 'B'); return <span>{v}</span>; }
export default function App() {
  return <Suspense fallback={<p>outer loading</p>}><A /></Suspense>;
}

// React's development build reports async components on the client.
export const hydrateWarnings = ['is an async Client Component'];
