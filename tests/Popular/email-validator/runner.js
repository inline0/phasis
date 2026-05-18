const ev = EmailValidator;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("ok", ev.validate("alice@example.com"));
log("bad.no-at", ev.validate("aliceexample.com"));
log("bad.no-tld", ev.validate("alice@example"));
log("bad.empty", ev.validate(""));
log("subdomain", ev.validate("alice@mail.example.com"));
log("plus-alias", ev.validate("alice+filter@example.com"));
log("punycode", ev.validate("alice@xn--bcher-kva.example.com"));

console.log(out.join("\n"));
