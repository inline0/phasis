<?php

declare(strict_types=1);

namespace Phasis\Ast;

use Phasis\Ast\Declaration\ClassDeclaration;
use Phasis\Ast\Declaration\ExportDeclaration;
use Phasis\Ast\Declaration\ExportSpecifier;
use Phasis\Ast\Declaration\FunctionDeclaration;
use Phasis\Ast\Declaration\ImportDeclaration;
use Phasis\Ast\Declaration\ImportSpecifier;
use Phasis\Ast\Declaration\VariableDeclaration;
use Phasis\Ast\Declaration\VariableDeclarator;
use Phasis\Ast\Expression\ArrayExpression;
use Phasis\Ast\Expression\ArrowFunction;
use Phasis\Ast\Expression\AssignmentExpression;
use Phasis\Ast\Expression\AwaitExpression;
use Phasis\Ast\Expression\BinaryExpression;
use Phasis\Ast\Expression\CallExpression;
use Phasis\Ast\Expression\ClassExpression;
use Phasis\Ast\Expression\ClassMethod;
use Phasis\Ast\Expression\ClassProperty;
use Phasis\Ast\Expression\ConditionalExpression;
use Phasis\Ast\Expression\FunctionExpression;
use Phasis\Ast\Expression\Identifier;
use Phasis\Ast\Expression\ImportExpression;
use Phasis\Ast\Expression\Literal;
use Phasis\Ast\Expression\LogicalExpression;
use Phasis\Ast\Expression\MemberExpression;
use Phasis\Ast\Expression\MetaProperty;
use Phasis\Ast\Expression\NewExpression;
use Phasis\Ast\Expression\ObjectExpression;
use Phasis\Ast\Expression\PrivateIdentifier;
use Phasis\Ast\Expression\Property;
use Phasis\Ast\Expression\SequenceExpression;
use Phasis\Ast\Expression\SpreadElement;
use Phasis\Ast\Expression\StaticBlock;
use Phasis\Ast\Expression\TaggedTemplate;
use Phasis\Ast\Expression\TemplateElement;
use Phasis\Ast\Expression\TemplateLiteral;
use Phasis\Ast\Expression\ThisExpression;
use Phasis\Ast\Expression\UnaryExpression;
use Phasis\Ast\Expression\UpdateExpression;
use Phasis\Ast\Expression\YieldExpression;
use Phasis\Ast\Pattern\ArrayPattern;
use Phasis\Ast\Pattern\AssignmentPattern;
use Phasis\Ast\Pattern\AssignmentProperty;
use Phasis\Ast\Pattern\ObjectPattern;
use Phasis\Ast\Pattern\RestElement;
use Phasis\Ast\Statement\BlockStatement;
use Phasis\Ast\Statement\BreakStatement;
use Phasis\Ast\Statement\CatchClause;
use Phasis\Ast\Statement\ContinueStatement;
use Phasis\Ast\Statement\DebuggerStatement;
use Phasis\Ast\Statement\DoWhileStatement;
use Phasis\Ast\Statement\EmptyStatement;
use Phasis\Ast\Statement\ExpressionStatement;
use Phasis\Ast\Statement\ForInStatement;
use Phasis\Ast\Statement\ForOfStatement;
use Phasis\Ast\Statement\ForStatement;
use Phasis\Ast\Statement\IfStatement;
use Phasis\Ast\Statement\LabeledStatement;
use Phasis\Ast\Statement\ReturnStatement;
use Phasis\Ast\Statement\SwitchCase;
use Phasis\Ast\Statement\SwitchStatement;
use Phasis\Ast\Statement\ThrowStatement;
use Phasis\Ast\Statement\TryStatement;
use Phasis\Ast\Statement\WhileStatement;
use Phasis\Ast\Statement\WithStatement;
use Phasis\Value\JsNumber;

