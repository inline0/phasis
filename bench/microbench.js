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
  // Generator iteration: counts to 10k via a simple snapshot-safe
  // generator (no yield*, no try/finally, no async). On main this
  // runs through PHP Fiber suspension — one Fiber resume per yield.
  // On the frame-snapshot branch the body is BC-compiled and Op::YIELD
  // captures a heap GeneratorSnapshot, so each next() is a normal
  // VM::execute call with no Fiber crossing.
  "gen-iterate": (() => {
    function* counter(n) {
      for (let i = 0; i < n; i++) yield i;
    }
    return () => {
      let s = 0;
      for (const v of counter(10000)) s += v;
      return s;
    };
  })(),
  // Prototype-method call: hits the path that current dataSlots
  // fast-load can't optimize (the method is on the prototype, not
  // own), so every iteration walks the proto chain through
  // lookupMember -> JsObject::get. The inline-cache work makes
  // this fast by remembering the prototype slot per call site.
  // Class is hoisted via the constructor object so the hot loop
  // sits in a VM-compiled arrow body (declaring `class` inline
  // bails out of bytecode compilation today).
  "proto-method": (() => {
    class C {
      constructor(v) { this.v = v; }
      sum(x) { return this.v + x; }
    }
    return () => {
      const c = new C(1);
      let s = 0;
      for (let i = 0; i < 100000; i++) s = c.sum(s);
      return s;
    };
  })(),
  // Constructor-heavy: instantiating 50k Points exercises STORE_MEMBER
  // on *new* own properties (this.x = …, this.y = …), the case the
  // dataSlots fast path can't catch (slot doesn't exist yet on a
  // fresh receiver). Targets the STORE_MEMBER inline cache that
  // remembers the prototype chain has no setter for x/y after the
  // first construct, then short-circuits the writeMember → JsObject::set
  // → ordinarySetWithOwnDescriptor walk on every subsequent call.
  "ctor-heavy": (() => {
    function Point(x, y) {
      this.x = x;
      this.y = y;
    }
    Point.prototype.dist2 = function (other) {
      const dx = this.x - other.x, dy = this.y - other.y;
      return dx * dx + dy * dy;
    };
    return () => {
      const origin = new Point(0, 0);
      let s = 0;
      for (let i = 0; i < 50000; i++) {
        const p = new Point(i, i + 1);
        s += p.dist2(origin);
      }
      return s;
    };
  })(),
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
