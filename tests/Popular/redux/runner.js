const { createStore, combineReducers, applyMiddleware, compose, bindActionCreators } = Redux;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// Simple counter
function counter(state = 0, action) {
  switch (action.type) {
    case "INC": return state + 1;
    case "DEC": return state - 1;
    case "SET": return action.payload;
    default: return state;
  }
}

const store = createStore(counter);
log("initial", store.getState());
store.dispatch({ type: "INC" });
store.dispatch({ type: "INC" });
store.dispatch({ type: "INC" });
log("after.3xINC", store.getState());
store.dispatch({ type: "DEC" });
log("after.DEC", store.getState());
store.dispatch({ type: "SET", payload: 100 });
log("after.SET", store.getState());

// combineReducers
function todos(state = [], action) {
  if (action.type === "ADD") return [...state, action.payload];
  return state;
}
function user(state = { name: "anon" }, action) {
  if (action.type === "LOGIN") return { name: action.payload };
  return state;
}
const root = combineReducers({ todos, user });
const store2 = createStore(root);
store2.dispatch({ type: "ADD", payload: "buy milk" });
store2.dispatch({ type: "LOGIN", payload: "alice" });
log("combined", store2.getState());

// Subscribe
const events = [];
const store3 = createStore(counter);
const unsub = store3.subscribe(() => events.push(store3.getState()));
store3.dispatch({ type: "INC" });
store3.dispatch({ type: "INC" });
unsub();
store3.dispatch({ type: "INC" }); // not observed
log("subscribe.events", events);
log("after.unsub", store3.getState());

// bindActionCreators
const actions = bindActionCreators(
  { inc: () => ({ type: "INC" }), set: (n) => ({ type: "SET", payload: n }) },
  store3.dispatch,
);
actions.inc();
actions.set(42);
log("bound", store3.getState());

// compose
const addOne = (x) => x + 1;
const double = (x) => x * 2;
const composed = compose(addOne, double);
log("compose", composed(5));

console.log(out.join("\n"));
