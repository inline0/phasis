<?php

declare(strict_types=1);

namespace Phasis\Formatter;

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
use Phasis\Ast\Node;
use Phasis\Ast\Pattern\ArrayPattern;
use Phasis\Ast\Pattern\AssignmentPattern;
use Phasis\Ast\Pattern\AssignmentProperty;
use Phasis\Ast\Pattern\ObjectPattern;
use Phasis\Ast\Pattern\RestElement;
use Phasis\Ast\Program;
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

/**
 * Maps the phasis AST to the formatter's document language with prettier's
 * layout semantics: groups that flatten when they fit, hugged last call
 * arguments, member chain breaking, original-multiline object preservation,
 * and at most one preserved blank line between statements.
 */
final class JsPrinter
{
    private const PRECEDENCE = [
        '??' => 4,
        '||' => 4,
        '&&' => 5,
        '|' => 6,
        '^' => 7,
        '&' => 8,
        '==' => 9,
        '!=' => 9,
        '===' => 9,
        '!==' => 9,
        '<' => 10,
        '>' => 10,
        '<=' => 10,
        '>=' => 10,
        'in' => 10,
        'instanceof' => 10,
        '<<' => 11,
        '>>' => 11,
        '>>>' => 11,
        '+' => 12,
        '-' => 12,
        '*' => 13,
        '/' => 13,
        '%' => 13,
        '**' => 14,
    ];

    public function __construct(
        private readonly FormatOptions $options,
        private readonly CommentIndex $comments,
        private readonly string $source,
        private readonly bool $jsonMode = false,
    ) {
    }

    /**
     * Prints a parsed JSON document: the wrapped program's single expression
     * with its comments, without a statement semicolon.
     *
     * @return array<int, mixed>
     */
    public function printJsonRoot(\Phasis\Ast\Program $program): array
    {
        $statement = $program->body[0];
        if (!$statement instanceof ExpressionStatement) {
            throw new \InvalidArgumentException('JSON source must be a single value.');
        }
        $doc = [$this->withComments($statement, $this->print($statement->expression, null))];
        foreach ($this->comments->trailingFor($program) as $attached) {
            $doc[] = Doc::hardline();
            $doc[] = $this->commentText($attached);
        }
        $doc[] = Doc::hardline();
        return $doc;
    }

    /** @return array<int, mixed> */
    public function printProgram(Program $program): array
    {
        $doc = [];
        if ($program->body === []) {
            foreach ($this->comments->danglingFor($program) as $attached) {
                $doc[] = $this->commentText($attached);
                $doc[] = Doc::hardline();
            }
            return $doc;
        }
        $doc[] = $this->statementList($program->body);
        foreach ($this->comments->trailingFor($program) as $attached) {
            $doc[] = Doc::hardline();
            $doc[] = $this->commentText($attached);
        }
        $doc[] = Doc::hardline();
        return $doc;
    }

    /**
     * @param array<int, Node> $statements
     */
    private function statementList(array $statements, bool $preserveLeadingBlank = false): mixed
    {
        $parts = [];
        $first = true;
        foreach ($statements as $statement) {
            if ($statement instanceof EmptyStatement && $this->comments->leadingFor($statement) === []) {
                continue;
            }
            if (!$first) {
                $parts[] = Doc::hardline();
                if ($this->blankBefore($this->effectiveStart($statement))) {
                    $parts[] = Doc::hardline();
                }
            } elseif ($preserveLeadingBlank && $this->blankBefore($this->effectiveStart($statement))) {
                $parts[] = Doc::hardline();
            }
            $parts[] = $this->withComments($statement, $this->statement($statement));
            $first = false;
        }
        return $parts;
    }

    private function effectiveStart(Node $node): int
    {
        $leading = $this->comments->leadingFor($node);
        if ($leading !== []) {
            return $leading[0]->comment->start->offset;
        }
        return $node->location->offset;
    }

    private function withComments(Node $node, mixed $doc): mixed
    {
        $leading = $this->comments->leadingFor($node);
        $trailing = $this->comments->trailingFor($node);
        if ($leading === [] && $trailing === []) {
            return $doc;
        }

        $parts = [];
        foreach ($leading as $attached) {
            $parts[] = $this->commentText($attached);
            if ($attached->comment->kind === 'line' || $this->newlineAfterComment($attached)) {
                $parts[] = Doc::hardline();
                if ($this->blankAfterComment($attached)) {
                    $parts[] = Doc::hardline();
                }
            } else {
                $parts[] = ' ';
            }
        }
        $parts[] = $doc;
        foreach ($trailing as $attached) {
            if ($attached->ownLine) {
                $parts[] = Doc::hardline();
                if ($attached->blankBefore) {
                    $parts[] = Doc::hardline();
                }
                $parts[] = $this->commentText($attached);
            } else {
                $parts[] = Doc::lineSuffix([' ', $this->commentText($attached)]);
                $parts[] = Doc::breakParent();
            }
        }
        return $parts;
    }

    private function newlineAfterComment(AttachedComment $attached): bool
    {
        $length = strlen($this->source);
        for ($i = $attached->comment->endOffset; $i < $length; $i++) {
            $ch = $this->source[$i];
            if ($ch === "\n") {
                return true;
            }
            if ($ch === ' ' || $ch === "\t" || $ch === "\r") {
                continue;
            }
            break;
        }
        return false;
    }

    private function blankAfterComment(AttachedComment $attached): bool
    {
        $newlines = 0;
        $length = strlen($this->source);
        for ($i = $attached->comment->endOffset; $i < $length; $i++) {
            $ch = $this->source[$i];
            if ($ch === "\n") {
                $newlines++;
                if ($newlines >= 2) {
                    return true;
                }
                continue;
            }
            if ($ch === ' ' || $ch === "\t" || $ch === "\r") {
                continue;
            }
            break;
        }
        return false;
    }

    private function commentText(AttachedComment $attached): mixed
    {
        $raw = $attached->comment->raw;
        if ($attached->comment->kind === 'line' || !str_contains($raw, "\n")) {
            return $raw;
        }

        $lines = explode("\n", $raw);
        $aligned = true;
        for ($i = 1; $i < count($lines); $i++) {
            if (!str_starts_with(ltrim($lines[$i], " \t"), '*')) {
                $aligned = false;
                break;
            }
        }
        if (!$aligned) {
            $parts = [];
            foreach ($lines as $i => $line) {
                if ($i > 0) {
                    $parts[] = Doc::literalline();
                }
                $parts[] = $line;
            }
            return $parts;
        }
        $parts = [];
        foreach ($lines as $i => $line) {
            if ($i === 0) {
                $parts[] = rtrim($line);
                continue;
            }
            $parts[] = Doc::hardline();
            $parts[] = ' ' . ltrim($line, " \t");
        }
        return $parts;
    }

    private function blankBefore(int $offset): bool
    {
        $newlines = 0;
        for ($i = $offset - 1; $i >= 0; $i--) {
            $ch = $this->source[$i];
            if ($ch === "\n") {
                $newlines++;
                if ($newlines >= 2) {
                    return true;
                }
                continue;
            }
            if ($ch === ' ' || $ch === "\t" || $ch === "\r" || $ch === '(') {
                continue;
            }
            break;
        }
        return false;
    }

