const globToRegExp = GtrLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
log("star", globToRegExp("*.js").test("foo.js"));
log("star.fail", globToRegExp("*.js").test("foo.css"));
log("globstar", globToRegExp("**/*.ts", { globstar: true }).test("a/b/c.ts"));
log("question", globToRegExp("a?c").test("abc"));
log("source", globToRegExp("*.{js,ts}", { extended: true }).source);
console.log(out.join("\n"));
