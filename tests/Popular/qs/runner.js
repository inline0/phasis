const qs = Qs;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("parse.basic", qs.parse("a=1&b=2"));
log("parse.nested", qs.parse("a[b]=1&a[c]=2"));
log("parse.array", qs.parse("a[]=1&a[]=2&a[]=3"));
log("parse.indexed", qs.parse("a[0]=x&a[1]=y"));
log("parse.deep", qs.parse("a[b][c]=deep"));
log("parse.bracket", qs.parse("a=1&b=2&c=3"));
log("parse.encoded", qs.parse("a=hello%20world&b=cafe%CC%81"));

log("stringify.basic", qs.stringify({ a: 1, b: 2 }));
log("stringify.nested", qs.stringify({ a: { b: 1, c: 2 } }));
log("stringify.array.indices", qs.stringify({ a: ["x", "y"] }));
log("stringify.array.brackets", qs.stringify({ a: ["x", "y"] }, { arrayFormat: "brackets" }));
log("stringify.array.repeat", qs.stringify({ a: ["x", "y"] }, { arrayFormat: "repeat" }));
log("stringify.delim", qs.stringify({ a: 1, b: 2 }, { delimiter: ";" }));
log("stringify.encode", qs.stringify({ name: "café" }));
log("stringify.encode.disable", qs.stringify({ name: "café" }, { encode: false }));

// roundtrip
const original = { user: { id: 1, name: "alice" }, tags: ["a", "b"] };
log("roundtrip.matches", JSON.stringify(qs.parse(qs.stringify(original))) === JSON.stringify(original));

console.log(out.join("\n"));
