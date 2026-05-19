<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Url;

/**
 * WHATWG URL Standard §4.4 "URL parsing".
 *
 * State-machine parser that takes an input string and an optional base
 * URL record, runs the spec state machine, and either returns the parsed
 * UrlRecord or null on failure (validation error that causes "failure").
 *
 * Limitations vs. full spec:
 *
 *  - IDN (Punycode / UTS#46) is OUT OF SCOPE for v1. Non-ASCII hostnames
 *    on special schemes are reported as failure (parser returns null).
 *    For non-special schemes the hostname is taken percent-encoded as-is
 *    (matches what the spec calls an "opaque host"), which is the lenient
 *    path browsers actually use for `git+ssh://`, `mongodb://`, etc.
 *
 *  - Validation errors that the spec catalogs as recoverable (e.g. "URL
 *    code points" in path) are silently accepted; we never surface
 *    them. Only failure-class errors return null.
 *
 *  - The "encoding override" parameter from the spec is unused — we run
 *    everything as UTF-8 because that is what JS strings are.
 *
 * The spec's "state override" parameter is supported via parse() — used
 * by setters to re-enter the machine at a specific state (e.g. host
 * setter starts at HOST state instead of SCHEME_START).
 */
final class UrlParser
{
    // ---- Parser states ----
    public const STATE_SCHEME_START = 1;
    public const STATE_SCHEME = 2;
    public const STATE_NO_SCHEME = 3;
    public const STATE_SPECIAL_RELATIVE_OR_AUTHORITY = 4;
    public const STATE_PATH_OR_AUTHORITY = 5;
    public const STATE_RELATIVE = 6;
    public const STATE_RELATIVE_SLASH = 7;
    public const STATE_SPECIAL_AUTHORITY_SLASHES = 8;
    public const STATE_SPECIAL_AUTHORITY_IGNORE_SLASHES = 9;
    public const STATE_AUTHORITY = 10;
    public const STATE_HOST = 11;
    public const STATE_HOSTNAME = 12;
    public const STATE_PORT = 13;
    public const STATE_FILE = 14;
    public const STATE_FILE_SLASH = 15;
    public const STATE_FILE_HOST = 16;
    public const STATE_PATH_START = 17;
    public const STATE_PATH = 18;
    public const STATE_OPAQUE_PATH = 19;
    public const STATE_QUERY = 20;
    public const STATE_FRAGMENT = 21;

    /** @var array<string, int|null> default ports for special schemes. */
    private const SPECIAL_SCHEMES = [
        'ftp' => 21,
        'file' => null,
        'http' => 80,
        'https' => 443,
        'ws' => 80,
        'wss' => 443,
    ];

    public static function isSpecialScheme(string $scheme): bool
    {
        return array_key_exists($scheme, self::SPECIAL_SCHEMES);
    }

    /**
     * Returns the default port for a special scheme, or null if the scheme
     * is non-special (or "file" which intentionally has no default port).
     */
    public static function defaultPort(string $scheme): ?int
    {
        return self::SPECIAL_SCHEMES[$scheme] ?? null;
    }

