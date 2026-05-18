// jsep — tiny JS expression parser. Pure AST, no evaluation.
const jsep = JsepBundle.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const cases = [
  "1 + 2",
  "x * y",
  "a.b.c",
  "fn(x, y, 1)",
  "(a + b) * (c - d)",
  "items[3]",
  "x ? y : z",
  "true && false || null",
  "!flag",
  "a == b !== c",
  "obj.method('hello')",
  "arr.map(f).filter(g)",
];

for (const c of cases) {
  log(c, jsep(c));
}

console.log(out.join("\n"));
