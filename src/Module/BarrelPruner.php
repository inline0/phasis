<?php

declare(strict_types=1);

namespace Phasis\Module;

use Phasis\Ast\Declaration\ExportDeclaration;
use Phasis\Ast\Declaration\ImportDeclaration;
use Phasis\Ast\Declaration\ImportSpecifier;
use Phasis\Ast\Node;
use Phasis\Ast\Program;
use Phasis\Ast\Statement\ExpressionStatement;
use Phasis\Ast\Expression\Literal;
use Phasis\Lexer\Lexer;
use Phasis\Lexer\TokenType;

/**
 * Skips unused re-exports of side-effect-free packages, the way bundlers
 * tree-shake them. `import { Alpha } from 'pkg'`, where pkg's entry only
 * re-exports and its package.json says `"sideEffects": false`, becomes an
 * import of Alpha straight from the module that defines it, so the other
 * hundreds of modules in the barrel are never parsed or run.
 *
 * Anything this can't prove safe (namespace imports, a barrel with real
 * code in it, a name it can't find) keeps the original import.
 */
final class BarrelPruner
{
    /** @var array<string, ?array{names: array<string, array{0: string, 1: string}>, stars: list<string>}> */
    private array $barrels = [];

    /** @var array<string, array<string, true>> */
    private array $exportNames = [];

    /** @var array<string, bool> */
    private array $sideEffectFree = [];

    /**
     * @param \Closure(string): ?Program $parse Parses a module file, or returns null if it can't be read.
     */
    public function __construct(
        private readonly ModuleResolver $resolver,
        private readonly \Closure $parse,
    ) {
    }

    public function rewrite(Program $program, string $importerPath): Program
    {
        $changed = false;
        $body = [];
        foreach ($program->body as $node) {
            if ($node instanceof ImportDeclaration && $node->phase === 'evaluation' && $node->attributes === null) {
                $rewritten = $this->rewriteImport($node, $importerPath);
                if ($rewritten !== null) {
                    array_push($body, ...$rewritten);
                    $changed = true;
                    continue;
                }
            }
            $body[] = $node;
        }
        return $changed ? new Program($program->location, $body) : $program;
    }

    /** @return ?list<ImportDeclaration> */
    private function rewriteImport(ImportDeclaration $node, string $importerPath): ?array
    {
        if ($node->specifiers === []) {
            return null;
        }
        try {
            $target = $this->resolver->resolve($node->source, $importerPath);
        } catch (\Throwable) {
            return null;
        }
        if (!$this->isPrunableBarrel($target)) {
            return null;
        }
        $imports = [];
        foreach ($node->specifiers as $specifier) {
            if ($specifier->specType === 'namespace') {
                return null;
            }
            $name = $specifier->specType === 'default' ? 'default' : ($specifier->imported ?? $specifier->local);
            $origin = $this->findOrigin($target, $name, 0);
            if ($origin === null) {
                return null;
            }
            [$module, $imported] = $origin;
            $spec = match (true) {
                $imported === '*' => new ImportSpecifier($specifier->location, 'namespace', $specifier->local),
                $imported === 'default' => new ImportSpecifier($specifier->location, 'default', $specifier->local),
                default => new ImportSpecifier($specifier->location, 'named', $specifier->local, $imported),
            };
            $imports[] = new ImportDeclaration($node->location, [$spec], $module);
        }
        return $imports;
    }

    /**
     * The module and export name that $name of $module really comes from,
     * following re-exports through side-effect-free barrels. An imported
     * name of `*` means the whole namespace of that module.
     *
     * @return ?array{0: string, 1: string}
     */
    private function findOrigin(string $module, string $name, int $depth): ?array
    {
        if ($depth > 32 || !$this->isPrunableBarrel($module)) {
            return $depth === 0 ? null : (isset($this->exportNamesOf($module)[$name]) ? [$module, $name] : null);
        }
        $barrel = $this->barrels[$module];
        if ($barrel === null) {
            return null;
        }
        if (isset($barrel['names'][$name])) {
            [$source, $imported] = $barrel['names'][$name];
            if ($imported === '*') {
                return [$source, '*'];
            }
            return $this->findOrigin($source, $imported, $depth + 1) ?? [$source, $imported];
        }
        if ($name === 'default') {
            return null;
        }
        foreach ($barrel['stars'] as $source) {
            $origin = $this->findOrigin($source, $name, $depth + 1);
            if ($origin !== null) {
                return $origin;
            }
        }
        return null;
    }

    private function isPrunableBarrel(string $path): bool
    {
        if (!str_starts_with($path, '/') || !$this->isSideEffectFree($path)) {
            return false;
        }
        if (!array_key_exists($path, $this->barrels)) {
            $this->barrels[$path] = $this->analyzeBarrel($path);
        }
        return $this->barrels[$path] !== null;
    }

