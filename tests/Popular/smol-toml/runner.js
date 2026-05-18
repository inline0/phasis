const { parse, stringify } = SmolToml;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("simple", parse('name = "phasis"\nversion = 1'));
log("array", parse('tags = ["a", "b", "c"]'));
log("nested", parse(`
[server]
port = 8080

[server.tls]
enabled = true
`));
log("string-escapes", parse(String.raw`s = "a\nb\tc"`));

// Stringify
log("stringify", stringify({ name: "phasis", version: 1, tags: ["a", "b"] }));

console.log(out.join("\n"));
