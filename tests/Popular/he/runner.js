// Runner: exercise `he`'s HTML entity encode/decode + escape/unescape.
// Outputs are deterministic for fixed input strings.

const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("encode.basic", He.encode("foo © bar ≠ baz 𝌆 qux"));
log("encode.ascii", He.encode("foo & bar < baz > 'q' \"q\"", { useNamedReferences: false }));
log("encode.named", He.encode("café & noël", { useNamedReferences: true }));
log("decode.named", He.decode("foo &copy; bar &ne; baz &#x1D306; qux"));
log("decode.numeric", He.decode("&#65;&#x42;&#x1F600;"));
log("escape.html", He.escape("<script>alert('xss')</script>"));
log("unescape.html", He.unescape("&lt;b&gt;&amp;&quot;&#39;&lt;/b&gt;"));

// Roundtrip
const samples = ["hello world", "café", "<a>", "\u{1F600}", "100% & co"];
for (const s of samples) {
  log("rt." + JSON.stringify(s), He.decode(He.encode(s)) === s);
}

console.log(out.join("\n"));
