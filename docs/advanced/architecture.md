---
title: "Architecture"
description: "A tour through the lexer, parser, AST, runtime, and built-in library — the four layers that turn JS text into PHP-driven execution."
path: "advanced/architecture"
order: 170
section: "Advanced"
meta_title: "Architecture"
meta_description: "A tour through the lexer, parser, AST, runtime, and built-in library — the four layers that turn JS text into PHP-driven execution."
---

# Architecture

Phasis is a four-stage tree walker with an opportunistic bytecode VM. The data path is:

```
JS text
  │
  ▼
┌──────────┐
│  Lexer   │  src/Lexer/
└────┬─────┘
     ▼ tokens
┌──────────┐
│  Parser  │  src/Parser/
└────┬─────┘
     ▼ AST nodes
┌──────────────┐
│ Interpreter  │  src/Runtime/
│  + Bytecode  │  src/Bytecode/
│      VM      │
└────┬─────────┘
     ▼ JsValue
PHP host
```

Each stage is a PHP class with a small surface that the next stage consumes. There's no link step — parsing produces an AST, the interpreter walks it directly.

## Lexer

`src/Lexer/Lexer.php` converts source text into a stream of `Token` instances. Each token is a readonly struct: type (`TokenType` enum, ~80 cases), value, line, column.

The lexer handles:

- Number literals — decimal, hex (`0x`), octal (`0o`), binary (`0b`), exponential, numeric separators.
- String literals — single and double quotes, all escape sequences, template literals with nested expressions.
- Regular expression literals — full `/pattern/flags` tokenisation with awareness of when a `/` should be regex vs division.
- Identifiers, keywords, punctuation.
- Comments (line and block), preserved as trivia.
- Unicode source — UTF-8 bytes interpreted as the JS UTF-16 source.

Output is a generator that yields tokens lazily. The parser consumes them with one-token lookahead.

## Parser

`src/Parser/Parser.php` is a hand-written Pratt parser. Expression precedence lives in `src/Parser/Precedence.php`. Statements have their own recursive descent.

It produces immutable AST nodes from `src/Ast/`. Every node carries its source location for stack traces and error reporting.

Notable shapes:

- **Expression**: `Literal`, `Identifier`, `BinaryExpression`, `UnaryExpression`, `AssignmentExpression`, `CallExpression`, `MemberExpression`, `ArrowFunction`, `ObjectExpression`, `ArrayExpression`, `ConditionalExpression`, `TemplateLiteral`, `SpreadElement`, `NewExpression`, `ThisExpression`, `SequenceExpression`, `TaggedTemplate`, `ClassExpression`, `YieldExpression`, `AwaitExpression`.
- **Statement**: `BlockStatement`, `IfStatement`, `ForStatement`, `ForInStatement`, `ForOfStatement`, `WhileStatement`, `DoWhileStatement`, `SwitchStatement`, `ReturnStatement`, `ThrowStatement`, `TryStatement`, `BreakStatement`, `ContinueStatement`, `ExpressionStatement`.
- **Declaration**: `VariableDeclaration`, `FunctionDeclaration`, `ClassDeclaration`, `ImportDeclaration`.
- **Pattern** (destructuring): `ArrayPattern`, `ObjectPattern`, `RestElement`, `AssignmentPattern`.

Automatic semicolon insertion is implemented in the parser (not the lexer) per spec.

## Interpreter

`src/Runtime/Interpreter.php` is the AST walker. It visits each node and returns a `JsValue` (or a `Completion` for statements that change control flow).

Key supporting classes:

- **`Environment`** (`src/Runtime/Environment.php`) — lexical scope chain. Each function call creates a new environment pointing to its lexical parent. `var` hoists to the function scope; `let` / `const` are block-scoped with a TDZ.
- **`CallStack`** (`src/Runtime/CallStack.php`) — explicit stack with depth limit. Function and method calls push frames; exceptions unwind them.
- **`Completion`** (`src/Runtime/Completion.php`) — discriminated union of `normal`, `return`, `throw`, `break`, `continue` plus an optional value and label.
- **`Reference`** (`src/Runtime/Reference.php`) — spec Reference type for left-hand-side evaluation in assignments and `delete`.

## Values

JavaScript values are PHP objects under `src/Value/`. Each one implements the `JsValue` interface and carries its own type-conversion methods (`toNumber()`, `toString()`, `toBoolean()`).

