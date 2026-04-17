# php-js

Pure PHP JavaScript engine. Lexes, parses, and executes ECMAScript without shelling out to Node.js, without FFI, without extensions. Node.js (V8) is the oracle: if V8 produces a result, php-js must produce the same result. Compliance is measured against test262, the official ECMAScript conformance test suite.

## Quick Reference

```bash
# Testing (oracle-driven)
./bin/verify-all                         # Required final gate: analyse + cs + phpunit + full oracle regression
./bin/test-scenario <name>               # Single scenario: oracle → actual → compare
./bin/test-regression                    # All scenarios
./bin/test-regression --jobs 4           # Parallel
./bin/test-regression --category expressions  # By category
./bin/test-regression --fast             # Pass/fail only, no reports
./bin/support-report                     # Deprecated alias for compat-report
./bin/compat-report                      # Generate compat.json + COMPAT.md from full test262 coverage
./bin/verify-compliance                  # Compatibility alias for compat-report
./bin/compliance-report                  # Optional sampled test262-only compliance.json

# test262 suite
./bin/test262                            # Run full test262 suite
./bin/test262 --category built-ins/Array # Run subset
./bin/test262 --jobs 4                   # Parallel
./bin/test262 --report                   # Generate compliance percentage report

# Oracle management
./bin/oracle <name>                      # Capture Node.js output for scenario
./bin/oracle --refresh <name>            # Re-capture (after Node version update)
./bin/actual <name>                      # Run php-js, capture output
./bin/compare <name>                     # Diff oracle vs actual

# Unit tests (no Node.js needed)
composer test:unit                       # Isolated component tests
composer test                            # PHPUnit suites from phpunit.xml

# Code quality
composer cs                              # Check coding standards
composer cs:fix                          # Fix coding standards
composer analyse                         # PHPStan static analysis

# CLI
./bin/php-js script.js                   # Execute a JavaScript file
./bin/php-js -e "1 + 2"                  # Evaluate expression
./bin/php-js --repl                      # Interactive REPL
```

## Non-Negotiable Testing Rule

After every meaningful work pass, run the full matrix from the repo root before treating the work as done:

```bash
./bin/verify-all
```

No partial sign-off. `./bin/verify-all` is the repo gate.

After any meaningful compliance work (not every small fix, but after a batch of improvements), update the canonical compatibility snapshot:

```bash
./bin/compat-report --jobs 4
```

This writes `compat.json` and `COMPAT.md`. Commit both. This is how compliance is tracked across sessions. Do not rely on ad-hoc test runs for compliance numbers.

test262 compliance must never regress. If a change reduces the test262 pass count, the change is broken.

## What This Is

A JavaScript interpreter that:

1. Lexes source text into tokens (identifiers, literals, operators, keywords)
2. Parses tokens into an AST (expressions, statements, declarations, functions)
3. Evaluates the AST in an environment with scope chains and closures
4. Implements the full ECMAScript standard library (Array, String, Object, Math, JSON, Date, RegExp, Map, Set, Promise, Proxy, Reflect, Symbol, TypedArray, generators, async/await, BigInt, and more)
5. Supports modern syntax (arrow functions, destructuring, template literals, let/const, classes, optional chaining, nullish coalescing, for-of, spread/rest, computed properties)
6. Provides direct PHP interop (share objects between PHP and JS without serialization)
7. Achieves 95%+ test262 compliance across major built-in categories (see COMPAT.md)

All without `exec('node ...')`. The only PHP requirement is PHP 8.2+ with `mbstring`. No extensions.

## What This Is Not

Not a JIT compiler. Not a competitor to V8 for raw throughput. This is a tree-walking interpreter. It will be 100-1000x slower than V8. The value is: zero dependencies, pure PHP, runs anywhere PHP runs, and the host controls the entire execution environment. Direct PHP-JS object bridging without serialization.

## Project Structure

