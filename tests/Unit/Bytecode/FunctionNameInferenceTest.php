<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class FunctionNameInferenceTest extends TestCase
{
    public function testAnonymousAssignmentsCreateTheInferredNameDescriptor(): void
    {
        foreach (['', '"use strict";'] as $directive) {
            foreach (['() => {}', 'function () {}', '(function () {})'] as $expression) {
                $engine = new Engine();
                self::assertSame([
                    'value' => 'assigned',
                    'writable' => false,
                    'enumerable' => false,
                    'configurable' => true,
                ], $engine->eval($directive . "\nvar assigned; assigned = " . $expression . ";\n"
                    . "Object.getOwnPropertyDescriptor(assigned, 'name');"));
            }
        }
    }

    public function testObjectValuesInferStringAndSymbolNamesWithoutRenamingNamedFunctions(): void
    {
        foreach (['', '"use strict";'] as $directive) {
            $engine = new Engine();
            self::assertSame('id|[key]||original|false|false|true', $engine->eval($directive . <<<'JS'

var named = Symbol('key');
var unnamed = Symbol();
var object;
object = {
    id: () => {},
    [named]: function () {},
    [unnamed]: (function () {}),
    explicit: function original() {}
};
var descriptor = Object.getOwnPropertyDescriptor(object.id, 'name');
[object.id.name, object[named].name, object[unnamed].name, object.explicit.name,
 descriptor.writable, descriptor.enumerable, descriptor.configurable].join('|');
JS));
        }
    }
}
