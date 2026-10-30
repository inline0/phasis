<?php

declare(strict_types=1);

namespace Phasis\Tests\Jsx;

use Phasis\Engine;
use Phasis\HtmlRenderOptions;
use Phasis\Parser\Jsx\JsxMode;
use Phasis\Parser\Jsx\JsxOptions;
use Phasis\RenderProfile;
use PHPUnit\Framework\TestCase;

final class JsxHtmlOptionsTest extends TestCase
{
    public function testTextAndAttributeEscapingMatchReactDomInBothModes(): void
    {
        $source = <<<'JS'
export default ({text, attr}) => <main><section>
  <p data-static="[&amp;&gt;span&gt;span] &quot; &lt;" data-dynamic={attr}>Static "quote" &amp; &lt; &gt; &amp;quot; {text}</p>
  <pre>"pre" &amp; &lt; &gt;</pre><code>"code" &amp; &lt; &gt;</code>
  </section><svg><a data-svg={attr}><foreignObject><div data-foreign={attr}>"foreign" {text}</div></foreignObject></a></svg></main>;
JS;
        $props = ['text' => '"dynamic" & < > &quot;', 'attr' => '[&>span>span] " < &quot;'];
        $oracle = json_decode((string) file_get_contents(__DIR__ . '/oracle/text-attribute-escaping.expected.json'), true, 512, JSON_THROW_ON_ERROR);
        $ordinary = new Engine();
        $precompiled = new Engine();
        $precompiled->setJsxOptions(new JsxOptions(mode: JsxMode::Precompile, importSource: 'phasis'));
        foreach ([false, true] as $quotes) {
            foreach ([false, true] as $greater) {
                $options = new HtmlRenderOptions(literalTextQuotes: $quotes, escapeAttributeGreaterThan: $greater);
                $html = $ordinary->renderSource($source, $props, 'default', null, RenderProfile::Html, $options);
                $this->assertSame($html, $precompiled->renderSource($source, $props, 'default', null, RenderProfile::Html, $options));
                $this->assertStringContainsString($quotes ? 'Static "quote"' : 'Static &quot;quote&quot;', $html);
                $this->assertStringContainsString($greater ? '[&amp;&gt;span&gt;span]' : '[&amp;>span>span]', $html);
                $dom = new \DOMDocument();
                @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
                $xpath = new \DOMXPath($dom);
                $paragraph = $xpath->query('//p')->item(0);
                $svg = $xpath->query('//a')->item(0);
                $foreign = $xpath->query('//*[@data-foreign]')->item(0);
                $this->assertInstanceOf(\DOMElement::class, $paragraph);
                $this->assertInstanceOf(\DOMElement::class, $svg);
                $this->assertInstanceOf(\DOMElement::class, $foreign);
                $this->assertSame($oracle['paragraph'], $paragraph->textContent);
                $this->assertSame($oracle['staticAttribute'], $paragraph->getAttribute('data-static'));
                $this->assertSame($oracle['dynamicAttribute'], $paragraph->getAttribute('data-dynamic'));
                $this->assertSame($oracle['pre'], $xpath->query('//pre')->item(0)->textContent);
                $this->assertSame($oracle['code'], $xpath->query('//code')->item(0)->textContent);
                $this->assertSame($oracle['svgAttribute'], $svg->getAttribute('data-svg'));
                $this->assertSame($oracle['foreignAttribute'], $foreign->getAttribute('data-foreign'));
                $this->assertSame($oracle['foreignText'], $foreign->textContent);
            }
        }
    }

