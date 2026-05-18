const bowser = Bowser.default ?? Bowser;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const uas = [
  "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
  "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15",
  "Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0",
  "Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1",
];

for (const ua of uas) {
  const parsed = bowser.parse(ua);
  log(ua.slice(0, 35), { browser: parsed.browser?.name, os: parsed.os?.name });
}

// getParser
const p = bowser.getParser(uas[0]);
log("getParser.browserName", p.getBrowserName());
log("getParser.osName", p.getOSName());
log("getParser.platform", p.getPlatformType());
log("getParser.engine", p.getEngineName());

console.log(out.join("\n"));
