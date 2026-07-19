<?php

declare(strict_types=1);

namespace Phasis\Formatter;

/**
 * Layout options for the formatter. Defaults mirror prettier 3.x so output
 * is byte-compatible with prettier-formatted code on the same settings.
 */
readonly class FormatOptions
{
    public function __construct(
        public int $printWidth = 80,
        public int $tabWidth = 2,
        public bool $useTabs = false,
        public bool $semi = true,
        public bool $singleQuote = false,
        /** One of "all", "es5", or "none". */
        public string $trailingComma = 'all',
        public bool $bracketSpacing = true,
        /** One of "always" or "avoid". */
        public string $arrowParens = 'always',
    ) {
        if (!in_array($this->trailingComma, ['all', 'es5', 'none'], true)) {
            throw new \InvalidArgumentException(
                "trailingComma must be \"all\", \"es5\", or \"none\", got \"{$this->trailingComma}\"",
            );
        }
        if (!in_array($this->arrowParens, ['always', 'avoid'], true)) {
            throw new \InvalidArgumentException(
                "arrowParens must be \"always\" or \"avoid\", got \"{$this->arrowParens}\"",
            );
        }
    }

    /** The string emitted for one level of indentation. */
    public function indentString(): string
    {
        return $this->useTabs ? "\t" : str_repeat(' ', $this->tabWidth);
    }
}
