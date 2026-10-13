<?php

/**
 * Renders one React fixture through tests/Jsx/react/_driver.mjs in Phasis and
 * prints the JSON the Node oracle prints. With --import-map=<dir>, react and
 * react-dom resolve to a vendored real React build instead of the native one.
 *
 * Usage: php tests/Jsx/react-run.php <fixture.jsx> [--import-map=<dir>] [--render]
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$fixture = realpath($argv[1] ?? '');
if ($fixture === false) {
    fwrite(STDERR, "usage: react-run.php <fixture.jsx> [--import-map=<dir>] [--render]\n");
    exit(1);
}
$engine = new Phasis\Engine();
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--import-map=')) {
        $dir = rtrim(substr($arg, 13), '/') . '/';
        $engine->setImportMap([
            'react' => $dir . 'react.mjs',
            'react/jsx-runtime' => $dir . 'jsx-runtime.mjs',
            'react-dom' => $dir . 'react-dom.mjs',
            'react-dom/server' => $dir . 'server.mjs',
            'react-dom/static' => $dir . 'static.mjs',
        ]);
    }
}
if (in_array('--render', $argv, true)) {
    echo $engine->render($fixture), "\n";
    exit(0);
}
echo Phasis\Tests\Jsx\ReactFixture::render($engine, $fixture), "\n";
