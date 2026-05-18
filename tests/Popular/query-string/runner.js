const qs = QueryString.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("parse.basic", qs.parse("a=1&b=2"));
log("parse.empty", qs.parse(""));
log("parse.encoded", qs.parse("a=hello%20world"));
log("parse.array.repeat", qs.parse("a=1&a=2&a=3"));
log("parse.array.brackets", qs.parse("a[]=1&a[]=2", { arrayFormat: "bracket" }));
log("parse.array.indexed", qs.parse("a[0]=x&a[1]=y", { arrayFormat: "index" }));
log("parse.array.comma", qs.parse("a=1,2,3", { arrayFormat: "comma" }));
log("parse.number", qs.parse("n=42", { parseNumbers: true }));
log("parse.bool", qs.parse("on=true&off=false", { parseBooleans: true }));

log("stringify.basic", qs.stringify({ a: 1, b: 2 }));
log("stringify.array.bracket", qs.stringify({ a: [1, 2] }, { arrayFormat: "bracket" }));
log("stringify.array.comma", qs.stringify({ a: [1, 2, 3] }, { arrayFormat: "comma" }));
log("stringify.sort", qs.stringify({ z: 1, a: 2 }, { sort: (a, b) => a.localeCompare(b) }));

log("extract", qs.extract("https://example.com/page?a=1&b=2"));

const parsed = qs.parseUrl("https://example.com/page?a=1&b=2#frag");
log("parseUrl", parsed);

log("stringifyUrl", qs.stringifyUrl({ url: "https://x.com", query: { a: 1, b: 2 } }));

console.log(out.join("\n"));
