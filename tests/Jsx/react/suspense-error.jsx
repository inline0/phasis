import { Suspense } from 'react';
function Boom() { throw new Error('boom'); }
export default function App() {
  return (
    <div>
      <Suspense fallback={<p>fallback shown</p>}><span>before</span><Boom /></Suspense>
      <p>after</p>
    </div>
  );
}

// Throws on the client too, so there is nothing to hydrate cleanly.
export const hydrate = false;
