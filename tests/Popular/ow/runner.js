const ow = OwLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
function check(label, fn) {
  try { fn(); log(label, "ok"); }
  catch (e) { log(label, e.message ? e.message.split("\n")[0] : String(e)); }
}
check("string.ok", () => ow("hello", ow.string));
check("string.bad", () => ow(42, ow.string));
check("number.ok", () => ow(42, ow.number.integer));
check("number.bad", () => ow(3.14, ow.number.integer));
check("array.min", () => ow([1, 2], ow.array.minLength(3)));
check("string.length", () => ow("hello", ow.string.minLength(3)));
check("string.short", () => ow("hi", ow.string.minLength(3)));
console.log(out.join("\n"));
