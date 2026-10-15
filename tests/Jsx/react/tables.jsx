import { Suspense, use } from 'react';
const p = new Promise((r) => setTimeout(() => r('cell'), 2));
function Cell() { return <td>{use(p)}</td>; }
export default function App() {
  return (
    <table>
      <colgroup><col span={2} /></colgroup>
      <thead><tr><th>h</th></tr></thead>
      <tbody>
        <tr><td>1</td><Suspense fallback={<td>…</td>}><Cell /></Suspense></tr>
      </tbody>
    </table>
  );
}