    public function testNewOptionsPreserveRawScriptNoscriptAndEmptyValues(): void
    {
        $source = <<<'JS'
import { raw } from 'phasis';
export default ({text}) => <main><section><p title="&gt;" value="">"ordinary" {text}</p></section>
  <noscript><div><p>"noscript" &gt; {text}</p></div></noscript>
  <script>{'if (a > b) { x = "</script>" }'}</script>
  {raw('<b data-x=">">"raw"</b>')}<div dangerouslySetInnerHTML={{__html:'<i>"raw"</i>'}}/></main>;
JS;
        $options = new HtmlRenderOptions(['value'], true, true, true, true);
        $ordinary = new Engine();
        $precompiled = new Engine();
        $precompiled->setJsxOptions(new JsxOptions(mode: JsxMode::Precompile, importSource: 'phasis'));
        $html = $ordinary->renderSource($source, ['text' => '">'], 'default', null, RenderProfile::Html, $options);
        $this->assertSame($html, $precompiled->renderSource($source, ['text' => '">'], 'default', null, RenderProfile::Html, $options));
        $this->assertStringContainsString('<p title="&gt;" value="">"ordinary" ">', $html);
        $this->assertStringContainsString('<p>"noscript" &gt; "&gt;</p>', $html);
        $this->assertStringContainsString('<script>if (a > b) { x = "</\\u0073cript>" }</script>', $html);
        $this->assertStringContainsString('<b data-x=">">"raw"</b><div><i>"raw"</i></div>', $html);
    }

    public function testOldMetadataFailsClosedForGreaterThanEscaping(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('precompiled static output from an older template');
        (new Engine())->renderSource(
            "import { jsxTemplate } from 'phasis/jsx-precompile'; import { jsx } from 'phasis/jsx-runtime'; "
                . "const tpl = ['<p title=\">\">text</p>', {p:'html', f:[0], s:[], a:[], h:[[]]}]; "
                . "export default () => jsx('div', {children:jsxTemplate(tpl)});",
            [],
            'default',
            null,
            RenderProfile::Html,
            new HtmlRenderOptions(escapeAttributeGreaterThan: true),
        );
    }

