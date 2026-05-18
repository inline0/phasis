// Runner: fast-deep-equal — recursive structural equality. Tests
// cover primitives, plain objects, nested arrays, Map / Set, Date,
// RegExp, and the standard gotchas (NaN, +0/-0, mixed types).

const eq = Eq;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// Primitives
log("prim.num", eq(42, 42));
log("prim.num.no", eq(42, 43));
log("prim.str", eq("hello", "hello"));
log("prim.str.no", eq("hello", "world"));
log("prim.bool", eq(true, true));
log("prim.null", eq(null, null));
log("prim.undef", eq(undefined, undefined));
log("prim.null.undef", eq(null, undefined));
log("prim.NaN", eq(NaN, NaN));
log("prim.plus0.minus0", eq(0, -0));

// Plain objects
log("obj.empty", eq({}, {}));
log("obj.simple", eq({ a: 1, b: 2 }, { a: 1, b: 2 }));
log("obj.order", eq({ a: 1, b: 2 }, { b: 2, a: 1 }));
log("obj.diff", eq({ a: 1, b: 2 }, { a: 1, b: 3 }));
log("obj.missing", eq({ a: 1 }, { a: 1, b: 2 }));

// Nested
log("nested.match", eq(
  { a: { b: [1, 2, { c: "x" }] } },
  { a: { b: [1, 2, { c: "x" }] } },
));
log("nested.diff", eq(
  { a: { b: [1, 2, { c: "x" }] } },
  { a: { b: [1, 2, { c: "y" }] } },
));

// Arrays
log("arr.match", eq([1, 2, 3], [1, 2, 3]));
log("arr.diff-len", eq([1, 2, 3], [1, 2, 3, 4]));
log("arr.order", eq([1, 2, 3], [3, 2, 1]));

// Date
log("date.match", eq(new Date(2020, 0, 1), new Date(2020, 0, 1)));
log("date.diff", eq(new Date(2020, 0, 1), new Date(2020, 0, 2)));

// RegExp
log("regex.match", eq(/abc/g, /abc/g));
log("regex.diff-flags", eq(/abc/g, /abc/i));

// Mixed types
log("mixed", eq([1, 2], { 0: 1, 1: 2, length: 2 }));

console.log(out.join("\n"));
