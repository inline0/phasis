<?php

declare(strict_types=1);

namespace PhpJs\Parser;

use PhpJs\Ast\Declaration\ClassDeclaration;
use PhpJs\Ast\Declaration\FunctionDeclaration;
use PhpJs\Ast\Declaration\VariableDeclaration;
use PhpJs\Ast\Declaration\VariableDeclarator;
use PhpJs\Ast\Expression\ArrayExpression;
use PhpJs\Ast\Expression\ArrowFunction;
use PhpJs\Ast\Expression\AssignmentExpression;
use PhpJs\Ast\Expression\AwaitExpression;
use PhpJs\Ast\Expression\BinaryExpression;
use PhpJs\Ast\Expression\CallExpression;
use PhpJs\Ast\Expression\ClassExpression;
use PhpJs\Ast\Expression\ClassMethod;
use PhpJs\Ast\Expression\ConditionalExpression;
use PhpJs\Ast\Expression\FunctionExpression;
use PhpJs\Ast\Expression\Identifier;
use PhpJs\Ast\Expression\Literal;
use PhpJs\Ast\Expression\LogicalExpression;
use PhpJs\Ast\Expression\MemberExpression;
use PhpJs\Ast\Expression\NewExpression;
use PhpJs\Ast\Expression\ObjectExpression;
use PhpJs\Ast\Expression\Property;
use PhpJs\Ast\Expression\SequenceExpression;
use PhpJs\Ast\Expression\SpreadElement;
use PhpJs\Ast\Expression\TemplateLiteral;
use PhpJs\Ast\Expression\TemplateElement;
use PhpJs\Ast\Expression\TaggedTemplate;
use PhpJs\Ast\Expression\ThisExpression;
use PhpJs\Ast\Expression\UnaryExpression;
use PhpJs\Ast\Expression\UpdateExpression;
use PhpJs\Ast\Expression\YieldExpression;
use PhpJs\Ast\Node;
use PhpJs\Ast\Pattern\ArrayPattern;
use PhpJs\Ast\Pattern\AssignmentPattern;
use PhpJs\Ast\Pattern\AssignmentProperty;
use PhpJs\Ast\Pattern\ObjectPattern;
use PhpJs\Ast\Pattern\RestElement;
use PhpJs\Ast\Program;
use PhpJs\Ast\Statement\BlockStatement;
use PhpJs\Ast\Statement\BreakStatement;
use PhpJs\Ast\Statement\CatchClause;
use PhpJs\Ast\Statement\ContinueStatement;
use PhpJs\Ast\Statement\DebuggerStatement;
use PhpJs\Ast\Statement\DoWhileStatement;
use PhpJs\Ast\Statement\EmptyStatement;
use PhpJs\Ast\Statement\ExpressionStatement;
use PhpJs\Ast\Statement\ForInStatement;
use PhpJs\Ast\Statement\ForOfStatement;
use PhpJs\Ast\Statement\ForStatement;
use PhpJs\Ast\Statement\IfStatement;
use PhpJs\Ast\Statement\LabeledStatement;
use PhpJs\Ast\Statement\ReturnStatement;
use PhpJs\Ast\Statement\SwitchCase;
use PhpJs\Ast\Statement\SwitchStatement;
use PhpJs\Ast\Statement\ThrowStatement;
use PhpJs\Ast\Statement\TryStatement;
use PhpJs\Ast\Statement\WhileStatement;
use PhpJs\Ast\Statement\WithStatement;
use PhpJs\Lexer\Lexer;
use PhpJs\Lexer\SourceLocation;
use PhpJs\Lexer\Token;
use PhpJs\Lexer\TokenType;

class Parser
{
    /** @var Token[] */
    private array $tokens;
    private int $pos = 0;
    private bool $noIn = false;
    public function __construct(string $source)
    {
        $lexer = new Lexer($source);
        $this->tokens = $lexer->tokenize();
    }

    public function parse(): Program
    {
        $location = $this->current()->location;
        $body = [];

        while (!$this->isAtEnd()) {
            $body[] = $this->parseStatementOrDeclaration();
        }

        return new Program($location, $body);
    }

    // -------------------------------------------------------------------------
    // Statements and declarations
    // -------------------------------------------------------------------------

    private function parseStatementOrDeclaration(): Node
    {
        $token = $this->current();

        // In sloppy mode, `let` is only a keyword when followed by an identifier,
        // `[`, or `{` (binding pattern starts). Otherwise treat it as an identifier.
        if ($token->type === TokenType::Let) {
            $next = $this->peek();
            $isDeclaration = $next->type === TokenType::Identifier
                || $next->type === TokenType::LeftBracket
                || $next->type === TokenType::LeftBrace
                || $next->type === TokenType::Yield
                || $next->type === TokenType::Await
                || $next->type === TokenType::Let;
            if ($isDeclaration) {
                return $this->parseVariableDeclaration();
            }
            return $this->parseStatement();
        }

        return match ($token->type) {
            TokenType::Var, TokenType::Const_ => $this->parseVariableDeclaration(),
            TokenType::Function_ => $this->parseFunctionDeclaration(),
            TokenType::Class_ => $this->parseClassDeclaration(),
            TokenType::Async => $this->maybeParseAsyncFunction(),
            default => $this->parseStatement(),
        };
    }

    private function parseStatement(): Node
    {
        $token = $this->current();

        return match ($token->type) {
            TokenType::LeftBrace => $this->parseBlockStatement(),
            TokenType::If => $this->parseIfStatement(),
            TokenType::For => $this->parseForStatement(),
            TokenType::While => $this->parseWhileStatement(),
            TokenType::Do => $this->parseDoWhileStatement(),
            TokenType::Switch => $this->parseSwitchStatement(),
            TokenType::Return => $this->parseReturnStatement(),
            TokenType::Throw => $this->parseThrowStatement(),
            TokenType::Try => $this->parseTryStatement(),
            TokenType::Break => $this->parseBreakStatement(),
            TokenType::Continue => $this->parseContinueStatement(),
            TokenType::Semicolon => $this->parseEmptyStatement(),
            TokenType::Debugger => $this->parseDebuggerStatement(),
            TokenType::With => $this->parseWithStatement(),
            default => $this->parseExpressionOrLabeledStatement(),
        };
    }

    private function parseBlockStatement(): BlockStatement
    {
        $location = $this->expect(TokenType::LeftBrace)->location;
        $body = [];

        while (!$this->check(TokenType::RightBrace) && !$this->isAtEnd()) {
            $body[] = $this->parseStatementOrDeclaration();
        }

        $this->expect(TokenType::RightBrace);
        return new BlockStatement($location, $body);
    }

    private function parseVariableDeclaration(): VariableDeclaration
    {
        $token = $this->advance();
        $kind = $token->value;
        $location = $token->location;
        $declarations = [];

        do {
            $declarations[] = $this->parseVariableDeclarator();
        } while ($this->eat(TokenType::Comma));

        $this->consumeSemicolon();
        return new VariableDeclaration($location, $kind, $declarations);
    }

    private function parseVariableDeclarator(): VariableDeclarator
    {
        $id = $this->parseBindingPattern();
        $init = null;

        if ($this->eat(TokenType::Equal)) {
            $init = $this->parseAssignmentExpression();
        }

        return new VariableDeclarator($id->location, $id, $init);
    }

