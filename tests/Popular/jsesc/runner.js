const jsesc = Jsesc;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("ascii", jsesc("hello"));
log("unicode", jsesc("café"));
log("emoji", jsesc("🌍"));
log("control", jsesc("\n\t"));
log("quotes", jsesc('"hi"'));

log("quotes.single", jsesc('"hi"', { quotes: "single" }));
log("quotes.double", jsesc("'hi'", { quotes: "double" }));
log("wrap", jsesc("test", { wrap: true }));
log("compact-false", jsesc({ a: 1, b: 2 }, { compact: false }));
log("json", jsesc({ a: 1, b: "x" }, { json: true }));

log("number", jsesc(123));
log("array", jsesc([1, 2, "x"]));
log("object", jsesc({ a: "b" }));

console.log(out.join("\n"));