    /**
     * Public entry point. Parses input optionally with a base URL.
     *
     * @param string         $input       Input string (already JS-side stringified).
     * @param UrlRecord|null $base        Optional base URL.
     * @param UrlRecord|null $url         Optional existing record to populate (setters).
     * @param int|null       $stateOverride Optional state override (setters).
     * @return UrlRecord|null Null on parse failure.
     */
    public static function parse(
        string $input,
        ?UrlRecord $base = null,
        ?UrlRecord $url = null,
        ?int $stateOverride = null,
    ): ?UrlRecord {
        // §4.4 step 1: if url is null, set url to a new URL.
        if ($url === null) {
            $url = new UrlRecord();

            // Strip leading and trailing C0 controls and space.
            $input = self::stripLeadingTrailingC0OrSpace($input);
        }

        // Strip all ASCII tab/LF/CR from input.
        $input = self::stripAsciiTabsAndNewlines($input);

        $buffer = '';
        $atSignSeen = false;
        $insideBrackets = false;
        $passwordTokenSeen = false;
        $state = $stateOverride ?? self::STATE_SCHEME_START;
        $pointer = 0;
        $len = strlen($input);

        // Convenience: char at pointer or "EOF" sentinel.
        // We use -1 for EOF and codepoint integers otherwise.
        // Since input has been validated to ASCII-safe so far, we work
        // byte-by-byte. The spec is fully byte-oriented except for IDN
        // (out of scope) and the "host parsing" sub-routine which we
        // handle separately.
        while (true) {
            $c = $pointer < $len ? ord($input[$pointer]) : -1;

            switch ($state) {
                // ---- scheme start state ----
                case self::STATE_SCHEME_START:
                    if ($c !== -1 && self::isAsciiAlpha($c)) {
                        $buffer .= strtolower(chr($c));
                        $state = self::STATE_SCHEME;
                    } elseif ($stateOverride === null) {
                        $state = self::STATE_NO_SCHEME;
                        $pointer--;
                    } else {
                        // State-override mode + invalid first char → return failure.
                        return null;
                    }
                    break;

                // ---- scheme state ----
                case self::STATE_SCHEME:
                    if ($c !== -1 && (self::isAsciiAlphanumeric($c) || $c === 0x2b || $c === 0x2d || $c === 0x2e)) {
                        $buffer .= strtolower(chr($c));
                    } elseif ($c === 0x3a) { // ':'
                        if ($stateOverride !== null) {
                            $bufferIsSpecial = self::isSpecialScheme($buffer);
                            $urlIsSpecial = $url->isSpecial();
                            if ($urlIsSpecial !== $bufferIsSpecial) {
                                return $url;
                            }
                            if (($url->includesCredentials() || $url->port !== null) && $buffer === 'file') {
                                return $url;
                            }
                            if (
                                $url->scheme === 'file' && (
                                $url->host === '' || $url->host === null
                                )
                            ) {
                                return $url;
                            }
                        }
                        $url->scheme = $buffer;
                        if ($stateOverride !== null) {
                            if ($url->port === self::defaultPort($url->scheme)) {
                                $url->port = null;
                            }
                            return $url;
                        }
                        $buffer = '';
                        if ($url->scheme === 'file') {
                            $state = self::STATE_FILE;
                        } elseif (
                            $url->isSpecial()
                            && $base !== null
                            && $base->scheme === $url->scheme
                        ) {
                            $state = self::STATE_SPECIAL_RELATIVE_OR_AUTHORITY;
                        } elseif ($url->isSpecial()) {
                            $state = self::STATE_SPECIAL_AUTHORITY_SLASHES;
                        } elseif ($pointer + 1 < $len && $input[$pointer + 1] === '/') {
                            $state = self::STATE_PATH_OR_AUTHORITY;
                            $pointer++;
                        } else {
                            $url->path = '';
                            $state = self::STATE_OPAQUE_PATH;
                        }
                    } else {
                        if ($stateOverride !== null) {
                            return null;
                        }
                        $buffer = '';
                        $state = self::STATE_NO_SCHEME;
                        $pointer = -1;
                    }
                    break;

                // ---- no scheme state ----
                case self::STATE_NO_SCHEME:
                    if ($base === null || ($base->hasOpaquePath() && $c !== 0x23)) {
                        return null;
                    }
                    if ($base->hasOpaquePath() && $c === 0x23) {
                        $url->scheme = $base->scheme;
                        $url->path = $base->path;
                        $url->query = $base->query;
                        $url->fragment = '';
                        $state = self::STATE_FRAGMENT;
                    } elseif ($base->scheme !== 'file') {
                        $state = self::STATE_RELATIVE;
                        $pointer--;
                    } else {
                        $state = self::STATE_FILE;
                        $pointer--;
                    }
                    break;

                // ---- special relative or authority state ----
                case self::STATE_SPECIAL_RELATIVE_OR_AUTHORITY:
                    if ($c === 0x2f && $pointer + 1 < $len && $input[$pointer + 1] === '/') {
                        $state = self::STATE_SPECIAL_AUTHORITY_IGNORE_SLASHES;
                        $pointer++;
                    } else {
                        $state = self::STATE_RELATIVE;
                        $pointer--;
                    }
                    break;

                // ---- path or authority state ----
                case self::STATE_PATH_OR_AUTHORITY:
                    if ($c === 0x2f) {
                        $state = self::STATE_AUTHORITY;
                    } else {
                        $state = self::STATE_PATH;
                        $pointer--;
                    }
                    break;

                // ---- relative state ----
                case self::STATE_RELATIVE:
                    /** @var UrlRecord $base */
                    $url->scheme = $base->scheme;
                    if ($c === 0x2f) {
                        $state = self::STATE_RELATIVE_SLASH;
                    } elseif ($url->isSpecial() && $c === 0x5c) {
                        $state = self::STATE_RELATIVE_SLASH;
                    } else {
                        $url->username = $base->username;
                        $url->password = $base->password;
                        $url->host = $base->host;
                        $url->port = $base->port;
                        // PHP copy-on-write deep-copies on assignment.
                        $url->path = $base->path;
                        $url->query = $base->query;
                        if ($c === 0x3f) {
                            $url->query = '';
                            $state = self::STATE_QUERY;
                        } elseif ($c === 0x23) {
                            $url->fragment = '';
                            $state = self::STATE_FRAGMENT;
                        } elseif ($c !== -1) {
                            $url->query = null;
                            self::shortenPath($url);
                            $state = self::STATE_PATH;
                            $pointer--;
                        }
                    }
                    break;

                // ---- relative slash state ----
                case self::STATE_RELATIVE_SLASH:
                    if ($url->isSpecial() && ($c === 0x2f || $c === 0x5c)) {
                        $state = self::STATE_SPECIAL_AUTHORITY_IGNORE_SLASHES;
                    } elseif ($c === 0x2f) {
                        $state = self::STATE_AUTHORITY;
                    } else {
                        /** @var UrlRecord $base */
                        $url->username = $base->username;
                        $url->password = $base->password;
                        $url->host = $base->host;
                        $url->port = $base->port;
                        $state = self::STATE_PATH;
                        $pointer--;
                    }
                    break;

                // ---- special authority slashes state ----
                case self::STATE_SPECIAL_AUTHORITY_SLASHES:
                    if ($c === 0x2f && $pointer + 1 < $len && $input[$pointer + 1] === '/') {
                        $state = self::STATE_SPECIAL_AUTHORITY_IGNORE_SLASHES;
                        $pointer++;
                    } else {
                        $state = self::STATE_SPECIAL_AUTHORITY_IGNORE_SLASHES;
                        $pointer--;
                    }
                    break;

                // ---- special authority ignore slashes state ----
                case self::STATE_SPECIAL_AUTHORITY_IGNORE_SLASHES:
                    if ($c !== 0x2f && $c !== 0x5c) {
                        $state = self::STATE_AUTHORITY;
                        $pointer--;
                    }
                    break;

                // ---- authority state ----
                case self::STATE_AUTHORITY:
                    if ($c === 0x40) { // '@'
                        if ($atSignSeen) {
                            $buffer = '%40' . $buffer;
                        }
                        $atSignSeen = true;
                        $bufferLen = strlen($buffer);
                        for ($i = 0; $i < $bufferLen; $i++) {
                            $bcCp = ord($buffer[$i]);
                            if ($bcCp === 0x3a && !$passwordTokenSeen) {
                                $passwordTokenSeen = true;
                                continue;
                            }
                            $encoded = self::percentEncode($bcCp, 'userinfo');
                            if ($passwordTokenSeen) {
                                $url->password .= $encoded;
                            } else {
                                $url->username .= $encoded;
                            }
                        }
                        $buffer = '';
                    } elseif (
                        $c === -1
                        || $c === 0x2f
                        || $c === 0x3f
                        || $c === 0x23
                        || ($url->isSpecial() && $c === 0x5c)
                    ) {
                        if ($atSignSeen && $buffer === '') {
                            return null;
                        }
                        $pointer -= strlen($buffer) + 1;
                        $buffer = '';
                        $state = self::STATE_HOST;
                    } else {
                        $buffer .= chr($c);
                    }
                    break;

                // ---- host and hostname state ----
                case self::STATE_HOST:
                case self::STATE_HOSTNAME:
                    if ($stateOverride !== null && $url->scheme === 'file') {
                        $state = self::STATE_FILE_HOST;
                        $pointer--;
                    } elseif ($c === 0x3a && !$insideBrackets) {
                        if ($buffer === '') {
                            return null;
                        }
                        if ($stateOverride === self::STATE_HOSTNAME) {
                            return $url;
                        }
                        $host = self::parseHost($buffer, !$url->isSpecial());
                        if ($host === false) {
                            return null;
                        }
                        $url->host = $host;
                        $buffer = '';
                        $state = self::STATE_PORT;
                    } elseif (
                        $c === -1
                        || $c === 0x2f
                        || $c === 0x3f
                        || $c === 0x23
                        || ($url->isSpecial() && $c === 0x5c)
                    ) {
                        $pointer--;
                        if ($url->isSpecial() && $buffer === '') {
                            return null;
                        }
                        if (
                            $stateOverride !== null
                            && $buffer === ''
                            && ($url->includesCredentials() || $url->port !== null)
                        ) {
                            return $url;
                        }
                        $host = self::parseHost($buffer, !$url->isSpecial());
                        if ($host === false) {
                            return null;
                        }
                        $url->host = $host;
                        $buffer = '';
                        $state = self::STATE_PATH_START;
                        if ($stateOverride !== null) {
                            return $url;
                        }
                    } else {
                        if ($c === 0x5b) {
                            $insideBrackets = true;
                        }
                        if ($c === 0x5d) {
                            $insideBrackets = false;
                        }
                        $buffer .= chr($c);
                    }
                    break;

                // ---- port state ----
                case self::STATE_PORT:
                    if ($c !== -1 && self::isAsciiDigit($c)) {
                        $buffer .= chr($c);
                    } elseif (
                        $c === -1
                        || $c === 0x2f
                        || $c === 0x3f
                        || $c === 0x23
                        || ($url->isSpecial() && $c === 0x5c)
                        || $stateOverride !== null
                    ) {
                        if ($buffer !== '') {
                            $portVal = (int) $buffer;
                            if ($portVal > 65535) {
                                return null;
                            }
                            $url->port = $portVal === self::defaultPort($url->scheme) ? null : $portVal;
                            $buffer = '';
                        } elseif ($stateOverride !== null) {
                            return $url;
                        }
                        if ($stateOverride !== null) {
                            return $url;
                        }
                        $state = self::STATE_PATH_START;
                        $pointer--;
                    } else {
                        return null;
                    }
                    break;

                // ---- file state ----
                case self::STATE_FILE:
                    $url->scheme = 'file';
                    $url->host = '';
                    if ($c === 0x2f || $c === 0x5c) {
                        $state = self::STATE_FILE_SLASH;
                    } elseif ($base !== null && $base->scheme === 'file') {
                        $url->host = $base->host;
                        // PHP copy-on-write deep-copies on assignment.
                        $url->path = $base->path;
                        $url->query = $base->query;
                        if ($c === 0x3f) {
                            $url->query = '';
                            $state = self::STATE_QUERY;
                        } elseif ($c === 0x23) {
                            $url->fragment = '';
                            $state = self::STATE_FRAGMENT;
                        } elseif ($c !== -1) {
                            $url->query = null;
                            if (!self::startsWithWindowsDriveLetter(substr($input, $pointer))) {
                                self::shortenPath($url);
                            } else {
                                $url->path = [];
                            }
                            $state = self::STATE_PATH;
                            $pointer--;
                        }
                    } else {
                        $state = self::STATE_PATH;
                        $pointer--;
                    }
                    break;

                // ---- file slash state ----
                case self::STATE_FILE_SLASH:
                    if ($c === 0x2f || $c === 0x5c) {
                        $state = self::STATE_FILE_HOST;
                    } else {
                        if (
                            $base !== null
                            && $base->scheme === 'file'
                            && !self::startsWithWindowsDriveLetter(substr($input, $pointer))
                        ) {
                            $basePath = is_array($base->path) ? $base->path : [];
                            if (
                                isset($basePath[0])
                                && self::isNormalizedWindowsDriveLetter($basePath[0])
                            ) {
                                $url->path = is_array($url->path) ? $url->path : [];
                                $url->path[] = $basePath[0];
                            } else {
                                $url->host = $base->host;
                            }
                        }
                        $state = self::STATE_PATH;
                        $pointer--;
                    }
                    break;

                // ---- file host state ----
                case self::STATE_FILE_HOST:
                    if ($c === -1 || $c === 0x2f || $c === 0x5c || $c === 0x3f || $c === 0x23) {
                        $pointer--;
                        if (
                            $stateOverride === null
                            && self::isWindowsDriveLetter($buffer)
                        ) {
                            $state = self::STATE_PATH;
                        } elseif ($buffer === '') {
                            $url->host = '';
                            if ($stateOverride !== null) {
                                return $url;
                            }
                            $state = self::STATE_PATH_START;
                        } else {
                            $host = self::parseHost($buffer, !$url->isSpecial());
                            if ($host === false) {
                                return null;
                            }
                            if ($host === 'localhost') {
                                $host = '';
                            }
                            $url->host = $host;
                            if ($stateOverride !== null) {
                                return $url;
                            }
                            $buffer = '';
                            $state = self::STATE_PATH_START;
                        }
                    } else {
                        $buffer .= chr($c);
                    }
                    break;

                // ---- path start state ----
                case self::STATE_PATH_START:
                    if ($url->isSpecial()) {
                        $state = self::STATE_PATH;
                        if ($c !== 0x2f && $c !== 0x5c) {
                            $pointer--;
                        }
                    } elseif ($stateOverride === null && $c === 0x3f) {
                        $url->query = '';
                        $state = self::STATE_QUERY;
                    } elseif ($stateOverride === null && $c === 0x23) {
                        $url->fragment = '';
                        $state = self::STATE_FRAGMENT;
                    } elseif ($c !== -1) {
                        $state = self::STATE_PATH;
                        if ($c !== 0x2f) {
                            $pointer--;
                        }
                    } elseif ($stateOverride !== null && $url->host === null) {
                        $url->path = is_array($url->path) ? $url->path : [];
                        $url->path[] = '';
                    }
                    break;

                // ---- path state ----
                case self::STATE_PATH:
                    if (
                        $c === -1
                        || $c === 0x2f
                        || ($url->isSpecial() && $c === 0x5c)
                        || ($stateOverride === null && ($c === 0x3f || $c === 0x23))
                    ) {
                        if (self::isDoubleDotPathSegment($buffer)) {
                            self::shortenPath($url);
                            if ($c !== 0x2f && !($url->isSpecial() && $c === 0x5c)) {
                                $url->path = is_array($url->path) ? $url->path : [];
                                $url->path[] = '';
                            }
                        } elseif (self::isSingleDotPathSegment($buffer)) {
                            if ($c !== 0x2f && !($url->isSpecial() && $c === 0x5c)) {
                                $url->path = is_array($url->path) ? $url->path : [];
                                $url->path[] = '';
                            }
                        } else {
                            if (
                                $url->scheme === 'file'
                                && (is_array($url->path) && count($url->path) === 0)
                                && self::isWindowsDriveLetter($buffer)
                            ) {
                                $buffer = $buffer[0] . ':';
                            }
                            $url->path = is_array($url->path) ? $url->path : [];
                            $url->path[] = $buffer;
                        }
                        $buffer = '';
                        if ($c === 0x3f) {
                            $url->query = '';
                            $state = self::STATE_QUERY;
                        } elseif ($c === 0x23) {
                            $url->fragment = '';
                            $state = self::STATE_FRAGMENT;
                        }
                    } else {
                        // UTF-8 codepoint percent-encode using "path" set.
                        $pointer = self::appendEncodedCodepoint($input, $pointer, $buffer, 'path');
                    }
                    break;

                // ---- opaque path state ----
                case self::STATE_OPAQUE_PATH:
                    if ($c === 0x3f) {
                        $url->query = '';
                        $state = self::STATE_QUERY;
                    } elseif ($c === 0x23) {
                        $url->fragment = '';
                        $state = self::STATE_FRAGMENT;
                    } elseif ($c !== -1) {
                        $current = is_string($url->path) ? $url->path : '';
                        $pointer = self::appendEncodedCodepoint($input, $pointer, $current, 'opaque-path');
                        $url->path = $current;
                    }
                    break;

                // ---- query state ----
                case self::STATE_QUERY:
                    if (
                        $stateOverride === null && $c === 0x23
                    ) {
                        $url->fragment = '';
                        $state = self::STATE_FRAGMENT;
                    } elseif ($c !== -1) {
                        $set = $url->isSpecial() ? 'special-query' : 'query';
                        $q = $url->query ?? '';
                        $pointer = self::appendEncodedCodepoint($input, $pointer, $q, $set);
                        $url->query = $q;
                    }
                    break;

                // ---- fragment state ----
                case self::STATE_FRAGMENT:
                    if ($c !== -1) {
                        $f = $url->fragment ?? '';
                        $pointer = self::appendEncodedCodepoint($input, $pointer, $f, 'fragment');
                        $url->fragment = $f;
                    }
                    break;
            }

            if ($pointer >= $len) {
                break;
            }
            $pointer++;
        }

        return $url;
    }

