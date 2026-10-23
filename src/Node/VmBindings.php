<?php

declare(strict_types=1);

namespace Phasis\Node;

use Phasis\Ast\Program;
use Phasis\Engine;
use Phasis\Exceptions\BudgetExceeded;
use Phasis\Exceptions\TypeError;
use Phasis\Parser\Parser;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/** Parsed scripts and separately owned realms behind the Node vm API. */
final class VmBindings
{
    /** @var \WeakMap<JsObject, \WeakReference<VmContext>> */
    private \WeakMap $contexts;
    private readonly string $contextSlot;
    private static int $contextSequence = 0;

    private function __construct(private readonly Engine $owner)
    {
        $this->contexts = new \WeakMap();
        $this->contextSlot = '[[NodeVmContext:' . ++self::$contextSequence . ']]';
    }

    /** Install vm host functions without evaluating scripts or starting external runtimes. */
    public static function create(Engine $engine, NodeRuntime $runtime): JsObject
    {
        $bindings = new self($engine);
        $runtime->onClose(static function () use ($bindings): void {
            foreach ($bindings->contexts as $sandbox => $reference) {
                $sandbox->setInternalProperty($bindings->contextSlot, null);
            }
            $bindings->contexts = new \WeakMap();
        });
        $host = new JsObject();
        self::method($host, 'createContext', static function (array $args) use ($bindings): JsValue {
            return $bindings->context(self::object($args[0]), self::object($args[1]));
        });
        self::method($host, 'isContext', static fn (array $args): JsValue => new JsBoolean(
            ($args[0] ?? null) instanceof JsObject && isset($bindings->contexts[$args[0]]),
        ));
        self::method($host, 'compile', static fn (array $args): JsValue => $bindings->compile($args[0]->toJsString(), self::object($args[1])));
        self::method($host, 'run', static fn (array $args): JsValue => $bindings->run(
            self::object($args[0]),
            $args[1] instanceof JsObject ? $args[1] : null,
            self::object($args[2]),
        ));
        return $host;
    }

    private function context(JsObject $sandbox, JsObject $options): JsObject
    {
        if (isset($this->contexts[$sandbox])) {
            return $sandbox;
        }
        $state = VmRealmState::capture();
        try {
            // Materialize intrinsics before linking the sandbox so lazy installation cannot leak globals into it.
            $engine = new Engine(eager: true);
            $engine->getInterpreter()->inheritLimits($this->owner->getInterpreter());
            $env = $engine->getGlobalEnv();
            $intrinsics = self::object($env->get('globalThis'));
            $global = new VmGlobalObject($sandbox, $intrinsics);
            $env->defineVar('this', $global);
            $env->defineVar('globalThis', $global);
            $intrinsics->set('globalThis', $global);
            $intrinsics->set('self', $global);
            $env->linkGlobalObject($global);
            $context = new VmContext($engine, $global, $options, JsPromise::captureIntrinsicState());
            // PHP WeakMap values are strong references, not ephemerons. The
            // sandbox owns its realm so the registry cannot retain its key.
            $sandbox->setInternalProperty($this->contextSlot, $context);
            $this->contexts[$sandbox] = \WeakReference::create($context);
        } finally {
            $state->restore();
        }
        return $sandbox;
    }

    private function compile(string $source, JsObject $options): JsObject
    {
        $lineOffset = $options->get('lineOffset');
        $columnOffset = $options->get('columnOffset');
        $source = str_repeat("\n", $lineOffset instanceof JsUndefined ? 0 : max(0, (int) $lineOffset->toNumber()))
            . str_repeat(' ', $columnOffset instanceof JsUndefined ? 0 : max(0, (int) $columnOffset->toNumber())) . $source;
        $parser = new Parser($source);
        $script = new JsObject();
        $script->setInternalProperty('[[VmProgram]]', $parser->parse());
        $filename = $options->get('filename');
        $script->setInternalProperty('[[VmFilename]]', $filename instanceof JsUndefined ? 'evalmachine.<anonymous>' : $filename->toJsString());
        return $script;
    }

    private function run(JsObject $script, ?JsObject $sandbox, JsObject $options): JsValue
    {
        $program = $script->getInternalProperty('[[VmProgram]]');
        if (!$program instanceof Program) {
            throw new TypeError('Invalid compiled vm.Script.');
        }
        if ($sandbox !== null && !isset($this->contexts[$sandbox])) {
            throw new TypeError('The contextifiedObject argument must be a vm.Context.');
        }
        $entry = $sandbox === null ? null : $this->contexts[$sandbox]->get();
        $engine = $entry->engine ?? $this->owner;
        $interpreter = $engine->getInterpreter();
        $caller = Engine::getCurrentInterpreter() ?? $this->owner->getInterpreter();
        $state = VmRealmState::capture();
        $strict = $interpreter->isStrictMode();
        $evalContext = $interpreter->isEvalContext();
        $timeout = $options->get('timeout');
        if ($engine !== $this->owner) {
            $interpreter->inheritLimits($caller);
        }
        $hostBudget = $interpreter->executionBudgetState();
        $timed = !$timeout instanceof JsUndefined;
        if ($timed) {
            $interpreter->tightenExecutionBudget($timeout->toNumber() / 1000);
        }
        VmRealmState::activate($engine);
        if ($entry !== null) {
            JsPromise::restoreIntrinsicState($entry->promiseIntrinsics);
        }
        Engine::pushSourceURL((string) $script->getInternalProperty('[[VmFilename]]'));
        try {
            $interpreter->setStrictMode(false);
            $interpreter->setEvalContext(false);
            $result = $interpreter->execute($program);
            $value = new JsObject();
            $value->set('value', $result);
            return $value;
        } catch (BudgetExceeded $error) {
            if (!$timed || $hostBudget !== null && hrtime(true) >= $hostBudget->deadline) {
                throw $error;
            }
            $failure = new JsObject();
            $failure->set('timeout', new JsBoolean(true));
            return $failure;
        } finally {
            Engine::popSourceURL();
            $interpreter->setStrictMode($strict);
            $interpreter->setEvalContext($evalContext);
            $interpreter->restoreExecutionBudgetState($hostBudget);
            if ($engine !== $this->owner) {
                $interpreter->inheritLimits($caller);
            }
            $state->restore();
        }
    }

    private static function object(JsValue $value): JsObject
    {
        if (!$value instanceof JsObject) {
            throw new TypeError('Expected an object.');
        }
        return $value;
    }

    /** @param \Closure(list<JsValue>): JsValue $callback */
    private static function method(JsObject $target, string $name, \Closure $callback): void
    {
        $target->set($name, JsFunction::fromCallable($name, static fn (JsValue $this_, array $args): JsValue => $callback($args)));
    }
}
