<?php

/**
 * Renders one HTML-profile fixture through tests/Jsx/html/_driver.mjs in
 * Phasis and prints the JSON the Node oracle prints.
 *
 * Usage: php tests/Jsx/html-run.php <fixture.jsx>
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$fixture = realpath($argv[1] ?? '');
if ($fixture === false) {
    fwrite(STDERR, "usage: html-run.php <fixture.jsx>\n");
    exit(1);
}
echo Phasis\Tests\Jsx\HtmlFixture::render(new Phasis\Engine(), $fixture), "\n";
