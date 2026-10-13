/** @jsxImportSource phasis */
import { use } from 'phasis';
const cache = new Map();
function load(key, ms) {
  if (!cache.has(key)) cache.set(key, new Promise((r) => setTimeout(() => r('data:' + key), ms)));
  return cache.get(key);
}
function Data({ k, ms }) { return <span>{use(load(k, ms))}</span>; }
function Box({ children }) { return <div class="box">{children}</div>; }
export default function App() {
  return (
    <main>
      <Box><Data k="a" ms={3} /></Box>
      <Box><p>sibling</p><Data k="b" ms={1} /></Box>
    </main>
  );
}