    // ---- helpers ----------------------------------------------------------

    private static function stripLeadingTrailingC0OrSpace(string $s): string
    {
        $start = 0;
        $end = strlen($s);
        while ($start < $end && ord($s[$start]) <= 0x20) {
            $start++;
        }
        while ($end > $start && ord($s[$end - 1]) <= 0x20) {
            $end--;
        }
        return substr($s, $start, $end - $start);
    }

    private static function stripAsciiTabsAndNewlines(string $s): string
    {
        return strtr($s, ["\t" => '', "\n" => '', "\r" => '']);
    }

    public static function isAsciiAlpha(int $c): bool
    {
        return ($c >= 0x41 && $c <= 0x5a) || ($c >= 0x61 && $c <= 0x7a);
    }

    public static function isAsciiDigit(int $c): bool
    {
        return $c >= 0x30 && $c <= 0x39;
    }

    public static function isAsciiAlphanumeric(int $c): bool
    {
        return self::isAsciiAlpha($c) || self::isAsciiDigit($c);
    }

    private static function isAsciiHexDigit(int $c): bool
    {
        return self::isAsciiDigit($c)
            || ($c >= 0x41 && $c <= 0x46)
            || ($c >= 0x61 && $c <= 0x66);
    }

    private static function isSingleDotPathSegment(string $s): bool
    {
        $lower = strtolower($s);
        return $lower === '.' || $lower === '%2e';
    }

