const ip = IpAddr;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("isValid.v4", ip.isValid("192.168.1.1"));
log("isValid.v6", ip.isValid("2001:db8::1"));
log("isValid.bad", ip.isValid("not.an.ip"));

const addr4 = ip.parse("192.168.1.1");
log("v4.kind", addr4.kind());
log("v4.range", addr4.range());

const addr6 = ip.parse("2001:db8::1");
log("v6.kind", addr6.kind());
log("v6.range", addr6.range());
log("v6.normalize", addr6.toNormalizedString());

// Loopback / private  / public ranges
log("loopback.v4", ip.parse("127.0.0.1").range());
log("loopback.v6", ip.parse("::1").range());
log("private", ip.parse("10.0.0.1").range());
log("public", ip.parse("8.8.8.8").range());

console.log(out.join("\n"));
