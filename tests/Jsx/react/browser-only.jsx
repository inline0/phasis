import { Suspense, use } from 'react';
import { browser } from 'react-dom';
function ClientOnly() { use(browser('needs window')); return <p>{window.location.href}</p>; }
export default function App() {
  return (
    <div>
      <Suspense fallback={<p>placeholder</p>}><ClientOnly /></Suspense>
      <p>server part</p>
    </div>
  );
}
