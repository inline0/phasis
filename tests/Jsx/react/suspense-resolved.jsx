import { Suspense } from 'react';
export default function App() {
  return (
    <div>
      <Suspense fallback={<p>loading</p>}><p>content</p></Suspense>
      text<Suspense fallback="fb">inner text</Suspense>tail
      <Suspense><Suspense fallback="x"><b>nested</b></Suspense></Suspense>
    </div>
  );
}
