<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;

/**
 * HTML `navigator` global — a tiny client-info shim.
 *
 * Spec: https://html.spec.whatwg.org/multipage/system-state.html#navigator
 *
 * Phasis is not a browser, so we only expose the two properties
 * libraries actually check before falling back to a polyfill:
 *
 *  - `navigator.userAgent` — `"Phasis/<version>"`
 *  - `navigator.appName`   — `"Phasis"`
 *
 * Bundled with the fetch global (Phase 7 of the Fetch Pack) because
 * many libraries pre-check `navigator.userAgent` before issuing a
 * `fetch()`.
 */
final class NavigatorObject
{
    /**
     * Phasis self-identifier. Bumped manually per release; nothing
     * consumes it programmatically so the patch level is informational.
     */
    public const USER_AGENT = 'Phasis/0.1';

    public const APP_NAME = 'Phasis';

    public static function install(Environment $env): void
    {
        $navigator = new JsObject();

        $navigator->defineOwnProperty(
            'userAgent',
            PropertyDescriptor::data(new JsString(self::USER_AGENT), false, true, false),
        );
        $navigator->defineOwnProperty(
            'appName',
            PropertyDescriptor::data(new JsString(self::APP_NAME), false, true, false),
        );

        // Symbol.toStringTag = "Navigator" per HTML's Navigator interface.
        $navigator->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Navigator'), false, false, true),
        );

        $env->defineVar('navigator', $navigator);
    }
}