```
php-js/
├── src/
│   ├── Engine.php                       # Top-level facade (eval, execFile, setGlobal, etc.)
│   │
│   ├── Lexer/
│   │   ├── Lexer.php                    # Source text → token stream
│   │   ├── Token.php                    # Readonly: type, value, line, column
│   │   ├── TokenType.php               # Enum: all token types (~80 types)
│   │   └── SourceLocation.php          # Readonly: line, column, offset
│   │
│   ├── Parser/
│   │   ├── Parser.php                   # Token stream → AST
│   │   ├── Precedence.php              # Operator precedence table (Pratt parsing)
│   │   └── ParseError.php              # Syntax error with location
│   │
│   ├── Ast/
│   │   ├── Node.php                     # Base AST node (readonly, with location)
│   │   ├── Program.php                  # Top-level program node
│   │   ├── Expression/
│   │   │   ├── Literal.php              # Number, string, boolean, null, undefined
│   │   │   ├── Identifier.php           # Variable reference
│   │   │   ├── BinaryExpression.php     # a + b, a === b, etc.
│   │   │   ├── UnaryExpression.php      # !a, -a, typeof a, etc.
│   │   │   ├── AssignmentExpression.php # a = b, a += b, etc.
│   │   │   ├── CallExpression.php       # foo(a, b)
│   │   │   ├── MemberExpression.php     # obj.prop, obj[expr]
│   │   │   ├── ArrowFunction.php        # (a, b) => expr
│   │   │   ├── FunctionExpression.php   # function(a, b) { ... }
│   │   │   ├── ObjectExpression.php     # { key: value }
│   │   │   ├── ArrayExpression.php      # [a, b, c]
│   │   │   ├── ConditionalExpression.php # a ? b : c
│   │   │   ├── TemplateLiteral.php      # `hello ${name}`
│   │   │   ├── SpreadElement.php        # ...args
│   │   │   ├── NewExpression.php        # new Foo()
│   │   │   ├── ThisExpression.php       # this
│   │   │   ├── SequenceExpression.php   # a, b (comma operator)
│   │   │   ├── TaggedTemplate.php       # tag`template`
│   │   │   ├── ClassExpression.php      # class { ... }
│   │   │   ├── YieldExpression.php      # yield value
│   │   │   └── AwaitExpression.php      # await promise
│   │   ├── Statement/
│   │   │   ├── BlockStatement.php       # { ... }
│   │   │   ├── IfStatement.php          # if/else
│   │   │   ├── ForStatement.php         # for (;;)
│   │   │   ├── ForInStatement.php       # for (x in obj)
│   │   │   ├── ForOfStatement.php       # for (x of iterable)
│   │   │   ├── WhileStatement.php       # while
│   │   │   ├── DoWhileStatement.php     # do...while
│   │   │   ├── SwitchStatement.php      # switch/case
│   │   │   ├── ReturnStatement.php      # return
│   │   │   ├── ThrowStatement.php       # throw
│   │   │   ├── TryStatement.php         # try/catch/finally
│   │   │   ├── BreakStatement.php       # break
│   │   │   ├── ContinueStatement.php    # continue
│   │   │   └── ExpressionStatement.php  # expression;
│   │   ├── Declaration/
│   │   │   ├── VariableDeclaration.php  # let, const, var
│   │   │   ├── FunctionDeclaration.php  # function foo() { ... }
│   │   │   ├── ClassDeclaration.php     # class Foo { ... }
│   │   │   └── ImportDeclaration.php    # import (future)
│   │   └── Pattern/
│   │       ├── ArrayPattern.php         # [a, b] = arr (destructuring)
│   │       ├── ObjectPattern.php        # {a, b} = obj (destructuring)
│   │       ├── RestElement.php          # ...rest
│   │       └── AssignmentPattern.php    # a = default
│   │
│   ├── Runtime/
│   │   ├── Interpreter.php              # AST walker: visit each node, return value
│   │   ├── Environment.php              # Scope chain (lexical environments)
│   │   ├── CallStack.php                # Function call stack with depth limit
│   │   ├── Completion.php               # Completion record (normal, return, throw, break, continue)
│   │   └── Reference.php               # Reference type (base + property name, for assignment)
│   │
│   ├── Value/
│   │   ├── JsValue.php                  # Base interface for all JS values
│   │   ├── JsUndefined.php              # undefined (singleton)
│   │   ├── JsNull.php                   # null (singleton)
│   │   ├── JsBoolean.php               # true / false
│   │   ├── JsNumber.php                # IEEE 754 double (NaN, Infinity, -0 handling)
│   │   ├── JsString.php                # UTF-16 string semantics
│   │   ├── JsObject.php                # Object with property map and prototype chain
│   │   ├── JsArray.php                 # Array (extends JsObject with length tracking)
│   │   ├── JsFunction.php              # Function (closure with captured environment)
│   │   ├── JsSymbol.php                # Symbol primitive
│   │   ├── JsBigInt.php                # BigInt (arbitrary precision via bcmath)
│   │   ├── JsProxy.php                 # Proxy (all traps, revocable)
│   │   ├── JsPromise.php               # Promise (synchronous execution model)
│   │   ├── JsGenerator.php             # Generator (PHP Fiber-based)
│   │   ├── JsMap.php                   # Map (slot-based live iteration)
│   │   ├── JsSet.php                   # Set (slot-based live iteration)
│   │   ├── JsWeakMap.php               # WeakMap
│   │   ├── JsWeakSet.php               # WeakSet
│   │   ├── JsArrayBuffer.php           # ArrayBuffer (binary data)
│   │   ├── JsTypedArray.php            # TypedArray (all 11 types)
│   │   └── JsDataView.php              # DataView (mixed-type buffer access)
│   │
│   ├── Object/
│   │   └── PropertyDescriptor.php       # {value, writable, enumerable, configurable} or {get, set}
│   │
│   ├── BuiltIn/
│   │   ├── GlobalObject.php             # Global scope (parseInt, isNaN, eval, call/apply/bind)
│   │   ├── ObjectConstructor.php        # Object, Object.keys, Object.assign, freeze, seal, etc.
│   │   ├── ArrayConstructor.php         # Array, Array.from, Array.isArray, all prototype methods
│   │   ├── StringPrototype.php          # String, String.fromCharCode, all prototype methods
│   │   ├── NumberConstructor.php        # Number, Number.isFinite, Number.parseInt
│   │   ├── MathObject.php               # Math.floor, Math.random, Math.max, etc.
│   │   ├── JsonObject.php               # JSON.parse, JSON.stringify
│   │   ├── DateConstructor.php          # Date (wraps PHP DateTime)
│   │   ├── RegExpPrototype.php          # RegExp prototype (Symbol.match/replace/split/search)
│   │   ├── ErrorConstructor.php         # Error, TypeError, RangeError, etc.
│   │   ├── PromiseConstructor.php       # Promise, Promise.all/race/any/allSettled
│   │   ├── ProxyConstructor.php         # Proxy, Proxy.revocable
│   │   ├── ReflectObject.php            # Reflect (13 static methods)
│   │   ├── SymbolConstructor.php        # Symbol, well-known symbols, Symbol.for/keyFor
│   │   ├── MapConstructor.php           # Map (with groupBy, live iteration)
│   │   ├── SetConstructor.php           # Set (live iteration)
│   │   ├── WeakMapConstructor.php       # WeakMap
│   │   ├── WeakSetConstructor.php       # WeakSet
│   │   ├── TypedArrayConstructor.php    # ArrayBuffer, DataView, all 11 TypedArray types
│   │   └── ConsoleObject.php            # console.log, console.error, etc.
│   │
│   ├── Interop/
│   │   ├── PhpBridge.php                # Expose PHP values/objects to JS
│   │   ├── JsToPhp.php                 # Convert JS values to PHP types
│   │   ├── PhpToJs.php                 # Convert PHP types to JS values
│   │   └── HostFunction.php            # Wrap PHP callable as JS function
│   │
│   ├── Spec/
│   │   ├── TypeConversion.php           # ToNumber, ToString, ToBoolean, ToPrimitive, ToObject
│   │   └── AbstractOperations.php       # Equality, relational comparison, instanceof
│   │
│   └── Exceptions/
│       ├── SyntaxError.php              # Parse error
│       ├── RuntimeError.php             # Generic runtime error
│       ├── TypeError.php                # Type mismatch
│       ├── ReferenceError.php           # Undeclared variable
│       ├── RangeError.php               # Value out of range
│       └── InternalError.php            # Stack overflow, resource limits
│
├── bin/
│   ├── php-js                           # CLI entry point (run files, eval, REPL)
│   ├── oracle                           # Capture Node.js output for a scenario
│   ├── actual                           # Run php-js, capture output
│   ├── compare                          # Diff oracle vs actual
│   ├── test-scenario                    # Full pipeline: oracle → actual → compare
│   ├── test-regression                  # Run all scenarios
│   ├── support-report                   # Deprecated alias for compat-report
│   ├── compat-report                    # Generate compat.json + COMPAT.md from full test262 coverage
│   ├── verify-compliance                # Compatibility alias for compat-report
│   ├── compliance-report                # Generate sampled test262-only compliance.json
│   ├── verify-all                       # analyse + cs + phpunit + oracle regression
│   └── test262                          # Run official test262 suite
│
├── tests/
│   ├── Unit/                            # Isolated component tests
│   │   ├── Lexer/
│   │   │   ├── LexerTest.php            # Token output for known inputs
│   │   │   ├── NumberLiteralTest.php     # 0x, 0o, 0b, exponential, decimals
│   │   │   ├── StringLiteralTest.php     # Escapes, unicode, template literals
│   │   │   └── RegExpLiteralTest.php     # /pattern/flags tokenization
│   │   ├── Parser/
│   │   │   ├── ExpressionParserTest.php  # Precedence, associativity
│   │   │   ├── StatementParserTest.php   # All statement types
│   │   │   ├── DestructuringTest.php     # Array and object patterns
│   │   │   └── ArrowFunctionTest.php     # Arrow function edge cases
│   │   ├── Runtime/
│   │   │   ├── EnvironmentTest.php       # Scope chain, closures, hoisting
│   │   │   ├── TypeConversionTest.php    # ToNumber, ToString, ToBoolean edge cases
│   │   │   └── CompletionTest.php        # Return, throw, break propagation
│   │   ├── Value/
│   │   │   ├── JsNumberTest.php          # NaN, Infinity, -0, IEEE 754 edge cases
│   │   │   ├── JsStringTest.php          # UTF-16 semantics, surrogate pairs
│   │   │   └── JsObjectTest.php          # Property descriptors, prototype chain
│   │   └── Interop/
│   │       ├── PhpBridgeTest.php         # PHP ↔ JS value conversion
│   │       └── HostFunctionTest.php      # PHP callables in JS
│   └── Oracle/
│       ├── OracleCapture.php            # Runs Node.js, captures output
│       ├── ActualCapture.php            # Runs php-js, captures output
│       ├── ScenarioComparator.php       # Diffs oracle vs actual
│       ├── ScenarioRunner.php           # Orchestrates: setup → oracle → actual → compare
│       ├── ScenarioRepository.php       # Discovers and loads scenarios
│       └── Test262Runner.php            # Adapter for running official test262 tests
│
├── scenarios/
│   ├── literals/                        # Literal evaluation
│   │   ├── number-integers/
│   │   ├── number-floats/
│   │   ├── number-special/              # NaN, Infinity, -0
│   │   ├── string-basic/
│   │   ├── string-escapes/
│   │   ├── string-unicode/
│   │   ├── template-literals/
│   │   ├── boolean/
│   │   ├── null-undefined/
│   │   └── regex/
│   │
│   ├── operators/                       # Operator evaluation
│   │   ├── arithmetic/
│   │   ├── comparison/
│   │   ├── equality/                    # == vs ===, type coercion
│   │   ├── logical/                     # &&, ||, ??
│   │   ├── bitwise/
│   │   ├── assignment/
│   │   ├── unary/                       # typeof, void, delete, !, ~, +, -
│   │   ├── optional-chaining/           # ?.
│   │   └── spread/                      # ...
│   │
│   ├── variables/                       # Variable semantics
│   │   ├── var-hoisting/
│   │   ├── let-const/
│   │   ├── tdz/                         # Temporal dead zone
│   │   └── scoping/                     # Block scope, function scope
│   │
│   ├── control-flow/                    # Control flow
│   │   ├── if-else/
│   │   ├── for-loop/
│   │   ├── for-in/
│   │   ├── for-of/
│   │   ├── while/
│   │   ├── do-while/
│   │   ├── switch-case/
│   │   ├── try-catch/
│   │   ├── break-continue/
│   │   └── labels/
│   │
│   ├── functions/                       # Functions
│   │   ├── declaration/
│   │   ├── expression/
│   │   ├── arrow/
│   │   ├── closures/
│   │   ├── default-params/
│   │   ├── rest-params/
│   │   ├── arguments-object/
│   │   ├── recursion/
│   │   └── hoisting/
│   │
│   ├── objects/                         # Objects
│   │   ├── literals/
│   │   ├── property-access/
│   │   ├── computed-properties/
│   │   ├── shorthand/
│   │   ├── methods/
│   │   ├── getters-setters/
│   │   ├── prototype-chain/
│   │   ├── this-binding/
│   │   ├── destructuring/
│   │   └── spread-rest/
│   │
│   ├── arrays/                          # Arrays
│   │   ├── literals/
│   │   ├── methods/                     # map, filter, reduce, etc.
│   │   ├── destructuring/
│   │   ├── spread/
│   │   └── iteration/
│   │
│   ├── classes/                         # Classes
│   │   ├── basic/
│   │   ├── inheritance/
│   │   ├── static-methods/
│   │   ├── getters-setters/
│   │   └── constructor/
│   │
│   ├── builtins/                        # Built-in objects
│   │   ├── math/
│   │   ├── json/
│   │   ├── date/
│   │   ├── regexp/
│   │   ├── error/
│   │   ├── map-set/
│   │   └── console/
│   │
│   ├── errors/                          # Error handling
│   │   ├── syntax-errors/               # Must throw SyntaxError at parse time
│   │   ├── reference-errors/            # Undeclared variables
│   │   ├── type-errors/                 # Wrong type operations
│   │   └── range-errors/                # Stack overflow, invalid array length
│   │
│   ├── interop/                         # PHP-JS interop
│   │   ├── php-to-js/                   # PHP values exposed to JS
│   │   ├── js-to-php/                   # JS results back to PHP
│   │   ├── host-functions/              # PHP callables in JS
│   │   └── shared-objects/              # Direct object bridging
│   │
│   └── edge/                            # Edge cases
│       ├── empty-program/
│       ├── semicolon-insertion/          # ASI
│       ├── unicode-identifiers/
│       ├── strict-mode/
│       └── stack-overflow/
│
├── test262/                             # Official test262 suite (submodule or vendored)
│   ├── harness/                         # assert.js, sta.js, etc.
│   └── test/                            # The actual 50,000+ test files
│       ├── language/
│       ├── built-ins/
│       ├── annexB/
│       └── staging/
│
├── config/
│   └── support.php                      # test262 category list and skipped features
│
├── compat.json                          # Machine-readable compatibility snapshot
├── COMPAT.md                            # Human-readable compatibility report
├── composer.json
├── phpunit.xml.dist
├── phpcs.xml
└── CLAUDE.md
```

