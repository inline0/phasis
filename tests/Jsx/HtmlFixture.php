<?php

declare(strict_types=1);

namespace Phasis\Tests\Jsx;

use Phasis\Engine;

/** Runs an HTML-profile fixture through the shared driver, as the Node oracle does. */
final class HtmlFixture
{
    public static function render(Engine $engine, string $fixture): string
    {
        $entry = 'import * as mod from ' . json_encode($fixture) . ";\n"
            . 'import { run } from ' . json_encode(__DIR__ . '/html/_driver.mjs') . ";\n"
            . "globalThis.__phasisHtmlResult = JSON.stringify(await run(mod), null, 2);\n";
        $engine->evalAsModule($entry, dirname($fixture) . '/__entry_' . basename($fixture, '.jsx') . '.mjs');
        return (string) $engine->eval('globalThis.__phasisHtmlResult');
    }
}
