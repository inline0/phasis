<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\JsThrowable;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Value\JsArray;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsBigInt;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsDataView;
use Phasis\Value\JsFinalizationRegistry;
use Phasis\Value\JsFunction;
use Phasis\Value\JsMap;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsProxy;
use Phasis\Value\JsSet;
use Phasis\Value\JsSharedArrayBuffer;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\Value\JsWeakMap;
use Phasis\Value\JsWeakRef;
use Phasis\Value\JsWeakSet;

/**
 * Global structuredClone(value, options?) function.
 *
 * Implements the HTML §2.7 StructuredClone algorithm
 * (https://html.spec.whatwg.org/multipage/structured-data.html#structured-cloning).
 *
 * Handled types: primitives (undefined/null/bool/number/string/BigInt),
 * Date, RegExp, Map, Set, Error (and subclasses), ArrayBuffer (with
 * optional transfer-list detach semantics), TypedArrays, DataView,
 * plain objects and arrays. Cycle-safe via an SplObjectStorage map
 * keyed by the source JsObject.
 *
 * Uncloneable inputs throw a DOMException("...", "DataCloneError")
 * when the engine exposes a DOMException global; otherwise falls back
 * to a JS Error with name = "DataCloneError" so downstream code can
 * still pattern-match on the name.
 */
final class StructuredCloneFunction
{
    public static function install(Environment $env): void
    {
        $fn = JsFunction::fromCallable(
            'structuredClone',
            static function (JsValue $this_, array $args): JsValue {
                $value = $args[0] ?? JsUndefined::instance();
                $options = $args[1] ?? JsUndefined::instance();

                $transferSet = self::buildTransferSet($options);

                $seen = new \SplObjectStorage();
                $clone = self::cloneValue($value, $seen, $transferSet);

                // After cloning completes successfully, detach every
                // transferred buffer per spec step 4 of structuredClone.
                foreach ($transferSet as $buffer) {
                    if (!$buffer instanceof JsArrayBuffer) {
                        continue;
                    }
                    if (!$buffer->isDetached()) {
                        $buffer->detach();
                    }
                }

                return $clone;
            },
            1,
        );

        $env->defineDeletable('structuredClone', $fn);
    }

    /**
     * Build a map of (source ArrayBuffer -> transferred clone). Each
     * entry in options.transfer becomes a freshly-allocated buffer
     * that takes over the source's bytes; the source is detached
     * after the cloning walk completes.
     *
     * Validates that every entry is a non-detached ArrayBuffer; any
     * other value (TypedArray, DataView, plain object, primitive)
     * throws a DataCloneError per spec.
     *
     * Keys are source JsArrayBuffers, values are their pre-allocated
     * clones; the SplObjectStorage generic parameters are <object, mixed>
     * since the class is not template-aware in stubs.
     *
     * @return \SplObjectStorage<object, mixed>
     */
    private static function buildTransferSet(JsValue $options): \SplObjectStorage
    {
        $set = new \SplObjectStorage();

        if (!$options instanceof JsObject) {
            return $set;
        }

        $transferProp = $options->get('transfer');
        if ($transferProp instanceof JsUndefined || $transferProp instanceof JsNull) {
            return $set;
        }

        // Per spec, options.transfer must be a sequence. We accept any
        // iterable-like JsArray here; non-arrays throw DataCloneError.
        if (!$transferProp instanceof JsArray) {
            // Try to read 'length' for an array-like fallback. To keep
            // this implementation small and spec-aligned (browsers
            // accept arrays; non-array iterables would require a full
            // GetIterator dance), reject anything that is not a JsArray.
            throw self::dataCloneError('options.transfer is not iterable');
        }

        foreach ($transferProp->toList() as $entry) {
            if (!$entry instanceof JsArrayBuffer) {
                throw self::dataCloneError(
                    'Value in transfer list is not a transferable object',
                );
            }
            if ($entry->isDetached()) {
                throw self::dataCloneError(
                    'ArrayBuffer in transfer list is already detached',
                );
            }
            if ($set->offsetExists($entry)) {
                throw self::dataCloneError(
                    'ArrayBuffer appears more than once in transfer list',
                );
            }

            // Build the cloned buffer eagerly, copying bytes from the
            // source. Detaching the source is deferred to after the
            // value graph has been cloned so concurrent references
            // see the new buffer via the transfer map.
            $cloned = new JsArrayBuffer(
                $entry->getByteLength(),
                JsArrayBuffer::getDefaultPrototype(),
            );
            $cloned->setData($entry->getData());

            $set[$entry] = $cloned;
        }

        return $set;
    }

