<?php

declare(strict_types=1);

namespace Phasis\Parser;

/**
 * The regular-expression syntax checks the parser needs for early errors.
 *
 * These are pure functions over the pattern text and Unicode property
 * tables, so parsing a regular expression literal needs nothing from the
 * engine. The runtime's RegExp helpers delegate here, keeping one copy.
 */
final class RegExpSyntax
{
    public static function mapBinaryProperty(string $name): ?string
    {
        if (self::isVStringBinaryProperty($name)) {
            // We don't have native data for v-flag string-binary
            // properties. Substitute a never-matching tag so the regex
            // compiles cleanly without polluting the match set.
            return '!StringProperty';
        }
        static $supported = [
            'ASCII' => 'ASCII', 'ASCII_Hex_Digit' => 'ASCII_Hex_Digit',
            'AHex' => 'ASCII_Hex_Digit', 'Alphabetic' => 'Alphabetic',
            'Alpha' => 'Alphabetic', 'Any' => 'Any',
            'Bidi_Control' => 'Bidi_Control', 'Bidi_C' => 'Bidi_Control',
            'Bidi_Mirrored' => 'Bidi_Mirrored', 'Bidi_M' => 'Bidi_Mirrored',
            'Case_Ignorable' => 'Case_Ignorable', 'CI' => 'Case_Ignorable',
            'Cased' => 'Cased',
            'Changes_When_Casefolded' => 'Changes_When_Casefolded',
            'CWCF' => 'Changes_When_Casefolded',
            'Changes_When_Casemapped' => 'Changes_When_Casemapped',
            'CWCM' => 'Changes_When_Casemapped',
            'Changes_When_Lowercased' => 'Changes_When_Lowercased',
            'CWL' => 'Changes_When_Lowercased',
            'Changes_When_NFKC_Casefolded' => 'Changes_When_NFKC_Casefolded',
            'CWKCF' => 'Changes_When_NFKC_Casefolded',
            'Changes_When_Titlecased' => 'Changes_When_Titlecased',
            'CWT' => 'Changes_When_Titlecased',
            'Changes_When_Uppercased' => 'Changes_When_Uppercased',
            'CWU' => 'Changes_When_Uppercased',
            'Dash' => 'Dash',
            'Default_Ignorable_Code_Point' => 'Default_Ignorable_Code_Point',
            'DI' => 'Default_Ignorable_Code_Point',
            'Deprecated' => 'Deprecated', 'Dep' => 'Deprecated',
            'Diacritic' => 'Diacritic', 'Dia' => 'Diacritic',
            'Emoji' => 'Emoji', 'Emoji_Component' => 'Emoji_Component',
            'EComp' => 'Emoji_Component',
            'Emoji_Modifier' => 'Emoji_Modifier', 'EMod' => 'Emoji_Modifier',
            'Emoji_Modifier_Base' => 'Emoji_Modifier_Base',
            'EBase' => 'Emoji_Modifier_Base',
            'Emoji_Presentation' => 'Emoji_Presentation',
            'EPres' => 'Emoji_Presentation',
            'Extended_Pictographic' => 'Extended_Pictographic',
            'ExtPict' => 'Extended_Pictographic',
            'Extender' => 'Extender', 'Ext' => 'Extender',
            'Grapheme_Base' => 'Grapheme_Base', 'Gr_Base' => 'Grapheme_Base',
            'Grapheme_Extend' => 'Grapheme_Extend', 'Gr_Ext' => 'Grapheme_Extend',
            'Hex_Digit' => 'Hex_Digit', 'Hex' => 'Hex_Digit',
            'IDS_Binary_Operator' => 'IDS_Binary_Operator',
            'IDSB' => 'IDS_Binary_Operator',
            'IDS_Trinary_Operator' => 'IDS_Trinary_Operator',
            'IDST' => 'IDS_Trinary_Operator',
            'ID_Continue' => 'ID_Continue', 'IDC' => 'ID_Continue',
            'ID_Start' => 'ID_Start', 'IDS' => 'ID_Start',
            'Ideographic' => 'Ideographic', 'Ideo' => 'Ideographic',
            'Join_Control' => 'Join_Control', 'Join_C' => 'Join_Control',
            'Logical_Order_Exception' => 'Logical_Order_Exception',
            'LOE' => 'Logical_Order_Exception',
            'Lowercase' => 'Lowercase', 'Lower' => 'Lowercase',
            'Math' => 'Math',
            'Noncharacter_Code_Point' => 'Noncharacter_Code_Point',
            'NChar' => 'Noncharacter_Code_Point',
            'Pattern_Syntax' => 'Pattern_Syntax', 'Pat_Syn' => 'Pattern_Syntax',
            'Pattern_White_Space' => 'Pattern_White_Space',
            'Pat_WS' => 'Pattern_White_Space',
            'Quotation_Mark' => 'Quotation_Mark', 'QMark' => 'Quotation_Mark',
            'Radical' => 'Radical',
            'Regional_Indicator' => 'Regional_Indicator', 'RI' => 'Regional_Indicator',
            'Sentence_Terminal' => 'Sentence_Terminal', 'STerm' => 'Sentence_Terminal',
            'Soft_Dotted' => 'Soft_Dotted', 'SD' => 'Soft_Dotted',
            'Terminal_Punctuation' => 'Terminal_Punctuation',
            'Term' => 'Terminal_Punctuation',
            'Unified_Ideograph' => 'Unified_Ideograph', 'UIdeo' => 'Unified_Ideograph',
            'Uppercase' => 'Uppercase', 'Upper' => 'Uppercase',
            'Variation_Selector' => 'Variation_Selector', 'VS' => 'Variation_Selector',
            'White_Space' => 'White_Space', 'space' => 'White_Space',
            'WSpace' => 'White_Space',
            'XID_Continue' => 'XID_Continue', 'XIDC' => 'XID_Continue',
            'XID_Start' => 'XID_Start', 'XIDS' => 'XID_Start',
            'Assigned' => '!Assigned',
        ];
        $pcre = $supported[$name] ?? null;
        if ($pcre === null) {
            return null;
        }
        if ($pcre === '!Assigned') {
            return '!Assigned';
        }
        if (@preg_match('/\\p{' . $pcre . '}/u', '') === false) {
            return 'Any';
        }
        return $pcre;
    }

