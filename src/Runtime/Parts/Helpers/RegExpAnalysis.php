<?php

declare(strict_types=1);

namespace Phasis\Runtime\Parts\Helpers;

use Phasis\Ast\Declaration\ClassDeclaration;
use Phasis\Ast\Declaration\ExportDeclaration;
use Phasis\Ast\Declaration\FunctionDeclaration;
use Phasis\Ast\Declaration\ImportDeclaration;
use Phasis\Ast\Declaration\VariableDeclaration;
use Phasis\Ast\Declaration\VariableDeclarator;
use Phasis\Ast\Expression\ArrayExpression;
use Phasis\Ast\Expression\ArrowFunction;
use Phasis\Ast\Expression\AssignmentExpression;
use Phasis\Ast\Expression\AwaitExpression;
use Phasis\Ast\Expression\BinaryExpression;
use Phasis\Ast\Expression\CallExpression;
use Phasis\Ast\Expression\ClassExpression;
use Phasis\Ast\Expression\ClassMethod;
use Phasis\Ast\Expression\ClassProperty;
use Phasis\Ast\Expression\PrivateIdentifier;
use Phasis\Ast\Expression\StaticBlock;
use Phasis\Ast\Expression\ConditionalExpression;
use Phasis\Ast\Expression\FunctionExpression;
use Phasis\Ast\Expression\ImportExpression;
use Phasis\Ast\Expression\MetaProperty;
use Phasis\Ast\Expression\Identifier;
use Phasis\Ast\Expression\Literal;
use Phasis\Ast\Expression\LogicalExpression;
use Phasis\Ast\Expression\MemberExpression;
use Phasis\Ast\Expression\NewExpression;
use Phasis\Ast\Expression\ObjectExpression;
use Phasis\Ast\Expression\Property;
use Phasis\Ast\Expression\SequenceExpression;
use Phasis\Ast\Expression\SpreadElement;
use Phasis\Ast\Expression\TaggedTemplate;
use Phasis\Ast\Expression\TemplateLiteral;
use Phasis\Ast\Expression\ThisExpression;
use Phasis\Ast\Expression\UnaryExpression;
use Phasis\Ast\Expression\UpdateExpression;
use Phasis\Ast\Expression\YieldExpression;
use Phasis\Ast\Node;
use Phasis\Ast\Pattern\ArrayPattern;
use Phasis\Ast\Pattern\AssignmentPattern;
use Phasis\Ast\Pattern\AssignmentProperty;
use Phasis\Ast\Pattern\ObjectPattern;
use Phasis\Ast\Pattern\RestElement;
use Phasis\Ast\Program;
use Phasis\Ast\Statement\BlockStatement;
use Phasis\Ast\Statement\BreakStatement;
use Phasis\Ast\Statement\ContinueStatement;
use Phasis\Ast\Statement\DebuggerStatement;
use Phasis\Ast\Statement\DoWhileStatement;
use Phasis\Ast\Statement\EmptyStatement;
use Phasis\Ast\Statement\ExpressionStatement;
use Phasis\Ast\Statement\ForInStatement;
use Phasis\Ast\Statement\ForOfStatement;
use Phasis\Ast\Statement\ForStatement;
use Phasis\Ast\Statement\IfStatement;
use Phasis\Ast\Statement\LabeledStatement;
use Phasis\Ast\Statement\ReturnStatement;
use Phasis\Ast\Statement\SwitchCase;
use Phasis\Ast\Statement\SwitchStatement;
use Phasis\Ast\Statement\ThrowStatement;
use Phasis\Ast\Statement\TryStatement;
use Phasis\Ast\Statement\WhileStatement;
use Phasis\Ast\Statement\WithStatement;
use Phasis\Exceptions\InternalError;
use Phasis\Exceptions\ReferenceError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Spec\AbstractOperations;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBigInt;
use Phasis\Value\JsBoolean;
use Phasis\Value\GeneratorReturnSignal;
use Phasis\Value\GeneratorThrowSignal;
use Phasis\Value\JsAsyncGenerator;
use Phasis\Value\JsFunction;
use Phasis\Value\JsGenerator;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsArgumentsObject;
use Phasis\Value\JsObject;
use Phasis\Value\JsOptionalUndefined;
use Phasis\Value\JsProxy;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\Runtime\Environment;
use Phasis\Runtime\CallStack;
use Phasis\Runtime\Completion;
use Phasis\Runtime\CompletionType;
use Phasis\Runtime\Reference;

/**
 * Interpreter helper part: RegExpAnalysis. Composed back into the
 * Interpreter via the InterpreterHelpers trait. `self::`/`$this->`
 * resolve into the composing class.
 */
trait RegExpAnalysis
{
    /**
     * Collapse leading zeros in `\u{HHHH…}` braced unicode escapes for /u
     * mode patterns. `\u{0…01234}` and `\u{1234}` denote the same code
     * point per spec §22.2.1.6, so canonicalising once here lets every
     * downstream char-by-char scan (validators, transforms, custom-matcher
     * parser, etc.) operate on a small string. Patterns whose contents are
     * not pure hex (e.g. `\u{0.0}`) or that exceed the spec-permitted six
     * hex digits after stripping zeros are preserved verbatim so the
     * downstream validators still report SyntaxError.
     */
    public static function canonicalizeUnicodeBracedEscapes(string $pattern): string
    {
        return (string) preg_replace_callback(
            '/\\\\u\{0+([0-9A-Fa-f]+)\}/',
            static function (array $m): string {
                $hex = $m[1];
                // Cap at 7 hex digits so values ≥ 0x10000000 stay long
                // enough for downstream "value too large" checks. Six
                // digits suffice for any valid code point (≤ 0x10FFFF).
                if (strlen($hex) > 7) {
                    return $m[0];
                }
                return '\\u{' . $hex . '}';
            },
            $pattern,
        );
    }

    /**
     * Analyze a regex pattern to find quantified (repeated) capturing groups
     * and determine which inner captures need ES-compliant reset behavior.
     *
     * Returns an array with:
     *   'repeatedGroups' => array of groupIndex => [
     *       'innerCaptures' => list of capture indices inside this repeated group,
     *       'bodyPattern' => the pattern text of the group body,
     *       'nullable' => whether the body can match empty,
     *       'lazy' => whether the quantifier is lazy (suffixed by `?`),
     *   ]
     *
     * @return array{
     *     repeatedGroups: array<int, array{
     *         innerCaptures: list<int>,
     *         bodyPattern: string,
     *         nullable: bool,
     *         quantifier: ?string,
     *         lazy: bool,
     *     }>,
     *     nullableNonCapturingGroups: list<array{innerCaptures: list<int>}>,
     * }
     */
    public static function analyzeRepeatedGroups(string $pattern): array
    {
        // Hot-path fast exit: a pattern with no `(` cannot contain any
        // repeated or nullable group. strpos in C is faster than the
        // byte-by-byte walk for long literal patterns.
        if (strpos($pattern, '(') === false) {
            return ['repeatedGroups' => [], 'nullableNonCapturingGroups' => []];
        }
        $len = strlen($pattern);
        $groupStack = []; // stack of [captureIndex|null, openPos, isNonCapturing]
        $groups = []; // captureIndex => [openPos, closePos, quantifier]
        $allGroups = []; // sequential id => [openPos, closePos, quantifier, captureIndex|null, isNonCapturing]
        $captureIndex = 0;
        $seqIndex = 0;
        $inCharClass = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];
            // Fast-skip plain bytes outside a class. Only `\\`, `[`, `]`,
            // `(`, `)`, `*`, `+`, `?`, `{` change state inside the loop.
            if (
                !$inCharClass
                && $ch !== '\\' && $ch !== '[' && $ch !== ']'
                && $ch !== '(' && $ch !== ')'
                && $ch !== '*' && $ch !== '+' && $ch !== '?' && $ch !== '{'
            ) {
                $skip = strcspn($pattern, "\\[]()*+?{", $i);
                if ($skip > 1) {
                    $i += $skip - 1;
                    continue;
                }
            } elseif ($inCharClass && $ch !== '\\' && $ch !== ']') {
                $skip = strcspn($pattern, "\\]", $i);
                if ($skip > 1) {
                    $i += $skip - 1;
                    continue;
                }
            }

            if ($ch === '\\' && $i + 1 < $len) {
                $i++;
                continue;
            }

            if ($ch === '[' && !$inCharClass) {
                $inCharClass = true;
                continue;
            }
            if ($ch === ']' && $inCharClass) {
                $inCharClass = false;
                continue;
            }
            if ($inCharClass) {
                continue;
            }

