// jotai — primitive + derived atoms.
const { atom, createStore } = JotaiBundle.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const store = createStore();

// Primitive atom
const countAtom = atom(0);
const events = [];
store.sub(countAtom, () => events.push(store.get(countAtom)));
store.set(countAtom, 1);
store.set(countAtom, 5);
store.set(countAtom, 42);
log("primitive.events", events);
log("primitive.final", store.get(countAtom));

// Derived atom (read-only)
const doubledAtom = atom((get) => get(countAtom) * 2);
log("derived.initial", store.get(doubledAtom));
store.set(countAtom, 7);
log("derived.7", store.get(doubledAtom));

// Writable derived atom
const sumAtom = atom(
  (get) => get(countAtom) + 100,
  (get, set, n) => set(countAtom, n - 100),
);
log("writable.read", store.get(sumAtom));
store.set(sumAtom, 200);
log("writable.afterSet.count", store.get(countAtom));
log("writable.afterSet.sum", store.get(sumAtom));

console.log(out.join("\n"));