    private function parseBindingPattern(): Node
    {
        $token = $this->current();

        return match ($token->type) {
            TokenType::LeftBracket => $this->parseArrayPattern(),
            TokenType::LeftBrace => $this->parseObjectPattern(),
            default => $this->parseIdentifier(),
        };
    }

    private function parseArrayPattern(): ArrayPattern
    {
        $location = $this->expect(TokenType::LeftBracket)->location;
        $elements = [];

        while (!$this->check(TokenType::RightBracket) && !$this->isAtEnd()) {
            if ($this->check(TokenType::Comma)) {
                $elements[] = null; // hole
                $this->advance();
                continue;
            }

            if ($this->check(TokenType::Ellipsis)) {
                $elements[] = $this->parseRestElement();
                break;
            }

            $element = $this->parseBindingPattern();
            if ($this->eat(TokenType::Equal)) {
                $default = $this->parseAssignmentExpression();
                $element = new AssignmentPattern($element->location, $element, $default);
            }
            $elements[] = $element;

            if (!$this->check(TokenType::RightBracket)) {
                $this->expect(TokenType::Comma);
            }
        }

        $this->expect(TokenType::RightBracket);
        return new ArrayPattern($location, $elements);
    }

    private function parseObjectPattern(): ObjectPattern
    {
        $location = $this->expect(TokenType::LeftBrace)->location;
        $properties = [];

        while (!$this->check(TokenType::RightBrace) && !$this->isAtEnd()) {
            if ($this->check(TokenType::Ellipsis)) {
                $properties[] = $this->parseRestElement();
                break;
            }

            $properties[] = $this->parseAssignmentProperty();

            if (!$this->check(TokenType::RightBrace)) {
                $this->expect(TokenType::Comma);
            }
        }

        $this->expect(TokenType::RightBrace);
        return new ObjectPattern($location, $properties);
    }

    private function parseAssignmentProperty(): AssignmentProperty
    {
        $computed = false;
        $shorthand = false;
        $location = $this->current()->location;

        if ($this->check(TokenType::LeftBracket)) {
            $computed = true;
            $this->advance();
            $key = $this->parseAssignmentExpression();
            $this->expect(TokenType::RightBracket);
            $this->expect(TokenType::Colon);
            $value = $this->parseBindingPattern();
            if ($this->eat(TokenType::Equal)) {
                $default = $this->parseAssignmentExpression();
                $value = new AssignmentPattern($value->location, $value, $default);
            }
            return new AssignmentProperty($location, $key, $value, $computed, $shorthand);
        }

        $key = $this->parsePropertyName();

        if ($this->eat(TokenType::Colon)) {
            $value = $this->parseBindingPattern();
            if ($this->eat(TokenType::Equal)) {
                $default = $this->parseAssignmentExpression();
                $value = new AssignmentPattern($value->location, $value, $default);
            }
        } else {
            // Shorthand: { x } or { x = default }
            $shorthand = true;
            $value = $key;
            if ($this->eat(TokenType::Equal)) {
                $default = $this->parseAssignmentExpression();
                $value = new AssignmentPattern($value->location, $value, $default);
            }
        }

        return new AssignmentProperty($location, $key, $value, $computed, $shorthand);
    }

    private function parseRestElement(): RestElement
    {
        $location = $this->expect(TokenType::Ellipsis)->location;
        $argument = $this->parseBindingPattern();
        return new RestElement($location, $argument);
    }

    private function parseFunctionDeclaration(): FunctionDeclaration
    {
        $location = $this->expect(TokenType::Function_)->location;
        $generator = $this->eat(TokenType::Star);
        $id = $this->parseIdentifier();
        $params = $this->parseFormalParameters();
        $body = $this->parseBlockStatement();

        return new FunctionDeclaration($location, $id, $params, $body, $generator, false);
    }

    private function parseClassDeclaration(): ClassDeclaration
    {
        $location = $this->expect(TokenType::Class_)->location;
        $id = null;
        if ($this->check(TokenType::Identifier)) {
            $id = $this->parseIdentifier();
        }
        $superClass = null;
        if ($this->eat(TokenType::Extends)) {
            $superClass = $this->parseLeftHandSideExpression();
        }
        $body = $this->parseClassBody();
        return new ClassDeclaration($location, $id, $superClass, $body);
    }

    /** @return ClassMethod[] */
    private function parseClassBody(): array
    {
        $this->expect(TokenType::LeftBrace);
        $methods = [];

        while (!$this->check(TokenType::RightBrace) && !$this->isAtEnd()) {
            if ($this->eat(TokenType::Semicolon)) {
                continue;
            }
            $methods[] = $this->parseClassMethod();
        }

        $this->expect(TokenType::RightBrace);
        return $methods;
    }

    private function parseClassMethod(): ClassMethod
    {
        $location = $this->current()->location;
        $isStatic = false;
        $kind = 'method';
        $computed = false;
        $isAsync = false;
        $isGenerator = false;

        if ($this->check(TokenType::Static_)) {
            $next = $this->peek();
            if (
                $next->type !== TokenType::LeftParen
                && $next->type !== TokenType::Equal
                && $next->type !== TokenType::Semicolon
            ) {
                $this->advance();
                $isStatic = true;
            }
        }

        if ($this->check(TokenType::Async)) {
            $next = $this->peek();
            if (!$next->lineTerminatorBefore && $next->type !== TokenType::LeftParen) {
                $this->advance();
                $isAsync = true;
            }
        }

        if ($this->eat(TokenType::Star)) {
            $isGenerator = true;
        }

        if ($this->checkContextual('get') && !$this->peekIs(TokenType::LeftParen)) {
            $this->advance();
            $kind = 'get';
        } elseif ($this->checkContextual('set') && !$this->peekIs(TokenType::LeftParen)) {
            $this->advance();
            $kind = 'set';
        }

        if ($this->check(TokenType::LeftBracket)) {
            $computed = true;
            $this->advance();
            $key = $this->parseAssignmentExpression();
            $this->expect(TokenType::RightBracket);
        } else {
            $key = $this->parsePropertyName();
        }

        if ($key instanceof Identifier && $key->name === 'constructor' && !$isStatic) {
            $kind = 'constructor';
        }

        $params = $this->parseFormalParameters();
        $body = $this->parseBlockStatement();

        $value = new FunctionExpression($body->location, null, $params, $body, $isGenerator, $isAsync);
        return new ClassMethod($location, $key, $value, $kind, $isStatic, $computed);
    }

    private function maybeParseAsyncFunction(): Node
    {
        $next = $this->peek();
        if ($next->type === TokenType::Function_ && !$next->lineTerminatorBefore) {
            $location = $this->advance()->location; // consume 'async'
            $this->advance(); // consume 'function'
            $generator = $this->eat(TokenType::Star);
            $id = $this->parseIdentifier();
            $params = $this->parseFormalParameters();
            $body = $this->parseBlockStatement();
            return new FunctionDeclaration($location, $id, $params, $body, $generator, true);
        }

        return $this->parseExpressionStatement();
    }

    /** @return Node[] */
    private function parseFormalParameters(): array
    {
        $this->expect(TokenType::LeftParen);
        $params = [];

        while (!$this->check(TokenType::RightParen) && !$this->isAtEnd()) {
            if ($this->check(TokenType::Ellipsis)) {
                $params[] = $this->parseRestElement();
                break;
            }

            $param = $this->parseBindingPattern();
            if ($this->eat(TokenType::Equal)) {
                $default = $this->parseAssignmentExpression();
                $param = new AssignmentPattern($param->location, $param, $default);
            }
            $params[] = $param;

            if (!$this->check(TokenType::RightParen)) {
                $this->expect(TokenType::Comma);
            }
        }

        $this->expect(TokenType::RightParen);
        return $params;
    }

