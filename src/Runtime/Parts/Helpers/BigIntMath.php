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
 * Interpreter helper part: BigIntMath. Composed back into the
 * Interpreter via the InterpreterHelpers trait. `self::`/`$this->`
 * resolve into the composing class.
 */
trait BigIntMath
{
    /**
     * Parse a BigInt literal string (any base) to canonical decimal string.
     * Replaces gmp_init($value, 0) + gmp_strval() since GMP may not be installed.
     */
    private static function parseBigIntLiteral(string $value): string
    {
        $negative = '';
        if ($value !== '' && $value[0] === '-') {
            $negative = '-';
            $value = substr($value, 1);
        }
        if (strlen($value) > 2 && $value[0] === '0') {
            $prefix = $value[1];
            $digits = substr($value, 2);
            if ($prefix === 'x' || $prefix === 'X') {
                $decimal = self::baseStringToDecimal($digits, 16);
                return $negative . $decimal;
            }
            if ($prefix === 'b' || $prefix === 'B') {
                $decimal = self::baseStringToDecimal($digits, 2);
                return $negative . $decimal;
            }
            if ($prefix === 'o' || $prefix === 'O') {
                $decimal = self::baseStringToDecimal($digits, 8);
                return $negative . $decimal;
            }
        }
        // Decimal: strip leading zeros.
        $trimmed = ltrim($value, '0');
        return $negative . ($trimmed !== '' ? $trimmed : '0');
    }

    /**
     * Convert a string of digits in the given base (2, 8, or 16) to a decimal string.
     * Uses PHP native integers; for values exceeding PHP_INT_MAX uses string long multiplication.
     */
    private static function baseStringToDecimal(string $digits, int $base): string
    {
        // For small values, PHP native int is sufficient and fast.
        // For large values, we use a pure-PHP string big-integer multiply-add.
        $result = '0'; // Big-integer decimal string.
        foreach (str_split(strtolower($digits)) as $char) {
            $d = $char >= 'a' ? (ord($char) - ord('a') + 10) : (int) $char;
            // result = result * base + d  (pure-PHP string arithmetic)
            $result = self::bigStrAdd(self::bigStrMul($result, (string) $base), (string) $d);
        }
        return $result;
    }

    /** Pure-PHP string addition of two non-negative decimal integer strings. */
    private static function bigStrAdd(string $a, string $b): string
    {
        $result = '';
        $carry = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        while ($i >= 0 || $j >= 0 || $carry) {
            $sum = $carry;
            if ($i >= 0) {
                $sum += (int) $a[$i--];
            }
            if ($j >= 0) {
                $sum += (int) $b[$j--];
            }
            $carry = intdiv($sum, 10);
            $result = ($sum % 10) . $result;
        }
        return $result !== '' ? $result : '0';
    }

