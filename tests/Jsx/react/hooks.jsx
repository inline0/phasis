import { useState, useReducer, useMemo, useCallback, useRef, useEffect, useLayoutEffect, useInsertionEffect, useImperativeHandle, useDebugValue, useDeferredValue, useTransition, useSyncExternalStore, useOptimistic, useActionState, useEffectEvent, useContext, createContext } from 'react';
const C = createContext(0);
function reducer(s, a) { return s + a; }
export default function App() {
  const [a, setA] = useState(() => 'lazy');
  const [b] = useState(2);
  const [r] = useReducer(reducer, 1, (x) => x * 10);
  const [r2] = useReducer(reducer, 7);
  const m = useMemo(() => a + b, [a, b]);
  const cb = useCallback(() => 1, []);
  const ref = useRef(5);
  // Effects never run on the server; in the browser they do, harmlessly.
  const onServer = typeof window === 'undefined';
  useEffect(() => { if (onServer) throw new Error('effect ran'); });
  useLayoutEffect(() => { if (onServer) throw new Error('layout effect ran'); });
  useInsertionEffect(() => { if (onServer) throw new Error('insertion effect ran'); });
  const handle = useRef(null);
  useImperativeHandle(handle, () => ({}));
  useDebugValue('x');
  const d = useDeferredValue('final', 'initial');
  const d2 = useDeferredValue('only');
  const [pending, start] = useTransition();
  const ext = useSyncExternalStore(() => () => {}, () => 'client', () => 'server');
  const [opt] = useOptimistic('optimistic');
  const [state, action, isPending] = useActionState(async (s) => s, 'action-state');
  const ev = useEffectEvent(() => 1);
  const c = useContext(C);
  return (
    <ul>
      <li>{a}</li><li>{b}</li><li>{r}</li><li>{r2}</li><li>{m}</li><li>{typeof cb}</li><li>{ref.current}</li>
      <li>{d}</li><li>{d2}</li><li>{String(pending)}</li><li>{typeof start}</li><li>{ext}</li><li>{opt}</li>
      <li>{state}</li><li>{typeof action}</li><li>{String(isPending)}</li><li>{typeof ev}</li><li>{c}</li><li>{typeof setA}</li>
    </ul>
  );
}
