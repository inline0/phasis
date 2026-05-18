const pm = Picomatch;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const isMatch = (path, pattern, opts) => pm.isMatch(path, pattern, opts);

log("star", isMatch("foo.js", "*.js"));
log("star.no", isMatch("foo.txt", "*.js"));
log("globstar", isMatch("a/b/c/d.js", "**/*.js"));
log("question", isMatch("file.js", "?ile.js"));
log("brace", isMatch("foo.ts", "*.{js,ts}"));
log("range", isMatch("file1.js", "file[1-5].js"));
log("range.out", isMatch("file9.js", "file[1-5].js"));
log("negate", isMatch("foo.txt", "!*.js"));
log("dot.default", isMatch(".hidden", "*"));
log("dot.enabled", isMatch(".hidden", "*", { dot: true }));
log("case.default", isMatch("FOO.JS", "*.js"));
log("case.insensitive", isMatch("FOO.JS", "*.js", { nocase: true }));

log("match.list", ["a.js", "b.ts", "c.js", "d.md"].filter(p => isMatch(p, "*.js")));
log("makeRe", pm.makeRe("*.js").source);

console.log(out.join("\n"));
