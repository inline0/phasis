import { Suspense, use } from 'react';
const ready = Promise.resolve('already resolved');
function Data() { return <p>{use(ready)}</p>; }
export default function App() {
  return <Suspense fallback={<i>fallback</i>}><Data /></Suspense>;
}
