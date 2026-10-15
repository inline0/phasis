// A short-circuited optional chain is plain undefined wherever its value
// goes: bindings read by other functions, arguments, return values,
// array and object literals, comparisons and typeof.
const o = {};
const n = null;
const stored = o.p?.x;
const deep = n?.a.b.c;
const call = o.missing?.();
const read = () => stored;
const check = (v) => [v === undefined, v == null, typeof v, String(v)];
console.log(JSON.stringify(check(stored)), JSON.stringify(check(deep)), JSON.stringify(check(call)));
console.log((() => stored === undefined)(), (function () { return deep === void 0; })(), read() === undefined);
console.log([n?.x][0] === undefined, { v: n?.x }.v === undefined, Object.is(n?.x, undefined));
// The shape Effect uses to detect an optional host API.
const Resource = (() => { try { return globalThis.missingHost?.getModule?.("x")?.Resource; } catch { return undefined; } })();
const capture = () => Resource === undefined ? undefined : new Resource("x");
console.log(String(capture()));
// Chains still short-circuit as a whole and keep `this` for method calls.
const obj = { v: 3, f() { return this.v; } };
console.log(String(n?.a.b()), String(n?.["k"].m), obj?.f(), String(obj.g?.()), obj?.["f"]());
console.log(delete n?.a, delete n?.a.b);
