<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Url;

/**
 * WHATWG URL Standard §4.5 "URL serializing" and §4.3.5 "Host
 * serializing".
 *
 * Pure-string operations; no JS values involved.
 */
final class UrlSerializer
{
    /**
     * §4.5 URL serializer. Returns the canonical string form.
     */
    public static function serializeUrl(UrlRecord $url, bool $excludeFragment = false): string
    {
        $output = $url->scheme . ':';

        if ($url->host !== null) {
            $output .= '//';
            if ($url->includesCredentials()) {
                $output .= $url->username;
                if ($url->password !== '') {
                    $output .= ':' . $url->password;
                }
                $output .= '@';
            }
            $output .= self::serializeHost($url->host);
            if ($url->port !== null) {
                $output .= ':' . (string) $url->port;
            }
        }

        if (is_string($url->path)) {
            $output .= $url->path;
        } else {
            // Spec quirk: file URLs with empty host get a leading "//" in
            // some edge cases; the standard handles this implicitly via
            // host = "" being non-null. We rely on that.
            if (
                $url->host === null
                && count($url->path) > 1
                && $url->path[0] === ''
            ) {
                $output .= '/.';
            }
            foreach ($url->path as $segment) {
                $output .= '/' . $segment;
            }
        }

        if ($url->query !== null) {
            $output .= '?' . $url->query;
        }
        if (!$excludeFragment && $url->fragment !== null) {
            $output .= '#' . $url->fragment;
        }

        return $output;
    }

    /**
     * §4.3.5 Host serializer.
     *
     * @param string|int|array<int,int> $host
     */
    public static function serializeHost(mixed $host): string
    {
        if (is_int($host)) {
            return self::serializeIpv4($host);
        }
        if (is_array($host)) {
            return '[' . self::serializeIpv6($host) . ']';
        }
        return (string) $host;
    }

    private static function serializeIpv4(int $address): string
    {
        $output = '';
        $n = $address;
        for ($i = 0; $i < 4; $i++) {
            $output = (string) ($n % 256) . $output;
            if ($i < 3) {
                $output = '.' . $output;
            }
            $n = intdiv($n, 256);
        }
        return $output;
    }

    /**
     * @param array<int,int> $pieces
     */
    private static function serializeIpv6(array $pieces): string
    {
        $compress = self::findIpv6Compress($pieces);
        $output = '';
        $ignore0 = false;
        for ($i = 0; $i < 8; $i++) {
            if ($ignore0 && $pieces[$i] === 0) {
                continue;
            }
            $ignore0 = false;
            if ($compress === $i) {
                $separator = $i === 0 ? '::' : ':';
                $output .= $separator;
                $ignore0 = true;
                continue;
            }
            $output .= dechex($pieces[$i]);
            if ($i !== 7) {
                $output .= ':';
            }
        }
        return $output;
    }

    /**
     * Return the index of the first piece in the longest run of two or more
     * consecutive 0 pieces, or null if no such run exists.
     *
     * @param array<int,int> $pieces
     */
    private static function findIpv6Compress(array $pieces): ?int
    {
        $longestStart = null;
        $longestLen = 1;
        $curStart = null;
        $curLen = 0;
        for ($i = 0; $i < 8; $i++) {
            if ($pieces[$i] === 0) {
                if ($curStart === null) {
                    $curStart = $i;
                    $curLen = 1;
                } else {
                    $curLen++;
                }
                if ($curLen > $longestLen) {
                    $longestLen = $curLen;
                    $longestStart = $curStart;
                }
            } else {
                $curStart = null;
                $curLen = 0;
            }
        }
        return $longestStart;
    }

    /**
     * §4.5.1 URL path serializer (returns the path portion only, with
     * leading slash for hierarchical paths). Used by the `pathname` getter.
     */
    public static function serializePath(UrlRecord $url): string
    {
        if (is_string($url->path)) {
            return $url->path;
        }
        $out = '';
        foreach ($url->path as $segment) {
            $out .= '/' . $segment;
        }
        return $out;
    }

    /**
     * §6.1 URL origin. Returns an origin string ("scheme://host:port") for
     * the schemes that have a tuple origin. Non-tuple origins return
     * "null".
     */
    public static function serializeOrigin(UrlRecord $url): string
    {
        switch ($url->scheme) {
            case 'ftp':
            case 'http':
            case 'https':
            case 'ws':
            case 'wss':
                $origin = $url->scheme . '://';
                if ($url->host !== null) {
                    $origin .= self::serializeHost($url->host);
                }
                if ($url->port !== null) {
                    $origin .= ':' . (string) $url->port;
                }
                return $origin;
            case 'blob':
                // §6.1 step "blob": parse the path as a URL and recurse.
                // Out of scope nicety; return "null" rather than wrongly
                // synthesizing an origin.
                return 'null';
            default:
                return 'null';
        }
    }
}
