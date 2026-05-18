// css-tree — CSS AST: tokenizer, parser, walker, generator.
const csstree = CsstreeBundle.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const css = `
.foo {
  color: #ff0000;
  padding: 10px 20px;
}
@media (max-width: 600px) {
  .bar {
    font-size: 14px;
  }
}
`;

// Parse
const ast = csstree.parse(css);
log("ast.type", ast.type);

// Walk: collect rule selectors
const selectors = [];
csstree.walk(ast, (node) => {
  if (node.type === "Selector") {
    selectors.push(csstree.generate(node));
  }
});
log("selectors", selectors);

// Walk declarations
const decls = [];
csstree.walk(ast, (node) => {
  if (node.type === "Declaration") {
    decls.push({ property: node.property, value: csstree.generate(node.value) });
  }
});
log("decls", decls);

// Generate back
log("generated", csstree.generate(ast));

// Parse a value
const vAst = csstree.parse("rgba(0, 100, 200, 0.5)", { context: "value" });
log("value.type", vAst.type);

console.log(out.join("\n"));