## Public API

### Engine (facade)

```php
$engine = new Engine();

// Evaluate JavaScript
$result = $engine->eval('1 + 2');                              // 3
$result = $engine->eval('[1,2,3].map(x => x * 2)');           // [2, 4, 6]
$result = $engine->eval('JSON.stringify({a: 1})');             // '{"a":1}'

// Execute a file
$result = $engine->execFile('script.js');

// Set global variables (PHP → JS)
$engine->setGlobal('config', ['debug' => true, 'version' => '1.0']);
$engine->setGlobal('log', fn(string $msg) => error_log($msg));

// Expose PHP objects directly (no serialization)
$engine->setGlobal('wp', $wordPressBridge);
$result = $engine->eval('wp.getOption("blogname")');

// Call JS functions from PHP
$engine->eval('function add(a, b) { return a + b; }');
$result = $engine->call('add', 2, 3);                         // 5

// REPL
$engine->repl();

// Resource limits
$engine->setLimit('maxCallDepth', 100);
$engine->setLimit('maxLoopIterations', 100_000);
$engine->setLimit('maxStringLength', 10 * 1024 * 1024);

// Reset state
$engine->reset();
```

### PHP-JS Interop

```php
$engine = new Engine();

// PHP values auto-convert to JS values
$engine->setGlobal('count', 42);                               // JsNumber
$engine->setGlobal('name', 'Dennis');                           // JsString
$engine->setGlobal('items', [1, 2, 3]);                        // JsArray
$engine->setGlobal('config', ['key' => 'val']);                 // JsObject

// PHP callables become JS functions
$engine->setGlobal('fetchData', function(string $url): array {
    return json_decode(file_get_contents($url), true);
});
$engine->eval('const data = fetchData("https://api.example.com")');

// JS results auto-convert to PHP values
$result = $engine->eval('[1,2,3].filter(x => x > 1)');         // PHP array [2, 3]
$result = $engine->eval('({name: "Dennis", age: 30})');         // PHP associative array

// Direct object bridging (no serialization)
$engine->setGlobal('post', $wpPost);                            // PHP object accessible in JS
$engine->eval('post.title = "New Title"');                       // Mutates the PHP object
```

