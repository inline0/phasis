// Sourced from web-platform-tests/wpt: encoding/textencoder-utf8.any.js
// Spec: https://encoding.spec.whatwg.org/#textencoder

test(() => {
  const enc = new TextEncoder();
  assert_equals(enc.encoding, "utf-8");
}, "TextEncoder: encoding property is 'utf-8'");

test(() => {
  const enc = new TextEncoder();
  const bytes = enc.encode("abc");
  assert_array_equals(Array.from(bytes), [0x61, 0x62, 0x63]);
}, "TextEncoder: ASCII");

test(() => {
  const enc = new TextEncoder();
  // é = U+00E9 → 2 bytes UTF-8 (0xC3 0xA9)
  assert_array_equals(Array.from(enc.encode("é")), [0xC3, 0xA9]);
  // 中 = U+4E2D → 3 bytes (0xE4 0xB8 0xAD)
  assert_array_equals(Array.from(enc.encode("中")), [0xE4, 0xB8, 0xAD]);
  // 😀 = U+1F600 → 4 bytes (0xF0 0x9F 0x98 0x80)
  assert_array_equals(Array.from(enc.encode("😀")), [0xF0, 0x9F, 0x98, 0x80]);
}, "TextEncoder: multi-byte UTF-8");

test(() => {
  const enc = new TextEncoder();
  // Lone high surrogate → U+FFFD (3 bytes UTF-8: 0xEF 0xBF 0xBD).
  assert_array_equals(Array.from(enc.encode("\uD800")), [0xEF, 0xBF, 0xBD]);
}, "TextEncoder: lone surrogate → U+FFFD");

test(() => {
  const enc = new TextEncoder();
  const buf = new Uint8Array(4);
  const r = enc.encodeInto("abc", buf);
  assert_equals(r.read, 3);
  assert_equals(r.written, 3);
  assert_equals(buf[0], 0x61);
  assert_equals(buf[1], 0x62);
  assert_equals(buf[2], 0x63);
}, "TextEncoder: encodeInto basic");

test(() => {
  const enc = new TextEncoder();
  // Buffer too small for a complete codepoint — must NOT write partial.
  const buf = new Uint8Array(1);
  const r = enc.encodeInto("é", buf);   // é is 2 bytes
  assert_equals(r.read, 0);
  assert_equals(r.written, 0);
}, "TextEncoder: encodeInto no partial codepoint write");
