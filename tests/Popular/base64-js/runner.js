const b64 = Base64Js;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("from.empty", b64.fromByteArray(new Uint8Array(0)));
log("from.abc", b64.fromByteArray(new Uint8Array([97, 98, 99])));
log("from.bytes", b64.fromByteArray(new Uint8Array([255, 0, 128, 64])));

log("to.YWJj", Array.from(b64.toByteArray("YWJj")));
log("to.empty", Array.from(b64.toByteArray("")));

const len = b64.byteLength("aGVsbG8gd29ybGQ=");
log("byteLength", len);

// Roundtrip
const samples = [
  [0],
  [0, 1, 2, 3, 4, 5],
  [255, 254, 253, 252],
  [...new Array(100).keys()],
];
for (const arr of samples) {
  const bytes = new Uint8Array(arr);
  const encoded = b64.fromByteArray(bytes);
  const decoded = Array.from(b64.toByteArray(encoded));
  log("rt." + arr.length, JSON.stringify(decoded) === JSON.stringify(arr));
}

console.log(out.join("\n"));
