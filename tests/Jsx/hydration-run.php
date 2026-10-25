<?php

declare(strict_types=1);

use Phasis\Engine;
use Phasis\Hydration\HydrationOptions;

$consumer = $argv[1];
require $consumer . '/vendor/autoload.php';
$cases = [
    ['id' => 'alpha', 'export' => 'default', 'props' => ['data' => ['label' => 'Alpha', 'value' => 10, 'mutate' => true]]],
    ['id' => 'beta', 'export' => 'default', 'props' => ['data' => ['label' => 'Beta', 'value' => 20]]],
    ['id' => 'named', 'export' => 'Defaults', 'props' => ['key' => 'key', '__self' => 'hidden', '__source' => 'hidden', 'ref' => null]],
    ['id' => 'empty', 'export' => 'Defaults', 'props' => []],
    ['id' => 'shapes', 'export' => 'Shapes', 'props' => ['data' => (object) [
        'emptyObject' => new stdClass(),
        'emptyArray' => [],
        'callable' => ['DateTime', 'createFromFormat'],
        'null' => null,
        'zero' => -0.0,
        'decimal' => 1.2345678901234567,
        'max' => 9007199254740991,
        'large' => 1.0e100,
        '__proto__' => (object) ['polluted' => true],
        'toJSON' => 'data only',
        'text' => '</script><script>window.exploit=true</script>' . "\u{2028}\u{2029}",
    ]]],
];
$html = '';
foreach ($cases as $case) {
    $engine = new Engine();
    try {
        $rendered = $engine->renderForHydration(
            $consumer . '/components/Root.jsx',
            $case['props'],
            export: $case['export'],
            options: new HydrationOptions(
                rootId: $case['id'],
                entryId: 'contract',
                buildId: 'build-123',
                identifierPrefix: $case['id'] . '-',
            ),
        );
        $html .= $rendered->toHtml();
    } finally {
        $engine->close();
    }
}
echo $html;
