const nacl = NaclLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
// Deterministic test: use fixed keypair from seed
const seed = new Uint8Array(32);
for (let i = 0; i < 32; i++) seed[i] = i;
const kp = nacl.sign.keyPair.fromSeed(seed);
log("pubkey.length", kp.publicKey.length);
log("pubkey.first8", Array.from(kp.publicKey.slice(0, 8)));
const msg = nacl.util ? nacl.util.decodeUTF8("hello") : new Uint8Array([104, 101, 108, 108, 111]);
const sig = nacl.sign.detached(msg, kp.secretKey);
log("sig.length", sig.length);
log("sig.first8", Array.from(sig.slice(0, 8)));
log("verify", nacl.sign.detached.verify(msg, sig, kp.publicKey));
const bad = new Uint8Array(msg);
bad[0] ^= 1;
log("verify.tampered", nacl.sign.detached.verify(bad, sig, kp.publicKey));
console.log(out.join("\n"));
