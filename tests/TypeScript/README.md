# TypeScript oracles

Run `node tests/TypeScript/oracle.ts` to refresh the committed reference
outputs. The current corpus was captured with Node 26.12.0. Most fixtures run
unchanged in Node's built-in type-stripping mode. Angle-bracket assertions,
which that mode rejects, use the existing JSX oracle toolchain's pinned
esbuild 0.28.2 and then Node.

`tests/Unit/TypeScriptTest.php` executes the original TypeScript in Phasis.
It compares runtime output, rejects unsupported or malformed syntax before
any execution, checks strict directive preservation, executes a typed child
with PHP and compares existing JSX ASTs between JS and TSX parsing.
`tests/Unit/Module/ParseCacheTest.php` also checks that a TypeScript cache entry
cannot bypass JavaScript syntax validation.

The connected `tests/Jsx/react/typescript-connected.tsx` fixture participates in
the existing React, real-React-in-Phasis, precompile and browser hydration
suites. Its imports include typed TSX modules, generics, context and state.
The browser checks run both production and development React.

These are focused oracle fixtures, not an upstream test runner. They need no
Node installation during the PHP unit tests. Node/esbuild remain development
and reference tools; server execution stays entirely in PHP.

Implementation references:

- [Node's Amaro wrapper](https://github.com/nodejs/amaro)
- [Deno AST and SWC](https://github.com/denoland/deno_ast)
- [esbuild TypeScript content type](https://esbuild.github.io/content-types/#typescript)
