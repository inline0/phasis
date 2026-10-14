<?php

declare(strict_types=1);

namespace Phasis\Parser\Jsx;

use Phasis\Ast\Declaration\ImportDeclaration;
use Phasis\Ast\Declaration\ImportSpecifier;
use Phasis\Ast\Declaration\VariableDeclaration;
use Phasis\Ast\Declaration\VariableDeclarator;
use Phasis\Ast\Expression\ArrayExpression;
use Phasis\Ast\Expression\CallExpression;
use Phasis\Ast\Expression\Identifier;
use Phasis\Ast\Expression\Literal;
use Phasis\Ast\Expression\MemberExpression;
use Phasis\Ast\Expression\ObjectExpression;
use Phasis\Ast\Expression\Property;
use Phasis\Ast\Expression\SpreadElement;
use Phasis\Ast\Expression\ThisExpression;
use Phasis\Ast\Jsx\JsxAttribute;
use Phasis\Ast\Jsx\JsxElement;
use Phasis\Ast\Jsx\JsxEmptyExpression;
use Phasis\Ast\Jsx\JsxExpressionContainer;
use Phasis\Ast\Jsx\JsxFragment;
use Phasis\Ast\Jsx\JsxIdentifier;
use Phasis\Ast\Jsx\JsxMemberExpression;
use Phasis\Ast\Jsx\JsxNamespacedName;
use Phasis\Ast\Jsx\JsxSpreadAttribute;
use Phasis\Ast\Jsx\JsxSpreadChild;
use Phasis\Ast\Jsx\JsxText as JsxTextNode;
use Phasis\Ast\Node;
use Phasis\Exceptions\SyntaxError;
use Phasis\Lexer\SourceLocation;

/**
 * Lowers JSX trees to plain ECMAScript calls, matching esbuild's output for
 * `--jsx=automatic` and `--jsx=transform` (classic). The interpreter,
 * compiler and VM therefore never see a JSX node.
 */
final class JsxLowering
{
    /** @var array<string, array<string, string>> import source => imported name => local name */
    private array $imports = [];

    /** @var list<array{0: string, 1: ArrayExpression}> Precompiled templates: binding name, parts. */
    private array $templates = [];

    /**
     * @param \Closure(string): string $uniqueName Returns a local binding name not used in the module.
     */
    public function __construct(
        private readonly JsxMode $mode,
        private readonly string $importSource,
        private readonly string $factory,
        private readonly string $fragment,
        private readonly \Closure $uniqueName,
    ) {
    }

    public function lower(JsxElement|JsxFragment $node): Node
    {
        return $this->mode === JsxMode::Classic ? $this->lowerClassic($node) : $this->lowerAutomatic($node);
    }

    /** True when lowering referenced runtime functions that have to be imported. */
    public function needsImports(): bool
    {
        return $this->imports !== [];
    }

    /**
     * Module-level `const $$tpl_N = [...]` declarations for precompiled
     * templates, placed after the imports.
     *
     * @return list<VariableDeclaration>
     */
    public function templateDeclarations(SourceLocation $location): array
    {
        $declarations = [];
        foreach ($this->templates as [$name, $parts]) {
            $declarations[] = new VariableDeclaration($location, 'const', [
                new VariableDeclarator($location, new Identifier($location, $name), $parts),
            ]);
        }
        return $declarations;
    }

    /** @return list<ImportDeclaration> */
    public function importDeclarations(SourceLocation $location): array
    {
        $declarations = [];
        foreach ($this->imports as $source => $names) {
            $specifiers = [];
            foreach ($names as $imported => $local) {
                $specifiers[] = new ImportSpecifier($location, 'named', $local, $imported);
            }
            $declarations[] = new ImportDeclaration($location, $specifiers, $source);
        }
        return $declarations;
    }

