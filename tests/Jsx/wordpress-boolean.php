<?php

declare(strict_types=1);

// Offline oracle input: an unmodified WordPress wp-includes/general-template.php.
// Pipe this JSON to tests/Jsx/oracle/named-boolean.mjs for the Node DOM comparison.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require $argv[1];

$source = <<<'JS'
export default ({flag}) => <main><select value="a"><option value="a">A</option>
  <option value="b" selected={flag}>B</option></select><div><p disabled>Static</p></div></main>;
JS;
$options = new \Phasis\HtmlRenderOptions(namedBooleanAttributes: ['selected', 'disabled']);
$results = [];
foreach ([false, true] as $flag) {
    $wordpress = '<main><select><option value="a"' . selected('a', 'a', false) . '>A</option>'
        . '<option value="b"' . selected($flag, true, false) . '>B</option></select>'
        . '<div><p' . disabled(true, true, false) . '>Static</p></div></main>';
    foreach ([\Phasis\Parser\Jsx\JsxMode::Automatic, \Phasis\Parser\Jsx\JsxMode::Precompile] as $mode) {
        $engine = new \Phasis\Engine();
        $engine->setJsxOptions(new \Phasis\Parser\Jsx\JsxOptions(mode: $mode, importSource: 'phasis'));
        $results[] = [
            'wordpress' => $wordpress,
            'phasis' => $engine->renderSource($source, ['flag' => $flag], 'default', null, \Phasis\RenderProfile::Html, $options),
            'mode' => $mode->value,
            'flag' => $flag,
        ];
        $engine->close();
    }
}
echo json_encode($results, JSON_THROW_ON_ERROR), "\n";
