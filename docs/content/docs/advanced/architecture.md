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

`src/Interop/` handles value conversion across the boundary.

- **`PhpToJs::convert()`** — PHP value to `JsValue`.
- **`JsToPhp::convert()`** — `JsValue` to PHP value.
- **`HostFunction`** — wraps a PHP callable as a `JsFunction`.
- **`PhpBridge`** — wraps a PHP object with a custom property map.

Conversion rules are documented in [Value conversion](/docs/interop/values).

## Error handling

JS `throw` becomes a PHP `Phasis\Exceptions\JsThrowable`. PHP exceptions thrown inside host functions become JS `Error` instances. The interpreter catches and re-throws across the boundary so each side sees the exception in its own idiom.

Resource limits (call depth, loop iterations, string length, output size, execution time) live on the `Engine`. When any limit is exceeded, the interpreter throws `Phasis\Exceptions\InternalError`.

## Bytecode VM

Hot functions get lowered two ways. `JsToPhp` (in `src/Bytecode/`) emits a PHP closure for numeric / locals-heavy bodies that PHP's tracing JIT can compile to native; the pipeline lowers `new Ctor(args)`, `obj.method(args)`, and `this.member` reads, which is enough to make the canonical class-method-in-a-loop pattern bypass the VM entirely. Everything else compiles to flat bytecode consumed by `VM::execute`, with a custom callstack that keeps every JS call inside a single PHP frame and a per-PC inline cache for prototype-method reads. Simple generator bodies compile to bytecode too, with `Op::YIELD` capturing a heap frame snapshot instead of crossing PHP Fiber.

Any shape the compiler doesn't lower (e.g. `with`, `try` with a re-binding catch, `yield*`, async generators) falls back to the tree-walker. See [Bytecode VM](/docs/advanced/bytecode-vm) for the full mechanism.
