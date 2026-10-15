import { useState, useReducer } from 'react';
function Counter() {
  const [n, setN] = useState(0);
  if (n < 3) setN(n + 1);
  return <p>{n}</p>;
}
function Batched() {
  const [n, setN] = useState(0);
  const [r, dispatch] = useReducer((s, a) => s + a, 0);
  if (n === 0) { setN((x) => x + 5); setN((x) => x * 2); dispatch(3); dispatch(4); }
  return <p>{n},{r}</p>;
}
export default function App() { return <div><Counter /><Batched /></div>; }