    /**
     * Recursive worker. $seen maps source JsObject -> already-allocated
     * clone so cycles and shared-subgraph identity are preserved.
     *
     * @param \SplObjectStorage<object, mixed> $seen
     * @param \SplObjectStorage<object, mixed> $transferSet
     */
    private static function cloneValue(
        JsValue $value,
        \SplObjectStorage $seen,
        \SplObjectStorage $transferSet,
    ): JsValue {
        // Primitives — all immutable, return as-is.
        if (
            $value instanceof JsUndefined
            || $value instanceof JsNull
            || $value instanceof JsBoolean
            || $value instanceof JsNumber
            || $value instanceof JsString
            || $value instanceof JsBigInt
        ) {
            return $value;
        }

        // Symbols are uncloneable per spec.
        if ($value instanceof JsSymbol) {
            throw self::dataCloneError(
                'Symbol could not be cloned',
            );
        }

        // Functions of any kind are uncloneable per spec.
        if ($value instanceof JsFunction) {
            throw self::dataCloneError(
                'Function object could not be cloned',
            );
        }

        if (!$value instanceof JsObject) {
            // Defensive: any unknown JsValue subtype.
            throw self::dataCloneError(
                'Value could not be cloned',
            );
        }

        // Explicitly uncloneable host-object types.
        if (
            $value instanceof JsPromise
            || $value instanceof JsWeakMap
            || $value instanceof JsWeakSet
            || $value instanceof JsWeakRef
            || $value instanceof JsFinalizationRegistry
            || $value instanceof JsSharedArrayBuffer
        ) {
            throw self::dataCloneError(
                'Object of this type could not be cloned',
            );
        }

        // Proxies are uncloneable per the HTML spec (no [[StructuredClone]]
        // semantics; the spec only deep-clones a fixed set of types).
        if ($value instanceof JsProxy) {
            throw self::dataCloneError(
                'Proxy object could not be cloned',
            );
        }

        // Cycle / shared-subgraph short-circuit.
        if ($seen->offsetExists($value)) {
            return $seen[$value];
        }

        // ArrayBuffer — handle before generic JsObject path so the
        // transfer list takes effect.
        if ($value instanceof JsArrayBuffer) {
            return self::cloneArrayBuffer($value, $seen, $transferSet);
        }

        // TypedArrays must clone their view shape over a cloned (or
        // transferred) underlying buffer.
        if ($value instanceof JsTypedArray) {
            return self::cloneTypedArray($value, $seen, $transferSet);
        }

        // DataView follows the same rule.
        if ($value instanceof JsDataView) {
            return self::cloneDataView($value, $seen, $transferSet);
        }

        if ($value instanceof JsMap) {
            return self::cloneMap($value, $seen, $transferSet);
        }

        if ($value instanceof JsSet) {
            return self::cloneSet($value, $seen, $transferSet);
        }

        if ($value instanceof JsArray) {
            return self::cloneArray($value, $seen, $transferSet);
        }

        // Date — detected via the [[IsDate]] internal slot.
        if ($value->getInternalProperty('[[IsDate]]') === true) {
            return self::cloneDate($value, $seen);
        }

        // RegExp — detected via the [[OriginalSource]] internal slot.
        if ($value->hasOwnProperty('[[OriginalSource]]')) {
            return self::cloneRegExp($value, $seen);
        }

        // Error and subclasses — detected via [[ErrorData]].
        if ($value->hasOwnProperty('[[ErrorData]]')) {
            return self::cloneError($value, $seen, $transferSet);
        }

        // Plain object (or class instance treated as a plain object per
        // the HTML spec's OrdinaryObject branch).
        return self::cloneOrdinaryObject($value, $seen, $transferSet);
    }

