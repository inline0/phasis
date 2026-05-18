// magic-string — string manipulation with source-map support. Used
// by most modern bundlers and transpilers for non-destructive edits.
const MagicString = MagicStr.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// 1. Basic prepend / append
const s1 = new MagicString("hello");
s1.prepend("> ");
s1.append("!");
log("prepend.append", s1.toString());

// 2. Overwrite range
const s2 = new MagicString("function foo() { return 1; }");
s2.overwrite(9, 12, "bar");
log("overwrite", s2.toString());

// 3. Remove range
const s3 = new MagicString("function foo() { /* comment */ return 1; }");
s3.remove(17, 30);
log("remove", s3.toString());

// 4. Insert at position
const s4 = new MagicString("abc def");
s4.appendLeft(3, " XXX");
s4.prependRight(4, "YYY ");
log("inserts", s4.toString());

// 5. Indent
const s5 = new MagicString("line1\nline2\nline3");
s5.indent("  ");
log("indent", s5.toString());

// 6. Source map
const s6 = new MagicString("const a = 1;");
s6.overwrite(6, 7, "renamed");
const sm = s6.generateMap({ source: "x.js", includeContent: true });
log("sourcemap.version", sm.version);
log("sourcemap.sources", sm.sources);
log("sourcemap.has.mappings", sm.mappings.length > 0);

// 7. Trim
const s7 = new MagicString("  hello  ");
s7.trim();
log("trim", s7.toString());

// 8. Clone + multiple ops
const s8 = new MagicString("foo bar baz");
const cloned = s8.clone();
cloned.overwrite(0, 3, "FOO");
log("orig", s8.toString());
log("clone", cloned.toString());

console.log(out.join("\n"));