    private function statement(Node $node): mixed
    {
        return match (true) {
            $node instanceof ExpressionStatement => $this->expressionStatement($node),
            $node instanceof VariableDeclaration => [$this->variableDeclaration($node), ';'],
            $node instanceof FunctionDeclaration => $this->functionNode($node, $node->id?->name),
            $node instanceof ClassDeclaration => $this->classNode($node),
            $node instanceof BlockStatement => $this->block($node),
            $node instanceof IfStatement => $this->ifStatement($node),
            $node instanceof ForStatement => $this->forStatement($node),
            $node instanceof ForInStatement => $this->forInOf($node, 'in'),
            $node instanceof ForOfStatement => $this->forInOf($node, 'of'),
            $node instanceof WhileStatement => $this->whileStatement($node),
            $node instanceof DoWhileStatement => $this->doWhileStatement($node),
            $node instanceof ReturnStatement => $this->returnLike('return', $node->argument),
            $node instanceof ThrowStatement => $this->returnLike('throw', $node->argument),
            $node instanceof TryStatement => $this->tryStatement($node),
            $node instanceof SwitchStatement => $this->switchStatement($node),
            $node instanceof BreakStatement => [$node->label === null ? 'break' : 'break ' . $node->label, ';'],
            $node instanceof ContinueStatement => [
                $node->label === null ? 'continue' : 'continue ' . $node->label,
                ';',
            ],
            $node instanceof LabeledStatement => $this->labeledStatement($node),
            $node instanceof EmptyStatement => '',
            $node instanceof DebuggerStatement => 'debugger;',
            $node instanceof WithStatement => $this->withStatement($node),
            $node instanceof ImportDeclaration => $this->importDeclaration($node),
            $node instanceof ExportDeclaration => $this->exportDeclaration($node),
            default => throw new \LogicException('Unhandled statement: ' . $node->type()),
        };
    }

    private function expressionStatement(ExpressionStatement $node): mixed
    {
        $expression = $node->expression;
        if ($expression instanceof Literal && is_string($expression->value) && $this->isSourceString($expression)) {
            return [$this->directiveText($expression), ';'];
        }

        $needsParens = $this->statementStartNeedsParens($expression);
        $doc = $this->print($expression, $node);
        if ($needsParens) {
            return ['(', $doc, ');'];
        }
        return [$doc, ';'];
    }

    /** Whether the leftmost token of an expression statement would be misparsed without parens. */
    private function statementStartNeedsParens(Node $node): bool
    {
        while (true) {
            if (
                $node instanceof ObjectExpression
                || $node instanceof FunctionExpression
                || $node instanceof ClassExpression
            ) {
                return true;
            }
            $child = null;
            if ($node instanceof AssignmentExpression || $node instanceof BinaryExpression || $node instanceof LogicalExpression) {
                $child = $node->left;
            } elseif ($node instanceof SequenceExpression) {
                $child = $node->expressions[0];
            } elseif ($node instanceof ConditionalExpression) {
                $child = $node->test;
            } elseif ($node instanceof CallExpression || $node instanceof NewExpression) {
                $child = $node->callee;
            } elseif ($node instanceof MemberExpression) {
                $child = $node->object;
            } elseif ($node instanceof TaggedTemplate) {
                $child = $node->tag;
            } elseif ($node instanceof UpdateExpression && !$node->prefix) {
                $child = $node->argument;
            }
            if ($child === null) {
                return false;
            }
            if ($this->needsParens($child, $node)) {
                return false;
            }
            $node = $child;
        }
    }

    private function block(BlockStatement $node): mixed
    {
        if ($node->body === [] || $this->onlyEmptyStatements($node->body)) {
            $dangling = $this->comments->danglingFor($node);
            if ($dangling === []) {
                return '{}';
            }
            $parts = ['{'];
            foreach ($dangling as $attached) {
                $parts[] = Doc::indent([Doc::hardline(), $this->commentText($attached)]);
            }
            $parts[] = Doc::hardline();
            $parts[] = '}';
            return $parts;
        }
        return ['{', Doc::indent([Doc::hardline(), $this->statementList($node->body, true)]), Doc::hardline(), '}'];
    }

    /**
     * @param array<int, Node> $body
     */
    private function onlyEmptyStatements(array $body): bool
    {
        foreach ($body as $statement) {
            if (!$statement instanceof EmptyStatement || $this->comments->leadingFor($statement) !== []) {
                return false;
            }
        }
        return true;
    }

    private function ifStatement(IfStatement $node): mixed
    {
        $test = Doc::group([Doc::indent([Doc::softline(), $this->print($node->test, $node)]), Doc::softline()]);
        $parts = [];
        if ($node->consequent instanceof BlockStatement) {
            $parts[] = ['if (', $test, ') ', $this->block($node->consequent)];
        } else {
            $parts[] = Doc::group(['if (', $test, ')', $this->clauseBody($node->consequent)]);
        }
        if ($node->alternate !== null) {
            $parts[] = $node->consequent instanceof BlockStatement ? ' else' : [Doc::hardline(), 'else'];
            if ($node->alternate instanceof IfStatement) {
                $parts[] = ' ';
                $parts[] = $this->ifStatement($node->alternate);
            } elseif ($node->alternate instanceof BlockStatement) {
                $parts[] = ' ';
                $parts[] = $this->block($node->alternate);
            } else {
                $parts[] = Doc::group($this->clauseBody($node->alternate));
            }
        }
        return $parts;
    }

    private function clauseBody(Node $body): mixed
    {
        if ($body instanceof BlockStatement) {
            return [' ', $this->block($body)];
        }
        if ($body instanceof EmptyStatement) {
            return ';';
        }
        return Doc::indent([Doc::line(), $this->withComments($body, $this->statement($body))]);
    }

    private function forStatement(ForStatement $node): mixed
    {
        if ($node->init === null && $node->test === null && $node->update === null) {
            return ['for (;;)', $this->clauseBody($node->body)];
        }
        $init = $node->init === null
            ? ''
            : ($node->init instanceof VariableDeclaration
                ? $this->variableDeclaration($node->init)
                : $this->print($node->init, $node));
        $test = $node->test === null ? '' : $this->print($node->test, $node);
        $update = $node->update === null ? '' : $this->print($node->update, $node);
        return Doc::group([
            'for (',
            Doc::group([
                Doc::indent([
                    Doc::softline(),
                    $init,
                    ';',
                    Doc::line(),
                    $test,
                    ';',
                    Doc::line(),
                    $update,
                ]),
                Doc::softline(),
            ]),
            ')',
            $this->clauseBody($node->body),
        ]);
    }

    private function forInOf(ForInStatement|ForOfStatement $node, string $keyword): mixed
    {
        $await = $node instanceof ForOfStatement && $node->await ? 'await ' : '';
        $left = $node->left instanceof VariableDeclaration
            ? $this->variableDeclaration($node->left)
            : $this->print($node->left, $node);
        return Doc::group([
            'for ' . $await . '(',
            $left,
            ' ' . $keyword . ' ',
            $this->print($node->right, $node),
            ')',
            $this->clauseBody($node->body),
        ]);
    }

    private function whileStatement(WhileStatement $node): mixed
    {
        return Doc::group([
            'while (',
            Doc::group([Doc::indent([Doc::softline(), $this->print($node->test, $node)]), Doc::softline()]),
            ')',
            $this->clauseBody($node->body),
        ]);
    }

    private function doWhileStatement(DoWhileStatement $node): mixed
    {
        $body = $node->body instanceof BlockStatement
            ? [$this->block($node->body), ' ']
            : [Doc::indent([Doc::hardline(), $this->statement($node->body)]), Doc::hardline()];
        return [
            'do ',
            $body,
            'while (',
            Doc::group([Doc::indent([Doc::softline(), $this->print($node->test, $node)]), Doc::softline()]),
            ');',
        ];
    }