    /**
     * A barrel holds only re-exports: `export { a as b } from`, `export *
     * from`, `export * as ns from`, and `import * as ns from` followed by
     * `export { ns }`. Returns its export table, or null for anything else.
     *
     * @return ?array{names: array<string, array{0: string, 1: string}>, stars: list<string>}
     */
    private function analyzeBarrel(string $path): ?array
    {
        $program = ($this->parse)($path);
        if ($program === null) {
            return null;
        }
        $names = [];
        $stars = [];
        $namespaceImports = [];
        foreach ($program->body as $node) {
            if ($node instanceof ExpressionStatement && $node->expression instanceof Literal && is_string($node->expression->value)) {
                continue;
            }
            if ($node instanceof ImportDeclaration) {
                if (count($node->specifiers) !== 1 || $node->specifiers[0]->specType !== 'namespace') {
                    return null;
                }
                $namespaceImports[$node->specifiers[0]->local] = $this->resolveFrom($node->source, $path);
                continue;
            }
            if (!$node instanceof ExportDeclaration || $node->declaration !== null || $node->isDefault) {
                return null;
            }
            if ($node->isAll) {
                $source = $this->resolveFrom((string) $node->source, $path);
                if ($source === null) {
                    return null;
                }
                if ($node->allAs !== null) {
                    $names[$node->allAs] = [$source, '*'];
                } else {
                    $stars[] = $source;
                }
                continue;
            }
            foreach ($node->specifiers as $specifier) {
                $exported = $specifier->exported;
                if ($node->source !== null) {
                    $source = $this->resolveFrom($node->source, $path);
                    if ($source === null) {
                        return null;
                    }
                    $names[$exported] = [$source, $specifier->local];
                } elseif (isset($namespaceImports[$specifier->local])) {
                    // isset() is false for a namespace import that didn't resolve.
                    $names[$exported] = [$namespaceImports[$specifier->local], '*'];
                } else {
                    return null;
                }
            }
        }
        return ['names' => $names, 'stars' => $stars];
    }

    private function resolveFrom(string $specifier, string $path): ?string
    {
        try {
            $resolved = $this->resolver->resolve($specifier, $path);
        } catch (\Throwable) {
            return null;
        }
        return str_starts_with($resolved, '/') ? $resolved : null;
    }

    /**
     * Export names of a module, from a token scan of its top level. Used to
     * find which `export *` source provides a name without running anything.
     *
     * @return array<string, true>
     */
    private function exportNamesOf(string $path): array
    {
        if (isset($this->exportNames[$path])) {
            return $this->exportNames[$path];
        }
        $this->exportNames[$path] = [];
        $source = @file_get_contents($path);
        if ($source === false) {
            return [];
        }
        try {
            $lexer = new Lexer($source);
            $lexer->setModuleMode(true);
            $tokens = $lexer->tokenize();
        } catch (\Throwable) {
            return [];
        }
        $names = [];
        $depth = 0;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $type = $tokens[$i]->type;
            if ($type === TokenType::LeftBrace || $type === TokenType::TemplateHead) {
                $depth++;
            } elseif ($type === TokenType::RightBrace || $type === TokenType::TemplateTail) {
                $depth--;
            }
            if ($depth !== 0 || $type !== TokenType::Export) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            if ($next === null) {
                break;
            }
            if ($next->type === TokenType::Default_) {
                $names['default'] = true;
            } elseif (in_array($next->type, [TokenType::Function_, TokenType::Class_, TokenType::Const_, TokenType::Let, TokenType::Var], true)) {
                $j = $i + 2;
                if (($tokens[$j]->type ?? null) === TokenType::Star) {
                    $j++;
                }
                if (($tokens[$j]->type ?? null) === TokenType::Identifier) {
                    $names[$tokens[$j]->value] = true;
                }
            } elseif ($next->type === TokenType::Async && ($tokens[$i + 2]->type ?? null) === TokenType::Function_) {
                if (($tokens[$i + 3]->type ?? null) === TokenType::Identifier) {
                    $names[$tokens[$i + 3]->value] = true;
                }
            } elseif ($next->type === TokenType::LeftBrace) {
                for ($j = $i + 2; $j < $count && $tokens[$j]->type !== TokenType::RightBrace; $j++) {
                    $after = $tokens[$j + 1] ?? null;
                    if ($after !== null && ($after->type === TokenType::Comma || $after->type === TokenType::RightBrace)) {
                        $names[$tokens[$j]->value] = true;
                    }
                }
            } elseif ($next->type === TokenType::Star && ($tokens[$i + 2]->value ?? '') === 'as') {
                $names[$tokens[$i + 3]->value ?? ''] = true;
            }
        }
        return $this->exportNames[$path] = $names;
    }

    private function isSideEffectFree(string $path): bool
    {
        $scope = $this->resolver->packageScope($path);
        if ($scope === null || !str_contains($path, '/node_modules/')) {
            return false;
        }
        $key = $scope . "\0" . $path;
        if (isset($this->sideEffectFree[$key])) {
            return $this->sideEffectFree[$key];
        }
        $flag = ($this->resolver->readPackageJson($scope) ?? [])['sideEffects'] ?? true;
        $result = false;
        if ($flag === false) {
            $result = true;
        } elseif (is_array($flag)) {
            $relative = substr($path, strlen($scope) + 1);
            $result = true;
            foreach ($flag as $pattern) {
                $pattern = ltrim((string) $pattern, './');
                if (fnmatch($pattern, $relative) || (!str_contains($pattern, '/') && fnmatch($pattern, basename($relative)))) {
                    $result = false;
                    break;
                }
            }
        }
        return $this->sideEffectFree[$key] = $result;
    }
}
