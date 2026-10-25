<?php

declare(strict_types=1);

namespace Phasis\Parser\Parts;

use Phasis\Ast\Expression\Literal;
use Phasis\Ast\Jsx\JsxAttribute;
use Phasis\Ast\Jsx\JsxClosingElement;
use Phasis\Ast\Jsx\JsxClosingFragment;
use Phasis\Ast\Jsx\JsxElement;
use Phasis\Ast\Jsx\JsxEmptyExpression;
use Phasis\Ast\Jsx\JsxExpressionContainer;
use Phasis\Ast\Jsx\JsxFragment;
use Phasis\Ast\Jsx\JsxIdentifier;
use Phasis\Ast\Jsx\JsxMemberExpression;
use Phasis\Ast\Jsx\JsxNamespacedName;
use Phasis\Ast\Jsx\JsxOpeningElement;
use Phasis\Ast\Jsx\JsxOpeningFragment;
use Phasis\Ast\Jsx\JsxSpreadAttribute;
use Phasis\Ast\Jsx\JsxSpreadChild;
use Phasis\Ast\Jsx\JsxText;
use Phasis\Ast\Node;
use Phasis\Exceptions\SyntaxError;
use Phasis\Lexer\TokenType;
use Phasis\Parser\Jsx\JsxMode;
use Phasis\Parser\Jsx\JsxText as JsxTextRules;
use Phasis\Parser\ParseError;

/**
 * Parser part: JSX. Composed into Parser via `use Parts\JsxParser;`.
 *
 * Parses a whole JSX tree from the tokens the lexer produced in JSX mode,
 * then hands the outermost element to the lowering step. Elements inside
 * `{...}` containers are separate trees, lowered on their own.
 */
trait JsxParser
{
    /** Entered from parsePrimaryExpression() on a JsxTagStart token. */
    private function parseJsxRoot(): Node
    {
        $savedNoIn = $this->noIn;
        $this->noIn = false;
        try {
            $element = $this->parseJsxElementAt();
        } finally {
            $this->noIn = $savedNoIn;
        }
        if ($this->jsxMode() === JsxMode::Preserve) {
            return $element;
        }
        return $this->jsxLowering()->lower($element);
    }

    private function parseJsxElementAt(): JsxElement|JsxFragment
    {
        $start = $this->expect(TokenType::JsxTagStart);

        if ($this->check(TokenType::JsxTagEnd)) {
            $this->advance();
            $opening = new JsxOpeningFragment($start->location);
            $children = $this->parseJsxChildren();
            $closeStart = $this->expect(TokenType::JsxTagStart);
            $this->expect(TokenType::Slash);
            if (!$this->check(TokenType::JsxTagEnd)) {
                throw new SyntaxError('Expected corresponding JSX closing tag for <>.', $this->current()->location);
            }
            $this->advance();
            return new JsxFragment($start->location, $opening, new JsxClosingFragment($closeStart->location), $children);
        }

        $name = $this->parseJsxElementName();
        if ($this->typeScript && $this->check(TokenType::LessThan)) {
            $this->tsTypeParameters(false);
        }
        $attributes = [];
        while (!$this->check(TokenType::Slash) && !$this->check(TokenType::JsxTagEnd)) {
            $attributes[] = $this->parseJsxAttribute();
        }
        if ($this->eat(TokenType::Slash)) {
            $this->expect(TokenType::JsxTagEnd);
            $opening = new JsxOpeningElement($start->location, $name, $attributes, true);
            return new JsxElement($start->location, $opening, null, []);
        }
        $this->expect(TokenType::JsxTagEnd);
        $opening = new JsxOpeningElement($start->location, $name, $attributes, false);
        $children = $this->parseJsxChildren();

        $closeStart = $this->expect(TokenType::JsxTagStart);
        $this->expect(TokenType::Slash);
        if ($this->check(TokenType::JsxTagEnd)) {
            throw new SyntaxError(
                'Expected corresponding JSX closing tag for <' . self::jsxNameText($name) . '>.',
                $closeStart->location,
            );
        }
        $closingName = $this->parseJsxElementName();
        if (self::jsxNameText($closingName) !== self::jsxNameText($name)) {
            throw new SyntaxError(
                'Expected corresponding JSX closing tag for <' . self::jsxNameText($name) . '>.',
                $closeStart->location,
            );
        }
        $this->expect(TokenType::JsxTagEnd);
        $closing = new JsxClosingElement($closeStart->location, $closingName);
        return new JsxElement($start->location, $opening, $closing, $children);
    }

