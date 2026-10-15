import { useState } from 'react';
export default function App() {
  const [n, setN] = useState(0);
  setN(n + 1);
  return <p>{n}</p>;
}
