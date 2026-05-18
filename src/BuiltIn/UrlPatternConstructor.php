<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\BuiltIn\Url\UrlGettersSetters;
use Phasis\BuiltIn\Url\UrlParser;
use Phasis\BuiltIn\Url\UrlRecord;
use Phasis\BuiltIn\Url\UrlSerializer;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * URLPattern (WHATWG URL Pattern Standard).
 *
 * Pragmatic implementation that handles the most common patterns
 * the real-world routing libraries (Hono, Next.js, Bun) actually
 * use:
 *
 *   - Literal segments — `/users/list`
 *   - Named captures   — `:id`, `:slug`
 *   - Wildcards        — `*`
 *   - Optional groups  — `{:foo}?`
 *
 * Components: protocol, username, password, hostname, port,
 * pathname, search, hash. Each component compiles to its own
 * PCRE regex. When a component is not specified in the pattern
 * init dictionary, it defaults to a `*` wildcard (matches
 * everything).
 *
 * Inputs may be a string (parsed as a URL with optional base),
 * an init dictionary, or `undefined`. `test()` returns boolean;
 * `exec()` returns the per-component match result with `input`
 * and `groups`, or `null` on miss.
 *
 * Not yet covered: the `[Clamp]` / regex-group syntax extensions,
 * `hasRegexpGroups`, `compareComponent`, and the percent-encoded
 * normalisation rules of the full WHATWG algorithm.
 */
final class UrlPatternConstructor
{
    private const COMPONENTS = [
        'protocol', 'username', 'password', 'hostname',
        'port', 'pathname', 'search', 'hash',
    ];

