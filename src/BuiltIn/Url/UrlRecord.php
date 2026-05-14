<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Url;

/**
 * Internal data record for a parsed URL.
 *
 * Mirrors WHATWG URL Standard §4.1 "URL representation".
 *
 * This is a plain mutable PHP class — it lives behind the JS URL object
 * as an internal slot (see UrlConstructor::URL_SLOT). The JS layer never
 * sees this object directly; getters / setters round-trip through here.
 *
 * Fields:
 *  - scheme:     ASCII string, no trailing ":".
 *  - username:   percent-encoded ASCII (may be "").
 *  - password:   percent-encoded ASCII (may be "").
 *  - host:       null | string | int (IPv4) | array<int,int> (IPv6 8 16-bit pieces)
 *                Per spec "host" is a tagged variant. We tag by PHP type.
 *  - port:       null | int (0–65535).
 *  - path:       list<string> for hierarchical URLs, or string for opaque ("cannot-be-a-base") URLs.
 *  - query:      null | string.
 *  - fragment:   null | string.
 *  - cannotBeABase: true when scheme is non-special and there was no "//" authority.
 */
final class UrlRecord
{
    public string $scheme = '';
    public string $username = '';
    public string $password = '';

    /** @var null|string|int|array<int,int> */
    public mixed $host = null;

    public ?int $port = null;

    /** @var list<string>|string */
    public array|string $path = [];

    public ?string $query = null;
    public ?string $fragment = null;
    public bool $cannotBeABase = false;

    public function isSpecial(): bool
    {
        return UrlParser::isSpecialScheme($this->scheme);
    }

    public function includesCredentials(): bool
    {
        return $this->username !== '' || $this->password !== '';
    }

    public function hasOpaquePath(): bool
    {
        return is_string($this->path);
    }

    public function clone(): self
    {
        // PHP copy-on-write covers array fields; primitives are by-value.
        $copy = new self();
        $copy->scheme = $this->scheme;
        $copy->username = $this->username;
        $copy->password = $this->password;
        $copy->host = $this->host;
        $copy->port = $this->port;
        $copy->path = $this->path;
        $copy->query = $this->query;
        $copy->fragment = $this->fragment;
        $copy->cannotBeABase = $this->cannotBeABase;
        return $copy;
    }
}