    private function returnLike(string $keyword, ?Node $argument): mixed
    {
        if ($argument === null) {
            return [$keyword, ';'];
        }
        if ($argument instanceof BinaryExpression || $argument instanceof LogicalExpression) {
            return [
                $keyword . ' ',
                Doc::group([
                    Doc::ifBreak('('),
                    Doc::indent([Doc::softline(), $this->print($argument, null)]),
                    Doc::softline(),
                    Doc::ifBreak(')'),
                ]),
                ';',
            ];
        }
        if ($argument instanceof SequenceExpression) {
            return [$keyword . ' (', $this->print($argument, null), ');'];
        }
        return [$keyword . ' ', $this->print($argument, null), ';'];
    }

    private function tryStatement(TryStatement $node): mixed
    {
        $parts = ['try ', $this->block($node->block)];
        if ($node->handler !== null) {
            $parts[] = ' catch ';
            if ($node->handler->param !== null) {
                $parts[] = ['(', $this->print($node->handler->param, $node->handler), ') '];
            }
            $parts[] = $this->block($node->handler->body);
        }
        if ($node->finalizer !== null) {
            $parts[] = ' finally ';
            $parts[] = $this->block($node->finalizer);
        }
        return $parts;
    }

    private function switchStatement(SwitchStatement $node): mixed
    {
        $parts = [
            'switch (',
            Doc::group([Doc::indent([Doc::softline(), $this->print($node->discriminant, $node)]), Doc::softline()]),
            ') {',
        ];
        $caseParts = [];
        foreach ($node->cases as $index => $case) {
            if ($index > 0) {
                $caseParts[] = Doc::hardline();
                if ($this->blankBefore($this->effectiveStart($case))) {
                    $caseParts[] = Doc::hardline();
                }
            }
            $caseParts[] = $this->withComments($case, $this->switchCase($case));
        }
        $parts[] = Doc::indent([Doc::hardline(), $caseParts]);
        $parts[] = Doc::hardline();
        $parts[] = '}';
        return $parts;
    }

    private function switchCase(SwitchCase $node): mixed
    {
        $label = $node->test === null ? 'default:' : ['case ', $this->print($node->test, $node), ':'];
        $consequent = array_values(array_filter(
            $node->consequent,
            fn(Node $statement): bool => !$statement instanceof EmptyStatement
                || $this->comments->leadingFor($statement) !== [],
        ));
        if ($consequent === []) {
            return $label;
        }
        if (count($consequent) === 1 && $consequent[0] instanceof BlockStatement) {
            return [$label, ' ', $this->block($consequent[0])];
        }
        return [$label, Doc::indent([Doc::hardline(), $this->statementList($consequent)])];
    }

    private function labeledStatement(LabeledStatement $node): mixed
    {
        if ($node->body instanceof EmptyStatement) {
            return [$node->label, ':;'];
        }
        return [$node->label, ': ', $this->statement($node->body)];
    }

    private function withStatement(WithStatement $node): mixed
    {
        return ['with (', $this->print($node->object, $node), ')',
        $this->clauseBody($node->body)];
    }

    private function variableDeclaration(VariableDeclaration $node): mixed
    {
        $printed = array_map(
            fn(Node $declarator): mixed => $this->variableDeclarator($declarator),
            $node->declarations,
        );
        if (count($printed) === 1) {
            return Doc::group([$node->kind . ' ', $printed[0]]);
        }
        $joined = [];
        foreach ($printed as $index => $doc) {
            if ($index > 0) {
                $joined[] = ',';
                $joined[] = Doc::hardline();
            }
            $joined[] = $doc;
        }
        return Doc::group([$node->kind . ' ', Doc::indent($joined)]);
    }

    private function variableDeclarator(Node $node): mixed
    {
        if (!$node instanceof VariableDeclarator) {
            return $this->print($node, null);
        }
        $id = $this->print($node->id, $node);
        if ($node->init === null) {
            return $id;
        }
        return $this->assignmentLike($id, '=', $node->init, $node);
    }

    private function assignmentLike(mixed $leftDoc, string $operator, Node $right, ?Node $parent): mixed
    {
        $rightDoc = $this->print($right, $parent);

        if ($this->rhsNeverBreaksAfterOperator($right)) {
            return Doc::group([$leftDoc, ' ' . $operator . ' ', $rightDoc]);
        }

        return Doc::group([$leftDoc, ' ' . $operator, Doc::group(Doc::indent([Doc::line(), $rightDoc]))]);
    }

    private function rhsNeverBreaksAfterOperator(Node $right): bool
    {
        return $right instanceof ArrowFunction
            || $right instanceof FunctionExpression
            || $right instanceof ClassExpression
            || $right instanceof TemplateLiteral
            || $right instanceof TaggedTemplate
            || $right instanceof ObjectExpression
            || $right instanceof ArrayExpression
            || $right instanceof CallExpression
            || $right instanceof NewExpression
            || $right instanceof MemberExpression
            || $right instanceof ImportExpression
            || $right instanceof Identifier
            || $right instanceof ThisExpression
            || ($right instanceof Literal && !is_string($right->value))
            || ($right instanceof ConditionalExpression
                && !$right->test instanceof BinaryExpression
                && !$right->test instanceof LogicalExpression)
            || $right instanceof AwaitExpression
            || $right instanceof UnaryExpression
            || $right instanceof UpdateExpression
            || ($right instanceof AssignmentExpression && $this->rhsNeverBreaksAfterOperator($right->right));
    }

    private function functionNode(
        FunctionDeclaration|FunctionExpression $node,
        ?string $name,
    ): mixed {
        $params = $node->params;
        $prefix = ($node->async ? 'async ' : '') . 'function' . ($node->generator ? '*' : '');
        $prefix .= $name !== null && $name !== '' ? ' ' . $name : ' ';
        $body = $node->body;
        return [
            $prefix,
            $this->functionParams($params, $node),
            ' ',
            $body instanceof BlockStatement ? $this->block($body) : $this->print($body, $node),
        ];
    }

    /**
     * @param array<int, Node> $params
     */
    private function functionParams(array $params, Node $parent): mixed
    {
        if ($params === []) {
            $dangling = $this->comments->danglingFor($parent);
            if ($dangling !== []) {
                $parts = ['('];
                foreach ($dangling as $index => $attached) {
                    if ($index > 0) {
                        $parts[] = ' ';
                    }
                    $parts[] = $this->commentText($attached);
                }
                $parts[] = ')';
                return $parts;
            }
            return '()';
        }

        if (count($params) === 1 && $this->paramHugs($params[0])) {
            return ['(', $this->print($params[0], $parent), ')'];
        }

        $printed = array_map(
            fn(Node $param): mixed => $this->withComments($param, $this->print($param, $parent)),
            $params,
        );
        $last = $params[count($params) - 1];
        $trailing = $last instanceof RestElement ? '' : $this->trailingCommaDoc(false);
        return Doc::group([
            '(',
            Doc::indent([Doc::softline(), Doc::join([',', Doc::line()], $printed)]),
            $trailing,
            Doc::softline(),
            ')',
        ]);
    }

    private function paramHugs(Node $param): bool
    {
        if ($param instanceof AssignmentPattern) {
            return $param->left instanceof ObjectPattern || $param->left instanceof ArrayPattern;
        }
        return $param instanceof ObjectPattern || $param instanceof ArrayPattern;
    }

    private function classNode(ClassDeclaration|ClassExpression $node): mixed
    {
        $name = $node instanceof ClassDeclaration ? $node->id?->name : $node->id?->name;
        $parts = ['class'];
        if ($name !== null && $name !== '') {
            $parts[] = ' ' . $name;
        }
        if ($node->superClass !== null) {
            $parts[] = ' extends ';
            $parts[] = $this->print($node->superClass, $node);
        }
        $parts[] = ' ';
        if ($node->body === []) {
            $parts[] = '{}';
            return $parts;
        }
        $members = [];
        $first = true;
        foreach ($node->body as $member) {
            if (!$first) {
                $members[] = Doc::hardline();
                if ($this->blankBefore($this->effectiveStart($member))) {
                    $members[] = Doc::hardline();
                }
            }
            $members[] = $this->withComments($member, $this->classMember($member));
            $first = false;
        }
        $parts[] = ['{', Doc::indent([Doc::hardline(), $members]), Doc::hardline(), '}'];
        return $parts;
    }

