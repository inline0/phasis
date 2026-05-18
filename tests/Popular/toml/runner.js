const TOML = Toml;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("simple", TOML.parse('name = "phasis"\nversion = 1\n'));
log("table", TOML.parse(`
[user]
name = "alice"
age = 30
`));
log("array", TOML.parse('tags = ["a", "b", "c"]\n'));
log("nested", TOML.parse(`
[server]
[server.config]
port = 8080
host = "localhost"
`));
log("floats", TOML.parse('pi = 3.14\nbig = 1e10\n'));
log("bools", TOML.parse('on = true\noff = false\n'));
log("string-escapes", TOML.parse(String.raw`s = "a\nb\tc"`));

console.log(out.join("\n"));
