// Sourced from web-platform-tests/wpt: url/ subtree.
// Spec: https://url.spec.whatwg.org/

test(() => {
  const u = new URL("https://example.com/path?query=1#frag");
  assert_equals(u.protocol, "https:");
  assert_equals(u.host, "example.com");
  assert_equals(u.hostname, "example.com");
  assert_equals(u.port, "");
  assert_equals(u.pathname, "/path");
  assert_equals(u.search, "?query=1");
  assert_equals(u.hash, "#frag");
  assert_equals(u.href, "https://example.com/path?query=1#frag");
}, "URL: full https parse");

test(() => {
  const u = new URL("//example.com/path", "https://base.com");
  assert_equals(u.host, "example.com");
}, "URL: protocol-relative with base");

test(() => {
  const u = new URL("/abs?q=1", "https://example.com:8080/x/y");
  assert_equals(u.href, "https://example.com:8080/abs?q=1");
  assert_equals(u.port, "8080");
}, "URL: absolute path resolves on base");

test(() => {
  const u = new URL("relative", "https://example.com/x/y");
  assert_equals(u.href, "https://example.com/x/relative");
}, "URL: relative path resolves on base directory");

test(() => {
  assert_throws_js(TypeError, () => new URL("not a url"));
  assert_throws_js(TypeError, () => new URL("http://"));
}, "URL: invalid throws TypeError");

test(() => {
  assert_equals(URL.canParse("https://example.com"), true);
  assert_equals(URL.canParse("nope"), false);
  assert_equals(URL.canParse("/rel", "https://x.com"), true);
}, "URL.canParse: returns boolean");

test(() => {
  const u = new URL("https://user:pass@example.com:8443/path");
  assert_equals(u.username, "user");
  assert_equals(u.password, "pass");
  assert_equals(u.port, "8443");
}, "URL: userinfo + non-default port");

test(() => {
  const u = new URL("http://example.com");
  // http default port 80 → port property is empty.
  u.port = "80";
  assert_equals(u.port, "");
  u.port = "8080";
  assert_equals(u.port, "8080");
}, "URL: default-port suppression on set");

test(() => {
  const u = new URL("https://example.com");
  u.pathname = "/new/path";
  u.search = "?q=v";
  u.hash = "#h";
  assert_equals(u.href, "https://example.com/new/path?q=v#h");
}, "URL: setters update href");

test(() => {
  const u = new URL("http://example.com");
  u.protocol = "https";
  assert_equals(u.protocol, "https:");
}, "URL: protocol setter normalises");

test(() => {
  const u = new URL("data:text/plain,Hello");
  assert_equals(u.protocol, "data:");
  assert_equals(u.pathname, "text/plain,Hello");
}, "URL: opaque (data:) parse");
