// Sourced from web-platform-tests/wpt: html/webappapis/structured-clone/
// Spec: https://html.spec.whatwg.org/multipage/structured-data.html#structured-cloning

test(() => {
  assert_equals(structuredClone(42), 42);
  assert_equals(structuredClone("hi"), "hi");
  assert_equals(structuredClone(true), true);
  assert_equals(structuredClone(null), null);
  assert_equals(structuredClone(undefined), undefined);
  assert_equals(structuredClone(123n), 123n);
}, "structuredClone: primitives pass through");

test(() => {
  const a = { x: 1, y: { z: 2 } };
  const b = structuredClone(a);
  assert_not_equals(b, a, "different identity");
  assert_not_equals(b.y, a.y, "nested object new identity");
  assert_equals(b.x, 1);
  assert_equals(b.y.z, 2);
  a.y.z = 99;
  assert_equals(b.y.z, 2, "modifying source does not affect clone");
}, "structuredClone: deep clone");

test(() => {
  const a = { name: "self" };
  a.self = a;
  const b = structuredClone(a);
  assert_not_equals(b, a);
  assert_equals(b.self, b, "cycle preserved with same-identity self-link");
}, "structuredClone: cycle preservation");

test(() => {
  const arr = [1, [2, [3, [4]]]];
  const clone = structuredClone(arr);
  assert_not_equals(clone, arr);
  assert_equals(clone[1][1][1][0], 4);
}, "structuredClone: nested arrays");

test(() => {
  const d = new Date(1234567890000);
  const c = structuredClone(d);
  assert_not_equals(c, d);
  assert_equals(c.getTime(), 1234567890000);
  assert_true(c instanceof Date);
}, "structuredClone: Date");

test(() => {
  const r = /abc/gi;
  const c = structuredClone(r);
  assert_not_equals(c, r);
  assert_equals(c.source, "abc");
  assert_equals(c.flags, "gi");
}, "structuredClone: RegExp");

test(() => {
  const m = new Map([["a", 1], ["b", 2]]);
  const c = structuredClone(m);
  assert_not_equals(c, m);
  assert_equals(c.get("a"), 1);
  assert_equals(c.size, 2);
}, "structuredClone: Map");

test(() => {
  const s = new Set([1, 2, 3]);
  const c = structuredClone(s);
  assert_not_equals(c, s);
  assert_true(c.has(2));
  assert_equals(c.size, 3);
}, "structuredClone: Set");

test(() => {
  const ab = new ArrayBuffer(8);
  new Uint8Array(ab).set([1, 2, 3, 4]);
  const c = structuredClone(ab);
  assert_not_equals(c, ab);
  assert_equals(c.byteLength, 8);
  assert_equals(new Uint8Array(c)[0], 1);
  // Original NOT detached when no transfer list.
  assert_equals(ab.byteLength, 8);
}, "structuredClone: ArrayBuffer (no transfer)");

test(() => {
  const ab = new ArrayBuffer(8);
  new Uint8Array(ab).set([1, 2, 3, 4]);
  const c = structuredClone(ab, { transfer: [ab] });
  // Transferred: source detached, clone has the bytes.
  assert_equals(ab.byteLength, 0);
  assert_equals(c.byteLength, 8);
  assert_equals(new Uint8Array(c)[0], 1);
}, "structuredClone: ArrayBuffer with transfer");

test(() => {
  assert_throws_dom("DataCloneError",
    () => structuredClone(() => {}),
    "function uncloneable");
  assert_throws_dom("DataCloneError",
    () => structuredClone(Symbol("x")),
    "symbol uncloneable");
  assert_throws_dom("DataCloneError",
    () => structuredClone(new WeakMap()),
    "weakmap uncloneable");
}, "structuredClone: uncloneable values throw DataCloneError");
