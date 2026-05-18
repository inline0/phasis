const jp = JsonPatch;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const doc = { foo: "bar", num: 1, list: [10, 20, 30] };

// applyOperation
let d1 = jp.deepClone(doc);
jp.applyOperation(d1, { op: "replace", path: "/foo", value: "baz" });
log("replace", d1);

d1 = jp.deepClone(doc);
jp.applyOperation(d1, { op: "add", path: "/added", value: 42 });
log("add", d1);

d1 = jp.deepClone(doc);
jp.applyOperation(d1, { op: "remove", path: "/foo" });
log("remove", d1);

d1 = jp.deepClone(doc);
jp.applyOperation(d1, { op: "move", from: "/foo", path: "/renamed" });
log("move", d1);

d1 = jp.deepClone(doc);
jp.applyOperation(d1, { op: "copy", from: "/foo", path: "/copied" });
log("copy", d1);

d1 = jp.deepClone(doc);
jp.applyOperation(d1, { op: "add", path: "/list/-", value: 40 });
log("append", d1.list);

d1 = jp.deepClone(doc);
const result = jp.applyOperation(d1, { op: "test", path: "/foo", value: "bar" });
log("test.ok", result.test);

// applyPatch (batch)
d1 = jp.deepClone(doc);
jp.applyPatch(d1, [
  { op: "replace", path: "/foo", value: "baz" },
  { op: "add", path: "/extra", value: true },
]);
log("batch", d1);

// validate
log("valid.ok", jp.validate([{ op: "replace", path: "/foo", value: 1 }], doc) === undefined);
log("valid.bad", jp.validate([{ op: "bad-op" }], doc) !== undefined);

// generate (compute diff)
const before = { a: 1, b: 2 };
const after = { a: 1, b: 99, c: 3 };
const observer = jp.observe(jp.deepClone(before));
Object.assign(observer.object, after);
const patches = jp.generate(observer);
log("diff", patches.map(p => ({ op: p.op, path: p.path, value: p.value })));

console.log(out.join("\n"));
