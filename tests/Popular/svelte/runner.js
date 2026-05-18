// svelte/compiler — Svelte's standalone compiler (~800 KB). Parses
// .svelte source into AST and compiles to JS.
const { compile, parse, VERSION } = Svelte.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("version", VERSION);

// 1. Parse a simple component
const src1 = `<script>
  let count = 0;
  function increment() { count += 1; }
</script>

<button on:click={increment}>
  Clicked {count} times
</button>

<style>
  button { padding: 8px; }
</style>`;

const ast1 = parse(src1, { modern: true });
log("ast.type", ast1.type);
log("ast.children", ast1.fragment.nodes.map((n) => n.type));

// 2. Compile to JS
const r1 = compile(src1, {
  generate: "client",
  filename: "Counter.svelte",
  dev: false,
});
log("compile.warnings", r1.warnings.length);
log("compile.hasCode", r1.js.code.length > 100);
log("compile.css.exists", r1.css !== null);

// 3. Compile a simpler one and inspect output structure
const src2 = `<script>
  export let name = "world";
</script>

<h1>Hello {name}!</h1>`;

const r2 = compile(src2, {
  generate: "client",
  filename: "Hello.svelte",
});
// Just check it produced JS code containing the prop name
log("hello.has.name", r2.js.code.includes("name"));
log("hello.has.template", r2.js.code.includes("Hello") || r2.js.code.includes("template"));

// 4. Compile to server (SSR)
const r3 = compile(src2, {
  generate: "server",
  filename: "Hello.svelte",
});
log("ssr.warnings", r3.warnings.length);

// 5. Parse error handling
try {
  parse("<button on:click={", { modern: true });
  log("parse.error", "no throw");
} catch (e) {
  log("parse.error", e.message ? e.message.split("\n")[0] : String(e));
}

console.log(out.join("\n"));
