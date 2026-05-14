<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFinalizationRegistry;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * FinalizationRegistry constructor and prototype methods.
 *
 * Per spec 26.2: new FinalizationRegistry(cleanupCallback) creates a registry.
 * register(target, heldValue [, unregisterToken]) registers a target.
 * unregister(unregisterToken) removes registrations.
 */
class FinalizationRegistryConstructor
{
    public static function install(Environment $env): void
    {
        $proto = self::createPrototype();

        $constructor = JsFunction::fromCallable(
            'FinalizationRegistry',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor FinalizationRegistry requires \'new\'');
                }
                $callback = $args[0] ?? JsUndefined::instance();
                if (!$callback instanceof JsFunction) {
                    throw new TypeError('FinalizationRegistry requires a callable argument');
                }

                // Per spec OrdinaryCreateFromConstructor: use NewTarget's prototype.
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsFunction) {
                    $ctorProto = $newTarget->get('prototype');
                    $useProto = $ctorProto instanceof JsObject ? $ctorProto : $proto;
                } else {
                    $useProto = $proto;
                }

                return new JsFinalizationRegistry($callback, $useProto);
            },
            1,
        );
        $constructor->setConstructable();

        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($constructor, true, false, true),
        );

        $env->defineVar('FinalizationRegistry', $constructor);
    }

    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();

        $registerFn = JsFunction::fromCallable(
            'register',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsFinalizationRegistry) {
                    throw new TypeError(
                        'Method FinalizationRegistry.prototype.register called on incompatible receiver',
                    );
                }
                $target = $args[0] ?? JsUndefined::instance();
                $heldValue = $args[1] ?? JsUndefined::instance();
                $unregisterToken = $args[2] ?? JsUndefined::instance();
                $this_->register($target, $heldValue, $unregisterToken);
                return JsUndefined::instance();
            },
            2,
        );
        $proto->defineOwnProperty('register', PropertyDescriptor::data($registerFn, true, false, true));

        $unregisterFn = JsFunction::fromCallable(
            'unregister',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsFinalizationRegistry) {
                    throw new TypeError(
                        'Method FinalizationRegistry.prototype.unregister called on incompatible receiver',
                    );
                }
                $token = $args[0] ?? JsUndefined::instance();
                return new JsBoolean($this_->unregister($token));
            },
            1,
        );
        $proto->defineOwnProperty('unregister', PropertyDescriptor::data($unregisterFn, true, false, true));

        // Symbol.toStringTag = "FinalizationRegistry".
        $toStringTagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('FinalizationRegistry'), false, false, true),
        );

        return $proto;
    }
}
