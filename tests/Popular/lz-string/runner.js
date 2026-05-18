const lz = LZString;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const sample = "the quick brown fox jumps over the lazy dog. ".repeat(10);

const base64 = lz.compressToBase64(sample);
log("base64.roundtrip", lz.decompressFromBase64(base64) === sample);

const utf16 = lz.compressToUTF16(sample);
log("utf16.roundtrip", lz.decompressFromUTF16(utf16) === sample);

const uri = lz.compressToEncodedURIComponent(sample);
log("uri.roundtrip", lz.decompressFromEncodedURIComponent(uri) === sample);

log("short.base64.roundtrip", lz.decompressFromBase64(lz.compressToBase64("hi")) === "hi");
log("empty.base64.roundtrip", lz.decompressFromBase64(lz.compressToBase64("")) === "");

console.log(out.join("\n"));
