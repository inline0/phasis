// Sourced from web-platform-tests/wpt: url/urlsearchparams-*.any.js
// Spec: https://url.spec.whatwg.org/#interface-urlsearchparams

test(() => {
  const usp = new URLSearchParams("a=1&b=2");
  assert_equals(usp.get("a"), "1");
  assert_equals(usp.get("b"), "2");
  assert_equals(usp.get("c"), null);
  assert_equals(usp.size, 2);
}, "URLSearchParams: string constructor");

test(() => {
  const usp = new URLSearchParams([["a", "1"], ["b", "2"]]);
  assert_equals(usp.get("a"), "1");
  assert_equals(usp.toString(), "a=1&b=2");
}, "URLSearchParams: array-of-pairs constructor");

test(() => {
  const usp = new URLSearchParams({ a: "1", b: "2" });
  assert_in_array(usp.toString(), ["a=1&b=2", "b=2&a=1"]);
}, "URLSearchParams: record constructor");

test(() => {
  const usp = new URLSearchParams("a=1&a=2&a=3");
  assert_array_equals(usp.getAll("a"), ["1", "2", "3"]);
}, "URLSearchParams: getAll");

test(() => {
  const usp = new URLSearchParams("a=1&b=2");
  usp.append("a", "3");
  assert_array_equals(usp.getAll("a"), ["1", "3"]);
  usp.set("a", "X");
  assert_array_equals(usp.getAll("a"), ["X"]);
  usp.delete("a");
  assert_equals(usp.get("a"), null);
}, "URLSearchParams: append / set / delete");

test(() => {
  const usp = new URLSearchParams("z=1&a=2&m=3");
  usp.sort();
  assert_equals(usp.toString(), "a=2&m=3&z=1");
}, "URLSearchParams: sort");

test(() => {
  const usp = new URLSearchParams("a=1&b=2&c=3");
  const keys = [];
  for (const [k] of usp) keys.push(k);
  assert_array_equals(keys, ["a", "b", "c"]);
}, "URLSearchParams: iteration via for-of");

test(() => {
  const u = new URL("https://x.com/?a=1");
  u.searchParams.set("b", "2");
  assert_equals(u.search, "?a=1&b=2");
  u.searchParams.delete("a");
  assert_equals(u.search, "?b=2");
}, "URL.searchParams: live link mutates URL.search");

test(() => {
  // ES2024 has(name, value) and delete(name, value)
  const usp = new URLSearchParams("a=1&a=2");
  assert_true(usp.has("a"));
  assert_true(usp.has("a", "1"));
  assert_false(usp.has("a", "3"));
  usp.delete("a", "1");
  assert_array_equals(usp.getAll("a"), ["2"]);
}, "URLSearchParams: ES2024 has(name, value) / delete(name, value)");
