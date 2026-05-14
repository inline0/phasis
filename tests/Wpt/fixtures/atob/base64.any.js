// Sourced from web-platform-tests/wpt: html/webappapis/atob/base64.any.js
// Spec: https://html.spec.whatwg.org/multipage/webappapis.html#atob

// Tests cover the forgiving-base64 algorithm in atob and the
// btoa-produces-ASCII rule. Trimmed to the cases this engine can
// exercise headlessly.

test(() => {
  assert_equals(btoa("hello"), "aGVsbG8=", "btoa('hello')");
  assert_equals(btoa(""), "", "btoa('')");
  assert_equals(btoa("a"), "YQ==", "btoa('a') (single byte, two padding)");
  assert_equals(btoa("ab"), "YWI=", "btoa('ab') (two bytes, one padding)");
  assert_equals(btoa("abc"), "YWJj", "btoa('abc') (three bytes, no padding)");
}, "btoa: known good inputs");

test(() => {
  // Latin-1 boundary: codepoint 255 is the highest allowed.
  assert_equals(btoa(String.fromCharCode(255)), "/w==");
  // "café" is all Latin-1 (é is U+00E9, codepoint 233) → ok.
  assert_equals(btoa("café"), "Y2Fm6Q==");
  // Codepoints ≥ 256 must throw. "中" is U+4E2D.
  assert_throws_dom("InvalidCharacterError",
    () => btoa(String.fromCharCode(256)),
    "codepoint 256 must throw");
  assert_throws_dom("InvalidCharacterError",
    () => btoa("中"), "non-Latin-1 must throw");
}, "btoa: Latin-1 boundary");

test(() => {
  assert_equals(atob("aGVsbG8="), "hello");
  assert_equals(atob(""), "");
  assert_equals(atob("YQ=="), "a");
  assert_equals(atob("YWI="), "ab");
  assert_equals(atob("YWJj"), "abc");
}, "atob: known good inputs");

test(() => {
  // Whitespace tolerance: ASCII whitespace (space, tab, LF, CR, FF)
  // is stripped before validation.
  assert_equals(atob(" a G V s b G 8 = "), "hello");
  assert_equals(atob("aGVsb\tG8="), "hello");
  assert_equals(atob("aGVs\nbG8="), "hello");
}, "atob: forgiving — strips whitespace");

test(() => {
  // Invalid characters and bad padding throw.
  assert_throws_dom("InvalidCharacterError", () => atob("a"));
  assert_throws_dom("InvalidCharacterError", () => atob("aa==="));
  assert_throws_dom("InvalidCharacterError", () => atob("a!bc"));
}, "atob: rejects malformed");

test(() => {
  // Round-trip arbitrary Latin-1 bytes.
  let chars = "";
  for (let i = 0; i < 256; i++) chars += String.fromCharCode(i);
  assert_equals(atob(btoa(chars)), chars, "all 256 bytes round-trip");
}, "atob/btoa: full Latin-1 round-trip");
