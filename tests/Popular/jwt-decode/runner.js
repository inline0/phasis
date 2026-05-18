const { jwtDecode } = JwtDecode;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const tok = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0IiwibmFtZSI6IkFsaWNlIiwiaWF0IjoxNTE2MjM5MDIyfQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c";

log("payload", jwtDecode(tok));
log("header", jwtDecode(tok, { header: true }));

console.log(out.join("\n"));
