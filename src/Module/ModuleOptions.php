<?php

declare(strict_types=1);

namespace Phasis\Module;

/**
 * How module specifiers resolve and how non-JavaScript imports load.
 * Defaults follow bundler behaviour, so imports that work in a client
 * bundle work on the server without a build step.
 */
final readonly class ModuleOptions
{
    /**
     * @param ?string $projectRoot Where jsconfig.json or tsconfig.json is read. Null means the nearest one above the importing file.
     * @param list<string> $extensions Tried in order for paths without an extension and for index files.
     * @param list<string> $conditions Active package.json conditions, besides "import" or "require" and "default".
     * @param string $nodeEnv The value process.env.NODE_ENV reads as; also selects the production/development condition.
     * @param list<string> $styleExtensions Imports with these extensions load as empty modules.
     * @param list<string> $assetExtensions Imports with these extensions default-export a URL from the asset resolver.
     * @param ?string $parseCacheDir When set, parsed module ASTs are cached in this directory across engines and requests.
     * @param bool $deferSideEffectFreeModules Load a module its package declares free of side effects when one of its
     *     imported bindings is first read, instead of before the importer runs. Off by default: it changes when such a
     *     module's top-level code runs, which a package that mislabels its side effects can observe.
     * @param list<string> $packageDirectories Directories whose files are installed package code, like files in
     *     node_modules: a `.js` file's format follows its package.json `"type"` (CommonJS unless it uses ES module
     *     syntax) and its package's `"sideEffects"` allows barrel pruning. For packages copied outside any
     *     node_modules directory, such as a build's vendored copies.
     */
    public function __construct(
        public ?string $projectRoot = null,
        public array $extensions = ['.jsx', '.js', '.mjs', '.cjs', '.json', '.tsx', '.ts', '.mts', '.cts'],
        public array $conditions = ['phasis', 'module', 'production'],
        public string $nodeEnv = 'production',
        public array $styleExtensions = ['.css', '.scss', '.sass', '.less', '.styl'],
        public array $assetExtensions = [
            '.svg', '.png', '.jpg', '.jpeg', '.gif', '.webp', '.avif', '.ico', '.bmp',
            '.woff', '.woff2', '.ttf', '.otf', '.eot', '.mp4', '.webm', '.mp3', '.wav', '.ogg',
        ],
        public ?string $parseCacheDir = null,
        public bool $deferSideEffectFreeModules = false,
        public bool $nodeCompatible = false,
        public array $packageDirectories = [],
    ) {
    }

    /** Whether a resolved module path is installed package code: in node_modules or a package directory. */
    public function isPackageFile(string $path): bool
    {
        if (str_contains($path, '/node_modules/')) {
            return true;
        }
        foreach ($this->packageDirectories as $directory) {
            if (str_starts_with($path, rtrim($directory, '/') . '/')) {
                return true;
            }
        }
        return false;
    }

    /** Preserve host resolution settings while selecting Node package semantics. */
    public function forNode(): self
    {
        return new self(
            projectRoot: $this->projectRoot,
            extensions: ['.js', '.json', '.cjs', '.mjs', '.ts', '.tsx', '.mts', '.cts'],
            conditions: ['node'],
            nodeEnv: $this->nodeEnv,
            styleExtensions: [],
            assetExtensions: [],
            parseCacheDir: $this->parseCacheDir,
            deferSideEffectFreeModules: false,
            nodeCompatible: true,
            packageDirectories: $this->packageDirectories,
        );
    }

    /**
     * Active conditions for `import` or `require`, with the production
     * condition following $nodeEnv.
     *
     * @return array<string, true>
     */
    public function activeConditions(string $kind): array
    {
        $active = [$kind => true, 'default' => true];
        foreach ($this->conditions as $condition) {
            if ($condition === 'production' || $condition === 'development') {
                $condition = $this->nodeEnv === 'production' ? 'production' : 'development';
            }
            $active[$condition] = true;
        }
        return $active;
    }
}
