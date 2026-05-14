<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Url;

use Phasis\BuiltIn\SymbolConstructor;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * Builds the URL.prototype getters and setters per WHATWG §6.1.
 *
 * Each property is exposed as an accessor property on URL.prototype.
 * Getters read directly from the [[URL]] internal slot (a UrlRecord);
 * setters mutate the UrlRecord, optionally re-running the parser at a
 * specific state, then refresh the linked URLSearchParams object.
 */
final class UrlGettersSetters
{
    public const URL_SLOT = '[[URL]]';
    public const QUERY_OBJ_SLOT = '[[URLSearchParams]]';

    /**
     * Install all accessors on the URL.prototype object.
     */
    public static function installOn(JsObject $proto): void
    {
        self::defineHrefAccessor($proto);
        self::defineOriginAccessor($proto);
        self::defineProtocolAccessor($proto);
        self::defineUsernameAccessor($proto);
        self::definePasswordAccessor($proto);
        self::defineHostAccessor($proto);
        self::defineHostnameAccessor($proto);
        self::definePortAccessor($proto);
        self::definePathnameAccessor($proto);
        self::defineSearchAccessor($proto);
        self::defineSearchParamsAccessor($proto);
        self::defineHashAccessor($proto);

        // toString / toJSON return href.
        $toStr = JsFunction::fromCallable('toString', function (JsValue $this_, array $args): JsValue {
            return new JsString(self::hrefOf(self::urlOf($this_)));
        }, 0);
        $proto->defineOwnProperty('toString', PropertyDescriptor::data($toStr, true, false, true));
        $proto->defineOwnProperty('toJSON', PropertyDescriptor::data(
            JsFunction::fromCallable('toJSON', function (JsValue $this_, array $args): JsValue {
                return new JsString(self::hrefOf(self::urlOf($this_)));
            }, 0),
            true,
            false,
            true,
        ));

        // Symbol.toStringTag = "URL".
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('URL'), false, false, true),
        );
    }

    public static function urlOf(JsValue $v): UrlRecord
    {
        if (!$v instanceof JsObject) {
            throw new TypeError('URL prototype accessor invoked on non-URL receiver');
        }
        $rec = $v->getInternalProperty(self::URL_SLOT);
        if (!$rec instanceof UrlRecord) {
            throw new TypeError('URL prototype accessor invoked on non-URL receiver');
        }
        return $rec;
    }

    public static function hrefOf(UrlRecord $url): string
    {
        return UrlSerializer::serializeUrl($url);
    }

    private static function defineHrefAccessor(JsObject $proto): void
    {
        $get = JsFunction::fromCallable('get href', function (JsValue $this_): JsValue {
            return new JsString(self::hrefOf(self::urlOf($this_)));
        }, 0);
        $set = JsFunction::fromCallable('set href', function (JsValue $this_, array $args): JsValue {
            $url = self::urlOf($this_);
            $input = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            $parsed = UrlParser::parse($input);
            if ($parsed === null) {
                throw new TypeError("Invalid URL: '$input'");
            }
            // Replace fields in-place so the prototype's [[URL]] reference
            // stays valid (live link with searchParams).
            self::copyInto($url, $parsed);
            self::refreshSearchParams($this_);
            return JsUndefined::instance();
        }, 1);
        $proto->defineOwnProperty('href', PropertyDescriptor::accessor(
            get: $get,
            set: $set,
            enumerable: true,
            configurable: true,
        ));
    }

    private static function defineOriginAccessor(JsObject $proto): void
    {
        $get = JsFunction::fromCallable('get origin', function (JsValue $this_): JsValue {
            return new JsString(UrlSerializer::serializeOrigin(self::urlOf($this_)));
        }, 0);
        $proto->defineOwnProperty('origin', PropertyDescriptor::accessor(
            get: $get,
            set: null,
            enumerable: true,
            configurable: true,
        ));
    }

    private static function defineProtocolAccessor(JsObject $proto): void
    {
        $get = JsFunction::fromCallable('get protocol', function (JsValue $this_): JsValue {
            return new JsString(self::urlOf($this_)->scheme . ':');
        }, 0);
        $set = JsFunction::fromCallable('set protocol', function (JsValue $this_, array $args): JsValue {
            $url = self::urlOf($this_);
            $input = TypeConversion::toString($args[0] ?? JsUndefined::instance()) . ':';
            UrlParser::parse($input, null, $url, UrlParser::STATE_SCHEME_START);
            return JsUndefined::instance();
        }, 1);
        $proto->defineOwnProperty('protocol', PropertyDescriptor::accessor(
            get: $get,
            set: $set,
            enumerable: true,
            configurable: true,
        ));
    }

    private static function defineUsernameAccessor(JsObject $proto): void
    {
        $get = JsFunction::fromCallable('get username', function (JsValue $this_): JsValue {
            return new JsString(self::urlOf($this_)->username);
        }, 0);
        $set = JsFunction::fromCallable('set username', function (JsValue $this_, array $args): JsValue {
            $url = self::urlOf($this_);
            if ($url->host === null || $url->host === '' || $url->scheme === 'file') {
                return JsUndefined::instance();
            }
            $input = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            $encoded = '';
            $len = strlen($input);
            for ($i = 0; $i < $len; $i++) {
                $encoded .= UrlParser::percentEncode(ord($input[$i]), 'userinfo');
            }
            $url->username = $encoded;
            return JsUndefined::instance();
        }, 1);
        $proto->defineOwnProperty('username', PropertyDescriptor::accessor(
            get: $get,
            set: $set,
            enumerable: true,
            configurable: true,
        ));
    }

    private static function definePasswordAccessor(JsObject $proto): void
    {
        $get = JsFunction::fromCallable('get password', function (JsValue $this_): JsValue {
            return new JsString(self::urlOf($this_)->password);
        }, 0);
        $set = JsFunction::fromCallable('set password', function (JsValue $this_, array $args): JsValue {
            $url = self::urlOf($this_);
            if ($url->host === null || $url->host === '' || $url->scheme === 'file') {
                return JsUndefined::instance();
            }
            $input = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            $encoded = '';
            $len = strlen($input);
            for ($i = 0; $i < $len; $i++) {
                $encoded .= UrlParser::percentEncode(ord($input[$i]), 'userinfo');
            }
            $url->password = $encoded;
            return JsUndefined::instance();
        }, 1);
        $proto->defineOwnProperty('password', PropertyDescriptor::accessor(
            get: $get,
            set: $set,
            enumerable: true,
            configurable: true,
        ));
    }

    private static function defineHostAccessor(JsObject $proto): void
    {
        $get = JsFunction::fromCallable('get host', function (JsValue $this_): JsValue {
            $url = self::urlOf($this_);
            if ($url->host === null) {
                return new JsString('');
            }
            $h = UrlSerializer::serializeHost($url->host);
            if ($url->port !== null) {
                $h .= ':' . (string) $url->port;
            }
            return new JsString($h);
        }, 0);
        $set = JsFunction::fromCallable('set host', function (JsValue $this_, array $args): JsValue {
            $url = self::urlOf($this_);
            if ($url->hasOpaquePath()) {
                return JsUndefined::instance();
            }
            $input = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            UrlParser::parse($input, null, $url, UrlParser::STATE_HOST);
            return JsUndefined::instance();
        }, 1);
        $proto->defineOwnProperty('host', PropertyDescriptor::accessor(
            get: $get,
            set: $set,
            enumerable: true,
            configurable: true,
        ));
    }

    private static function defineHostnameAccessor(JsObject $proto): void
    {
        $get = JsFunction::fromCallable('get hostname', function (JsValue $this_): JsValue {
            $url = self::urlOf($this_);
            if ($url->host === null) {
                return new JsString('');
            }
            return new JsString(UrlSerializer::serializeHost($url->host));
        }, 0);
        $set = JsFunction::fromCallable('set hostname', function (JsValue $this_, array $args): JsValue {
            $url = self::urlOf($this_);
            if ($url->hasOpaquePath()) {
                return JsUndefined::instance();
            }
            $input = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            UrlParser::parse($input, null, $url, UrlParser::STATE_HOSTNAME);
            return JsUndefined::instance();
        }, 1);
        $proto->defineOwnProperty('hostname', PropertyDescriptor::accessor(
            get: $get,
            set: $set,
            enumerable: true,
            configurable: true,
        ));
    }

    private static function definePortAccessor(JsObject $proto): void
    {
        $get = JsFunction::fromCallable('get port', function (JsValue $this_): JsValue {
            $port = self::urlOf($this_)->port;
            return new JsString($port === null ? '' : (string) $port);
        }, 0);
        $set = JsFunction::fromCallable('set port', function (JsValue $this_, array $args): JsValue {
            $url = self::urlOf($this_);
            if ($url->host === null || $url->host === '' || $url->scheme === 'file' || $url->hasOpaquePath()) {
                return JsUndefined::instance();
            }
            $input = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            if ($input === '') {
                $url->port = null;
                return JsUndefined::instance();
            }
            UrlParser::parse($input, null, $url, UrlParser::STATE_PORT);
            return JsUndefined::instance();
        }, 1);
        $proto->defineOwnProperty('port', PropertyDescriptor::accessor(
            get: $get,
            set: $set,
            enumerable: true,
            configurable: true,
        ));
    }

    private static function definePathnameAccessor(JsObject $proto): void
    {
        $get = JsFunction::fromCallable('get pathname', function (JsValue $this_): JsValue {
            return new JsString(UrlSerializer::serializePath(self::urlOf($this_)));
        }, 0);
        $set = JsFunction::fromCallable('set pathname', function (JsValue $this_, array $args): JsValue {
            $url = self::urlOf($this_);
            if ($url->hasOpaquePath()) {
                return JsUndefined::instance();
            }
            $url->path = [];
            $input = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            UrlParser::parse($input, null, $url, UrlParser::STATE_PATH_START);
            return JsUndefined::instance();
        }, 1);
        $proto->defineOwnProperty('pathname', PropertyDescriptor::accessor(
            get: $get,
            set: $set,
            enumerable: true,
            configurable: true,
        ));
    }

    private static function defineSearchAccessor(JsObject $proto): void
    {
        $get = JsFunction::fromCallable('get search', function (JsValue $this_): JsValue {
            $q = self::urlOf($this_)->query;
            if ($q === null || $q === '') {
                return new JsString('');
            }
            return new JsString('?' . $q);
        }, 0);
        $set = JsFunction::fromCallable('set search', function (JsValue $this_, array $args): JsValue {
            $url = self::urlOf($this_);
            $input = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            if ($input === '') {
                $url->query = null;
            } else {
                if ($input[0] === '?') {
                    $input = substr($input, 1);
                }
                $url->query = '';
                UrlParser::parse($input, null, $url, UrlParser::STATE_QUERY);
            }
            // Refresh the linked URLSearchParams object's pair list.
            self::refreshSearchParams($this_);
            return JsUndefined::instance();
        }, 1);
        $proto->defineOwnProperty('search', PropertyDescriptor::accessor(
            get: $get,
            set: $set,
            enumerable: true,
            configurable: true,
        ));
    }

    private static function defineSearchParamsAccessor(JsObject $proto): void
    {
        $get = JsFunction::fromCallable('get searchParams', function (JsValue $this_): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('URL.prototype.searchParams getter called on non-URL');
            }
            $cached = $this_->getInternalProperty(self::QUERY_OBJ_SLOT);
            if ($cached instanceof JsObject) {
                return $cached;
            }
            $url = self::urlOf($this_);
            $pairs = SearchParams::parseQuery($url->query ?? '');
            $obj = SearchParams::createObject($pairs, $url);
            $this_->setInternalProperty(self::QUERY_OBJ_SLOT, $obj);
            return $obj;
        }, 0);
        $proto->defineOwnProperty('searchParams', PropertyDescriptor::accessor(
            get: $get,
            set: null,
            enumerable: true,
            configurable: true,
        ));
    }

    private static function defineHashAccessor(JsObject $proto): void
    {
        $get = JsFunction::fromCallable('get hash', function (JsValue $this_): JsValue {
            $f = self::urlOf($this_)->fragment;
            if ($f === null || $f === '') {
                return new JsString('');
            }
            return new JsString('#' . $f);
        }, 0);
        $set = JsFunction::fromCallable('set hash', function (JsValue $this_, array $args): JsValue {
            $url = self::urlOf($this_);
            $input = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            if ($input === '') {
                $url->fragment = null;
                return JsUndefined::instance();
            }
            if ($input[0] === '#') {
                $input = substr($input, 1);
            }
            $url->fragment = '';
            UrlParser::parse($input, null, $url, UrlParser::STATE_FRAGMENT);
            return JsUndefined::instance();
        }, 1);
        $proto->defineOwnProperty('hash', PropertyDescriptor::accessor(
            get: $get,
            set: $set,
            enumerable: true,
            configurable: true,
        ));
    }

    private static function copyInto(UrlRecord $dst, UrlRecord $src): void
    {
        $dst->scheme = $src->scheme;
        $dst->username = $src->username;
        $dst->password = $src->password;
        $dst->host = $src->host;
        $dst->port = $src->port;
        // PHP arrays are copy-on-write; assignment already deep-copies a
        // list of strings, so no array_values() needed here.
        $dst->path = $src->path;
        $dst->query = $src->query;
        $dst->fragment = $src->fragment;
        $dst->cannotBeABase = $src->cannotBeABase;
    }

    /**
     * Re-parse the URL's query into the cached URLSearchParams object so
     * that callers holding a reference see the updated key/value list.
     */
    private static function refreshSearchParams(JsValue $this_): void
    {
        if (!$this_ instanceof JsObject) {
            return;
        }
        $cached = $this_->getInternalProperty(self::QUERY_OBJ_SLOT);
        if (!$cached instanceof JsObject) {
            return;
        }
        $url = self::urlOf($this_);
        $pairs = SearchParams::parseQuery($url->query ?? '');
        $cached->setInternalProperty('[[SearchParamsList]]', $pairs);
        // The linked-url slot stays; mutations on the cached params still
        // serialize back into $url->query.
    }
}