    private function lowerAutomatic(JsxElement|JsxFragment $node): Node
    {
        $location = $node->location;
        $runtime = $this->importSource . '/jsx-runtime';
        $react = $this->importSource === 'react';
        if (
            $this->mode === JsxMode::Precompile
            && ($react || $this->importSource === 'phasis')
            && $node instanceof JsxElement
            && JsxPrecompiler::canTemplateChildren($node, $react)
        ) {
            $children = [$this->template($node, $react)];
        } else {
            $children = $this->lowerChildren($node->children);
        }

        if ($node instanceof JsxFragment) {
            $type = $this->runtimeRef($runtime, 'Fragment', $location);
            $props = [];
            $key = null;
        } else {
            $type = $this->elementType($node->openingElement->name);
            $props = [];
            $all = [];
            $key = null;
            $sawSpread = false;
            $keyAfterSpread = false;
            foreach ($node->openingElement->attributes as $attribute) {
                if (!($attribute instanceof JsxAttribute)) {
                    $spread = new SpreadElement($attribute->location, self::spreadArgument($attribute));
                    $props[] = $spread;
                    $all[] = $spread;
                    $sawSpread = true;
                    continue;
                }
                $property = $this->attributeProperty($attribute);
                $all[] = $property;
                if ($attribute->name instanceof JsxIdentifier && $attribute->name->name === 'key') {
                    if ($attribute->value === null) {
                        throw new SyntaxError('Please provide an explicit value for "key"', $attribute->location);
                    }
                    if ($sawSpread) {
                        $keyAfterSpread = true;
                    } else {
                        $key = $property->value;
                    }
                    continue;
                }
                $props[] = $property;
            }
            if ($keyAfterSpread) {
                // A key after a spread can't be extracted without changing which
                // value wins, so esbuild falls back to createElement from the
                // package root, keeping every prop in order.
                $callee = $this->runtimeRef($this->importSource, 'createElement', $location);
                $arguments = [$type, new ObjectExpression($location, $all)];
                foreach ($children as $child) {
                    $arguments[] = $child;
                }
                return new CallExpression($location, $callee, $arguments);
            }
        }

        $static = false;
        if (count($children) === 1 && !($children[0] instanceof SpreadElement)) {
            $props[] = $this->property('children', $children[0], $location);
        } elseif ($children !== []) {
            $props[] = $this->property('children', new ArrayExpression($location, $children), $location);
            $static = true;
        }

        $callee = $this->runtimeRef($runtime, $static ? 'jsxs' : 'jsx', $location);
        $arguments = [$type, new ObjectExpression($location, $props)];
        if ($key !== null) {
            $arguments[] = $key;
        }
        return new CallExpression($location, $callee, $arguments);
    }

    private function lowerClassic(JsxElement|JsxFragment $node): Node
    {
        $location = $node->location;
        if ($node instanceof JsxFragment) {
            $type = $this->dottedName($this->fragment, $location);
            $props = new Literal($location, null, 'null');
        } else {
            $type = $this->elementType($node->openingElement->name);
            $properties = [];
            foreach ($node->openingElement->attributes as $attribute) {
                $properties[] = $attribute instanceof JsxAttribute
                    ? $this->attributeProperty($attribute)
                    : new SpreadElement($attribute->location, self::spreadArgument($attribute));
            }
            $props = $properties === [] ? new Literal($location, null, 'null') : new ObjectExpression($location, $properties);
        }
        $arguments = [$type, $props];
        foreach ($this->lowerChildren($node->children) as $child) {
            $arguments[] = $child;
        }
        return new CallExpression($location, $this->dottedName($this->factory, $location), $arguments);
    }

    /** `jsxTemplate($$tpl_N, ...holes)` for an element's children. */
    private function template(JsxElement $element, bool $react): Node
    {
        $location = $element->location;
        $precompiler = new JsxPrecompiler(
            $react,
            fn (Node $node): Node => $this->lower(
                $node instanceof JsxElement || $node instanceof JsxFragment ? $node : throw new \LogicException('JSX node expected'),
            ),
        );
        [$parts, $holes] = $precompiler->compileChildren($element);
        $name = ($this->uniqueName)('$$tpl_' . (count($this->templates) + 1));
        $this->templates[] = [$name, $this->literalArray($parts, $location)];
        return new CallExpression(
            $location,
            $this->runtimeRef('phasis/jsx-precompile', 'jsxTemplate', $location),
            [new Identifier($location, $name), ...$holes],
        );
    }

    /** @param array<int|string, mixed> $values */
    private function literalArray(array $values, SourceLocation $location): ArrayExpression
    {
        return new ArrayExpression($location, array_map(fn (mixed $value): Node => $this->literal($value, $location), array_values($values)));
    }

