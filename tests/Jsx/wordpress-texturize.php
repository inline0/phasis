<?php

declare(strict_types=1);

// Offline integration oracle: pass the path to an unmodified WordPress
// wp-includes/formatting.php. Stubs supply the default English WP hooks.
// php tests/Jsx/wordpress-texturize.php /path/to/wp-includes/formatting.php
require dirname(__DIR__, 2) . '/vendor/autoload.php';

function apply_filters(string $name, mixed $value): mixed
{
    return $value;
}

function _x(string $text, string $context): string
{
    return $text;
}

$shortcode_tags = [];
require $argv[1];

$source = <<<'JS'
import { raw } from 'phasis';
export default ({text, attr}) => <main><section><p data-animate="[&amp;&gt;span&gt;span]" title={attr}>"Hello" &amp; &lt; &gt; &amp;quot; {text}</p>
  <pre>"pre"</pre><code>"code"</code></section>
  <script>{'let x = "script";'}</script><noscript>"noscript"</noscript>
  {raw('<strong>"raw"</strong>')}</main>;
JS;
$options = new \Phasis\HtmlRenderOptions(escapeNoscriptText: true, reactScriptText: true, literalTextQuotes: true, escapeAttributeGreaterThan: true);
foreach ([\Phasis\Parser\Jsx\JsxMode::Automatic, \Phasis\Parser\Jsx\JsxMode::Precompile] as $mode) {
    $engine = new \Phasis\Engine();
    $engine->setJsxOptions(new \Phasis\Parser\Jsx\JsxOptions(mode: $mode, importSource: 'phasis'));
    $props = ['text' => '"dynamic"', 'attr' => '[&>span>span] "<'];
    $html = $engine->renderSource($source, $props, 'default', null, \Phasis\RenderProfile::Html, $options);
    $default = $engine->renderSource($source, $props, 'default', null, \Phasis\RenderProfile::Html);
    if (!str_contains(wptexturize($default, true), '&quot;Hello&quot;')) {
        throw new RuntimeException('The default profile no longer reproduces the literal-quote regression.');
    }
    $actual = wptexturize($html, true);
    $expected = '<main><section><p data-animate="[&amp;&gt;span&gt;span]" title="[&amp;&gt;span&gt;span] &quot;&lt;">'
        . '&#8220;Hello&#8221; &amp; &lt; > &amp;quot; &#8220;dynamic&#8221;</p><pre>"pre"</pre><code>"code"</code></section>'
        . '<script>let x = "script";</script><noscript>&#8220;noscript&#8221;</noscript><strong>&#8220;raw&#8221;</strong></main>';
    if ($actual !== $expected) {
        throw new RuntimeException("Unexpected WordPress output for {$mode->value}:\n" . $actual);
    }
    $engine->close();
}
fwrite(STDOUT, "WordPress texturize regression passed in both JSX modes.\n");
