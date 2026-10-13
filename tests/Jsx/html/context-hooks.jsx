/** @jsxImportSource phasis */
import { createContext, useContext, useState, useReducer, useMemo, useCallback, useRef, useEffect, useLayoutEffect } from 'phasis';
const Theme = createContext('light');
function Show() {
  const theme = useContext(Theme);
  const [count] = useState(() => 2);
  const [n] = useReducer((s, a) => s + a, 5);
  const doubled = useMemo(() => count * 2, [count]);
  const cb = useCallback(() => 1, []);
  const ref = useRef('r');
  useEffect(() => { throw new Error('effects never run'); });
  useLayoutEffect(() => { throw new Error('layout effects never run'); });
  return <p>{theme} {count} {n} {doubled} {typeof cb} {ref.current}</p>;
}
export default function App() {
  return (
    <div>
      <Show />
      <Theme value="dark"><Show /></Theme>
      <Theme.Provider value="nested"><Theme value="inner"><Show /></Theme><Show /></Theme.Provider>
      <Theme.Consumer>{(v) => <b>{v}</b>}</Theme.Consumer>
    </div>
  );
}
