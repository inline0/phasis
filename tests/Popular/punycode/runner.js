const pc = PunyCode;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("encode.bare", pc.encode("bücher"));
log("encode.ascii", pc.encode("hello"));
log("encode.umlaut", pc.encode("ümläut"));
log("encode.chinese", pc.encode("中文"));
log("decode.bare", pc.decode("bcher-kva"));
log("decode.chinese", pc.decode("fiq228c"));

log("toASCII.url", pc.toASCII("bücher.example.com"));
log("toASCII.full-unicode", pc.toASCII("café.fr"));
log("toUnicode.bare", pc.toUnicode("xn--bcher-kva.example.com"));
log("toUnicode.fr", pc.toUnicode("xn--caf-dma.fr"));

// ucs2
log("ucs2.decode.ascii", pc.ucs2.decode("abc"));
log("ucs2.encode", pc.ucs2.encode([97, 98, 99]));
log("ucs2.decode.emoji", pc.ucs2.decode("😀"));

console.log(out.join("\n"));
