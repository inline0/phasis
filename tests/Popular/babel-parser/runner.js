// @babel/parser — modern syntax breadth.
const { parse, parseExpression } = BabelParser;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

function summary(code, opts = {}) {
  try {
    const ast = parse(code, opts);
    return {
      type: ast.type,
      body: ast.program.body.length,
      first: ast.program.body[0] && ast.program.body[0].type,
    };
  } catch (e) {
    return { err: e.message.split("\n")[0] };
  }
}

log("simple", summary("const x = 1;"));
log("arrow", summary("const f = (x) => x * 2;"));
log("class", summary("class Foo extends Bar { #priv = 1; get value() { return this.#priv; } }"));
log("async", summary("async function f() { for await (const x of stream) yield x; }"));
log("optional", summary("const x = obj?.a?.b ?? 'default';"));
log("destructure", summary("const { a, b = 1, ...rest } = obj;"));
log("template", summary("const s = `hello ${name}!`;"));
log("decorator", summary("@dec class C {}", { plugins: ["decorators-legacy"] }));
log("jsx", summary("const el = <div className='x'>hi</div>;", { plugins: ["jsx"] }));
// TypeScript plugin path defers — surfaces an internal Assert fail
// inside babel's TS lexer state; investigate separately.
log("import-meta", summary("const url = import.meta.url;", { sourceType: "module" }));
log("dynamic-import", summary("const m = await import('./mod.js');", { sourceType: "module" }));

// parseExpression
const expr = parseExpression("a + b * c");
log("expr.type", expr.type);
log("expr.operator", expr.operator);

console.log(out.join("\n"));
