<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Streams;

use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WHATWG ByteLengthQueuingStrategy and CountQueuingStrategy.
 *
 * Both are simple wrappers over { highWaterMark, size(chunk) }. The size
 * function differs:
 *  - ByteLengthQueuingStrategy: returns chunk.byteLength
 *  - CountQueuingStrategy:      returns 1
 *
 * Spec: https://streams.spec.whatwg.org/#blqs-class
 */
final class QueuingStrategy
{
    public static function install(Environment $env): void
    {
        self::installByteLength($env);
        self::installCount($env);
    }

    private static function installByteLength(Environment $env): void
    {
        $proto = new JsObject();
        $ctor = JsFunction::fromCallable(
            'ByteLengthQueuingStrategy',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor ByteLengthQueuingStrategy requires 'new'");
                }
                $init = $args[0] ?? JsUndefined::instance();
                if (!$init instanceof JsObject) {
                    throw new TypeError('init must be an object');
                }
                $hwm = $init->get('highWaterMark');
                if ($hwm instanceof JsUndefined) {
                    throw new TypeError('highWaterMark required');
                }
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $useProto = $ntProto instanceof JsObject ? $ntProto : $proto;
                    $this_->setPrototype($useProto);
                }
                $this_->setInternalProperty('[[IsByteLengthQueuingStrategy]]', true);
                $this_->setInternalProperty('[[HighWaterMark]]', \Phasis\Spec\TypeConversion::toNumber($hwm));
                return $this_;
            },
            1,
        );
        $ctor->setConstructable();
        $ctor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($proto, false, false, false)
        );
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($ctor, true, false, true)
        );

        // highWaterMark getter
        $hwmGetter = JsFunction::fromCallable(
            'get highWaterMark',
            function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsObject || $this_->getInternalProperty('[[IsByteLengthQueuingStrategy]]') !== true) {
                    throw new TypeError('highWaterMark called on incompatible object');
                }
                /** @var JsObject $this_ */
                return JsNumber::of((float) $this_->getInternalProperty('[[HighWaterMark]]'));
            },
            0,
        );
        $proto->defineOwnProperty(
            'highWaterMark',
            PropertyDescriptor::accessor($hwmGetter, null, false, true)
        );

        // size(chunk) — spec WHATWG Streams §6.6.1: returns
        // `chunk["byteLength"]`. NO special-casing of undefined or
        // non-objects. `size(undefined).byteLength` throws TypeError;
        // a `get byteLength` accessor that throws propagates its
        // exception. The returned value is whatever the property
        // access yields — number, string, BigInt — passed through
        // to the queue without coercion (the queue itself enforces
        // finiteness when it actually uses the size).
        $sizeFn = JsFunction::fromCallable(
            'size',
            function (JsValue $this_, array $args): JsValue {
                $chunk = $args[0] ?? JsUndefined::instance();
                if ($chunk instanceof JsUndefined || $chunk instanceof \Phasis\Value\JsNull) {
                    throw new TypeError(
                        "Cannot read properties of " . ($chunk instanceof JsUndefined ? 'undefined' : 'null')
                        . " (reading 'byteLength')"
                    );
                }
                if (!$chunk instanceof JsObject) {
                    // Primitive — coerce to an object briefly via
                    // ToObject's property-access semantics. Number /
                    // String / Boolean primitives don't have a
                    // byteLength so the result is undefined.
                    return JsUndefined::instance();
                }
                return $chunk->get('byteLength');
            },
            1,
        );
        $proto->defineOwnProperty('size', PropertyDescriptor::data($sizeFn, true, false, true));

        $env->defineVar('ByteLengthQueuingStrategy', $ctor);
    }

    private static function installCount(Environment $env): void
    {
        $proto = new JsObject();
        $ctor = JsFunction::fromCallable(
            'CountQueuingStrategy',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor CountQueuingStrategy requires 'new'");
                }
                $init = $args[0] ?? JsUndefined::instance();
                if (!$init instanceof JsObject) {
                    throw new TypeError('init must be an object');
                }
                $hwm = $init->get('highWaterMark');
                if ($hwm instanceof JsUndefined) {
                    throw new TypeError('highWaterMark required');
                }
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $useProto = $ntProto instanceof JsObject ? $ntProto : $proto;
                    $this_->setPrototype($useProto);
                }
                $this_->setInternalProperty('[[IsCountQueuingStrategy]]', true);
                $this_->setInternalProperty('[[HighWaterMark]]', \Phasis\Spec\TypeConversion::toNumber($hwm));
                return $this_;
            },
            1,
        );
        $ctor->setConstructable();
        $ctor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($proto, false, false, false)
        );
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($ctor, true, false, true)
        );

        $hwmGetter = JsFunction::fromCallable(
            'get highWaterMark',
            function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsObject || $this_->getInternalProperty('[[IsCountQueuingStrategy]]') !== true) {
                    throw new TypeError('highWaterMark called on incompatible object');
                }
                /** @var JsObject $this_ */
                return JsNumber::of((float) $this_->getInternalProperty('[[HighWaterMark]]'));
            },
            0,
        );
        $proto->defineOwnProperty(
            'highWaterMark',
            PropertyDescriptor::accessor($hwmGetter, null, false, true)
        );

        // size() — returns 1.
        $sizeFn = JsFunction::fromCallable(
            'size',
            function (JsValue $this_, array $args): JsValue {
                return JsNumber::of(1.0);
            },
            0,
        );
        $proto->defineOwnProperty('size', PropertyDescriptor::data($sizeFn, true, false, true));

        $env->defineVar('CountQueuingStrategy', $ctor);
    }
}
