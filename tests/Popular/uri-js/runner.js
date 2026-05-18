const URI = UriJs;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("parse.http", URI.parse("https://example.com:8080/path?q=1#frag"));
log("parse.mailto", URI.parse("mailto:alice@example.com"));
log("parse.urn", URI.parse("urn:isbn:0451450523"));
log("parse.ipv6", URI.parse("http://[2001:db8::1]:8080/"));

log("serialize", URI.serialize({ scheme: "https", host: "phasis.dev", port: 443, path: "/x" }));
log("normalize", URI.normalize("HTTP://Example.COM/a/b/../c"));
log("resolve.abs", URI.resolve("http://a.com/b/", "c.html"));
log("resolve.parent", URI.resolve("http://a.com/b/c/", "../d"));

log("equal.case", URI.equal("HTTP://Example.com", "http://example.com"));
log("equal.diff", URI.equal("http://a.com", "http://b.com"));

console.log(out.join("\n"));
