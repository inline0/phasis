<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Jsx;

use Phasis\Engine;
use Phasis\RenderProfile;
use PHPUnit\Framework\TestCase;

final class HtmlProfileTest extends TestCase
{
    public function testImportSourcePragmaPicksTheHtmlProfile(): void
    {
        $html = (new Engine())->renderSource(
            "/** @jsxImportSource phasis */\nexport default ({ name }) => <p class=\"hi\">Hi {name}<br /></p>;",
            ['name' => 'Ada'],
        );
        $this->assertSame('<p class="hi">Hi Ada<br/></p>', $html);
    }

    public function testRawInsertsTrustedHtml(): void
    {
        $html = (new Engine())->renderSource(
            "/** @jsxImportSource phasis */\nimport { raw } from 'phasis';\n"
            . "export default function Newsletter({ name, articleHtml }) {\n"
            . "  return <table class=\"newsletter\"><tr><td>Hello {name}</td></tr><tr><td>{raw(articleHtml)}</td></tr></table>;\n"
            . '}',
            ['name' => '<Ada>', 'articleHtml' => '<p>Trusted <b>HTML</b></p>'],
        );
        $this->assertSame(
            '<table class="newsletter"><tr><td>Hello &lt;Ada></td></tr><tr><td><p>Trusted <b>HTML</b></p></td></tr></table>',
            $html,
        );
    }

    public function testEngineRenderAwaitsAsyncComponents(): void
    {
        $html = (new Engine())->renderSource(
            "/** @jsxImportSource phasis */\n"
            . "async function Late() { const v = await new Promise((r) => setTimeout(() => r('later'), 5)); return <b>{v}</b>; }\n"
            . 'export default () => <div><Late /></div>;',
        );
        $this->assertSame('<div><b>later</b></div>', $html);
    }

    public function testProfileCanBeForced(): void
    {
        $source = 'export default () => <p>x{1}y</p>;';
        $engine = new Engine();
        $this->assertSame('<p>x<!-- -->1<!-- -->y</p>', $engine->renderSource($source, [], 'default', null, RenderProfile::React));
        $this->assertSame('<p>x1y</p>', (new Engine())->renderSource($source, [], 'default', null, RenderProfile::Html));
    }

    public function testNoUrlFiltering(): void
    {
        $html = (new Engine())->renderSource("/** @jsxImportSource phasis */\nexport default () => <a href=\"javascript:x()\">x</a>;");
        $this->assertSame('<a href="javascript:x()">x</a>', $html);
    }
}
