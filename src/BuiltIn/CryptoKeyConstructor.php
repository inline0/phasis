<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Engine;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsValue;

/** The WebCrypto CryptoKey interface is exposed but cannot be directly constructed. */
final class CryptoKeyConstructor
{
    /** Install this realm's CryptoKey constructor and prototype. */
    public static function install(Environment $environment): void
    {
        $prototype = new JsObject();
        $constructor = JsFunction::fromCallable('CryptoKey', static function (JsValue $this_, array $args): JsValue {
            throw new TypeError('Illegal constructor');
        });
        $constructor->setConstructable();
        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($prototype, false, false, false));
        $prototype->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));
        $prototype->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('CryptoKey'), false, false, true),
        );
        $environment->defineVar('CryptoKey', $constructor);
    }

    /** Return the prototype belonging to the active realm. */
    public static function prototype(): ?JsObject
    {
        $environment = Engine::getCurrentRealm()?->getGlobalEnv();
        if ($environment === null || !$environment->has('CryptoKey')) {
            return null;
        }
        $constructor = $environment->get('CryptoKey');
        $prototype = $constructor instanceof JsObject ? $constructor->get('prototype') : null;
        return $prototype instanceof JsObject ? $prototype : null;
    }
}
