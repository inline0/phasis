const esre = EsrLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
log("dot", esre("a.b"));
log("special", esre("[a-z]+?*"));
log("plain", esre("hello world"));
console.log(out.join("\n"));
