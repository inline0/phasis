// Runner: exercise camelcase + its options. esbuild bundles the
// ESM export under .default — unwrap once.

const camelcase = CamelCase.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("basic", camelcase("foo-bar"));
log("space", camelcase("foo bar"));
log("snake", camelcase("foo_bar"));
log("upper", camelcase("FOO_BAR"));
log("mixed", camelcase("foo-bar-baz_qux"));
log("array", camelcase(["foo-bar", "baz-qux"]));
log("pascal", camelcase("foo-bar", { pascalCase: true }));
log("preserve", camelcase("FOO-BAR", { preserveConsecutiveUppercase: true }));
log("locale", camelcase("İSTANBUL", { locale: "en-US" }));
log("empty", camelcase(""));
log("single", camelcase("foo"));
log("numbers", camelcase("foo-1-bar"));

console.log(out.join("\n"));