    private function parseIfStatement(): IfStatement
    {
        $location = $this->expect(TokenType::If)->location;
        $this->expect(TokenType::LeftParen);
        $test = $this->parseExpression();
        $this->expect(TokenType::RightParen);

        // AnnexB B.3.3: in sloppy mode, allow function declarations in if bodies.
        $consequent = $this->current()->type === TokenType::Function_
            ? $this->parseFunctionDeclaration()
            : $this->parseStatement();
        $alternate = null;

        if ($this->eat(TokenType::Else)) {
            $alternate = $this->current()->type === TokenType::Function_
                ? $this->parseFunctionDeclaration()
                : $this->parseStatement();
        }

        return new IfStatement($location, $test, $consequent, $alternate);
    }

    private function parseForStatement(): Node
    {
        $location = $this->expect(TokenType::For)->location;
        $this->expect(TokenType::LeftParen);

        // for (var/let/const ...
        if ($this->check(TokenType::Var) || $this->check(TokenType::Let) || $this->check(TokenType::Const_)) {
            $kindToken = $this->advance();
            $kind = $kindToken->value;
            $id = $this->parseBindingPattern();
            $init = null;

            // for (var x in ...)
            if ($this->check(TokenType::In)) {
                $this->advance();
                $right = $this->parseExpression();
                $this->expect(TokenType::RightParen);
                $body = $this->parseStatement();
                $left = new VariableDeclaration(
                    $kindToken->location,
                    $kind,
                    [new VariableDeclarator($id->location, $id, null)],
                );
                return new ForInStatement($location, $left, $right, $body);
            }

            // for (var x of ...)
            if ($this->check(TokenType::Of)) {
                $this->advance();
                $right = $this->parseAssignmentExpression();
                $this->expect(TokenType::RightParen);
                $body = $this->parseStatement();
                $left = new VariableDeclaration(
                    $kindToken->location,
                    $kind,
                    [new VariableDeclarator($id->location, $id, null)],
                );
                return new ForOfStatement($location, $left, $right, $body, false);
            }

            // Regular for with var declaration
            if ($this->eat(TokenType::Equal)) {
                $init = $this->parseAssignmentExpression();
            }
            $declarations = [new VariableDeclarator($id->location, $id, $init)];
            while ($this->eat(TokenType::Comma)) {
                $declarations[] = $this->parseVariableDeclarator();
            }
            $varDecl = new VariableDeclaration($kindToken->location, $kind, $declarations);
            $this->expect(TokenType::Semicolon);
            $test = $this->check(TokenType::Semicolon) ? null : $this->parseExpression();
            $this->expect(TokenType::Semicolon);
            $update = $this->check(TokenType::RightParen) ? null : $this->parseExpression();
            $this->expect(TokenType::RightParen);
            $body = $this->parseStatement();
            return new ForStatement($location, $varDecl, $test, $update, $body);
        }

        // for (;;)
        if ($this->check(TokenType::Semicolon)) {
            $this->advance();
            $test = $this->check(TokenType::Semicolon) ? null : $this->parseExpression();
            $this->expect(TokenType::Semicolon);
            $update = $this->check(TokenType::RightParen) ? null : $this->parseExpression();
            $this->expect(TokenType::RightParen);
            $body = $this->parseStatement();
            return new ForStatement($location, null, $test, $update, $body);
        }

        // for (expr in/of ...) — use NoIn so `in` is treated as for-in separator
        $this->noIn = true;
        $init = $this->parseExpression();
        $this->noIn = false;

        if ($this->check(TokenType::In)) {
            $this->advance();
            $right = $this->parseExpression();
            $this->expect(TokenType::RightParen);
            $body = $this->parseStatement();
            return new ForInStatement($location, $init, $right, $body);
        }

        if ($this->check(TokenType::Of)) {
            $this->advance();
            $right = $this->parseAssignmentExpression();
            $this->expect(TokenType::RightParen);
            $body = $this->parseStatement();
            return new ForOfStatement($location, $init, $right, $body, false);
        }

        // Regular for
        $this->expect(TokenType::Semicolon);
        $test = $this->check(TokenType::Semicolon) ? null : $this->parseExpression();
        $this->expect(TokenType::Semicolon);
        $update = $this->check(TokenType::RightParen) ? null : $this->parseExpression();
        $this->expect(TokenType::RightParen);
        $body = $this->parseStatement();
        return new ForStatement($location, $init, $test, $update, $body);
    }

    private function parseWhileStatement(): WhileStatement
    {
        $location = $this->expect(TokenType::While)->location;
        $this->expect(TokenType::LeftParen);
        $test = $this->parseExpression();
        $this->expect(TokenType::RightParen);
        $body = $this->parseStatement();
        return new WhileStatement($location, $test, $body);
    }

    private function parseDoWhileStatement(): DoWhileStatement
    {
        $location = $this->expect(TokenType::Do)->location;
        $body = $this->parseStatement();
        $this->expect(TokenType::While);
        $this->expect(TokenType::LeftParen);
        $test = $this->parseExpression();
        $this->expect(TokenType::RightParen);
        // ASI special case: do-while always allows semicolon insertion after )
        $this->eat(TokenType::Semicolon);
        return new DoWhileStatement($location, $body, $test);
    }

    private function parseSwitchStatement(): SwitchStatement
    {
        $location = $this->expect(TokenType::Switch)->location;
        $this->expect(TokenType::LeftParen);
        $discriminant = $this->parseExpression();
        $this->expect(TokenType::RightParen);
        $this->expect(TokenType::LeftBrace);

        $cases = [];
        while (!$this->check(TokenType::RightBrace) && !$this->isAtEnd()) {
            $cases[] = $this->parseSwitchCase();
        }

        $this->expect(TokenType::RightBrace);
        return new SwitchStatement($location, $discriminant, $cases);
    }

    private function parseSwitchCase(): SwitchCase
    {
        $location = $this->current()->location;
        $test = null;

        if ($this->eat(TokenType::Case)) {
            $test = $this->parseExpression();
        } else {
            $this->expect(TokenType::Default_);
        }

        $this->expect(TokenType::Colon);

        $consequent = [];
        while (
            !$this->check(TokenType::Case)
            && !$this->check(TokenType::Default_)
            && !$this->check(TokenType::RightBrace)
            && !$this->isAtEnd()
        ) {
            $consequent[] = $this->parseStatementOrDeclaration();
        }

        return new SwitchCase($location, $test, $consequent);
    }

    private function parseReturnStatement(): ReturnStatement
    {
        $location = $this->expect(TokenType::Return)->location;

        $isVoidReturn = $this->check(TokenType::Semicolon)
            || $this->check(TokenType::RightBrace)
            || $this->current()->lineTerminatorBefore
            || $this->isAtEnd();
        if ($isVoidReturn) {
            $this->consumeSemicolon();
            return new ReturnStatement($location, null);
        }

        $argument = $this->parseExpression();
        $this->consumeSemicolon();
        return new ReturnStatement($location, $argument);
    }

