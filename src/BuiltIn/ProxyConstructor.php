<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\TypeError;
use PhpJs\Object\PropertyDescriptor;
use PhpJs\Runtime\Environment;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsProxy;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

/**
 * Proxy constructor and Proxy.revocable static method.
 *
 * new Proxy(target, handler) creates a proxy that intercepts operations
 * on target via traps defined on handler.
 *
 * Proxy.revocable(target, handler) returns {proxy, revoke} where calling
 * revoke() disables the proxy permanently.
 */
class ProxyConstructor
{
    public static function install(Environment $env): void
    {
        $constructor = JsFunction::fromCallable(
            'Proxy',
            function (JsValue $this_, array $args): JsValue {
                // Proxy must be called with new. The interpreter sets _isNew
                // on the this_ object when using new; for built-in constructors
                // via fromCallable, we detect direct call by checking if this_
                // is not a JsProxy and not a fresh JsObject from the new path.
                // Per spec 28.2.1.1 step 1: If NewTarget is undefined, throw TypeError.
                // The interpreter passes the constructor itself as $this_ for
                // direct calls, so if $this_ is the function itself or undefined, it's direct.
                if ($this_ instanceof JsFunction || $this_ instanceof JsUndefined) {
                    throw new TypeError('Constructor Proxy requires \'new\'');
                }

                $target = $args[0] ?? JsUndefined::instance();
                $handler = $args[1] ?? JsUndefined::instance();

                if (!$target instanceof JsObject) {
                    throw new TypeError('Cannot create proxy with a non-object as target');
                }
                if (!$handler instanceof JsObject) {
                    throw new TypeError('Cannot create proxy with a non-object as handler');
                }

                return new JsProxy($target, $handler);
            },
            2,
        );
        $constructor->setConstructable();

        // Proxy.revocable(target, handler) - non-enumerable per spec.
        $revocableFn = JsFunction::fromCallable(
            'revocable',
            function (JsValue $this_, array $args): JsValue {
                $target = $args[0] ?? JsUndefined::instance();
                $handler = $args[1] ?? JsUndefined::instance();

                if (!$target instanceof JsObject) {
                    throw new TypeError('Cannot create proxy with a non-object as target');
                }
                if (!$handler instanceof JsObject) {
                    throw new TypeError('Cannot create proxy with a non-object as handler');
                }

                $proxy = new JsProxy($target, $handler);

                $revokeFn = JsFunction::fromCallable('', function () use ($proxy): JsValue {
                    $proxy->revoke();
                    return JsUndefined::instance();
                }, 0);

                $result = new JsObject();
                $result->set('proxy', $proxy);
                $result->set('revoke', $revokeFn);
                return $result;
            },
            2,
        );
        $constructor->defineOwnProperty(
            'revocable',
            PropertyDescriptor::data($revocableFn, true, false, true),
        );

        $env->defineVar('Proxy', $constructor);
    }
}
