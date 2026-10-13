import { Suspense, use } from 'react';
const cache = new Map();
function load(key, ms) {
  if (!cache.has(key)) cache.set(key, new Promise((r) => setTimeout(() => r('data:' + key), ms)));
  return cache.get(key);
}
function Data({ k, ms }) { return <p>{use(load(k, ms))}</p>; }
export default function App() {
  return (
    <div>
      <Suspense fallback={<i>loading a</i>}><Data k="a" ms={5} /></Suspense>
      <Suspense fallback={<i>loading b</i>}><Data k="b" ms={1} /><Data k="c" ms={3} /></Suspense>
    </div>
  );
}