    /** Pure-PHP string multiplication of a non-negative decimal integer string by a small int. */
    private static function bigStrMul(string $a, string $b): string
    {
        if ($a === '0' || $b === '0') {
            return '0';
        }
        // Schoolbook multiplication for single-digit multiplier (base 2/8/16).
        $bInt = (int) $b;
        $result = '';
        $carry = 0;
        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            $prod = (int) $a[$i] * $bInt + $carry;
            $carry = intdiv($prod, 10);
            $result = ($prod % 10) . $result;
        }
        while ($carry > 0) {
            $result = ($carry % 10) . $result;
            $carry = intdiv($carry, 10);
        }
        return $result !== '' ? $result : '0';
    }

    /** Compare two non-negative decimal integer strings. Returns -1, 0, 1. */
    private static function bigStrCompUnsigned(string $a, string $b): int
    {
        $la = strlen($a);
        $lb = strlen($b);
        if ($la !== $lb) {
            return $la < $lb ? -1 : 1;
        }
        return strcmp($a, $b) <=> 0;
    }

    /** Signed comparison of two decimal integer strings. Returns -1, 0, 1. */
    private static function bigStrComp(string $a, string $b): int
    {
        $aNeg = isset($a[0]) && $a[0] === '-';
        $bNeg = isset($b[0]) && $b[0] === '-';
        if ($aNeg !== $bNeg) {
            return $aNeg ? -1 : 1;
        }
        $cmp = self::bigStrCompUnsigned(ltrim($a, '-'), ltrim($b, '-'));
        return $aNeg ? -$cmp : $cmp;
    }

    /** Signed addition of two decimal integer strings. */
    private static function bigStrAddSigned(string $a, string $b): string
    {
        // Fast path: both fit in native PHP int.
        if (abs((float) $a) < 9.2e18 && abs((float) $b) < 9.2e18 && strlen($a) <= 18 && strlen($b) <= 18) {
            $ia = (int) $a;
            $ib = (int) $b;
            if ((string) $ia === ltrim($a, '+') && (string) $ib === ltrim($b, '+')) {
                return (string) ($ia + $ib);
            }
        }
        $aNeg = isset($a[0]) && $a[0] === '-';
        $bNeg = isset($b[0]) && $b[0] === '-';
        $absA = ltrim($a, '-');
        $absB = ltrim($b, '-');
        if ($aNeg === $bNeg) {
            $sum = self::bigStrAdd($absA, $absB);
            return $aNeg ? ('-' . $sum) : $sum;
        }
        $cmp = self::bigStrCompUnsigned($absA, $absB);
        if ($cmp === 0) {
            return '0';
        }
        if ($cmp > 0) {
            // |a| > |b|, result sign = sign of a
            $diff = self::bigStrSubUnsigned($absA, $absB);
            return ($aNeg && $diff !== '0') ? ('-' . $diff) : $diff;
        }
        // |a| < |b|, result sign = sign of b
        $diff = self::bigStrSubUnsigned($absB, $absA);
        return ($bNeg && $diff !== '0') ? ('-' . $diff) : $diff;
    }

    /** Subtract b from a where a >= b (both non-negative). */
    private static function bigStrSubUnsigned(string $a, string $b): string
    {
        $result = '';
        $borrow = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        while ($i >= 0) {
            $diff = (int) $a[$i--] - ($j >= 0 ? (int) $b[$j--] : 0) - $borrow;
            if ($diff < 0) {
                $diff += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = $diff . $result;
        }
        return ltrim($result, '0') ?: '0';
    }

    /**
     * Full unsigned long division. Returns [quotient, remainder] as decimal strings.
     * Uses digit-by-digit algorithm: at each step remainder < b, so q is 0-9.
     *
     * @return array{0: string, 1: string}
     */
    private static function bigStrDivModFull(string $a, string $b): array
    {
        if ($b === '0') {
            throw new \Phasis\Exceptions\RangeError('Division by zero');
        }
        if (self::bigStrCompUnsigned($a, $b) < 0) {
            return ['0', $a];
        }
        if ($b === '1') {
            return [$a, '0'];
        }

        // Fast path: b fits in native PHP int.
        $bInt = (int) $b;
        if ((string) $bInt === $b && $bInt > 0) {
            $q = '';
            $rem = 0;
            for ($i = 0; $i < strlen($a); $i++) {
                $cur = $rem * 10 + (int) $a[$i];
                $q .= (string) intdiv($cur, $bInt);
                $rem = $cur % $bInt;
            }
            return [ltrim($q, '0') ?: '0', (string) $rem];
        }

        // General long division: digit-by-digit.
        // Invariant: $rem < $b at all times.
        $quotient = '';
        $rem = '0';
        for ($i = 0; $i < strlen($a); $i++) {
            // current = rem * 10 + digit
            $rem = self::bigStrAdd(self::bigStrMul($rem, '10'), (string) (int) $a[$i]);
            // Find q in 0..9 such that q*b <= rem < (q+1)*b
            $q = 0;
            for ($try = 9; $try >= 1; $try--) {
                $prod = self::bigStrMul($b, (string) $try);
                if (self::bigStrCompUnsigned($prod, $rem) <= 0) {
                    $q = $try;
                    break;
                }
            }
            $quotient .= (string) $q;
            if ($q > 0) {
                $rem = self::bigStrSubUnsigned($rem, self::bigStrMul($b, (string) $q));
            }
        }
        return [ltrim($quotient, '0') ?: '0', $rem];
    }

    /** Signed BigInt add (replaces bcadd). */
    private static function bigStrBcAdd(string $a, string $b): string
    {
        return self::bigStrAddSigned($a, $b);
    }

    /**
     * BigInt::bitwiseNOT per §6.1.6.2.2: return -x - 1 as an arbitrary-
     * precision decimal string. Accepts hex/octal/binary BigInt literal
     * forms that may arrive from JsBigInt::value (e.g. "0xff").
     */
    private static function bigIntBitwiseNot(string $value): string
    {
        $value = self::bigIntLiteralToDecimal($value);
        // -x - 1 = -(x + 1)
        $xPlus1 = self::bigStrAddSigned($value, '1');
        if ($xPlus1 === '0') {
            return '0';
        }
        return $xPlus1[0] === '-' ? substr($xPlus1, 1) : '-' . $xPlus1;
    }

    /**
     * Public wrapper so the bytecode VM's BNOT opcode can compute the
     * BigInt path without duplicating the decimal-string arithmetic. The
     * tree-walker's UnaryExpression handler calls the private form via
     * self:: but the VM lives outside the class.
     */
    public static function bigIntBitwiseNotPublic(string $value): string
    {
        return self::bigIntBitwiseNot($value);
    }

    /** Convert a JsBigInt value string (possibly hex/oct/bin-prefixed) to decimal. */
    private static function bigIntLiteralToDecimal(string $value): string
    {
        $negative = false;
        $v = $value;
        if ($v !== '' && $v[0] === '-') {
            $negative = true;
            $v = substr($v, 1);
        }
        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/', $v, $m) === 1) {
            $dec = self::baseStringToDecimal($m[1], 16);
        } elseif (preg_match('/^0[oO]([0-7]+)$/', $v, $m) === 1) {
            $dec = self::baseStringToDecimal($m[1], 8);
        } elseif (preg_match('/^0[bB]([01]+)$/', $v, $m) === 1) {
            $dec = self::baseStringToDecimal($m[1], 2);
        } else {
            $dec = $v;
        }
        return $negative ? '-' . $dec : $dec;
    }

    /** Signed BigInt subtract (replaces bcsub). */
    private static function bigStrBcSub(string $a, string $b): string
    {
        // a - b = a + (-b)
        if ($b !== '0' && $b !== '') {
            $bNeg = $b[0] === '-';
            $negB = $bNeg ? substr($b, 1) : ('-' . $b);
        } else {
            $negB = '0';
        }
        return self::bigStrAddSigned($a, $negB);
    }

    /** Signed BigInt multiply (replaces bcmul). */
    private static function bigStrBcMul(string $a, string $b): string
    {
        $aNeg = $a !== '0' && isset($a[0]) && $a[0] === '-';
        $bNeg = $b !== '0' && isset($b[0]) && $b[0] === '-';
        $absA = ltrim($a, '-');
        $absB = ltrim($b, '-');

        // Use full schoolbook multiplication for both operands.
        // bigStrMul only handles small second operand; bigStrMulUnsigned handles full.
        if (strlen($absB) === 1) {
            $prod = self::bigStrMul($absA, $absB);
        } else {
            // Schoolbook.
            $m = strlen($absA);
            $n = strlen($absB);
            $result = array_fill(0, $m + $n, 0);
            for ($i = $m - 1; $i >= 0; $i--) {
                for ($j = $n - 1; $j >= 0; $j--) {
                    $mul = (int) $absA[$i] * (int) $absB[$j];
                    $p1 = $i + $j;
                    $p2 = $i + $j + 1;
                    $sum = $mul + $result[$p2];
                    $result[$p2] = $sum % 10;
                    $result[$p1] += intdiv($sum, 10);
                }
            }
            $prod = ltrim(implode('', $result), '0') ?: '0';
        }
        if ($prod === '0') {
            return '0';
        }
        return ($aNeg xor $bNeg) ? ('-' . $prod) : $prod;
    }

    /**
     * Signed BigInt divide, truncating toward zero (replaces bcdiv($a, $b, 0)).
     * Returns quotient as decimal string.
     */
    private static function bigStrBcDiv(string $a, string $b): string
    {
        if ($b === '0') {
            throw new \Phasis\Exceptions\RangeError('Division by zero');
        }
        $aNeg = isset($a[0]) && $a[0] === '-';
        $bNeg = isset($b[0]) && $b[0] === '-';
        $absA = ltrim($a, '-');
        $absB = ltrim($b, '-');
        [$q,] = self::bigStrDivModFull($absA, $absB);
        if ($q === '0') {
            return '0';
        }
        return ($aNeg xor $bNeg) ? ('-' . $q) : $q;
    }

    /**
     * Signed BigInt remainder (replaces bcmod).
     * Result has same sign as dividend.
     */
    private static function bigStrBcMod(string $a, string $b): string
    {
        if ($b === '0') {
            throw new \Phasis\Exceptions\RangeError('Division by zero');
        }
        $aNeg = isset($a[0]) && $a[0] === '-';
        $absA = ltrim($a, '-');
        $absB = ltrim($b, '-');
        [,$r] = self::bigStrDivModFull($absA, $absB);
        if ($r === '0') {
            return '0';
        }
        return $aNeg ? ('-' . $r) : $r;
    }

    /**
     * BigInt exponentiation (replaces bcpow($a, $b, 0)).
     * $b must be non-negative.
     */
    private static function bigStrBcPow(string $base, string $exp): string
    {
        if ($exp === '0') {
            return '1';
        }
        if ($exp === '1') {
            return $base;
        }
        if ($base === '0') {
            return '0';
        }
        if ($base === '1') {
            return '1';
        }
        // Fast path: exp fits in native int.
        $expInt = (int) $exp;
        if ((string) $expInt === $exp) {
            // Exponentiation by squaring.
            $result = '1';
            $b = $base;
            $e = $expInt;
            while ($e > 0) {
                if ($e % 2 === 1) {
                    $result = self::bigStrBcMul($result, $b);
                }
                $b = self::bigStrBcMul($b, $b);
                $e = intdiv($e, 2);
            }
            return $result;
        }
        // Very large exponent: impractical, throw range error.
        throw new \Phasis\Exceptions\RangeError('BigInt exponent too large');
    }

    /**
     * BigInt bitwise AND/OR/XOR without GMP or bcmath.
     * Uses native PHP int for values that fit, binary-string two's-complement for large values.
     */
    /** @param '&'|'|'|'^' $op */
    private function bigintBitwiseOp(JsBigInt $left, JsBigInt $right, string $op): JsBigInt
    {
        // Fast path: both values fit in a native PHP int.
        if ($this->bigStrFitsInt($left->value) && $this->bigStrFitsInt($right->value)) {
            $l = (int) $left->value;
            $r = (int) $right->value;
            $result = match ($op) {
                '&' => $l & $r,
                '|' => $l | $r,
                '^' => $l ^ $r,
            };
            return new JsBigInt((string) $result);
        }

        // Large values: two's-complement binary string manipulation.
        // Width: max bit-length + 1 guard bit for sign extension.
        $lBin = $this->bigintToTwosCompBin($left->value);
        $rBin = $this->bigintToTwosCompBin($right->value);
        $len = max(strlen($lBin), strlen($rBin)) + 1;
        $lSign = $left->value[0] === '-' ? '1' : '0';
        $rSign = $right->value[0] === '-' ? '1' : '0';
        $lBin = str_pad($lBin, $len, $lSign, STR_PAD_LEFT);
        $rBin = str_pad($rBin, $len, $rSign, STR_PAD_LEFT);

        $resultBin = '';
        for ($i = 0; $i < $len; $i++) {
            $lb = (int) $lBin[$i];
            $rb = (int) $rBin[$i];
            $resultBin .= (string) match ($op) {
                '&' => $lb & $rb,
                '|' => $lb | $rb,
                '^' => $lb ^ $rb,
            };
        }
        return new JsBigInt($this->twosCompBinToDecimal($resultBin));
    }

    /** Check whether a BigInt decimal string fits in PHP's native int. */
    private function bigStrFitsInt(string $value): bool
    {
        $abs = ltrim($value, '-');
        $max = (string) PHP_INT_MAX;
        if (strlen($abs) < strlen($max)) {
            return true;
        }
        if (strlen($abs) > strlen($max)) {
            return false;
        }
        // Same digit length: compare lexicographically.
        return $abs <= $max;
    }

    /** Convert a decimal BigInt string to a two's-complement binary string. Pure PHP. */
    private function bigintToTwosCompBin(string $value): string
    {
        $negative = $value[0] === '-';
        $abs = $negative ? substr($value, 1) : $value;
        if ($abs === '' || $abs === '0') {
            return '0';
        }
        // Convert |value| to binary via repeated halving.
        $bin = '';
        $v = $abs;
        while ($v !== '0') {
            [$q, $r] = $this->bigStrDivMod($v, '2');
            $bin = $r . $bin;
            $v = $q;
        }
        if (!$negative) {
            return $bin;
        }
        // Negative: two's complement = NOT(|value| - 1).
        // Subtract 1 from $abs, then invert bits.
        $abs1 = $this->bigStrSub($abs, '1');
        if ($abs1 === '0') {
            // -1 in two's complement is all 1s; single '1' is fine (sign-extended).
            return '1';
        }
        $bin2 = '';
        $v = $abs1;
        while ($v !== '0') {
            [$q, $r] = $this->bigStrDivMod($v, '2');
            $bin2 = $r . $bin2;
            $v = $q;
        }
        return strtr($bin2, ['0' => '1', '1' => '0']);
    }

    /** Convert a two's-complement binary string back to a decimal BigInt string. Pure PHP. */
    private function twosCompBinToDecimal(string $bin): string
    {
        if ($bin === '' || $bin[0] === '0') {
            // Positive or zero.
            $result = '0';
            foreach (str_split($bin) as $bit) {
                $result = self::bigStrAdd(self::bigStrMul($result, '2'), $bit);
            }
            return $result;
        }
        // Negative (sign bit = 1): invert bits and add 1 to get magnitude.
        $inverted = strtr($bin, ['0' => '1', '1' => '0']);
        $mag = '0';
        foreach (str_split($inverted) as $bit) {
            $mag = self::bigStrAdd(self::bigStrMul($mag, '2'), $bit);
        }
        return '-' . self::bigStrAdd($mag, '1');
    }

    /**
     * Divide two non-negative decimal integer strings.
     * Returns [$quotient, $remainder] as strings.
     *
     * @return array{0: string, 1: string}
     */
    private function bigStrDivMod(string $a, string $divisor): array
    {
        $d = (int) $divisor;
        $q = '';
        $rem = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $cur = $rem * 10 + (int) $a[$i];
            $q .= (string) intdiv($cur, $d);
            $rem = $cur % $d;
        }
        $q = ltrim($q, '0') ?: '0';
        return [$q, (string) $rem];
    }

    /** Pure-PHP string subtraction of non-negative decimal integer strings (a >= b). */
    private function bigStrSub(string $a, string $b): string
    {
        $result = '';
        $borrow = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        while ($i >= 0) {
            $diff = (int) $a[$i--] - ($j >= 0 ? (int) $b[$j--] : 0) - $borrow;
            if ($diff < 0) {
                $diff += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = $diff . $result;
        }
        return ltrim($result, '0') ?: '0';
    }
}
