<?php

declare(strict_types=1);

namespace Phasis\Module;

use Phasis\Exceptions\TypeError;

/**
 * Resolves module specifiers the way bundlers do, so the same source works
 * on the server without a build step. Order, first match wins:
 *
 *   1. import map (exact key, then the longest `/`-terminated prefix)
 *   2. built-in modules (react, react-dom, phasis, ...)
 *   3. `#name` specifiers through the package's `imports` field
 *   4. path aliases from jsconfig.json / tsconfig.json
 *   5. relative and absolute paths
 *   6. node_modules, walking up from the importing file
 *
 * Results are keys understood by ModuleLoader: an absolute real path, or
 * `builtin:<specifier>` / `stub:<specifier>` for synthetic modules.
 */
final class ModuleResolver
{
    public const BUILTIN = 'builtin:';
    public const STUB = 'stub:';

    /** @var array<string, ?string> */
    private array $importMap = [];

    /** @var array<string, string> Aliases sharing one built-in module identity. */
    private array $builtinAliases = [];

    /** @var array<string, string> */
    private array $cache = [];

    /** @var array<string, ?array<string, mixed>> package.json contents by directory */
    private array $packageJson = [];

    /** @var array<string, bool> */
    private array $sideEffectFree = [];

    /** @var array<string, ?array{dir: string, baseUrl: ?string, paths: array<string, list<string>>}> */
    private array $aliasConfig = [];

    /**
     * @param \Closure(string): bool $isBuiltin
     */
    public function __construct(
        private ModuleOptions $options,
        private readonly \Closure $isBuiltin,
    ) {
    }

    public function options(): ModuleOptions
    {
        return $this->options;
    }

    public function setOptions(ModuleOptions $options): void
    {
        $this->options = $options;
        $this->clearCache();
    }

    /**
     * @param array<string, ?string> $imports Specifier (or `/`-terminated prefix) => absolute path, or null for a stub.
     */
    public function setImportMap(array $imports): void
    {
        $this->importMap = $imports;
        $this->clearCache();
    }

    public function clearCache(): void
    {
        $this->cache = [];
        $this->packageJson = [];
        $this->aliasConfig = [];
        $this->sideEffectFree = [];
    }

    /** Register an alternate spelling of a built-in module. */
    public function registerBuiltinAlias(string $alias, string $canonical): void
    {
        $this->builtinAliases[$alias] = $canonical;
        $this->clearCache();
    }

    /**
     * @param 'import'|'require' $kind
     */
    public function resolve(string $specifier, ?string $referrer, string $kind = 'import'): string
    {
        if (str_starts_with($specifier, self::BUILTIN) || str_starts_with($specifier, self::STUB)) {
            return $specifier;
        }
        $cacheKey = $kind . "\0" . ($referrer === null ? '' : dirname($referrer)) . "\0" . $specifier;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        return $this->cache[$cacheKey] = $this->resolveUncached($specifier, $referrer, $kind);
    }

    /** The directory of the nearest package.json at or above $path, or null. */
    public function packageScope(string $path): ?string
    {
        $dir = is_dir($path) ? $path : dirname($path);
        while (true) {
            if (is_file($dir . '/package.json')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $dir = $parent;
        }
    }

    /**
     * Whether $path is in a node_modules package whose package.json declares
     * it free of side effects: `"sideEffects": false`, or a list of patterns
     * it does not match. Bundlers trust the same field to tree-shake.
     */
    public function isSideEffectFree(string $path): bool
    {
        $scope = $this->packageScope($path);
        if ($scope === null || !str_contains($path, '/node_modules/')) {
            return false;
        }
        $key = $scope . "\0" . $path;
        if (isset($this->sideEffectFree[$key])) {
            return $this->sideEffectFree[$key];
        }
        $flag = ($this->readPackageJson($scope) ?? [])['sideEffects'] ?? true;
        $result = false;
        if ($flag === false) {
            $result = true;
        } elseif (is_array($flag)) {
            $relative = substr($path, strlen($scope) + 1);
            $result = true;
            foreach ($flag as $pattern) {
                $pattern = ltrim((string) $pattern, './');
                if (fnmatch($pattern, $relative) || (!str_contains($pattern, '/') && fnmatch($pattern, basename($relative)))) {
                    $result = false;
                    break;
                }
            }
        }
        return $this->sideEffectFree[$key] = $result;
    }

    /** @return array<string, mixed>|null */
    public function readPackageJson(string $dir): ?array
    {
        if (array_key_exists($dir, $this->packageJson)) {
            return $this->packageJson[$dir];
        }
        $file = $dir . '/package.json';
        $data = null;
        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            $data = is_array($decoded) ? $decoded : null;
        }
        return $this->packageJson[$dir] = $data;
    }