| Class | JS type |
|---|---|
| `JsUndefined` | `undefined` (singleton) |
| `JsNull` | `null` (singleton) |
| `JsBoolean` | `true` / `false` |
| `JsNumber` | IEEE 754 double; preserves NaN, ±Infinity, ±0 |
| `JsString` | UTF-16 string semantics |
| `JsObject` | base object with property map and prototype chain |
| `JsArray` | extends `JsObject` with length tracking |
| `JsFunction` | closure with captured `Environment` |
| `JsSymbol` | Symbol primitive |
| `JsBigInt` | arbitrary precision via bcmath |
| `JsProxy` | all 13 traps, revocable |
| `JsPromise` | synchronous executor + microtask queue |
| `JsGenerator` | dual-mode: frame-snapshot (bytecode-compiled bodies; see [Bytecode VM § Frame-snapshot generators](/docs/advanced/bytecode-vm)) with PHP Fiber fallback for `yield*`, try-with-yield, and async generators |
| `JsMap`, `JsSet`, `JsWeakMap`, `JsWeakSet`, `JsWeakRef`, `JsFinalizationRegistry` | collections |
| `JsArrayBuffer`, `JsSharedArrayBuffer`, `JsDataView`, `JsTypedArray` | binary data |

Number handling is the single largest source of historical bugs: `NaN !== NaN` must be true, `-0 === 0` must be true, ToNumber edge cases need exact bit-level care. The `Spec/TypeConversion` class implements the spec algorithms verbatim and is exhaustively tested.

## Built-in library

`src/BuiltIn/` provides every standard-library object: `Array`, `String`, `Object`, `Math`, `JSON`, `Date`, `RegExp`, `Map`, `Set`, `Promise`, `Proxy`, `Reflect`, `Symbol`, `BigInt`, `TypedArray`, `Temporal`, `Intl`, `console`, plus the Web Platform / Fetch packs (`URL`, `Headers`, `Request`, `Response`, `Blob`, `FormData`, full Streams, `EventTarget`, `AbortController`/`AbortSignal`, `DOMException`, `structuredClone`, `performance`, `TextEncoder`/`TextDecoder`, `atob`/`btoa`), `crypto` + `SubtleCrypto`, `WebSocket`, `XMLHttpRequest`, and the event loop (`setTimeout`, `setInterval`, `queueMicrotask`, `AsyncContext`).

Each built-in is a static `install()` method on a class. The `Engine` calls them in dependency order at construction. Each one defines its prototype chain, installs methods as `JsFunction` instances wrapped around PHP closures, and exposes the constructor as a global.

## PHP↔JS bridge

Value conversion across the boundary lives in two places:

- **`Phasis\Interop\PhpToJs::convert()`** — PHP value to `JsValue`, used by `setGlobal()`, `call()` arguments and host-function return values. A PHP callable becomes a `JsFunction` whose body calls it.
- **`PhpToJs::toPhp()`** — `JsValue` to PHP for the arguments of a host function.
- **`Engine::toPhp()`** (private) — `JsValue` to PHP for the results of `eval()` and `call()`.

`PhpToJs` converts host scalars and arrays and wraps callables as JS functions. `PhpBridge` exposes non-callable PHP objects by reference through their public properties and methods, preserving identity within a realm. JS function arguments become PHP closures tied to their function realm, and cycle detection terminates object and array conversion. `JsToPhp` in `src/Bytecode/` is the transpiler described below. See [Value conversion](/docs/interop/values).

## Error handling

An uncaught JS `throw` becomes a PHP `Phasis\Exceptions\JsThrowable`; engine errors extend `Phasis\Exceptions\RuntimeError`. JavaScript handlers catch those values and parse errors. PHP host-function throwables are converted at the interop boundary into catchable JS errors, preserving the PHP class name and message; callback throws retain their original JS value.

The runtime enforces a call-stack ceiling (`CallStack::maxDepth`, 4,096 frames, plus an 8,192-frame guard for transpiled closures) and `maxLoopIterations`, configured through `Engine::setLimit()`. Loop counters are local to each loop entry and survive generator suspension. Hosts can also set an execution-time budget with `Engine::setExecutionBudget()`; expiry throws a PHP `BudgetExceeded` exception that JavaScript cannot catch. There are no built-in string-length or output-size limits.

## Bytecode VM

Hot functions get lowered two ways. `JsToPhp` (in `src/Bytecode/`) emits PHP closures for proven numeric bodies and string-scanning loops, which PHP's tracing JIT can optimize. Guards validate assumptions before observable effects; calls, getters and constructors that can expose caller state retain tracked activations. Other supported bodies compile to flat bytecode consumed by `VM::execute`, whose custom callstack keeps eligible bytecode calls inside one PHP frame. Property inline caches retain up to four prototype shapes and check mutation versions. Simple generator bodies compile to bytecode too, with `Op::YIELD` capturing a heap frame snapshot instead of crossing PHP Fiber.

Any shape the compiler doesn't lower (e.g. `with`, `try` with a re-binding catch, `yield*`, async generators) falls back to the tree-walker. See [Bytecode VM](/docs/advanced/bytecode-vm) for the full mechanism.
