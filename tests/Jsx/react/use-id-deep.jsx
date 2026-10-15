import { useId } from 'react';
function Leaf() { return <i id={useId()} />; }
function Tree({ depth }) {
  if (depth === 0) return <Leaf />;
  return <div>{Array.from({ length: 3 }, (_, i) => <Tree key={i} depth={depth - 1} />)}</div>;
}
export default function App() { return <Tree depth={7} />; }
