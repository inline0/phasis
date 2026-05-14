// Runner: parse a representative JS snippet with acorn and emit a
// deterministic AST summary. The oracle (oracle.txt) is the output
// of running this exact runner under Node.js. CI runs it under
// Phasis and byte-compares the output.

const source = `
import { foo } from './bar.js';

export class Counter {
  #value = 0;
  constructor(start = 0) {
    this.#value = start;
  }
  increment() {
    return ++this.#value;
  }
  static fromArray(arr) {
    return arr.reduce((acc, n) => acc + n, 0);
  }
}

const range = (a, b) => Array.from({ length: b - a }, (_, i) => i + a);

async function* asyncRange(a, b) {
  for (const n of range(a, b)) {
    yield await Promise.resolve(n * 2);
  }
}

const { x = 1, ...rest } = { x: 5, y: 10 };
const tagged = String.raw\`hello \${x}\\nworld\`;
`;

const ast = acorn.parse(source, {
  ecmaVersion: 2024,
  sourceType: "module",
  locations: false,
});

// Build a deterministic summary: walk the AST and emit one line per node.
function summarize(node, depth) {
  const lines = [];
  const indent = "  ".repeat(depth);
  const extra = [];
  if (node.name) extra.push(`name=${node.name}`);
  if (node.value !== undefined && typeof node.value !== "object") {
    extra.push(`value=${JSON.stringify(node.value)}`);
  }
  if (node.operator) extra.push(`op=${node.operator}`);
  if (node.kind) extra.push(`kind=${node.kind}`);
  lines.push(`${indent}${node.type}${extra.length ? " " + extra.join(" ") : ""}`);
  for (const key of Object.keys(node)) {
    const child = node[key];
    if (child && typeof child === "object" && child.type) {
      lines.push(...summarize(child, depth + 1));
    } else if (Array.isArray(child)) {
      for (const c of child) {
        if (c && typeof c === "object" && c.type) {
          lines.push(...summarize(c, depth + 1));
        }
      }
    }
  }
  return lines;
}

console.log(summarize(ast, 0).join("\n"));
