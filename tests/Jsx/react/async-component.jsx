import { Suspense } from 'react';
async function Comments({ id }) {
  const comments = await new Promise((r) => setTimeout(() => r([{ id: 1, body: 'First ' + id }, { id: 2, body: 'Second' }]), 3));
  return <ul>{comments.map((c) => <li key={c.id}>{c.body}</li>)}</ul>;
}
export default function App() {
  return (
    <section>
      <h2>Comments</h2>
      <Suspense fallback={<p>Loading comments</p>}><Comments id="p1" /></Suspense>
    </section>
  );
}

// React's development build reports async components on the client.
export const hydrateWarnings = ['is an async Client Component'];
