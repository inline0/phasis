<?php

declare(strict_types=1);

namespace PhpJs\Bytecode;

/**
 * Thrown by the compiler when it encounters an AST shape it does
 * not yet handle. JsFunction catches this on the lazy-compile path
 * and falls back to the tree-walker for that body. The reason
 * string is for diagnostics only — flipping `compileFailed = true`
 * is the actual signal.
 */
final class CompilerBailout extends \RuntimeException
{
}
