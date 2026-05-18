const toRegex = ToregexLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
log("string", toRegex("foo.bar").source);
log("regex", toRegex(/foo/).source);
log("array", toRegex(["foo", "bar"]).source);
log("test", toRegex("foo").test("foo"));
console.log(out.join("\n"));
