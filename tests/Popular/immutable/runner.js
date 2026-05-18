// Runner: immutable.js — persistent data structures. List/Map/Set
// operations, structural sharing, fromJS / toJS roundtrip.

const I = Immutable;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// List
const lst = I.List([1, 2, 3, 4]);
log("list.size", lst.size);
log("list.get", lst.get(0));
log("list.push", lst.push(5).toArray());
log("list.set", lst.set(0, 99).toArray());
log("list.filter", lst.filter(x => x % 2 === 0).toArray());
log("list.map", lst.map(x => x * 2).toArray());
log("list.reduce", lst.reduce((a, b) => a + b, 0));

// Map
const m = I.Map({ a: 1, b: 2, c: 3 });
log("map.get", m.get("a"));
log("map.set", m.set("d", 4).toJS());
log("map.delete", m.delete("a").toJS());
log("map.size", m.size);
log("map.keys", m.keys && Array.from(m.keys()).sort());

// Set
const s = I.Set([1, 2, 2, 3, 3, 3]);
log("set.size", s.size);
log("set.has", s.has(2));
log("set.add", s.add(4).toArray().sort());
log("set.union", s.union(I.Set([3, 4, 5])).toArray().sort());

// fromJS / toJS
const nested = I.fromJS({ a: [1, 2, { b: "x" }] });
log("nested.getIn", nested.getIn(["a", 2, "b"]));
log("nested.toJS", nested.toJS());

// Structural sharing
const l1 = I.List([1, 2, 3]);
const l2 = l1.push(4);
log("immutable", l1.toArray());
log("derived", l2.toArray());

// Equality
log("eq.same-data", I.is(I.Map({ a: 1 }), I.Map({ a: 1 })));
log("eq.diff", I.is(I.Map({ a: 1 }), I.Map({ a: 2 })));

console.log(out.join("\n"));