    private function parseThrowStatement(): ThrowStatement
    {
        $location = $this->expect(TokenType::Throw)->location;

        if ($this->current()->lineTerminatorBefore) {
            throw new ParseError('Illegal newline after throw', $this->current());
        }

        $argument = $this->parseExpression();
        $this->consumeSemicolon();
        return new ThrowStatement($location, $argument);
    }

    private function parseTryStatement(): TryStatement
    {
        $location = $this->expect(TokenType::Try)->location;
        $block = $this->parseBlockStatement();
        $handler = null;
        $finalizer = null;

        if ($this->check(TokenType::Catch)) {
            $handlerLocation = $this->advance()->location;
            $param = null;
            if ($this->eat(TokenType::LeftParen)) {
                $param = $this->parseBindingPattern();
                $this->expect(TokenType::RightParen);
            }
            $handlerBody = $this->parseBlockStatement();
            $handler = new CatchClause($handlerLocation, $param, $handlerBody);
        }

        if ($this->eat(TokenType::Finally)) {
            $finalizer = $this->parseBlockStatement();
        }

        if ($handler === null && $finalizer === null) {
            throw new ParseError('Missing catch or finally after try', $this->current());
        }

        return new TryStatement($location, $block, $handler, $finalizer);
    }

    private function parseBreakStatement(): BreakStatement
    {
        $location = $this->expect(TokenType::Break)->location;
        $label = null;

        if (!$this->current()->lineTerminatorBefore && $this->check(TokenType::Identifier)) {
            $label = $this->advance()->value;
        }

        $this->consumeSemicolon();
        return new BreakStatement($location, $label);
    }

    private function parseContinueStatement(): ContinueStatement
    {
        $location = $this->expect(TokenType::Continue)->location;
        $label = null;

        if (!$this->current()->lineTerminatorBefore && $this->check(TokenType::Identifier)) {
            $label = $this->advance()->value;
        }

        $this->consumeSemicolon();
        return new ContinueStatement($location, $label);
    }

    private function parseEmptyStatement(): EmptyStatement
    {
        $location = $this->expect(TokenType::Semicolon)->location;
        return new EmptyStatement($location);
    }

    private function parseDebuggerStatement(): DebuggerStatement
    {
        $location = $this->expect(TokenType::Debugger)->location;
        $this->consumeSemicolon();
        return new DebuggerStatement($location);
    }

    private function parseWithStatement(): WithStatement
    {
        $location = $this->expect(TokenType::With)->location;
        $this->expect(TokenType::LeftParen);
        $object = $this->parseExpression();
        $this->expect(TokenType::RightParen);
        $body = $this->parseStatement();
        return new WithStatement($location, $object, $body);
    }

    private function parseExpressionOrLabeledStatement(): Node
    {
        $expr = $this->parseExpression();

        // label: statement
        if ($expr instanceof Identifier && $this->eat(TokenType::Colon)) {
            $body = $this->parseStatement();
            return new LabeledStatement($expr->location, $expr->name, $body);
        }

        $this->consumeSemicolon();
        return new ExpressionStatement($expr->location, $expr);
    }

    private function parseExpressionStatement(): Node
    {
        $expr = $this->parseExpression();
        $this->consumeSemicolon();
        return new ExpressionStatement($expr->location, $expr);
    }

    // -------------------------------------------------------------------------
    // Expressions (Pratt parser)
    // -------------------------------------------------------------------------

    private function parseExpression(): Node
    {
        $expr = $this->parseAssignmentExpression();

        // Sequence expression (comma operator)
        if ($this->check(TokenType::Comma)) {
            $expressions = [$expr];
            while ($this->eat(TokenType::Comma)) {
                $expressions[] = $this->parseAssignmentExpression();
            }
            return new SequenceExpression($expr->location, $expressions);
        }

        return $expr;
    }

    private function parseAssignmentExpression(): Node
    {
        $left = $this->parseConditionalExpression();

        if ($this->current()->type->isAssignmentOperator()) {
            $op = $this->advance();
            $right = $this->parseAssignmentExpression();
            return new AssignmentExpression($left->location, $op->value, $left, $right);
        }

        // Arrow function: (params) => body OR ident => body
        if ($this->check(TokenType::Arrow)) {
            return $this->parseArrowFunction($left, false);
        }

        return $left;
    }

    private function parseConditionalExpression(): Node
    {
        $expr = $this->parseBinaryExpression(Precedence::NONE);

        if ($this->eat(TokenType::Question)) {
            $consequent = $this->parseAssignmentExpression();
            $this->expect(TokenType::Colon);
            $alternate = $this->parseAssignmentExpression();
            return new ConditionalExpression($expr->location, $expr, $consequent, $alternate);
        }

        return $expr;
    }

    private function parseBinaryExpression(int $minPrec): Node
    {
        $left = $this->parseUnaryExpression();

        while (true) {
            $token = $this->current();
            $prec = Precedence::infixFor($token->type);

            if ($prec <= $minPrec) {
                break;
            }

            // Skip 'in' in for-loop init (NoIn production)
            if ($token->type === TokenType::In && $this->noIn) {
                break;
            }

            $this->advance();
            $op = $token->value;

            // Right-associative: **
            $nextPrec = $token->type === TokenType::Exponent ? $prec - 1 : $prec;

            // Logical operators use LogicalExpression
            if (
                $token->type === TokenType::LogicalAnd
                || $token->type === TokenType::LogicalOr
                || $token->type === TokenType::NullishCoalescing
            ) {
                $right = $this->parseBinaryExpression($nextPrec);
                $left = new LogicalExpression($left->location, $op, $left, $right);
                continue;
            }

            // Member access, call, optional chaining handled separately
            if ($token->type === TokenType::Dot) {
                $property = $this->parseIdentifierOrKeyword();
                $left = new MemberExpression($left->location, $left, $property, false, false);
                continue;
            }

            if ($token->type === TokenType::OptionalChaining) {
                if ($this->check(TokenType::LeftParen)) {
                    $this->advance();
                    $args = $this->parseArguments();
                    $left = new CallExpression($left->location, $left, $args, true);
                } elseif ($this->check(TokenType::LeftBracket)) {
                    $this->advance();
                    $property = $this->parseExpression();
                    $this->expect(TokenType::RightBracket);
                    $left = new MemberExpression($left->location, $left, $property, true, true);
                } else {
                    $property = $this->parseIdentifierOrKeyword();
                    $left = new MemberExpression($left->location, $left, $property, false, true);
                }
                continue;
            }

            if ($token->type === TokenType::LeftBracket) {
                $property = $this->parseExpression();
                $this->expect(TokenType::RightBracket);
                $left = new MemberExpression($left->location, $left, $property, true, false);
                continue;
            }

            if ($token->type === TokenType::LeftParen) {
                $args = $this->parseArguments();
                $left = new CallExpression($left->location, $left, $args, false);
                continue;
            }

            $right = $this->parseBinaryExpression($nextPrec);
            $left = new BinaryExpression($left->location, $op, $left, $right);
        }

        // Postfix ++ and --
        if (
            ($this->check(TokenType::PlusPlus) || $this->check(TokenType::MinusMinus))
            && !$this->current()->lineTerminatorBefore
        ) {
            $op = $this->advance();
            $left = new UpdateExpression($left->location, $op->value, $left, false);
        }

        // Tagged template literal
        if ($this->check(TokenType::NoSubstitutionTemplate) || $this->check(TokenType::TemplateHead)) {
            $quasi = $this->parseTemplateLiteral();
            $left = new TaggedTemplate($left->location, $left, $quasi);
        }

        return $left;
    }