## Configuration

| Constant | Default | Description |
|---|---|---|
| `PHPJS_MAX_CALL_DEPTH` | `100` | Maximum function call stack depth |
| `PHPJS_MAX_LOOP_ITERATIONS` | `100000` | Maximum iterations per loop |
| `PHPJS_MAX_STRING_LENGTH` | `10M` | Maximum string length in bytes |
| `PHPJS_MAX_OUTPUT_SIZE` | `10M` | Maximum console output size |
| `PHPJS_MAX_EXECUTION_TIME` | `60` | Maximum execution time in seconds |
| `PHPJS_STRICT_MODE` | `false` | Default strict mode for all scripts |

## Key Rules

1. Pure PHP. No extensions beyond `mbstring` (for UTF-16 string handling). No FFI. No `exec()`. No Node.js at runtime. The entire point is to run JavaScript inside PHP without external dependencies.
2. Node.js (V8) is the oracle, test262 is the test suite. When behavior is ambiguous, run it in Node.js. When compliance is questioned, run test262. Never invent semantics.
3. The interpreter is a tree-walker. Parse to AST, walk the AST, evaluate each node. No bytecode compilation, no JIT. Simplicity and correctness over performance.
4. JavaScript values are PHP objects. `JsNumber`, `JsString`, `JsObject`, etc. Each has its own type conversion methods (`toNumber()`, `toString()`, `toBoolean()`). Never use raw PHP types internally.
5. IEEE 754 double precision for all numbers. Use PHP's `pack('d', ...)` / `unpack('d', ...)` for bit-level operations. Handle NaN, Infinity, -0 correctly. `NaN !== NaN` must be true. `-0 === 0` must be true. This is the single largest source of bugs.
6. Strings are conceptually UTF-16. JavaScript strings are sequences of UTF-16 code units. PHP strings are bytes. Bridge carefully: `String.length` counts UTF-16 code units, not bytes or codepoints. Surrogate pairs must work correctly.
7. Scope chains are linked lists of `Environment` objects. Each function creates a new environment pointing to its lexical parent. `var` hoists to function scope. `let`/`const` are block-scoped with temporal dead zone. Closures capture the environment, not values.
8. `this` binding follows the spec. Default `this` is `undefined` in strict mode, global object in sloppy mode. Method calls bind `this` to the receiver. Arrow functions capture `this` from enclosing scope. `call`/`apply`/`bind` override `this`.
9. Prototypes are linked objects, not classes. Every object has a `[[Prototype]]` internal slot. Property lookup walks the chain. `Object.create(null)` produces an object with no prototype. Constructor functions set prototype via `new`.
10. Error handling maps to PHP exceptions. JS `throw` becomes a PHP exception caught by the interpreter. JS `try/catch` catches it. Unhandled throws propagate to the PHP caller as `RuntimeError`.
11. Type coercion follows the spec exactly. `ToPrimitive`, `ToNumber`, `ToString`, `ToBoolean` are implemented as spec algorithms. `==` uses Abstract Equality Comparison. `===` uses Strict Equality. These are the second largest source of bugs after number handling.
12. Resource limits are enforced. Call stack depth, loop iterations, string length, and execution time are all bounded. Exceeding a limit throws `InternalError`. The host controls the sandbox.
13. PHP 8.2+. Use readonly classes for AST nodes and tokens. Use enums for `TokenType`, `Completion` types. Use match expressions for node dispatch. Use constructor promotion. Use named arguments.
14. The harness helper files (`assert.js`, `sta.js`) must be loaded before every test262 test. Implement them faithfully: `assert.sameValue`, `assert.throws`, `assert.compareArray`, `$DONE` for async tests.
15. Automatic semicolon insertion (ASI) must follow the spec. This is tricky: line terminators before certain tokens trigger insertion. The parser must handle this, not the lexer.

