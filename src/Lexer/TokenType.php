<?php

declare(strict_types=1);

namespace PhpJs\Lexer;

enum TokenType: string
{
    // Literals
    case Number = 'Number';
    case String = 'String';
    case TemplateHead = 'TemplateHead';
    case TemplateMiddle = 'TemplateMiddle';
    case TemplateTail = 'TemplateTail';
    case NoSubstitutionTemplate = 'NoSubstitutionTemplate';
    case RegExp = 'RegExp';

    // Identifier
    case Identifier = 'Identifier';

    // Keywords
    case Break = 'break';
    case Case = 'case';
    case Catch = 'catch';
    case Class_ = 'class';
    case Const_ = 'const';
    case Continue = 'continue';
    case Debugger = 'debugger';
    case Default_ = 'default';
    case Delete = 'delete';
    case Do = 'do';
    case Else = 'else';
    case Export = 'export';
    case Extends = 'extends';
    case Finally = 'finally';
    case For = 'for';
    case Function_ = 'function';
    case If = 'if';
    case Import = 'import';
    case In = 'in';
    case Instanceof = 'instanceof';
    case Let = 'let';
    case New = 'new';
    case Of = 'of';
    case Return = 'return';
    case Static_ = 'static';
    case Super = 'super';
    case Switch = 'switch';
    case This = 'this';
    case Throw = 'throw';
    case Try = 'try';
    case Typeof = 'typeof';
    case Var = 'var';
    case Void = 'void';
    case While = 'while';
    case With = 'with';
    case Yield = 'yield';
    case Async = 'async';
    case Await = 'await';

    // Boolean and null
    case True = 'true';
    case False = 'false';
    case Null = 'null';

    // Punctuators
    case LeftBrace = '{';
    case RightBrace = '}';
    case LeftParen = '(';
    case RightParen = ')';
    case LeftBracket = '[';
    case RightBracket = ']';
    case Dot = '.';
    case Ellipsis = '...';
    case Semicolon = ';';
    case Comma = ',';
    case LessThan = '<';
    case GreaterThan = '>';
    case LessThanEqual = '<=';
    case GreaterThanEqual = '>=';
    case EqualEqual = '==';
    case NotEqual = '!=';
    case StrictEqual = '===';
    case StrictNotEqual = '!==';
    case Plus = '+';
    case Minus = '-';
    case Star = '*';
    case Exponent = '**';
    case Slash = '/';
    case Percent = '%';
    case PlusPlus = '++';
    case MinusMinus = '--';
    case LeftShift = '<<';
    case RightShift = '>>';
    case UnsignedRightShift = '>>>';
    case Ampersand = '&';
    case Pipe = '|';
    case Caret = '^';
    case Bang = '!';
    case Tilde = '~';
    case LogicalAnd = '&&';
    case LogicalOr = '||';
    case NullishCoalescing = '??';
    case Question = '?';
    case Colon = ':';
    case Equal = '=';
    case PlusEqual = '+=';
    case MinusEqual = '-=';
    case StarEqual = '*=';
    case ExponentEqual = '**=';
    case SlashEqual = '/=';
    case PercentEqual = '%=';
    case LeftShiftEqual = '<<=';
    case RightShiftEqual = '>>=';
    case UnsignedRightShiftEqual = '>>>=';
    case AmpersandEqual = '&=';
    case PipeEqual = '|=';
    case CaretEqual = '^=';
    case LogicalAndEqual = '&&=';
    case LogicalOrEqual = '||=';
    case NullishCoalescingEqual = '??=';
    case Arrow = '=>';
    case OptionalChaining = '?.';

    // Private identifier (#name)
    case PrivateIdentifier = 'PrivateIdentifier';

    // Special
    case EOF = 'EOF';

    private const KEYWORDS = [
        'break' => self::Break,
        'case' => self::Case,
        'catch' => self::Catch,
        'class' => self::Class_,
        'const' => self::Const_,
        'continue' => self::Continue,
        'debugger' => self::Debugger,
        'default' => self::Default_,
        'delete' => self::Delete,
        'do' => self::Do,
        'else' => self::Else,
        'export' => self::Export,
        'extends' => self::Extends,
        'finally' => self::Finally,
        'for' => self::For,
        'function' => self::Function_,
        'if' => self::If,
        'import' => self::Import,
        'in' => self::In,
        'instanceof' => self::Instanceof,
        'let' => self::Let,
        'new' => self::New,
        'of' => self::Of,
        'return' => self::Return,
        'static' => self::Static_,
        'super' => self::Super,
        'switch' => self::Switch,
        'this' => self::This,
        'throw' => self::Throw,
        'try' => self::Try,
        'typeof' => self::Typeof,
        'var' => self::Var,
        'void' => self::Void,
        'while' => self::While,
        'with' => self::With,
        'yield' => self::Yield,
        'async' => self::Async,
        'await' => self::Await,
        'true' => self::True,
        'false' => self::False,
        'null' => self::Null,
    ];

    public static function fromKeyword(string $word): ?self
    {
        return self::KEYWORDS[$word] ?? null;
    }

    public function isKeyword(): bool
    {
        return isset(self::KEYWORDS[$this->value]);
    }

    public function isAssignmentOperator(): bool
    {
        return match ($this) {
            self::Equal,
            self::PlusEqual,
            self::MinusEqual,
            self::StarEqual,
            self::ExponentEqual,
            self::SlashEqual,
            self::PercentEqual,
            self::LeftShiftEqual,
            self::RightShiftEqual,
            self::UnsignedRightShiftEqual,
            self::AmpersandEqual,
            self::PipeEqual,
            self::CaretEqual,
            self::LogicalAndEqual,
            self::LogicalOrEqual,
            self::NullishCoalescingEqual => true,
            default => false,
        };
    }
}
