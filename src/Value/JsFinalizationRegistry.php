<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\BuiltIn\SymbolConstructor;

/**
 * JavaScript FinalizationRegistry object.
 *
 * Per spec 26.2: FinalizationRegistry holds registrations of (target, heldValue, unregisterToken)
 * triples. When a target is garbage collected, the cleanup callback is invoked with the heldValue.
 *
 * In PHP, true weak-reference-based cleanup is limited. We use PHP WeakReference for
 * object targets and check for collected targets when cleanupSome is called or when
 * new registrations happen.
 */
class JsFinalizationRegistry extends JsObject
{
    private JsFunction $cleanupCallback;

    /**
     * Each entry: [weakRef (WeakReference|null), target (JsSymbol|null), heldValue, unregisterToken (JsValue|null)].
     *
     * @var list<array{0: ?\WeakReference<JsObject>, 1: ?JsSymbol, 2: JsValue, 3: ?JsValue}>
     */
    private array $cells = [];

    public function __construct(JsFunction $callback, ?JsObject $prototype = null)
    {
        parent::__construct($prototype);
        $this->cleanupCallback = $callback;
    }

    /**
     * Per spec 26.2.3.2: register(target, heldValue [, unregisterToken]).
     */
    public function register(JsValue $target, JsValue $heldValue, ?JsValue $unregisterToken): void
    {
        // Per spec: target must be an object or non-registered symbol.
        $validTarget = $target instanceof JsObject
            || ($target instanceof JsSymbol && !SymbolConstructor::isRegisteredSymbol($target));
        if (!$validTarget) {
            throw new \PhpJs\Exceptions\TypeError('Invalid FinalizationRegistry target');
        }

        // Per spec: heldValue must not be the same as target.
        if ($target === $heldValue) {
            throw new \PhpJs\Exceptions\TypeError('target and holdings must not be the same');
        }

        // Per spec: unregisterToken must be an object, non-registered symbol, or undefined.
        if ($unregisterToken !== null && !($unregisterToken instanceof JsUndefined)) {
            $validToken = $unregisterToken instanceof JsObject
                || ($unregisterToken instanceof JsSymbol
                    && !SymbolConstructor::isRegisteredSymbol($unregisterToken));
            if (!$validToken) {
                throw new \PhpJs\Exceptions\TypeError('Invalid unregister token');
            }
        }

        $weakRef = null;
        $symbolTarget = null;
        if ($target instanceof JsObject) {
            $weakRef = \WeakReference::create($target);
        } elseif ($target instanceof JsSymbol) {
            $symbolTarget = $target;
        }

        $token = ($unregisterToken instanceof JsUndefined) ? null : $unregisterToken;

        $this->cells[] = [$weakRef, $symbolTarget, $heldValue, $token];
    }

    /**
     * Per spec 26.2.3.3: unregister(unregisterToken).
     * Removes all cells whose unregisterToken matches.
     */
    public function unregister(JsValue $unregisterToken): bool
    {
        // Per spec: token must be an object or non-registered symbol.
        $validToken = $unregisterToken instanceof JsObject
            || ($unregisterToken instanceof JsSymbol
                && !SymbolConstructor::isRegisteredSymbol($unregisterToken));
        if (!$validToken) {
            throw new \PhpJs\Exceptions\TypeError('Invalid unregister token');
        }

        $removed = false;
        $this->cells = array_values(array_filter(
            $this->cells,
            function (array $cell) use ($unregisterToken, &$removed): bool {
                if ($cell[3] !== null && $cell[3] === $unregisterToken) {
                    $removed = true;
                    return false;
                }
                return true;
            },
        ));
        return $removed;
    }

    /**
     * Run cleanup for any collected targets.
     * In PHP, this checks WeakReferences for collected objects.
     */
    public function cleanupSome(): void
    {
        $remaining = [];
        foreach ($this->cells as $cell) {
            [$weakRef, $symbolTarget, $heldValue] = $cell;
            $collected = false;

            if ($weakRef !== null) {
                // Object target: check if WeakReference has been collected.
                if ($weakRef->get() === null) {
                    $collected = true;
                }
            }
            // Symbol targets are never "collected" in PHP since they are not weak-referenced.

            if ($collected) {
                // Fire the callback with the held value.
                try {
                    $this->cleanupCallback->call(JsUndefined::instance(), [$heldValue]);
                } catch (\Throwable) {
                    // Per spec: cleanup callback errors are silently ignored.
                }
            } else {
                $remaining[] = $cell;
            }
        }
        $this->cells = $remaining;
    }

    public function toJsString(): string
    {
        return '[object FinalizationRegistry]';
    }

    public function display(): string
    {
        return 'FinalizationRegistry {}';
    }
}