## Oracle Model

Same oracle-driven verification model as pitmaster, greph, and php-browser (sibling projects in this repo). The principle is identical:

**Chromium is to php-browser as canonical `git` is to Pitmaster as Node.js (V8) is to php-js.**

```
1. SETUP    → a JavaScript source file or snippet
2. ORACLE   → Node.js executes it, output is captured as truth
3. ACTUAL   → php-js executes it, output is captured
4. COMPARE  → oracle vs actual, diff measures the gap
```

### Relationship to sibling projects

| Concept | php-browser | pitmaster | greph | php-js |
|---|---|---|---|---|
| Oracle | Chromium | `git` | `grep` + `rg` + `sg` | Node.js (V8) |
| Actual | PHP renderer | Pitmaster | greph | php-js |
| Test suite | Fixture snapshots | git interop | Scenario corpus | **test262** (50,000+ tests) |
| Pipeline | oracle → render → compare | oracle → actual → compare | oracle → actual → compare | oracle → actual → compare |
| Combined | `./bin/test-fixture` | `./bin/test-scenario` | `./bin/test-scenario` | `./bin/test-scenario` |
| Regression | `./bin/test-regression` | `./bin/test-regression` | `./bin/test-regression` | `./bin/test-regression` |
| Compliance | CSS_COVERAGE.md | support-report | support-report | **COMPAT.md** |

