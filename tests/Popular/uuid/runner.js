// Runner: uuid library. Uses the deterministic v3 / v5 namespace-
// based UUIDs (v1/v4 are random, can't oracle-test).

const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// v3 (MD5-based) and v5 (SHA1-based) — both deterministic given
// (namespace, name).
const ns = "6ba7b810-9dad-11d1-80b4-00c04fd430c8"; // RFC 4122 DNS namespace

log("v3.example.com", UUID.v3("example.com", ns));
log("v3.www.widgets.com", UUID.v3("www.widgets.com", ns));
log("v3.same.input.matches", UUID.v3("example.com", ns) === UUID.v3("example.com", ns));

log("v5.example.com", UUID.v5("example.com", ns));
log("v5.www.widgets.com", UUID.v5("www.widgets.com", ns));
log("v5.same.input.matches", UUID.v5("example.com", ns) === UUID.v5("example.com", ns));

// NIL UUID
log("NIL", UUID.NIL);
log("MAX", UUID.MAX);

// validate + version
log("validate.nil", UUID.validate(UUID.NIL));
log("validate.bad", UUID.validate("not-a-uuid"));
log("validate.v5", UUID.validate(UUID.v5("x", ns)));
log("version.v5", UUID.version(UUID.v5("x", ns)));

// parse / stringify roundtrip
const u = UUID.v5("phasis.test", ns);
const parsed = UUID.parse(u);
log("roundtrip", UUID.stringify(parsed) === u);
log("parsed.length", parsed.length);

console.log(out.join("\n"));
