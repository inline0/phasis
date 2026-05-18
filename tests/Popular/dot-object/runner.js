const Dot = DotObject;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// Set
const o1 = {};
Dot.set("user.name", "alice", o1);
Dot.set("user.address.city", "berlin", o1);
log("set.nested", o1);

// Get / pick
const o2 = { user: { profile: { name: "Bob" } } };
log("pick", Dot.pick("user.profile.name", o2));

// Flatten / un-flatten
const flat = Dot.dot({ a: { b: 1, c: { d: 2 } } });
log("flatten", flat);

const obj = Dot.object({ "a.b": 1, "a.c.d": 2 });
log("object", obj);

// Delete
const o3 = { a: { b: 1, c: 2 } };
Dot.del("a.b", o3);
log("delete", o3);

// Copy
const src = { src: { x: 1, y: 2 } };
const dst = {};
Dot.copy("src.x", "dst.x", src, dst);
log("copy", dst);

console.log(out.join("\n"));