            if ($ch === '(') {
                $isCapturing = false;
                $isNonCapturing = false;
                if ($i + 1 < $len && $pattern[$i + 1] !== '?') {
                    $isCapturing = true;
                } elseif (
                    $i + 3 < $len && $pattern[$i + 1] === '?'
                    && $pattern[$i + 2] === '<'
                    && $pattern[$i + 3] !== '=' && $pattern[$i + 3] !== '!'
                ) {
                    $isCapturing = true;
                } elseif (
                    $i + 2 < $len && $pattern[$i + 1] === '?'
                    && $pattern[$i + 2] === ':'
                ) {
                    $isNonCapturing = true;
                }

                $thisSeq = $seqIndex++;
                if ($isCapturing) {
                    $captureIndex++;
                    $groupStack[] = [$captureIndex, $i, false, $thisSeq];
                    $groups[$captureIndex] = [
                        'openPos' => $i,
                        'closePos' => null,
                        'quantifier' => null,
                    ];
                    $allGroups[$thisSeq] = [
                        'openPos' => $i,
                        'closePos' => null,
                        'quantifier' => null,
                        'captureIndex' => $captureIndex,
                        'isNonCapturing' => false,
                    ];
                } else {
                    $groupStack[] = [null, $i, $isNonCapturing, $thisSeq];
                    $allGroups[$thisSeq] = [
                        'openPos' => $i,
                        'closePos' => null,
                        'quantifier' => null,
                        'captureIndex' => null,
                        'isNonCapturing' => $isNonCapturing,
                    ];
                }
                continue;
            }

