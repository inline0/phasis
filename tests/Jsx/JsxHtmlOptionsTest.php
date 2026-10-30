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

    public function testPrecompiledStaticAttributesFailClosedWhenOptionsAreSet(): void
    {
        $engine = new Engine();
        $engine->setJsxOptions(new JsxOptions(mode: JsxMode::Precompile, importSource: 'phasis'));
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('HTML render options cannot change precompiled static attributes');
        $engine->renderSource(
            'export default () => <div><p value="">All</p></div>',
            [],
            'default',
            null,
            RenderProfile::Html,
            new HtmlRenderOptions(['value']),
        );
    }
}
