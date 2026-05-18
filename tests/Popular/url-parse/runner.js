const URLParse = UrlParse;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const u = URLParse("https://user:pass@example.com:8080/path/to/page?q=1&r=2#section");
log("protocol", u.protocol);
log("username", u.username);
log("password", u.password);
log("hostname", u.hostname);
log("port", u.port);
log("pathname", u.pathname);
log("query", u.query);
log("hash", u.hash);
log("origin", u.origin);
log("href", u.href);

// Parse query
const u2 = URLParse("https://example.com/?a=1&b=2", true);
log("query.parsed", u2.query);

const u3 = URLParse("//example.com/path", "https:");
log("schemeless", u3.href);

const u4 = URLParse("relative/path", "https://base.com/abs/");
log("relative.resolved", u4.href);

// Set + toString
const u5 = URLParse("http://example.com");
u5.set("hostname", "phasis.dev");
u5.set("pathname", "/docs");
log("modified", u5.href);

console.log(out.join("\n"));
