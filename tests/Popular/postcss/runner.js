// postcss — parse CSS, walk and mutate the AST, stringify back.
const postcss = PostCSS.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const css = `
.foo { color: red; padding: 4px; }
.bar { color: blue; margin: 2px; }
@media (min-width: 600px) {
  .baz { font-size: 16px; }
}
`;

const root = postcss.parse(css);

// Inventory
let ruleCount = 0;
let atRuleCount = 0;
let declCount = 0;
root.walkRules(() => { ruleCount++; });
root.walkAtRules(() => { atRuleCount++; });
root.walkDecls(() => { declCount++; });
log("counts", { rules: ruleCount, atRules: atRuleCount, decls: declCount });

// Mutate: prefix every selector with `.app `
root.walkRules((rule) => {
  rule.selector = rule.selectors.map((s) => ".app " + s).join(", ");
});

// Mutate: convert all `color: red` to `color: crimson`
root.walkDecls("color", (decl) => {
  if (decl.value === "red") decl.value = "crimson";
});

const result = root.toResult().css;
log("transformed", result);

// Parse a malformed-but-recoverable rule to check error path
try {
  const r2 = postcss.parse(".missing { color }", { from: "x.css" });
  log("loose.parse", r2.toResult().css);
} catch (e) {
  log("loose.error", e.message);
}

// Build a rule programmatically
const rule = postcss.rule({ selector: ".built" });
rule.append(postcss.decl({ prop: "display", value: "flex" }));
rule.append(postcss.decl({ prop: "gap", value: "8px" }));
log("built", rule.toString());

// Source-map-less round trip
const round = postcss.parse(".x { a: 1 }").toResult().css;
log("round", round);

console.log(out.join("\n"));