    private static function isDoubleDotPathSegment(string $s): bool
    {
        $lower = strtolower($s);
        return $lower === '..' || $lower === '.%2e' || $lower === '%2e.' || $lower === '%2e%2e';
    }

    public static function isWindowsDriveLetter(string $s): bool
    {
        return strlen($s) === 2
            && self::isAsciiAlpha(ord($s[0]))
            && ($s[1] === ':' || $s[1] === '|');
    }

    public static function isNormalizedWindowsDriveLetter(string $s): bool
    {
        return strlen($s) === 2 && self::isAsciiAlpha(ord($s[0])) && $s[1] === ':';
    }

    private static function startsWithWindowsDriveLetter(string $s): bool
    {
        $len = strlen($s);
        if ($len < 2) {
            return false;
        }
        if (!(self::isAsciiAlpha(ord($s[0])) && ($s[1] === ':' || $s[1] === '|'))) {
            return false;
        }
        if ($len === 2) {
            return true;
        }
        $third = $s[2];
        return $third === '/' || $third === '\\' || $third === '?' || $third === '#';
    }

    /**
     * §4.4 shorten URL's path.
     */
    public static function shortenPath(UrlRecord $url): void
    {
        if (!is_array($url->path)) {
            return;
        }
        if (
            $url->scheme === 'file'
            && count($url->path) === 1
            && self::isNormalizedWindowsDriveLetter($url->path[0])
        ) {
            return;
        }
        array_pop($url->path);
    }

