<?php

declare(strict_types=1);

namespace Phasis\Bytecode;

/**
 * Internal exception used by JsToPhp's emitter to abort compilation
 * cleanly when it encounters an AST node it cannot lower to native
 * PHP. Caller catches and falls back to the VM. Kept distinct from
 * CompilerBailout so the bytecode compiler's bailout signal doesn't
 * accidentally short-circuit the JsToPhp pipeline.
 */
class Bailout extends \Exception
{
}
