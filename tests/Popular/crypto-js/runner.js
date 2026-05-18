const C = CryptoJs;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// Hashes (deterministic — fixed input, fixed output)
log("md5", C.MD5("hello").toString());
log("md5.long", C.MD5("the quick brown fox jumps over the lazy dog").toString());
log("sha1", C.SHA1("hello").toString());
log("sha256", C.SHA256("hello").toString());
log("sha512", C.SHA512("hello").toString().substring(0, 32) + "...");

// HMAC
log("hmac.sha256", C.HmacSHA256("hello", "secret").toString());
log("hmac.md5", C.HmacMD5("hello", "secret").toString());

// PBKDF2 (deterministic with fixed salt + iterations)
log("pbkdf2", C.PBKDF2("password", "salt", { keySize: 8, iterations: 1000 }).toString().substring(0, 32) + "...");

// AES encrypt/decrypt (deterministic with fixed IV)
const key = C.enc.Utf8.parse("0123456789abcdef");
const iv = C.enc.Utf8.parse("fedcba9876543210");
const enc = C.AES.encrypt("hello world", key, { iv: iv, mode: C.mode.CBC, padding: C.pad.Pkcs7 });
log("aes.ciphertext", enc.ciphertext.toString().substring(0, 16) + "...");
const dec = C.AES.decrypt(enc, key, { iv: iv, mode: C.mode.CBC, padding: C.pad.Pkcs7 });
log("aes.roundtrip", dec.toString(C.enc.Utf8));

// Encoding utilities
log("base64.encode", C.enc.Utf8.parse("hello").toString(C.enc.Base64));
log("base64.decode", C.enc.Base64.parse("aGVsbG8gd29ybGQ=").toString(C.enc.Utf8));

console.log(out.join("\n"));
