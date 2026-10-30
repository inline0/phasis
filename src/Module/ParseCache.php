<?php

declare(strict_types=1);

namespace Phasis\Module;

use Phasis\Ast\Program;

/**
 * Optional on-disk cache of parsed module ASTs (ModuleOptions::$parseCacheDir).
 *
 * Under PHP-FPM every request parses the modules it imports again; with the
 * cache, a module is parsed once and later requests unserialize its AST.
 * Entries are keyed by the source text and everything that changes the
 * parse (module or CommonJS goal, JSX options, NODE_ENV), so an edited file
 * or a changed option simply misses.
 */
final class ParseCache
{
    /** Bump when AST classes or generated runtime metadata change, to orphan old entries. */
    private const FORMAT = 'phasis-ast-2';

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @template T of Program|array<int|string, mixed>
     * @param array<string, mixed> $options
     * @param \Closure(): T $parse
     * @return T
     */
    public function remember(string $kind, string $source, array $options, \Closure $parse): mixed
    {
        $key = sha1(self::FORMAT . "\0" . $kind . "\0" . serialize($options) . "\0" . sha1($source));
        $file = $this->directory . '/' . substr($key, 0, 2) . '/' . $key . '.ast';
        $cached = @file_get_contents($file);
        if ($cached !== false) {
            $value = @unserialize($cached);
            if ($value !== false) {
                return $value;
            }
        }
        $value = $parse();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, serialize($value)) !== false) {
            @rename($tmp, $file);
        }
        return $value;
    }
}
