<?php

declare(strict_types=1);

namespace Phasis\Formatter\Css;

use Phasis\Formatter\Doc;
use Phasis\Formatter\FormatOptions;
use Phasis\Formatter\LiteralText;

/**
 * Maps parsed CSS nodes to the shared document language with prettier's
 * stylesheet layout: one selector per line, one declaration per line,
 * fill-packed top-level value lists, all-or-nothing function arguments, and
 * preserved blank lines.
 */
final class CssPrinter
{
    /** At-rules whose prelude gets media-query style colon normalization. */
    private const QUERY_AT_RULES = ['media', 'supports', 'container'];

    public function __construct(private readonly FormatOptions $options)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, mixed>
     */
    public function printStylesheet(array $nodes): array
    {
        $doc = [$this->nodeList($nodes)];
        $doc[] = Doc::hardline();
        return $doc;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    private function nodeList(array $nodes): mixed
    {
        $parts = [];
        $first = true;
        foreach ($nodes as $node) {
            if ($node['kind'] === 'comment' && $node['sameLine'] && !$first) {
                $parts[] = ' ' . $node['text'];
                continue;
            }
            if (!$first) {
                $parts[] = Doc::hardline();
                if ($node['blankBefore']) {
                    $parts[] = Doc::hardline();
                }
            }
            $parts[] = $this->node($node);
            $first = false;
        }
        return $parts;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function node(array $node): mixed
    {
        return match ($node['kind']) {
            'comment' => $node['text'],
            'rule' => $this->rule($node),
            'at' => $this->atRule($node),
            'decl' => $this->declaration($node),
            'raw' => [$node['text'], ';'],
            default => throw new \LogicException('Unhandled css node kind.'),
        };
    }

    /**
     * @param array<string, mixed> $node
     */
    private function rule(array $node): mixed
    {
        return [$this->selector((string) $node['selector']), ' {', $this->block($node['children']), '}'];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function atRule(array $node): mixed
    {
        $parts = ['@' . $node['name']];
        $prelude = (string) $node['prelude'];
        if ($prelude !== '') {
            $parts[] = ' ';
            $parts[] = in_array($node['name'], self::QUERY_AT_RULES, true)
                ? $this->queryPrelude($prelude)
                : $this->collapseWhitespace($prelude);
        }
        if ($node['children'] === null) {
            $parts[] = ';';
            return $parts;
        }
        $parts[] = ' {';
        $parts[] = $this->block($node['children']);
        $parts[] = '}';
        return $parts;
    }

    /**
     * @param array<int, array<string, mixed>>|null $children
     */
    private function block(?array $children): mixed
    {
        if ($children === null || $children === []) {
            return Doc::hardline();
        }
        return [Doc::indent([Doc::hardline(), $this->nodeList($children)]), Doc::hardline()];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function declaration(array $node): mixed
    {
        $prop = $this->collapseWhitespace((string) $node['prop']);
        $value = (string) $node['value'];

        $important = false;
        if (preg_match('/\s*!\s*important\s*$/i', $value, $match) === 1) {
            $important = true;
            $value = substr($value, 0, strlen($value) - strlen($match[0]));
        }

        $groups = $this->splitTopLevel($value, ',');
        $groupDocs = array_map(fn(string $group): mixed => $this->valueGroup($group), $groups);

        $suffix = $important ? ' !important;' : ';';

        if (count($groupDocs) > 1) {
            $multiWord = false;
            foreach ($groups as $group) {
                if (count($this->splitWords($group)) > 1 || str_contains($group, '(')) {
                    $multiWord = true;
                    break;
                }
            }
            if ($multiWord) {
                return Doc::group(
                    [
                        $prop . ':',
                        Doc::indent([Doc::hardline(), Doc::join([',', Doc::hardline()], $groupDocs)]),
                        $suffix,
                    ],
                );
            }
            $filled = [];
            foreach ($groupDocs as $index => $groupDoc) {
                if ($index > 0) {
                    $filled[] = [',', Doc::indent(Doc::line())];
                }
                $filled[] = $groupDoc;
            }
            return Doc::group([$prop . ':', Doc::indent(Doc::line()), Doc::fill($filled), $suffix]);
        }

        return Doc::group([$prop . ': ', $groupDocs[0], $suffix]);
    }

    /** One comma-free segment of a value: words that break at spaces only under width pressure. */
    private function valueGroup(string $segment): mixed
    {
        $words = $this->splitWords($segment);
        $docs = [];
        foreach ($words as $index => $word) {
            if ($index > 0) {
                $docs[] = Doc::indent(Doc::line());
            }
            $docs[] = $this->word($word);
        }
        return count($docs) === 1 ? $docs[0] : Doc::fill($docs);
    }

    /** Words joined by plain spaces; never breaks internally. */
    private function atomicGroup(string $segment): mixed
    {
        $words = $this->splitWords($segment);
        $docs = [];
        foreach ($words as $index => $word) {
            if ($index > 0) {
                $docs[] = ' ';
            }
            $docs[] = $this->word($word);
        }
        return $docs;
    }

    private function word(string $word): mixed
    {
        if ($word === '') {
            return '';
        }
        if ($word[0] === '"' || $word[0] === "'") {
            return LiteralText::printString($word, $this->options);
        }
        if (str_starts_with($word, '/*')) {
            return $word;
        }

        $open = strpos($word, '(');
        if ($open !== false && $open > 0 && str_ends_with($word, ')')) {
            $name = substr($word, 0, $open);
            $inner = substr($word, $open + 1, -1);
            if (strtolower($name) === 'url') {
                return $name . '(' . trim($inner) . ')';
            }
            $args = $this->splitTopLevel($inner, ',');
            if (count($args) === 1) {
                return [$name, '(', $this->atomicGroup($args[0]), ')'];
            }
            $argDocs = array_map(fn(string $arg): mixed => $this->atomicGroup($arg), $args);
            return Doc::group([
                $name . '(',
                Doc::indent([Doc::softline(), Doc::join([',', Doc::line()], $argDocs)]),
                Doc::softline(),
                ')',
            ]);
        }

        return $this->normalizeNumeric($word);
    }

    private function normalizeNumeric(string $word): string
    {
        return (string) preg_replace_callback(
            '/^([+-]?)(?:(\d+)\.(\d+)|\.(\d+))(?=\D|$)/',
            static function (array $match): string {
                $sign = $match[1];
                $whole = ($match[2] ?? '') !== '' ? $match[2] : '0';
                $fraction = ($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? '');
                $fraction = rtrim($fraction, '0');
                return $sign . $whole . ($fraction === '' ? '' : '.' . $fraction);
            },
            $word,
        );
    }

    private function selector(string $selector): mixed
    {
        $parts = $this->splitTopLevel($selector, ',');
        $docs = [];
        foreach ($parts as $index => $part) {
            if ($index > 0) {
                $docs[] = ',';
                $docs[] = Doc::hardline();
            }
            $docs[] = $this->selectorPartDoc($this->selectorPart($part));
        }
        return $docs;
    }

    /**
     * A long compound selector breaks at its descendant combinators with the
     * continuation lines indented one level.
     */
    private function selectorPartDoc(string $part): mixed
    {
        $units = $this->splitTopLevel($part, ' ');
        $merged = [];
        foreach ($units as $unit) {
            if ($unit === '') {
                continue;
            }
            $previous = $merged === [] ? '' : (string) end($merged);
            if ($previous === '>' || $previous === '+' || $previous === '~') {
                $merged[count($merged) - 1] .= ' ' . $unit;
                continue;
            }
            $merged[] = $unit;
        }
        if (count($merged) <= 1) {
            return $part;
        }
        $head = array_shift($merged);
        $tail = [];
        foreach ($merged as $unit) {
            $tail[] = Doc::line();
            $tail[] = $this->unitDoc($unit);
        }
        return Doc::group([$this->unitDoc($head), Doc::indent($tail)]);
    }

    /**
     * A single compound selector unit; pseudo-class parens become breakable
     * groups so an overlong unit can wrap inside its parentheses.
     */
    private function unitDoc(string $unit): mixed
    {
        if (!str_contains($unit, '(')) {
            return $unit;
        }
        $docs = [];
        $current = '';
        $length = strlen($unit);
        for ($i = 0; $i < $length; $i++) {
            $ch = $unit[$i];
            if ($ch !== '(') {
                $current .= $ch;
                continue;
            }
            $depth = 1;
            $inner = '';
            for ($i++; $i < $length; $i++) {
                if ($unit[$i] === '(') {
                    $depth++;
                } elseif ($unit[$i] === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                $inner .= $unit[$i];
            }
            $docs[] = $current;
            $docs[] = Doc::group(['(', Doc::indent([Doc::softline(), $inner]), Doc::softline(), ')']);
            $current = '';
        }
        if ($current !== '') {
            $docs[] = $current;
        }
        return $docs;
    }

    private function selectorPart(string $part): string
    {
        $out = '';
        $length = strlen($part);
        $brackets = 0;
        for ($i = 0; $i < $length; $i++) {
            $ch = $part[$i];
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $raw = $ch;
                for ($i++; $i < $length; $i++) {
                    $raw .= $part[$i];
                    if ($part[$i] === '\\') {
                        $i++;
                        if ($i < $length) {
                            $raw .= $part[$i];
                        }
                        continue;
                    }
                    if ($part[$i] === $quote) {
                        break;
                    }
                }
                $out .= LiteralText::printString($raw, $this->options);
                continue;
            }
            if ($ch === '[') {
                $brackets++;
            } elseif ($ch === ']') {
                $brackets--;
            }
            if ($brackets === 0 && ($ch === '>' || $ch === '+' || $ch === '~')) {
                $trimmed = rtrim($out);
                $out = $trimmed === '' || str_ends_with($trimmed, '(') ? $trimmed . $ch : $trimmed . ' ' . $ch;
                $rest = ltrim(substr($part, $i + 1));
                $part = $out . ' ' . $rest;
                $length = strlen($part);
                $i = strlen($out);
                $out .= ' ';
                continue;
            }
            $out .= $ch;
        }
        return $this->collapseWhitespace($out);
    }

    private function queryPrelude(string $prelude): string
    {
        $collapsed = $this->collapseWhitespace($prelude);
        return (string) preg_replace('/\s*:\s*/', ': ', $collapsed);
    }

    private function collapseWhitespace(string $text): string
    {
        $out = '';
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $ch = $text[$i];
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $out .= $ch;
                for ($i++; $i < $length; $i++) {
                    $out .= $text[$i];
                    if ($text[$i] === '\\') {
                        $i++;
                        if ($i < $length) {
                            $out .= $text[$i];
                        }
                        continue;
                    }
                    if ($text[$i] === $quote) {
                        break;
                    }
                }
                continue;
            }
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r" || $ch === "\f") {
                if ($out !== '' && !str_ends_with($out, ' ')) {
                    $out .= ' ';
                }
                continue;
            }
            $out .= $ch;
        }
        return trim($out);
    }

    /**
     * Splits on a separator at paren and bracket depth zero, respecting
     * strings and comments. Segments come back trimmed.
     *
     * @return array<int, string>
     */
    private function splitTopLevel(string $text, string $separator): array
    {
        $segments = [];
        $current = '';
        $parens = 0;
        $brackets = 0;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $ch = $text[$i];
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $current .= $ch;
                for ($i++; $i < $length; $i++) {
                    $current .= $text[$i];
                    if ($text[$i] === '\\') {
                        $i++;
                        if ($i < $length) {
                            $current .= $text[$i];
                        }
                        continue;
                    }
                    if ($text[$i] === $quote) {
                        break;
                    }
                }
                continue;
            }
            if ($ch === '/' && $i + 1 < $length && $text[$i + 1] === '*') {
                $end = strpos($text, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                $current .= substr($text, $i, $end - $i);
                $i = $end - 1;
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
            }
            if ($ch === $separator && $parens === 0 && $brackets === 0) {
                $segments[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if (trim($current) !== '' || $segments === []) {
            $segments[] = trim($current);
        }
        return $segments;
    }

    /**
     * Splits a comma-free segment into space-separated words, keeping
     * strings, comments, and function calls (with their full argument text)
     * as single words.
     *
     * @return array<int, string>
     */
    private function splitWords(string $segment): array
    {
        $words = [];
        $current = '';
        $parens = 0;
        $length = strlen($segment);
        for ($i = 0; $i < $length; $i++) {
            $ch = $segment[$i];
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $current .= $ch;
                for ($i++; $i < $length; $i++) {
                    $current .= $segment[$i];
                    if ($segment[$i] === '\\') {
                        $i++;
                        if ($i < $length) {
                            $current .= $segment[$i];
                        }
                        continue;
                    }
                    if ($segment[$i] === $quote) {
                        break;
                    }
                }
                continue;
            }
            if ($ch === '/' && $i + 1 < $length && $segment[$i + 1] === '*') {
                if ($current !== '') {
                    $words[] = $current;
                    $current = '';
                }
                $end = strpos($segment, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                $words[] = substr($segment, $i, $end - $i);
                $i = $end - 1;
                continue;
            }
            if ($ch === '(') {
                $parens++;
            } elseif ($ch === ')') {
                $parens--;
            }
            if (($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") && $parens === 0) {
                if ($current !== '') {
                    $words[] = $current;
                    $current = '';
                }
                continue;
            }
            $current .= $ch;
        }
        if ($current !== '') {
            $words[] = $current;
        }
        return $words;
    }
}