    /**
     * @param 'import'|'require' $kind
     */
    private function resolveUncached(string $specifier, ?string $referrer, string $kind): string
    {
        if (isset($this->builtinAliases[$specifier])) {
            return self::BUILTIN . $this->builtinAliases[$specifier];
        }
        $mapped = $this->resolveImportMap($specifier);
        if ($mapped !== false) {
            if ($mapped === null) {
                return self::STUB . $specifier;
            }
            return $this->resolveFile($mapped) ?? throw $this->notFound($specifier, $referrer);
        }

        if (($this->isBuiltin)($specifier)) {
            return self::BUILTIN . $specifier;
        }

        if (str_starts_with($specifier, 'node:')) {
            throw new TypeError(
                "Cannot load Node built-in module '{$specifier}'" . self::from($referrer)
                . '; stub it through the import map if it is not used while rendering',
            );
        }

        if (str_starts_with($specifier, '#')) {
            if ($referrer !== null) {
                $resolved = $this->resolvePackageImports($specifier, $referrer, $kind);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
            throw $this->notFound($specifier, $referrer);
        }

        if ($specifier !== '' && ($specifier[0] === '/' || $specifier[0] === '.')) {
            if ($specifier[0] === '.') {
                if ($referrer === null) {
                    throw new TypeError("Cannot resolve relative module specifier '{$specifier}' without a referrer");
                }
                $path = dirname($referrer) . '/' . $specifier;
            } else {
                $path = $specifier;
            }
            return $this->resolveFile($path) ?? throw $this->notFound($specifier, $referrer);
        }

        $alias = $this->resolveAlias($specifier, $referrer);
        if ($alias !== null) {
            return $alias;
        }

        $package = $this->resolvePackage($specifier, $referrer, $kind);
        if ($package !== null) {
            return $package;
        }
        if (self::isNodeBuiltinName($specifier)) {
            throw new TypeError(
                "Cannot load Node built-in module '{$specifier}'" . self::from($referrer)
                . '; stub it through the import map if it is not used while rendering',
            );
        }
        throw $this->notFound($specifier, $referrer);
    }

    /** @return string|null|false The mapped path, null for a stub, false when the import map has no entry. */
    private function resolveImportMap(string $specifier): string|null|false
    {
        if (array_key_exists($specifier, $this->importMap)) {
            return $this->importMap[$specifier];
        }
        $best = null;
        foreach ($this->importMap as $key => $target) {
            if (!str_ends_with($key, '/') || !str_starts_with($specifier, $key)) {
                continue;
            }
            if ($best === null || strlen($key) > strlen($best)) {
                $best = $key;
            }
        }
        if ($best === null) {
            return false;
        }
        $target = $this->importMap[$best];
        if ($target === null) {
            return null;
        }
        return rtrim($target, '/') . '/' . substr($specifier, strlen($best));
    }

    /**
     * A path to a file: the exact file, then each extension appended, then a
     * directory's index file.
     */
    public function resolveFile(string $path): ?string
    {
        return $this->resolvePath($path, []);
    }

    /** @param array<string, true> $visited */
    private function resolvePath(string $path, array $visited): ?string
    {
        $directoryOnly = str_ends_with($path, '/') || preg_match('~(?:^|/)\.{1,2}$~', $path) === 1;
        $path = self::normalize($path);
        if (isset($visited[$path])) {
            return null;
        }
        $visited[$path] = true;
        if (!$directoryOnly && is_file($path)) {
            return realpath($path) ?: $path;
        }
        foreach ($this->options->extensions as $extension) {
            if (!$directoryOnly && is_file($path . $extension)) {
                return realpath($path . $extension) ?: $path . $extension;
            }
        }
        if (is_dir($path)) {
            $json = $this->readPackageJson($path);
            $fields = $this->options->nodeCompatible ? ['main'] : ['module', 'main'];
            foreach ($fields as $field) {
                $main = $json[$field] ?? null;
                if (is_string($main) && $main !== '') {
                    $resolved = $this->resolvePath($path . '/' . self::stripDotSlash($main), $visited);
                    if ($resolved !== null) {
                        return $resolved;
                    }
                }
            }
            foreach ($this->options->extensions as $extension) {
                $index = $path . '/index' . $extension;
                if (is_file($index)) {
                    return realpath($index) ?: $index;
                }
            }
        }
        return null;
    }

    /**
     * @param 'import'|'require' $kind
     */
    private function resolvePackage(string $specifier, ?string $referrer, string $kind): ?string
    {
        [$name, $subpath] = self::splitPackageSpecifier($specifier);
        if ($name === null) {
            return null;
        }
        $dir = $referrer === null
            ? ($this->options->projectRoot ?? (getcwd() ?: '/'))
            : dirname($referrer);
        while (true) {
            $packageDir = $dir . '/node_modules/' . $name;
            if (is_dir($packageDir)) {
                return $this->resolveInPackage($packageDir, $subpath, $specifier, $referrer, $kind);
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $dir = $parent;
        }
    }

    /**
     * @param 'import'|'require' $kind
     */
    private function resolveInPackage(string $packageDir, string $subpath, string $specifier, ?string $referrer, string $kind): string
    {
        $packageDir = realpath($packageDir) ?: $packageDir;
        $json = $this->readPackageJson($packageDir) ?? [];
        if (array_key_exists('exports', $json) && $json['exports'] !== null) {
            $target = $this->resolveExportsMap($json['exports'], $subpath, $this->options->activeConditions($kind));
            if ($target === null) {
                throw new TypeError(
                    "Package subpath '{$subpath}' is not defined by \"exports\" in {$packageDir}/package.json"
                    . self::from($referrer),
                );
            }
            $file = $packageDir . '/' . self::stripDotSlash($target);
            if (!is_file($file)) {
                throw $this->notFound($specifier, $referrer);
            }
            return realpath($file) ?: $file;
        }
        if ($subpath === '.' || $subpath === './') {
            $fields = $this->options->nodeCompatible ? ['main'] : ['module', 'main'];
            foreach ($fields as $field) {
                if (isset($json[$field]) && is_string($json[$field]) && $json[$field] !== '') {
                    $resolved = $this->resolveFile($packageDir . '/' . self::stripDotSlash($json[$field]));
                    if ($resolved !== null) {
                        return $resolved;
                    }
                }
            }
            return $this->resolveFile($packageDir . '/index') ?? throw $this->notFound($specifier, $referrer);
        }
        return $this->resolveFile($packageDir . '/' . self::stripDotSlash($subpath)) ?? throw $this->notFound($specifier, $referrer);
    }

    /**
     * @param 'import'|'require' $kind
     */
    private function resolvePackageImports(string $specifier, string $referrer, string $kind): ?string
    {
        $scope = $this->packageScope($referrer);
        if ($scope === null) {
            return null;
        }
        $json = $this->readPackageJson($scope) ?? [];
        if (!isset($json['imports']) || !is_array($json['imports'])) {
            return null;
        }
        $target = $this->resolveSubpathMap($json['imports'], $specifier, $this->options->activeConditions($kind));
        if ($target === null) {
            return null;
        }
        if (str_starts_with($target, './')) {
            $file = $scope . '/' . substr($target, 2);
            return is_file($file) ? (realpath($file) ?: $file) : null;
        }
        return $this->resolvePackage($target, $referrer, $kind);
    }

    /**
     * Node's PACKAGE_EXPORTS_RESOLVE: returns the target path relative to
     * the package (starting with `./`) or null when the subpath is not
     * exported or is blocked.
     *
     * @param array<string, true> $conditions
     */
    private function resolveExportsMap(mixed $exports, string $subpath, array $conditions): ?string
    {
        $isSubpathMap = is_array($exports) && !array_is_list($exports)
            && str_starts_with((string) array_key_first($exports), '.');
        if (!$isSubpathMap) {
            return $subpath === '.' ? $this->resolveTarget($exports, null, $conditions) : null;
        }
        /** @var array<string, mixed> $exports */
        return $this->resolveSubpathMap($exports, $subpath, $conditions);
    }

    /**
     * @param array<string, mixed> $map
     * @param array<string, true> $conditions
     */
    private function resolveSubpathMap(array $map, string $subpath, array $conditions): ?string
    {
        if (array_key_exists($subpath, $map) && !str_contains($subpath, '*')) {
            return $this->resolveTarget($map[$subpath], null, $conditions);
        }
        $bestKey = null;
        $bestMatch = null;
        foreach ($map as $key => $_) {
            $key = (string) $key;
            $star = strpos($key, '*');
            if ($star === false) {
                // Legacy folder mapping: "./dir/": "./lib/dir/".
                if (
                    str_ends_with($key, '/') && str_starts_with($subpath, $key)
                    && ($bestKey === null || strlen($key) > strlen($bestKey))
                ) {
                    $bestKey = $key;
                    $bestMatch = substr($subpath, strlen($key));
                }
                continue;
            }
            $prefix = substr($key, 0, $star);
            $suffix = substr($key, $star + 1);
            if (
                str_starts_with($subpath, $prefix)
                && $subpath !== $prefix
                && strlen($subpath) >= strlen($key) - 1
                && ($suffix === '' || str_ends_with($subpath, $suffix))
                && ($bestKey === null || self::patternKeyCompare($key, $bestKey) < 0)
            ) {
                $bestKey = $key;
                $bestMatch = substr($subpath, strlen($prefix), strlen($subpath) - strlen($prefix) - strlen($suffix));
            }
        }
        if ($bestKey === null) {
            return null;
        }
        if (!str_contains($bestKey, '*')) {
            $target = $this->resolveTarget($map[$bestKey], null, $conditions);
            return $target === null ? null : $target . $bestMatch;
        }
        return $this->resolveTarget($map[$bestKey], $bestMatch, $conditions);
    }

    /** Node's PATTERN_KEY_COMPARE: the more specific pattern sorts first. */
    private static function patternKeyCompare(string $a, string $b): int
    {
        $baseA = strpos($a, '*');
        $baseB = strpos($b, '*');
        $lengthA = $baseA === false ? strlen($a) : $baseA + 1;
        $lengthB = $baseB === false ? strlen($b) : $baseB + 1;
        if ($lengthA !== $lengthB) {
            return $lengthA > $lengthB ? -1 : 1;
        }
        return strlen($a) > strlen($b) ? -1 : (strlen($a) < strlen($b) ? 1 : 0);
    }

    /**
     * @param array<string, true> $conditions
     */
    private function resolveTarget(mixed $target, ?string $patternMatch, array $conditions): ?string
    {
        if (is_string($target)) {
            if ($patternMatch !== null) {
                $target = str_replace('*', $patternMatch, $target);
            }
            return $target;
        }
        if (is_array($target)) {
            if (array_is_list($target)) {
                foreach ($target as $candidate) {
                    $resolved = $this->resolveTarget($candidate, $patternMatch, $conditions);
                    if ($resolved !== null) {
                        return $resolved;
                    }
                }
                return null;
            }
            foreach ($target as $condition => $value) {
                if (isset($conditions[(string) $condition])) {
                    $resolved = $this->resolveTarget($value, $patternMatch, $conditions);
                    if ($resolved !== null) {
                        return $resolved;
                    }
                }
            }
        }
        return null;
    }

    private function resolveAlias(string $specifier, ?string $referrer): ?string
    {
        $config = $this->aliasConfigFor($referrer);
        if ($config === null) {
            return null;
        }
        $base = $config['baseUrl'] ?? $config['dir'];
        $bestKey = null;
        $bestMatch = '';
        foreach ($config['paths'] as $pattern => $_) {
            $star = strpos($pattern, '*');
            if ($star === false) {
                if ($pattern === $specifier) {
                    $bestKey = $pattern;
                    $bestMatch = '';
                    break;
                }
                continue;
            }
            $prefix = substr($pattern, 0, $star);
            $suffix = substr($pattern, $star + 1);
            if (
                str_starts_with($specifier, $prefix)
                && str_ends_with($specifier, $suffix)
                && strlen($specifier) >= strlen($prefix) + strlen($suffix)
                && ($bestKey === null || strlen($prefix) > strpos($bestKey, '*'))
            ) {
                $bestKey = $pattern;
                $bestMatch = substr($specifier, strlen($prefix), strlen($specifier) - strlen($prefix) - strlen($suffix));
            }
        }
        if ($bestKey !== null) {
            foreach ($config['paths'][$bestKey] as $target) {
                $resolved = $this->resolveFile(self::normalize($base . '/' . str_replace('*', $bestMatch, $target)));
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }
        if ($config['baseUrl'] !== null) {
            return $this->resolveFile(self::normalize($config['baseUrl'] . '/' . $specifier));
        }
        return null;
    }

    /** @return ?array{dir: string, baseUrl: ?string, paths: array<string, list<string>>} */
    private function aliasConfigFor(?string $referrer): ?array
    {
        $start = $this->options->projectRoot ?? ($referrer === null ? null : dirname($referrer));
        if ($start === null) {
            return null;
        }
        if (array_key_exists($start, $this->aliasConfig)) {
            return $this->aliasConfig[$start];
        }
        $dir = $start;
        $found = null;
        while (true) {
            foreach (['jsconfig.json', 'tsconfig.json'] as $name) {
                if (is_file($dir . '/' . $name)) {
                    $found = $dir . '/' . $name;
                    break 2;
                }
            }
            if ($this->options->projectRoot !== null) {
                break;
            }
            $parent = dirname($dir);
            if ($parent === $dir || is_dir($dir . '/node_modules')) {
                break;
            }
            $dir = $parent;
        }
        return $this->aliasConfig[$start] = $found === null ? null : $this->loadAliasConfig($found, 0);
    }

    /** @return ?array{dir: string, baseUrl: ?string, paths: array<string, list<string>>} */
    private function loadAliasConfig(string $file, int $depth): ?array
    {
        $data = self::parseJsonc((string) file_get_contents($file));
        if (!is_array($data)) {
            return null;
        }
        $dir = dirname($file);
        $result = ['dir' => $dir, 'baseUrl' => null, 'paths' => []];
        if ($depth < 8 && isset($data['extends']) && is_string($data['extends']) && str_starts_with($data['extends'], '.')) {
            $parentFile = self::normalize($dir . '/' . $data['extends']);
            if (!str_ends_with($parentFile, '.json')) {
                $parentFile .= '.json';
            }
            if (is_file($parentFile)) {
                $result = $this->loadAliasConfig($parentFile, $depth + 1) ?? $result;
            }
        }
        $compiler = is_array($data['compilerOptions'] ?? null) ? $data['compilerOptions'] : [];
        if (isset($compiler['baseUrl']) && is_string($compiler['baseUrl'])) {
            $result['baseUrl'] = self::normalize($dir . '/' . $compiler['baseUrl']);
            $result['dir'] = $dir;
        }
        if (isset($compiler['paths']) && is_array($compiler['paths'])) {
            $paths = [];
            foreach ($compiler['paths'] as $pattern => $targets) {
                $paths[(string) $pattern] = array_values(array_filter((array) $targets, 'is_string'));
            }
            $result['paths'] = $paths;
            if ($result['baseUrl'] === null) {
                $result['dir'] = $dir;
            }
        }
        return $result;
    }

    /** JSON with comments and trailing commas, as tsconfig.json allows. */
    public static function parseJsonc(string $text): mixed
    {
        $out = '';
        $len = strlen($text);
        $i = 0;
        while ($i < $len) {
            $c = $text[$i];
            if ($c === '"') {
                $j = $i + 1;
                while ($j < $len && $text[$j] !== '"') {
                    $j += $text[$j] === '\\' ? 2 : 1;
                }
                $out .= substr($text, $i, $j - $i + 1);
                $i = $j + 1;
                continue;
            }
            if ($c === '/' && ($text[$i + 1] ?? '') === '/') {
                $end = strpos($text, "\n", $i);
                $i = $end === false ? $len : $end;
                continue;
            }
            if ($c === '/' && ($text[$i + 1] ?? '') === '*') {
                $end = strpos($text, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                continue;
            }
            $out .= $c;
            $i++;
        }
        $out = (string) preg_replace('/,(\s*[}\]])/', '$1', $out);
        return json_decode($out, true);
    }

    /** @return array{0: ?string, 1: string} Package name and subpath (`.` or `./rest`). */
    private static function splitPackageSpecifier(string $specifier): array
    {
        $parts = explode('/', $specifier);
        if ($specifier === '' || $parts[0] === '') {
            return [null, '.'];
        }
        if ($parts[0][0] === '@') {
            if (count($parts) < 2 || $parts[1] === '') {
                return [null, '.'];
            }
            $name = $parts[0] . '/' . $parts[1];
            $rest = array_slice($parts, 2);
        } else {
            $name = $parts[0];
            $rest = array_slice($parts, 1);
        }
        return [$name, $rest === [] ? '.' : './' . implode('/', $rest)];
    }

    private static function stripDotSlash(string $path): string
    {
        return str_starts_with($path, './') ? substr($path, 2) : $path;
    }

    /** Resolve `.` and `..` segments without touching the filesystem. */
    public static function normalize(string $path): string
    {
        $absolute = str_starts_with($path, '/');
        $stack = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($stack);
                continue;
            }
            $stack[] = $part;
        }
        return ($absolute ? '/' : '') . implode('/', $stack);
    }

    private static function isNodeBuiltinName(string $specifier): bool
    {
        $name = explode('/', $specifier)[0];
        return in_array($name, [
            'assert', 'buffer', 'child_process', 'cluster', 'crypto', 'dgram', 'dns', 'events', 'fs', 'http',
            'http2', 'https', 'module', 'net', 'os', 'path', 'perf_hooks', 'process', 'querystring', 'readline',
            'stream', 'string_decoder', 'timers', 'tls', 'tty', 'url', 'util', 'v8', 'vm', 'worker_threads', 'zlib',
        ], true);
    }

    private function notFound(string $specifier, ?string $referrer): TypeError
    {
        return new TypeError("Cannot find module '{$specifier}'" . self::from($referrer));
    }

    private static function from(?string $referrer): string
    {
        return $referrer === null ? '' : " imported from '{$referrer}'";
    }
}
