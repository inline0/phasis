const unraw = Unraw.unraw;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("simple", unraw("hello\\nworld"));
log("tab", unraw("a\\tb"));
log("hex", unraw("\\x41"));
log("unicode", unraw("\\u00e9"));
log("unicode.code-point", unraw("\\u{1F600}"));
log("backslash", unraw("\\\\"));
log("quote", unraw('\\"'));
log("noEscape", unraw("plain text"));

// Roundtrip
const samples = ["a\nb", "tab\there", "café"];
for (const s of samples) {
  const escaped = s.replace(/\\/g, "\\\\").replace(/\n/g, "\\n").replace(/\t/g, "\\t");
  log("rt." + JSON.stringify(s), unraw(escaped) === s);
}

console.log(out.join("\n"));
