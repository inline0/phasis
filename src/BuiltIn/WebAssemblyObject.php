<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsSharedArrayBuffer;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * The `WebAssembly` namespace, limited to what needs no WebAssembly engine:
 * `WebAssembly.Memory` and the `CompileError`, `LinkError` and
 * `RuntimeError` constructors. Phasis does not compile or run WebAssembly
 * modules, so `compile`, `instantiate`, `validate`, `Module` and the other
 * module APIs are absent and feature checks for them fail.
 *
 * Memory follows the WebAssembly JS API (https://webassembly.github.io/spec/js-api/#memories)
 * with V8's error messages:
 *  - a non-shared memory's buffer is an ArrayBuffer only the memory can
 *    detach (grow() detaches it and hands out a new one);
 *  - a shared memory's buffer is a frozen SharedArrayBuffer, and every
 *    buffer it hands out views the same bytes, before and after grow().
 */
final class WebAssemblyObject
{
    private const PAGE_SIZE = 65536;
    private const MAX_PAGES = 65536;
    private const DETACH_KEY = 'WebAssembly.Memory';

    public static function install(Environment $env): void
    {
        $wasm = new JsObject();
        $wasm->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('WebAssembly'), false, false, true),
        );
        $wasm->defineOwnProperty('Memory', PropertyDescriptor::data(self::buildMemory(), true, false, true));

        $error = $env->get('Error');
        if ($error instanceof JsObject) {
            $errorProto = $error->get('prototype');
            foreach (['CompileError', 'LinkError', 'RuntimeError'] as $name) {
                $proto = new JsObject($errorProto instanceof JsObject ? $errorProto : null);
                $proto->defineOwnProperty('name', PropertyDescriptor::data(new JsString($name), true, false, true));
                $proto->defineOwnProperty('message', PropertyDescriptor::data(new JsString(''), true, false, true));
                $ctor = JsFunction::fromCallable($name, ErrorConstructor::makeConstructor($name, $proto), 1);
                $ctor->setConstructable();
                $ctor->setPrototype($error);
                $ctor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
                $proto->defineOwnProperty('constructor', PropertyDescriptor::data($ctor, true, false, true));
                $wasm->defineOwnProperty($name, PropertyDescriptor::data($ctor, true, false, true));
            }
        }

        $env->defineVar('WebAssembly', $wasm);
    }

    private static function buildMemory(): JsFunction
    {
        $proto = new JsObject();
        $ctor = JsFunction::fromCallable(
            'Memory',
            static function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || !$this_->hasNativeSlot('[[NewTarget]]')) {
                    throw new TypeError("WebAssembly.Memory(): WebAssembly.Memory must be invoked with 'new'");
                }
                $descriptor = $args[0] ?? JsUndefined::instance();
                if (!$descriptor instanceof JsObject) {
                    throw new TypeError('WebAssembly.Memory(): Argument 0 must be a memory descriptor');
                }
                $initialValue = $descriptor->get('initial');
                if ($initialValue instanceof JsUndefined) {
                    throw new TypeError("WebAssembly.Memory(): Property 'initial' is required");
                }
                $initial = self::toPageCount($initialValue, "WebAssembly.Memory(): Property 'initial'");
                if ($initial > self::MAX_PAGES) {
                    throw new RangeError(
                        "WebAssembly.Memory(): Property 'initial': value {$initial} is above the upper bound "
                        . self::MAX_PAGES,
                    );
                }
                $maximumValue = $descriptor->get('maximum');
                $maximum = null;
                if (!$maximumValue instanceof JsUndefined) {
                    $maximum = self::toPageCount($maximumValue, "WebAssembly.Memory(): Property 'maximum'");
                    if ($maximum < $initial) {
                        throw new RangeError(
                            "WebAssembly.Memory(): Property 'maximum': value {$maximum} is below the lower bound "
                            . $initial,
                        );
                    }
                    if ($maximum > self::MAX_PAGES) {
                        throw new RangeError(
                            "WebAssembly.Memory(): Property 'maximum': value {$maximum} is above the upper bound "
                            . self::MAX_PAGES,
                        );
                    }
                }
                $shared = TypeConversion::toBoolean($descriptor->get('shared'));
                if ($shared && $maximum === null) {
                    throw new TypeError(
                        'WebAssembly.Memory(): If shared is true, maximum property should be defined.',
                    );
                }

                $newTarget = $this_->getNativeSlot('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $targetProto = $newTarget->get('prototype');
                    $this_->setPrototype($targetProto instanceof JsObject ? $targetProto : $proto);
                }
                $byteLength = $initial * self::PAGE_SIZE;
                if (!JsArrayBuffer::canAllocate($byteLength)) {
                    throw new RangeError('WebAssembly.Memory(): could not allocate memory');
                }
                $this_->setInternalProperty('[[WasmMemoryPages]]', $initial);
                $this_->setInternalProperty('[[WasmMemoryMaximum]]', $maximum);
                $this_->setInternalProperty('[[WasmMemoryShared]]', $shared);
                $this_->setInternalProperty('[[WasmMemoryBuffer]]', self::newBuffer($shared, $byteLength, null));
                return $this_;
            },
            1,
        );
        $ctor->setConstructable();
        $ctor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($ctor, true, false, true));

        $grow = JsFunction::fromCallable(
            'grow',
            static function (JsValue $this_, array $args): JsValue {
                $memory = self::memory($this_, 'WebAssembly.Memory.grow():');
                $delta = self::toPageCount($args[0] ?? JsUndefined::instance(), 'WebAssembly.Memory.grow(): Argument 0');
                /** @var int $pages */
                $pages = $memory->getInternalProperty('[[WasmMemoryPages]]');
                /** @var ?int $maximum */
                $maximum = $memory->getInternalProperty('[[WasmMemoryMaximum]]');
                if ($maximum !== null && $pages + $delta > $maximum) {
                    throw new RangeError('WebAssembly.Memory.grow(): Maximum memory size exceeded');
                }
                $byteLength = ($pages + $delta) * self::PAGE_SIZE;
                if ($pages + $delta > self::MAX_PAGES || !JsArrayBuffer::canAllocate($byteLength)) {
                    throw new RangeError('WebAssembly.Memory.grow(): Unable to grow instance memory');
                }
                /** @var JsArrayBuffer $old */
                $old = $memory->getInternalProperty('[[WasmMemoryBuffer]]');
                $shared = $memory->getInternalProperty('[[WasmMemoryShared]]') === true;
                $memory->setInternalProperty('[[WasmMemoryBuffer]]', self::newBuffer($shared, $byteLength, $old));
                if (!$shared) {
                    $old->detach(self::DETACH_KEY);
                }
                $memory->setInternalProperty('[[WasmMemoryPages]]', $pages + $delta);
                return JsNumber::of($pages);
            },
            1,
        );
        $proto->defineOwnProperty('grow', PropertyDescriptor::data($grow, true, true, true));

        $bufferGetter = JsFunction::fromCallable(
            'get buffer',
            static function (JsValue $this_): JsValue {
                $buffer = self::memory($this_, 'WebAssembly.Memory.buffer:')->getInternalProperty('[[WasmMemoryBuffer]]');
                return $buffer instanceof JsArrayBuffer ? $buffer : JsUndefined::instance();
            },
            0,
        );
        $proto->defineOwnProperty('buffer', PropertyDescriptor::accessor($bufferGetter, null, true, true));
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('WebAssembly.Memory'), false, false, true),
        );
        return $ctor;
    }

    /**
     * A buffer of $byteLength bytes. A shared memory's new buffer views the
     * bytes of $previous; a non-shared one gets a copy, since grow()
     * detaches $previous.
     */
    private static function newBuffer(bool $shared, int $byteLength, ?JsArrayBuffer $previous): JsArrayBuffer
    {
        if ($shared) {
            $buffer = new JsSharedArrayBuffer($previous === null ? $byteLength : 0);
            if ($previous !== null) {
                $buffer->shareStorage($previous, $byteLength);
            }
            $buffer->preventExtensions();
            return $buffer;
        }
        $buffer = new JsArrayBuffer($byteLength);
        if ($previous !== null && $previous->getByteLength() > 0) {
            $buffer->writeBytes(0, $previous->readBytes(0, $previous->getByteLength()));
        }
        $buffer->setDetachKey(self::DETACH_KEY);
        return $buffer;
    }

    private static function memory(JsValue $value, string $context): JsObject
    {
        if (!$value instanceof JsObject || $value->getInternalProperty('[[WasmMemoryPages]]') === null) {
            throw new TypeError($context . ' Receiver is not a WebAssembly.Memory');
        }
        return $value;
    }

    /** WebIDL [EnforceRange] unsigned long, with V8's messages. */
    private static function toPageCount(JsValue $value, string $context): int
    {
        $number = TypeConversion::toNumber($value);
        if (is_nan($number) || is_infinite($number)) {
            throw new TypeError($context . ' must be convertible to a valid number');
        }
        $number = $number < 0 ? ceil($number) : floor($number);
        if ($number < 0) {
            throw new TypeError($context . ' must be non-negative');
        }
        if ($number > 4294967295) {
            throw new TypeError($context . ' must be in the unsigned long range');
        }
        return (int) $number;
    }
}
