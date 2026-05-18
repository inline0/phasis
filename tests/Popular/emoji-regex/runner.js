// emoji-regex returns a regex when called.
const re = EmojiRegex.default ? EmojiRegex.default() : EmojiRegex();
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("type", typeof re);
log("source.contains.unicode", re.source.length > 100);
log("flags", re.flags);

const samples = [
  "no emoji here",
  "🙂 smiling",
  "🇩🇪 flag",
  "👨‍💻 person + zwj",
  "🌍",
  "a 😀 b 😎 c",
];

for (const s of samples) {
  const matches = s.match(re) || [];
  log(JSON.stringify(s).slice(0, 30), { count: matches.length, matches });
}

console.log(out.join("\n"));
