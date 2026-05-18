const aesjs = AesLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
const key = aesjs.utils.utf8.toBytes("0123456789abcdef0123456789abcdef");
const text = "Phasis is a pure-PHP JS engine!!";
const textBytes = aesjs.utils.utf8.toBytes(text);
// AES-CTR
const ctr = new aesjs.ModeOfOperation.ctr(key, new aesjs.Counter(5));
const encrypted = ctr.encrypt(textBytes);
log("ctr.encrypted.first8", Array.from(encrypted.slice(0, 8)));
const ctr2 = new aesjs.ModeOfOperation.ctr(key, new aesjs.Counter(5));
const decrypted = ctr2.decrypt(encrypted);
log("ctr.decrypted", aesjs.utils.utf8.fromBytes(decrypted));
// AES-CBC
const iv = aesjs.utils.utf8.toBytes("abcdefghijklmnop");
const padded = aesjs.padding.pkcs7.pad(textBytes);
const cbc = new aesjs.ModeOfOperation.cbc(key, iv);
const encCbc = cbc.encrypt(padded);
log("cbc.encrypted.len", encCbc.length);
const cbc2 = new aesjs.ModeOfOperation.cbc(key, iv);
const decCbc = aesjs.padding.pkcs7.strip(cbc2.decrypt(encCbc));
log("cbc.decrypted", aesjs.utils.utf8.fromBytes(decCbc));
console.log(out.join("\n"));