    /**
     * v-flag binary properties of strings (ES2024) that aren't backed by a
     * PCRE binary property. Accepted at parse-time so the regex compiles;
     * runtime matching against these falls through and matches nothing.
     */
    public static function isVStringBinaryProperty(string $name): bool
    {
        static $names = [
            'Basic_Emoji' => true,
            'Emoji_Keycap_Sequence' => true,
            'RGI_Emoji' => true,
            'RGI_Emoji_Flag_Sequence' => true,
            'RGI_Emoji_Modifier_Sequence' => true,
            'RGI_Emoji_Tag_Sequence' => true,
            'RGI_Emoji_ZWJ_Sequence' => true,
        ];
        return isset($names[$name]);
    }

    public static function mapGeneralCategoryValue(string $value): ?string
    {
        static $map = [
            'Letter' => 'L', 'Cased_Letter' => 'L&',
            'Uppercase_Letter' => 'Lu', 'Lowercase_Letter' => 'Ll',
            'Titlecase_Letter' => 'Lt', 'Modifier_Letter' => 'Lm',
            'Other_Letter' => 'Lo',
            'Mark' => 'M', 'Nonspacing_Mark' => 'Mn',
            'Spacing_Mark' => 'Mc', 'Enclosing_Mark' => 'Me',
            'Number' => 'N', 'Decimal_Number' => 'Nd',
            'Letter_Number' => 'Nl', 'Other_Number' => 'No',
            'Punctuation' => 'P', 'Connector_Punctuation' => 'Pc',
            'Dash_Punctuation' => 'Pd', 'Open_Punctuation' => 'Ps',
            'Close_Punctuation' => 'Pe', 'Initial_Punctuation' => 'Pi',
            'Final_Punctuation' => 'Pf', 'Other_Punctuation' => 'Po',
            'Symbol' => 'S', 'Math_Symbol' => 'Sm',
            'Currency_Symbol' => 'Sc', 'Modifier_Symbol' => 'Sk',
            'Other_Symbol' => 'So',
            'Separator' => 'Z', 'Space_Separator' => 'Zs',
            'Line_Separator' => 'Zl', 'Paragraph_Separator' => 'Zp',
            'Other' => 'C', 'Control' => 'Cc', 'Format' => 'Cf',
            'Surrogate' => 'Cs', 'Private_Use' => 'Co', 'Unassigned' => 'Cn',
            'L' => 'L', 'L&' => 'L&', 'LC' => 'L&',
            'Lu' => 'Lu', 'Ll' => 'Ll', 'Lt' => 'Lt',
            'Lm' => 'Lm', 'Lo' => 'Lo',
            'M' => 'M', 'Mn' => 'Mn', 'Mc' => 'Mc', 'Me' => 'Me',
            'N' => 'N', 'Nd' => 'Nd', 'Nl' => 'Nl', 'No' => 'No',
            'P' => 'P', 'Pc' => 'Pc', 'Pd' => 'Pd', 'Ps' => 'Ps',
            'Pe' => 'Pe', 'Pi' => 'Pi', 'Pf' => 'Pf', 'Po' => 'Po',
            'S' => 'S', 'Sm' => 'Sm', 'Sc' => 'Sc', 'Sk' => 'Sk', 'So' => 'So',
            'Z' => 'Z', 'Zs' => 'Zs', 'Zl' => 'Zl', 'Zp' => 'Zp',
            'C' => 'C', 'Cc' => 'Cc', 'Cf' => 'Cf', 'Cs' => 'Cs',
            'Co' => 'Co', 'Cn' => 'Cn',
            'cntrl' => 'Cc', 'digit' => 'Nd', 'punct' => 'P',
            // Unicode "loose" alias for Mark / M (PropertyValueAliases.txt
            // lists Combining_Mark as a synonym for the Mark general
            // category). Required by the test262 generated suite.
            'Combining_Mark' => 'M',
        ];
        return $map[$value] ?? null;
    }

