<?php

declare(strict_types=1);

namespace Phasis\Node;

use Phasis\Engine;
use Phasis\Runtime\AsyncContextStorage;
use Phasis\Runtime\Interpreter;
use Phasis\Value\JsArray;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsBigInt;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsValue;

/** Restore host execution state around creation and evaluation of vm realms. */
final class VmRealmState
{
    /** @var array{0: array<int, JsObject>, 1: array<int, JsValue>} */
    private array $context;

    private ?Interpreter $interpreter;

    private ?Interpreter $functionInterpreter;

    private ?\Closure $functionCallback;

    private ?\Closure $postDrain;

    private ?\Closure $turnBoundary;

    /** @var array{?JsObject, ?JsValue} */
    private array $promiseIntrinsics;

    private function __construct()
    {
        $this->interpreter = Engine::getCurrentInterpreter();
        $this->functionInterpreter = JsFunction::getInterpreterInstance();
        $this->functionCallback = JsFunction::getInterpreterCallback();
        $this->postDrain = JsPromise::getPostDrainHook();
        $this->turnBoundary = JsPromise::getTurnBoundaryHook();
        $this->promiseIntrinsics = JsPromise::captureIntrinsicState();
        $this->context = AsyncContextStorage::active()->snapshot();
    }

    /** Capture the active caller without clearing queued work. */
    public static function capture(): self
    {
        return new self();
    }

    /** Select realm-owned intrinsic defaults for native value constructors. */
    public static function activate(Engine $engine): void
    {
        $interpreter = $engine->getInterpreter();
        Engine::setCurrentInterpreter($interpreter);
        JsFunction::setInterpreterInstance($interpreter);
        JsFunction::setInterpreterCallback($interpreter->callFunction(...));
        $env = $engine->getGlobalEnv();
        $setters = [
            'Object' => JsObject::setGlobalPrototype(...), 'Array' => JsArray::setGlobalPrototype(...),
            'String' => JsString::setStringPrototype(...), 'Number' => JsNumber::setNumberPrototype(...),
            'Boolean' => JsBoolean::setBooleanPrototype(...), 'Symbol' => JsSymbol::setSymbolPrototype(...),
            'BigInt' => JsBigInt::setPrototype(...), 'ArrayBuffer' => JsArrayBuffer::setDefaultPrototype(...),
            'Promise' => JsPromise::setPromisePrototype(...),
        ];
        foreach ($setters as $name => $setter) {
            $constructor = $env->get($name);
            $prototype = $constructor instanceof JsObject ? $constructor->get('prototype') : null;
            if ($prototype instanceof JsObject) {
                $setter($prototype);
            }
        }
    }

    /** Restore the outer event loop, async context, interpreter, and intrinsic defaults. */
    public function restore(): void
    {
        $realm = $this->interpreter?->getRealm();
        if ($realm !== null) {
            self::activate($realm);
        }
        Engine::setCurrentInterpreter($this->interpreter);
        if ($this->functionInterpreter !== null) {
            JsFunction::setInterpreterInstance($this->functionInterpreter);
        }
        if ($this->functionCallback !== null) {
            JsFunction::setInterpreterCallback($this->functionCallback);
        }
        JsPromise::setPostDrainHook($this->postDrain);
        JsPromise::setTurnBoundaryHook($this->turnBoundary);
        JsPromise::restoreIntrinsicState($this->promiseIntrinsics);
        AsyncContextStorage::active()->restore($this->context);
    }
}
