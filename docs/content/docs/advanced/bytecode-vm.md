---
title: "Bytecode VM"
description: "How Phasis compiles hot JS functions to PHP closures at runtime — and the bail-out semantics that keep the spec correct."
path: "advanced/bytecode-vm"
order: 180
section: "Advanced"
meta_title: "Bytecode VM"
meta_description: "How Phasis compiles hot JS functions to PHP closures at runtime — and the bail-out semantics that keep the spec correct."
---

# Bytecode VM

The default execution path is a tree-walker — visit each AST node, return a value. For hot functions that get called millions of times, the dispatch overhead per node is the bottleneck. Phasis layers a speculative bytecode-to-PHP-closure compiler on top to cut that overhead.

## When the VM kicks in

Every `JsFunction` has a hot-call counter. Once it crosses a threshold (default 100), the engine attempts to compile the function body to a PHP closure via `src/Bytecode/JsToPhp.php`.

Compilation succeeds when the function body fits a known set of shapes:

- Local variable assignments with predictable types (numeric, object, function, array, string).
- Arithmetic on numbers.
- Member reads on object-typed locals AND on `this` (`this.v`, `obj.x`).
- Method calls on object-typed locals (`obj.method(args)`), dispatched with the receiver threaded through as `$thisValue`.
- `new Ctor(args)` constructor calls — type-promotes the LHS local to `object` and Bailout-guards a non-object return.
- `for` / `while` loops with simple condition shapes.
- `return` with a computable expression.
- `if` / `else` over typed conditions.

Compilation fails (and the function stays on the tree-walker) when the body contains:

- `with` statements.
- `try / catch / finally` (handled by the tree-walker for proper completion semantics).
- `eval()` calls.
- Direct `arguments` mutation.
- Anything the compiler doesn't yet know how to lower.

When compilation succeeds, the engine swaps the function's call path to the compiled closure. Subsequent calls run native PHP with one function-call overhead, not one-per-AST-node.

## Bailouts

The compiled fast path makes assumptions (numbers stay numbers, properties stay on the same shape). When an assumption is violated at runtime, the closure throws a `Bailout` and execution restarts in the tree-walker for that call.

This is where spec correctness matters most. The tree-walker has to be able to re-execute the bailing-out call from scratch without observing any side effects from the failed fast path. Phasis handles this in two ways:

1. **Pure prefixes** — the compiler scans for side effects before any speculation. If the function does I/O (console.log, host functions) before the speculation point, the fast path doesn't elide it; it commits and continues.
2. **Compile-time refusal** — for shapes that would require rolling back observable side effects (a non-numeric `let r = fn()` where `fn()` mutates state), the compiler refuses to compile at all. The function stays on the tree-walker.

The motivating case: a non-numeric assignment executes `fn()` on the fast path, throws `Bailout`, then re-executes on the tree-walker — running `fn()` twice with two sets of side effects. Compile-time refusal keeps `fn()` from running on the fast path at all, so the tree-walker observes the only call.

## VM dispatch (the second layer)

Some functions compile to PHP closures; others fall through to an even simpler bytecode interpreter in `src/Bytecode/VM.php`. The bytecode is a flat array of opcodes (`LOAD_LOCAL`, `STORE_PROP`, `CALL_METHOD`, …) consumed by a `switch` dispatch.

The VM exists for functions that aren't worth compiling to a PHP closure but are still hot enough to benefit from skipping AST traversal. Method calls on built-in objects (Array.prototype.map, String.prototype.split, …) go through fast paths in the VM that bypass the generic `callFunction` trampoline.

## Custom callstack

`Op::CALL`, `Op::CALL_METHOD`, and `Op::NEW_CALL` use an inline-call path that keeps the entire JS callstack inside `VM::execute` — no new PHP frame per JS call. On entry, the caller's state (pc, code stream, operand stack, locals, env, `this`, strict-mode flag) is pushed onto a per-VM `savedFrames` pool of `SavedFrame` instances and the dispatch loop rewires its locals to the callee. `Op::RET` pops the saved frame and restores. Exceptions unwind across saved frames before falling back to the tree-walker.

Eligibility is memoised on `JsFunction`: `vmInlineCallCache` for regular calls (BC-compiled body, not `bind()`-bound, not native), `vmInlineConstructCache` for `new`-expressions (additionally `isConstructable`, not derived, no instance-field or private-method initializers). Anything else still flows through the canonical `callFunction` / `vmNewExpression` trampoline.

The architectural side-benefit: the PHP-stack ceiling no longer caps JS recursion depth. `CallStack::maxDepth` is the only limit now — set to **4 096**, comfortably above every legitimate test262 case (most need under 2 k frames) and tight enough that pathological infinite-recursion tests fail fast inside the runner's timeout budget. The transpiled-closure path (`JsToPhp`) has its own 8 192-frame guard for the non-inlined fallback.

## Frame-snapshot generators