    public static function normalizeEsPropertyName(string $name): ?string
    {
        static $aliases = [
            'General_Category' => 'General_Category', 'gc' => 'General_Category',
            'Script' => 'Script', 'sc' => 'Script',
            'Script_Extensions' => 'Script_Extensions', 'scx' => 'Script_Extensions',
        ];
        return $aliases[$name] ?? null;
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

    /** Whether $name is a binary Unicode property the engine recognises. */
    public static function isBinaryUnicodePropertyName(string $name): bool
    {
        return self::mapBinaryProperty($name) !== null;
    }

    /**
     * Whether the lone-form `\p{X}` is known: either a General_Category
     * value (e.g. Letter, Lu) or a binary property (e.g. ASCII, Emoji).
     */
    public static function isLoneUnicodePropertyKnown(string $name): bool
    {
        return self::mapGeneralCategoryValue($name) !== null
            || self::mapBinaryProperty($name) !== null;
    }

    /**
     * Whether $name is a known non-binary Unicode property name (the left
     * side of `\p{Name=Value}`): General_Category, Script,
     * Script_Extensions and their aliases.
     */
    public static function isNonBinaryUnicodePropertyName(string $name): bool
    {
        return self::normalizeEsPropertyName($name) !== null;
    }

    /** Whether $value is an exact, case-sensitive General_Category value. */
    public static function isGeneralCategoryValue(string $value): bool
    {
        return self::mapGeneralCategoryValue($value) !== null;
    }

    private function __construct()
    {
    }
}