    private function parseUnaryExpression(): Node
    {
        $token = $this->current();

        // Prefix update: ++ --
        if ($token->type === TokenType::PlusPlus || $token->type === TokenType::MinusMinus) {
            $this->advance();
            $argument = $this->parseUnaryExpression();
            return new UpdateExpression($token->location, $token->value, $argument, true);
        }

        // Unary operators: ! ~ + - typeof void delete
        if (
            $token->type === TokenType::Bang
            || $token->type === TokenType::Tilde
            || $token->type === TokenType::Plus
            || $token->type === TokenType::Minus
            || $token->type === TokenType::Typeof
            || $token->type === TokenType::Void
            || $token->type === TokenType::Delete
        ) {
            $this->advance();
            $argument = $this->parseUnaryExpression();
            return new UnaryExpression($token->location, $token->value, $argument, true);
        }

        // await
        if ($token->type === TokenType::Await) {
            $this->advance();
            $argument = $this->parseUnaryExpression();
            return new AwaitExpression($token->location, $argument);
        }

        // yield
        if ($token->type === TokenType::Yield) {
            return $this->parseYieldExpression();
        }

        return $this->parsePostfixExpression();
    }

    /**
     * Parse postfix operations: member access, computed access, and call expressions.
     *
     * This sits between unary and primary expressions so that
     * `!obj.method()` is parsed as `!(obj.method())`, not `(!obj).method()`.
     */
    private function parsePostfixExpression(): Node
    {
        $expr = $this->parsePrimaryExpression();

        while (true) {
            if ($this->check(TokenType::Dot) && !$this->current()->lineTerminatorBefore) {
                $this->advance();
                $property = $this->parseIdentifierOrKeyword();
                $expr = new MemberExpression($expr->location, $expr, $property, false, false);
                continue;
            }
            if ($this->check(TokenType::LeftBracket)) {
                $this->advance();
                $property = $this->parseExpression();
                $this->expect(TokenType::RightBracket);
                $expr = new MemberExpression($expr->location, $expr, $property, true, false);
                continue;
            }
            if ($this->check(TokenType::LeftParen)) {
                $this->advance();
                $args = $this->parseArguments();
                $expr = new CallExpression($expr->location, $expr, $args, false);
                continue;
            }
            if ($this->check(TokenType::OptionalChaining)) {
                $this->advance();
                if ($this->check(TokenType::LeftParen)) {
                    $this->advance();
                    $args = $this->parseArguments();
                    $expr = new CallExpression($expr->location, $expr, $args, true);
                } elseif ($this->check(TokenType::LeftBracket)) {
                    $this->advance();
                    $property = $this->parseExpression();
                    $this->expect(TokenType::RightBracket);
                    $expr = new MemberExpression(
                        $expr->location,
                        $expr,
                        $property,
                        true,
                        true,
                    );
                } else {
                    $property = $this->parseIdentifierOrKeyword();
                    $expr = new MemberExpression(
                        $expr->location,
                        $expr,
                        $property,
                        false,
                        true,
                    );
                }
                continue;
            }
            // Tagged template literal in postfix position.
            if (
                $this->check(TokenType::NoSubstitutionTemplate)
                || $this->check(TokenType::TemplateHead)
            ) {
                $quasi = $this->parseTemplateLiteral();
                $expr = new TaggedTemplate($expr->location, $expr, $quasi);
                continue;
            }
            break;
        }

        // Postfix ++ and --
        if (
            ($this->check(TokenType::PlusPlus) || $this->check(TokenType::MinusMinus))
            && !$this->current()->lineTerminatorBefore
        ) {
            $op = $this->advance();
            $expr = new UpdateExpression($expr->location, $op->value, $expr, false);
        }

        return $expr;
    }

    private function parseYieldExpression(): YieldExpression
    {
        $location = $this->expect(TokenType::Yield)->location;
        $delegate = $this->eat(TokenType::Star);
        $argument = null;

        if (
            !$this->check(TokenType::Semicolon)
            && !$this->check(TokenType::RightBrace)
            && !$this->check(TokenType::RightParen)
            && !$this->check(TokenType::RightBracket)
            && !$this->check(TokenType::Comma)
            && !$this->check(TokenType::Colon)
            && !$this->isAtEnd()
            && !$this->current()->lineTerminatorBefore
        ) {
            $argument = $this->parseAssignmentExpression();
        }

        return new YieldExpression($location, $argument, $delegate);
    }

    private function parsePrimaryExpression(): Node
    {
        $token = $this->current();

        return match ($token->type) {
            TokenType::Number => $this->parseNumericLiteral(),
            TokenType::String => $this->parseStringLiteral(),
            TokenType::True, TokenType::False => $this->parseBooleanLiteral(),
            TokenType::Null => $this->parseNullLiteral(),
            TokenType::Identifier, TokenType::Let, TokenType::Await => $this->parseIdentifierExpression(),
            TokenType::This => $this->parseThisExpression(),
            TokenType::LeftParen => $this->parseParenthesizedOrArrow(),
            TokenType::LeftBracket => $this->parseArrayExpression(),
            TokenType::LeftBrace => $this->parseObjectExpression(),
            TokenType::Function_ => $this->parseFunctionExpression(),
            TokenType::Class_ => $this->parseClassExpression(),
            TokenType::New => $this->parseNewExpression(),
            TokenType::NoSubstitutionTemplate, TokenType::TemplateHead => $this->parseTemplateLiteral(),
            TokenType::RegExp => $this->parseRegExpLiteral(),
            TokenType::Ellipsis => $this->parseSpreadElement(),
            TokenType::Async => $this->parseAsyncExpression(),
            TokenType::Super => $this->parseSuperExpression(),
            default => throw new ParseError('Unexpected token', $token),
        };
    }

    private function parseNumericLiteral(): Literal
    {
        $token = $this->advance();
        $raw = $token->value;

        if (str_ends_with($raw, 'n')) {
            $value = $raw; // BigInt: keep as string
        } elseif (str_starts_with($raw, '0x') || str_starts_with($raw, '0X')) {
            $value = hexdec($raw);
        } elseif (str_starts_with($raw, '0o') || str_starts_with($raw, '0O')) {
            $value = octdec(substr($raw, 2));
        } elseif (str_starts_with($raw, '0b') || str_starts_with($raw, '0B')) {
            $value = bindec(substr($raw, 2));
        } else {
            $value = (float) $raw;
            $isInt = $value == (int) $value
                && !str_contains($raw, '.')
                && !str_contains($raw, 'e')
                && !str_contains($raw, 'E')
                && abs($value) < PHP_INT_MAX;
            if ($isInt) {
                $value = (int) $raw;
            }
        }

        return new Literal($token->location, $value, $raw);
    }

    private function parseRegExpLiteral(): Literal
    {
        $token = $this->advance();
        // Store the full regex string as value; interpreter will create RegExp object
        return new Literal($token->location, $token->value, $token->value);
    }

    private function parseStringLiteral(): Literal
    {
        $token = $this->advance();
        return new Literal($token->location, $token->value, $token->value);
    }

    private function parseBooleanLiteral(): Literal
    {
        $token = $this->advance();
        return new Literal($token->location, $token->type === TokenType::True, $token->value);
    }

