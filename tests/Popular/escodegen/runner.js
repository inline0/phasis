// escodegen — ESTree AST → JS source code. The inverse of a JS parser.
const escodegen = Escodegen.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// 1. Simple AST → code
const ast1 = {
  type: "Program",
  body: [
    {
      type: "VariableDeclaration",
      kind: "const",
      declarations: [{
        type: "VariableDeclarator",
        id: { type: "Identifier", name: "x" },
        init: { type: "Literal", value: 42 },
      }],
    },
  ],
};
log("simple", escodegen.generate(ast1));

// 2. Function declaration
const ast2 = {
  type: "FunctionDeclaration",
  id: { type: "Identifier", name: "add" },
  params: [
    { type: "Identifier", name: "a" },
    { type: "Identifier", name: "b" },
  ],
  body: {
    type: "BlockStatement",
    body: [{
      type: "ReturnStatement",
      argument: {
        type: "BinaryExpression",
        operator: "+",
        left: { type: "Identifier", name: "a" },
        right: { type: "Identifier", name: "b" },
      },
    }],
  },
};
log("function", escodegen.generate(ast2));

// 3. Conditional with branches
const ast3 = {
  type: "IfStatement",
  test: {
    type: "BinaryExpression",
    operator: ">",
    left: { type: "Identifier", name: "x" },
    right: { type: "Literal", value: 0 },
  },
  consequent: {
    type: "ReturnStatement",
    argument: { type: "Literal", value: "positive" },
  },
  alternate: {
    type: "ReturnStatement",
    argument: { type: "Literal", value: "non-positive" },
  },
};
log("if", escodegen.generate(ast3));

// 4. Object expression
const ast4 = {
  type: "ObjectExpression",
  properties: [
    { type: "Property", kind: "init", key: { type: "Identifier", name: "a" }, value: { type: "Literal", value: 1 }, computed: false, shorthand: false, method: false },
    { type: "Property", kind: "init", key: { type: "Identifier", name: "b" }, value: { type: "Literal", value: "two" }, computed: false, shorthand: false, method: false },
  ],
};
log("object", escodegen.generate(ast4));

// 5. Custom format options
const ast5 = ast1;
log("compact", escodegen.generate(ast5, { format: { indent: { style: "" }, newline: "" } }));

console.log(out.join("\n"));
