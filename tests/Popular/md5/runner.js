// Runner: blueimp-md5 hash function. Deterministic — same input
// always yields the same digest.

const md5 = Md5Mod;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// Canonical test vectors from RFC 1321
log("empty", md5(""));
log("a", md5("a"));
log("abc", md5("abc"));
log("message", md5("message digest"));
log("alphabet", md5("abcdefghijklmnopqrstuvwxyz"));
log("alnum", md5("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789"));
log("nums", md5("12345678901234567890123456789012345678901234567890123456789012345678901234567890"));

// Unicode (UTF-8 encoded under the hood)
log("emoji", md5("hello 🌍"));
log("german", md5("Grüße"));

// Long-ish input
log("long", md5("the quick brown fox jumps over the lazy dog. ".repeat(40)));

// HMAC (second arg = key)
log("hmac.empty", md5("", "key"));
log("hmac.msg", md5("the quick brown fox jumps over the lazy dog", "key"));

console.log(out.join("\n"));