    private function parseNullLiteral(): Literal
    {
        $token = $this->advance();
        return new Literal($token->location, null, 'null');
    }

    private function parseIdentifier(): Identifier
    {
        $token = $this->current();
        // In sloppy mode, `let`, `static`, `of`, and `yield` can be used as identifiers.
        if (
            $token->type !== TokenType::Identifier
            && $token->type !== TokenType::Let
            && $token->type !== TokenType::Static_
            && $token->type !== TokenType::Of
            && $token->type !== TokenType::Yield
            && $token->type !== TokenType::Await
        ) {
            throw new ParseError('Expected identifier', $token);
        }
        $this->advance();
        return new Identifier($token->location, $token->value);
    }

    private function parseIdentifierExpression(): Node
    {
        $token = $this->current();
        $this->advance();

        // async arrow: async (params) => body or async ident => body
        if ($token->value === 'async' && !$this->current()->lineTerminatorBefore) {
            if ($this->check(TokenType::Function_)) {
                return $this->parseAsyncFunctionExpression($token->location);
            }
            if ($this->check(TokenType::Identifier)) {
                $id = $this->parseIdentifier();
                if ($this->check(TokenType::Arrow)) {
                    return $this->parseArrowFunction($id, true);
                }
                // Back up: this was just an identifier "async" followed by another identifier
                // This case shouldn't normally happen in well-formed code; treat as "async" identifier
                $this->pos--;
            }
        }

        return new Identifier($token->location, $token->value);
    }

    private function parseIdentifierOrKeyword(): Identifier
    {
        $token = $this->current();
        // Accept identifiers and most keywords as property names
        if ($token->type === TokenType::Identifier || $token->type->isKeyword()) {
            $this->advance();
            return new Identifier($token->location, $token->value);
        }
        throw new ParseError('Expected identifier', $token);
    }

    private function parseThisExpression(): ThisExpression
    {
        $token = $this->advance();
        return new ThisExpression($token->location);
    }

    private function parseParenthesizedOrArrow(): Node
    {
        $location = $this->current()->location;

        // Try to determine if this is an arrow function or parenthesized expression.
        // Simple heuristic: save state, try parsing as arrow params.
        $savedPos = $this->pos;

        // () => ...
        if ($this->peek()->type === TokenType::RightParen) {
            $this->advance(); // (
            $this->advance(); // )
            if ($this->check(TokenType::Arrow)) {
                return $this->parseArrowFunctionFromParams($location, [], false);
            }
            // Empty parens without arrow: syntax error
            $this->pos = $savedPos;
        }

        // (...rest) => ...
        if ($this->peek()->type === TokenType::Ellipsis) {
            $this->advance(); // (
            $params = $this->parseArrowParams();
            if ($this->check(TokenType::Arrow)) {
                return $this->parseArrowFunctionFromParams($location, $params, false);
            }
            $this->pos = $savedPos;
        }

        // Normal parenthesized expression (may still be arrow params)
        $this->advance(); // consume (
        $expr = $this->parseExpression();
        $this->expect(TokenType::RightParen);

        // Check for arrow
        if ($this->check(TokenType::Arrow)) {
            $params = $this->expressionToParams($expr);
            return $this->parseArrowFunctionFromParams($location, $params, false);
        }

        return $expr;
    }

    /**
     * Parse comma-separated parameters inside already-opened parens.
     * @return Node[]
     */
    private function parseArrowParams(): array
    {
        $params = [];

        while (!$this->check(TokenType::RightParen) && !$this->isAtEnd()) {
            if ($this->check(TokenType::Ellipsis)) {
                $params[] = $this->parseRestElement();
                break;
            }

            $param = $this->parseBindingPattern();
            if ($this->eat(TokenType::Equal)) {
                $default = $this->parseAssignmentExpression();
                $param = new AssignmentPattern($param->location, $param, $default);
            }
            $params[] = $param;

            if (!$this->check(TokenType::RightParen)) {
                $this->expect(TokenType::Comma);
            }
        }

        $this->expect(TokenType::RightParen);
        return $params;
    }

    private function parseArrowFunction(Node $paramExpr, bool $async): ArrowFunction
    {
        $location = $paramExpr->location;
        $params = $this->expressionToParams($paramExpr);
        return $this->parseArrowFunctionFromParams($location, $params, $async);
    }

    /** @param Node[] $params */
    private function parseArrowFunctionFromParams(SourceLocation $location, array $params, bool $async): ArrowFunction
    {
        $this->expect(TokenType::Arrow);

        if ($this->check(TokenType::LeftBrace)) {
            $body = $this->parseBlockStatement();
            return new ArrowFunction($location, $params, $body, false, $async);
        }

        $body = $this->parseAssignmentExpression();
        return new ArrowFunction($location, $params, $body, true, $async);
    }

    /**
     * Convert a parsed expression back into parameter nodes.
     * @return Node[]
     */
    private function expressionToParams(Node $expr): array
    {
        if ($expr instanceof SequenceExpression) {
            return array_map(fn(Node $e) => $this->expressionToParam($e), $expr->expressions);
        }
        return [$this->expressionToParam($expr)];
    }

    private function expressionToParam(Node $expr): Node
    {
        if ($expr instanceof Identifier) {
            return $expr;
        }
        if ($expr instanceof AssignmentExpression && $expr->operator === '=') {
            return new AssignmentPattern(
                $expr->location,
                $this->expressionToParam($expr->left),
                $expr->right,
            );
        }
        if ($expr instanceof SpreadElement) {
            return new RestElement($expr->location, $this->expressionToParam($expr->argument));
        }
        if ($expr instanceof ArrayExpression) {
            $elements = array_map(
                fn(?Node $e) => $e === null ? null : $this->expressionToParam($e),
                $expr->elements,
            );
            return new ArrayPattern($expr->location, $elements);
        }
        if ($expr instanceof ObjectExpression) {
            $props = [];
            foreach ($expr->properties as $prop) {
                if ($prop instanceof SpreadElement) {
                    $props[] = new RestElement($prop->location, $this->expressionToParam($prop->argument));
                } elseif ($prop instanceof Property) {
                    $value = $this->expressionToParam($prop->value);
                    $props[] = new AssignmentProperty(
                        $prop->location,
                        $prop->key,
                        $value,
                        $prop->computed,
                        $prop->shorthand,
                    );
                }
            }
            return new ObjectPattern($expr->location, $props);
        }

        // Fallback: just return the expression as-is (will be caught at runtime)
        return $expr;
    }

    private function parseArrayExpression(): ArrayExpression
    {
        $location = $this->expect(TokenType::LeftBracket)->location;
        $elements = [];

        while (!$this->check(TokenType::RightBracket) && !$this->isAtEnd()) {
            if ($this->check(TokenType::Comma)) {
                $elements[] = null; // elision
                $this->advance();
                continue;
            }

            if ($this->check(TokenType::Ellipsis)) {
                $elements[] = $this->parseSpreadElement();
            } else {
                $elements[] = $this->parseAssignmentExpression();
            }

            if (!$this->check(TokenType::RightBracket)) {
                $this->expect(TokenType::Comma);
            }
        }

        $this->expect(TokenType::RightBracket);
        return new ArrayExpression($location, $elements);
    }

