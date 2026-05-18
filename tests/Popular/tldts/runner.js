const tld = Tldts;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("parse.com", tld.parse("https://example.com"));
log("parse.co.uk", tld.parse("https://example.co.uk"));
log("parse.deep", tld.parse("https://api.v1.example.com/path"));
log("parse.subdom", tld.getSubdomain("api.example.com"));
log("parse.tld.uk", tld.getPublicSuffix("example.co.uk"));
log("parse.tld.com", tld.getPublicSuffix("example.com"));
log("domain", tld.getDomain("api.example.co.uk"));
log("domain.bare", tld.getDomain("example.com"));
log("hostname", tld.getHostname("https://api.example.com/x"));

log("validHostname", tld.parse("not a hostname").isPrivate);
log("ip", tld.parse("https://1.2.3.4"));
log("punycode", tld.parse("https://xn--bcher-kva.example.com"));

console.log(out.join("\n"));
