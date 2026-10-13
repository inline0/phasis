<?php

/**
 * opcache.preload script for Phasis.
 *
 * Loads every Phasis class at server start, so the engine, the module
 * loader and the native React and HTML renderers are compiled and linked
 * once instead of per request:
 *
 *   opcache.preload=/path/to/vendor/phasis/phasis/preload.php
 *   opcache.preload_user=www-data   ; when PHP-FPM starts as root
 *
 * Nothing JavaScript is parsed or run here; React itself is PHP code in
 * Phasis, so preloading it is all the library loading a request needs.
 */

declare(strict_types=1);

(static function (): void {
    foreach ([__DIR__ . '/vendor/autoload.php', __DIR__ . '/../../autoload.php'] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
            break;
        }
    }
    $src = __DIR__ . '/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = $file->getPathname();
        if (!str_ends_with($path, '.php') || str_contains($path, '/data/')) {
            continue;
        }
        $class = 'Phasis\\' . str_replace('/', '\\', substr($path, strlen($src) + 1, -4));
        // Autoloading (rather than opcache_compile_file) links parents,
        // interfaces and traits, which preloading requires.
        if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
            opcache_compile_file($path);
        }
    }
})();
