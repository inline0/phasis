const L = Linkify;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const samples = [
  "Visit https://example.com for more.",
  "Email alice@example.com or bob@test.org",
  "Multiple: http://a.com, http://b.io, foo@bar.com",
  "No links here",
  "Just www.example.com without scheme",
  "Mixed: text https://x.com text foo@y.com text",
];

for (let i = 0; i < samples.length; i++) {
  const found = L.find(samples[i]);
  log("find." + i, found.map(f => ({ type: f.type, value: f.value, href: f.href })));
}

// Test specific types only
log("urls.only", L.find("Mix https://x.com and y@z.com", "url").map(f => f.value));
log("emails.only", L.find("Mix https://x.com and y@z.com", "email").map(f => f.value));

console.log(out.join("\n"));
