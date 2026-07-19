<?php

declare(strict_types=1);

namespace Phasis\Formatter\Css;

use Phasis\Exceptions\SyntaxError;
use Phasis\Lexer\SourceLocation;

/**
 * A small structural CSS parser for the formatter: stylesheets become lists
 * of rule, at-rule, declaration, and comment nodes with enough position data
 * for blank-line preservation. Selector, prelude, and value text stay raw;
 * the printer normalizes them.
 *
 * @phpstan-type CssNode array<string, mixed>
 */
final class CssParser
{
    private int $pos = 0;
    private int $line = 1;
    private readonly int $length;

    private function __construct(private readonly string $source)
    {
        $this->length = strlen($source);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function parse(string $source): array
    {
        return (new self($source))->parseBlockContents(true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseBlockContents(bool $topLevel): array
    {
        $nodes = [];

        while (true) {
            [$newlines] = $this->skipWhitespace();
            if ($this->pos >= $this->length) {
                break;
            }

            $ch = $this->source[$this->pos];

            if ($ch === '}') {
                if ($topLevel) {
                    throw new SyntaxError('Unmatched closing brace in stylesheet', $this->location());
                }
                $this->pos++;
                break;
            }

            $blankBefore = $newlines >= 2;
            $sameLine = $newlines === 0 && $nodes !== [];

            if ($ch === '/' && $this->peek(1) === '*') {
                $nodes[] = [
                    'kind' => 'comment',
                    'text' => $this->readComment(),
                    'blankBefore' => $blankBefore,
                    'sameLine' => $sameLine,
                ];
                continue;
            }

            if ($ch === '@') {
                $nodes[] = $this->parseAtRule($blankBefore);
                continue;
            }

            $nodes[] = $this->parseRuleOrDeclaration($blankBefore);
        }

        return $nodes;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAtRule(bool $blankBefore): array
    {
        $this->pos++;
        $name = '';
        while ($this->pos < $this->length && preg_match('/[A-Za-z0-9-]/', $this->source[$this->pos]) === 1) {
            $name .= $this->source[$this->pos];
            $this->pos++;
        }

        [$prelude, $terminator] = $this->readChunk();

        if ($terminator === '{') {
            return [
                'kind' => 'at',
                'name' => $name,
                'prelude' => trim($prelude),
                'children' => $this->parseBlockContents(false),
                'blankBefore' => $blankBefore,
            ];
        }

        return [
            'kind' => 'at',
            'name' => $name,
            'prelude' => trim($prelude),
            'children' => null,
            'blankBefore' => $blankBefore,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRuleOrDeclaration(bool $blankBefore): array
    {
        [$chunk, $terminator] = $this->readChunk();
        $chunk = trim($chunk);

        if ($terminator === '{') {
            return [
                'kind' => 'rule',
                'selector' => $chunk,
                'children' => $this->parseBlockContents(false),
                'blankBefore' => $blankBefore,
            ];
        }

        $colon = $this->topLevelColon($chunk);
        if ($colon === null) {
            return [
                'kind' => 'raw',
                'text' => $chunk,
                'blankBefore' => $blankBefore,
            ];
        }

        return [
            'kind' => 'decl',
            'prop' => trim(substr($chunk, 0, $colon)),
            'value' => trim(substr($chunk, $colon + 1)),
            'blankBefore' => $blankBefore,
        ];
    }

    /**
     * Reads raw text up to a top-level `{`, `;`, or `}`. The terminator `{`
     * and `;` are consumed; `}` is left for the block reader. Strings,
     * parens, brackets, and comments never terminate the chunk.
     *
     * @return array{0: string, 1: string}
     */
    private function readChunk(): array
    {
        $out = '';
        $parens = 0;
        $brackets = 0;

        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];

            if ($ch === '"' || $ch === "'") {
                $out .= $this->readString($ch);
                continue;
            }
            if ($ch === '/' && $this->peek(1) === '*') {
                $out .= $this->readComment();
                continue;
            }
            if ($ch === '(') {
                $parens++;
            } elseif ($ch === ')') {
                $parens = max(0, $parens - 1);
            } elseif ($ch === '[') {
                $brackets++;
            } elseif ($ch === ']') {
                $brackets = max(0, $brackets - 1);
            } elseif ($parens === 0 && $brackets === 0) {
                if ($ch === '{' || $ch === ';') {
                    $this->pos++;
                    return [$out, $ch];
                }
                if ($ch === '}') {
                    return [$out, '}'];
                }
            }

            if ($ch === "\n") {
                $this->line++;
            }
            $out .= $ch;
            $this->pos++;
        }

        return [$out, ''];
    }

    private function readString(string $quote): string
    {
        $start = $this->pos;
        $this->pos++;
        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];
            if ($ch === '\\') {
                $this->pos += 2;
                continue;
            }
            if ($ch === "\n") {
                $this->line++;
            }
            $this->pos++;
            if ($ch === $quote) {
                break;
            }
        }
        return substr($this->source, $start, $this->pos - $start);
    }

    private function readComment(): string
    {
        $start = $this->pos;
        $this->pos += 2;
        while ($this->pos < $this->length) {
            if ($this->source[$this->pos] === '*' && $this->peek(1) === '/') {
                $this->pos += 2;
                return substr($this->source, $start, $this->pos - $start);
            }
            if ($this->source[$this->pos] === "\n") {
                $this->line++;
            }
            $this->pos++;
        }
        throw new SyntaxError('Unterminated comment in stylesheet', $this->location());
    }

    /**
     * @return array{0: int}
     */
    private function skipWhitespace(): array
    {
        $newlines = 0;
        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];
            if ($ch === "\n") {
                $newlines++;
                $this->line++;
            } elseif ($ch !== ' ' && $ch !== "\t" && $ch !== "\r" && $ch !== "\f") {
                break;
            }
            $this->pos++;
        }
        return [$newlines];
    }

    private function topLevelColon(string $chunk): ?int
    {
        $parens = 0;
        $brackets = 0;
        $length = strlen($chunk);
        for ($i = 0; $i < $length; $i++) {
            $ch = $chunk[$i];
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                for ($i++; $i < $length; $i++) {
                    if ($chunk[$i] === '\\') {
                        $i++;
                        continue;
                    }
                    if ($chunk[$i] === $quote) {
                        break;
                    }
                }
                continue;
            }
            if ($ch === '(') {
                $parens++;
            } elseif ($ch === ')') {
                $parens--;
            } elseif ($ch === '[') {
                $brackets++;
            } elseif ($ch === ']') {
                $brackets--;
            } elseif ($ch === ':' && $parens === 0 && $brackets === 0) {
                return $i;
            }
        }
        return null;
    }

    private function peek(int $ahead): string
    {
        return $this->pos + $ahead < $this->length ? $this->source[$this->pos + $ahead] : '';
    }

    private function location(): SourceLocation
    {
        return new SourceLocation($this->line, 0, $this->pos);
    }
}
