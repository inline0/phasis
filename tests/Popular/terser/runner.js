// terser — JS minifier. Async minify() takes code + options.

async function run() {
  const out = [];
  const log = (k, v) => out.push(k + " " + JSON.stringify(v));

  const samples = [
    ["fn-decl",   "function add(a, b) { return a + b; }"],
    ["arrow",     "const sum = (a, b) => a + b;"],
    ["if-else",   "function classify(n) { if (n > 0) return 'pos'; else if (n < 0) return 'neg'; else return 'zero'; }"],
    ["loop",      "for (let i = 0; i < 10; i++) { console.log(i); }"],
    ["destruct",  "const { a, b = 5, ...rest } = obj;"],
    ["chain",     "const x = obj?.a?.b?.c ?? 'default';"],
    ["template",  "const s = `Hello ${name}, you have ${count} items`;"],
    ["class",     "class Foo { constructor(v) { this.v = v; } get value() { return this.v; } }"],
  ];

  for (const [label, code] of samples) {
    try {
      const result = await Terser.minify(code, { compress: { passes: 1 }, mangle: true });
      log(label, result.code);
    } catch (e) {
      log(label + ".err", e.message);
    }
  }

  // No-mangle compress path
  const r = await Terser.minify("function foo(a, b) { return a + b; }", { mangle: false });
  log("no-mangle", r.code);

  console.log(out.join("\n"));
}

run();