Study `pitmaster/tests/Oracle/` and `greph/tests/Oracle/` for the reference implementation of the oracle pattern.

### Two levels of oracle testing

**Level 1: Custom scenarios (oracle = Node.js)**

Each scenario is a small JS program with known output. Same structure as pitmaster:

```
scenarios/operators/arithmetic/
├── scenario.json
├── setup/
│   └── test.js                   # console.log(1 + 2); console.log("a" + "b");
├── oracle/
│   └── output.txt                # Node.js output: "3\nab\n"
├── actual/
│   └── output.txt                # php-js output
└── reports/
    └── comparison.json
```

**Level 2: test262 suite (oracle = the test suite itself)**

test262 tests are self-verifying: they pass if they don't throw, fail if they do (or the reverse for `negative` tests). No external oracle needed for pass/fail. But Node.js is used to verify expected behavior when a test's intent is unclear.

```
test262/test/language/expressions/addition/S11.6.1_A1.js
    ├── Frontmatter: { description, features, includes, flags, negative }
    ├── Harness: assert.js, sta.js loaded first
    ├── Execute in php-js
    └── Result: PASS (no exception) or FAIL (exception / wrong exception)
```

### test262 Test Format

Each test262 file has:

```javascript
/*---
description: What this test verifies
esid: sec-addition-operator
features: [arrow-function]
includes: [assert.js]
flags: [onlyStrict]
negative:
  type: SyntaxError
  phase: parse
---*/

// Test body
assert.sameValue(1 + 2, 3);
```