    /**
     * Percent-encode a single byte against a named encode set.
     */
    public static function percentEncode(int $byte, string $set): string
    {
        if (self::byteInPercentEncodeSet($byte, $set)) {
            return self::percentEncodeByte($byte);
        }
        return chr($byte);
    }

    private static function percentEncodeByte(int $byte): string
    {
        $hex = strtoupper(dechex($byte));
        if (strlen($hex) === 1) {
            $hex = '0' . $hex;
        }
        return '%' . $hex;
    }

    /**
     * Returns true when the byte must be percent-encoded for the named set.
     * Sets are nested per spec: C0 ⊂ fragment ⊂ query ⊂ special-query;
     * C0 ⊂ path; userinfo and component are even broader supersets.
     */
    public static function byteInPercentEncodeSet(int $b, string $set): bool
    {
        // C0 control percent-encode set: C0 controls + > 0x7e.
        $inC0 = ($b <= 0x1f || $b > 0x7e);

        if ($set === 'c0') {
            return $inC0;
        }

        // fragment set: C0 + 0x20, 0x22, 0x3c, 0x3e, 0x60.
        $inFragment = $inC0
            || $b === 0x20 || $b === 0x22 || $b === 0x3c || $b === 0x3e || $b === 0x60;

        if ($set === 'fragment') {
            return $inFragment;
        }

        // query set: C0 + 0x20, 0x22, 0x23, 0x3c, 0x3e.
        $inQuery = $inC0
            || $b === 0x20 || $b === 0x22 || $b === 0x23 || $b === 0x3c || $b === 0x3e;

        if ($set === 'query') {
            return $inQuery;
        }

        // special-query: query + 0x27.
        if ($set === 'special-query') {
            return $inQuery || $b === 0x27;
        }

        // path set: query + 0x3f, 0x60, 0x7b, 0x7d.
        $inPath = $inQuery
            || $b === 0x3f || $b === 0x60 || $b === 0x7b || $b === 0x7d;

        if ($set === 'path') {
            return $inPath;
        }

        // userinfo: path + 0x2f, 0x3a, 0x3b, 0x3d, 0x40, 0x5b..0x5e, 0x7c.
        if ($set === 'userinfo') {
            return $inPath
                || $b === 0x2f || $b === 0x3a || $b === 0x3b || $b === 0x3d || $b === 0x40
                || $b === 0x5b || $b === 0x5c || $b === 0x5d || $b === 0x5e || $b === 0x7c;
        }

        // component: userinfo + 0x24..0x26, 0x2b, 0x2c.
        if ($set === 'component') {
            $userinfo = $inPath
                || $b === 0x2f || $b === 0x3a || $b === 0x3b || $b === 0x3d || $b === 0x40
                || $b === 0x5b || $b === 0x5c || $b === 0x5d || $b === 0x5e || $b === 0x7c;
            return $userinfo
                || $b === 0x24 || $b === 0x25 || $b === 0x26 || $b === 0x2b || $b === 0x2c;
        }

        // application/x-www-form-urlencoded set: component + 0x21, 0x27,
        // 0x28, 0x29, 0x7e. Used by URLSearchParams.
        if ($set === 'form-urlencoded') {
            $component = (
                $inPath
                || $b === 0x2f || $b === 0x3a || $b === 0x3b || $b === 0x3d || $b === 0x40
                || $b === 0x5b || $b === 0x5c || $b === 0x5d || $b === 0x5e || $b === 0x7c
                || $b === 0x24 || $b === 0x25 || $b === 0x26 || $b === 0x2b || $b === 0x2c
            );
            return $component
                || $b === 0x21 || $b === 0x27 || $b === 0x28 || $b === 0x29 || $b === 0x7e;
        }

        // opaque-path: query set + 0x60, 0x7b, 0x7d.
        if ($set === 'opaque-path') {
            return $inQuery || $b === 0x60 || $b === 0x7b || $b === 0x7d;
        }

        // Default fallback: C0 only.
        return $inC0;
    }

