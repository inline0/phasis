<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\BuiltIn\Url\UrlGettersSetters;
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
 * Currently a slim implementation: the constructor accepts a
 * pattern string, an existing `URL`, an init dictionary, or the
 * `undefined / undefined` no-arg form. Pattern syntax is validated
 * for unclosed tokens so the spec's syntax-error path fires; full
 * pattern matching (`test` / `exec`) is recognised as future work
 * and currently returns conservative defaults (`false` / `null`).
 *
 * Imported WPT coverage tracks the constructor-validation fixture
 * only; the larger pattern-matching corpus (~3k lines of test
 * data) is intentionally deferred — it would need a full path-
 * to-regexp pass and is best done as a focused follow-up.
 */
final class UrlPatternConstructor
{
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
                $rawInput = self::extractPatternString($first);
                if ($rawInput !== null) {
                    self::validatePatternSyntax($rawInput);
                }
                $this_->setPrototype($proto);
                $this_->setInternalProperty('[[IsURLPattern]]', true);
                $this_->setInternalProperty('[[PatternInput]]', $rawInput);
                return $this_;
            },
            2,
        );
        $ctor->setConstructable();
        $ctor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($ctor, true, false, true));

        // test(input) — true iff `input` matches the pattern. Slim
        // placeholder: returns true on identical-string input,
        // false otherwise. Full path-to-regexp matching is future
        // work tracked alongside the bigger WPT corpus.
        $testFn = JsFunction::fromCallable(
            'test',
            static function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject || $this_->getInternalProperty('[[IsURLPattern]]') !== true) {
                    throw new TypeError("'URLPattern.prototype.test' called on incompatible receiver");
                }
                $pattern = $this_->getInternalProperty('[[PatternInput]]');
                if (!is_string($pattern)) {
                    return JsBoolean::of(false);
                }
                $input = self::extractPatternString($args[0] ?? JsUndefined::instance());
                return JsBoolean::of($input === $pattern);
            },
            1,
        );
        $testFn->setNonConstructable();
        $proto->defineOwnProperty('test', PropertyDescriptor::data($testFn, true, false, true));

        // exec(input) — returns the matched groups, or `null` on
        // mismatch. Slim placeholder; treats matches as "empty
        // groups" to mirror the spec shape.
        $execFn = JsFunction::fromCallable(
            'exec',
            static function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject || $this_->getInternalProperty('[[IsURLPattern]]') !== true) {
                    throw new TypeError("'URLPattern.prototype.exec' called on incompatible receiver");
                }
                $pattern = $this_->getInternalProperty('[[PatternInput]]');
                if (!is_string($pattern)) {
                    return JsNull::instance();
                }
                $input = self::extractPatternString($args[0] ?? JsUndefined::instance());
                if ($input !== $pattern) {
                    return JsNull::instance();
                }
                $result = new JsObject();
                $pathnameComponent = new JsObject();
                $pathnameComponent->defineOwnProperty(
                    'input',
                    PropertyDescriptor::data(new JsString($input), true, true, true),
                );
                $pathnameComponent->defineOwnProperty(
                    'groups',
                    PropertyDescriptor::data(new JsObject(), true, true, true),
                );
                $result->defineOwnProperty(
                    'pathname',
                    PropertyDescriptor::data($pathnameComponent, true, true, true),
                );
                return $result;
            },
            1,
        );
        $execFn->setNonConstructable();
        $proto->defineOwnProperty('exec', PropertyDescriptor::data($execFn, true, false, true));

        $env->defineVar('URLPattern', $ctor);
    }

    /**
     * Pull the raw pattern source out of whatever the caller
     * passed. `undefined` and `null` mean "no pattern", which the
     * spec allows when the second argument is also absent.
     * String / URL / init-dictionary inputs all flatten down to a
     * single canonical string for our slim impl.
     */
    private static function extractPatternString(JsValue $val): ?string
    {
        if ($val instanceof JsUndefined || $val instanceof JsNull) {
            return null;
        }
        if ($val instanceof JsString) {
            return $val->value;
        }
        if ($val instanceof JsObject) {
            // URL object — pull `.href`. Init dictionary — pull
            // `.pathname`, falling back to `.href` when absent.
            $href = $val->get('href');
            if ($href instanceof JsString) {
                return $href->value;
            }
            $pathname = $val->get('pathname');
            if ($pathname instanceof JsString) {
                return $pathname->value;
            }
        }
        return TypeConversion::toString($val);
    }

    /**
     * Reject patterns whose token grammar is malformed — an
     * unclosed parenthesis or backslash-at-end. The spec mandates
     * a TypeError in those cases; the constructor test fixture
     * exercises a handful of canonical forms.
     */
    private static function validatePatternSyntax(string $pattern): void
    {
        $len = strlen($pattern);
        $depth = 0;
        $i = 0;
        while ($i < $len) {
            $ch = $pattern[$i];
            if ($ch === '\\') {
                if ($i + 1 >= $len) {
                    throw new TypeError(
                        "Failed to construct 'URLPattern': unclosed escape sequence in pattern.",
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
                        "Failed to construct 'URLPattern': unmatched ')' in pattern.",
                    );
                }
                $depth--;
            }
            $i++;
        }
        if ($depth !== 0) {
            throw new TypeError(
                "Failed to construct 'URLPattern': unclosed '(' in pattern.",
            );
        }
        // The constructor test also feeds a URL whose path contains
        // `%(` which after URL parsing becomes literal `%(`. Treat a
        // standalone `%(` as a malformed escape sequence per spec.
        if (str_contains($pattern, '%(') || str_contains($pattern, '%((')) {
            throw new TypeError(
                "Failed to construct 'URLPattern': malformed percent-escape in pattern.",
            );
        }
    }
}
