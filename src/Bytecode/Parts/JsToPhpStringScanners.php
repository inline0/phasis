<?php

declare(strict_types=1);

namespace Phasis\Bytecode\Parts;

use Phasis\Ast\Expression\CallExpression;
use Phasis\Ast\Expression\Identifier;
use Phasis\Ast\Expression\MemberExpression;
use Phasis\Ast\Node;
use Phasis\Bytecode\Bailout;
use Phasis\Bytecode\StringScannerPlan;

/** Typed string loops share the numeric emitter after a side-effect-free entry proof. */
trait JsToPhpStringScanners
{
    private ?StringScannerPlan $scannerPlan = null;

    /** @var array<string, string> Numeric predicate parameters substituted by already evaluated temporaries. */
    private array $scannerSubstitutions = [];

    /** @var ?\WeakMap<Node, array<string, \Closure>> */
    private static ?\WeakMap $scannerFactories = null;

    private function emitScannerGuards(): void
    {
        if ($this->scannerPlan === null) {
            return;
        }
        $this->emitLine('$_scanner_context = \\Phasis\\Engine::getCurrentInterpreter();');
        $this->emitLine('if ($interp->hasExecutionBudget() || $_scanner_context?->hasExecutionBudget()'
            . ' || $scannerRealm->get() !== $interp->getRealm() || $_scanner_context?->getRealm() !== $interp->getRealm()) {'
            . ' throw new \\Phasis\\Bytecode\\Bailout("scanner context changed"); }');
        $frames = $this->scannerPlan->predicates === [] ? 1 : 2;
        $this->emitLine('if (!$interp->hasPureCallCapacity(' . $frames . ')) {'
            . ' throw new \\Phasis\\Bytecode\\Bailout("scanner call depth"); }');
        if ($this->scannerPlan->usesCharacterRead) {
            $this->emitLine('if (!$interp->hasCachedStringIntrinsic("charCodeAt", "string.charCodeAt")) {'
                . ' throw new \\Phasis\\Bytecode\\Bailout("scanner intrinsic changed"); }');
        }
        if ($this->scannerPlan->predicates === []) {
            return;
        }
        $index = 0;
        foreach ($this->scannerPlan->predicates as $name => $_) {
            $this->emitLine('$_scanner_binding = $env->findBindingDepth(' . var_export($name, true) . ');');
            $this->emitLine('if ($_scanner_binding === null || $_scanner_binding[0] !== $scannerGuards[' . $index . ']->get()'
                . ' || !$_scanner_binding[0]->getClosure()->hasPlainChain()) {'
                . ' throw new \\Phasis\\Bytecode\\Bailout("scanner predicate changed"); }');
            ++$index;
        }
    }

    private function emitScannerStringParameter(string $name, int $index): void
    {
        $variable = $this->slotVar($name);
        $this->emitLine($variable . ' = $args[' . $index . '] ?? null;');
        $this->emitLine('if (!(' . $variable . ' instanceof \\Phasis\\Value\\JsString)'
            . ' || get_class(' . $variable . ') !== \\Phasis\\Value\\JsString::class || !' . $variable . '->isAscii()) {'
            . ' throw new \\Phasis\\Bytecode\\Bailout("scanner string assumption"); }');
        $this->emitLine($variable . ' = ' . $variable . '->value;');
    }

    private function scannerCallType(CallExpression $expression): ?string
    {
        if (
            $this->loopRegion !== null && $expression->callee instanceof MemberExpression
            && !$expression->callee->computed && !$expression->callee->optional
            && $expression->callee->object instanceof Identifier && $expression->callee->property instanceof Identifier
            && ($this->loopRegion->bindingTypes[$expression->callee->object->name] ?? null) === 'string'
            && $expression->callee->property->name === 'charCodeAt'
        ) {
            return 'numeric';
        }
        if ($this->scannerPlan === null) {
            return null;
        }
        if ($expression->callee instanceof Identifier && isset($this->scannerPlan->predicates[$expression->callee->name])) {
            return 'boolean';
        }
        if (
            $expression->callee instanceof MemberExpression && $expression->callee->object instanceof Identifier
            && isset($this->scannerPlan->stringParameters[$expression->callee->object->name])
            && $expression->callee->property instanceof Identifier && $expression->callee->property->name === 'charCodeAt'
        ) {
            return 'numeric';
        }
        return null;
    }

    private function emitScannerCall(CallExpression $expression): ?string
    {
        $type = $this->scannerCallType($expression);
        if ($type === null || ($this->scannerPlan === null && $this->loopRegion === null)) {
            return null;
        }
        if ($type === 'numeric' && $expression->callee instanceof MemberExpression) {
            $receiver = $expression->callee->object;
            if (!$receiver instanceof Identifier) {
                throw new Bailout('scanner receiver is not a local');
            }
            $position = $this->newTemp('scanner_pos');
            $value = $this->newTemp('scanner_code');
            $index = $this->emitExpression($expression->arguments[0]);
            $string = $this->slotVar($receiver->name);
            $this->pendingStatements[] = $position . ' = ' . $index . ';';
            $this->pendingStatements[] = $position . ' = is_nan(' . $position . ') ? 0.0 : ('
                . $position . ' < 0 ? ceil(' . $position . ') : floor(' . $position . '));';
            $this->pendingStatements[] = $value . ' = ' . $position . ' < 0 || ' . $position . ' >= strlen(' . $string . ')'
                . ' ? NAN : (float) ord(' . $string . '[(int) ' . $position . ']);';
            return $value;
        }
        if (!$expression->callee instanceof Identifier || $this->scannerPlan === null) {
            throw new Bailout('scanner predicate is not a named binding');
        }
        $predicate = $this->scannerPlan->predicates[$expression->callee->name];
        $arguments = [];
        foreach ($expression->arguments as $argument) {
            $value = $this->emitExpression($argument);
            $temporary = $this->newTemp('scanner_arg');
            $this->pendingStatements[] = $temporary . ' = ' . $value . ';';
            $arguments[] = $temporary;
        }
        $saved = $this->scannerSubstitutions;
        $this->scannerSubstitutions = array_combine($predicate['parameters'], $arguments);
        try {
            return $this->emitExpression($predicate['body']);
        } finally {
            $this->scannerSubstitutions = $saved;
        }
    }

    /** Compile shared PHP code without retaining any function, realm or lexical environment. */
    private static function scannerClosureFactory(Node $body, string $source): ?\Closure
    {
        self::$scannerFactories ??= new \WeakMap();
        $key = hash('sha256', $source);
        $entries = self::$scannerFactories[$body] ?? [];
        if (isset($entries[$key])) {
            return $entries[$key];
        }
        try {
            $factory = eval('return static function ($scannerGuards, $scannerRealm) { ' . $source . ' };');
        } catch (\Throwable) {
            return null;
        }
        if (!$factory instanceof \Closure) {
            return null;
        }
        if (count($entries) >= 8) {
            array_shift($entries);
        }
        $entries[$key] = $factory;
        self::$scannerFactories[$body] = $entries;
        return $factory;
    }
}
