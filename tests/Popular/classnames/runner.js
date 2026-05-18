const classNames = CnLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
log("strings", classNames("foo", "bar"));
log("conditional", classNames("foo", { bar: true, baz: false }));
log("array", classNames(["foo", "bar"], { baz: true }));
log("falsy", classNames("foo", null, undefined, false, "bar"));
log("dedup.not", classNames("foo", "foo", "bar"));
console.log(out.join("\n"));
