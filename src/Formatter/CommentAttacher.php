<?php

declare(strict_types=1);

namespace Phasis\Formatter;

use Phasis\Ast\Node;
use Phasis\Lexer\Comment;

/**
 * Distributes collected comments onto AST nodes as leading, trailing, or
 * dangling attachments. Works from node start offsets plus targeted raw
 * source scans, so it needs no end positions on nodes: a comment sits inside
 * a subtree when it starts before the subtree's deepest start offset, and a
 * closing delimiter between a comment and the next node reveals that the
 * comment belongs to the construct being closed.
 */
final class CommentAttacher
{
    /** @var \SplObjectStorage<Node, int> */
    private \SplObjectStorage $maxStart;

    /** @var \SplObjectStorage<Node, array<int, array{0: Node, 1: bool}>> */
    private \SplObjectStorage $childCache;

    /** @var array<string, array<int, array{0: string, 1: bool}>> */
    private array $classProps = [];

    private function __construct(private readonly string $source)
    {
        $this->maxStart = new \SplObjectStorage();
        $this->childCache = new \SplObjectStorage();
    }

    /**
     * @param array<int, Comment> $comments
     */
    public static function attach(Node $root, array $comments, string $source): CommentIndex
    {
        $attacher = new self($source);
        $index = new CommentIndex();
        if ($comments !== []) {
            $attacher->distribute($root, $comments, strlen($source), $index);
        }
        return $attacher->finish($comments, $index);
    }

    /**
     * @param array<int, Comment> $comments
     */
    private function finish(array $comments, CommentIndex $index): CommentIndex
    {
        if ($index->attachedCount() !== count($comments)) {
            throw new \LogicException(sprintf(
                'Comment attachment dropped comments: %d collected, %d attached.',
                count($comments),
                $index->attachedCount(),
            ));
        }
        return $index;
    }

    /**
     * @param array<int, Comment> $comments Comments positioned within this node's span, in source order.
     */
    private function distribute(Node $node, array $comments, int $upperBound, CommentIndex $index): void
    {
        $children = $this->children($node);

        if ($children === []) {
            foreach ($comments as $comment) {
                $index->addDangling($node, $this->attached($comment));
            }
            return;
        }

        /** @var \SplObjectStorage<Node, array<int, Comment>> $into */
        $into = new \SplObjectStorage();
        $count = count($children);

        foreach ($comments as $comment) {
            $offset = $comment->start->offset;

            $followingIndex = null;
            for ($i = 0; $i < $count; $i++) {
                if ($children[$i][0]->location->offset > $offset) {
                    $followingIndex = $i;
                    break;
                }
            }
            $preceding = $followingIndex === null
                ? $children[$count - 1]
                : ($followingIndex > 0 ? $children[$followingIndex - 1] : null);
            $following = $followingIndex === null ? null : $children[$followingIndex];

            if ($preceding !== null && $offset < $this->subtreeMaxStart($preceding[0])) {
                $list = $into[$preceding[0]] ?? [];
                $list[] = $comment;
                $into[$preceding[0]] = $list;
                continue;
            }

            $bound = $following !== null ? $following[0]->location->offset : $upperBound;

            if ($preceding !== null && $this->closingBetween($comment->endOffset, $bound)) {
                $container = $this->braceContainerFor($preceding[0], $offset);
                if ($container !== null) {
                    $containerKids = $this->children($container);
                    if ($containerKids === []) {
                        $index->addDangling($container, $this->attached($comment));
                    } else {
                        $index->addTrailing($containerKids[count($containerKids) - 1][0], $this->attached($comment));
                    }
                } else {
                    $index->addTrailing($preceding[0], $this->attached($comment));
                }
                continue;
            }

            if (!$comment->newlineBefore && $preceding !== null) {
                $index->addTrailing($preceding[0], $this->attached($comment));
                continue;
            }

            if ($following !== null) {
                $index->addLeading($following[0], $this->attached($comment));
                continue;
            }

            $index->addTrailing($preceding[0], $this->attached($comment));
        }

        for ($i = 0; $i < $count; $i++) {
            $child = $children[$i][0];
            if (!isset($into[$child])) {
                continue;
            }
            $childBound = $i + 1 < $count ? $children[$i + 1][0]->location->offset : $upperBound;
            $this->distribute($child, $into[$child], $childBound, $index);
        }
    }

    private function attached(Comment $comment): AttachedComment
    {
        return new AttachedComment(
            $comment,
            $comment->newlineBefore,
            $comment->newlineBefore && $this->blankBefore($comment->start->offset),
        );
    }

    /** Whether at least one blank line precedes the given offset. */
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
            if ($ch === ' ' || $ch === "\t" || $ch === "\r") {
                continue;
            }
            break;
        }
        return false;
    }

    /**
     * Whether the source between two offsets reaches a closing delimiter
     * before any other construct, meaning the comment lives inside the
     * construct being closed.
     */
    private function closingBetween(int $from, int $to): bool
    {
        for ($i = $from; $i < $to; $i++) {
            $ch = $this->source[$i];
            if ($ch === ' ' || $ch === "\t" || $ch === "\r" || $ch === "\n") {
                continue;
            }
            if ($ch === '/' && $i + 1 < $to && ($this->source[$i + 1] === '/' || $this->source[$i + 1] === '*')) {
                return false;
            }
            return $ch === '}' || $ch === ')' || $ch === ']' || $ch === ';' || $ch === ',';
        }
        return false;
    }

    /**
     * The deepest brace-delimited container on the trailing edge of a node
     * whose opening precedes the given offset. An own-line comment sitting
     * before a closing delimiter belongs inside this container.
     */
    private function braceContainerFor(Node $node, int $offset): ?Node
    {
        $containers = [
            'BlockStatement',
            'ObjectExpression',
            'ObjectPattern',
            'SwitchStatement',
            'ClassDeclaration',
            'ClassExpression',
        ];
        $best = null;
        $current = $node;
        while (true) {
            if ($current->location->offset >= $offset) {
                break;
            }
            if (in_array($current->type(), $containers, true)) {
                $best = $current;
            }
            $kids = $this->children($current);
            if ($kids === []) {
                break;
            }
            $current = $kids[count($kids) - 1][0];
        }
        return $best;
    }

    private function subtreeMaxStart(Node $node): int
    {
        if (isset($this->maxStart[$node])) {
            return $this->maxStart[$node];
        }
        $max = $node->location->offset;
        foreach ($this->children($node) as [$child]) {
            $childMax = $this->subtreeMaxStart($child);
            if ($childMax > $max) {
                $max = $childMax;
            }
        }
        $this->maxStart[$node] = $max;
        return $max;
    }

    /**
     * Ordered child nodes with a flag marking members of array-valued
     * properties (statement lists, property lists, argument lists).
     *
     * @return array<int, array{0: Node, 1: bool}>
     */
    private function children(Node $node): array
    {
        if (isset($this->childCache[$node])) {
            return $this->childCache[$node];
        }

        $class = get_class($node);
        if (!isset($this->classProps[$class])) {
            $props = [];
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
                $props[] = [$prop->getName(), true];
            }
            $this->classProps[$class] = $props;
        }

        $children = [];
        foreach ($this->classProps[$class] as [$name]) {
            $value = $node->{$name};
            if ($value instanceof Node) {
                $children[] = [$value, false];
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $children[] = [$item, true];
                    }
                }
            }
        }

        usort($children, static fn(array $a, array $b): int => $a[0]->location->offset <=> $b[0]->location->offset);

        $this->childCache[$node] = $children;
        return $children;
    }
}