**Frontmatter fields:**

| Field | Purpose |
|---|---|
| `description` | What the test validates |
| `esid` | ECMAScript spec section |
| `features` | Required language features (skip test if feature not implemented) |
| `includes` | Harness files to load before test (e.g., `assert.js`, `sta.js`) |
| `flags` | Execution modifiers: `onlyStrict`, `noStrict`, `module`, `async`, `raw` |
| `negative` | Test MUST throw: `{type: ErrorType, phase: parse|runtime}` |

**Execution rules:**
- Each test runs in a fresh realm (isolated global scope)
- Unless `onlyStrict` or `noStrict`, run twice: sloppy mode AND strict mode
- For strict mode, prepend `"use strict";` to source
- Harness files (`includes`) are evaluated in global scope before the test
- `async` tests pass when `$DONE()` is called without arguments, fail on `$DONE(error)` or timeout
- `negative` tests pass when the specified error is thrown at the specified phase

### test262 Runner

```php
class Test262Runner {
    public function run(string $testPath): TestResult;     // Run single test
    public function runSuite(string $dir): SuiteResult;    // Run all tests in directory
    public function runAll(): SuiteResult;                 // Run everything
    
    // Filter by feature (skip tests needing unimplemented features)
    public function setSkipFeatures(array $features): void;
}
```