    public function testHostileDynamicAttributeCannotBreakOut(): void
    {
        $source = 'export default ({value}) => <main><section><p title={value}>{value}</p></section></main>;';
        $value = '"><img src=x onerror=alert(1)> & <';
        $options = new HtmlRenderOptions(literalTextQuotes: true, escapeAttributeGreaterThan: true);
        $engine = new Engine();
        $engine->setJsxOptions(new JsxOptions(mode: JsxMode::Precompile, importSource: 'phasis'));
        $html = $engine->renderSource($source, ['value' => $value], 'default', null, RenderProfile::Html, $options);
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $this->assertSame(0, $dom->getElementsByTagName('img')->length);
        $paragraph = $dom->getElementsByTagName('p')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $paragraph);
        $this->assertSame($value, $paragraph->getAttribute('title'));
        $this->assertSame($value, $paragraph->textContent);
    }

    public function testSelectedEmptyValuesMatchReactWithoutChangingTheDefault(): void
    {
        $fixture = __DIR__ . '/react/empty-option.jsx';
        $oracle = json_decode((string) file_get_contents(__DIR__ . '/react/empty-option.expected.txt'), true, 512, JSON_THROW_ON_ERROR);
        $engine = new Engine();
        $options = new HtmlRenderOptions(['value']);
        $this->assertSame('<option value>All</option>', $engine->render($fixture, [], 'default', RenderProfile::Html));
        $this->assertSame($oracle['static'], $engine->render($fixture, [], 'default', RenderProfile::Html, $options));
        $this->assertSame('<option value>All</option>', $engine->render($fixture, [], 'default', RenderProfile::Html));
    }

    public function testOnlyNamedStringAttributesAreQuotedAndOptionsReachAsyncChildren(): void
    {
        $engine = new Engine();
        $source = <<<'JS'
async function Child({text}) {
  await Promise.resolve();
  return <option value="" data-label="">{text}</option>;
}
export default function App() {
  return <noscript><select data-root="" disabled><Child text={'"a" <b>'}/></select></noscript>;
}
JS;
        $this->assertSame(
            '<noscript><select data-root disabled><option value="" data-label>"a" &lt;b&gt;</option></select></noscript>',
            $engine->renderSource($source, [], 'default', null, RenderProfile::Html, new HtmlRenderOptions(['value'], true)),
        );
    }

    public function testNoscriptTextMatchesNodeHtmlSerializationWithoutChangingOrdinaryText(): void
    {
        $cases = json_decode((string) file_get_contents(__DIR__ . '/oracle/noscript-text.expected.json'), true, 512, JSON_THROW_ON_ERROR);
        $source = 'export default function App({text}) { return <main><p>{text}</p><noscript><p>{text}</p></noscript><p>{text}</p></main>; }';
        $engine = new Engine();
        foreach ($cases as $case) {
            $ordinary = strtr($case['input'], ['"' => '&quot;', '&' => '&amp;', '<' => '&lt;']);
            $expected = '<main><p>' . $ordinary . '</p><noscript><p>' . $case['normalized'] . '</p></noscript><p>' . $ordinary . '</p></main>';
            $this->assertSame(
                $expected,
                $engine->renderSource($source, ['text' => $case['input']], 'default', null, RenderProfile::Html, new HtmlRenderOptions([], true)),
            );
        }
    }

    public function testScriptTextMatchesReactWithoutChangingDefaultOrNeighbouringText(): void
    {
        $cases = json_decode((string) file_get_contents(__DIR__ . '/oracle/script-text.expected.json'), true, 512, JSON_THROW_ON_ERROR);
        $source = 'export default function App({text}) { return <script>{text}</script>; }';
        $engine = new Engine();
        $options = new HtmlRenderOptions([], false, true);
        foreach ($cases as $case) {
            $this->assertSame(
                $case['html'],
                $engine->renderSource($source, ['text' => $case['input']], 'default', null, RenderProfile::Html, $options),
            );
        }
        $text = 'if (a < b && x > 1) { console.log("ok") }';
        $this->assertSame(
            '<script>if (a &lt; b &amp;&amp; x > 1) { console.log(&quot;ok&quot;) }</script>',
            $engine->renderSource($source, ['text' => $text], 'default', null, RenderProfile::Html),
        );
        $neighbours = 'export default function App(){return <main><p>{"a < b && c"}</p>'
            . '<script>{"a < b && c"}</script><p>{"a < b && c"}</p></main>}';
        $this->assertSame(
            '<main><p>a &lt; b &amp;&amp; c</p><script>a < b && c</script><p>a &lt; b &amp;&amp; c</p></main>',
            $engine->renderSource($neighbours, [], 'default', null, RenderProfile::Html, $options),
        );
    }

    public function testScriptTextRequiresOneStringAndSurvivesAsyncRendering(): void
    {
        $engine = new Engine();
        $options = new HtmlRenderOptions([], false, true);
        $async = 'async function Child(){await Promise.resolve();return <script>{"if (a < b && c) { run() }"}</script>} '
            . 'export default function App(){return <Child/>}';
        $this->assertSame(
            '<script>if (a < b && c) { run() }</script>',
            $engine->renderSource($async, [], 'default', null, RenderProfile::Html, $options),
        );
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('requires a single string child');
        $invalid = 'export default function App(){return <script>{["</scr", "ipt>"]}</script>}';
        $engine->renderSource($invalid, [], 'default', null, RenderProfile::Html, $options);
    }

    public function testStyleTextMatchesReactInBothJsxModesWithoutChangingDefaults(): void
    {
        $cases = json_decode((string) file_get_contents(__DIR__ . '/oracle/style-text.expected.json'), true, 512, JSON_THROW_ON_ERROR);
        $source = 'export default function App({text}) { return <main><style>{text}</style><p>{text}</p></main>; }';
        $options = new HtmlRenderOptions(reactStyleText: true);
        foreach ([JsxMode::Automatic, JsxMode::Precompile] as $mode) {
            $engine = new Engine();
            $engine->setJsxOptions(new JsxOptions(mode: $mode, importSource: 'phasis'));
            foreach ($cases as $case) {
                $ordinary = strtr($case['input'], ['"' => '&quot;', '&' => '&amp;', '<' => '&lt;']);
                $this->assertSame(
                    '<main>' . $case['html'] . '<p>' . $ordinary . '</p></main>',
                    $engine->renderSource($source, ['text' => $case['input']], 'default', null, RenderProfile::Html, $options),
                );
            }
            $this->assertSame(
                '<main><style>a > b &amp; &quot;x&quot;</style><p>a > b &amp; &quot;x&quot;</p></main>',
                $engine->renderSource($source, ['text' => 'a > b & "x"'], 'default', null, RenderProfile::Html),
            );
        }
    }

    public function testStyleTextRejectsMultipleChildren(): void
    {
        $engine = new Engine();
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('requires a single string child');
        $source = 'export default function App(){return <style>{["</st", "yle>"]}</style>}';
        $engine->renderSource($source, [], 'default', null, RenderProfile::Html, new HtmlRenderOptions(reactStyleText: true));
    }

    public function testOptionsRejectInvalidNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTML attribute name');
        new HtmlRenderOptions(['onload=']);
    }

    public function testHtmlOptionsCannotBeSilentlyIgnoredByReact(): void
    {
        $engine = new Engine();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTML render options require the HTML profile');
        $engine->renderSource('export default () => <div />', [], 'default', null, RenderProfile::React, new HtmlRenderOptions(['value']));
    }

    public function testPrecompiledOptionsMatchOrdinaryRenderingAndReactOracle(): void
    {
        $source = <<<'JS'
async function Child({text}) {
  await Promise.resolve();
  return <div><p value="" data-x="">Static "quoted" &gt; {text}</p></div>;
}
export default function App({text, empty, script}) {
  return <main><section><p value="" data-x={empty}>Outside {text}</p></section>
    <noscript><div><p value="" data-x={empty}>Static "quoted" &gt; {text}</p><Child text={text}/></div></noscript>
    <script>{script}</script><section><p>After</p></section></main>;
}
JS;
        $props = ['text' => 'a & b < c', 'empty' => '', 'script' => 'if (a < b) { x = "</script>"; }'];
        $ordinary = new Engine();
        $precompiled = new Engine();
        $precompiled->setJsxOptions(new JsxOptions(mode: JsxMode::Precompile, importSource: 'phasis'));
        $profiles = [
            null,
            new HtmlRenderOptions(['value']),
            new HtmlRenderOptions(['data-x'], true),
            new HtmlRenderOptions([], false, true),
            new HtmlRenderOptions(['value', 'data-x'], true, true),
        ];
        foreach ($profiles as $options) {
            $expected = $ordinary->renderSource($source, $props, 'default', null, RenderProfile::Html, $options);
            $this->assertSame($expected, $precompiled->renderSource($source, $props, 'default', null, RenderProfile::Html, $options));
        }
        $oracle = json_decode((string) file_get_contents(__DIR__ . '/oracle/precompile-options.expected.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($oracle['html'], $precompiled->renderSource($source, $props, 'default', null, RenderProfile::Html, $profiles[4]));
    }

    public function testOldPrecompiledTemplatesFailClosedWhenStaticOutputMightChange(): void
    {
        $engine = new Engine();
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('precompiled static output from an older template');
        $engine->renderSource(
            "import { jsxTemplate } from 'phasis/jsx-precompile'; import { jsx } from 'phasis/jsx-runtime'; "
                . "const tpl = ['<p value>text</p>', {p:'html', f:[0], s:[], a:[]}]; export default () => jsx('div', {children:jsxTemplate(tpl)});",
            [],
            'default',
            null,
            RenderProfile::Html,
            new HtmlRenderOptions(['value']),
        );
    }

    public function testPrecompiledEditsUseSerializedNamesAndByteOffsets(): void
    {
        $source = <<<'JS'
export default ({dynamic}) => <noscript><div><p className="" htmlFor="" data-x="">雪 "a" &gt;</p>
  <p className={dynamic} htmlFor={dynamic} data-x={dynamic}>雪 "b" &gt;</p></div></noscript>;
JS;
        $ordinary = new Engine();
        $precompiled = new Engine();
        $precompiled->setJsxOptions(new JsxOptions(mode: JsxMode::Precompile, importSource: 'phasis'));
        foreach ([[], ['class'], ['for', 'data-x'], ['class', 'for', 'data-x']] as $names) {
            foreach ([false, true] as $escape) {
                $options = new HtmlRenderOptions($names, $escape);
                $expected = $ordinary->renderSource($source, ['dynamic' => ''], 'default', null, RenderProfile::Html, $options);
                $this->assertSame($expected, $precompiled->renderSource($source, ['dynamic' => ''], 'default', null, RenderProfile::Html, $options));
            }
        }
    }

    public function testPrecompiledParentAndTemplateInsideNoscriptKeepTheirOwnContexts(): void
    {
        $source = <<<'JS'
export default ({text}) => <main><section><p>Outside "quote" &gt; {text}</p></section>
  <noscript><div><p value="">Inside "quote" &gt; {text}</p></div></noscript>
  <section><p>After "quote" &gt; {text}</p></section></main>;
JS;
        $engine = new Engine();
        $engine->setJsxOptions(new JsxOptions(mode: JsxMode::Precompile, importSource: 'phasis'));
        $this->assertSame(
            '<main><section><p>Outside &quot;quote&quot; > &quot;></p></section>'
                . '<noscript><div><p value="">Inside "quote" &gt; "&gt;</p></div></noscript>'
                . '<section><p>After &quot;quote&quot; > &quot;></p></section></main>',
            $engine->renderSource($source, ['text' => '">'], 'default', null, RenderProfile::Html, new HtmlRenderOptions(['value'], true, true)),
        );
    }

    public function testPrecompiledSvgAttributesFollowRuntimeContext(): void
    {
        $source = <<<'JS'
async function Child() { await Promise.resolve(); return <a><a strokeWidth="2">Async</a></a>; }
function Links({empty}) {
  return <a><a strokeWidth="" fillRule="" strokeLinecap={empty} strokeDasharray="2 3" aria-label="">Link</a><Child/></a>;
}
export default ({empty}) => {
  return <main><Links empty={empty}/><svg><Links empty={empty}/>
    <foreignObject><Links empty={empty}/></foreignObject></svg></main>;
}
JS;
        $ordinary = new Engine();
        $precompiled = new Engine();
        $precompiled->setJsxOptions(new JsxOptions(mode: JsxMode::Precompile, importSource: 'phasis'));
        $this->assertSame(
            $ordinary->renderSource($source, ['empty' => ''], 'default', null, RenderProfile::Html),
            $precompiled->renderSource($source, ['empty' => ''], 'default', null, RenderProfile::Html),
        );
        foreach ([[], ['stroke-width', 'fill-rule', 'stroke-linecap'], ['strokeWidth', 'fillRule', 'strokeLinecap'], ['aria-label']] as $names) {
            foreach ([false, true] as $escape) {
                $options = new HtmlRenderOptions($names, $escape, true);
                foreach (['', 'round', null] as $value) {
                    $expected = $ordinary->renderSource($source, ['empty' => $value], 'default', null, RenderProfile::Html, $options);
                    $this->assertSame(
                        $expected,
                        $precompiled->renderSource($source, ['empty' => $value], 'default', null, RenderProfile::Html, $options),
                    );
                }
            }
        }
        $actual = $precompiled->renderSource(
            $source,
            ['empty' => ''],
            'default',
            null,
            RenderProfile::Html,
            new HtmlRenderOptions(['stroke-width', 'fill-rule', 'stroke-linecap']),
        );
        $this->assertStringContainsString('<a stroke-width="" fill-rule="" stroke-linecap="" stroke-dasharray="2 3"', $actual);
        $this->assertStringContainsString('<a strokeWidth fillRule strokeLinecap strokeDasharray="2 3"', $actual);
    }

    public function testOldPrecompiledTemplatesFailClosedInSvg(): void
    {
        $engine = new Engine();
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('precompiled static output from an older template');
        $engine->renderSource(
            "import { jsxTemplate } from 'phasis/jsx-precompile'; import { jsx } from 'phasis/jsx-runtime'; "
                . "const tpl = ['<a strokeWidth></a>', {p:'html', f:[0], s:[], a:[]}]; export default () => jsx('svg', {children:jsxTemplate(tpl)});",
            [],
            'default',
            null,
            RenderProfile::Html,
        );
    }
}