    private function classMember(Node $node): mixed
    {
        if ($node instanceof ClassMethod) {
            $prefix = $node->static ? 'static ' : '';
            return [$prefix, $this->methodLike($node->key, $node->computed, $node->value, $node->kind)];
        }
        if ($node instanceof ClassProperty) {
            $parts = [];
            if ($node->static) {
                $parts[] = 'static ';
            }
            if ($node->isAccessor) {
                $parts[] = 'accessor ';
            }
            $parts[] = $this->propertyKey($node->key, $node->computed);
            if ($node->value !== null) {
                $parts[] = ' = ';
                $parts[] = $this->print($node->value, $node);
            }
            $parts[] = ';';
            return $parts;
        }
        if ($node instanceof StaticBlock) {
            return ['static ', $this->block($node->body)];
        }
        return $this->print($node, null);
    }

    private function methodLike(Node $key, bool $computed, FunctionExpression $function, string $kind): mixed
    {
        $prefix = '';
        if ($kind === 'get' || $kind === 'set') {
            $prefix = $kind . ' ';
        }
        if ($function->async) {
            $prefix .= 'async ';
        }
        if ($function->generator) {
            $prefix .= '*';
        }
        $body = $function->body;
        return [
            $prefix,
            $this->propertyKey($key, $computed),
            $this->functionParams($function->params, $function),
            ' ',
            $body instanceof BlockStatement ? $this->block($body) : $this->print($body, $function),
        ];
    }

    private function trailingCommaDoc(bool $es5Context): mixed
    {
        if ($this->jsonMode || $this->options->trailingComma === 'none') {
            return '';
        }
        if ($this->options->trailingComma === 'es5' && !$es5Context) {
            return '';
        }
        return Doc::ifBreak(',');
    }

