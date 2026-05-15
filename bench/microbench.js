// Bench harness — each test logs a name and time. Workloads target hot paths.
const tests = {
  "loop-arith": () => {
    let s = 0;
    for (let i = 0; i < 200000; i++) s += i * 2 - 1;
    return s;
  },
  "loop-fib": () => {
    let a = 0, b = 1;
    for (let i = 0; i < 100000; i++) { const t = a + b; a = b; b = t; }
    return b;
  },
  "fn-recurse": () => {
    function fib(n) { return n < 2 ? n : fib(n - 1) + fib(n - 2); }
    return fib(22);
  },
  // Single-line deep recursion. On main this hits the 1024 PHP-
  // stack ceiling; on the custom-callstack branch the inline-call
  // path lets it run to completion. Reports time but the more
  // important signal is whether it crashes — main throws
  // "Maximum call stack size exceeded" before reaching n=0.
  "fn-deep-recurse": () => {
    "use strict";
    function deep(n) { return n === 0 ? 0 : deep(n - 1) + 1; }
    return deep(2000);
  },
  "obj-create": () => {
    let last;
    for (let i = 0; i < 20000; i++) last = { a: i, b: i * 2, c: i + 1 };
    return last.a;
  },
  "obj-prop": () => {
    const o = { x: 1 };
    let s = 0;
    for (let i = 0; i < 100000; i++) { o.x = i; s += o.x; }
    return s;
  },
  "arr-push": () => {
    const a = [];
    for (let i = 0; i < 50000; i++) a.push(i);
    return a.length;
  },
  "arr-map": () => {
    const a = new Array(20000).fill(0).map((_, i) => i);
    return a.reduce((s, v) => s + v, 0);
  },
  "str-concat": () => {
    let s = "";
    for (let i = 0; i < 20000; i++) s += "x";
    return s.length;
  },
  "str-split-join": () => {
    let s = "the quick brown fox jumps over the lazy dog";
    for (let i = 0; i < 5000; i++) s = s.split(" ").join("-");
    return s.length;
  },
  "json-roundtrip": () => {
    const o = { a: 1, b: [1, 2, 3], c: { d: "x", e: true } };
    let last;
    for (let i = 0; i < 5000; i++) last = JSON.parse(JSON.stringify(o));
    return last.a;
  },
  "closure": () => {
    function adder(n) { return x => x + n; }
    const add5 = adder(5);
    let s = 0;
    for (let i = 0; i < 100000; i++) s = add5(s);
    return s;
  },
  "destructure": () => {
    let s = 0;
    for (let i = 0; i < 30000; i++) {
      const { a, b } = { a: i, b: i + 1 };
      s += a + b;
    }
    return s;
  },
};

for (const [name, fn] of Object.entries(tests)) {
  const t0 = Date.now();
  const result = fn();
  const t1 = Date.now();
  console.log(`${name.padEnd(20)} ${(t1 - t0).toString().padStart(6)}ms  result=${result}`);
}
