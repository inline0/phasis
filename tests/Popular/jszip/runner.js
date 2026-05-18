// JSZip — async ZIP builder. The library is UMD and registers
// JSZip globally. We build, inspect, then round-trip a small archive
// using the deterministic uint8array output (compression: STORE so
// the byte stream is reproducible across Node / Phasis).

// JSZip's internal scheduler hard-references `setImmediate`; Phasis
// ships setTimeout but not setImmediate (browsers don't have it
// either), so we shim it before requiring the library.
if (typeof globalThis.setImmediate !== "function") {
  globalThis.setImmediate = function (cb) { return setTimeout(cb, 0); };
}

const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

async function run() {
  try {
    const zip = new JSZip();
    const fixedDate = new Date(Date.UTC(2026, 0, 1, 0, 0, 0));
    zip.file("README.md", "# hello\n\nphasis test.", { date: fixedDate });
    zip.file("data/numbers.txt", "1\n2\n3\n", { date: fixedDate });
    zip.folder("empty");
    zip.file("nested/deep/leaf.json", '{"a":1}', { date: fixedDate });

    log("names", Object.keys(zip.files).sort());
    log("readme", await zip.file("README.md").async("string"));
    log("leaf", await zip.file("nested/deep/leaf.json").async("string"));
    log("isDir.empty", zip.files["empty/"].dir);
    log("isDir.readme", zip.files["README.md"].dir);

    const bytes = await zip.generateAsync({
      type: "uint8array",
      compression: "STORE",
    });
    log("zipLen", bytes.length);
    log("magic", [bytes[0], bytes[1], bytes[2], bytes[3]]);

    const reread = await JSZip.loadAsync(bytes);
    log("reread.names", Object.keys(reread.files).sort());
    log("reread.readme", await reread.file("README.md").async("string"));
    log("reread.leaf", await reread.file("nested/deep/leaf.json").async("string"));
  } catch (e) {
    log("error", e && e.message ? e.message : String(e));
  }

  console.log(out.join("\n"));
}

run();
