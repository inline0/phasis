---
title: "Parser"
description: "Parse JavaScript into a typed PHP AST with Phasis\\Parser\\Parser, walk it, and export it as ESTree-shaped arrays or JSON."
path: "parser"
order: 230
section: "Reference"
meta_title: "Parser"
meta_description: "Parse JavaScript into a typed PHP AST with Phasis\\Parser\\Parser, walk it, and export it as ESTree-shaped arrays or JSON."
---

# Parser

Phasis exposes its JavaScript parser as a standalone PHP API. You can parse source text into a typed AST, traverse it, and export it as ESTree-shaped arrays or JSON without constructing an `Engine` or executing anything.

## parseSource

```php
public static function parseSource(string $source, string $sourceType = 'script'): Program
```

The one-call entry point on `Phasis\Parser\Parser`. Parses `$source` and returns the `Phasis\Ast\Program` root node.

```php
use Phasis\Parser\Parser;

$program = Parser::parseSource('const x = [1, 2].map(n => n * 2);');
$module = Parser::parseSource('import { x } from "./x.js"; export default x;', 'module');
```

`$sourceType` selects the grammar goal, named after the same option in JS parsers:

| Value | Goal |
|---|---|
| `script` | classic Script. Top-level `import`/`export` are syntax errors, `await` is an identifier |
| `module` | ES Module. Implicitly strict, `import`/`export` and top-level `await` parse |

Any other value throws `\InvalidArgumentException`.

For finer control (pre-seeding strict mode for a direct-eval context, reading the `//# sourceURL` pragma), construct the parser directly: `new Parser($source)` plus `setModuleMode()`, `setStrictMode()`, and `parse()`.

## Nodes

Every AST class extends `Phasis\Ast\Node` and lives under `Phasis\Ast\{Declaration, Expression, Pattern, Statement}`. Nodes are plain readonly value objects:

- `$node->type()` returns the node's type string, which is the class basename (`'BinaryExpression'`, `'ArrowFunction'`, ...).
- `$node->location` is a `Phasis\Lexer\SourceLocation` with `line` (1-based), `column` (0-based), and `offset` (byte offset). Only start positions are stored; nodes carry no end offset.
- All other public fields are the node's children and attributes (`$binary->left`, `$binary->operator`, `$binary->right`).

Most type names match [ESTree](https://github.com/estree/estree). The deviations:

| Phasis type | ESTree type |
|---|---|
| `ArrowFunction` | `ArrowFunctionExpression` |
| `TaggedTemplate` | `TaggedTemplateExpression` |
| `ClassMethod` | `MethodDefinition` |
| `ClassProperty` | `PropertyDefinition` |
| `AssignmentProperty` | `Property` (inside `ObjectPattern`) |
| `ExportDeclaration` | `ExportNamedDeclaration` / `ExportDefaultDeclaration` / `ExportAllDeclaration` |
| `ImportSpecifier` | `ImportSpecifier` / `ImportDefaultSpecifier` / `ImportNamespaceSpecifier` |

A few shapes also differ: class bodies are a plain element array on the class node (no `ClassBody` wrapper), import/export names, statement labels, and `FunctionExpression` names are stored as strings rather than nested `Identifier` nodes, and `super` parses as an `Identifier` named `super` (there is no `Super` node class). `Phasis\Ast\EstreeSerializer` reshapes all of this to the ESTree form.

## Syntax errors

Invalid input throws `Phasis\Exceptions\SyntaxError` (usually its subclass `Phasis\Parser\ParseError`). The exception message ends with the position, and the structured location is available on the exception:

```php
use Phasis\Exceptions\SyntaxError;
use Phasis\Parser\Parser;

try {
    Parser::parseSource("const a = 1;\nconst b = ;");
} catch (SyntaxError $e) {
    echo $e->getMessage();          // Unexpected token (got ; ";") at line 2, column 10
    echo $e->location->line;        // 2
    echo $e->location->column;      // 10
    echo $e->location->offset;      // 23
}
```

## Walker

`Phasis\Ast\Walker` traverses a tree depth-first with enter/leave callbacks, in the style of estraverse:

```php
public static function walk(Node $node, callable $enter, ?callable $leave = null): void
```

Both callbacks receive `(Node $node, ?Node $parent)`. Returning `Walker::SKIP` from `$enter` prunes the node's children; the node's own `$leave` still fires.

```php
use Phasis\Ast\Expression\Identifier;
use Phasis\Ast\Node;
use Phasis\Ast\Walker;

$names = [];
Walker::walk($program, function (Node $node) use (&$names) {
    if ($node instanceof Identifier) {
        $names[] = $node->name;
    }
    if ($node instanceof \Phasis\Ast\Expression\ArrowFunction) {
        return Walker::SKIP;
    }
});
```

Traversal is read-only. Nodes are readonly value objects, so there is no replace/mutate support.

## Serializer

`Phasis\Ast\Serializer` converts the typed AST to arrays or JSON using the Phasis type names and field layout, with parser-internal cache fields stripped:

```php
public static function toArray(Node $node): array
public static function toJson(Node $node, bool $pretty = false): string
```

This is the format `bin/phasis --ast` prints.

## EstreeSerializer

`Phasis\Ast\EstreeSerializer` converts the typed AST to ESTree-shaped arrays instead: ESTree type names (per the deviation table above), ESTree field names and ordering, synthesized `ClassBody` / specifier / source-`Literal` nodes, and a `start` byte offset on every node parsed from source. There are no `end` offsets because nodes only store start positions, and string `Literal` nodes carry no `raw` text.

```php
public static function toArray(Node $node): array
public static function toJson(Node $node, bool $pretty = false): string
public static function summarize(Node $node): string
```

`summarize()` emits a deterministic one-line-per-node summary (type plus `name=` / `value=` / `op=` / `kind=` attributes, two-space indent per depth level). The format is byte-identical to the AST summary the acorn fixture in `tests/Popular/acorn` produces, so the same oracle referees both the JS parser path and this PHP export:

```php
use Phasis\Ast\EstreeSerializer;
use Phasis\Parser\Parser;

echo EstreeSerializer::summarize(Parser::parseSource('let x = a + 1;'));
// Program
//   VariableDeclaration kind=let
//     VariableDeclarator
//       Identifier name=x
//       BinaryExpression op=+
//         Identifier name=a
//         Literal value=1
```

## CLI

`bin/phasis --ast script.js` parses a file (script goal) and prints the `Serializer` JSON dump with two-space indentation. See [CLI](/docs/cli) for the full flag reference.
