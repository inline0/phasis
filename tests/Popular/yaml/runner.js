// yaml — modern YAML 1.2 parser/stringifier.
const yaml = YamlBundle.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// 1. Parse simple
log("scalar", yaml.parse("42"));
log("str", yaml.parse("hello"));
log("bool", yaml.parse("true"));
log("null", yaml.parse("null"));

// 2. Parse sequence
log("seq", yaml.parse("- 1\n- 2\n- 3"));
log("flowseq", yaml.parse("[a, b, c]"));

// 3. Parse map
log("map", yaml.parse("a: 1\nb: 2\nc: 3"));
log("flowmap", yaml.parse("{a: 1, b: 2}"));

// 4. Nested
const doc = `
name: Phasis
version: 0.1.0
authors:
  - Alice
  - Bob
config:
  port: 8080
  debug: true
  tags: [js, php, engine]
`;
log("nested", yaml.parse(doc));

// 5. Anchors / aliases
const aliasYaml = `
defaults: &defaults
  timeout: 30
  retries: 3

prod:
  <<: *defaults
  host: prod.example.com
`;
log("anchors", yaml.parse(aliasYaml));

// 6. Multi-document
const multi = yaml.parseAllDocuments("---\nfoo: 1\n---\nbar: 2");
log("multidoc.count", multi.length);
log("multidoc.docs", multi.map((d) => d.toJSON()));

// 7. Stringify
log("stringify", yaml.stringify({ a: 1, b: [1, 2, 3], c: { x: "y" } }));

// 8. Round-trip
const original = { name: "test", items: ["a", "b"], meta: { count: 5 } };
const roundtrip = yaml.parse(yaml.stringify(original));
log("roundtrip", roundtrip);

console.log(out.join("\n"));
