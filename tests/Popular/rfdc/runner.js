const clone = Rfdc();
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const obj = { a: 1, b: [2, 3, { c: "x" }], d: { e: true } };
const cloned = clone(obj);
log("equal", JSON.stringify(cloned) === JSON.stringify(obj));
log("different.ref", cloned !== obj);
log("different.nested.ref", cloned.b !== obj.b);

cloned.b[2].c = "MUTATED";
log("isolated", obj.b[2].c === "x");

// Edge cases
log("primitive", clone(42));
log("null", clone(null));
log("arr-of-arr", clone([[1, 2], [3, 4]]));
log("date.iso", clone(new Date(0)).toISOString());

// Custom options
const cloneProto = Rfdc({ proto: true });
log("proto.clone", cloneProto({ x: 1 }).x);

console.log(out.join("\n"));
