<?php

declare(strict_types=1);

namespace Phasis\Tests\Jsx;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/**
 * The PRD's Goals examples, end to end through Engine::render(): PostCard
 * with its imports (CommonJS classnames, a lucide-react icon, an alias, a
 * CSS import, an asset), CommentsToggle with useId and useState, and an
 * async Comments component whose fetch() is served by a mock transport.
 *
 * The fixture project in tests/Jsx/oracle/project, bundled by esbuild like
 * a client build and rendered with react-dom@19.3.1's prerender in Node,
 * is the oracle (`node tests/Jsx/oracle/jsx-oracle.mjs project`).
 */
final class JsxGoalsTest extends TestCase
{
    private const PROJECT = __DIR__ . '/oracle/project';

    public function testProjectRendersLikeTheBundledClientBuild(): void
    {
        if (!is_dir(__DIR__ . '/oracle/node_modules')) {
            $this->markTestSkipped('Run `npm ci` in tests/Jsx/oracle to install the pinned packages.');
        }
        $engine = new Engine();
        $engine->setAssetResolver(static fn (string $file): string => '/assets/' . basename($file));
        $comments = (string) file_get_contents(self::PROJECT . '/comments.json');
        $requested = [];
        $engine->setFetchTransport(static function (array $request) use ($comments, &$requested): array {
            $requested[] = $request['url'];
            return [
                'status' => 200,
                'statusText' => 'OK',
                'headers' => [['content-type', 'application/json']],
                'body' => $comments,
            ];
        });
        $props = json_decode((string) file_get_contents(self::PROJECT . '/props.json'), true);
        $html = $engine->render(self::PROJECT . '/Page.jsx', $props);
        $this->assertSame(rtrim((string) file_get_contents(self::PROJECT . '/expected.html'), "\n"), $html);
        $this->assertContains('https://api.example.com/posts/42/comments', $requested);
    }

    public function testPostCardRendersFromPhpProps(): void
    {
        if (!is_dir(__DIR__ . '/oracle/node_modules')) {
            $this->markTestSkipped('Run `npm ci` in tests/Jsx/oracle to install the pinned packages.');
        }
        $engine = new Engine();
        $engine->setAssetResolver(static fn (string $file): string => '/assets/' . basename($file));
        $post = ['title' => 'Hello', 'excerpt' => 'World', 'author' => 'Grace Hopper', 'featured' => false, 'commentCount' => 0];
        $html = $engine->render(self::PROJECT . '/components/PostCard.jsx', ['post' => $post]);
        $this->assertStringStartsWith('<link rel="preload" as="image" href="/assets/avatar.svg"/><article class="post-card">', $html);
        $this->assertStringContainsString('<figcaption>GH</figcaption>', $html);
        $this->assertStringContainsString('class="lucide lucide-message-circle"', $html);
    }

    public function testAssetImportWithoutResolverThrows(): void
    {
        if (!is_dir(__DIR__ . '/oracle/node_modules')) {
            $this->markTestSkipped('Run `npm ci` in tests/Jsx/oracle to install the pinned packages.');
        }
        $this->expectExceptionMessageMatches('/avatar\.svg/');
        (new Engine())->render(self::PROJECT . '/components/Avatar/index.jsx', ['name' => 'X']);
    }
}
