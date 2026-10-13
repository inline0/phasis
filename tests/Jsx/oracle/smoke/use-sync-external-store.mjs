import { createElement as h } from "react";
import { renderToString } from "react-dom/server";
import { useSyncExternalStore } from "use-sync-external-store/shim";
import { useSyncExternalStoreWithSelector } from "use-sync-external-store/shim/with-selector";
const store = { state: { count: 7, name: "store" }, subscribe: () => () => {}, get: () => store.state };
function Counter() {
  const state = useSyncExternalStore(store.subscribe, store.get, store.get);
  const name = useSyncExternalStoreWithSelector(store.subscribe, store.get, store.get, (s) => s.name);
  return h("p", null, name, ": ", state.count);
}
console.log(renderToString(h(Counter)));
