<?php

declare(strict_types=1);

namespace Phasis\Lexer;

/**
 * A source comment captured during tokenization when comment collection is
 * enabled. Comments are trivia to the engine; the formatter needs them with
 * enough position data to reattach and reprint them.
 */
readonly class Comment
{
    public function __construct(
        /** Either "line" ("// ...") or "block". */
        public string $kind,
        /** Full raw text including the comment markers. */
        public string $raw,
        /** Location of the first marker character. */
        public SourceLocation $start,
        /** Byte offset just past the end of the comment. */
        public int $endOffset,
        /** Line number of the comment's last line. */
        public int $endLine,
        /** Whether a line terminator separated this comment from the previous token or comment. */
        public bool $newlineBefore,
    ) {
    }
}
