const p5 = Parse5;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const html = "<!DOCTYPE html><html><head><title>Hello</title></head><body><p class='x'>World</p></body></html>";
const doc = p5.parse ? p5.parse(html) : null;
log("has.parse", typeof p5.parse);
log("has.serialize", typeof p5.serialize);

if (typeof p5.parse === "function") {
  const tree = p5.parse("<p>Hi</p>");
  log("tree.nodeName", tree.nodeName);
  log("roundtrip", p5.serialize(tree).includes("<p>Hi</p>"));
}

const frag = p5.parseFragment ? p5.parseFragment("<a href='x'>link</a><b>bold</b>") : null;
if (frag) {
  log("frag.childNodes.length", frag.childNodes.length);
}

console.log(out.join("\n"));
