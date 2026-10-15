<?php

declare(strict_types=1);

namespace Phasis\Tests\Jsx;

use Phasis\Engine;

/** Runs a React fixture through the shared driver, as the Node oracle does. */
final class ReactFixture
{
    public static function render(Engine $engine, string $fixture): string
    {
        $driver = __DIR__ . '/react/_driver.mjs';
        $entry = 'import * as mod from ' . json_encode($fixture) . ";\n"
            . 'import { run } from ' . json_encode($driver) . ";\n"
            . "globalThis.__phasisReactResult = JSON.stringify(await run(mod), null, 2);\n";
        $engine->evalAsModule($entry, dirname($fixture) . '/__entry_' . basename($fixture, '.jsx') . '.mjs');
        return (string) $engine->eval('globalThis.__phasisReactResult');
    }
}
