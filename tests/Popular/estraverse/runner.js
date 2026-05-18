// estraverse — ESTree traversal with replace support. Used by
// escope, ESLint historically, and many AST tools.
const estraverse = Estraverse.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// Build an AST manually
const ast = {
  type: "Program",
  body: [
    {
      type: "VariableDeclaration", kind: "const",
      declarations: [{
        type: "VariableDeclarator",
        id: { type: "Identifier", name: "x" },
        init: { type: "Literal", value: 10 },
      }],
    },
    {
      type: "ExpressionStatement",
      expression: {
        type: "CallExpression",
        callee: { type: "Identifier", name: "console.log" },
        arguments: [{ type: "Identifier", name: "x" }],
      },
    },
  ],
};

// 1. traverse — collect node types
const types = [];
estraverse.traverse(ast, {
  enter(node) {
    types.push(node.type);
  },
});
log("types", types);

// 2. replace — rename identifiers
const renamed = estraverse.replace(JSON.parse(JSON.stringify(ast)), {
  enter(node) {
    if (node.type === "Identifier" && node.name === "x") {
      return { type: "Identifier", name: "renamed_x" };
    }
  },
});
const names = [];
estraverse.traverse(renamed, {
  enter(node) {
    if (node.type === "Identifier") names.push(node.name);
  },
});
log("after.rename", names);

// 3. Skip subtree via this.skip()
const visited = [];
estraverse.traverse(ast, {
  enter(node) {
    visited.push(node.type);
    if (node.type === "VariableDeclaration") {
      this.skip();
    }
  },
});
log("with.skip", visited);

// 4. Break out via this.break()
const visitedBreak = [];
try {
  estraverse.traverse(ast, {
    enter(node) {
      visitedBreak.push(node.type);
      if (node.type === "CallExpression") {
        this.break();
      }
    },
  });
} catch (e) {}
log("with.break", visitedBreak);

console.log(out.join("\n"));