    /**
     * Read one UTF-8 codepoint starting at $pointer in $input, append its
     * percent-encoded form to $buffer (passed by reference), and return the
     * new pointer position (still pointing at the last consumed byte so the
     * outer loop's $pointer++ advances cleanly).
     *
     * Bytes are encoded one by one through byteInPercentEncodeSet.
     */
    public static function appendEncodedCodepoint(string $input, int $pointer, string &$buffer, string $set): int
    {
        $len = strlen($input);
        $b = ord($input[$pointer]);

        if ($b < 0x80) {
            if ($b === 0x25 && $pointer + 2 < $len) {
                // Already percent-encoded: pass through literally unless the
                // remaining two chars aren't valid hex (then encode the '%').
                if (
                    self::isAsciiHexDigit(ord($input[$pointer + 1]))
                    && self::isAsciiHexDigit(ord($input[$pointer + 2]))
                ) {
                    $buffer .= '%' . strtoupper(substr($input, $pointer + 1, 2));
                    return $pointer + 2;
                }
            }
            if (self::byteInPercentEncodeSet($b, $set)) {
                $buffer .= self::percentEncodeByte($b);
            } else {
                $buffer .= chr($b);
            }
            return $pointer;
        }

        // Multi-byte UTF-8: figure out how long the sequence is.
        $count = 0;
        if (($b & 0xe0) === 0xc0) {
            $count = 2;
        } elseif (($b & 0xf0) === 0xe0) {
            $count = 3;
        } elseif (($b & 0xf8) === 0xf0) {
            $count = 4;
        } else {
            // Invalid lead byte. Encode it raw.
            $buffer .= self::percentEncodeByte($b);
            return $pointer;
        }
        $end = min($pointer + $count - 1, $len - 1);
        for ($i = $pointer; $i <= $end; $i++) {
            $bb = ord($input[$i]);
            if (self::byteInPercentEncodeSet($bb, $set)) {
                $buffer .= self::percentEncodeByte($bb);
            } else {
                $buffer .= chr($bb);
            }
        }
        return $end;
    }

