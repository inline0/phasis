<?php

declare(strict_types=1);

namespace Phasis\Parser\Jsx;

/**
 * JSX settings for the parser and the Engine. Per-file pragmas
 * (`@jsx`, `@jsxFrag`, `@jsxRuntime`, `@jsxImportSource`) override these.
 */
final readonly class JsxOptions
{
    /**
     * @param list<string> $extensions Module path suffixes the Engine parses with JSX.
     */
    public function __construct(
        public JsxMode $mode = JsxMode::Automatic,
        public string $importSource = 'react',
        public string $factory = 'React.createElement',
        public string $fragment = 'React.Fragment',
        public array $extensions = ['.jsx', '.tsx'],
    ) {
    }

    /** True when $path ends in one of the JSX extensions. */
    public function appliesTo(string $path): bool
    {
        $lower = strtolower($path);
        foreach ($this->extensions as $extension) {
            if (str_ends_with($lower, strtolower($extension))) {
                return true;
            }
        }
        return false;
    }
}
