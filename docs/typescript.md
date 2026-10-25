---
title: "TypeScript"
description: "Run .ts and .tsx files in PHP with automatic type erasure and the existing JSX renderer."
path: "typescript"
order: 84
section: "JSX"
meta_title: "TypeScript"
meta_description: "Run .ts and .tsx files in PHP with automatic type erasure and the existing JSX renderer."
---

# TypeScript

TypeScript files work automatically. Pass a `.ts` entry to `execFile()` or a
`.tsx` component to `render()`, using the same APIs as JavaScript and JSX.
No TypeScript flag, runtime dependency, compiler installation or configuration
file is required.

```php
$engine = new \Phasis\Engine();
$engine->execFile(__DIR__ . '/entry.ts');
echo $engine->render(__DIR__ . '/Card.tsx', ['title' => 'Hello']);
```

```tsx
// Card.tsx
import type { ReactNode } from 'react';
type Props = { title: string; children?: ReactNode };

export default function Card({ title, children }: Props) {
  return <article><h2>{title}</h2>{children}</article>;
}
```

The PHP parser consumes type syntax and builds the executable JavaScript AST.
It does not rewrite source files or execute a JavaScript compiler. Source
locations still refer to the original input. Type checking remains a separate
editor or `tsc` task.

## Syntax

Supported erasure includes annotations, interfaces, type aliases, generic
parameters and arguments, unions, intersections, conditional and mapped types,
tuple and template literal types, `as` and `satisfies` assertions, non-null
assertions, access/type modifiers, overload signatures and ambient declarations.
Type-only declarations create no runtime bindings.

`.tsx` combines these rules with the [existing JSX renderer](/docs/jsx).
Generic arrows use the usual TSX disambiguation, such as `<T,>(value: T) => value`.
JSX pragmas, React or HTML profiles and precompile continue to use the existing
JSX options.

Runtime constructs requiring TypeScript code generation are rejected:
runtime enums, constructor parameter properties, namespaces containing runtime
values, and runtime import/export assignments. Ambient enum declarations are
erased. Phasis does not apply TypeScript compiler options or legacy decorator
transforms.

## Imports and files

Use explicit `import type` and `export type` for bindings that exist only in the
type system. Phasis does not infer which ordinary imports are used only as types.

```ts
import type { User } from './types.ts';
import { format, type Options } from './format.ts';
```

A complete type-only import or re-export does not load its target.
Ordinary imports retain module side effects even if all named specifiers use
`type`. Strings, comments, regexes and template literal text remain data.

`.ts` and `.tsx` entries and dependencies use the existing module loader.
`.mts` explicitly selects ES modules; `.cts` selects CommonJS. In Node mode,
`.ts` follows the existing package/module rules. Extension and `index` lookup
also find TypeScript files, after the existing JavaScript extensions, preserving
the priority of existing files. See [Modules](/docs/modules) for resolution.

For in-memory source, supply its filename to the existing module/render APIs:

```php
$engine->evalAsModule($source, __DIR__ . '/entry.ts');
$html = $engine->renderSource($component, ['title' => 'Hello'], path: '/Card.tsx');
```

`eval()` and JavaScript filenames retain JavaScript syntax. Standalone parser
users can supply `path: 'entry.ts'` or `path: 'Card.tsx'` to
`Parser::parseSource()`. Parsed-module cache identities distinguish TypeScript
from JavaScript.

## Verification

The focused corpus lives in `tests/TypeScript`. Original TypeScript executes
in Phasis and is compared with captured Node type-stripping output. Angle
assertions, which Node's stripping mode rejects, use the existing pinned
esbuild/Node oracle.

The JSX corpus also includes connected TSX components. The same fixture is
checked with the native renderer, real React executing in Phasis, precompile,
and browser hydration in development and production. Ordinary JSX fixtures are
additionally compared as ASTs in JavaScript and TypeScript modes.