    private function parseObjectExpression(): ObjectExpression
    {
        $location = $this->expect(TokenType::LeftBrace)->location;
        $properties = [];

        while (!$this->check(TokenType::RightBrace) && !$this->isAtEnd()) {
            if ($this->check(TokenType::Ellipsis)) {
                $properties[] = $this->parseSpreadElement();
            } else {
                $properties[] = $this->parsePropertyDefinition();
            }

            if (!$this->check(TokenType::RightBrace)) {
                $this->expect(TokenType::Comma);
            }
        }

        $this->expect(TokenType::RightBrace);
        return new ObjectExpression($location, $properties);
    }

    private function parsePropertyDefinition(): Property
    {
        $location = $this->current()->location;
        $computed = false;
        $shorthand = false;
        $method = false;
        $kind = 'init';

        // Generator method: * method() {}
        $isGenerator = false;
        if ($this->eat(TokenType::Star)) {
            $isGenerator = true;
        }

        // Async method: async method() {}
        $isAsync = false;
        if (!$isGenerator && $this->checkContextual('async') && !$this->peekIs(TokenType::Colon) && !$this->peekIs(TokenType::Comma) && !$this->peekIs(TokenType::RightBrace)) {
            $next = $this->peek();
            if (!$next->lineTerminatorBefore && $next->type !== TokenType::LeftParen) {
                $this->advance();
                $isAsync = true;
                if ($this->eat(TokenType::Star)) {
                    $isGenerator = true;
                }
            }
        }

        // get/set methods
        $isGetOrSet = !$isGenerator && !$isAsync
            && ($this->checkContextual('get') || $this->checkContextual('set'))
            && !$this->peekIs(TokenType::Colon)
            && !$this->peekIs(TokenType::LeftParen)
            && !$this->peekIs(TokenType::Comma)
            && !$this->peekIs(TokenType::RightBrace);

        if ($isGetOrSet && $this->checkContextual('get')) {
            $this->advance();
            $kind = 'get';
            [$key, $computed] = $this->parsePropertyKey($computed);
        } elseif ($isGetOrSet && $this->checkContextual('set')) {
            $this->advance();
            $kind = 'set';
            [$key, $computed] = $this->parsePropertyKey($computed);
        } else {
            [$key, $computed] = $this->parsePropertyKey($computed);
        }

        // Method shorthand: { foo() {} } or { *foo() {} } or { async foo() {} }
        if ($kind !== 'get' && $kind !== 'set' && $this->check(TokenType::LeftParen)) {
            $method = true;
            $params = $this->parseFormalParameters();
            $body = $this->parseBlockStatement();
            $value = new FunctionExpression($body->location, null, $params, $body, $isGenerator, $isAsync);
            return new Property($location, $key, $value, $kind, $computed, $shorthand, $method);
        }

        // Generator/async requires method syntax
        if ($isGenerator || $isAsync) {
            $method = true;
            $params = $this->parseFormalParameters();
            $body = $this->parseBlockStatement();
            $value = new FunctionExpression($body->location, null, $params, $body, $isGenerator, $isAsync);
            return new Property($location, $key, $value, $kind, $computed, $shorthand, $method);
        }

        // get/set: parse params and body
        if ($kind === 'get' || $kind === 'set') {
            $method = true;
            $params = $this->parseFormalParameters();
            $body = $this->parseBlockStatement();
            $value = new FunctionExpression($body->location, null, $params, $body, false, false);
            return new Property($location, $key, $value, $kind, $computed, $shorthand, $method);
        }

        // { key: value }
        if ($this->eat(TokenType::Colon)) {
            $value = $this->parseAssignmentExpression();
            return new Property($location, $key, $value, $kind, $computed, $shorthand, $method);
        }

        // Shorthand { x } or { x = default }
        if ($key instanceof Identifier) {
            $shorthand = true;
            $value = $key;
            if ($this->eat(TokenType::Equal)) {
                $default = $this->parseAssignmentExpression();
                $value = new AssignmentExpression($key->location, '=', $key, $default);
            }
            return new Property($location, $key, $value, $kind, $computed, $shorthand, $method);
        }

        throw new ParseError('Expected property value', $this->current());
    }

    /**
     * @return array{0: Node, 1: bool} [key, computed]
     */
    private function parsePropertyKey(bool &$computed): array
    {
        if ($this->check(TokenType::LeftBracket)) {
            $computed = true;
            $this->advance();
            $key = $this->parseAssignmentExpression();
            $this->expect(TokenType::RightBracket);
            return [$key, true];
        }

        if ($this->check(TokenType::Number)) {
            $token = $this->advance();
            $value = (float) $token->value;
            if ($value == (int) $value) {
                $value = (int) $token->value;
            }
            return [new Literal($token->location, $value, $token->value), false];
        }

        if ($this->check(TokenType::String)) {
            $token = $this->advance();
            return [new Literal($token->location, $token->value, $token->value), false];
        }

        return [$this->parsePropertyName(), false];
    }

    private function parsePropertyName(): Node
    {
        $token = $this->current();
        if ($token->type === TokenType::Identifier || $token->type->isKeyword()) {
            $this->advance();
            return new Identifier($token->location, $token->value);
        }
        if ($token->type === TokenType::Number) {
            return $this->parseNumericLiteral();
        }
        if ($token->type === TokenType::String) {
            return $this->parseStringLiteral();
        }
        throw new ParseError('Expected property name', $token);
    }

    private function parseFunctionExpression(): FunctionExpression
    {
        $location = $this->expect(TokenType::Function_)->location;
        $generator = $this->eat(TokenType::Star);
        $name = null;

        if ($this->check(TokenType::Identifier)) {
            $name = $this->advance()->value;
        }

        $params = $this->parseFormalParameters();
        $body = $this->parseBlockStatement();

        return new FunctionExpression($location, $name, $params, $body, $generator, false);
    }

    private function parseAsyncExpression(): Node
    {
        $token = $this->current();
        $next = $this->peek();

        // async function
        if ($next->type === TokenType::Function_ && !$next->lineTerminatorBefore) {
            $location = $this->advance()->location;
            return $this->parseAsyncFunctionExpression($location);
        }

        // async (params) => body
        if ($next->type === TokenType::LeftParen && !$next->lineTerminatorBefore) {
            $this->advance(); // consume 'async'
            $location = $token->location;
            $this->advance(); // consume (
            $params = $this->parseArrowParams();
            if ($this->check(TokenType::Arrow)) {
                return $this->parseArrowFunctionFromParams($location, $params, true);
            }
            // Not an arrow function; restore is impractical so throw
            throw new ParseError('Expected =>', $this->current());
        }

        // async ident => body
        if ($next->type === TokenType::Identifier && !$next->lineTerminatorBefore) {
            $this->advance(); // consume 'async'
            $id = $this->parseIdentifier();
            if ($this->check(TokenType::Arrow)) {
                return $this->parseArrowFunction($id, true);
            }
            // Not an arrow: "async" as identifier, rewind
            $this->pos -= 1;
            return new Identifier($token->location, 'async');
        }

        // Just the identifier "async"
        $this->advance();
        return new Identifier($token->location, 'async');
    }

    private function parseAsyncFunctionExpression(SourceLocation $location): FunctionExpression
    {
        $this->expect(TokenType::Function_);
        $generator = $this->eat(TokenType::Star);
        $name = null;

        if ($this->check(TokenType::Identifier)) {
            $name = $this->advance()->value;
        }

        $params = $this->parseFormalParameters();
        $body = $this->parseBlockStatement();

        return new FunctionExpression($location, $name, $params, $body, $generator, true);
    }

