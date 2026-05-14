<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\Value\JsWeakRef;

/**
 * WeakRef constructor and prototype methods.
 *
 * Per spec 26.1: new WeakRef(target) creates a weak reference to target.
 * target must be an object or a non-registered symbol.
 */
class WeakRefConstructor
{
    public static function install(Environment $env): void
    {
        $proto = self::createPrototype();

        $constructor = JsFunction::fromCallable(
            'WeakRef',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor WeakRef requires \'new\'');
                }
                $target = $args[0] ?? JsUndefined::instance();

                // Per spec 26.1.1.1: target must be an object or a non-registered symbol.
                $validTarget = $target instanceof JsObject
                    || ($target instanceof JsSymbol && !SymbolConstructor::isRegisteredSymbol($target));
                if (!$validTarget) {
                    throw new TypeError('Invalid WeakRef target');
                }

                // Per spec OrdinaryCreateFromConstructor: use NewTarget's prototype.
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsFunction) {
                    $ctorProto = $newTarget->get('prototype');
                    $useProto = $ctorProto instanceof JsObject ? $ctorProto : $proto;
                } else {
                    $useProto = $proto;
                }

                $ref = new JsWeakRef($useProto);
                $ref->setTarget($target);
                return $ref;
            },
            1,
        );
        $constructor->setConstructable();

        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($constructor, true, false, true),
        );

        $env->defineVar('WeakRef', $constructor);
    }

    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();

        $derefFn = JsFunction::fromCallable('deref', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsWeakRef) {
                throw new TypeError('Method WeakRef.prototype.deref called on incompatible receiver');
            }
            return $this_->deref();
        }, 0);
        $proto->defineOwnProperty('deref', PropertyDescriptor::data($derefFn, true, false, true));

        // Symbol.toStringTag = "WeakRef".
        $toStringTagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('WeakRef'), false, false, true),
        );

        return $proto;
    }
}