    private function literal(mixed $value, SourceLocation $location): Node
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return $this->literalArray($value, $location);
            }
            $properties = [];
            foreach ($value as $key => $item) {
                $properties[] = $this->property((string) $key, $this->literal($item, $location), $location);
            }
            return new ObjectExpression($location, $properties);
        }
        if ($value === null) {
            return new Literal($location, null, 'null');
        }
        if (is_int($value)) {
            return new Literal($location, $value, (string) $value);
        }
        $string = (string) $value;
        return new Literal($location, $string, (string) json_encode($string, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param Node[] $children
     * @return list<Node>
     */
    private function lowerChildren(array $children): array
    {
        $lowered = [];
        foreach ($children as $child) {
            if ($child instanceof JsxTextNode) {
                $text = JsxText::cleanChild($child->raw);
                if ($text !== null) {
                    $lowered[] = new Literal($child->location, $text, $text);
                }
                continue;
            }
            if ($child instanceof JsxExpressionContainer) {
                if (!($child->expression instanceof JsxEmptyExpression)) {
                    $lowered[] = $child->expression;
                }
                continue;
            }
            if ($child instanceof JsxSpreadChild) {
                $lowered[] = new SpreadElement($child->location, $child->expression);
                continue;
            }
            if ($child instanceof JsxElement || $child instanceof JsxFragment) {
                $lowered[] = $this->lower($child);
            }
        }
        return $lowered;
    }

    private function attributeProperty(JsxAttribute $attribute): Property
    {
        $name = $attribute->name instanceof JsxNamespacedName
            ? $attribute->name->namespace->name . ':' . $attribute->name->name->name
            : ($attribute->name instanceof JsxIdentifier ? $attribute->name->name : '');
        $value = $attribute->value;
        if ($value === null) {
            $value = new Literal($attribute->location, true, 'true');
        } elseif ($value instanceof JsxExpressionContainer) {
            $value = $value->expression;
        } elseif ($value instanceof JsxElement || $value instanceof JsxFragment) {
            $value = $this->lower($value);
        }
        return $this->property($name, $value, $attribute->location);
    }

    private function property(string $name, Node $value, SourceLocation $location): Property
    {
        $key = preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $name) === 1
            ? new Identifier($location, $name)
            : new Literal($location, $name, $name);
        return new Property($location, $key, $value, 'init', false, false, false);
    }

    private function elementType(Node $name): Node
    {
        if ($name instanceof JsxNamespacedName) {
            $text = $name->namespace->name . ':' . $name->name->name;
            return new Literal($name->location, $text, $text);
        }
        if ($name instanceof JsxMemberExpression) {
            return new MemberExpression(
                $name->location,
                $this->elementType($name->object),
                new Identifier($name->property->location, $name->property->name),
                false,
                false,
            );
        }
        if (!($name instanceof JsxIdentifier)) {
            throw new \LogicException('Unexpected JSX element name: ' . $name->type());
        }
        if ($name->name === 'this') {
            return new ThisExpression($name->location);
        }
        $first = $name->name[0];
        if (($first >= 'a' && $first <= 'z') || str_contains($name->name, '-')) {
            return new Literal($name->location, $name->name, $name->name);
        }
        return new Identifier($name->location, $name->name);
    }

    private static function spreadArgument(Node $attribute): Node
    {
        if (!($attribute instanceof JsxSpreadAttribute)) {
            throw new \LogicException('Unexpected JSX attribute: ' . $attribute->type());
        }
        return $attribute->argument;
    }

    /** A reference to an automatic-runtime import, registering the import on first use. */
    private function runtimeRef(string $source, string $imported, SourceLocation $location): Identifier
    {
        if (!isset($this->imports[$source][$imported])) {
            $this->imports[$source][$imported] = ($this->uniqueName)('_' . $imported);
        }
        return new Identifier($location, $this->imports[$source][$imported]);
    }

    /** Build `React.createElement`-style member chains for the classic factory. */
    private function dottedName(string $name, SourceLocation $location): Node
    {
        $parts = explode('.', $name);
        $node = $parts[0] === 'this' ? new ThisExpression($location) : new Identifier($location, $parts[0]);
        foreach (array_slice($parts, 1) as $part) {
            $node = new MemberExpression($location, $node, new Identifier($location, $part), false, false);
        }
        return $node;
    }
}
