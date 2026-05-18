const { sha256, utf8ToBytes, bytesToHex } = NobleLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
log("empty", bytesToHex(sha256(utf8ToBytes(""))));
log("abc", bytesToHex(sha256(utf8ToBytes("abc"))));
log("long", bytesToHex(sha256(utf8ToBytes("The quick brown fox jumps over the lazy dog"))));
log("phasis", bytesToHex(sha256(utf8ToBytes("Phasis pure-PHP engine"))));
console.log(out.join("\n"));