    /** @return Node[] */
    private function parseJsxChildren(): array
    {
        $children = [];
        while (true) {
            $token = $this->current();
            if ($token->type === TokenType::JsxText) {
                $this->advance();
                $children[] = new JsxText(
                    $token->location,
                    JsxTextRules::decodeEntities($token->value),
                    $token->value,
                );
                continue;
            }
            if ($token->type === TokenType::LeftBrace) {
                $children[] = $this->parseJsxChildExpression();
                continue;
            }
            if ($token->type === TokenType::JsxTagStart) {
                if ($this->peek()->type === TokenType::Slash) {
                    return $children;
                }
                $children[] = $this->parseJsxElementAt();
                continue;
            }
            throw new ParseError('Unexpected token in JSX children', $token);
        }
    }

    private function parseJsxChildExpression(): Node
    {
        $open = $this->advance();
        if ($this->check(TokenType::RightBrace)) {
            $close = $this->advance();
            return new JsxExpressionContainer($open->location, new JsxEmptyExpression($close->location));
        }
        if ($this->check(TokenType::Ellipsis)) {
            $this->advance();
            $expression = $this->parseExpression();
            $this->expect(TokenType::RightBrace);
            return new JsxSpreadChild($open->location, $expression);
        }
        $expression = $this->parseExpression();
        $this->expect(TokenType::RightBrace);
        return new JsxExpressionContainer($open->location, $expression);
    }

    private function parseJsxAttribute(): Node
    {
        $token = $this->current();
        if ($token->type === TokenType::LeftBrace) {
            $this->advance();
            $this->expect(TokenType::Ellipsis);
            $argument = $this->parseAssignmentExpression();
            $this->expect(TokenType::RightBrace);
            return new JsxSpreadAttribute($token->location, $argument);
        }
        $name = $this->parseJsxIdentifier();
        if ($this->eat(TokenType::Colon)) {
            $name = new JsxNamespacedName($name->location, $name, $this->parseJsxIdentifier());
        }
        if (!$this->eat(TokenType::Equal)) {
            return new JsxAttribute($token->location, $name, null);
        }
        $valueToken = $this->current();
        if ($valueToken->type === TokenType::JsxString) {
            $this->advance();
            $quote = $valueToken->rawValue ?? '"';
            $value = new Literal(
                $valueToken->location,
                JsxTextRules::decodeEntities($valueToken->value),
                $quote . $valueToken->value . $quote,
            );
            return new JsxAttribute($token->location, $name, $value);
        }
        if ($valueToken->type === TokenType::LeftBrace) {
            $this->advance();
            if ($this->check(TokenType::RightBrace)) {
                throw new SyntaxError(
                    'JSX attributes must only be assigned a non-empty expression.',
                    $valueToken->location,
                );
            }
            $expression = $this->parseAssignmentExpression();
            $this->expect(TokenType::RightBrace);
            return new JsxAttribute($token->location, $name, new JsxExpressionContainer($valueToken->location, $expression));
        }
        if ($valueToken->type === TokenType::JsxTagStart) {
            return new JsxAttribute($token->location, $name, $this->parseJsxElementAt());
        }
        throw new SyntaxError(
            'JSX value should be either an expression or a quoted JSX text.',
            $valueToken->location,
        );
    }

    private function parseJsxElementName(): Node
    {
        $name = $this->parseJsxIdentifier();
        if ($this->eat(TokenType::Colon)) {
            return new JsxNamespacedName($name->location, $name, $this->parseJsxIdentifier());
        }
        $object = $name;
        while ($this->eat(TokenType::Dot)) {
            $object = new JsxMemberExpression($name->location, $object, $this->parseJsxIdentifier());
        }
        return $object;
    }

    private function parseJsxIdentifier(): JsxIdentifier
    {
        $token = $this->current();
        if ($token->type !== TokenType::JsxName) {
            throw new ParseError('Expected a JSX name', $token);
        }
        $this->advance();
        return new JsxIdentifier($token->location, $token->value);
    }

    /** The source spelling of an element name, used to match opening and closing tags. */
    private static function jsxNameText(Node $name): string
    {
        return match (true) {
            $name instanceof JsxIdentifier => $name->name,
            $name instanceof JsxNamespacedName => $name->namespace->name . ':' . $name->name->name,
            $name instanceof JsxMemberExpression => self::jsxNameText($name->object) . '.' . $name->property->name,
            default => '',
        };
    }
}
