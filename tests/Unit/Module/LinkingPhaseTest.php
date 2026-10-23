<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Module;

use Phasis\Engine;
use Phasis\Exceptions\JsThrowable;
use Phasis\Exceptions\SyntaxError;
use PHPUnit\Framework\TestCase;

final class LinkingPhaseTest extends TestCase
{
    use TempProject;

    public function testLinkingDefersDependencyAndEntryBodiesUntilEvaluation(): void
    {
        $source = "import { value } from './dependency.mjs';\n"
            . "globalThis.events.push('entry');\nexport const answer = value + 1;\n";
        $root = $this->project([
            'entry.mjs' => $source,
            'dependency.mjs' => "globalThis.events.push('dependency');\nexport const value = 41;\n",
        ]);
        $engine = new Engine();
        $engine->eval('globalThis.events = [];');
        $loader = $engine->getInterpreter()->getModuleLoader();
        $namespace = $loader->linkModuleGraph($root . '/entry.mjs', $source);
        self::assertSame('', $engine->eval('events.join()'));
        self::assertTrue($namespace->hasOwnProperty('answer'));
        self::assertFalse($loader->record($root . '/entry.mjs')->bodyEvaluated);
        self::assertFalse($loader->record($root . '/dependency.mjs')->bodyEvaluated);

        self::assertSame($namespace, $loader->evaluateModule($root . '/entry.mjs', $source));
        self::assertSame(42.0, $namespace->get('answer')->toNumber());
        self::assertSame('dependency,entry', $engine->eval('events.join()'));
        self::assertSame($namespace, $loader->evaluateModule($root . '/entry.mjs', $source));
        self::assertSame('dependency,entry', $engine->eval('events.join()'));
    }

    public function testEvaluationAfterLinkingPropagatesAndCachesTheRuntimeError(): void
    {
        $source = "throw new RangeError('module body');\nexport const value = 1;\n";
        $root = $this->project(['entry.mjs' => $source]);
        $engine = new Engine();
        $loader = $engine->getInterpreter()->getModuleLoader();
        $loader->linkModuleGraph($root . '/entry.mjs', $source);
        $caught = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $loader->evaluateModule($root . '/entry.mjs', $source);
                self::fail('The module body must throw when evaluated.');
            } catch (JsThrowable $error) {
                self::assertSame('RangeError', $error->jsValue->get('name')->toJsString());
                if ($caught !== null) {
                    self::assertSame($caught, $error->jsValue);
                }
                $caught = $error->jsValue;
            }
        }
    }

    public function testResolutionFailurePrecedesAnyDependencyBody(): void
    {
        $source = "import { missing } from './dependency.mjs';\nthrow new Error('entry ran');\n";
        $root = $this->project([
            'entry.mjs' => $source,
            'dependency.mjs' => "globalThis.dependencyRan = true;\nexport const present = 1;\n",
        ]);
        $engine = new Engine();
        $engine->eval('globalThis.dependencyRan = false;');
        try {
            $engine->getInterpreter()->getModuleLoader()->linkModuleGraph($root . '/entry.mjs', $source);
            self::fail('An unresolved import must fail during linking.');
        } catch (SyntaxError $error) {
            self::assertStringContainsString("does not export 'missing'", $error->getMessage());
        }
        self::assertFalse($engine->eval('dependencyRan'));
    }

    public function testParseFailureRestoresLinkingStateForTheNextGraph(): void
    {
        $source = 'export const broken = ;';
        $root = $this->project([
            'broken.mjs' => $source,
            'valid.mjs' => 'globalThis.validRan = true; export const value = 1;',
        ]);
        $engine = new Engine();
        $loader = $engine->getInterpreter()->getModuleLoader();
        try {
            $loader->linkModuleGraph($root . '/broken.mjs', $source);
            self::fail('Malformed module source must fail during linking.');
        } catch (SyntaxError) {
        }
        $loader->loadModule($root . '/valid.mjs');
        self::assertTrue($engine->eval('validRan'));
    }

    public function testNamedDefaultAndNestedVarExportsSurviveEvaluation(): void
    {
        $source = 'export default function value() { return 42; } '
            . 'export { value, nested }; if (true) { var nested = 7; }';
        $root = $this->project(['entry.mjs' => $source]);
        $engine = new Engine();
        $namespace = $engine->getInterpreter()->getModuleLoader()->evaluateModule($root . '/entry.mjs', $source);
        self::assertSame($namespace->get('default'), $namespace->get('value'));
        self::assertSame(7.0, $namespace->get('nested')->toNumber());
    }
}
