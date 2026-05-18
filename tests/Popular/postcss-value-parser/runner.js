const valueParser = PostcssValue;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

function nodes(input) {
  return valueParser(input).nodes.map(n => ({ type: n.type, value: n.value }));
}

log("simple", nodes("10px"));
log("rgb", nodes("rgb(255, 0, 0)"));
log("mixed", nodes("10px solid red"));
log("calc", nodes("calc(100% - 10px)"));
log("url", nodes("url(image.png)"));
log("string", nodes('"hello"'));
log("var", nodes("var(--my-color)"));

// unit extraction
log("unit.px", valueParser.unit("10px"));
log("unit.em", valueParser.unit("1.5em"));
log("unit.nounit", valueParser.unit("42"));
log("unit.bad", valueParser.unit("abc"));

console.log(out.join("\n"));