    private function propertyKey(Node $key, bool $computed): mixed
    {
        if ($computed) {
            return ['[', $this->print($key, null), ']'];
        }
        if ($key instanceof Literal && is_string($key->value) && $this->isSourceString($key)) {
            if ($this->jsonMode) {
                return LiteralText::makeString(substr($this->stringSourceRaw($key), 1, -1), '"');
            }
            $content = $key->value;
            if (preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $content) === 1) {
                return $content;
            }
            if (
                preg_match('/^(?:0|[1-9][0-9]*)$/', $content) === 1
                && (string) (int) $content === $content
            ) {
                return $content;
            }
            return LiteralText::printString($this->stringSourceRaw($key), $this->options);
        }
        return $this->print($key, null);
    }

    private function importDeclaration(ImportDeclaration $node): mixed
    {
        if ($node->specifiers === []) {
            return ['import ', $this->moduleString($node->source), ';'];
        }

        $default = null;
        $namespace = null;
        $named = [];
        foreach ($node->specifiers as $specifier) {
            if (!$specifier instanceof ImportSpecifier) {
                continue;
            }
            if ($specifier->specType === 'default') {
                $default = $specifier->local;
            } elseif ($specifier->specType === 'namespace') {
                $namespace = $specifier->local;
            } else {
                $imported = $specifier->imported ?? $specifier->local;
                $named[] = $imported === $specifier->local
                    ? $specifier->local
                    : $imported . ' as ' . $specifier->local;
            }
        }

        $clauses = [];
        if ($default !== null) {
            $clauses[] = $default;
        }
        if ($namespace !== null) {
            $clauses[] = '* as ' . $namespace;
        }
        $parts = ['import '];
        $parts[] = Doc::join(', ', $clauses);
        if ($named !== []) {
            if ($clauses !== []) {
                $parts[] = ', ';
            }
            $parts[] = $this->namedSpecifierBraces($named);
        }
        $parts[] = ' from ';
        $parts[] = $this->moduleString($node->source);
        $parts[] = ';';
        return $parts;
    }

    /**
     * @param array<int, string> $named
     */
    private function namedSpecifierBraces(array $named): mixed
    {
        $space = $this->options->bracketSpacing ? Doc::line() : Doc::softline();
        return Doc::group([
            '{',
            Doc::indent([$space, Doc::join([',', Doc::line()], $named)]),
            $this->trailingCommaDoc(true),
            $space,
            '}',
        ]);
    }

    private function exportDeclaration(ExportDeclaration $node): mixed
    {
        if ($node->isAll) {
            $clause = $node->allAs !== null ? '* as ' . $node->allAs : '*';
            return ['export ' . $clause . ' from ', $this->moduleString((string) $node->source), ';'];
        }
        if ($node->isDefault) {
            $declaration = $node->declaration;
            if ($declaration === null) {
                return 'export default;';
            }
            if ($declaration instanceof FunctionDeclaration) {
                return ['export default ', $this->functionNode($declaration, $declaration->id?->name)];
            }
            if ($declaration instanceof ClassDeclaration) {
                return ['export default ', $this->classNode($declaration)];
            }
            return ['export default ', $this->print($declaration, $node), ';'];
        }
        if ($node->declaration !== null) {
            return ['export ', $this->statement($node->declaration)];
        }

        $named = [];
        foreach ($node->specifiers as $specifier) {
            if (!$specifier instanceof ExportSpecifier) {
                continue;
            }
            $local = $specifier->localIsString
                ? LiteralText::makeString($specifier->local, $this->options->singleQuote ? "'" : '"')
                : $specifier->local;
            $exported = $specifier->exportedIsString
                ? LiteralText::makeString($specifier->exported, $this->options->singleQuote ? "'" : '"')
                : $specifier->exported;
            $named[] = $local === $exported && !$specifier->localIsString && !$specifier->exportedIsString
                ? $local
                : $local . ' as ' . $exported;
        }
        $parts = ['export ', $this->namedSpecifierBraces($named)];
        if ($node->source !== null) {
            $parts[] = ' from ';
            $parts[] = $this->moduleString($node->source);
        }
        $parts[] = ';';
        return $parts;
    }

    private function moduleString(string $cooked): string
    {
        return LiteralText::makeString(
            $this->escapeCookedForRawContext($cooked),
            $this->options->singleQuote ? "'" : '"',
        );
    }

    private function print(Node $node, ?Node $parent): mixed
    {
        $doc = $this->printWithoutParens($node, $parent);
        if ($parent !== null && $this->needsParens($node, $parent)) {
            return ['(', $doc, ')'];
        }
        return $doc;
    }

    private function printWithoutParens(Node $node, ?Node $parent): mixed
    {
        return match (true) {
            $node instanceof Identifier => $node->name,
            $node instanceof PrivateIdentifier
                => str_starts_with($node->name, '#') ? $node->name : '#' . $node->name,
            $node instanceof ThisExpression => 'this',
            $node instanceof Literal => $this->literal($node),
            $node instanceof TemplateLiteral => $this->templateLiteral($node),
            $node instanceof TaggedTemplate => [
                $this->print($node->tag, $node),
                $this->templateLiteral($node->quasi),
            ],
            $node instanceof ArrayExpression => $this->arrayLike($node, $node->elements),
            $node instanceof ArrayPattern => $this->arrayLike($node, $node->elements),
            $node instanceof ObjectExpression => $this->objectLike($node, $node->properties),
            $node instanceof ObjectPattern => $this->objectLike($node, $node->properties),
            $node instanceof Property => $this->objectProperty($node),
            $node instanceof AssignmentProperty => $this->assignmentProperty($node),
            $node instanceof AssignmentPattern => [
                $this->print($node->left, $node),
                ' = ',
                $this->print($node->right, $node),
            ],
            $node instanceof RestElement => ['...', $this->print($node->argument, $node)],
            $node instanceof SpreadElement => ['...', $this->print($node->argument, $node)],
            $node instanceof FunctionExpression => $this->functionNode($node, $node->name),
            $node instanceof ArrowFunction => $this->arrowFunction($node),
            $node instanceof ClassExpression => $this->classNode($node),
            $node instanceof CallExpression => $this->callExpression($node),
            $node instanceof NewExpression => $this->newExpression($node),
            $node instanceof ImportExpression => $this->importExpression($node),
            $node instanceof MemberExpression => $this->memberExpression($node),
            $node instanceof MetaProperty => $node->meta . '.' . $node->property,
            $node instanceof UnaryExpression => $this->unaryExpression($node),
            $node instanceof UpdateExpression => $node->prefix
                ? [$node->operator, $this->print($node->argument, $node)]
                : [$this->print($node->argument, $node), $node->operator],
            $node instanceof AwaitExpression => ['await ', $this->print($node->argument, $node)],
            $node instanceof YieldExpression => $this->yieldExpression($node),
            $node instanceof BinaryExpression, $node instanceof LogicalExpression => $this->binaryish($node, $parent),
            $node instanceof AssignmentExpression => $this->assignmentLike(
                $this->print($node->left, $node),
                $node->operator,
                $node->right,
                $node,
            ),
            $node instanceof ConditionalExpression => $this->conditional($node),
            $node instanceof SequenceExpression => Doc::group(
                Doc::join([',', Doc::line()], array_map(fn(Node $e): mixed => $this->print($e, $node), $node->expressions)),
            ),
            $node instanceof VariableDeclaration => $this->variableDeclaration($node),
            $node instanceof VariableDeclarator => $this->variableDeclarator($node),
            $node instanceof BlockStatement => $this->block($node),
            $node instanceof TemplateElement => $node->rawValue,
            default => throw new \LogicException('Unhandled node: ' . $node->type()),
        };
    }

    private function literal(Literal $node): mixed
    {
        $raw = $node->raw;
        if (str_starts_with($raw, '__REGEXP__')) {
            return substr($raw, 10);
        }
        if (str_starts_with($raw, '__BIGINT__')) {
            return strtolower(substr($raw, 10));
        }
        if (is_string($node->value) && $this->isSourceString($node)) {
            if ($this->jsonMode) {
                return LiteralText::makeString(substr($this->stringSourceRaw($node), 1, -1), '"');
            }
            return LiteralText::printString($this->stringSourceRaw($node), $this->options);
        }
        if (is_float($node->value) || is_int($node->value)) {
            return LiteralText::printNumber($this->numberSourceRaw($node) ?? $raw);
        }
        return $raw;
    }

    /** Exact raw number text at the literal's offset, or null when it cannot be scanned. */
    private function numberSourceRaw(Literal $node): ?string
    {
        $matched = preg_match(
            '/\.?[0-9][0-9a-fA-F_xXoObB]*(?:\.[0-9_]*)?(?:[eEpP][+-]?[0-9_]+)?n?/A',
            $this->source,
            $match,
            0,
            $node->location->offset,
        );
        return $matched === 1 ? $match[0] : null;
    }

    /** Whether the literal's source text at its offset is a quoted string. */
    private function isSourceString(Literal $node): bool
    {
        $offset = $node->location->offset;
        if ($offset >= strlen($this->source)) {
            return false;
        }
        $ch = $this->source[$offset];
        return $ch === '"' || $ch === "'";
    }

    /** Exact raw source text of a string literal, scanned from its offset. */
    private function stringSourceRaw(Literal $node): string
    {
        $offset = $node->location->offset;
        $quote = $this->source[$offset];
        $length = strlen($this->source);
        for ($i = $offset + 1; $i < $length; $i++) {
            $ch = $this->source[$i];
            if ($ch === '\\') {
                $i++;
                continue;
            }
            if ($ch === $quote) {
                return substr($this->source, $offset, $i - $offset + 1);
            }
        }
        return $quote . $this->escapeCookedForRawContext((string) $node->value) . $quote;
    }

    private function escapeCookedForRawContext(string $cooked): string
    {
        return strtr($cooked, [
            '\\' => '\\\\',
            "\n" => '\\n',
            "\r" => '\\r',
        ]);
    }

    private function directiveText(Literal $node): string
    {
        $raw = $this->stringSourceRaw($node);
        $content = substr($raw, 1, -1);
        if (!str_contains($content, '"') && !str_contains($content, "'")) {
            $quote = $this->options->singleQuote ? "'" : '"';
            return $quote . $content . $quote;
        }
        return $raw;
    }

    private function templateLiteral(TemplateLiteral $node): mixed
    {
        $parts = ['`'];
        foreach ($node->quasis as $index => $quasi) {
            if (!$quasi instanceof TemplateElement) {
                continue;
            }
            $parts[] = $this->rawWithLiterallines($quasi->rawValue);
            if (!$quasi->tail && isset($node->expressions[$index])) {
                $parts[] = '${';
                $parts[] = $this->print($node->expressions[$index], $node);
                $parts[] = '}';
            }
        }
        $parts[] = '`';
        return $parts;
    }

    private function rawWithLiterallines(string $raw): mixed
    {
        if (!str_contains($raw, "\n")) {
            return $raw;
        }
        $parts = [];
        foreach (explode("\n", $raw) as $index => $line) {
            if ($index > 0) {
                $parts[] = Doc::literalline();
            }
            $parts[] = $line;
        }
        return $parts;
    }

    /**
     * @param array<int, mixed> $elements
     */
    private function arrayLike(Node $node, array $elements): mixed
    {
        if ($elements === []) {
            $dangling = $this->comments->danglingFor($node);
            if ($dangling === []) {
                return '[]';
            }
            $parts = ['['];
            foreach ($dangling as $attached) {
                $parts[] = Doc::indent([Doc::hardline(), $this->commentText($attached)]);
            }
            $parts[] = Doc::hardline();
            $parts[] = ']';
            return $parts;
        }

        $printed = [];
        $count = count($elements);
        foreach ($elements as $index => $element) {
            if ($element === null) {
                $printed[] = '';
                continue;
            }
            $printed[] = $this->withComments($element, $this->print($element, $node));
        }
        $lastIsRest = $elements[$count - 1] instanceof RestElement;
        $lastIsHole = $elements[$count - 1] === null;
        $trailing = $lastIsRest ? '' : $this->trailingCommaDoc(true);
        if ($lastIsHole) {
            $trailing = ',';
        }
        $shouldBreak = $this->wasWrittenMultiline($node, $elements);
        return Doc::group(
            [
                '[',
                Doc::indent([Doc::softline(), Doc::join([',', Doc::line()], $printed)]),
                $trailing,
                Doc::softline(),
                ']',
            ],
            $shouldBreak,
        );
    }

    /**
     * @param array<int, mixed> $properties
     */
    private function objectLike(Node $node, array $properties): mixed
    {
        if ($properties === []) {
            $dangling = $this->comments->danglingFor($node);
            if ($dangling === []) {
                return '{}';
            }
            $parts = ['{'];
            foreach ($dangling as $attached) {
                $parts[] = Doc::indent([Doc::hardline(), $this->commentText($attached)]);
            }
            $parts[] = Doc::hardline();
            $parts[] = '}';
            return $parts;
        }

        $printed = [];
        foreach ($properties as $property) {
            $printed[] = $this->withComments($property, $this->print($property, $node));
        }
        $lastIsRest = $properties[count($properties) - 1] instanceof RestElement;
        $space = $this->options->bracketSpacing ? Doc::line() : Doc::softline();
        $shouldBreak = $this->wasWrittenMultiline($node, $properties);
        return Doc::group(
            [
                '{',
                Doc::indent([$space, Doc::join([',', Doc::line()], $printed)]),
                $lastIsRest ? '' : $this->trailingCommaDoc(true),
                $space,
                '}',
            ],
            $shouldBreak,
        );
    }

    /**
     * Prettier preserves the author's choice to write an object across
     * multiple lines: an opening brace followed by a newline keeps the
     * object broken even when it would fit.
     *
     * @param array<int, mixed> $members
     */
    private function wasWrittenMultiline(Node $node, array $members): bool
    {
        if (!$node instanceof ObjectExpression && !$node instanceof ObjectPattern) {
            return false;
        }
        foreach ($members as $member) {
            if ($member instanceof Node) {
                return $member->location->line > $node->location->line;
            }
        }
        return false;
    }

    private function objectProperty(Property $node): mixed
    {
        if ($node->method || $node->kind === 'get' || $node->kind === 'set') {
            $value = $node->value;
            if ($value instanceof FunctionExpression) {
                return $this->methodLike($node->key, $node->computed, $value, $node->kind === 'init' ? '' : $node->kind);
            }
        }
        if ($node->shorthand) {
            return $this->print($node->value, $node);
        }
        return Doc::group([
            $this->propertyKey($node->key, $node->computed),
            ': ',
            $this->print($node->value, $node),
        ]);
    }

    private function assignmentProperty(AssignmentProperty $node): mixed
    {
        if ($node->shorthand) {
            return $this->print($node->value, $node);
        }
        return [$this->propertyKey($node->key, $node->computed), ': ', $this->print($node->value, $node)];
    }

    private function arrowFunction(ArrowFunction $node): mixed
    {
        $chainParts = [];
        $current = $node;
        while (true) {
            $chainParts[] = [$current->async ? 'async ' : '', $this->arrowParams($current), ' =>'];
            $body = $current->body;
            if ($current->expression && $body instanceof ArrowFunction) {
                $chainParts[] = ' ';
                $current = $body;
                continue;
            }
            break;
        }

        $body = $current->body;
        $head = $chainParts;

        if (!$current->expression && $body instanceof BlockStatement) {
            return [$head, ' ', $this->block($body)];
        }

        $bodyNeedsParens = $this->arrowBodyStartsWithObject($body);
        $bodyDoc = $this->print($body, $current);
        if ($bodyNeedsParens) {
            return Doc::group([$head, ' (', $bodyDoc, ')']);
        }

        if ($this->arrowBodyHugs($body)) {
            return Doc::group([$head, ' ', $bodyDoc]);
        }

        return Doc::group([$head, Doc::group(Doc::indent([Doc::line(), $bodyDoc]))]);
    }

    private function arrowBodyStartsWithObject(Node $body): bool
    {
        while (true) {
            if ($body instanceof ObjectExpression) {
                return true;
            }
            if ($body instanceof SequenceExpression) {
                $body = $body->expressions[0];
                continue;
            }
            if (
                $body instanceof AssignmentExpression
                || $body instanceof BinaryExpression
                || $body instanceof LogicalExpression
            ) {
                $body = $body->left;
                continue;
            }
            if ($body instanceof ConditionalExpression) {
                $body = $body->test;
                continue;
            }
            if ($body instanceof CallExpression || $body instanceof NewExpression) {
                $body = $body->callee;
                continue;
            }
            if ($body instanceof MemberExpression) {
                $body = $body->object;
                continue;
            }
            return false;
        }
    }

    private function arrowBodyHugs(Node $body): bool
    {
        return $body instanceof ArrayExpression
            || $body instanceof ObjectExpression
            || $body instanceof TemplateLiteral
            || $body instanceof TaggedTemplate
            || $body instanceof ArrowFunction;
    }

    private function arrowParams(ArrowFunction $node): mixed
    {
        if (
            $this->options->arrowParens === 'avoid'
            && count($node->params) === 1
            && $node->params[0] instanceof Identifier
        ) {
            return $node->params[0]->name;
        }
        return $this->functionParams($node->params, $node);
    }

    private function yieldExpression(YieldExpression $node): mixed
    {
        $keyword = $node->delegate ? 'yield*' : 'yield';
        if ($node->argument === null) {
            return $keyword;
        }
        return [$keyword . ' ', $this->print($node->argument, $node)];
    }

    private function unaryExpression(UnaryExpression $node): mixed
    {
        $operator = $node->operator;
        $space = strlen($operator) > 1 ? ' ' : '';
        if ($space === '') {
            $argument = $node->argument;
            $sameSign = ($operator === '+' || $operator === '-')
                && (
                    ($argument instanceof UnaryExpression && $argument->operator === $operator)
                    || ($argument instanceof UpdateExpression && $argument->prefix
                        && $argument->operator[0] === $operator)
                );
            if ($sameSign) {
                return [$operator, '(', $this->print($argument, $node), ')'];
            }
        }
        return [$operator . $space, $this->print($node->argument, $node)];
    }

    private function binaryish(BinaryExpression|LogicalExpression $node, ?Node $parent): mixed
    {
        $parts = $this->binaryChain($node);

        $bareChain = $node instanceof LogicalExpression
            && ($parent instanceof IfStatement
                || $parent instanceof WhileStatement
                || $parent instanceof DoWhileStatement
                || $parent instanceof SwitchStatement
                || $parent instanceof ForStatement);

        if ($bareChain) {
            return $parts;
        }

        $parenPosition = $parent instanceof IfStatement
            || $parent instanceof WhileStatement
            || $parent instanceof DoWhileStatement
            || $parent instanceof SwitchStatement
            || $parent instanceof ForStatement;
        if ($parenPosition) {
            return Doc::group($parts);
        }

        $ownGroupFlat = $parent === null
            || $parent instanceof ExpressionStatement
            || $parent instanceof VariableDeclarator
            || $parent instanceof AssignmentExpression;
        if ($ownGroupFlat) {
            return Doc::group($parts);
        }

        $head = array_shift($parts);
        return Doc::group([$head, Doc::indent($parts)]);
    }

    /**
     * Flattens same-precedence binary chains into [operand, line-op-operand...]
     * parts, prettier's printBinaryishExpressions shape.
     *
     * @return array<int, mixed>
     */
    private function binaryChain(BinaryExpression|LogicalExpression $node): array
    {
        $operator = $node->operator;
        $parts = [];

        $left = $node->left;
        $samePrecedenceLeft = ($left instanceof BinaryExpression || $left instanceof LogicalExpression)
            && self::PRECEDENCE[$left->operator] === self::PRECEDENCE[$operator]
            && !$this->needsParens($left, $node);
        if ($samePrecedenceLeft) {
            /** @var BinaryExpression|LogicalExpression $left */
            $parts = $this->binaryChain($left);
        } else {
            $parts[] = $this->print($left, $node);
        }

        $parts[] = [' ' . $operator, Doc::line(), $this->print($node->right, $node)];
        return $parts;
    }

    private function conditional(ConditionalExpression $node): mixed
    {
        return Doc::group([
            $this->print($node->test, $node),
            Doc::indent([
                Doc::line(),
                '? ',
                Doc::indent($this->print($node->consequent, $node)),
                Doc::line(),
                ': ',
                Doc::indent($this->print($node->alternate, $node)),
            ]),
        ]);
    }

    private function memberExpression(MemberExpression $node): mixed
    {
        $chainRoot = $this->isCallChainLink($node->object);
        $objectDoc = $this->print($node->object, $node);
        return [$objectDoc, $this->memberSuffix($node)];
    }

    private function isCallChainLink(Node $node): bool
    {
        return $node instanceof CallExpression || $node instanceof MemberExpression;
    }

    private function memberSuffix(MemberExpression $node): mixed
    {
        if ($node->computed) {
            return [
                $node->optional ? '?.' : '',
                '[',
                $this->print($node->property, $node),
                ']',
            ];
        }
        $property = $node->property;
        $name = $property instanceof Identifier
            ? $property->name
            : ($property instanceof PrivateIdentifier
                ? (str_starts_with($property->name, '#') ? $property->name : '#' . $property->name)
                : null);
        if ($name === null) {
            return [$node->optional ? '?.' : '.', $this->print($property, $node)];
        }
        return ($node->optional ? '?.' : '.') . $name;
    }

    private function callExpression(CallExpression $node): mixed
    {
        if ($this->isMemberChainCandidate($node)) {
            return $this->memberChain($node);
        }
        return [
            $this->print($node->callee, $node),
            $node->optional ? '?.' : '',
            $this->callArguments($node->arguments, $node),
        ];
    }

    private function newExpression(NewExpression $node): mixed
    {
        return [
            'new ',
            $this->print($node->callee, $node),
            $this->callArguments($node->arguments, $node),
        ];
    }

    private function importExpression(ImportExpression $node): mixed
    {
        $arguments = [$node->source];
        if ($node->options !== null) {
            $arguments[] = $node->options;
        }
        $keyword = 'import';
        if ($node->phase === 'defer') {
            $keyword = 'import.defer';
        } elseif ($node->phase === 'source') {
            $keyword = 'import.source';
        }
        return [$keyword, $this->callArguments($arguments, $node)];
    }

    /**
     * @param array<int, Node> $arguments
     */
    private function callArguments(array $arguments, Node $parent): mixed
    {
        if ($arguments === []) {
            return '()';
        }

        $printed = array_map(
            fn(Node $argument): mixed => $this->withComments($argument, $this->print($argument, $parent)),
            $arguments,
        );
        $count = count($arguments);
        $last = $arguments[$count - 1];
        $first = $arguments[0];

        $expanded = [
            '(',
            Doc::indent([Doc::softline(), Doc::join([',', Doc::line()], $printed)]),
            $last instanceof SpreadElement || $last instanceof RestElement ? '' : $this->trailingCommaDoc(false),
            Doc::softline(),
            ')',
        ];

        $huggableLast = $this->isHuggable($last) && ($count === 1 || $this->allSimple($arguments, $count - 1));
        $huggableFirst = !$huggableLast
            && $count > 1
            && ($first instanceof FunctionExpression || $first instanceof ArrowFunction)
            && $this->allSimple(array_slice($arguments, 1), $count - 1)
            && !($last instanceof FunctionExpression || $last instanceof ArrowFunction);

        if ($huggableLast) {
            $flatButLast = array_slice($printed, 0, -1);
            $hugged = [
                '(',
                Doc::join(', ', $flatButLast),
                $flatButLast === [] ? '' : ', ',
                Doc::group($printed[$count - 1], true),
            ];
            if ($this->lastArgEndsWithBrace($last)) {
                $hugged[] = ')';
            } else {
                $hugged[] = ',';
                $hugged[] = Doc::hardline();
                $hugged[] = ')';
            }
            $allFlat = ['(', Doc::join(', ', $printed), ')'];
            return Doc::conditionalGroup([$allFlat, $hugged, Doc::group($expanded, true)]);
        }

        if ($huggableFirst) {
            $hugged = [
                '(',
                Doc::group($printed[0], true),
                ', ',
                Doc::join(', ', array_slice($printed, 1)),
                ')',
            ];
            $allFlat = ['(', Doc::join(', ', $printed), ')'];
            return Doc::conditionalGroup([$allFlat, $hugged, Doc::group($expanded, true)]);
        }

        return Doc::group($expanded);
    }

    private function lastArgEndsWithBrace(Node $node): bool
    {
        if ($node instanceof SpreadElement) {
            return $this->lastArgEndsWithBrace($node->argument);
        }
        if ($node instanceof ArrowFunction) {
            return !$node->expression || $node->body instanceof BlockStatement
                || $this->arrowBodyHugs($this->arrowChainTailBody($node));
        }
        return true;
    }

    private function arrowChainTailBody(ArrowFunction $node): Node
    {
        $current = $node;
        while ($current->expression && $current->body instanceof ArrowFunction) {
            $current = $current->body;
        }
        return $current->body;
    }

    private function isHuggable(Node $node): bool
    {
        if ($node instanceof SpreadElement) {
            return $this->isHuggable($node->argument);
        }
        return $node instanceof ObjectExpression
            || $node instanceof ArrayExpression
            || $node instanceof FunctionExpression
            || $node instanceof ArrowFunction
            || $node instanceof TemplateLiteral
            || $node instanceof TaggedTemplate
            || $node instanceof ClassExpression;
    }

    /**
     * @param array<int, Node> $arguments
     */
    private function allSimple(array $arguments, int $upto): bool
    {
        foreach (array_slice($arguments, 0, $upto) as $argument) {
            if (
                $argument instanceof FunctionExpression
                || $argument instanceof ArrowFunction
                || $argument instanceof ObjectExpression
                || $argument instanceof ArrayExpression
                || $argument instanceof ConditionalExpression
            ) {
                return false;
            }
        }
        return true;
    }

    private function isMemberChainCandidate(CallExpression $node): bool
    {
        $callee = $node->callee;
        if (!$callee instanceof MemberExpression) {
            return false;
        }
        $calls = 0;
        $current = $node;
        while (true) {
            if ($current instanceof CallExpression) {
                $calls++;
                $current = $current->callee;
                continue;
            }
            if ($current instanceof MemberExpression) {
                $current = $current->object;
                continue;
            }
            break;
        }
        return $calls >= 2;
    }

    /**
     * Prettier's member chain layout: decompose into groups anchored at each
     * dot, keep the head merged when it is short, and either lay the chain
     * flat or break before every dot.
     */
    private function memberChain(CallExpression $node): mixed
    {
        $units = [];
        $current = $node;
        while (true) {
            if ($current instanceof CallExpression) {
                $units[] = ['call', $current];
                $current = $current->callee;
                continue;
            }
            if ($current instanceof MemberExpression) {
                $units[] = ['member', $current];
                $current = $current->object;
                continue;
            }
            break;
        }
        $units = array_reverse($units);
        $head = $current;

        $groups = [];
        $currentGroup = [$this->print($head, null)];
        $callCount = 0;
        $index = 0;
        $unitCount = count($units);

        while ($index < $unitCount) {
            [$kind, $unit] = $units[$index];
            if ($kind === 'call' || ($unit instanceof MemberExpression && $unit->computed)) {
                if ($kind === 'call') {
                    /** @var CallExpression $unit */
                    $currentGroup[] = $unit->optional ? '?.' : '';
                    $currentGroup[] = $this->callArguments($unit->arguments, $unit);
                    $callCount++;
                } else {
                    $currentGroup[] = $this->memberSuffix($unit);
                }
                $index++;
                continue;
            }
            break;
        }

        if ($callCount === 0) {
            while ($index + 1 < $unitCount && $units[$index][0] === 'member' && $units[$index + 1][0] === 'member') {
                $currentGroup[] = $this->memberSuffix($units[$index][1]);
                $index++;
            }
        }

        for (; $index < $unitCount; $index++) {
            [$kind, $unit] = $units[$index];
            if ($kind === 'call') {
                /** @var CallExpression $unit */
                $currentGroup[] = $unit->optional ? '?.' : '';
                $currentGroup[] = $this->callArguments($unit->arguments, $unit);
                $callCount++;
                continue;
            }
            /** @var MemberExpression $unit */
            if ($unit->computed) {
                $currentGroup[] = $this->memberSuffix($unit);
                continue;
            }
            $groups[] = $currentGroup;
            $currentGroup = [$this->memberSuffix($unit)];
        }
        $groups[] = $currentGroup;

        $mergeHead = $this->shouldMergeChainHead($head) && count($groups) > 1;
        if ($mergeHead) {
            $first = array_shift($groups);
            $second = array_shift($groups);
            array_unshift($groups, array_merge($first, $second));
        }

        $flat = [];
        foreach ($groups as $group) {
            $flat[] = $group;
        }

        if (count($groups) <= 1) {
            return $flat;
        }

        $headGroup = $groups[0];
        $restGroups = array_slice($groups, 1);
        $expanded = [
            $headGroup,
            Doc::indent(array_map(
                static fn(array $group): array => [Doc::hardline(), $group],
                $restGroups,
            )),
        ];

        if ($callCount > 2) {
            return $expanded;
        }

        return Doc::conditionalGroup([$flat, $expanded]);
    }

    private function shouldMergeChainHead(Node $head): bool
    {
        if ($head instanceof ThisExpression) {
            return true;
        }
        if ($head instanceof Identifier) {
            return strlen($head->name) <= $this->options->tabWidth
                || preg_match('/^[A-Z]|^[$_]+$/', $head->name) === 1;
        }
        return false;
    }

    private function needsParens(Node $node, Node $parent): bool
    {
        if ($node instanceof SequenceExpression) {
            return !($parent instanceof ExpressionStatement || $parent instanceof ForStatement
                || $parent instanceof SequenceExpression);
        }

        if ($node instanceof AssignmentExpression) {
            return !($parent instanceof ExpressionStatement
                || $parent instanceof ForStatement
                || $parent instanceof SequenceExpression
                || $parent instanceof AssignmentExpression
                || ($parent instanceof ArrowFunction && $parent->body === $node));
        }

        if ($node instanceof ArrowFunction) {
            return $parent instanceof CallExpression && $parent->callee === $node
                || $parent instanceof NewExpression && $parent->callee === $node
                || $parent instanceof MemberExpression && $parent->object === $node
                || $parent instanceof TaggedTemplate
                || $parent instanceof UnaryExpression
                || $parent instanceof AwaitExpression
                || $parent instanceof BinaryExpression
                || $parent instanceof LogicalExpression
                || ($parent instanceof ConditionalExpression && $parent->test === $node);
        }

        if ($node instanceof ConditionalExpression) {
            return ($parent instanceof ConditionalExpression && $parent->test === $node)
                || $parent instanceof UnaryExpression
                || $parent instanceof AwaitExpression
                || ($parent instanceof CallExpression && $parent->callee === $node)
                || ($parent instanceof NewExpression && $parent->callee === $node)
                || ($parent instanceof MemberExpression && $parent->object === $node)
                || $parent instanceof BinaryExpression
                || $parent instanceof LogicalExpression
                || $parent instanceof SpreadElement
                || $parent instanceof TaggedTemplate;
        }

        if ($node instanceof YieldExpression || $node instanceof AwaitExpression) {
            return $parent instanceof UnaryExpression
                || $parent instanceof UpdateExpression
                || ($parent instanceof BinaryExpression && $parent->operator === '**' && $parent->left === $node)
                || ($parent instanceof CallExpression && $parent->callee === $node)
                || ($parent instanceof MemberExpression && $parent->object === $node)
                || ($parent instanceof NewExpression && $parent->callee === $node)
                || ($node instanceof YieldExpression && (
                    $parent instanceof BinaryExpression
                    || $parent instanceof LogicalExpression
                    || ($parent instanceof ConditionalExpression && $parent->test === $node)
                ));
        }

        if ($node instanceof BinaryExpression || $node instanceof LogicalExpression) {
            return $this->binaryNeedsParens($node, $parent);
        }

        if ($node instanceof UnaryExpression) {
            if ($parent instanceof BinaryExpression && $parent->operator === '**' && $parent->left === $node) {
                return true;
            }
            return ($parent instanceof CallExpression && $parent->callee === $node)
                || ($parent instanceof NewExpression && $parent->callee === $node)
                || ($parent instanceof MemberExpression && $parent->object === $node)
                || $parent instanceof TaggedTemplate;
        }

        if ($node instanceof UpdateExpression) {
            return ($parent instanceof CallExpression && $parent->callee === $node)
                || ($parent instanceof NewExpression && $parent->callee === $node)
                || ($parent instanceof MemberExpression && $parent->object === $node);
        }

        if ($node instanceof FunctionExpression || $node instanceof ClassExpression) {
            return ($parent instanceof CallExpression && $parent->callee === $node)
                || ($parent instanceof NewExpression && $parent->callee === $node)
                || ($parent instanceof MemberExpression && $parent->object === $node)
                || $parent instanceof TaggedTemplate;
        }

        if ($node instanceof CallExpression) {
            return $parent instanceof NewExpression && $parent->callee === $node;
        }

        if ($node instanceof ObjectExpression) {
            return $parent instanceof ArrowFunction && $parent->body === $node;
        }

        if ($node instanceof NewExpression && !$node->hasArguments) {
            return ($parent instanceof CallExpression && $parent->callee === $node)
                || ($parent instanceof MemberExpression && $parent->object === $node);
        }

        if ($node instanceof Literal && is_int($node->value) === false && is_float($node->value)) {
            if ($parent instanceof MemberExpression && $parent->object === $node && !$parent->computed) {
                return !str_contains($node->raw, '.') && !str_contains(strtolower($node->raw), 'e')
                    && !str_starts_with($node->raw, '0x');
            }
        }

        return false;
    }

    private function binaryNeedsParens(BinaryExpression|LogicalExpression $node, Node $parent): bool
    {
        if (
            $parent instanceof UnaryExpression
            || $parent instanceof AwaitExpression
            || $parent instanceof UpdateExpression
            || $parent instanceof SpreadElement
            || $parent instanceof TaggedTemplate
        ) {
            return true;
        }
        if (
            ($parent instanceof CallExpression && $parent->callee === $node)
            || ($parent instanceof NewExpression && $parent->callee === $node)
            || ($parent instanceof MemberExpression && $parent->object === $node)
        ) {
            return true;
        }
        if (!$parent instanceof BinaryExpression && !$parent instanceof LogicalExpression) {
            return false;
        }

        $childOp = $node->operator;
        $parentOp = $parent->operator;

        $mixedNullish = ($parentOp === '??' && ($childOp === '||' || $childOp === '&&'))
            || ($childOp === '??' && ($parentOp === '||' || $parentOp === '&&'));
        if ($mixedNullish) {
            return true;
        }

        if ($parentOp === '||' && $childOp === '&&') {
            return true;
        }

        $childPrecedence = self::PRECEDENCE[$childOp];
        $parentPrecedence = self::PRECEDENCE[$parentOp];

        if ($childPrecedence < $parentPrecedence) {
            return true;
        }
        if ($childPrecedence > $parentPrecedence) {
            return false;
        }

        if ($parentOp === '**') {
            return true;
        }

        if ($parent->right === $node) {
            if ($childOp === $parentOp && ($childOp === '&&' || $childOp === '||' || $childOp === '??')) {
                return false;
            }
            return true;
        }

        if (
            $childOp !== $parentOp
            && ($parentOp === '%' || $childOp === '%' || $parentOp === '/' || $childOp === '/')
            && $childPrecedence === 13
        ) {
            return true;
        }

        return false;
    }
}