    // ---- host parsing -----------------------------------------------------

    /**
     * §4.3 host parsing.
     *
     * Returns the parsed host (string or int IPv4 or list-of-int IPv6) or
     * false on failure.
     *
     * @return string|int|array<int,int>|false
     */
    public static function parseHost(string $input, bool $isOpaque = false)
    {
        if ($input === '') {
            return $isOpaque ? '' : false;
        }
        if ($input[0] === '[') {
            if ($input[strlen($input) - 1] !== ']') {
                return false;
            }
            return self::parseIpv6(substr($input, 1, -1));
        }
        if ($isOpaque) {
            return self::parseOpaqueHost($input);
        }

        // §4.3.3 domain-to-ASCII: percent-decode, then UTS#46 / Punycode.
        // We support only ASCII; non-ASCII results in failure. See file
        // header for IDN scope note.
        $decoded = self::percentDecode($input);
        if (!self::isAscii($decoded)) {
            // IDN out of scope for v1. Browsers would punycode here.
            return false;
        }
        $ascii = strtolower($decoded);

        // Forbidden host code points.
        $len = strlen($ascii);
        for ($i = 0; $i < $len; $i++) {
            $cp = ord($ascii[$i]);
            if (self::isForbiddenDomainCodePoint($cp)) {
                return false;
            }
        }

        // Try IPv4 if the host ends in a number per §4.3.4.
        if (self::endsInANumber($ascii)) {
            $v4 = self::parseIpv4($ascii);
            if ($v4 === false) {
                return false;
            }
            return $v4;
        }

        return $ascii;
    }

