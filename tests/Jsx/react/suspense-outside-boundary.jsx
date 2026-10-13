import { use } from 'react';
const p = new Promise((r) => setTimeout(() => r('late'), 2));
function Data() { return <p>{use(p)}</p>; }
export default function App() { return <div><Data /></div>; }