Simple generator bodies — no `yield*`, no `yield` inside try/catch/finally, no `await` — compile to bytecode. The compiler emits a new `Op::YIELD` opcode where the body suspends; `Op::YIELD` captures the dispatch state (cf, pc+1, stack, sp, locals, env, thisValue, strict) into a `GeneratorSnapshot` heap object and returns a `YieldResult` sentinel from `VM::execute`. The driving `JsGenerator` stores the snapshot and feeds it back to `VM::execute` on the next `next()` / `throw()`, replacing the resume-value at the top of the operand stack.

This replaces the PHP-Fiber path for the supported subset. The Fiber-based driver (`executeGeneratorBody`) is still in place for shapes the compiler rejects — `yield*` delegation, generators that yield inside a try-block, async generators, async functions with `await`. `JsGenerator` is dual-mode: each `next()` / `throw()` / `return()` checks whether the instance has a snapshot or a Fiber and dispatches accordingly.

No bench-tunable knob; eligibility is decided at compile time and memoised on `JsFunction`. The win is one fewer PHP context switch per yield in the snapshot path; the Fiber path is unchanged for everything else.

## Inline caches

`Op::LOAD_MEMBER` carries a per-PC inline cache keyed on prototype identity, used for the prototype-method-on-instance pattern (`c.sum(...)`, `arr.push(...)`, every class-method dispatch). Hit condition: the receiver has no own slot for the name, the cached prototype reference is identical to the receiver's `getPrototype()`, and the prototype's `PropertyMap::$version` still matches the captured version.

Cache invalidation rides on `PropertyMap::$version`. Structural mutations (set / delete) bump it unconditionally. Data-slot value overwrites bump only when `PropertyMap::$isUsedAsProto` is true — a write-barrier flag flipped on the first time the object is observed as another object's `[[Prototype]]`. Instance objects that never serve as prototypes pay nothing for the IC infrastructure on every `obj.x = v`.

Getter accessors are never cached (they must re-run on every read).

`PropertyMap` carries a second counter, `$mutationVersion`, that bumps *unconditionally* on every write (no write-barrier). The `JSON.stringify` cache pins this counter on every visited nested object/array so that an instance-only mutation like `o.a = 99` correctly invalidates the cached string even when the IC's `$version` would have skipped the bump.

## JSON.stringify cache

`JsObject` carries `$jsonCacheString` + `$jsonCacheVersions` (a list of `[obj, mutationVersion]` pairs for every nested object visited during the trivial-stringify walk). On the next `JSON.stringify(o)` call with no replacer / no space, the trivial fast path verifies every pinned version is unchanged and returns the cached string without re-walking or re-encoding. Hot deep-clone patterns like `JSON.parse(JSON.stringify(o))` get a roughly 2× speedup on the stringify half.

The cache is dropped silently on miss (any pinned version differs) and refilled by the next walk. Objects with `toJSON`, getters, accessors, symbols, or `[[PrimitiveValue]]` slots bail to the spec serialiser and never populate the cache — so the cached path only ever sees structurally-uniform data.

## Built-in fast paths

The largest perf wins came from teaching the VM about specific built-ins:

- **`Atomics.load` / `store` / `compareExchange`** with `loadSpinHook` / `storeNotifyHook` cooperative scheduling — recovered 4 Atomics spin-loop tests.
- **`Date.prototype.getTimezoneOffset`** with a direct-hashmap DST cache — 45 % speedup on SpiderMonkey DST stress.
- **`String.fromCharCode`** with an inline ASCII fast path — saves ~1 M dispatches per typical sweep.
- **`decodeURI` / `decodeURIComponent`** with a 12-char `%XX%XX%XX%XX` ultra-fast path using `hex2bin` bulk decode.
- **`Object.keys` / `Object.values` / `Object.entries`** with direct property-map access.
- **`Array.prototype.map` / `filter` / `forEach` / `reduce`** with native iteration over the underlying PHP array.

Each fast path is gated by a "shape check" — if the receiver doesn't match the expected shape (e.g. a TypedArray view that isn't backed by a plain PHP `string`), the VM falls through to the spec-correct slow path.

## What this doesn't do

The bytecode VM is **not a JIT** in the V8 sense. It doesn't generate native machine code per-function and doesn't speculate based on observed types beyond the IC's monomorphic prototype-identity check. Function-boundary inlining is approximated by the custom callstack (no extra PHP frame per JS call) rather than by lowering the callee's body into the caller's bytecode.

The JsToPhp transpiler is the closest the engine has to a JIT: it generates PHP source for hot function bodies that PHP 8.5's tracing JIT then compiles to native. JsToPhp goes through `eval()`, so it pays a one-time compile tax and the tracing JIT has to discover the function as if it were user code. Skipping the `eval()` step by emitting opcache opcodes directly would close more of the gap — see [Benchmarks § Roadmap](/docs/advanced/benchmarks#roadmap).

## Bench

`bench/microbench.js` runs a dozen-and-change tiny benchmarks (loop-arith, loop-fib, fn-recurse, fn-deep-recurse, obj-create, obj-prop, proto-method, gen-iterate, arr-push, arr-map, str-concat, str-split-join, json-roundtrip, closure, destructure). Median wall time:

```bash
php bench/run.php
```

Current numbers are committed in `BENCH.md` after each `bench` workflow run. The full test262 suite + bench run together in ~6 min on the CI matrix.
