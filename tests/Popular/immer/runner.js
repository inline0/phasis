const { produce } = Immer;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const state = { user: { name: "Alice", age: 30 }, tags: ["a", "b"] };
const next = produce(state, draft => { draft.user.age = 31; });
log("orig.unchanged", state.user.age);
log("next.updated", next.user.age);
log("user.different-ref", state.user !== next.user);
log("tags.same-ref", state.tags === next.tags);

const list = [1, 2, 3];
const list2 = produce(list, d => { d.push(4); });
log("push", list2);
log("orig.list", list);

console.log(out.join("\n"));
