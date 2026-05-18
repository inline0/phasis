// acorn-walk — AST visitor utilities. We parse JS with acorn (which
// the bundle also ships) and walk the resulting tree.
const { walk, acorn } = AcornWalk.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const code = `
function add(a, b) { return a + b; }
const data = [1, 2, 3].map(x => x * 2);
class Box {
  constructor(v) { this.v = v; }
  get() { return this.v; }
}
const fn = async () => {
  try { await fetch("/"); } catch (e) { console.error(e); }
};
`;

const tree = acorn.parse(code, { ecmaVersion: "latest", sourceType: "module" });

// simple — visits every node by type
const types = [];
walk.simple(tree, {
  Identifier(node) { types.push("Id:" + node.name); },
  Literal(node) { types.push("Lit:" + JSON.stringify(node.value)); },
  FunctionDeclaration(node) { types.push("FnDecl:" + node.id.name); },
  ClassDeclaration(node) { types.push("Class:" + node.id.name); },
});
log("simple", types.slice(0, 30));

// full — visits every node
let count = 0;
walk.full(tree, () => count++);
log("full.count", count);

// findNodeAt — find the smallest node containing offset
const found = walk.findNodeAt(tree, 0, 50);
log("findAt.type", found ? found.node.type : null);

// recursive — custom traversal
const fnNames = [];
walk.recursive(tree, null, {
  FunctionDeclaration(node, _, c) {
    fnNames.push(node.id.name);
    c(node.body, null);
  },
  ArrowFunctionExpression(node, _, c) {
    fnNames.push("<arrow>");
    c(node.body, null);
  },
  ClassBody(node, _, c) {
    for (const m of node.body) {
      if (m.type === "MethodDefinition") fnNames.push("method:" + m.key.name);
      c(m.value, null);
    }
  },
});
log("recursive.fns", fnNames);

// findNodeAfter
const tokens = walk.findNodeAfter(tree, 30, "Literal");
log("findAfter.lit", tokens ? tokens.node.value : null);

console.log(out.join("\n"));