The runner:
1. Parses the YAML frontmatter to extract metadata
2. Checks `features` against skip list (don't run tests for unimplemented features)
3. Loads harness files specified in `includes`
4. Executes the test body in a fresh Engine instance
5. Checks the result against `negative` expectations (if any)
6. Reports PASS, FAIL, or SKIP

### Compatibility Tracking

`./bin/compat-report` is the canonical compatibility snapshot generator. It runs the full discovered `test262` category set with no sampling and writes:

- `compat.json`: machine-readable full compatibility snapshot
- `COMPAT.md`: human-readable full compatibility report

`./bin/verify-compliance` is an alias for `./bin/compat-report`.

For a quick sampled spot check, `./bin/compliance-report` still writes `compliance.json` from the tracked categories in `config/support.php`.

`./bin/test262 --report` remains useful for ad hoc inspection of a single directory or category, but repo-level compatibility documentation should come from `./bin/compat-report`.

### Comparison with test262.fyi

test262.fyi runs the same suite daily against V8, SpiderMonkey, JavaScriptCore, QuickJS, Hermes, and others. php-js compliance reports follow the same format so you can compare directly:

| Engine | Compliance |
|---|---|
| V8 (Chrome/Node) | 99.8% |
| SpiderMonkey (Firefox) | 99.6% |
| JavaScriptCore (Safari) | 99.4% |
| QuickJS | ~97% |
| Hermes (React Native) | ~95% |
| **php-js** | **see COMPAT.md** |

## Compliance Tracking

All six implementation phases are complete. The engine implements the full ECMAScript standard library. Ongoing work focuses on edge-case compliance measured by the test262 suite.

### Canonical workflow

After any compliance work, regenerate the compatibility snapshot:

```bash
./bin/compat-report --jobs 4       # Generates compat.json + COMPAT.md
```

This is the single source of truth. Do not track compliance numbers in ad-hoc console output or conversation summaries. Always commit the updated `COMPAT.md` and `compat.json` so the repo reflects current state.

For quick spot checks on a single category during development:

```bash
./bin/test262 --category built-ins/Array --limit 200 --failures
```

But repo-level documentation must come from `./bin/compat-report`.

### Remaining structural gaps

These failures cannot be fixed without architectural changes:

- **`$262` / cross-realm**: test262 tests that use the `$262` host API for creating realms, detaching ArrayBuffers, etc. We do not implement multi-realm support.
- **Detached ArrayBuffers**: requires `$262.detachArrayBuffer()` host API.
- **PCRE2 vs ECMAScript regex**: PCRE2 handles some regex features differently (nullable quantifier capture groups, backreference reset in repetitions). Would require a custom regex engine.
- **UTF-16 surrogates**: URI encode/decode functions need to validate lone surrogates per UTF-16 semantics. Requires tracking surrogate pairs in string operations.
- **ES modules**: `import`/`export` not implemented.

### Skip list

The skip list in `config/support.php` (`test262_skipped_features`) controls which test262 feature flags cause tests to be skipped. Only add features that are genuinely unimplemented. Remove features from the skip list as soon as they are supported.

## Comment Policy

Same as all inline0 packages. PHPDoc on public APIs. Inline comments explain why, not what. No decorative separators. No em dashes. Use periods, commas, colons, or rewrite.
