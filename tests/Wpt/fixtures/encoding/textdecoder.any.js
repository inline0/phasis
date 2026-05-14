// Sourced from web-platform-tests/wpt: encoding/textdecoder-*.any.js
// Spec: https://encoding.spec.whatwg.org/#textdecoder

test(() => {
  const dec = new TextDecoder();
  assert_equals(dec.encoding, "utf-8");
  assert_equals(dec.fatal, false);
  assert_equals(dec.ignoreBOM, false);
}, "TextDecoder: default options");

test(() => {
  const dec = new TextDecoder("utf-8");
  assert_equals(dec.decode(new Uint8Array([0x61, 0x62, 0x63])), "abc");
}, "TextDecoder: utf-8 ASCII");

test(() => {
  const dec = new TextDecoder("utf-8");
  // é = 0xC3 0xA9
  assert_equals(dec.decode(new Uint8Array([0xC3, 0xA9])), "é");
  // 中 = 0xE4 0xB8 0xAD
  assert_equals(dec.decode(new Uint8Array([0xE4, 0xB8, 0xAD])), "中");
  // 😀 = 0xF0 0x9F 0x98 0x80
  assert_equals(dec.decode(new Uint8Array([0xF0, 0x9F, 0x98, 0x80])), "😀");
}, "TextDecoder: utf-8 multi-byte");

test(() => {
  const dec = new TextDecoder("utf-8", { fatal: true });
  assert_throws_js(TypeError,
    () => dec.decode(new Uint8Array([0xC0])),
    "lone byte should throw under fatal");
}, "TextDecoder: fatal mode");

test(() => {
  // 0xEF 0xBB 0xBF is the UTF-8 BOM; default ignoreBOM:false strips it.
  const dec1 = new TextDecoder("utf-8");
  assert_equals(dec1.decode(new Uint8Array([0xEF, 0xBB, 0xBF, 0x61])), "a");
  const dec2 = new TextDecoder("utf-8", { ignoreBOM: true });
  assert_equals(dec2.decode(new Uint8Array([0xEF, 0xBB, 0xBF, 0x61])), "﻿a");
}, "TextDecoder: BOM handling");

test(() => {
  const dec = new TextDecoder("utf-8");
  // 0xC3 followed by 0xA9 (split across two chunks): with stream:true
  // the first chunk holds back; the second completes "é".
  const first = dec.decode(new Uint8Array([0xC3]), { stream: true });
  const rest = dec.decode(new Uint8Array([0xA9]));
  assert_equals(first + rest, "é");
}, "TextDecoder: streaming holds partial codepoint");

test(() => {
  const enc = new TextEncoder();
  const dec = new TextDecoder("utf-8");
  const s = "round-trip café 中 😀";
  assert_equals(dec.decode(enc.encode(s)), s);
}, "TextEncoder/TextDecoder: round-trip");

test(() => {
  // utf-16le: ASCII 'a' is 0x61 0x00
  const dec = new TextDecoder("utf-16le");
  assert_equals(dec.decode(new Uint8Array([0x61, 0x00, 0x62, 0x00])), "ab");
}, "TextDecoder: utf-16le");

test(() => {
  // utf-16be: ASCII 'a' is 0x00 0x61
  const dec = new TextDecoder("utf-16be");
  assert_equals(dec.decode(new Uint8Array([0x00, 0x61, 0x00, 0x62])), "ab");
}, "TextDecoder: utf-16be");
