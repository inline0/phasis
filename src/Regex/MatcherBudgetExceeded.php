<?php

declare(strict_types=1);

namespace Phasis\Regex;

/**
 * Thrown when the custom matcher exhausts its step budget. The
 * caller should fall back to the PCRE2 path so a single bad pattern
 * doesn't take down the rest of the chunk.
 */
class MatcherBudgetExceeded extends \RuntimeException
{
}
