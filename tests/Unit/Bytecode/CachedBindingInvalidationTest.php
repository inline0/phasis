<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Ast\Declaration\FunctionDeclaration;
use Phasis\Bytecode\Compiler;
use Phasis\Bytecode\Bailout;
use Phasis\Bytecode\Frame;
use Phasis\Bytecode\JsToPhp;
use Phasis\Bytecode\Op;
use Phasis\Bytecode\VM;
use Phasis\Engine;
use Phasis\Parser\Parser;
use Phasis\Runtime\Environment;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsUndefined;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CachedBindingInvalidationTest extends TestCase
{
    public function testImportFallbackDoesNotRepeatTheResolver(): void
    {
        $engine = new Engine();
        $scope = $engine->getGlobalEnv()->createChild();
        $calls = 0;
        $scope->defineImportBinding('current', static function () use (&$calls): JsNumber {
            return JsNumber::of(++$calls);
        });
        $read = $this->reader($engine, $scope, 'php', true);
        self::assertSame(1.0, $read());
        self::assertSame(2.0, $read());
        self::assertSame(2, $calls);
    }

    /** @return iterable<string, array{string, string}> */
    public static function mutations(): iterable
    {
        foreach (['bytecode', 'php'] as $tier) {
            $mutations = ['replace-deletable', 'shadow-deletable', 'delete-deletable', 'shadow-annex-b', 'shadow-import', 'replace-global'];
            foreach ($mutations as $mutation) {
                yield $tier . '-' . $mutation => [$tier, $mutation];
            }
        }
    }

    #[DataProvider('mutations')]
    public function testCachedReadsObserveHostBindingMutations(string $tier, string $mutation): void
    {
        $engine = new Engine();
        $parent = $engine->getGlobalEnv()->createChild();
        $parent->defineVar('current', JsNumber::of(2));
        $scope = $parent->createChild();
        if ($mutation === 'replace-global') {
            $scope = $engine->getGlobalEnv();
            $scope->defineGlobalVar('current', JsNumber::of(1));
        } elseif (in_array($mutation, ['replace-deletable', 'delete-deletable'], true)) {
            $scope->defineDeletable('current', JsNumber::of(1));
        } else {
            $parent->set('current', JsNumber::of(1));
        }
        $read = $this->reader($engine, $scope, $tier, $mutation === 'shadow-import');
        self::assertSame(1.0, $read());
        switch ($mutation) {
            case 'replace-deletable':
            case 'shadow-deletable':
                $scope->defineDeletable('current', JsNumber::of(2));
                break;
            case 'delete-deletable':
                self::assertTrue($scope->deleteBinding('current'));
                break;
            case 'shadow-annex-b':
                $scope->defineAnnexBVar('current', JsNumber::of(2));
                break;
            case 'shadow-import':
                $scope->defineImportBinding('current', static fn (): JsNumber => JsNumber::of(2));
                break;
            case 'replace-global':
                $scope->defineGlobalVar('current', JsNumber::of(2));
                break;
        }
        self::assertSame(2.0, $scope->get('current')->value);
        self::assertSame(2.0, $read());
    }

    /** @return \Closure(): float */
    private function reader(Engine $engine, Environment $scope, string $tier, bool $importFallback = false): \Closure
    {
        // Activation setup must not incidentally invalidate the warmed binding cache.
        $declaration = (new Parser('function read() { return current + 0; }'))->parse()->body[0];
        self::assertInstanceOf(FunctionDeclaration::class, $declaration);
        $function = new JsFunction('read', $declaration->params, $declaration->body, $scope);
        $undefined = JsUndefined::instance();
        if ($tier === 'php') {
            $compiled = JsToPhp::compile($function);
            self::assertInstanceOf(\Closure::class, $compiled);
            return static function () use ($compiled, $undefined, $scope, $engine, $function, $importFallback): float {
                try {
                    return $compiled([], $undefined, $scope, $engine->getInterpreter(), [])->value;
                } catch (Bailout $error) {
                    if (!$importFallback) {
                        throw $error;
                    }
                    self::assertNull($scope->getDataBinding('current'));
                    return $engine->getInterpreter()->callFunction($function, $undefined, [])->value;
                }
            };
        }
        $compiled = (new Compiler())->compile($function);
        self::assertContains(Op::LOAD_NAME, $compiled->code);
        $vm = new VM($engine->getInterpreter());
        return static fn (): float => $vm->execute(
            $compiled,
            new Frame($scope, $undefined, $compiled->slotCount, $undefined),
        )->value;
    }
}
