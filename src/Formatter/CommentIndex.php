<?php

declare(strict_types=1);

namespace Phasis\Formatter;

use Phasis\Ast\Node;

/**
 * Comment attachments produced by CommentAttacher: per-node leading,
 * trailing, and dangling comment lists.
 */
final class CommentIndex
{
    /** @var \SplObjectStorage<Node, array<int, AttachedComment>> */
    private \SplObjectStorage $leading;

    /** @var \SplObjectStorage<Node, array<int, AttachedComment>> */
    private \SplObjectStorage $trailing;

    /** @var \SplObjectStorage<Node, array<int, AttachedComment>> */
    private \SplObjectStorage $dangling;

    private int $attachedCount = 0;

    public function __construct()
    {
        $this->leading = new \SplObjectStorage();
        $this->trailing = new \SplObjectStorage();
        $this->dangling = new \SplObjectStorage();
    }

    public function addLeading(Node $node, AttachedComment $comment): void
    {
        $list = $this->leading[$node] ?? [];
        $list[] = $comment;
        $this->leading[$node] = $list;
        $this->attachedCount++;
    }

    public function addTrailing(Node $node, AttachedComment $comment): void
    {
        $list = $this->trailing[$node] ?? [];
        $list[] = $comment;
        $this->trailing[$node] = $list;
        $this->attachedCount++;
    }

    public function addDangling(Node $node, AttachedComment $comment): void
    {
        $list = $this->dangling[$node] ?? [];
        $list[] = $comment;
        $this->dangling[$node] = $list;
        $this->attachedCount++;
    }

    /** @return array<int, AttachedComment> */
    public function leadingFor(Node $node): array
    {
        return $this->leading[$node] ?? [];
    }

    /** @return array<int, AttachedComment> */
    public function trailingFor(Node $node): array
    {
        return $this->trailing[$node] ?? [];
    }

    /** @return array<int, AttachedComment> */
    public function danglingFor(Node $node): array
    {
        return $this->dangling[$node] ?? [];
    }

    /** Total number of comments attached anywhere; used to assert none were dropped. */
    public function attachedCount(): int
    {
        return $this->attachedCount;
    }
}