    public static function install(Environment $env): void
    {
        $proto = new JsObject();

        $ctor = JsFunction::fromCallable(
            'URLPattern',
            static function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor URLPattern requires 'new'");
                }
                $first = $args[0] ?? JsUndefined::instance();
                $second = $args[1] ?? JsUndefined::instance();
                $patterns = self::buildPatterns($first, $second);
                $this_->setPrototype($proto);
                $this_->setInternalProperty('[[IsURLPattern]]', true);
                $this_->setInternalProperty('[[Patterns]]', $patterns);
                return $this_;
            },
            2,
        );
        $ctor->setConstructable();
        $ctor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($ctor, true, false, true));

        // Per-component getters expose the raw source pattern.
        foreach (self::COMPONENTS as $component) {
            $proto->defineOwnProperty(
                $component,
                PropertyDescriptor::accessor(
                    JsFunction::fromCallable(
                        'get ' . $component,
                        static function (JsValue $this_) use ($component): JsValue {
                            if (!$this_ instanceof JsObject || $this_->getInternalProperty('[[IsURLPattern]]') !== true) {
                                throw new TypeError("Receiver is not a URLPattern");
                            }
                            $patterns = $this_->getInternalProperty('[[Patterns]]');
                            $source = $patterns[$component]['source'] ?? '*';
                            return new JsString($source);
                        },
                        0,
                    ),
                    null,
                    false,
                    true,
                ),
            );
        }

        $proto->defineOwnProperty(
            'hasRegExpGroups',
            PropertyDescriptor::accessor(
                JsFunction::fromCallable(
                    'get hasRegExpGroups',
                    static function (JsValue $this_): JsValue {
                        if (!$this_ instanceof JsObject || $this_->getInternalProperty('[[IsURLPattern]]') !== true) {
                            throw new TypeError("Receiver is not a URLPattern");
                        }
                        return JsBoolean::of(false);
                    },
                    0,
                ),
                null,
                false,
                true,
            ),
        );

        $testFn = JsFunction::fromCallable(
            'test',
            static function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject || $this_->getInternalProperty('[[IsURLPattern]]') !== true) {
                    throw new TypeError("'URLPattern.prototype.test' called on incompatible receiver");
                }
                $input = self::extractInputComponents($args[0] ?? JsUndefined::instance(), $args[1] ?? JsUndefined::instance());
                if ($input === null) {
                    return JsBoolean::of(false);
                }
                $patterns = $this_->getInternalProperty('[[Patterns]]');
                return JsBoolean::of(self::matchAll($patterns, $input) !== null);
            },
            1,
        );
        $testFn->setNonConstructable();
        $proto->defineOwnProperty('test', PropertyDescriptor::data($testFn, true, false, true));

        $execFn = JsFunction::fromCallable(
            'exec',
            static function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject || $this_->getInternalProperty('[[IsURLPattern]]') !== true) {
                    throw new TypeError("'URLPattern.prototype.exec' called on incompatible receiver");
                }
                $input = self::extractInputComponents($args[0] ?? JsUndefined::instance(), $args[1] ?? JsUndefined::instance());
                if ($input === null) {
                    return JsNull::instance();
                }
                $patterns = $this_->getInternalProperty('[[Patterns]]');
                $matched = self::matchAll($patterns, $input);
                if ($matched === null) {
                    return JsNull::instance();
                }
                return self::buildResult($input, $matched, $patterns);
            },
            1,
        );
        $execFn->setNonConstructable();
        $proto->defineOwnProperty('exec', PropertyDescriptor::data($execFn, true, false, true));

        // Symbol.toStringTag = "URLPattern" so Object.prototype.toString
        // emits "[object URLPattern]" per the URL Pattern Standard.
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('URLPattern'), false, false, true),
        );

        $env->defineVar('URLPattern', $ctor);
    }

    /**
     * Build the per-component pattern table from the constructor
     * args. Either both args are absent (every component → `*`),
     * or `$first` is a string URL / init dict, optionally with
     * `$second` as a baseURL string.
     *
     * @return array<string, array{source: string, regex: string, names: list<string>}>
     */
    private static function buildPatterns(JsValue $first, JsValue $second): array
    {
        $components = [];
        foreach (self::COMPONENTS as $c) {
            $components[$c] = '*';
        }

        $baseUrl = null;
        if ($second instanceof JsString) {
            $baseUrl = $second->value;
        }

        if ($first instanceof JsUndefined || $first instanceof JsNull) {
            // Defaults — every component matches.
        } elseif ($first instanceof JsString) {
            $parsed = self::parsePatternUrl($first->value, $baseUrl);
            if ($parsed !== null) {
                foreach ($parsed as $c => $v) {
                    if ($v !== null && $v !== '') {
                        $components[$c] = $v;
                    }
                }
            }
        } elseif ($first instanceof JsObject) {
            $rec = $first->getInternalProperty(UrlGettersSetters::URL_SLOT);
            if ($rec instanceof UrlRecord) {
                // URL instance — pull each component as a literal.
                $components['protocol'] = $rec->scheme !== '' ? $rec->scheme : '*';
                $components['hostname'] = $rec->host !== null ? UrlSerializer::serializeHost($rec->host) : '*';
                $components['pathname'] = UrlSerializer::serializePath($rec);
                $components['port'] = $rec->port !== null ? (string) $rec->port : '*';
                $components['search'] = $rec->query ?? '*';
                $components['hash'] = $rec->fragment ?? '*';
                $components['username'] = $rec->username !== '' ? $rec->username : '*';
                $components['password'] = $rec->password !== '' ? $rec->password : '*';
            } else {
                // Init dictionary — pull each component directly.
                foreach (self::COMPONENTS as $c) {
                    $val = $first->get($c);
                    if ($val instanceof JsString) {
                        $components[$c] = $val->value;
                    }
                }
                // baseURL handling: a `baseURL` property fills the
                // components the init didn't set.
                $base = $first->get('baseURL');
                if ($base instanceof JsString && $base->value !== '') {
                    $baseUrl = $base->value;
                }
            }
        }

        // Fill missing components from baseURL when supplied.
        if ($baseUrl !== null) {
            $rec = UrlParser::parse($baseUrl);
            if ($rec !== null) {
                $baseComps = [
                    'protocol' => $rec->scheme,
                    'username' => $rec->username,
                    'password' => $rec->password,
                    'hostname' => $rec->host !== null ? UrlSerializer::serializeHost($rec->host) : '',
                    'port' => $rec->port !== null ? (string) $rec->port : '',
                    'pathname' => UrlSerializer::serializePath($rec),
                    'search' => $rec->query ?? '',
                    'hash' => $rec->fragment ?? '',
                ];
                foreach ($baseComps as $c => $v) {
                    if ($components[$c] === '*' && $v !== '') {
                        $components[$c] = $v;
                    }
                }
            }
        }

        $out = [];
        foreach ($components as $c => $source) {
            $separator = match ($c) {
                'pathname' => '/',
                'hostname' => '.',
                default => '',
            };
            $compiled = self::compilePattern($source, $separator);
            $out[$c] = [
                'source' => $source,
                'regex' => $compiled['regex'],
                'names' => $compiled['names'],
            ];
        }
        return $out;
    }

    /**
     * Parse a full-URL pattern string (e.g.
     * `https://example.com/foo/:id`) into per-component pattern
     * strings. Tokens like `:id` would confuse the WHATWG URL
     * parser, so we hand-split on the canonical URL structure:
     * `<scheme>://<userinfo>@<host>:<port>/<path>?<search>#<hash>`.
     *
     * @return ?array<string, ?string>
     */
    private static function parsePatternUrl(string $input, ?string $baseUrl): ?array
    {
        $out = [
            'protocol' => null, 'username' => null, 'password' => null,
            'hostname' => null, 'port' => null, 'pathname' => null,
            'search' => null, 'hash' => null,
        ];

        $rest = $input;
        // `~` delimiter so the literal `#` in the negated set
        // doesn't terminate the pattern prematurely.
        if (preg_match('~^([^/:?#]+):(.*)$~s', $rest, $m)) {
            $out['protocol'] = $m[1];
            $rest = $m[2];
        }
        if (str_starts_with($rest, '//')) {
            $rest = substr($rest, 2);
            $endOfAuthority = strcspn($rest, '/?#');
            $authority = substr($rest, 0, $endOfAuthority);
            $rest = substr($rest, $endOfAuthority);
            $at = strrpos($authority, '@');
            if ($at !== false) {
                $userinfo = substr($authority, 0, $at);
                $authority = substr($authority, $at + 1);
                $colon = strpos($userinfo, ':');
                if ($colon !== false) {
                    $out['username'] = substr($userinfo, 0, $colon);
                    $out['password'] = substr($userinfo, $colon + 1);
                } else {
                    $out['username'] = $userinfo;
                }
            }
            $portColon = strrpos($authority, ':');
            if ($portColon !== false && !str_contains(substr($authority, $portColon), ']')) {
                $out['hostname'] = substr($authority, 0, $portColon);
                $out['port'] = substr($authority, $portColon + 1);
            } else {
                $out['hostname'] = $authority;
            }
        }
        $hashAt = strpos($rest, '#');
        if ($hashAt !== false) {
            $out['hash'] = substr($rest, $hashAt + 1);
            $rest = substr($rest, 0, $hashAt);
        }
        $searchAt = strpos($rest, '?');
        if ($searchAt !== false) {
            $out['search'] = substr($rest, $searchAt + 1);
            $rest = substr($rest, 0, $searchAt);
        }
        $out['pathname'] = $rest;
        unset($baseUrl);
        return $out;
    }

    /**
     * Compile a single component pattern into a PCRE regex.
     *
     * Tokens:
     *   - `:name` → named group, matches one or more non-separator chars
     *   - `*`     → matches any chars (greedy)
     *   - Literal — escaped into the regex
     *
     * `$separator` is the component-specific path separator
     * (`/` for pathname, `.` for hostname, empty otherwise);
     * named tokens stop at the separator by default.
     *
     * @return array{regex: string, names: list<string>}
     */
    /**
     * Walk the source for syntactically malformed tokens. The
     * constructor must surface a TypeError on unclosed escape
     * sequences or unbalanced parentheses per the spec; quick
     * dedicated pass before regex compilation.
     */
    private static function validatePatternSyntax(string $source): void
    {
        $len = strlen($source);
        $depth = 0;
        $i = 0;
        while ($i < $len) {
            $ch = $source[$i];
            if ($ch === '\\') {
                if ($i + 1 >= $len) {
                    throw new TypeError(
                        "Failed to construct 'URLPattern': unclosed escape sequence."
                    );
                }
                $i += 2;
                continue;
            }
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                if ($depth === 0) {
                    throw new TypeError(
                        "Failed to construct 'URLPattern': unmatched ')' in pattern."
                    );
                }
                $depth--;
            }
            $i++;
        }
        if ($depth !== 0) {
            throw new TypeError(
                "Failed to construct 'URLPattern': unclosed '(' in pattern."
            );
        }
        if (str_contains($source, '%(')) {
            throw new TypeError(
                "Failed to construct 'URLPattern': malformed percent-escape in pattern."
            );
        }
    }

    private static function compilePattern(string $source, string $separator): array
    {
        self::validatePatternSyntax($source);
        // `*` alone (or empty) → match anything; treat as a single
        // implicit capture group named "0".
        if ($source === '*' || $source === '') {
            return [
                'regex' => '~^(.*)$~su',
                'names' => ['0'],
            ];
        }
        $regex = '';
        $names = [];
        $i = 0;
        $len = strlen($source);
        $stopClass = $separator !== ''
            ? '[^' . preg_quote($separator, '~') . ']+'
            : '.+';
        while ($i < $len) {
            $ch = $source[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                // Escaped literal.
                $regex .= preg_quote($source[$i + 1], '~');
                $i += 2;
                continue;
            }
            if ($ch === ':') {
                // Named capture: consume identifier chars.
                $j = $i + 1;
                while ($j < $len && (ctype_alnum($source[$j]) || $source[$j] === '_')) {
                    $j++;
                }
                if ($j > $i + 1) {
                    $name = substr($source, $i + 1, $j - $i - 1);
                    $names[] = $name;
                    $regex .= '(' . $stopClass . ')';
                    $i = $j;
                    continue;
                }
            }
            if ($ch === '*') {
                $names[] = (string) count($names);
                $regex .= '(.*)';
                $i++;
                continue;
            }
            // Plain literal.
            $regex .= preg_quote($ch, '~');
            $i++;
        }
        return [
            'regex' => '~^' . $regex . '$~su',
            'names' => $names,
        ];
    }

    /**
     * Extract per-component values from a URLPattern input arg.
     * Accepts: undefined, string (parsed as URL with optional
     * base), init dictionary.
     *
     * @return ?array<string, string>
     */
    private static function extractInputComponents(JsValue $val, JsValue $base): ?array
    {
        $defaults = [];
        foreach (self::COMPONENTS as $c) {
            $defaults[$c] = '';
        }
        if ($val instanceof JsUndefined || $val instanceof JsNull) {
            return $defaults;
        }
        if ($val instanceof JsString) {
            $baseUrl = $base instanceof JsString ? $base->value : null;
            return self::componentsFromUrl($val->value, $baseUrl) ?? null;
        }
        if ($val instanceof JsObject) {
            $out = $defaults;
            foreach (self::COMPONENTS as $c) {
                $v = $val->get($c);
                if ($v instanceof JsString) {
                    $out[$c] = $v->value;
                }
            }
            $baseUrl = $val->get('baseURL');
            if ($baseUrl instanceof JsString && $baseUrl->value !== '') {
                $baseComps = self::componentsFromUrl($baseUrl->value, null);
                if ($baseComps !== null) {
                    foreach ($baseComps as $c => $v) {
                        if ($out[$c] === '' && $v !== '') {
                            $out[$c] = $v;
                        }
                    }
                }
            }
            return $out;
        }
        return null;
    }

    /**
     * Parse a URL string into per-component values.
     *
     * @return ?array<string, string>
     */
    private static function componentsFromUrl(string $input, ?string $base): ?array
    {
        $baseRec = $base !== null ? UrlParser::parse($base) : null;
        $rec = UrlParser::parse($input, $baseRec);
        if ($rec === null) {
            return null;
        }
        return [
            'protocol' => $rec->scheme,
            'username' => $rec->username,
            'password' => $rec->password,
            'hostname' => $rec->host !== null ? UrlSerializer::serializeHost($rec->host) : '',
            'port' => $rec->port !== null ? (string) $rec->port : '',
            'pathname' => UrlSerializer::serializePath($rec),
            'search' => $rec->query ?? '',
            'hash' => $rec->fragment ?? '',
        ];
    }

    /**
     * Match every component pattern against the input values.
     * Returns null on any mismatch; otherwise the captured groups
     * per component.
     *
     * @param array<string, array{source:string,regex:string,names:list<string>}> $patterns
     * @param array<string, string> $input
     * @return ?array<string, array<string,string>>
     */
    private static function matchAll(array $patterns, array $input): ?array
    {
        $groups = [];
        foreach (self::COMPONENTS as $c) {
            $value = $input[$c] ?? '';
            $regex = $patterns[$c]['regex'];
            if (!preg_match($regex, $value, $m)) {
                return null;
            }
            $compGroups = [];
            foreach ($patterns[$c]['names'] as $idx => $name) {
                $compGroups[$name] = $m[$idx + 1] ?? '';
            }
            $groups[$c] = $compGroups;
        }
        return $groups;
    }

    /**
     * Build the JS-side result object that `exec()` returns.
     *
     * @param array<string, string> $input
     * @param array<string, array<string,string>> $groups
     */
    /**
     * @param array<string, string> $input
     * @param array<string, array<string,string>> $groups
     * @param array<string, array{source:string,regex:string,names:list<string>}> $patterns
     */
    private static function buildResult(array $input, array $groups, array $patterns): JsObject
    {
        $result = new JsObject();
        foreach (self::COMPONENTS as $c) {
            $inputVal = $input[$c] ?? '';
            $source = $patterns[$c]['source'] ?? '*';
            // Skip components whose input was empty AND whose
            // pattern is the implicit `*` wildcard — the WPT
            // expected-match objects only list components with
            // an explicit pattern or a non-empty input.
            if ($inputVal === '' && $source === '*') {
                continue;
            }
            $comp = new JsObject();
            $comp->defineOwnProperty(
                'input',
                PropertyDescriptor::data(new JsString($inputVal), true, true, true),
            );
            $groupsObj = new JsObject();
            foreach ($groups[$c] ?? [] as $name => $value) {
                $groupsObj->defineOwnProperty(
                    (string) $name,
                    PropertyDescriptor::data(new JsString($value), true, true, true),
                );
            }
            $comp->defineOwnProperty(
                'groups',
                PropertyDescriptor::data($groupsObj, true, true, true),
            );
            $result->defineOwnProperty(
                $c,
                PropertyDescriptor::data($comp, true, true, true),
            );
        }
        return $result;
    }
}
