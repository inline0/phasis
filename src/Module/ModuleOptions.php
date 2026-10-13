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
     */
    public function __construct(
        public ?string $projectRoot = null,
        public array $extensions = ['.jsx', '.js', '.mjs', '.cjs', '.json'],
        public array $conditions = ['phasis', 'module', 'production'],
        public string $nodeEnv = 'production',
        public array $styleExtensions = ['.css', '.scss', '.sass', '.less', '.styl'],
        public array $assetExtensions = [
            '.svg', '.png', '.jpg', '.jpeg', '.gif', '.webp', '.avif', '.ico', '.bmp',
            '.woff', '.woff2', '.ttf', '.otf', '.eot', '.mp4', '.webm', '.mp3', '.wav', '.ogg',
        ],
    ) {
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