    /**
     * @param \SplObjectStorage<object, mixed> $seen
     * @param \SplObjectStorage<object, mixed> $transferSet
     */
    private static function cloneArrayBuffer(
        JsArrayBuffer $value,
        \SplObjectStorage $seen,
        \SplObjectStorage $transferSet,
    ): JsArrayBuffer {
        if ($transferSet->offsetExists($value)) {
            $transferred = $transferSet[$value];
            $seen[$value] = $transferred;
            return $transferred;
        }

        if ($value->isDetached()) {
            throw self::dataCloneError(
                'ArrayBuffer is detached and cannot be cloned',
            );
        }

        $clone = new JsArrayBuffer(
            $value->getByteLength(),
            JsArrayBuffer::getDefaultPrototype(),
        );
        $clone->setData($value->getData());

        $seen[$value] = $clone;
        return $clone;
    }

    /**
     * @param \SplObjectStorage<object, mixed> $seen
     * @param \SplObjectStorage<object, mixed> $transferSet
     */
    private static function cloneTypedArray(
        JsTypedArray $value,
        \SplObjectStorage $seen,
        \SplObjectStorage $transferSet,
    ): JsTypedArray {
        $srcBuffer = $value->getBuffer();
        if ($srcBuffer->isDetached()) {
            throw self::dataCloneError(
                'TypedArray buffer is detached',
            );
        }

        $clonedBuffer = self::cloneArrayBuffer($srcBuffer, $seen, $transferSet);

        $proto = self::lookupPrototype($value->getTypeName());
        $clone = new JsTypedArray(
            $value->getTypeName(),
            $clonedBuffer,
            $value->getByteOffset(),
            $value->getLength(),
            $proto,
        );
        if ($value->isAutoLength()) {
            $clone->setAutoLength(true);
        }

        $seen[$value] = $clone;
        return $clone;
    }

    /**
     * @param \SplObjectStorage<object, mixed> $seen
     * @param \SplObjectStorage<object, mixed> $transferSet
     */
    private static function cloneDataView(
        JsDataView $value,
        \SplObjectStorage $seen,
        \SplObjectStorage $transferSet,
    ): JsDataView {
        $srcBuffer = $value->getBuffer();
        if ($srcBuffer->isDetached()) {
            throw self::dataCloneError(
                'DataView buffer is detached',
            );
        }

        $clonedBuffer = self::cloneArrayBuffer($srcBuffer, $seen, $transferSet);

        $proto = self::lookupPrototype('DataView');
        $clone = new JsDataView(
            $clonedBuffer,
            $value->getByteOffset(),
            $value->isAutoLength() ? null : $value->getByteLength(),
            $proto,
        );

        $seen[$value] = $clone;
        return $clone;
    }

    /**
     * @param \SplObjectStorage<object, mixed> $seen
     * @param \SplObjectStorage<object, mixed> $transferSet
     */
    private static function cloneMap(
        JsMap $value,
        \SplObjectStorage $seen,
        \SplObjectStorage $transferSet,
    ): JsMap {
        $clone = new JsMap(self::lookupPrototype('Map'));
        // Register before recursing so cycles via map values resolve.
        $seen[$value] = $clone;

        foreach ($value->mapEntries() as [$k, $v]) {
            $clonedKey = self::cloneValue($k, $seen, $transferSet);
            $clonedVal = self::cloneValue($v, $seen, $transferSet);
            $clone->mapSet($clonedKey, $clonedVal);
        }

        return $clone;
    }

