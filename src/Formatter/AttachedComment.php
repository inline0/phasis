<?php

declare(strict_types=1);

namespace Phasis\Formatter;

use Phasis\Lexer\Comment;

/**
 * A comment bound to an AST node with the layout facts the printer needs.
 */
readonly class AttachedComment
{
    public function __construct(
        public Comment $comment,
        /** True when the comment sat on its own line (a newline separated it from the previous content). */
        public bool $ownLine,
        /** True when at least one blank line separated the comment from the previous content. */
        public bool $blankBefore,
    ) {
    }
}