final class EstreeSerializer
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Node $node): array
    {
        return self::convert($node);
    }

    public static function toJson(Node $node, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return json_encode(self::convert($node), $flags);
    }

    public static function summarize(Node $node): string
    {
        $lines = [];
        self::collectLines(self::convert($node), 0, $lines);
        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private static function convert(Node $node): array
    {
        return match (true) {
            $node instanceof Program => self::entry($node, 'Program', [
                'body' => self::nodeList($node->body),
            ]),
            $node instanceof Identifier => $node->name === 'super'
                ? self::entry($node, 'Super', [])
                : self::entry($node, 'Identifier', ['name' => $node->name]),
            $node instanceof PrivateIdentifier => self::entry($node, 'PrivateIdentifier', [
                'name' => ltrim($node->name, '#'),
            ]),
            $node instanceof Literal => self::convertLiteral($node),
            $node instanceof ThisExpression => self::entry($node, 'ThisExpression', []),
            $node instanceof ArrayExpression => self::entry($node, 'ArrayExpression', [
                'elements' => self::nodeList($node->elements),
            ]),
            $node instanceof ObjectExpression => self::entry($node, 'ObjectExpression', [
                'properties' => self::nodeList($node->properties),
            ]),
            $node instanceof Property => self::entry($node, 'Property', [
                'method' => $node->method,
                'shorthand' => $node->shorthand,
                'computed' => $node->computed,
                'key' => self::convert($node->key),
                'kind' => $node->kind,
                'value' => self::convert($node->value),
            ]),
            $node instanceof AssignmentProperty => self::entry($node, 'Property', [
                'method' => false,
                'shorthand' => $node->shorthand,
                'computed' => $node->computed,
                'key' => self::convert($node->key),
                'kind' => 'init',
                'value' => self::convert($node->value),
            ]),
            $node instanceof SequenceExpression => self::entry($node, 'SequenceExpression', [
                'expressions' => self::nodeList($node->expressions),
            ]),
            $node instanceof SpreadElement => self::entry($node, 'SpreadElement', [
                'argument' => self::convert($node->argument),
            ]),
            $node instanceof UnaryExpression => self::entry($node, 'UnaryExpression', [
                'operator' => $node->operator,
                'prefix' => $node->prefix,
                'argument' => self::convert($node->argument),
            ]),
            $node instanceof UpdateExpression => self::entry($node, 'UpdateExpression', [
                'operator' => $node->operator,
                'prefix' => $node->prefix,
                'argument' => self::convert($node->argument),
            ]),
            $node instanceof BinaryExpression => self::entry($node, 'BinaryExpression', [
                'left' => self::convert($node->left),
                'operator' => $node->operator,
                'right' => self::convert($node->right),
            ]),
            $node instanceof LogicalExpression => self::entry($node, 'LogicalExpression', [
                'left' => self::convert($node->left),
                'operator' => $node->operator,
                'right' => self::convert($node->right),
            ]),
            $node instanceof AssignmentExpression => self::entry($node, 'AssignmentExpression', [
                'operator' => $node->operator,
                'left' => self::convert($node->left),
                'right' => self::convert($node->right),
            ]),
            $node instanceof ConditionalExpression => self::entry($node, 'ConditionalExpression', [
                'test' => self::convert($node->test),
                'consequent' => self::convert($node->consequent),
                'alternate' => self::convert($node->alternate),
            ]),
            $node instanceof CallExpression => self::entry($node, 'CallExpression', [
                'callee' => self::convert($node->callee),
                'arguments' => self::nodeList($node->arguments),
                'optional' => $node->optional,
            ]),
            $node instanceof NewExpression => self::entry($node, 'NewExpression', [
                'callee' => self::convert($node->callee),
                'arguments' => self::nodeList($node->arguments),
            ]),
            $node instanceof MemberExpression => self::entry($node, 'MemberExpression', [
                'object' => self::convert($node->object),
                'property' => self::convert($node->property),
                'computed' => $node->computed,
                'optional' => $node->optional,
            ]),
            $node instanceof MetaProperty => self::entry($node, 'MetaProperty', [
                'meta' => self::identifier($node->meta),
                'property' => self::identifier($node->property),
            ]),
            $node instanceof ImportExpression => self::entry($node, 'ImportExpression', [
                'source' => self::convert($node->source),
                'options' => self::optional($node->options),
            ]),
            $node instanceof AwaitExpression => self::entry($node, 'AwaitExpression', [
                'argument' => self::convert($node->argument),
            ]),
            $node instanceof YieldExpression => self::entry($node, 'YieldExpression', [
                'delegate' => $node->delegate,
                'argument' => self::optional($node->argument),
            ]),
            $node instanceof ArrowFunction => self::entry($node, 'ArrowFunctionExpression', [
                'id' => null,
                'expression' => $node->expression,
                'generator' => false,
                'async' => $node->async,
                'params' => self::nodeList($node->params),
                'body' => self::convert($node->body),
            ]),
            $node instanceof FunctionExpression => self::entry($node, 'FunctionExpression', [
                'id' => $node->name === null ? null : self::identifier($node->name),
                'expression' => false,
                'generator' => $node->generator,
                'async' => $node->async,
                'params' => self::nodeList($node->params),
                'body' => self::convert($node->body),
            ]),
            $node instanceof FunctionDeclaration => self::entry($node, 'FunctionDeclaration', [
                'id' => self::optional($node->id),
                'expression' => $node->expression,
                'generator' => $node->generator,
                'async' => $node->async,
                'params' => self::nodeList($node->params),
                'body' => self::convert($node->body),
            ]),
            $node instanceof ClassDeclaration => self::entry($node, 'ClassDeclaration', [
                'id' => self::optional($node->id),
                'superClass' => self::optional($node->superClass),
                'body' => self::classBody($node->body),
            ]),
            $node instanceof ClassExpression => self::entry($node, 'ClassExpression', [
                'id' => self::optional($node->id),
                'superClass' => self::optional($node->superClass),
                'body' => self::classBody($node->body),
            ]),
            $node instanceof ClassMethod => self::entry($node, 'MethodDefinition', [
                'static' => $node->static,
                'computed' => $node->computed,
                'key' => self::convert($node->key),
                'kind' => $node->kind,
                'value' => self::convert($node->value),
            ]),
            $node instanceof ClassProperty => self::entry($node, $node->isAccessor ? 'AccessorProperty' : 'PropertyDefinition', [
                'static' => $node->static,
                'computed' => $node->computed,
                'key' => self::convert($node->key),
                'value' => self::optional($node->value),
            ]),
            $node instanceof StaticBlock => self::entry($node, 'StaticBlock', [
                'body' => self::nodeList($node->body->body),
            ]),
            $node instanceof TemplateLiteral => self::entry($node, 'TemplateLiteral', [
                'expressions' => self::nodeList($node->expressions),
                'quasis' => self::nodeList($node->quasis),
            ]),
            $node instanceof TemplateElement => self::entry($node, 'TemplateElement', [
                'value' => ['raw' => $node->rawValue, 'cooked' => $node->cookedValue],
                'tail' => $node->tail,
            ]),
            $node instanceof TaggedTemplate => self::entry($node, 'TaggedTemplateExpression', [
                'tag' => self::convert($node->tag),
                'quasi' => self::convert($node->quasi),
            ]),
            $node instanceof ObjectPattern => self::entry($node, 'ObjectPattern', [
                'properties' => self::nodeList($node->properties),
            ]),
            $node instanceof ArrayPattern => self::entry($node, 'ArrayPattern', [
                'elements' => self::nodeList($node->elements),
            ]),
            $node instanceof RestElement => self::entry($node, 'RestElement', [
                'argument' => self::convert($node->argument),
            ]),
            $node instanceof AssignmentPattern => self::entry($node, 'AssignmentPattern', [
                'left' => self::convert($node->left),
                'right' => self::convert($node->right),
            ]),
            $node instanceof VariableDeclaration => self::entry($node, 'VariableDeclaration', [
                'declarations' => self::nodeList($node->declarations),
                'kind' => $node->kind,
            ]),
            $node instanceof VariableDeclarator => self::entry($node, 'VariableDeclarator', [
                'id' => self::convert($node->id),
                'init' => self::optional($node->init),
            ]),
            $node instanceof ImportDeclaration => self::entry($node, 'ImportDeclaration', [
                'specifiers' => self::nodeList($node->specifiers),
                'source' => self::literal($node->source),
            ]),
            $node instanceof ImportSpecifier => self::convertImportSpecifier($node),
            $node instanceof ExportDeclaration => self::convertExportDeclaration($node),
            $node instanceof ExportSpecifier => self::entry($node, 'ExportSpecifier', [
                'local' => $node->localIsString ? self::literal($node->local) : self::identifier($node->local),
                'exported' => $node->exportedIsString ? self::literal($node->exported) : self::identifier($node->exported),
            ]),
            $node instanceof BlockStatement => self::entry($node, 'BlockStatement', [
                'body' => self::nodeList($node->body),
            ]),
            $node instanceof ExpressionStatement => self::entry($node, 'ExpressionStatement', [
                'expression' => self::convert($node->expression),
            ]),
            $node instanceof EmptyStatement => self::entry($node, 'EmptyStatement', []),
            $node instanceof DebuggerStatement => self::entry($node, 'DebuggerStatement', []),
            $node instanceof IfStatement => self::entry($node, 'IfStatement', [
                'test' => self::convert($node->test),
                'consequent' => self::convert($node->consequent),
                'alternate' => self::optional($node->alternate),
            ]),
            $node instanceof LabeledStatement => self::entry($node, 'LabeledStatement', [
                'body' => self::convert($node->body),
                'label' => self::identifier($node->label),
            ]),
            $node instanceof BreakStatement => self::entry($node, 'BreakStatement', [
                'label' => $node->label === null ? null : self::identifier($node->label),
            ]),
            $node instanceof ContinueStatement => self::entry($node, 'ContinueStatement', [
                'label' => $node->label === null ? null : self::identifier($node->label),
            ]),
            $node instanceof WithStatement => self::entry($node, 'WithStatement', [
                'object' => self::convert($node->object),
                'body' => self::convert($node->body),
            ]),
            $node instanceof SwitchStatement => self::entry($node, 'SwitchStatement', [
                'discriminant' => self::convert($node->discriminant),
                'cases' => self::nodeList($node->cases),
            ]),
            $node instanceof SwitchCase => self::entry($node, 'SwitchCase', [
                'test' => self::optional($node->test),
                'consequent' => self::nodeList($node->consequent),
            ]),
            $node instanceof ReturnStatement => self::entry($node, 'ReturnStatement', [
                'argument' => self::optional($node->argument),
            ]),
            $node instanceof ThrowStatement => self::entry($node, 'ThrowStatement', [
                'argument' => self::convert($node->argument),
            ]),
            $node instanceof TryStatement => self::entry($node, 'TryStatement', [
                'block' => self::convert($node->block),
                'handler' => self::optional($node->handler),
                'finalizer' => self::optional($node->finalizer),
            ]),
            $node instanceof CatchClause => self::entry($node, 'CatchClause', [
                'param' => self::optional($node->param),
                'body' => self::convert($node->body),
            ]),
            $node instanceof WhileStatement => self::entry($node, 'WhileStatement', [
                'test' => self::convert($node->test),
                'body' => self::convert($node->body),
            ]),
            $node instanceof DoWhileStatement => self::entry($node, 'DoWhileStatement', [
                'body' => self::convert($node->body),
                'test' => self::convert($node->test),
            ]),
            $node instanceof ForStatement => self::entry($node, 'ForStatement', [
                'init' => self::optional($node->init),
                'test' => self::optional($node->test),
                'update' => self::optional($node->update),
                'body' => self::convert($node->body),
            ]),
            $node instanceof ForInStatement => self::entry($node, 'ForInStatement', [
                'left' => self::convert($node->left),
                'right' => self::convert($node->right),
                'body' => self::convert($node->body),
            ]),
            $node instanceof ForOfStatement => self::entry($node, 'ForOfStatement', [
                'await' => $node->await,
                'left' => self::convert($node->left),
                'right' => self::convert($node->right),
                'body' => self::convert($node->body),
            ]),
            default => self::entry($node, $node->type(), []),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function convertLiteral(Literal $node): array
    {
        if (str_starts_with($node->raw, '__REGEXP__')) {
            $text = substr($node->raw, 10);
            $slash = strrpos($text, '/');
            $slash = $slash === false ? 0 : $slash;
            return self::entry($node, 'Literal', [
                'value' => null,
                'regex' => [
                    'pattern' => substr($text, 1, $slash - 1),
                    'flags' => substr($text, $slash + 1),
                ],
            ]);
        }
        if (str_starts_with($node->raw, '__BIGINT__')) {
            return self::entry($node, 'Literal', [
                'value' => null,
                'bigint' => is_string($node->value) ? $node->value : (string) $node->value,
            ]);
        }
        return self::entry($node, 'Literal', ['value' => $node->value]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function convertImportSpecifier(ImportSpecifier $node): array
    {
        return match ($node->specType) {
            'default' => self::entry($node, 'ImportDefaultSpecifier', [
                'local' => self::identifier($node->local),
            ]),
            'namespace' => self::entry($node, 'ImportNamespaceSpecifier', [
                'local' => self::identifier($node->local),
            ]),
            default => self::entry($node, 'ImportSpecifier', [
                'imported' => self::identifier($node->imported ?? $node->local),
                'local' => self::identifier($node->local),
            ]),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function convertExportDeclaration(ExportDeclaration $node): array
    {
        if ($node->isAll) {
            return self::entry($node, 'ExportAllDeclaration', [
                'exported' => $node->allAs === null ? null : self::identifier($node->allAs),
                'source' => self::literal($node->source ?? ''),
            ]);
        }
        if ($node->isDefault) {
            return self::entry($node, 'ExportDefaultDeclaration', [
                'declaration' => self::optional($node->declaration),
            ]);
        }
        return self::entry($node, 'ExportNamedDeclaration', [
            'declaration' => self::optional($node->declaration),
            'specifiers' => self::nodeList($node->specifiers),
            'source' => $node->source === null ? null : self::literal($node->source),
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function entry(Node $node, string $type, array $fields): array
    {
        return ['type' => $type, 'start' => $node->location->offset] + $fields;
    }

    /**
     * @param array<int|string, mixed> $nodes
     * @return list<array<string, mixed>|null>
     */
    private static function nodeList(array $nodes): array
    {
        $result = [];
        foreach ($nodes as $item) {
            $result[] = $item instanceof Node ? self::convert($item) : null;
        }
        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function optional(?Node $node): ?array
    {
        return $node === null ? null : self::convert($node);
    }

    /**
     * @return array<string, mixed>
     */
    private static function identifier(string $name): array
    {
        return ['type' => 'Identifier', 'name' => $name];
    }

    /**
     * @return array<string, mixed>
     */
    private static function literal(mixed $value): array
    {
        return ['type' => 'Literal', 'value' => $value];
    }

    /**
     * @param Node[] $elements
     * @return array<string, mixed>
     */
    private static function classBody(array $elements): array
    {
        return ['type' => 'ClassBody', 'body' => self::nodeList($elements)];
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $lines
     */
    private static function collectLines(array $node, int $depth, array &$lines): void
    {
        $extra = [];
        if (isset($node['name']) && is_string($node['name']) && $node['name'] !== '') {
            $extra[] = 'name=' . $node['name'];
        }
        if (array_key_exists('value', $node) && $node['value'] !== null && is_scalar($node['value'])) {
            $extra[] = 'value=' . self::stringifyScalar($node['value']);
        }
        if (isset($node['operator']) && is_string($node['operator']) && $node['operator'] !== '') {
            $extra[] = 'op=' . $node['operator'];
        }
        if (isset($node['kind']) && is_string($node['kind']) && $node['kind'] !== '') {
            $extra[] = 'kind=' . $node['kind'];
        }
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $lines[] = str_repeat('  ', $depth) . $type . ($extra === [] ? '' : ' ' . implode(' ', $extra));
        foreach ($node as $child) {
            if (!is_array($child)) {
                continue;
            }
            if (isset($child['type']) && is_string($child['type'])) {
                self::collectLines($child, $depth + 1, $lines);
                continue;
            }
            foreach ($child as $item) {
                if (is_array($item) && isset($item['type']) && is_string($item['type'])) {
                    self::collectLines($item, $depth + 1, $lines);
                }
            }
        }
    }

    private static function stringifyScalar(bool|int|float|string $value): string
    {
        if (is_string($value)) {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            );
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        return JsNumber::of($value)->toJsString();
    }
}
