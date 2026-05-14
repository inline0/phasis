<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\RegExp_;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Exceptions\SyntaxError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Runtime\Interpreter;
use Phasis\Spec\AbstractOperations;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\BuiltIn\SymbolConstructor;

/**
 * RegExpPrototype trait part: RegExpHelpers. Composed into
 * RegExpPrototype via `use RegExp_\RegExpHelpers;`.
 */
trait RegExpHelpers
{
    /**
     * Clear the cached %RegExpStringIteratorPrototype% so fresh realms get a
     * prototype whose [[Prototype]] points at the realm's own %IteratorPrototype%.
     */
    public static function resetStringIteratorProto(): void
    {
        self::$regExpStringIteratorProto = null;
        self::$intrinsicExec = null;
    }

    /** %RegExpStringIteratorPrototype%: inherits from %IteratorPrototype%. */
    private static function getRegExpStringIteratorProto(): JsObject
    {
        if (self::$regExpStringIteratorProto !== null) {
            return self::$regExpStringIteratorProto;
        }
        $proto = new JsObject();

        // Set [[Prototype]] to %IteratorPrototype%.
        $iterProto = JsFunction::getInterpreterInstance()
            ?->getGlobalEnv()->get('__IteratorPrototype__');
        if ($iterProto instanceof JsObject) {
            $proto->setPrototype($iterProto);
        }

        // next method per spec 22.2.9.1.1.
        $nextFn = JsFunction::fromCallable(
            'next',
            function (JsValue $this_): JsValue {
                if (
                    !$this_ instanceof JsObject
                    || !$this_->hasOwnProperty('[[IteratingRegExp]]')
                ) {
                    throw new \Phasis\Exceptions\TypeError(
                        'next called on non-RegExp string iterator'
                    );
                }
                $doneVal = $this_->get('[[Done]]');
                if ($doneVal instanceof JsBoolean && $doneVal->value) {
                    return self::iterResult(JsUndefined::instance(), true);
                }
                $R = $this_->get('[[IteratingRegExp]]');
                if (!$R instanceof JsObject) {
                    return self::iterResult(JsUndefined::instance(), true);
                }
                $S = TypeConversion::toString($this_->get('[[IteratedString]]'));
                $global = $this_->get('[[Global]]');
                $isGlobal = $global instanceof JsBoolean && $global->value;
                $fullUnicode = $this_->get('[[Unicode]]');
                $isUnicode = $fullUnicode instanceof JsBoolean
                    && $fullUnicode->value;
                $match = self::regExpExec($R, $S);
                if ($match instanceof JsNull) {
                    $this_->set('[[Done]]', new JsBoolean(true));
                    return self::iterResult(JsUndefined::instance(), true);
                }
                if ($isGlobal) {
                    $matchStr = TypeConversion::toString($match->get('0'));
                    if ($matchStr === '') {
                        $li = (int) TypeConversion::toNumber($R->get('lastIndex'));
                        $next = $isUnicode
                            ? self::advanceStringIndex($S, $li) : $li + 1;
                        $R->set('lastIndex', JsNumber::of((float) $next));
                    }
                } else {
                    $this_->set('[[Done]]', new JsBoolean(true));
                }
                return self::iterResult($match, false);
            },
            0,
        );
        $proto->defineOwnProperty(
            'next',
            PropertyDescriptor::data($nextFn, true, false, true),
        );

        // Symbol.toStringTag
        $tagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol(
            $tagSym,
            PropertyDescriptor::data(
                new JsString('RegExp String Iterator'),
                false,
                false,
                true,
            ),
        );

        self::$regExpStringIteratorProto = $proto;
        return $proto;
    }

    /**
     * §22.2.6.13 EscapeRegExpPattern: escape `/` and LineTerminator code
     * points in a regexp source so `/` + src + `/` forms a valid literal.
     * Escaping inside character classes differs per spec (char classes may
     * contain `/` unescaped), but V8 and SpiderMonkey escape them anyway
     * and test262 checks the round-trip not the specific form.
     */
    private static function escapeRegExpPattern(string $source): string
    {
        $out = '';
        $len = strlen($source);
        $inClass = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                // A backslash followed by a line terminator in the pattern
                // source must stringify as `\` + the LineTerminator's
                // escape letter (e.g. \<LF> -> \n) per EscapeRegExpPattern;
                // the existing `\` plays the role of the escape backslash.
                $next = $source[$i + 1];
                if ($next === "\n") {
                    $out .= '\\n';
                    $i++;
                    continue;
                }
                if ($next === "\r") {
                    $out .= '\\r';
                    $i++;
                    continue;
                }
                if (
                    $next === "\xE2"
                    && $i + 3 < $len
                    && $source[$i + 2] === "\x80"
                    && ($source[$i + 3] === "\xA8" || $source[$i + 3] === "\xA9")
                ) {
                    $out .= $source[$i + 3] === "\xA8" ? '\\u2028' : '\\u2029';
                    $i += 3;
                    continue;
                }
                $out .= $ch . $next;
                $i++;
                continue;
            }
            if ($ch === '[') {
                $inClass = true;
                $out .= $ch;
                continue;
            }
            if ($ch === ']') {
                $inClass = false;
                $out .= $ch;
                continue;
            }
            if ($ch === '/' && !$inClass) {
                $out .= '\\/';
                continue;
            }
            // Line terminators LF / CR in pattern must be escaped.
            if ($ch === "\n") {
                $out .= '\\n';
                continue;
            }
            if ($ch === "\r") {
                $out .= '\\r';
                continue;
            }
            // U+2028 / U+2029 as UTF-8 sequences.
            if (
                $ch === "\xE2"
                && $i + 2 < $len
                && $source[$i + 1] === "\x80"
                && ($source[$i + 2] === "\xA8" || $source[$i + 2] === "\xA9")
            ) {
                $out .= $source[$i + 2] === "\xA8" ? '\\u2028' : '\\u2029';
                $i += 2;
                continue;
            }
            $out .= $ch;
        }
        return $out;
    }

    private static function iterResult(JsValue $value, bool $done): JsObject
    {
        $obj = new JsObject();
        $obj->set('value', $value);
        $obj->set('done', new JsBoolean($done));
        return $obj;
    }

    /**
     * Perform a sticky match at exact byte offset. Returns the match array
     * (PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL format) or null.
     *
     * @return array<int|string, array{0: ?string, 1: int}>|null
     */
    private static function stickyMatchAt(string $pcrePattern, string $str, int $byteOffset): ?array
    {
        if (@preg_match($pcrePattern, $str, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $byteOffset) !== 1) {
            return null;
        }
        // Sticky: match must start exactly at byteOffset.
        if ($matches[0][1] !== $byteOffset) {
            return null;
        }
        return $matches;
    }

    private static function advanceStringIndex(string $S, int $index): int
    {
        $u16 = JsString::utf8ToUtf16LE($S);
        $u16Len = (int) (strlen($u16) / 2);
        if ($index + 1 >= $u16Len) {
            return $index + 1;
        }
        $offset = $index * 2;
        $high = ord($u16[$offset]) | (ord($u16[$offset + 1]) << 8);
        if ($high < 0xD800 || $high > 0xDBFF) {
            return $index + 1;
        }
        $low = ord($u16[$offset + 2]) | (ord($u16[$offset + 3]) << 8);
        if ($low < 0xDC00 || $low > 0xDFFF) {
            return $index + 1;
        }
        return $index + 2;
    }
}
