// Runner: exercise JSON5's relaxed JSON parser/stringifier. JSON5
// extends JSON with: unquoted keys, single-quoted strings, trailing
// commas, comments, hex/Infinity/NaN literals, line continuations.
// Each parse round-trips back through JSON.stringify so the oracle
// captures a canonical form regardless of input style.
//
// JSON5 registers as the global JSON5 (UMD).

const out = [];
const log = (label, value) => out.push(label + " " + JSON.stringify(value));

// --- Parse: relaxed inputs that strict JSON.parse rejects ----

// Unquoted keys + trailing commas
log("unquoted-keys", JSON5.parse("{a: 1, b: 2, c: 3,}"));

// Single-quoted strings
log("single-quoted", JSON5.parse("{'name': 'Phasis', 'kind': 'engine'}"));

// Block + line comments
log("with-comments", JSON5.parse(`{
  // user fields
  name: 'Alice',
  /* numeric */
  age: 30,
  active: true,
}`));

// Hex literals
log("hex-literal", JSON5.parse("{ flags: 0xff, mask: 0xCAFE }"));

// Special numeric values
log("infinity", JSON5.parse("{a: Infinity, b: -Infinity}").a === Infinity);
log("nan.isNaN", Number.isNaN(JSON5.parse("{n: NaN}").n));

// Leading / trailing decimal points
log("decimal-dot", JSON5.parse("{a: .5, b: 1., c: -.25}"));

// Plus sign on numbers
log("plus-sign", JSON5.parse("{a: +1, b: +1.5e10}"));

// Multiline string via line continuation
log("multiline-str", JSON5.parse(`{ msg: 'hello \\
world' }`));

// Nested + mixed quotes
log("nested-mixed", JSON5.parse(`{
  users: [
    { id: 1, "name": 'alice' },
    { id: 2, name: "bob", },
  ],
  count: 2,
}`));

// --- Strict JSON inputs still parse identically ---
log("strict-json", JSON5.parse('{"plain": "json", "n": 42}'));

// --- Stringify: produces JSON5 with optional space indent ---
log("stringify.compact", JSON5.stringify({ a: 1, b: [2, 3], c: "hi" }));
log("stringify.indent2", JSON5.stringify({ a: 1, b: { c: 2 } }, null, 2));
log("stringify.indent.tab", JSON5.stringify({ a: 1 }, null, "\t"));
log("stringify.replacer.array", JSON5.stringify(
  { keep: 1, drop: 2, alsoKeep: 3 },
  ["keep", "alsoKeep"],
));
log("stringify.replacer.fn", JSON5.stringify(
  { a: 1, b: 2, c: 3 },
  (key, value) => (key === "b" ? undefined : value),
));

// --- Round-trips through both parsers ---
const original = { a: 1, b: "two", c: [3, 4, 5], d: { nested: true } };
const viaJSON5 = JSON5.parse(JSON5.stringify(original));
log("roundtrip.equal", JSON.stringify(viaJSON5) === JSON.stringify(original));

// JSON5 -> strict JSON conversion is exact
const relaxed = "{a: 1, 'b': 'two', c: [.5, 0xff],}";
const parsed = JSON5.parse(relaxed);
log("relaxed-to-strict", JSON.stringify(parsed));

// Stringify with special values is JSON5-aware
log("stringify.infinity", JSON5.stringify({ a: Infinity, b: -Infinity }));
log("stringify.nan", JSON5.stringify({ n: NaN }));

console.log(out.join("\n"));
