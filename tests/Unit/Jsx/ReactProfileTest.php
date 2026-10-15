<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Jsx;

use Phasis\Engine;
use Phasis\Exceptions\JsThrowable;
use Phasis\Exceptions\RuntimeError;
use PHPUnit\Framework\TestCase;

final class ReactProfileTest extends TestCase
{
    public function testRenderSourceWithPhpProps(): void
    {
        $engine = new Engine();
        $html = $engine->renderSource(
            "export default function Hi({ name, tags }) { return <p title={name}>Hi {name}: {tags.join(', ')}</p>; }",
            ['name' => 'Ada & <Co>', 'tags' => ['a', 'b']],
        );
        $this->assertSame('<p title="Ada &amp; &lt;Co&gt;">Hi <!-- -->Ada &amp; &lt;Co&gt;<!-- -->: <!-- -->a, b</p>', $html);
    }

    public function testNamedExportAndWaitingForAsyncComponents(): void
    {
        $engine = new Engine();
        $html = $engine->renderSource(
            "export async function Late() { const v = await new Promise((r) => setTimeout(() => r('done'), 5)); return <b>{v}</b>; }",
            [],
            'Late',
        );
        $this->assertSame('<b>done</b>', $html);
    }

    public function testRenderErrorsRethrowAsJsThrowable(): void
    {
        $engine = new Engine();
        try {
            $engine->renderSource("export default function Boom() { throw new RangeError('nope'); }");
            $this->fail('render should throw');
        } catch (JsThrowable $e) {
            $this->assertSame('RangeError: nope', $e->getMessage());
        }
    }

    public function testRenderThatNeverSettlesFailsFast(): void
    {
        $engine = new Engine();
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('render did not settle');
        $engine->renderSource(
            "import { use } from 'react';\nconst never = new Promise(() => {});\n"
            . 'export default function Wait() { return <p>{use(never)}</p>; }',
        );
    }

    public function testHooksOutsideRenderThrowLikeReact(): void
    {
        $engine = new Engine();
        $engine->evalAsModule(
            "import { useState } from 'react';\ntry { useState(0); } catch (e) { console.log(e.constructor.name, e.message); }",
            (string) getcwd() . '/__hooks_outside.mjs',
        );
        $this->assertSame("TypeError Cannot read properties of null (reading 'useState')", $engine->getConsoleOutput());
    }

    public function testStreamingApisThrowANamedTypeError(): void
    {
        $engine = new Engine();
        $engine->evalAsModule(
            "import { renderToReadableStream } from 'react-dom/server';\n"
            . 'try { renderToReadableStream(null); } catch (e) { console.log(e.constructor.name, e.message.slice(0, 51)); }',
            (string) getcwd() . '/__streaming.mjs',
        );
        $this->assertSame('TypeError renderToReadableStream() is not supported by Phasis', $engine->getConsoleOutput());
    }

    public function testJavascriptUrlsAreBlocked(): void
    {
        $html = (new Engine())->renderSource("export default () => <a href={'javascript:alert(1)'}>x</a>;");
        $this->assertSame(
            '<a href="javascript:throw new Error(&#x27;React has blocked a javascript: URL as a security precaution.&#x27;)">x</a>',
            $html,
        );
    }
}