            if ($ch === ')' && !empty($groupStack)) {
                $popped = array_pop($groupStack);
                $grpIdx = $popped[0];
                $thisSeq = $popped[3];

                // Check for quantifier after closing paren.
                $quantifier = null;
                $lazy = false;
                if ($i + 1 < $len) {
                    $next = $pattern[$i + 1];
                    if ($next === '*' || $next === '+' || $next === '?') {
                        $quantifier = $next;
                        // `*?`, `+?`, `??` are lazy variants. Skip the
                        // ? when it directly follows another quantifier.
                        if (
                            $next !== '?'
                            && $i + 2 < $len
                            && $pattern[$i + 2] === '?'
                        ) {
                            $lazy = true;
                        }
                    } elseif ($next === '{') {
                        $quantifier = '{';
                        // Find closing `}` then check for trailing `?`.
                        $close = strpos($pattern, '}', $i + 2);
                        if ($close !== false && $close + 1 < $len && $pattern[$close + 1] === '?') {
                            $lazy = true;
                        }
                    }
                }

                if ($grpIdx !== null) {
                    $groups[$grpIdx]['closePos'] = $i;
                    $groups[$grpIdx]['quantifier'] = $quantifier;
                    $groups[$grpIdx]['lazy'] = $lazy;
                }
                $allGroups[$thisSeq]['closePos'] = $i;
                $allGroups[$thisSeq]['quantifier'] = $quantifier;
                $allGroups[$thisSeq]['lazy'] = $lazy;
                continue;
            }
        }

        $repeatedGroups = [];
        foreach ($groups as $idx => $g) {
            // Process groups with quantifiers that allow zero matches at
            // runtime. Per the ES RepeatMatcher rule, when min=0 and the
            // body matches the empty string, the iteration is discarded
            // and captures inside reset to undefined.
            if (
                $g['quantifier'] !== '*'
                && $g['quantifier'] !== '+'
                && $g['quantifier'] !== '?'
                && $g['quantifier'] !== '{'
            ) {
                continue;
            }
            if ($g['closePos'] === null) {
                continue;
            }

            // Extract the body pattern (everything between the parens).
            $bodyStart = $g['openPos'] + 1;
            // Skip past named group prefix (?<name>) if present.
            if (
                $bodyStart < $len && $pattern[$bodyStart] === '?'
                && $bodyStart + 1 < $len && $pattern[$bodyStart + 1] === '<'
            ) {
                $end = strpos($pattern, '>', $bodyStart + 2);
                if ($end !== false) {
                    $bodyStart = $end + 1;
                }
            }
            $bodyPattern = substr($pattern, $bodyStart, $g['closePos'] - $bodyStart);

            // Find inner captures (captures whose open position is between this group's parens).
            $innerCaptures = [];
            foreach ($groups as $innerIdx => $inner) {
                if (
                    $innerIdx !== $idx
                    && $inner['openPos'] > $g['openPos']
                    && $inner['closePos'] !== null
                    && $inner['closePos'] < $g['closePos']
                ) {
                    $innerCaptures[] = $innerIdx;
                }
            }

            // Check if body is nullable (can match empty string).
            $nullable = self::isPatternNullable($bodyPattern);

            $repeatedGroups[$idx] = [
                'innerCaptures' => $innerCaptures,
                'bodyPattern' => $bodyPattern,
                'nullable' => $nullable,
                'quantifier' => $g['quantifier'],
                'lazy' => $g['lazy'] ?? false,
            ];
        }

        // Detect non-capturing groups with min-zero quantifiers (?, *, {0,...})
        // that contain capturing groups. Per ES spec RepeatMatcher step 2.b,
        // when min=0 and the body matches zero-length, the repetition returns
        // failure, causing captures inside to be reset to undefined. PCRE does
        // not implement this, so we track these for post-processing.
        $nullableNonCapturingGroups = [];
        foreach ($allGroups as $seqIdx => $ag) {
            if (!$ag['isNonCapturing'] || $ag['closePos'] === null) {
                continue;
            }
            // Check if the quantifier allows zero matches.
            $q = $ag['quantifier'];
            $minZero = false;
            if ($q === '?' || $q === '*') {
                $minZero = true;
            } elseif ($q === '{') {
                // Parse {N,...} to check if N is 0.
                $bPos = $ag['closePos'] + 2; // after ){
                $digits = '';
                while ($bPos < $len && $pattern[$bPos] >= '0' && $pattern[$bPos] <= '9') {
                    $digits .= $pattern[$bPos];
                    $bPos++;
                }
                if ($digits !== '' && (int) $digits === 0) {
                    $minZero = true;
                }
            }

            if (!$minZero) {
                continue;
            }

            // Find capturing groups inside this non-capturing group.
            $innerCaptures = [];
            foreach ($groups as $capIdx => $g) {
                if (
                    $g['openPos'] > $ag['openPos']
                    && $g['closePos'] !== null
                    && $g['closePos'] < $ag['closePos']
                ) {
                    $innerCaptures[] = $capIdx;
                }
            }

            if (empty($innerCaptures)) {
                continue;
            }

            // Check if the body is purely zero-width (only lookaheads/lookbehinds).
            $bodyStart = $ag['openPos'] + 1;
            // Skip ?: prefix.
            if (
                $bodyStart < $len && $pattern[$bodyStart] === '?'
                && $bodyStart + 1 < $len && $pattern[$bodyStart + 1] === ':'
            ) {
                $bodyStart += 2;
            }
            $bodyPattern = substr($pattern, $bodyStart, $ag['closePos'] - $bodyStart);
            $zeroWidth = self::isPatternZeroWidth($bodyPattern);

            if ($zeroWidth) {
                $nullableNonCapturingGroups[] = [
                    'innerCaptures' => $innerCaptures,
                ];
            }
        }

        return [
            'repeatedGroups' => $repeatedGroups,
            'nullableNonCapturingGroups' => $nullableNonCapturingGroups,
        ];
    }

    /**
     * Check if a regex pattern body consists entirely of zero-width assertions.
     * Returns true if the body can only match zero-length (lookaheads, lookbehinds,
     * word boundaries, anchors).
     */
    private static function isPatternZeroWidth(string $pattern): bool
    {
        $len = strlen($pattern);
        $i = 0;

        while ($i < $len) {
            $ch = $pattern[$i];

            // Skip whitespace.
            if ($ch === ' ' || $ch === "\t" || $ch === "\n") {
                $i++;
                continue;
            }

            // Anchors are zero-width.
            if ($ch === '^' || $ch === '$') {
                $i++;
                continue;
            }

            // \b and \B are zero-width.
            if ($ch === '\\' && $i + 1 < $len && ($pattern[$i + 1] === 'b' || $pattern[$i + 1] === 'B')) {
                $i += 2;
                continue;
            }

            // Lookahead/lookbehind groups are zero-width.
            if (
                $ch === '(' && $i + 2 < $len
                && $pattern[$i + 1] === '?'
                && ($pattern[$i + 2] === '=' || $pattern[$i + 2] === '!')
            ) {
                // Skip to the matching close paren.
                $depth = 1;
                $j = $i + 1;
                while ($j < $len && $depth > 0) {
                    if ($pattern[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($pattern[$j] === '(') {
                        $depth++;
                    } elseif ($pattern[$j] === ')') {
                        $depth--;
                    }
                    $j++;
                }
                $i = $j;
                // Skip any quantifier after.
                if ($i < $len && ($pattern[$i] === '?' || $pattern[$i] === '*' || $pattern[$i] === '+')) {
                    $i++;
                }
                continue;
            }

            // Lookbehind (?<=...) or (?<!...).
            if (
                $ch === '(' && $i + 3 < $len
                && $pattern[$i + 1] === '?'
                && $pattern[$i + 2] === '<'
                && ($pattern[$i + 3] === '=' || $pattern[$i + 3] === '!')
            ) {
                $depth = 1;
                $j = $i + 1;
                while ($j < $len && $depth > 0) {
                    if ($pattern[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($pattern[$j] === '(') {
                        $depth++;
                    } elseif ($pattern[$j] === ')') {
                        $depth--;
                    }
                    $j++;
                }
                $i = $j;
                if ($i < $len && ($pattern[$i] === '?' || $pattern[$i] === '*' || $pattern[$i] === '+')) {
                    $i++;
                }
                continue;
            }

            // Non-capturing group containing only zero-width patterns.
            if (
                $ch === '(' && $i + 2 < $len
                && $pattern[$i + 1] === '?'
                && $pattern[$i + 2] === ':'
            ) {
                $depth = 1;
                $j = $i + 1;
                while ($j < $len && $depth > 0) {
                    if ($pattern[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($pattern[$j] === '(') {
                        $depth++;
                    } elseif ($pattern[$j] === ')') {
                        $depth--;
                    }
                    $j++;
                }
                // Extract body and recurse.
                $bodyInner = substr($pattern, $i + 3, $j - 1 - ($i + 3));
                if (!self::isPatternZeroWidth($bodyInner)) {
                    return false;
                }
                $i = $j;
                // Skip quantifier.
                if ($i < $len && ($pattern[$i] === '?' || $pattern[$i] === '*' || $pattern[$i] === '+')) {
                    $i++;
                }
                continue;
            }

            // Anything else is not zero-width.
            return false;
        }

        return true;
    }

    /**
     * Check if a regex pattern can match the empty string.
     * This is a conservative check: it returns true if the pattern appears nullable.
     * For simple patterns (concatenation of optional elements), this is accurate.
     */
    private static function isPatternNullable(string $pattern): bool
    {
        // A concatenation is nullable if every element is nullable.
        // An alternation is nullable if any branch is nullable.
        // We parse the pattern at the top level and check each element.
        $len = strlen($pattern);
        $i = 0;
        $inAlternation = false;
        $currentBranchNullable = true;
        $anyBranchNullable = false;

        while ($i < $len) {
            $ch = $pattern[$i];

            if ($ch === '\\' && $i + 1 < $len) {
                // Escaped character: not nullable by itself.
                $i += 2;
                // Check for quantifier after.
                if ($i < $len && ($pattern[$i] === '?' || $pattern[$i] === '*')) {
                    $i++;
                    if ($i < $len && $pattern[$i] === '?') {
                        $i++; // lazy modifier
                    }
                    // nullable element, continue
                } elseif ($i < $len && $pattern[$i] === '{') {
                    // Check if {0,...} or {n,...} with n > 0.
                    $j = $i + 1;
                    $digits = '';
                    while ($j < $len && $pattern[$j] >= '0' && $pattern[$j] <= '9') {
                        $digits .= $pattern[$j];
                        $j++;
                    }
                    if ($digits !== '' && (int) $digits === 0) {
                        // {0,...} is nullable.
                        while ($j < $len && $pattern[$j] !== '}') {
                            $j++;
                        }
                        $i = $j + 1;
                    } else {
                        $currentBranchNullable = false;
                    }
                } elseif ($i < $len && $pattern[$i] === '+') {
                    $currentBranchNullable = false;
                    $i++;
                } else {
                    $currentBranchNullable = false;
                }
                continue;
            }

            if ($ch === '[') {
                // Character class: not nullable by itself.
                $i++;
                while ($i < $len && $pattern[$i] !== ']') {
                    if ($pattern[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
                if ($i < $len) {
                    $i++; // skip ]
                }
                // Check for quantifier after.
                if ($i < $len && ($pattern[$i] === '?' || $pattern[$i] === '*')) {
                    $i++;
                    if ($i < $len && $pattern[$i] === '?') {
                        $i++;
                    }
                } elseif ($i < $len && $pattern[$i] === '{') {
                    $j = $i + 1;
                    $digits = '';
                    while ($j < $len && $pattern[$j] >= '0' && $pattern[$j] <= '9') {
                        $digits .= $pattern[$j];
                        $j++;
                    }
                    if ($digits !== '' && (int) $digits === 0) {
                        while ($j < $len && $pattern[$j] !== '}') {
                            $j++;
                        }
                        $i = $j + 1;
                    } else {
                        $currentBranchNullable = false;
                    }
                } elseif ($i < $len && $pattern[$i] === '+') {
                    $currentBranchNullable = false;
                    $i++;
                } else {
                    $currentBranchNullable = false;
                }
                continue;
            }

            if ($ch === '|') {
                // Alternation boundary.
                $inAlternation = true;
                if ($currentBranchNullable) {
                    $anyBranchNullable = true;
                }
                $currentBranchNullable = true; // reset for next branch
                $i++;
                continue;
            }

            if ($ch === '(') {
                // Group: skip to matching close paren and check quantifier.
                $depth = 1;
                $i++;
                while ($i < $len && $depth > 0) {
                    if ($pattern[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($pattern[$i] === '(') {
                        $depth++;
                    } elseif ($pattern[$i] === ')') {
                        $depth--;
                    }
                    $i++;
                }
                // $i is now past the closing ')'.
                // Check for quantifier.
                if ($i < $len && ($pattern[$i] === '?' || $pattern[$i] === '*')) {
                    $i++;
                    if ($i < $len && $pattern[$i] === '?') {
                        $i++;
                    }
                    // Nullable (group with ? or * quantifier).
                } elseif ($i < $len && $pattern[$i] === '{') {
                    $j = $i + 1;
                    $digits = '';
                    while ($j < $len && $pattern[$j] >= '0' && $pattern[$j] <= '9') {
                        $digits .= $pattern[$j];
                        $j++;
                    }
                    if ($digits !== '' && (int) $digits === 0) {
                        while ($j < $len && $pattern[$j] !== '}') {
                            $j++;
                        }
                        $i = $j + 1;
                    } else {
                        $currentBranchNullable = false;
                    }
                } elseif ($i < $len && $pattern[$i] === '+') {
                    $currentBranchNullable = false;
                    $i++;
                } else {
                    // No quantifier: the group itself must be nullable.
                    // We'd need to recurse, but for simplicity treat as non-nullable.
                    $currentBranchNullable = false;
                }
                continue;
            }

            // Anchors and zero-width assertions are nullable.
            if ($ch === '^' || $ch === '$') {
                $i++;
                continue;
            }

            // Literal character or '.': not nullable by itself.
            $i++;
            if ($i < $len && ($pattern[$i] === '?' || $pattern[$i] === '*')) {
                $i++;
                if ($i < $len && $pattern[$i] === '?') {
                    $i++;
                }
                // nullable
            } elseif ($i < $len && $pattern[$i] === '{') {
                $j = $i + 1;
                $digits = '';
                while ($j < $len && $pattern[$j] >= '0' && $pattern[$j] <= '9') {
                    $digits .= $pattern[$j];
                    $j++;
                }
                if ($digits !== '' && (int) $digits === 0) {
                    while ($j < $len && $pattern[$j] !== '}') {
                        $j++;
                    }
                    $i = $j + 1;
                } else {
                    $currentBranchNullable = false;
                }
            } elseif ($i < $len && $pattern[$i] === '+') {
                $currentBranchNullable = false;
                $i++;
            } else {
                $currentBranchNullable = false;
            }
        }

        if ($inAlternation) {
            return $anyBranchNullable || $currentBranchNullable;
        }
        return $currentBranchNullable;
    }

    /**
     * Post-process PCRE match results to fix ES-compliant capture reset
     * for capturing groups inside repeated (quantified) outer groups.
     *
     * PCRE retains the last successful match for captures inside a repeated group
     * across all iterations. ES spec requires captures to be reset to undefined
     * at the start of each iteration, so only captures that participated in the
     * LAST iteration should have values.
     *
     * @param array<int|string, array{0: ?string, 1: int}> $matches PCRE match result
     * @param array{
     *     repeatedGroups: array<int, array{
     *         innerCaptures: list<int>,
     *         bodyPattern: string,
     *         nullable: bool,
     *         quantifier: ?string,
     *     }>,
     *     nullableNonCapturingGroups?: list<array{innerCaptures: list<int>}>,
     * } $analysis
     * @param string $pcreFlags The PCRE flags string (e.g., 'iu')
     * @param callable $transformFn Transforms ES pattern to PCRE pattern
     * @return array<int|string, array{0: ?string, 1: int}>
     */
    public static function fixRepeatedGroupCaptures(
        array $matches,
        array $analysis,
        string $pcreFlags,
        callable $transformFn,
    ): array {
        foreach ($analysis['repeatedGroups'] as $groupIdx => $info) {
            // Per RepeatMatcher step 2.b: when min=0 and the body matched
            // empty, the iteration is discarded and the capture itself is
            // undefined. PCRE keeps the empty match instead.
            if (
                isset($matches[$groupIdx])
                && $matches[$groupIdx][0] === ''
                && $info['quantifier'] === '?'
                && $info['nullable']
            ) {
                $matches[$groupIdx] = [null, -1];
                foreach ($info['innerCaptures'] as $innerIdx) {
                    if (isset($matches[$innerIdx])) {
                        $matches[$innerIdx] = [null, -1];
                    }
                }
                continue;
            }

            if (empty($info['innerCaptures'])) {
                continue;
            }

            // Get the last captured value of the outer repeated group.
            if (!isset($matches[$groupIdx]) || $matches[$groupIdx][0] === null || $matches[$groupIdx][1] === -1) {
                // Outer group didn't match: all inner captures should be undefined.
                foreach ($info['innerCaptures'] as $innerIdx) {
                    if (isset($matches[$innerIdx])) {
                        $matches[$innerIdx] = [null, -1];
                    }
                }
                continue;
            }

            $lastCapturedValue = $matches[$groupIdx][0];

            // Build a PCRE pattern for just the inner body with captures.
            $innerEsPattern = $info['bodyPattern'];
            $innerPcreBody = $transformFn($innerEsPattern);
            $innerPcrePattern = '/^' . $innerPcreBody . '$/' . $pcreFlags;

            // Match the inner pattern against the last captured value.
            $innerResult = @preg_match(
                $innerPcrePattern,
                $lastCapturedValue,
                $innerMatches,
                PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
            );

            if ($innerResult === 1) {
                // Map inner match results back to the original capture indices.
                $innerCaptureList = $info['innerCaptures'];
                for ($k = 0; $k < count($innerCaptureList); $k++) {
                    $originalIdx = $innerCaptureList[$k];
                    $innerIdx = $k + 1; // Inner match group 1 corresponds to first inner capture.
                    if (
                        isset($innerMatches[$innerIdx])
                        && $innerMatches[$innerIdx][0] !== null
                    ) {
                        // Calculate the byte offset relative to the outer group match position.
                        $outerByteOffset = $matches[$groupIdx][1];
                        $matches[$originalIdx] = [
                            $innerMatches[$innerIdx][0],
                            $outerByteOffset + $innerMatches[$innerIdx][1],
                        ];
                    } else {
                        $matches[$originalIdx] = [null, -1];
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * Handle nullable quantifier patterns by implementing iterative matching.
     *
     * When a quantified group (e.g., (X)*) has a nullable body (X can match empty),
     * PCRE stops the repetition on empty match, but ES spec discards the empty
     * iteration and backtracks to find non-empty alternatives.
     *
     * This method detects whether the PCRE result was cut short by the nullable
     * quantifier issue and extends the match by trying substrings of increasing
     * length against the anchored inner pattern, forcing non-empty matches.
     *
     * @param array<int|string, array{0: ?string, 1: int}> $matches PCRE match result
     * @param array{repeatedGroups: array<int, array{innerCaptures: list<int>, bodyPattern: string, nullable: bool, lazy?: bool}>} $analysis
     * @param string $str The full input string
     * @param string $pcreFlags The PCRE flags string (e.g., 'iu')
     * @param callable $transformFn Transforms ES pattern to PCRE pattern
     * @return array<int|string, array{0: ?string, 1: int}>
     */
    public static function fixNullableQuantifier(
        array $matches,
        array $analysis,
        string $str,
        string $pcreFlags,
        callable $transformFn,
    ): array {
        foreach ($analysis['repeatedGroups'] as $groupIdx => $info) {
            if (!$info['nullable']) {
                continue;
            }

            if (!isset($matches[$groupIdx])) {
                continue;
            }

            // Lazy quantifiers (*?, +?, ??, {N,M}?) deliberately match
            // as few iterations as possible. The "extend" heuristic
            // below is for greedy nullable bodies where PCRE2 hit a
            // zero-width match too early — applying it to a lazy
            // quantifier would consume past the lazy stop point.
            if (!empty($info['lazy'])) {
                continue;
            }

            // Calculate the end position of the current overall match.
            $overallMatch = $matches[0][0] ?? '';
            $overallByteStart = $matches[0][1] ?? 0;
            $overallByteEnd = $overallByteStart + strlen($overallMatch);

            // If we're already at end of string, nothing to extend.
            if ($overallByteEnd >= strlen($str)) {
                continue;
            }

            // Build anchored PCRE pattern for the inner body. Using ^ and $ anchors
            // forces PCRE to match the entire substring, which prevents the nullable
            // body from matching empty when there are characters available.
            // This avoids PCRE2 JIT bugs with (*NOTEMPTY_ATSTART).
            $innerEsPattern = $info['bodyPattern'];
            $innerPcreBody = $transformFn($innerEsPattern);
            $anchoredPattern = '/^(' . $innerPcreBody . ')$/' . $pcreFlags;

            // Also build an unanchored pattern for normal (non-empty) matching.
            $normalPattern = '/(' . $innerPcreBody . ')/' . $pcreFlags;

            // Iteratively extend the match from the current end position.
            $currentByteEnd = $overallByteEnd;
            $extended = false;
            $lastGroupCapture = $matches[$groupIdx];
            $strLen = strlen($str);

            while ($currentByteEnd < $strLen) {
                // First, try normal unanchored match at current position.
                // This handles the common case where the inner pattern matches
                // non-empty without needing the substring workaround.
                $innerMatches = [];
                $innerResult = @preg_match(
                    $normalPattern,
                    $str,
                    $innerMatches,
                    PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
                    $currentByteEnd,
                );

                if (
                    $innerResult === 1
                    && $innerMatches[0][1] === $currentByteEnd
                    && strlen($innerMatches[0][0]) > 0
                ) {
                    // Non-empty match at current position: extend and continue.
                    $currentByteEnd += strlen($innerMatches[0][0]);
                    $lastGroupCapture = [$innerMatches[1][0], $innerMatches[1][1]];
                    $extended = true;
                    continue;
                }

                // Empty match or no match. Use the substring approach:
                // try substrings of length 1, 2, ... from current position and
                // match the anchored pattern (^body$) against each. The anchors
                // force PCRE to use the available characters rather than matching empty.
                $found = false;
                $remaining = $strLen - $currentByteEnd;
                for ($tryLen = 1; $tryLen <= $remaining; $tryLen++) {
                    $sub = substr($str, $currentByteEnd, $tryLen);
                    // Verify this is a valid UTF-8 boundary (don't split multi-byte chars).
                    if (mb_check_encoding($sub, 'UTF-8') === false) {
                        continue;
                    }
                    $subMatches = [];
                    $subResult = @preg_match(
                        $anchoredPattern,
                        $sub,
                        $subMatches,
                        PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
                    );
                    if ($subResult === 1 && strlen($subMatches[0][0]) > 0) {
                        // Found a non-empty match of length $tryLen.
                        $lastGroupCapture = [$subMatches[1][0], $currentByteEnd + $subMatches[1][1]];
                        $currentByteEnd += strlen($subMatches[0][0]);
                        $extended = true;
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    // No non-empty match possible at this position: stop iterating.
                    break;
                }
            }

            if ($extended) {
                // Update the overall match to include the extended portion.
                $newOverallMatch = substr($str, $overallByteStart, $currentByteEnd - $overallByteStart);
                $matches[0] = [$newOverallMatch, $overallByteStart];

                // Update the group capture to reflect the last iteration.
                $matches[$groupIdx] = $lastGroupCapture;
            }
        }

        return $matches;
    }

    /**
     * Fix captures inside nullable non-capturing groups.
     *
     * Per ES spec RepeatMatcher step 2.b: when min=0 and the body matched
     * zero-length, the repetition returns failure and captures inside are
     * reset to undefined. PCRE does not implement this: it keeps captures
     * from the zero-width match. This method detects such cases and resets
     * the affected captures.
     *
     * @param array<int|string, array{0: ?string, 1: int}> $matches
     * @return array<int|string, array{0: ?string, 1: int}>
     * @param array<mixed> $analysis
     */
    public static function fixNullableNonCapturingGroupCaptures(
        array $matches,
        array $analysis,
    ): array {
        if (empty($analysis['nullableNonCapturingGroups'])) {
            return $matches;
        }

        foreach ($analysis['nullableNonCapturingGroups'] as $info) {
            foreach ($info['innerCaptures'] as $capIdx) {
                if (isset($matches[$capIdx])) {
                    // Reset the capture to unmatched (null at offset -1).
                    $matches[$capIdx] = [null, -1];
                }
            }
        }

        return $matches;
    }

    /**
     * Validate (?addFlags-removeFlags:...) modifier groups. Per spec, the
     * allowed flags are `i`, `m`, `s` and both sides combined must be
     * non-empty and non-overlapping, with no flag repeated on either side.
     */
    public static function validateRegExpModifierGroups(string $pattern): void
    {
        // Hot-path fast exit: a pattern with no `(?` syntax has no
        // modifier groups to validate.
        if (strpos($pattern, '(?') === false) {
            return;
        }
        $len = strlen($pattern);
        $i = 0;
        $inClass = false;
        while ($i < $len) {
            $ch = $pattern[$i];
            if ($ch !== '\\' && $ch !== '[' && $ch !== ']' && $ch !== '(') {
                $skip = strcspn($pattern, "\\[](", $i);
                if ($skip > 0) {
                    $i += $skip;
                    continue;
                }
            }
            if ($ch === '\\') {
                $i += 2;
                continue;
            }
            if (!$inClass && $ch === '[') {
                $inClass = true;
                $i++;
                continue;
            }
            if ($inClass) {
                if ($ch === ']') {
                    $inClass = false;
                }
                $i++;
                continue;
            }
            if ($ch === '(' && $i + 1 < $len && $pattern[$i + 1] === '?') {
                // Distinguish `(?:`, `(?=`, `(?!`, `(?<=`, `(?<!`, `(?<name>`
                // from a modifier group `(?flags-flags:`. Modifier groups
                // start with i/m/s or `-`.
                if ($i + 2 < $len) {
                    $first = $pattern[$i + 2];
                    if (
                        !in_array($first, ['i', 'm', 's', '-'], true)
                    ) {
                        $i += 2;
                        continue;
                    }
                }
                // Scan flag characters after `(?`.
                $j = $i + 2;
                $add = '';
                $remove = '';
                $phase = 'add';
                $hasMinus = false;
                $hasColon = false;
                while ($j < $len) {
                    $c = $pattern[$j];
                    if ($c === '-' && $phase === 'add' && !$hasMinus) {
                        $phase = 'remove';
                        $hasMinus = true;
                        $j++;
                        continue;
                    }
                    if ($c === ':') {
                        $hasColon = true;
                        break;
                    }
                    if ($c === ')') {
                        break;
                    }
                    if (!in_array($c, ['i', 'm', 's'], true)) {
                        // Invalid character in modifier group.
                        throw new \Phasis\Exceptions\SyntaxError(
                            "Invalid regular expression: /{$pattern}/: Invalid modifier flag"
                        );
                    }
                    if ($phase === 'add') {
                        $add .= $c;
                    } else {
                        $remove .= $c;
                    }
                    $j++;
                }
                if (!$hasColon) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Modifier group requires colon"
                    );
                }
                if ($add === '' && $remove === '') {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Modifier group has no flags"
                    );
                }
                // Repeated flag on either side → SyntaxError.
                if (strlen(count_chars($add, 3)) !== strlen($add)) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Repeated flag in modifier"
                    );
                }
                if (strlen(count_chars($remove, 3)) !== strlen($remove)) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Repeated flag in modifier"
                    );
                }
                // Overlap between add and remove → SyntaxError.
                for ($k = 0; $k < strlen($add); $k++) {
                    if (str_contains($remove, $add[$k])) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            "Invalid regular expression: /{$pattern}/: Flag in both add and remove modifiers"
                        );
                    }
                }
                $i = $j + 1;
                continue;
            }
            $i++;
        }
    }

    /**
     * Detect duplicate named capture groups declared within the same
     * Alternative (i.e. not separated by a top-level `|`). Per spec, this
     * is a SyntaxError even under the duplicate-named-capture-groups
     * proposal — duplicates are only allowed across disjoint alternatives.
     */
    public static function hasDuplicateNamedGroupsInSameAlternative(string $pattern): bool
    {
        // Hot-path fast exit: at least two named-group declarations are
        // required for a duplicate to exist.
        if (strpos($pattern, '(?<') === false) {
            return false;
        }
        // Collect declared names per alternative, descending into groups but
        // restarting the "seen" set on every top-level `|`. Groups themselves
        // introduce their own alternative scope (their internal `|` splits
        // the inner names, independent of the outer set).
        $len = strlen($pattern);
        $i = 0;
        // Stack of "seen-name" sets, one per nested alternative scope.
        $stack = [[]];
        $topIndex = 0;
        while ($i < $len) {
            $ch = $pattern[$i];
            if ($ch !== '\\' && $ch !== '[' && $ch !== '(' && $ch !== ')' && $ch !== '|') {
                $skip = strcspn($pattern, "\\[()|", $i);
                if ($skip > 0) {
                    $i += $skip;
                    continue;
                }
            }
            if ($ch === '\\') {
                $i += 2;
                continue;
            }
            if ($ch === '[') {
                $i++;
                while ($i < $len && $pattern[$i] !== ']') {
                    if ($pattern[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
                $i++;
                continue;
            }
            if ($ch === '|') {
                // New alternative at the current nesting: reset its seen set.
                $stack[$topIndex] = [];
                $i++;
                continue;
            }
            if (
                $ch === '('
                && $i + 1 < $len
                && $pattern[$i + 1] === '?'
            ) {
                // Is it a named capture (?<name>..) — not lookbehind (?<=..) or (?<!..)?
                if (
                    $i + 3 < $len
                    && $pattern[$i + 2] === '<'
                    && $pattern[$i + 3] !== '='
                    && $pattern[$i + 3] !== '!'
                ) {
                    $nameStart = $i + 3;
                    $nameEnd = $nameStart;
                    while ($nameEnd < $len && $pattern[$nameEnd] !== '>') {
                        $nameEnd++;
                    }
                    if ($nameEnd < $len) {
                        $name = substr($pattern, $nameStart, $nameEnd - $nameStart);
                        if (isset($stack[$topIndex][$name])) {
                            return true;
                        }
                        $stack[$topIndex][$name] = true;
                        $i = $nameEnd + 1;
                        // Enter a new alternative scope for the group body.
                        $stack[] = [];
                        $topIndex++;
                        continue;
                    }
                }
                // Non-capturing or other (?...) group — push scope.
                $stack[] = [];
                $topIndex++;
                $i += 2;
                continue;
            }
            if ($ch === '(') {
                // Plain capturing group — push scope.
                $stack[] = [];
                $topIndex++;
                $i++;
                continue;
            }
            if ($ch === ')') {
                // Pop scope.
                if ($topIndex > 0) {
                    array_pop($stack);
                    $topIndex--;
                }
                $i++;
                continue;
            }
            $i++;
        }
        return false;
    }

    /**
     * Detect duplicate named capture groups in an ES pattern.
     */
    private static function hasDuplicateNamedGroups(
        string $pattern
    ): bool {
        // Hot-path fast exit.
        if (strpos($pattern, '(?<') === false) {
            return false;
        }
        $seen = [];
        $len = strlen($pattern);
        $i = 0;
        while ($i < $len) {
            $ch = $pattern[$i];
            if ($ch !== '\\' && $ch !== '[' && $ch !== '(') {
                $skip = strcspn($pattern, "\\[(", $i);
                if ($skip > 0) {
                    $i += $skip;
                    continue;
                }
            }
            if ($ch === '\\') {
                $i += 2;
                continue;
            }
            if ($ch === '[') {
                $i++;
                while ($i < $len && $pattern[$i] !== ']') {
                    if ($pattern[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
                $i++;
                continue;
            }
            if (
                $ch === '('
                && $i + 3 < $len
                && $pattern[$i + 1] === '?'
                && $pattern[$i + 2] === '<'
                && $pattern[$i + 3] !== '='
                && $pattern[$i + 3] !== '!'
            ) {
                $nameStart = $i + 3;
                $nameEnd = $nameStart;
                while ($nameEnd < $len && $pattern[$nameEnd] !== '>') {
                    $nameEnd++;
                }
                if ($nameEnd < $len) {
                    $name = substr(
                        $pattern,
                        $nameStart,
                        $nameEnd - $nameStart,
                    );
                    if (isset($seen[$name])) {
                        return true;
                    }
                    $seen[$name] = true;
                }
                $i = $nameEnd + 1;
                continue;
            }
            $i++;
        }
        return false;
    }

    /**
     * Detect whether the pattern uses any feature where PCRE2's
     * matching diverges from ECMA-262 in a user-visible way:
     *   - A lookbehind that contains a capture group (PCRE2 captures
     *     left-to-right, ES specifies right-to-left).
     *   - A quantified group containing captures (PCRE2 keeps state
     *     between iterations, ES resets).
     *
     * When this returns true the regex compiler keeps a parsed AST on
     * the regex object so exec() can route through the in-engine
     * matcher (see src/Regex/Matcher.php).
     */
    public static function patternNeedsCustomMatcher(string $pattern, string $flags = ''): bool
    {
        $isUnicode = str_contains($flags, 'u') || str_contains($flags, 'v');
        $isCaseless = str_contains($flags, 'i');
        $len = strlen($pattern);
        $i = 0;
        $inClass = false;
        $hasDotInNonUnicode = false;
        $hasInlineModifier = false;
        $hasLookbehind = false;
        // PCRE2 in /u mode applies Unicode case-folding under /i; ES
        // without /u must use ASCII-only folding. If the pattern
        // contains any non-ASCII code point (literal byte or
        // \uXXXX/\xHH escape) under /i but not /u, route to the
        // custom matcher whose canonicalize() honours that
        // distinction.
        $hasNonAsciiInIWithoutU = false;
        // PCRE2 with /u (PCRE2_UTF) treats every Unicode letter as a
        // word character — broader than ECMA-262 outside /u+/i mode.
        // GetWordCharacters limits the basic set to ASCII A-Z a-z 0-9
        // _; under /u+/i it adds chars whose Canonicalize lands in
        // that basic set. PCRE2's match-the-Unicode-letters behaviour
        // happens to coincide closely enough with the spec under
        // /u+/i (test262 passes there), so route only when /u+/i is
        // NOT both set; that's where PCRE2 over-matches.
        $hasWordToken = false;
        // Track group nesting and whether we're inside a lookbehind.
        $lookbehindDepth = 0;
        $needNonAsciiScan = $isCaseless && !$isUnicode;
        $needAsciiWordCheck = !($isUnicode && $isCaseless);
        // PCRE2 with /u rejects lone surrogate code units in input
        // strings as invalid UTF-8 (we encode them via CESU-8).
        // Patterns containing astral characters (or \\u{XXXXX} with
        // value > BMP) will get tested against inputs that may
        // contain those astrals split into surrogate halves; route
        // them through the custom matcher so the codepoint walk
        // handles either form.
        $hasAstralInUnicode = false;
        $needAstralScan = $isUnicode;
        // Lone surrogate escapes (\uD800–\uDFFF) in patterns
        // need the custom matcher: PCRE2 with UTF mode rejects them
        // as invalid byte sequences. In non-/u mode, patterns like
        // /\udf06/ should still match the lone surrogate per spec
        // (regex operates on UTF-16 code units). In /u mode,
        // /[\uD83D]/u should match the lone high surrogate per spec.
        // Both routes rely on the custom matcher's UTF-16 walk.
        $hasLoneSurrogateEscape = false;
        $needSurrogateScan = true;
        // \p{...} / \P{...} Unicode property escapes need the custom
        // matcher: PCRE2's built-in Unicode tables ship with
        // whatever Unicode version PHP's PCRE was built against
        // (often older than ICU's), and many test262 property tests
        // fail at codepoints PCRE2 and ICU disagree on. The custom
        // matcher routes all lookups through IntlChar (ICU) for a
        // consistent data source.
        $hasUnicodePropertyEscape = false;
        // Unicode 16 added simple/common case-fold equivalences that
        // older ICU (Ubuntu CI ships ICU 70 / 74) doesn't know.
        // When /ui (or /vi) is set, patterns containing one of the
        // affected codepoints route through the custom matcher whose
        // Matcher::canonicalize() applies the host-independent
        // override table. PCRE2 would otherwise miss the fold pair
        // on the older ICU and produce a no-match where ICU 76+
        // says match.
        $hasUnicode16FoldCodepoint = false;
        $needUnicode16FoldScan = $isCaseless && $isUnicode;
        // Track which group has captures inside it for quantifier
        // detection. We approximate by scanning for `(...){n,m}`-like
        // shapes that contain captures.
        $hasLookbehindWithCapture = false;
        $hasQuantifiedCapture = false;
        // Stack of: ['kind' => 'capture' | 'noncapture' | 'lookbehind' | 'lookahead', 'sawCapture' => bool]
        $stack = [];
        // Most pattern bytes are uninteresting (literal digits, letters,
        // whitespace) and just advance $i without changing any flag.
        // Pre-build the byte set strcspn should stop at: control bytes
        // for grouping/escape plus, when the slow path needs to
        // inspect non-ASCII for flag updates, every high-bit byte.
        // This collapses a 16 MiB zero-padded \u{…} pattern from 16M
        // PHP iterations down to a single C-level strcspn jump.
        // Either /i+!/u (Unicode case-fold check), /u (astral codepoint
        // check), or non-/u (raw astral atom check) wants byte-level
        // inspection of high-bit runs, so always include 0x80..0xFF in
        // the strcspn stop set: the slow path handles them.
        $highBytes = '';
        for ($b = 0x80; $b <= 0xFF; $b++) {
            $highBytes .= chr($b);
        }
        $stopBytes = '\\[].()' . $highBytes;
        $stopBytesInClass = '\\]' . substr($stopBytes, 6); // drop ".()[" runners
        while ($i < $len) {
            $ch = $pattern[$i];
            // Inside character classes the only meaningful tokens are
            // `\`, `]`, and any non-ASCII byte. Plain ASCII content
            // doesn't change state — jump past it in one strcspn.
            if ($inClass) {
                if ($ch !== '\\' && $ch !== ']' && (ord($ch) & 0x80) === 0) {
                    $skip = strcspn($pattern, $stopBytesInClass, $i);
                    if ($skip > 0) {
                        $i += $skip;
                        continue;
                    }
                }
            } elseif (
                $ch !== '\\' && $ch !== '[' && $ch !== ']'
                && $ch !== '.' && $ch !== '(' && $ch !== ')'
                && (ord($ch) & 0x80) === 0
            ) {
                $skip = strcspn($pattern, $stopBytes, $i);
                if ($skip > 0) {
                    $i += $skip;
                    continue;
                }
            }
            if ($ch === '\\') {
                if ($i + 1 < $len) {
                    $next = $pattern[$i + 1];
                    {
                    if ($next === 'u' && $i + 2 < $len && $pattern[$i + 2] === '{') {
                        $closeBrace = strpos($pattern, '}', $i + 3);
                        if ($closeBrace !== false) {
                            $hex = substr($pattern, $i + 3, $closeBrace - $i - 3);
                            if (ctype_xdigit($hex)) {
                                $cp = (int) hexdec($hex);
                                if ($needNonAsciiScan && $cp > 0x7F) {
                                    $hasNonAsciiInIWithoutU = true;
                                }
                                if ($needAstralScan && $cp > 0xFFFF) {
                                    $hasAstralInUnicode = true;
                                }
                                if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                                    $hasLoneSurrogateEscape = true;
                                }
                                if ($needUnicode16FoldScan && self::isUnicode16FoldCodepoint($cp)) {
                                    $hasUnicode16FoldCodepoint = true;
                                }
                            }
                        }
                    } elseif ($next === 'u' && $i + 5 < $len) {
                        $hex = substr($pattern, $i + 2, 4);
                        if (ctype_xdigit($hex)) {
                            $cp = (int) hexdec($hex);
                            if ($needNonAsciiScan && $cp > 0x7F) {
                                $hasNonAsciiInIWithoutU = true;
                            }
                            if ($needUnicode16FoldScan && self::isUnicode16FoldCodepoint($cp)) {
                                $hasUnicode16FoldCodepoint = true;
                            }
                            // A high surrogate followed by an
                            // adjacent low surrogate is a valid
                            // pair (PCRE2 handles it as an astral
                            // codepoint). A bare lone surrogate,
                            // or a low surrogate not preceded by
                            // its high pair, needs the custom
                            // matcher because PCRE2 rejects it as
                            // invalid UTF-8.
                            if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                                $paired = false;
                                if ($cp <= 0xDBFF) {
                                    // Look ahead for paired low.
                                    if (
                                        $i + 11 < $len
                                        && $pattern[$i + 6] === '\\'
                                        && $pattern[$i + 7] === 'u'
                                        && ctype_xdigit(substr($pattern, $i + 8, 4))
                                    ) {
                                        $n2 = (int) hexdec(substr($pattern, $i + 8, 4));
                                        $paired = $n2 >= 0xDC00 && $n2 <= 0xDFFF;
                                    }
                                } else {
                                    // Low surrogate: paired iff
                                    // preceded by an adjacent high.
                                    if (
                                        $i >= 6
                                        && $pattern[$i - 6] === '\\'
                                        && $pattern[$i - 5] === 'u'
                                        && ctype_xdigit(substr($pattern, $i - 4, 4))
                                    ) {
                                        $p1 = (int) hexdec(substr($pattern, $i - 4, 4));
                                        $paired = $p1 >= 0xD800 && $p1 <= 0xDBFF;
                                    }
                                }
                                // In /u mode an adjacent pair is one
                                // astral codepoint atom (PCRE2 handles
                                // it). Outside /u each surrogate
                                // escape is its own UTF-16 code unit
                                // atom; the PCRE transform collapses
                                // adjacent pairs into a single
                                // codepoint, which loses the two-atom
                                // structure when a quantifier is
                                // attached (e.g. `/\\uD83D\\uDC38?/`
                                // wants the trail optional, not the
                                // pair). Route through the custom
                                // matcher whose UTF-16 walk preserves
                                // both atoms.
                                if (!$paired || !$isUnicode) {
                                    $hasLoneSurrogateEscape = true;
                                }
                            }
                        }
                    } elseif ($next === 'x' && $i + 3 < $len) {
                        $hex = substr($pattern, $i + 2, 2);
                        if (
                            $needNonAsciiScan
                            && ctype_xdigit($hex)
                            && (int) hexdec($hex) > 0x7F
                        ) {
                            $hasNonAsciiInIWithoutU = true;
                        }
                    }
                    }
                    if (
                        $needAsciiWordCheck
                        && ($next === 'b' || $next === 'B' || $next === 'w' || $next === 'W')
                    ) {
                        $hasWordToken = true;
                    }
                    // \p{...} / \P{...} present in the pattern. Only
                    // applicable in /u or /v mode; outside Unicode
                    // mode \p has no special meaning and the
                    // translator already lowers it to a literal.
                    if (
                        $isUnicode
                        && ($next === 'p' || $next === 'P')
                        && $i + 2 < $len
                        && $pattern[$i + 2] === '{'
                    ) {
                        $hasUnicodePropertyEscape = true;
                    }
                }
                $i += 2;
                continue;
            }
            if ($needNonAsciiScan && ord($ch) > 0x7F) {
                $hasNonAsciiInIWithoutU = true;
            }
            if ($needAstralScan && (ord($ch) & 0xF8) === 0xF0) {
                // 4-byte UTF-8 = astral codepoint.
                $hasAstralInUnicode = true;
            }
            if (!$isUnicode && (ord($ch) & 0xF8) === 0xF0) {
                // Non-/u: a raw astral character in the pattern is two
                // UTF-16 code-unit atoms per spec. PCRE2 treats it as
                // one codepoint, so a quantifier like `🐸?` would
                // make the whole 4-byte char optional instead of just
                // its trail surrogate. Route to the custom matcher
                // whose parser splits raw astrals into a lead atom +
                // pending-trail atom.
                $hasLoneSurrogateEscape = true;
            }
            if (!$inClass && $ch === '[') {
                // /u patterns with `[^]` (negated empty class — i.e.
                // "match any code unit including lone surrogates")
                // need the custom matcher: PCRE2 with /u rejects
                // lone-surrogate input as invalid UTF-8 and returns
                // an internal error, so /[^]/u.exec("\uD83D") would
                // always return null even though the spec says it
                // should match.
                if (
                    $isUnicode
                    && $i + 2 < $len
                    && $pattern[$i + 1] === '^'
                    && $pattern[$i + 2] === ']'
                ) {
                    $hasLoneSurrogateEscape = true;
                }
                $inClass = true;
                $i++;
                continue;
            }
            if ($inClass && $ch === ']') {
                $inClass = false;
                $i++;
                continue;
            }
            if ($inClass) {
                $i++;
                continue;
            }
            if ($ch === '.') {
                // Dot semantics where PCRE2 diverges from spec:
                //   - non-unicode mode: PCRE2 treats astrals as one
                //     codepoint, ECMAScript needs UTF-16 code units.
                //   - /u or /u+/s: spec includes lone surrogates,
                //     which our CESU-8 encoding produces as bytes
                //     PCRE2 rejects as invalid UTF-8. Route through
                //     the custom matcher in every case.
                $hasDotInNonUnicode = true;
            }
            if ($ch === '(') {
                $kind = 'capture';
                $consume = 1;
                if ($i + 2 < $len && $pattern[$i + 1] === '?') {
                    $next = $pattern[$i + 2];
                    if ($next === ':') {
                        $kind = 'noncapture';
                        $consume = 3;
                    } elseif ($next === '=' || $next === '!') {
                        $kind = 'lookahead';
                        $consume = 3;
                    } elseif ($next === '<' && $i + 3 < $len) {
                        $third = $pattern[$i + 3];
                        if ($third === '=' || $third === '!') {
                            $kind = 'lookbehind';
                            $consume = 4;
                        } else {
                            // Named capture (?<name>
                            $kind = 'capture';
                            $consume = 1;
                        }
                    } elseif ($next === 'i' || $next === 'm' || $next === 's' || $next === '-') {
                        // Inline modifier (?ims:...) / (?-ims:...) —
                        // PCRE2 handles these; our custom matcher
                        // does not honour the per-group flag overrides.
                        $hasInlineModifier = true;
                        $kind = 'noncapture';
                        $consume = 1;
                    }
                }
                if ($kind === 'capture') {
                    // Mark every enclosing scope as having seen a capture.
                    foreach ($stack as &$frame) {
                        $frame['sawCapture'] = true;
                    }
                    unset($frame);
                }
                if ($kind === 'lookbehind') {
                    $lookbehindDepth++;
                    $hasLookbehind = true;
                }
                $stack[] = ['kind' => $kind, 'sawCapture' => false];
                $i += $consume;
                continue;
            }
            if ($ch === ')') {
                $top = array_pop($stack);
                if ($top !== null && $top['kind'] === 'lookbehind') {
                    $lookbehindDepth--;
                }
                // Check if a quantifier follows.
                $j = $i + 1;
                $hasQuant = false;
                if ($j < $len) {
                    $q = $pattern[$j];
                    if ($q === '*' || $q === '+' || $q === '?' || $q === '{') {
                        $hasQuant = true;
                    }
                }
                if ($hasQuant && $top !== null && $top['sawCapture']) {
                    // (...){...} containing a capture — needs spec
                    // capture-reset semantics PCRE2 lacks.
                    $hasQuantifiedCapture = true;
                }
                if (
                    $top !== null
                    && $top['sawCapture']
                    && $lookbehindDepth > 0
                ) {
                    // We're still inside a lookbehind and just closed
                    // a group that had captures.
                    $hasLookbehindWithCapture = true;
                }
                if ($top !== null && $top['kind'] === 'lookbehind' && $top['sawCapture']) {
                    $hasLookbehindWithCapture = true;
                }
                $i++;
                continue;
            }
            $i++;
        }
        if ($hasInlineModifier) {
            // Inline-modifier patterns route through the custom
            // matcher (which honours per-group flag overrides via
            // ModifierGroup AST nodes).
            return true;
        }
        if ($hasUnicodePropertyEscape) {
            // Unicode property escapes use ICU's Unicode tables via
            // IntlChar in the custom matcher. PCRE2's built-in
            // tables can lag behind ICU (and the test262 generated
            // data) by several Unicode versions, producing
            // category mismatches on codepoints whose
            // Bidi_Mirrored / Script / Script_Extensions /
            // Alphabetic / ... state changed.
            // Exception: /v patterns. The custom matcher's
            // CharClass parser does not model set operations
            // ([A--B], [A&&B]), nested classes ([[A]B]), or
            // property-of-strings (\p{Emoji_Keycap_Sequence} and
            // friends, which match multi-codepoint sequences).
            // The transformVFlagPattern PCRE2 lowering already
            // handles all three; keep /v patterns on that path
            // until the custom matcher catches up.
            if (!str_contains($flags, 'v')) {
                return true;
            }
        }
        // Patterns with duplicate named groups also route through the
        // custom matcher so backreferences resolve the right capture
        // (PCRE2 with the J flag picks differently from spec when
        // multiple named groups share a name).
        if (self::hasDuplicateNamedGroups($pattern)) {
            return true;
        }
        // /i without /u must use ASCII-only Canonicalize per
        // ECMA-262 §22.2.2.7. PCRE2 always runs with PCRE2_UTF and
        // applies Unicode case-folding under /i, which only diverges
        // for codepoints whose simple-fold lands on an ASCII letter:
        // U+212A (KELVIN SIGN) → k and U+017F (LATIN SMALL LETTER
        // LONG S) → s. So the only patterns whose result actually
        // changes between PCRE2/iu and spec/i are those mentioning
        // K, k, S, or s (literally or via \uXXXX/\u{...}/\xHH/\cX
        // escapes). For every other /i-without-/u pattern the two
        // canonicalizations produce identical match sets, and we can
        // let PCRE2 handle them — keeping the custom matcher (which
        // catastrophically backtracks on lazy-quantifier shapes like
        // `(.*\n?)*?`) out of the path.
        $caselessNeedsCustom = false;
        if ($isCaseless && !$isUnicode) {
            $caselessNeedsCustom = self::patternMentionsCaseDivergentLetter($pattern);
        }
        // Literal codepoints appear in the pattern as UTF-8 bytes
        // when the source uses String.fromCodePoint (the SM
        // unicode-ignoreCase fixture builds RegExp(fromCodePoint(c) +
        // "+", "iu") for ~3000 fold pairs). The escape-aware scan
        // above only inspects \uXXXX / \u{X} forms; do a separate UTF-8
        // walk here when /iu is set, checking each non-ASCII codepoint
        // against the bundled fold table.
        if (!$hasUnicode16FoldCodepoint && $needUnicode16FoldScan) {
            $j = 0;
            $patLen = strlen($pattern);
            while ($j < $patLen) {
                $b = ord($pattern[$j]);
                if ($b < 0x80) {
                    $j++;
                    continue;
                }
                $cp = 0;
                $consume = 1;
                if (($b & 0xE0) === 0xC0 && $j + 1 < $patLen) {
                    $cp = (($b & 0x1F) << 6) | (ord($pattern[$j + 1]) & 0x3F);
                    $consume = 2;
                } elseif (($b & 0xF0) === 0xE0 && $j + 2 < $patLen) {
                    $cp = (($b & 0x0F) << 12)
                        | ((ord($pattern[$j + 1]) & 0x3F) << 6)
                        | (ord($pattern[$j + 2]) & 0x3F);
                    $consume = 3;
                } elseif (($b & 0xF8) === 0xF0 && $j + 3 < $patLen) {
                    $cp = (($b & 0x07) << 18)
                        | ((ord($pattern[$j + 1]) & 0x3F) << 12)
                        | ((ord($pattern[$j + 2]) & 0x3F) << 6)
                        | (ord($pattern[$j + 3]) & 0x3F);
                    $consume = 4;
                } else {
                    $j++;
                    continue;
                }
                if (\Phasis\Regex\FoldTable::participates($cp)) {
                    $hasUnicode16FoldCodepoint = true;
                    break;
                }
                $j += $consume;
            }
        }
        return $hasLookbehindWithCapture
            || $hasQuantifiedCapture
            || $hasDotInNonUnicode
            || $hasLookbehind
            || $hasNonAsciiInIWithoutU
            || $hasWordToken
            || $hasAstralInUnicode
            || $hasLoneSurrogateEscape
            || $hasUnicode16FoldCodepoint
            || $caselessNeedsCustom;
    }

    /**
     * Returns true iff the codepoint has a non-trivial simple case-fold
     * equivalent per Unicode 16's CaseFolding.txt (delegated to
     * Regex/FoldTable). Used to route /iu patterns containing
     * potentially fold-divergent codepoints to the custom matcher,
     * which canonicalises via the bundled fold table so behaviour is
     * host-independent. PCRE2's internal /i fold uses the host PCRE2
     * build's ICU; Ubuntu CI ships ICU 70/74 which miss Unicode 14/15/16
     * additions and produces "no match" where ICU 76+ correctly matches.
     */
    private static function isUnicode16FoldCodepoint(int $cp): bool
    {
        return \Phasis\Regex\FoldTable::participates($cp);
    }

    /**
     * Returns true iff $pattern contains an ASCII K/k/S/s as a
     * literal byte or via an escape (\uXXXX, \u{...}, \xHH, or \cK
     * for K). These are the only ASCII letters whose case-fold class
     * differs between PCRE2's /iu Unicode folding and ECMA-262's
     * ASCII-only Canonicalize for non-/u patterns: U+212A folds to k
     * and U+017F folds to s, so PCRE2 over-matches Kelvin / long-s
     * for those four letters but is spec-equivalent for every other
     * ASCII letter.
     */
    private static function patternMentionsCaseDivergentLetter(string $pattern): bool
    {
        $len = strlen($pattern);
        $i = 0;
        while ($i < $len) {
            $ch = $pattern[$i];
            if ($ch === 'K' || $ch === 'k' || $ch === 'S' || $ch === 's') {
                return true;
            }
            if ($ch === '\\' && $i + 1 < $len) {
                $next = $pattern[$i + 1];
                if ($next === 'u' && $i + 2 < $len && $pattern[$i + 2] === '{') {
                    $closeBrace = strpos($pattern, '}', $i + 3);
                    if ($closeBrace !== false) {
                        $hex = substr($pattern, $i + 3, $closeBrace - $i - 3);
                        if (ctype_xdigit($hex)) {
                            $cp = (int) hexdec($hex);
                            if ($cp === 0x4B || $cp === 0x6B || $cp === 0x53 || $cp === 0x73) {
                                return true;
                            }
                        }
                        $i = $closeBrace + 1;
                        continue;
                    }
                } elseif ($next === 'u' && $i + 5 < $len) {
                    $hex = substr($pattern, $i + 2, 4);
                    if (ctype_xdigit($hex)) {
                        $cp = (int) hexdec($hex);
                        if ($cp === 0x4B || $cp === 0x6B || $cp === 0x53 || $cp === 0x73) {
                            return true;
                        }
                    }
                    $i += 6;
                    continue;
                } elseif ($next === 'x' && $i + 3 < $len) {
                    $hex = substr($pattern, $i + 2, 2);
                    if (ctype_xdigit($hex)) {
                        $cp = (int) hexdec($hex);
                        if ($cp === 0x4B || $cp === 0x6B || $cp === 0x53 || $cp === 0x73) {
                            return true;
                        }
                    }
                    $i += 4;
                    continue;
                } elseif ($next === 'c' && $i + 2 < $len) {
                    // \cX control escape: \cK is U+000B (vertical
                    // tab) — not k. None of the control escapes
                    // produce K/k/S/s themselves, so skip.
                    $i += 3;
                    continue;
                }
                $i += 2;
                continue;
            }
            $i++;
        }
        return false;
    }

    /**
     * Extract every distinct named group from the pattern, in source
     * order. Used by exec() to pre-populate the `groups` object so
     * named groups that did not participate in the match still appear
     * with the value undefined (per spec 22.2.6.13.5 Group Specifier
     * Properties — and required by the duplicate-named-groups
     * proposal).
     *
     * @return list<string>
     */
    public static function extractNamedGroupNames(string $pattern): array
    {
        $names = [];
        $seen = [];
        $len = strlen($pattern);
        $i = 0;
        $inClass = false;
        while ($i < $len) {
            $ch = $pattern[$i];
            if ($ch === '\\') {
                $i += 2;
                continue;
            }
            if (!$inClass && $ch === '[') {
                $inClass = true;
                $i++;
                continue;
            }
            if ($inClass && $ch === ']') {
                $inClass = false;
                $i++;
                continue;
            }
            if ($inClass) {
                $i++;
                continue;
            }
            if (
                $ch === '('
                && $i + 3 < $len
                && $pattern[$i + 1] === '?'
                && $pattern[$i + 2] === '<'
                && $pattern[$i + 3] !== '='
                && $pattern[$i + 3] !== '!'
            ) {
                $nameStart = $i + 3;
                $nameEnd = $nameStart;
                while ($nameEnd < $len && $pattern[$nameEnd] !== '>') {
                    $nameEnd++;
                }
                if ($nameEnd < $len) {
                    $name = substr($pattern, $nameStart, $nameEnd - $nameStart);
                    if (!isset($seen[$name])) {
                        $names[] = $name;
                        $seen[$name] = true;
                    }
                }
                $i = $nameEnd + 1;
                continue;
            }
            $i++;
        }
        return $names;
    }
}