    /**
     * @param \SplObjectStorage<object, mixed> $seen
     * @param \SplObjectStorage<object, mixed> $transferSet
     */
    private static function cloneSet(
        JsSet $value,
        \SplObjectStorage $seen,
        \SplObjectStorage $transferSet,
    ): JsSet {
        $clone = new JsSet(self::lookupPrototype('Set'));
        $seen[$value] = $clone;

        foreach ($value->setValues() as $v) {
            $clone->setAdd(self::cloneValue($v, $seen, $transferSet));
        }

        return $clone;
    }

    /**
     * @param \SplObjectStorage<object, mixed> $seen
     * @param \SplObjectStorage<object, mixed> $transferSet
     */
    private static function cloneArray(
        JsArray $value,
        \SplObjectStorage $seen,
        \SplObjectStorage $transferSet,
    ): JsArray {
        $clone = new JsArray();
        $seen[$value] = $clone;

        // Clone integer-indexed slots (including holes — which the
        // dense path materializes as JsUndefined; HTML spec keeps the
        // array as-is so we let the dense clone mirror that).
        $length = $value->getLength();
        for ($i = 0; $i < $length; $i++) {
            $key = (string) $i;
            $desc = $value->getOwnPropertyDescriptor($key);
            if ($desc === null) {
                continue;
            }
            // structured clone produces default-attr data properties.
            $elem = $desc->value ?? JsUndefined::instance();
            $cloned = self::cloneValue($elem, $seen, $transferSet);
            $clone->defineOwnProperty(
                $key,
                PropertyDescriptor::data($cloned, true, true, true),
            );
        }
        $clone->setLength($length);

        // Clone non-index own enumerable string-keyed properties (rare
        // but legal: `arr.foo = 1`).
        self::cloneStringKeyedOwnProperties($value, $clone, $seen, $transferSet, skipIndices: true);

        return $clone;
    }

    /**
     * @param \SplObjectStorage<object, mixed> $seen
     */
    private static function cloneDate(JsObject $value, \SplObjectStorage $seen): JsObject
    {
        $tv = $value->getInternalProperty('[[DateValue]]');
        if (!is_float($tv) && !is_int($tv)) {
            $tv = NAN;
        }
        $proto = self::lookupPrototype('Date');
        $clone = new JsObject($proto);
        $clone->setInternalProperty('[[DateValue]]', (float) $tv);
        $clone->setInternalProperty('[[IsDate]]', true);

        $seen[$value] = $clone;
        return $clone;
    }

    /**
     * @param \SplObjectStorage<object, mixed> $seen
     */
    private static function cloneRegExp(JsObject $value, \SplObjectStorage $seen): JsObject
    {
        $srcDesc = $value->getOwnPropertyDescriptor('[[OriginalSource]]');
        $flagsDesc = $value->getOwnPropertyDescriptor('[[OriginalFlags]]');
        $source = ($srcDesc !== null && $srcDesc->value instanceof JsString)
            ? $srcDesc->value->value
            : '';
        $flags = ($flagsDesc !== null && $flagsDesc->value instanceof JsString)
            ? $flagsDesc->value->value
            : '';

        // Build the clone by calling the RegExp constructor so all
        // internal slots ([[PCREPattern]], compiled matcher, etc.) get
        // populated identically. Falling back to property copying would
        // leave the clone unable to execute matches.
        $interp = \Phasis\Engine::getCurrentInterpreter();
        if ($interp === null) {
            // No active interpreter (shouldn't happen during eval but
            // be defensive): degrade to a plain object copy.
            $clone = new JsObject($value->getPrototype());
        } else {
            $env = $interp->getGlobalEnv();
            if (!$env->has('RegExp')) {
                $clone = new JsObject($value->getPrototype());
            } else {
                $ctor = $env->get('RegExp');
                if (!$ctor instanceof JsFunction) {
                    $clone = new JsObject($value->getPrototype());
                } else {
                    // `new RegExp(source, flags)` runs the full
                    // construction path and yields a JsObject with all
                    // internal slots wired.
                    $newTargetMarker = new JsObject();
                    $newTargetMarker->defineOwnProperty(
                        '[[NewTarget]]',
                        PropertyDescriptor::data($ctor, false, false, false),
                    );
                    $result = $ctor->call($newTargetMarker, [
                        new JsString($source),
                        new JsString($flags),
                    ]);
                    $clone = $result instanceof JsObject ? $result : new JsObject($value->getPrototype());
                }
            }
        }

        // Per spec, lastIndex on the clone is 0 (the default from the
        // RegExp constructor), so no additional work is needed.
        $seen[$value] = $clone;
        return $clone;
    }

