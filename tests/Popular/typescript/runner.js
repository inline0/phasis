// typescript — the TS compiler itself. The vendored standalone build
// (typescript.js, ~9 MB) self-registers a global `ts` namespace.
// We exercise the tokenizer, parser, and the transpileModule API,
// which produces emit output without needing a real file system.
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// 1. Tokenize a snippet
const code = "const x: number = 42;";
const scanner = ts.createScanner(ts.ScriptTarget.Latest, false);
scanner.setText(code);
const tokens = [];
let kind;
while ((kind = scanner.scan()) !== ts.SyntaxKind.EndOfFileToken) {
  tokens.push(ts.SyntaxKind[kind] + ":" + scanner.getTokenText());
}
log("tokens", tokens);

// 2. Parse to AST and walk top-level statements
const sf = ts.createSourceFile(
  "test.ts",
  "interface User { id: number; name: string; }\nconst u: User = { id: 1, name: 'a' };\nfunction add(a: number, b: number): number { return a + b; }",
  ts.ScriptTarget.Latest,
  true,
);
const stmtKinds = [];
sf.forEachChild((node) => {
  stmtKinds.push(ts.SyntaxKind[node.kind]);
});
log("ast.kinds", stmtKinds);

// 3. transpileModule: TS → JS
const out1 = ts.transpileModule(
  "const greet = (name: string): string => `Hello, ${name}`;\nexport { greet };",
  {
    compilerOptions: {
      module: ts.ModuleKind.ESNext,
      target: ts.ScriptTarget.ES2020,
    },
  },
);
log("transpile.simple", out1.outputText);

// 4. transpileModule with a class
const out2 = ts.transpileModule(
  "class Box<T> { constructor(public value: T) {} get(): T { return this.value; } }",
  { compilerOptions: { target: ts.ScriptTarget.ES2020 } },
);
log("transpile.class", out2.outputText);

// 5. transpileModule with async + await
const out3 = ts.transpileModule(
  "async function fetchUser(id: number): Promise<{ id: number }> { return { id }; }",
  { compilerOptions: { target: ts.ScriptTarget.ES2020 } },
);
log("transpile.async", out3.outputText);

// 6. transpileModule with decorators / enum
const out4 = ts.transpileModule(
  "enum Color { Red, Green, Blue }\nconst c = Color.Green;",
  { compilerOptions: { target: ts.ScriptTarget.ES2020 } },
);
log("transpile.enum", out4.outputText);

// 7. Diagnostics: invalid TS should still transpile but report errors
const out5 = ts.transpileModule(
  "const x: number = 'not a number';",
  {
    compilerOptions: { target: ts.ScriptTarget.ES2020, noEmitOnError: false },
    reportDiagnostics: true,
  },
);
log("transpile.invalid.out", out5.outputText);
log("transpile.invalid.diagCount", (out5.diagnostics || []).length);

// 8. Version
log("version", ts.version);

console.log(out.join("\n"));