    /**
     * @return string|false
     */
    private static function parseOpaqueHost(string $input)
    {
        $len = strlen($input);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $cp = ord($input[$i]);
            if (self::isForbiddenHostCodePoint($cp)) {
                return false;
            }
            $i = self::appendEncodedCodepoint($input, $i, $out, 'c0');
        }
        return $out;
    }

    private static function isForbiddenHostCodePoint(int $cp): bool
    {
        // U+0000, tab, LF, CR, U+0020, #, /, :, <, >, ?, @, [, \, ], ^, |.
        return $cp === 0x00 || $cp === 0x09 || $cp === 0x0a || $cp === 0x0d
            || $cp === 0x20 || $cp === 0x23 || $cp === 0x2f || $cp === 0x3a
            || $cp === 0x3c || $cp === 0x3e || $cp === 0x3f || $cp === 0x40
            || $cp === 0x5b || $cp === 0x5c || $cp === 0x5d || $cp === 0x5e
            || $cp === 0x7c;
    }

    private static function isForbiddenDomainCodePoint(int $cp): bool
    {
        // Forbidden host set + C0 controls + 0x25 (%) + 0x7f.
        return self::isForbiddenHostCodePoint($cp)
            || $cp <= 0x1f
            || $cp === 0x25
            || $cp === 0x7f;
    }

    private static function isAscii(string $s): bool
    {
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if (ord($s[$i]) > 0x7f) {
                return false;
            }
        }
        return true;
    }

    public static function percentDecode(string $s): string
    {
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if (
                $s[$i] === '%'
                && $i + 2 < $len
                && self::isAsciiHexDigit(ord($s[$i + 1]))
                && self::isAsciiHexDigit(ord($s[$i + 2]))
            ) {
                $out .= chr((int) hexdec(substr($s, $i + 1, 2)));
                $i += 2;
            } else {
                $out .= $s[$i];
            }
        }
        return $out;
    }

    private static function endsInANumber(string $input): bool
    {
        $parts = explode('.', $input);
        if (end($parts) === '' && count($parts) > 1) {
            array_pop($parts);
        }
        if (empty($parts)) {
            return false;
        }
        $last = end($parts);
        if ($last === '') {
            return false;
        }
        // All ASCII digits → number.
        $allDigit = true;
        $len = strlen($last);
        for ($i = 0; $i < $len; $i++) {
            if (!self::isAsciiDigit(ord($last[$i]))) {
                $allDigit = false;
                break;
            }
        }
        if ($allDigit) {
            return true;
        }
        return self::parseIpv4Number($last) !== false;
    }

    /**
     * §4.3.4 IPv4 parser. Returns the 32-bit integer host or false.
     *
     * @return int|false
     */
    private static function parseIpv4(string $input)
    {
        $parts = explode('.', $input);
        if (end($parts) === '' && count($parts) > 1) {
            array_pop($parts);
        }
        if (count($parts) > 4) {
            return false;
        }
        $numbers = [];
        foreach ($parts as $part) {
            if ($part === '') {
                return false;
            }
            $n = self::parseIpv4Number($part);
            if ($n === false) {
                return false;
            }
            $numbers[] = $n;
        }
        $partCount = count($numbers);
        for ($i = 0; $i < $partCount - 1; $i++) {
            if ($numbers[$i] > 255) {
                return false;
            }
        }
        if ($numbers[$partCount - 1] >= 256 ** (5 - $partCount)) {
            return false;
        }
        $ipv4 = $numbers[$partCount - 1];
        $counter = 0;
        for ($i = 0; $i < $partCount - 1; $i++) {
            $ipv4 += $numbers[$i] * (256 ** (3 - $counter));
            $counter++;
        }
        return (int) $ipv4;
    }

    /**
     * @return int|false
     */
    private static function parseIpv4Number(string $input)
    {
        if ($input === '') {
            return false;
        }
        $r = 10;
        if (strlen($input) >= 2 && $input[0] === '0' && ($input[1] === 'x' || $input[1] === 'X')) {
            $input = substr($input, 2);
            $r = 16;
        } elseif (strlen($input) >= 2 && $input[0] === '0') {
            $input = substr($input, 1);
            $r = 8;
        }
        if ($input === '') {
            return 0;
        }
        $valid = match ($r) {
            16 => '/^[0-9a-fA-F]+$/',
            8 => '/^[0-7]+$/',
            default => '/^[0-9]+$/',
        };
        if (!preg_match($valid, $input)) {
            return false;
        }
        return (int) base_convert($input, $r, 10);
    }

    /**
     * §4.3.4 IPv6 parser. Returns 8 16-bit pieces or false on failure.
     *
     * @return array<int,int>|false
     */
    private static function parseIpv6(string $input)
    {
        $pieces = array_fill(0, 8, 0);
        $pieceIndex = 0;
        $compress = null;
        $pointer = 0;
        $len = strlen($input);

        $charAt = fn(int $p) => $p < $len ? $input[$p] : '';
        $ordAt = fn(int $p) => $p < $len ? ord($input[$p]) : -1;

        if ($len >= 1 && $input[0] === ':') {
            if ($len < 2 || $input[1] !== ':') {
                return false;
            }
            $pointer = 2;
            $pieceIndex = 1;
            $compress = 1;
        }

        while ($pointer < $len) {
            if ($pieceIndex === 8) {
                return false;
            }
            if ($charAt($pointer) === ':') {
                if ($compress !== null) {
                    return false;
                }
                $pointer++;
                $pieceIndex++;
                $compress = $pieceIndex;
                continue;
            }
            $value = 0;
            $length = 0;
            while ($length < 4 && self::isAsciiHexDigit($ordAt($pointer))) {
                $value = $value * 16 + (int) hexdec($charAt($pointer));
                $pointer++;
                $length++;
            }
            if ($charAt($pointer) === '.') {
                if ($length === 0) {
                    return false;
                }
                $pointer -= $length;
                if ($pieceIndex > 6) {
                    return false;
                }
                $numbersSeen = 0;
                while ($pointer < $len) {
                    $ipv4Piece = null;
                    if ($numbersSeen > 0) {
                        if ($pointer < $len && $input[$pointer] === '.' && $numbersSeen < 4) {
                            $pointer++;
                        } else {
                            return false;
                        }
                    }
                    if (!self::isAsciiDigit($ordAt($pointer))) {
                        return false;
                    }
                    while (self::isAsciiDigit($ordAt($pointer))) {
                        $number = $ordAt($pointer) - 48;
                        if ($ipv4Piece === null) {
                            $ipv4Piece = $number;
                        } elseif ($ipv4Piece === 0) {
                            return false;
                        } else {
                            $ipv4Piece = $ipv4Piece * 10 + $number;
                        }
                        if ($ipv4Piece > 255) {
                            return false;
                        }
                        $pointer++;
                    }
                    $pieces[$pieceIndex] = $pieces[$pieceIndex] * 0x100 + (int) $ipv4Piece;
                    $numbersSeen++;
                    if ($numbersSeen === 2 || $numbersSeen === 4) {
                        $pieceIndex++;
                    }
                }
                if ($numbersSeen !== 4) {
                    return false;
                }
                break;
            } elseif ($charAt($pointer) === ':') {
                $pointer++;
                if ($pointer >= $len) {
                    return false;
                }
            } elseif ($pointer < $len) {
                return false;
            }
            $pieces[$pieceIndex] = $value;
            $pieceIndex++;
        }

        if ($compress !== null) {
            $swaps = $pieceIndex - $compress;
            $pieceIndex = 7;
            while ($pieceIndex !== 0 && $swaps > 0) {
                $tmp = $pieces[$pieceIndex];
                $pieces[$pieceIndex] = $pieces[$compress + $swaps - 1];
                $pieces[$compress + $swaps - 1] = $tmp;
                $pieceIndex--;
                $swaps--;
            }
        } elseif ($pieceIndex !== 8) {
            return false;
        }
        return $pieces;
    }
}
