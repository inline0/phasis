import { Suspense } from 'react';
const rows = Array.from({ length: 60 }, (_, i) => i);
export default function App() {
  return (
    <main>
      <Suspense fallback={<p>loading</p>}>
        <ul>{rows.map((i) => <li key={i}>Row number {i} with some text to make it long enough</li>)}</ul>
      </Suspense>
      <Suspense fallback={<p>small</p>}><p>small</p></Suspense>
    </main>
  );
}