    /**
     * @param \SplObjectStorage<object, mixed> $seen
     * @param \SplObjectStorage<object, mixed> $transferSet
     */
    private static function cloneError(
        JsObject $value,
        \SplObjectStorage $seen,
        \SplObjectStorage $transferSet,
    ): JsObject {
        // Preserve prototype so `instanceof TypeError` etc still works.
        $clone = new JsObject($value->getPrototype());
        $clone->defineOwnProperty(
            '[[ErrorData]]',
            PropertyDescriptor::data(JsUndefined::instance(), false, false, false),
        );
        $seen[$value] = $clone;

        // name, message, stack are plain data; cause is recursively cloned.
        foreach (['name', 'message', 'stack'] as $field) {
            if (!$value->hasOwnProperty($field)) {
                continue;
            }
            $fieldVal = $value->get($field);
            $clone->defineOwnProperty(
                $field,
                PropertyDescriptor::data(
                    self::cloneValue($fieldVal, $seen, $transferSet),
                    true,
                    false,
                    true,
                ),
            );
        }
        if ($value->hasOwnProperty('cause')) {
            $clone->defineOwnProperty(
                'cause',
                PropertyDescriptor::data(
                    self::cloneValue($value->get('cause'), $seen, $transferSet),
                    true,
                    false,
                    true,
                ),
            );
        }

        return $clone;
    }

    /**
     * @param \SplObjectStorage<object, mixed> $seen
     * @param \SplObjectStorage<object, mixed> $transferSet
     */
    private static function cloneOrdinaryObject(
        JsObject $value,
        \SplObjectStorage $seen,
        \SplObjectStorage $transferSet,
    ): JsObject {
        // Per HTML spec, structured clone of an OrdinaryObject produces
        // a plain object with %Object.prototype% as prototype (custom
        // prototype is intentionally dropped). Use the default global
        // prototype if available.
        $clone = new JsObject();
        $seen[$value] = $clone;

        self::cloneStringKeyedOwnProperties($value, $clone, $seen, $transferSet, skipIndices: false);

        return $clone;
    }

    /**
     * Copy all own enumerable string-keyed properties (including array
     * indices unless $skipIndices is set, in which case the caller has
     * already handled them). Symbol-keyed properties are intentionally
     * skipped per the HTML spec.
     *
     * @param \SplObjectStorage<object, mixed> $seen
     * @param \SplObjectStorage<object, mixed> $transferSet
     */
    private static function cloneStringKeyedOwnProperties(
        JsObject $src,
        JsObject $dest,
        \SplObjectStorage $seen,
        \SplObjectStorage $transferSet,
        bool $skipIndices,
    ): void {
        foreach ($src->ordinaryOwnPropertyKeys() as $key) {
            if (!$key instanceof JsString) {
                // Symbol keys are not cloned.
                continue;
            }
            $name = $key->value;
            // Internal-slot keys (`[[...]]`) must never be enumerated
            // onto the clone — they are engine-private state.
            if (str_starts_with($name, '[[') && str_ends_with($name, ']]')) {
                continue;
            }
            if ($skipIndices && self::isArrayIndexKey($name)) {
                continue;
            }
            $desc = $src->getOwnPropertyDescriptor($name);
            if ($desc === null || $desc->enumerable !== true) {
                continue;
            }

            // Per spec, accessors are read and their result cloned as a
            // data property (the clone never holds the getter/setter).
            $rawValue = null;
            if ($desc->isDataDescriptor()) {
                $rawValue = $desc->value ?? JsUndefined::instance();
            } elseif ($desc->isAccessorDescriptor()) {
                if ($desc->get !== null) {
                    $rawValue = $desc->get->call($src, []);
                } else {
                    $rawValue = JsUndefined::instance();
                }
            } else {
                $rawValue = JsUndefined::instance();
            }

            $cloned = self::cloneValue($rawValue, $seen, $transferSet);
            $dest->defineOwnProperty(
                $name,
                PropertyDescriptor::data($cloned, true, true, true),
            );
        }
    }

