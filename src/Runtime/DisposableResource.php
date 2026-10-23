<?php

declare(strict_types=1);

namespace Phasis\Runtime;

use Phasis\BuiltIn\SymbolConstructor;
use Phasis\Exceptions\TypeError;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/** A resource and its disposal method, captured when the resource is registered. */
final readonly class DisposableResource
{
    public function __construct(public JsValue $value, public ?JsFunction $method, public bool $async)
    {
    }

    /** Apply AddDisposableResource and GetDisposeMethod without rereading either symbol. */
    public static function create(JsValue $value, bool $async): ?self
    {
        if ($value instanceof JsNull || $value instanceof JsUndefined) {
            return $async ? new self(JsUndefined::instance(), null, true) : null;
        }
        if (!$value instanceof JsObject) {
            throw new TypeError('Disposable resource must be an object, null, or undefined');
        }
        $method = $async ? $value->getBySymbol(SymbolConstructor::asyncDispose()) : JsUndefined::instance();
        if (!$method instanceof JsNull && !$method instanceof JsUndefined) {
            if (!$method instanceof JsFunction) {
                throw new TypeError('Property [Symbol.asyncDispose] is not a function');
            }
            return new self($value, $method, true);
        }
        $method = $value->getBySymbol(SymbolConstructor::dispose());
        if (!$method instanceof JsFunction) {
            throw new TypeError('Property [Symbol.dispose] is not a function');
        }
        if (!$async) {
            return new self($value, $method, false);
        }
        $wrapper = JsFunction::fromCallable('', static function (JsValue $this_) use ($method): JsValue {
            try {
                $method->call($this_, []);
                return JsPromise::resolved(JsUndefined::instance());
            } catch (\Throwable $error) {
                $interpreter = JsFunction::getInterpreterInstance();
                if ($interpreter === null) {
                    throw $error;
                }
                return JsPromise::rejected($interpreter->phpExceptionToJsValue($error));
            }
        });
        return new self($value, $wrapper, true);
    }
}
