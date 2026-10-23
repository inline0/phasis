<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Module;

use Phasis\Engine;
use Phasis\Runtime\Environment;
use Phasis\Value\JsNumber;
use Phasis\Value\JsUndefined;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VariableScopeTest extends TestCase
{
    use TempProject;

    public function testModuleVarHoistingCreatesOwnBindingsBeforeEvaluation(): void
    {
        $source = 'export var shared; globalThis.before = shared; shared = "module"; '
            . 'export var lexical = "module lexical"; if (true) { var nested = "module block"; } export { nested };';
        $root = $this->project(['entry.mjs' => $source]);
        $engine = new Engine();
        $engine->eval('var shared = "global"; let lexical = "global lexical"; var nested = "global block";');
        $loader = $engine->getInterpreter()->getModuleLoader();
        $namespace = $loader->linkModuleGraph($root . '/entry.mjs', $source);

        self::assertTrue($loader->record($root . '/entry.mjs')->environment->hasOwnBinding('shared'));
        self::assertInstanceOf(JsUndefined::class, $namespace->get('shared'));
        $loader->evaluateModule($root . '/entry.mjs', $source);
        self::assertSame('module', $namespace->get('shared')->toJsString());
        self::assertSame('module lexical', $namespace->get('lexical')->toJsString());
        self::assertSame('module block', $namespace->get('nested')->toJsString());
        self::assertSame('global,global lexical,global block', $engine->eval('[shared, lexical, nested].join()'));
        self::assertSame('undefined', $engine->eval('typeof before'));
    }

    public function testIndependentModulesKeepBlockVarsAndClosureUpdatesLocal(): void
    {
        $root = $this->project([
            'first.mjs' => 'var count = 1; { var value = 2; } export { count, value }; '
                . 'export function bump() { count++; return count; }',
            'second.mjs' => 'var count = 10; { var value = 20; } export { count, value };',
            'entry.mjs' => 'import * as first from "./first.mjs"; import * as second from "./second.mjs"; '
                . 'first.bump(); export const result = [first.count, first.value, second.count, second.value].join();',
        ]);
        $engine = new Engine();
        $engine->eval('var count = 100; var value = 200;');
        $namespace = $engine->getInterpreter()->getModuleLoader()->loadModule($root . '/entry.mjs');
        self::assertSame('2,2,10,20', $namespace->get('result')->toJsString());
        self::assertSame('100,200', $engine->eval('[count, value].join()'));
    }

    #[DataProvider('modulePrefixes')]
    public function testModuleEvalKeepsGlobalContextRestrictionsAndOwnVarScope(string $prefix): void
    {
        $source = $prefix . 'export var caught; try { eval("new.target"); } catch (error) { caught = error.name; } '
            . 'eval("var hidden = 1;"); export const hiddenType = typeof hidden; export const receiver = eval("this");';
        $root = $this->project(['entry.mjs' => $source]);
        $engine = new Engine();
        $namespace = $engine->getInterpreter()->getModuleLoader()->loadModule($root . '/entry.mjs');
        self::assertSame('SyntaxError', $namespace->get('caught')->toJsString());
        self::assertSame('undefined', $namespace->get('hiddenType')->toJsString());
        self::assertInstanceOf(JsUndefined::class, $namespace->get('receiver'));
    }

    /** @return iterable<string, array{string}> */
    public static function modulePrefixes(): iterable
    {
        yield 'synchronous module' => [''];
        yield 'top-level await module' => ['await Promise.resolve(); '];
    }

    public function testModuleBoundaryStopsVarAndLexicalScopeWalksWithoutBecomingAFunction(): void
    {
        $global = new Environment();
        $global->defineLet('outer', new JsNumber(1));
        $module = $global->createChild();
        $module->markAsModuleEnvironment();
        $module->defineLet('inner', new JsNumber(2));
        $block = $module->createChild();
        self::assertSame($module, $block->getVariableEnvironment());
        self::assertNull($block->getEnclosingNonArrowFunctionKind());
        self::assertFalse($block->hasLexicalBindingInScope('outer'));
        self::assertTrue($block->hasLexicalBindingInScope('inner'));

        $function = $block->createChild();
        $function->setFunctionKind('function');
        self::assertSame($function, $function->getVariableEnvironment());
        self::assertSame('function', $function->getEnclosingNonArrowFunctionKind());
    }
}
