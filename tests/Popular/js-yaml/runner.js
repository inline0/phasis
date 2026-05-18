// Runner: js-yaml load + dump on YAML documents covering scalars,
// nested maps, sequences, anchors/aliases, multi-doc.

const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("scalar", YAML.load("hello"));
log("map", YAML.load("a: 1\nb: 2\nc: hello"));
log("seq", YAML.load("- foo\n- bar\n- baz"));
log("nested", YAML.load("user:\n  name: alice\n  tags:\n    - admin\n    - dev"));
log("types", YAML.load("yes: true\nno: false\nn: null\nflt: 3.14\nint: 42"));
log("anchors", YAML.load("base: &b\n  x: 1\n  y: 2\nderived:\n  <<: *b\n  z: 3"));

const docs = YAML.loadAll("---\na: 1\n---\nb: 2\n---\nc: 3");
log("multi-doc", docs);

log("dump.simple", YAML.dump({ a: 1, b: [2, 3], c: { d: "x" } }));
log("dump.indent", YAML.dump({ k: { nested: [1, 2] } }, { indent: 4 }));
log("dump.flow", YAML.dump({ a: [1, 2, 3] }, { flowLevel: 0 }));

// Roundtrip
const sample = { name: "Phasis", version: 1, deps: ["a", "b"], meta: { stable: true } };
log("roundtrip", YAML.load(YAML.dump(sample)));

console.log(out.join("\n"));
