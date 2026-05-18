// valtio — proxy-based state with snapshot semantics.
const { proxy, snapshot, subscribe } = ValtioBundle.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const state = proxy({ count: 0, items: [], nested: { x: 1 } });

const events = [];
const unsub = subscribe(state, () => events.push("count=" + state.count + " items.len=" + state.items.length));

state.count = 5;
state.count = 10;
state.items.push("a");
state.items.push("b");
state.nested.x = 99;

// Allow microtasks to drain
setTimeout(() => {
  unsub();
  log("snap", snapshot(state));
  log("events.count", events.length);

  // Snapshot freeze
  const snap1 = snapshot(state);
  state.count = 999;
  const snap2 = snapshot(state);
  log("snap1.frozen", snap1.count);
  log("snap2.fresh", snap2.count);

  console.log(out.join("\n"));
}, 0);