    /** Whether $key is a canonical array-index string per spec. */
    private static function isArrayIndexKey(string $key): bool
    {
        if ($key === '' || $key[0] === '-') {
            return false;
        }
        if (!ctype_digit($key)) {
            return false;
        }
        if (strlen($key) > 1 && $key[0] === '0') {
            return false;
        }
        if (strlen($key) > 10) {
            return false;
        }
        $val = (int) $key;
        return $val >= 0 && $val <= 4294967294 && (string) $val === $key;
    }

    /** Look up the realm's prototype for a built-in by constructor name. */
    private static function lookupPrototype(string $ctorName): ?JsObject
    {
        $interp = \Phasis\Engine::getCurrentInterpreter();
        if ($interp === null) {
            return null;
        }
        $env = $interp->getGlobalEnv();
        if (!$env->has($ctorName)) {
            return null;
        }
        $ctor = $env->get($ctorName);
        if (!$ctor instanceof JsObject) {
            return null;
        }
        $proto = $ctor->get('prototype');
        return $proto instanceof JsObject ? $proto : null;
    }

    /**
     * Build and throw a DataCloneError. Uses the engine's DOMException
     * global when present; otherwise falls back to a plain Error object
     * with `name = "DataCloneError"` so callers can still pattern-match
     * on the name field.
     */
    private static function dataCloneError(string $message): JsThrowable
    {
        $interp = \Phasis\Engine::getCurrentInterpreter();
        $env = $interp !== null ? $interp->getGlobalEnv() : null;

        if ($env !== null && $env->has('DOMException')) {
            $ctor = $env->get('DOMException');
            if ($ctor instanceof JsFunction && $ctor->isConstructable()) {
                try {
                    $newTargetMarker = new JsObject();
                    $newTargetMarker->defineOwnProperty(
                        '[[NewTarget]]',
                        PropertyDescriptor::data($ctor, false, false, false),
                    );
                    $result = $ctor->call($newTargetMarker, [
                        new JsString($message),
                        new JsString('DataCloneError'),
                    ]);
                    if ($result instanceof JsObject) {
                        return new JsThrowable($result, 'DataCloneError: ' . $message);
                    }
                } catch (\Throwable) {
                    // Fall through to plain-Error fallback.
                }
            }
        }

        // Fallback: plain Error-shaped object with name = "DataCloneError".
        $errProto = self::lookupPrototype('Error');
        $err = new JsObject($errProto);
        $err->defineOwnProperty(
            '[[ErrorData]]',
            PropertyDescriptor::data(JsUndefined::instance(), false, false, false),
        );
        $err->defineOwnProperty(
            'name',
            PropertyDescriptor::data(new JsString('DataCloneError'), true, false, true),
        );
        $err->defineOwnProperty(
            'message',
            PropertyDescriptor::data(new JsString($message), true, false, true),
        );
        return new JsThrowable($err, 'DataCloneError: ' . $message);
    }
}
