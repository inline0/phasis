<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Parser;

use Phasis\Ast\Program;
use Phasis\Exceptions\SyntaxError;
use Phasis\Parser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleEarlyErrorsTest extends TestCase
{
    #[DataProvider('invalidModules')]
    public function testRejectsEarlyErrorsWithoutLinkingOrEvaluation(string $source): void
    {
        $this->expectException(SyntaxError::class);
        Parser::parseSource($source, 'module');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidModules(): iterable
    {
        yield 'duplicate functions' => ['function f() {} function f() {}'];
        yield 'default function conflicts with class' => ['class f {} export default function* f() {}'];
        yield 'nested var conflicts with lexical' => ['let x; if (false) { var x; }'];
        yield 'import conflicts with function' => ['import { x } from "dependency"; function x() {}'];
        yield 'duplicate import locals' => ['import { a as x, b as x } from "dependency";'];
        yield 'unknown exported binding' => ['export { missing };'];
        yield 'nested lexical is not module binding' => ['{ let x; } export { x };'];
        yield 'duplicate exported name' => ['let a, b; export { a as value, b as value };'];
        yield 'top-level new.target' => ['new.target;'];
        yield 'arrow inherits module new.target scope' => ['const f = () => new.target;'];
        yield 'class computed key uses module scope' => ['class C { [new.target]() {} }'];
        yield 'private access outside a class' => ['const f = value => value.#missing;'];
        yield 'private access in a class without declaration' => ['class C { get(value) { return value.#missing; } }'];
        yield 'heritage excludes own private names' => ['class C extends value.#own { #own; }'];
        yield 'return in a top-level nested block' => ['if (false) { return; }'];
        yield 'super at module scope' => ['super.value;'];
        yield 'import name with high surrogate' => ['import { "\uD83D" as value } from "dep";'];
        yield 'import name with low surrogate' => ['import { "\uDCA9" as value } from "dep";'];
        yield 're-export source name with surrogate' => ['export { "\uD83D" as value } from "dep";'];
        yield 'exported name with surrogate' => ['let value; export { value as "\uDCA9" };'];
        yield 'namespace export name with surrogate' => ['export * as "\uD83D" from "dep";'];
    }

    #[DataProvider('validModules')]
    public function testKeepsValidBindingAndReferenceScopes(string $source): void
    {
        self::assertInstanceOf(Program::class, Parser::parseSource($source, 'module'));
    }

    /** @return iterable<string, array{string}> */
    public static function validModules(): iterable
    {
        yield 'named default has independent named export' => ['export default function f() {} export { f };'];
        yield 'named default does not export local name' => ['export default function f() {} export { x as f } from "dep";'];
        yield 'nested var is exportable' => ['export { x }; if (false) { var x; }'];
        yield 'import is exportable' => ['import { x } from "dependency"; export { x };'];
        yield 'duplicate var names are allowed' => ['var x; var x; export { x };'];
        yield 'nested functions have separate bindings' => ['function outer() { var x; } let x;'];
        yield 'new.target inside a function and its arrow' => ['function f() { return () => new.target; }'];
        yield 'class initializer has its own new.target' => ['class C { field = new.target; static { new.target; } }'];
        yield 'private names can be declared later' => ['class C { get() { return this.#x; } #x; }'];
        yield 'nested class sees enclosing private names' => ['class C { #x; factory() { return class { get(v) { return v.#x; } }; } }'];
        yield 'arrow return is local' => ['const f = () => { return 1; };'];
        yield 'super IdentifierName is not a reference' => ['const value = { super: 1 }; value.super;'];
        yield 'method super has its own context' => ['const value = { method() { return super.value; } };'];
        yield 'import supplementary code point' => ['import { "\u{1F4A9}" as value } from "dep";'];
        yield 'import surrogate pair' => ['import { "\uD83D\uDCA9" as value } from "dep";'];
        yield 'import separately escaped surrogate pair' => ['import { "\u{D83D}\u{DCA9}" as value } from "dep";'];
        yield 're-export well-formed name' => ['export { "\u{D83D}\u{DCA9}" as value } from "dep";'];
        yield 'namespace export well-formed name' => ['export * as "\uD83D\uDCA9" from "dep";'];
    }
}
