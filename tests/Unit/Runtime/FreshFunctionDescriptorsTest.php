<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FreshFunctionDescriptorsTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function modes(): iterable
    {
        yield 'ordinary' => [false];
        yield 'budgeted' => [true];
    }

    #[DataProvider('modes')]
    public function testFunctionMetadataMatchesNode(bool $budgeted): void
    {
        $engine = new Engine();
        if ($budgeted) {
            $engine->setExecutionBudget(10);
        }
        $source = file_get_contents(__DIR__ . '/fixtures/fresh-function-descriptors.js');
        $oracle = file_get_contents(__DIR__ . '/fixtures/fresh-function-descriptors.expected.json');
        self::assertIsString($source);
        self::assertIsString($oracle);
        $engine->eval($source);
        $actual = $engine->eval('JSON.stringify(freshFunctionDescriptors)');
        self::assertIsString($actual);
        self::assertSame(
            self::comparableMetadata(json_decode($oracle, true, flags: JSON_THROW_ON_ERROR)),
            self::comparableMetadata(json_decode($actual, true, flags: JSON_THROW_ON_ERROR)),
        );
    }

    /**
     * Full Annex B own-key differences remain in the raw Node and baseline
     * snapshots. This oracle assertion covers metadata and its relative order.
     */
    private static function comparableMetadata(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        unset($value['keys']);
        foreach ($value as $key => $item) {
            $value[$key] = self::comparableMetadata($item);
        }
        return $value;
    }

    public function testInitialDescriptorsHaveIndependentState(): void
    {
        $first = new JsFunction('first', [null, null], null, new Environment());
        $second = new JsFunction('second', [null, null], null, new Environment());
        self::assertSame(['length', 'name'], $first->getOwnPropertyNames());
        $firstLength = $first->getOwnPropertyDescriptor('length');
        $secondLength = $second->getOwnPropertyDescriptor('length');
        $firstName = $first->getOwnPropertyDescriptor('name');
        $secondName = $second->getOwnPropertyDescriptor('name');
        self::assertNotNull($firstLength);
        self::assertNotNull($secondLength);
        self::assertNotNull($firstName);
        self::assertNotNull($secondName);
        self::assertNotSame($firstLength, $secondLength);
        self::assertNotSame($firstName, $secondName);
        self::assertFalse($firstLength->writable);
        self::assertFalse($firstLength->enumerable);
        self::assertTrue($firstLength->configurable);
        $firstLength->value = JsNumber::of(19);
        $firstName->configurable = false;
        self::assertSame(19.0, $first->get('length')->toNumber());
        self::assertFalse($first->getOwnPropertyDescriptor('name')?->configurable);
        self::assertSame(2.0, $second->get('length')->toNumber());
        self::assertTrue($secondName->configurable);
        self::assertSame('second', TypeConversion::toString($second->get('name')));
    }

    public function testHostSubclassDefinitionHooksKeepTheirOrderAndEffects(): void
    {
        $function = new class ('provided', [null, null], null, new Environment()) extends JsFunction {
            /** @var list<array{string, list<string>}> */
            public array $observations = [];

            public function defineOwnProperty(string $name, PropertyDescriptor $descriptor): bool
            {
                $this->observations[] = [$name, $this->getOwnPropertyNames()];
                if ($name === 'length') {
                    $descriptor->value = JsNumber::of(7);
                    parent::defineOwnProperty('name', PropertyDescriptor::data(
                        new JsString('host'),
                        false,
                        false,
                        false,
                    ));
                }
                return parent::defineOwnProperty($name, $descriptor);
            }
        };
        self::assertSame([['length', []], ['name', ['name', 'length']]], $function->observations);
        self::assertSame(['name', 'length'], $function->getOwnPropertyNames());
        self::assertSame(7.0, $function->get('length')->toNumber());
        self::assertSame('host', TypeConversion::toString($function->get('name')));
        self::assertFalse($function->getOwnPropertyDescriptor('name')?->configurable);
    }

    #[DataProvider('modes')]
    public function testSiblingAndForeignConstructorsKeepTheirRealm(bool $budgeted): void
    {
        $foreign = new Engine();
        if ($budgeted) {
            $foreign->setExecutionBudget(10);
        }
        $foreign->eval(<<<'JS'
var foreignFunctionConstructor = Function;
var foreignFunctionPrototype = Function.prototype;
var foreignObjectPrototype = Object.prototype;
JS);
        $caller = new Engine();
        if ($budgeted) {
            $caller->setExecutionBudget(10);
        }
        self::assertSame([true, true, true, 2, 1, 1, 'named', 'arrow', 'anonymous'], $foreign->eval(<<<'JS'
function named(a, b) {}
var arrow = a => a;
var dynamic = Function('a', 'b = 1', 'return a + b');
[Object.getPrototypeOf(named) === foreignFunctionPrototype,
 Object.getPrototypeOf(arrow) === foreignFunctionPrototype,
 Object.getPrototypeOf(dynamic.prototype) === foreignObjectPrototype,
 named.length, arrow.length, dynamic.length, named.name, arrow.name, dynamic.name];
JS));
        foreach (['foreignFunctionConstructor', 'foreignFunctionPrototype', 'foreignObjectPrototype'] as $name) {
            $caller->getGlobalEnv()->defineVar($name, $foreign->getGlobalEnv()->get($name));
        }
        self::assertSame([true, true, 1, 'anonymous', ['length', 'name']], $caller->eval(<<<'JS'
var foreignCreated = foreignFunctionConstructor('a', 'b = 1', 'return a + b');
[Object.getPrototypeOf(foreignCreated) === foreignFunctionPrototype,
 Object.getPrototypeOf(foreignCreated.prototype) === foreignObjectPrototype,
 foreignCreated.length, foreignCreated.name,
 Object.getOwnPropertyNames(foreignCreated).filter(key => key === 'length' || key === 'name')];
JS));
    }
}
