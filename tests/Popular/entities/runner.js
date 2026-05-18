const E = Entities;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("encode.html", E.encodeHTML("<b>foo & bar</b>"));
log("encode.xml", E.encodeXML("<b>foo & bar</b>"));
log("encode.nonAsciiHTML", E.encodeNonAsciiHTML("café & noël"));
log("escape", E.escape("<script>alert(1)</script>"));
log("escapeUTF8", E.escapeUTF8("<>&\"'"));
log("escapeAttribute", E.escapeAttribute("a\"b'c"));

log("decode.named", E.decode("foo &copy; bar"));
log("decode.numeric", E.decode("&#65;&#x42;"));
log("decode.html", E.decode("&lt;a href=&quot;x&quot;&gt;"));
log("decode.utf16", E.decode("&#x1D306;"));
log("decode.strict", E.decodeStrict("foo &copy; bar"));

// Roundtrip
const samples = ["plain", "<a>", "café", "100% & up"];
for (const s of samples) {
  log("rt." + s, E.decode(E.encodeHTML(s)) === s);
}

console.log(out.join("\n"));