    private function parseClassExpression(): ClassExpression
    {
        $location = $this->expect(TokenType::Class_)->location;
        $id = null;
        if ($this->check(TokenType::Identifier)) {
            $id = $this->parseIdentifier();
        }
        $superClass = null;
        if ($this->eat(TokenType::Extends)) {
            $superClass = $this->parseLeftHandSideExpression();
        }
        $body = $this->parseClassBody();
        return new ClassExpression($location, $id, $superClass, $body);
    }

    private function parseLeftHandSideExpression(): Node
    {
        $expr = $this->parsePrimaryExpression();

        while (true) {
            if ($this->check(TokenType::Dot)) {
                $this->advance();
                $property = $this->parseIdentifierOrKeyword();
                $expr = new MemberExpression($expr->location, $expr, $property, false, false);
            } elseif ($this->check(TokenType::LeftBracket)) {
                $this->advance();
                $property = $this->parseExpression();
                $this->expect(TokenType::RightBracket);
                $expr = new MemberExpression($expr->location, $expr, $property, true, false);
            } elseif ($this->check(TokenType::LeftParen)) {
                $this->advance();
                $args = $this->parseArguments();
                $expr = new CallExpression($expr->location, $expr, $args, false);
            } else {
                break;
            }
        }

        return $expr;
    }

    private function parseNewExpression(): Node
    {
        $location = $this->expect(TokenType::New)->location;

        // new.target
        if ($this->eat(TokenType::Dot)) {
            $token = $this->current();
            if ($token->type === TokenType::Identifier && $token->value === 'target') {
                $this->advance();
                return new MemberExpression(
                    $location,
                    new Identifier($location, 'new'),
                    new Identifier($token->location, 'target'),
                    false,
                    false,
                );
            }
            throw new ParseError('Expected "target" after "new."', $token);
        }

        // Nested new: new new Foo()
        if ($this->check(TokenType::New)) {
            $callee = $this->parseNewExpression();
        } else {
            $callee = $this->parsePrimaryExpression();

            // Allow member access on callee: new Foo.Bar()
            while ($this->check(TokenType::Dot) || $this->check(TokenType::LeftBracket)) {
                if ($this->eat(TokenType::Dot)) {
                    $property = $this->parseIdentifierOrKeyword();
                    $callee = new MemberExpression($callee->location, $callee, $property, false, false);
                } else {
                    $this->advance();
                    $property = $this->parseExpression();
                    $this->expect(TokenType::RightBracket);
                    $callee = new MemberExpression($callee->location, $callee, $property, true, false);
                }
            }
        }

        $args = [];
        if ($this->eat(TokenType::LeftParen)) {
            $args = $this->parseArguments();
        }

        return new NewExpression($location, $callee, $args);
    }

    private function parseSpreadElement(): SpreadElement
    {
        $location = $this->expect(TokenType::Ellipsis)->location;
        $argument = $this->parseAssignmentExpression();
        return new SpreadElement($location, $argument);
    }

    private function parseTemplateLiteral(): TemplateLiteral
    {
        $token = $this->current();
        $location = $token->location;
        $quasis = [];
        $expressions = [];

        if ($token->type === TokenType::NoSubstitutionTemplate) {
            $this->advance();
            $quasis[] = new TemplateElement($token->location, $token->value, $token->value, true);
            return new TemplateLiteral($location, $quasis, $expressions);
        }

        // TemplateHead — tokens are already split by the lexer
        $this->advance();
        $quasis[] = new TemplateElement($token->location, $token->value, $token->value, false);

        while (true) {
            $expressions[] = $this->parseExpression();

            // The lexer has already tokenized the continuation as TemplateTail or TemplateMiddle
            $cont = $this->current();

            if ($cont->type === TokenType::TemplateTail) {
                $this->advance();
                $quasis[] = new TemplateElement($cont->location, $cont->value, $cont->value, true);
                break;
            }

            if ($cont->type === TokenType::TemplateMiddle) {
                $this->advance();
                $quasis[] = new TemplateElement($cont->location, $cont->value, $cont->value, false);
                continue;
            }

            throw new ParseError('Expected template continuation', $cont);
        }

        return new TemplateLiteral($location, $quasis, $expressions);
    }

    /** @return Node[] */
    private function parseArguments(): array
    {
        $args = [];

        while (!$this->check(TokenType::RightParen) && !$this->isAtEnd()) {
            if ($this->check(TokenType::Ellipsis)) {
                $args[] = $this->parseSpreadElement();
            } else {
                $args[] = $this->parseAssignmentExpression();
            }

            if (!$this->check(TokenType::RightParen)) {
                $this->expect(TokenType::Comma);
            }
        }

        $this->expect(TokenType::RightParen);
        return $args;
    }

    private function parseSuperExpression(): Node
    {
        $location = $this->advance()->location;
        $superIdent = new Identifier($location, 'super');

        if ($this->check(TokenType::Dot)) {
            $this->advance();
            $property = $this->parseIdentifierOrKeyword();
            return new MemberExpression($location, $superIdent, $property, false, false);
        }

        if ($this->check(TokenType::LeftBracket)) {
            $this->advance();
            $property = $this->parseExpression();
            $this->expect(TokenType::RightBracket);
            return new MemberExpression($location, $superIdent, $property, true, false);
        }

        if ($this->check(TokenType::LeftParen)) {
            $this->advance();
            $args = $this->parseArguments();
            return new CallExpression($location, $superIdent, $args, false);
        }

        return $superIdent;
    }

    // -------------------------------------------------------------------------
    // Token navigation
    // -------------------------------------------------------------------------

    /** @phpstan-impure */
    private function current(): Token
    {
        return $this->tokens[$this->pos];
    }

    /** @phpstan-impure */
    private function peek(): Token
    {
        return $this->tokens[$this->pos + 1] ?? $this->tokens[$this->pos];
    }

    /** @phpstan-impure */
    private function advance(): Token
    {
        $token = $this->tokens[$this->pos];
        $this->pos++;
        return $token;
    }

    /** @phpstan-impure */
    private function check(TokenType $type): bool
    {
        return $this->current()->type === $type;
    }

    /** @phpstan-impure */
    private function checkContextual(string $name): bool
    {
        $token = $this->current();
        return $token->type === TokenType::Identifier && $token->value === $name;
    }

    /** @phpstan-impure */
    private function peekIs(TokenType $type): bool
    {
        return $this->peek()->type === $type;
    }

    /** @phpstan-impure */
    private function eat(TokenType $type): bool
    {
        if ($this->check($type)) {
            $this->advance();
            return true;
        }
        return false;
    }

    private function expect(TokenType $type): Token
    {
        if (!$this->check($type)) {
            throw new ParseError("Expected {$type->value}", $this->current());
        }
        return $this->advance();
    }

    private function isAtEnd(): bool
    {
        return $this->current()->type === TokenType::EOF;
    }

    /** Automatic Semicolon Insertion. */
    private function consumeSemicolon(): void
    {
        if ($this->eat(TokenType::Semicolon)) {
            return;
        }

        // ASI: insert semicolon if line terminator before current token,
        // or current token is }, or at end of input.
        if (
            $this->current()->lineTerminatorBefore
            || $this->check(TokenType::RightBrace)
            || $this->isAtEnd()
        ) {
            return;
        }

        throw new ParseError('Expected semicolon', $this->current());
    }
}
