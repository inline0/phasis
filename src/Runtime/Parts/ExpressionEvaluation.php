<?php

declare(strict_types=1);

namespace Phasis\Runtime\Parts;

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
use Phasis\Runtime\Completion;
use Phasis\Runtime\CompletionType;
use Phasis\Runtime\Reference;
use Phasis\Runtime\TailCallThunk;

/**
 * Interpreter part: ExpressionEvaluation. Composed into Interpreter via
 * `use Parts\ExpressionEvaluation;`. `self::`/`$this->` references resolve
 * into the composing class.
 */
trait ExpressionEvaluation
{
    // -------------------------------------------------------------------------
    // Expression evaluation
    // -------------------------------------------------------------------------

    public function evaluate(Node $node, Environment $env): JsValue
    {
        // Fast path for the two hottest node types. Identifier reads
        // can legitimately surface a JsOptionalUndefined that an
        // earlier nested optional chain stored into the binding
        // (`const r = a?.b?.c`); unwrap it on the way out so non-
        // chain consumers see plain JsUndefined, matching the post-
        // dispatch unwrap that the slow path applies for non-chain
        // node types. Literals never produce the sentinel.
        if ($node instanceof Identifier) {
            $name = $node->name;
            if ($name === 'undefined') {
                return JsUndefined::instance();
            }
            // Identifier scope-depth cache: if we have already
            // resolved this Identifier in a structurally identical
            // env chain, jump directly to the owning env. Safe only
            // when no with-env is reachable (with intercepts dynamically)
            // and the program is provably free of direct eval (which
            // could reshuffle which env owns a name mid-execution).
            if (
                $node->resolvedDepth !== null
                && $this->programIsEvalFree
                && !$env->hasAnyWithObjectInChain()
            ) {
                $cached = $env->getAtDepth($name, $node->resolvedDepth);
                if ($cached !== null) {
                    return $cached instanceof JsOptionalUndefined
                        ? JsUndefined::instance()
                        : $cached;
                }
                // Fall through: the cached depth no longer points to
                // this binding (e.g. a let-shadowed inner block).
                // Refresh below.
            }
            // First or fallback resolution.
            if (
                $this->programIsEvalFree
                && !$env->hasAnyWithObjectInChain()
            ) {
                $found = $env->findBindingDepth($name);
                if ($found !== null) {
                    $node->resolvedDepth = $found[1];
                    return $found[0] instanceof JsOptionalUndefined
                        ? JsUndefined::instance()
                        : $found[0];
                }
            }
            $value = $env->get($name, $this->strictMode);
            return $value instanceof JsOptionalUndefined
                ? JsUndefined::instance()
                : $value;
        }
        if ($node instanceof Literal) {
            return $node->cached ?? $this->evalLiteral($node);
        }

        // Match-true dispatch is ordered by hot-path frequency on real
        // workloads. Binary ops dominate the remaining traffic in tight
        // loops; member/call follow.
        $result = match (true) {
            $node instanceof BinaryExpression => $this->evalBinaryExpression($node, $env),
            $node instanceof MemberExpression => $this->evalMemberExpression($node, $env),
            $node instanceof CallExpression => $this->evalCallExpression($node, $env),
            $node instanceof AssignmentExpression => $this->evalAssignment($node, $env),
            $node instanceof UpdateExpression => $this->evalUpdateExpression($node, $env),
            $node instanceof UnaryExpression => $this->evalUnaryExpression($node, $env),
            $node instanceof LogicalExpression => $this->evalLogicalExpression($node, $env),
            $node instanceof ConditionalExpression => $this->evalConditional($node, $env),
            $node instanceof ObjectExpression => $this->evalObjectExpression($node, $env),
            $node instanceof ArrayExpression => $this->evalArrayExpression($node, $env),
            $node instanceof TemplateLiteral => $this->evalTemplateLiteral($node, $env),
            $node instanceof ArrowFunction => $this->evalArrowFunction($node, $env),
            $node instanceof FunctionExpression => $this->evalFunctionExpression($node, $env),
            $node instanceof NewExpression => $this->evalNewExpression($node, $env),
            $node instanceof ThisExpression => $this->evalThisExpression($env),
            $node instanceof SequenceExpression => $this->evalSequence($node, $env),
            $node instanceof ClassExpression => $this->evalClassExpression($node, $env),
            $node instanceof TaggedTemplate => $this->evalTaggedTemplate($node, $env),
            $node instanceof SpreadElement => $this->evaluate($node->argument, $env),
            $node instanceof YieldExpression => $this->evalYieldExpression($node, $env),
            $node instanceof AwaitExpression => $this->evalAwaitExpression($node, $env),
            $node instanceof ImportExpression => $this->evalImportExpression($node, $env),
            $node instanceof MetaProperty => $this->evalMetaProperty($node, $env),
            default => throw new InternalError('Unknown expression type: ' . $node->type()),
        };

        // Unwrap optional chain sentinel for non-chain consumers.
        // MemberExpression and CallExpression handle JsOptionalUndefined internally.
        if (
            $result instanceof JsOptionalUndefined
            && !($node instanceof MemberExpression)
            && !($node instanceof CallExpression)
        ) {
            return JsUndefined::instance();
        }

        return $result;
    }

    private function evalLiteral(Literal $node): JsValue
    {
        // Per-node memoisation: a Literal in a hot loop is otherwise
        // re-converted into a JsValue on every visit. RegExp literals are
        // intentionally NOT cached — each evaluation must produce a fresh
        // RegExp object with a reset lastIndex (per spec 22.2.4.1).
        if ($node->cached !== null) {
            return $node->cached;
        }
        $value = $node->value;
        if ($value === null) {
            return $node->cached = JsNull::instance();
        }
        if (is_bool($value)) {
            return $node->cached = new JsBoolean($value);
        }
        if (is_int($value) || is_float($value)) {
            return $node->cached = JsNumber::of((float) $value);
        }
        if (is_string($value)) {
            if (str_starts_with($node->raw, '__BIGINT__')) {
                return $node->cached = new JsBigInt(self::parseBigIntLiteral(rtrim($value, 'n')));
            }
            // RegExp literal: bypass cache so each visit yields a fresh
            // RegExp object with lastIndex == 0.
            if (
                str_starts_with($node->raw, '__REGEXP__')
                && preg_match('#^/(.+)/([dgimsuvy]*)$#s', $value, $m)
            ) {
                return $this->createRegExpObject($m[1], $m[2]);
            }
            return $node->cached = new JsString($value);
        }
        return $node->cached = JsUndefined::instance();
    }

    private function evalBinaryExpression(BinaryExpression $node, Environment $env): JsValue
    {
        // Private field brand check: #name in obj
        if ($node->operator === 'in' && $node->left instanceof PrivateIdentifier) {
            $right = $this->evaluate($node->right, $env);
            if (!($right instanceof JsObject)) {
                throw new TypeError('Cannot use \'in\' operator to search for a private field without an object');
            }
            $brandedName = $env->resolvePrivateName($node->left->name);
            return new JsBoolean($right->hasPrivateField($brandedName));
        }

        $left = $this->evaluate($node->left, $env);
        $right = $this->evaluate($node->right, $env);

        // Fast path: both operands are already plain numbers and the
        // operator is a simple arith / compare. Avoids the
        // toNumeric/toPrimitive dance and the BigInt fork that dominates
        // tight loops like `i * 2 - 1` or `i < n`.
        if ($left instanceof JsNumber && $right instanceof JsNumber) {
            $l = $left->value;
            $r = $right->value;
            switch ($node->operator) {
                case '+':
                    return JsNumber::of($l + $r);
                case '-':
                    return JsNumber::of($l - $r);
                case '*':
                    return JsNumber::of($l * $r);
                case '<':
                    return JsBoolean::of(!is_nan($l) && !is_nan($r) && $l < $r);
                case '>':
                    return JsBoolean::of(!is_nan($l) && !is_nan($r) && $l > $r);
                case '<=':
                    return JsBoolean::of(!is_nan($l) && !is_nan($r) && $l <= $r);
                case '>=':
                    return JsBoolean::of(!is_nan($l) && !is_nan($r) && $l >= $r);
                case '===':
                    return JsBoolean::of($l === $r);
                case '!==':
                    return JsBoolean::of($l !== $r);
                case '==':
                    return JsBoolean::of($l == $r && !is_nan($l));
                case '!=':
                    return JsBoolean::of($l != $r || is_nan($l));
            }
            // Fall through for /, %, **, bitwise — those still need
            // the slower paths (NaN/Infinity handling, divisor-zero
            // semantics, integer truncation).
        }

        return match ($node->operator) {
            '+' => $this->addOperator($left, $right),
            '-' => $this->numericBinaryOp($left, $right, '-'),
            '*' => $this->numericBinaryOp($left, $right, '*'),
            '/' => $this->numericBinaryOp($left, $right, '/'),
            '%' => $this->numericBinaryOp($left, $right, '%'),
            '**' => $this->numericBinaryOp($left, $right, '**'),
            '==' => new JsBoolean(AbstractOperations::abstractEquals($left, $right)),
            '!=' => new JsBoolean(!AbstractOperations::abstractEquals($left, $right)),
            '===' => new JsBoolean(AbstractOperations::strictEquals($left, $right)),
            '!==' => new JsBoolean(!AbstractOperations::strictEquals($left, $right)),
            '<' => $this->relational($left, $right, '<'),
            '>' => $this->relational($right, $left, '>'),
            '<=' => $this->relational($right, $left, '<='),
            '>=' => $this->relational($left, $right, '>='),
            '<<' => $this->bitwiseShift($left, $right, '<<'),
            '>>' => $this->bitwiseShift($left, $right, '>>'),
            '>>>' => $this->bitwiseShift($left, $right, '>>>'),
            '&' => $this->bitwiseBinaryOp($left, $right, '&'),
            '|' => $this->bitwiseBinaryOp($left, $right, '|'),
            '^' => $this->bitwiseBinaryOp($left, $right, '^'),
            'in' => $this->evalInOperator($left, $right),
            'instanceof' => new JsBoolean(AbstractOperations::instanceofOperator($left, $right)),
            default => throw new InternalError("Unknown binary operator: {$node->operator}"),
        };
    }

    /**
     * 13.15.3 Addition operator (+).
     *
     * ToPrimitive both sides. If either is a string, concatenate.
     * Otherwise call ToNumeric on both. If types differ, throw TypeError.
     * BigInt + BigInt uses pure-PHP string arithmetic. Number + Number uses float addition.
     */
    public function addOperator(JsValue $left, JsValue $right): JsValue
    {
        $lprim = TypeConversion::toPrimitive($left);
        $rprim = TypeConversion::toPrimitive($right);

        // String concatenation takes priority.
        if ($lprim instanceof JsString || $rprim instanceof JsString) {
            $l = TypeConversion::toString($lprim);
            $r = TypeConversion::toString($rprim);
            return new JsString(JsString::concatNormalize($l, $r));
        }

        // ToNumeric: if the primitive is already BigInt, keep it; otherwise ToNumber.
        $lnum = TypeConversion::toNumeric($lprim);
        $rnum = TypeConversion::toNumeric($rprim);

        if ($lnum instanceof JsBigInt && $rnum instanceof JsBigInt) {
            return new JsBigInt(self::bigStrBcAdd($lnum->value, $rnum->value));
        }

        if ($lnum instanceof JsBigInt || $rnum instanceof JsBigInt) {
            throw new TypeError('Cannot mix BigInt and other types, use explicit conversions');
        }

        // Both are JsNumber after the BigInt rejection above.
        return JsNumber::of(TypeConversion::toNumber($lnum) + TypeConversion::toNumber($rnum));
    }

    /**
     * Apply a numeric binary operator (-, *, /, %, **) with BigInt support.
     *
     * Calls ToNumeric on both operands. If both are BigInt, performs the
     * corresponding arbitrary-precision operation. If both are Number,
     * delegates to the existing float-based helpers. Mixed types throw TypeError.
     *
     * @param '-'|'*'|'/'|'%'|'**' $op
     */
    public function numericBinaryOp(JsValue $left, JsValue $right, string $op): JsValue
    {
        $lnum = TypeConversion::toNumeric($left);
        $rnum = TypeConversion::toNumeric($right);

        if ($lnum instanceof JsBigInt && $rnum instanceof JsBigInt) {
            return $this->bigintArithmetic($lnum, $rnum, $op);
        }

        if ($lnum instanceof JsBigInt || $rnum instanceof JsBigInt) {
            throw new TypeError('Cannot mix BigInt and other types, use explicit conversions');
        }

        // Both JsNumber: delegate to existing helpers.
        $l = TypeConversion::toNumber($lnum);
        $r = TypeConversion::toNumber($rnum);

        return match ($op) {
            '-' => JsNumber::of($l - $r),
            '*' => JsNumber::of($l * $r),
            '/' => $this->divide($l, $r),
            '%' => $this->modulo($l, $r),
            '**' => $this->exponentiate($lnum, $rnum),
        };
    }

    /**
     * BigInt arithmetic for -, *, /, %, **.
     *
     * Division truncates toward zero (not floor). Division and remainder
     * by zero throw RangeError per the spec.
     *
     * @param '-'|'*'|'/'|'%'|'**' $op
     */
    private function bigintArithmetic(JsBigInt $left, JsBigInt $right, string $op): JsBigInt
    {
        return match ($op) {
            '-' => new JsBigInt(self::bigStrBcSub($left->value, $right->value)),
            '*' => new JsBigInt(self::bigStrBcMul($left->value, $right->value)),
            '/' => $this->bigintDivide($left, $right),
            '%' => $this->bigintRemainder($left, $right),
            '**' => $this->bigintExponentiate($left, $right),
        };
    }

    /**
     * BigInt::divide(x, y). Throws RangeError if y is 0n. Truncates toward zero.
     */
    private function bigintDivide(JsBigInt $left, JsBigInt $right): JsBigInt
    {
        if ($right->value === '0' || $right->value === '-0') {
            throw new \Phasis\Exceptions\RangeError('Division by zero');
        }
        // Divide truncating toward zero, matching the spec.
        return new JsBigInt(self::bigStrBcDiv($left->value, $right->value));
    }

    /**
     * BigInt::remainder(x, y). Throws RangeError if y is 0n.
     */
    private function bigintRemainder(JsBigInt $left, JsBigInt $right): JsBigInt
    {
        if ($right->value === '0' || $right->value === '-0') {
            throw new \Phasis\Exceptions\RangeError('Division by zero');
        }
        return new JsBigInt(self::bigStrBcMod($left->value, $right->value));
    }

    /**
     * BigInt::exponentiate(x, y). Throws RangeError if y is negative.
     */
    private function bigintExponentiate(JsBigInt $left, JsBigInt $right): JsBigInt
    {
        if (self::bigStrComp($right->value, '0') < 0) {
            throw new \Phasis\Exceptions\RangeError('Exponent must be positive');
        }
        return new JsBigInt(self::bigStrBcPow($left->value, $right->value));
    }

    /**
     * Bitwise AND, OR, XOR for both Number and BigInt operands.
     *
     * Per spec: ToNumeric both sides. If both BigInt, perform BigInt bitwise op.
     * If types differ, throw TypeError.
     *
     * @param '&'|'|'|'^' $op
     */
    public function bitwiseBinaryOp(JsValue $left, JsValue $right, string $op): JsValue
    {
        $lnum = TypeConversion::toNumeric($left);
        $rnum = TypeConversion::toNumeric($right);

        if ($lnum instanceof JsBigInt && $rnum instanceof JsBigInt) {
            return $this->bigintBitwiseOp($lnum, $rnum, $op);
        }

        if ($lnum instanceof JsBigInt || $rnum instanceof JsBigInt) {
            throw new TypeError('Cannot mix BigInt and other types, use explicit conversions');
        }

        $l = TypeConversion::toInt32($lnum);
        $r = TypeConversion::toInt32($rnum);

        return JsNumber::of((float) match ($op) {
            '&' => $l & $r,
            '|' => $l | $r,
            '^' => $l ^ $r,
        });
    }

    /**
     * Shift operators (<<, >>, >>>) for both Number and BigInt operands.
     *
     * Per spec: ToNumeric both sides. If both BigInt, perform BigInt shift.
     * BigInt >>> is always a TypeError. If types differ, throw TypeError.
     *
     * @param '<<'|'>>'|'>>>' $op
     */
    public function bitwiseShift(JsValue $left, JsValue $right, string $op): JsValue
    {
        $lnum = TypeConversion::toNumeric($left);
        $rnum = TypeConversion::toNumeric($right);

        if ($lnum instanceof JsBigInt && $rnum instanceof JsBigInt) {
            if ($op === '>>>') {
                throw new TypeError('BigInts have no unsigned right shift, use >> instead');
            }
            return $this->bigintShift($lnum, $rnum, $op);
        }

        if ($lnum instanceof JsBigInt || $rnum instanceof JsBigInt) {
            throw new TypeError('Cannot mix BigInt and other types, use explicit conversions');
        }

        return match ($op) {
            '<<' => JsNumber::of(TypeConversion::leftShift($lnum, $rnum)),
            '>>' => JsNumber::of(TypeConversion::signedRightShift($lnum, $rnum)),
            '>>>' => JsNumber::of(TypeConversion::unsignedRightShift($lnum, $rnum)),
        };
    }

    /**
     * BigInt shift operations.
     *
     * BigInt::leftShift(x, y):
     *   If y < 0: floor(x / 2^(-y)) (rounding toward negative infinity).
     *   Otherwise: x * 2^y.
     *
     * BigInt::signedRightShift(x, y) = BigInt::leftShift(x, -y).
     */
    private function bigintShift(JsBigInt $left, JsBigInt $right, string $op): JsBigInt
    {
        // Right shift is defined as leftShift(x, -y).
        $rightNeg = $right->value[0] === '-';
        $shiftNeg = ($op === '>>' && !$rightNeg) || ($op === '<<' && $rightNeg);

        // Absolute shift amount as a string.
        $absShift = ltrim($right->value, '-');
        if ($absShift === '' || $absShift === '0') {
            return $left;
        }

        // Clamp very large shift amounts to prevent memory exhaustion.
        $shiftInt = $this->bigStrFitsInt($absShift) ? (int) $absShift : 10000;
        $shiftInt = min($shiftInt, 10000);

        if (!$shiftNeg) {
            // Left shift: x * 2^shift.
            // Multiply $left->value by 2 repeatedly (or pow).
            $result = $left->value;
            $multiplier = $this->bigStrPow2($shiftInt);
            $result = $this->bigStrMulSigned($left->value, $multiplier);
            return new JsBigInt($result);
        }

        // Right shift: floor(x / 2^shift) (toward negative infinity).
        if ($this->bigStrFitsInt($left->value)) {
            // Use native PHP int arithmetic for small values.
            $l = (int) $left->value;
            $d = $shiftInt >= 63 ? PHP_INT_MAX : (1 << $shiftInt);
            // PHP >> is arithmetic right shift (sign-extending), which matches floor division.
            return new JsBigInt((string) ($shiftInt >= 63 ? ($l < 0 ? -1 : 0) : ($l >> $shiftInt)));
        }
        // Large: convert to binary, drop last $shiftInt bits, convert back.
        $leftNeg = $left->value[0] === '-';
        $abs = ltrim($left->value, '-');
        $bin = $this->bigintToTwosCompBin($leftNeg ? '-' . $abs : $abs);
        if ($shiftInt >= strlen($bin)) {
            return new JsBigInt($leftNeg ? '-1' : '0');
        }
        $shifted = substr($bin, 0, strlen($bin) - $shiftInt);
        if ($shifted === '') {
            return new JsBigInt($leftNeg ? '-1' : '0');
        }
        $padded = str_pad($shifted, strlen($bin), $leftNeg ? '1' : '0', STR_PAD_LEFT);
        return new JsBigInt($this->twosCompBinToDecimal($padded));
    }

    /** Compute 2^n as a decimal string using pure-PHP string doubling. */
    private function bigStrPow2(int $n): string
    {
        $result = '1';
        for ($i = 0; $i < $n; $i++) {
            $result = self::bigStrMul($result, '2');
        }
        return $result;
    }

    /** Multiply two decimal integer strings (handles sign). */
    private function bigStrMulSigned(string $a, string $b): string
    {
        $negA = $a[0] === '-';
        $negB = $b[0] === '-';
        $absA = ltrim($a, '-');
        $absB = ltrim($b, '-');
        $result = $this->bigStrMulUnsigned($absA, $absB);
        if (($negA xor $negB) && $result !== '0') {
            return '-' . $result;
        }
        return $result;
    }

    /** Multiply two non-negative decimal integer strings using schoolbook algorithm. */
    private function bigStrMulUnsigned(string $a, string $b): string
    {
        if ($a === '0' || $b === '0') {
            return '0';
        }
        $m = strlen($a);
        $n = strlen($b);
        $result = array_fill(0, $m + $n, 0);
        for ($i = $m - 1; $i >= 0; $i--) {
            for ($j = $n - 1; $j >= 0; $j--) {
                $mul = (int) $a[$i] * (int) $b[$j];
                $p1 = $i + $j;
                $p2 = $i + $j + 1;
                $sum = $mul + $result[$p2];
                $result[$p2] = $sum % 10;
                $result[$p1] += intdiv($sum, 10);
            }
        }
        return ltrim(implode('', $result), '0') ?: '0';
    }

    private function divide(float $left, float $right): JsNumber
    {
        if ($right === 0.0) {
            if ($left === 0.0 || is_nan($left)) {
                return JsNumber::of(NAN);
            }
            $leftNeg = $left < 0 || JsNumber::isNegativeZero($left);
            $rightNeg = JsNumber::isNegativeZero($right);
            return JsNumber::of(($leftNeg xor $rightNeg) ? -INF : INF);
        }
        return JsNumber::of($left / $right);
    }

    private function modulo(float $left, float $right): JsNumber
    {
        if (is_nan($left) || is_nan($right) || is_infinite($left) || $right === 0.0) {
            return JsNumber::of(NAN);
        }
        if (is_infinite($right)) {
            return JsNumber::of($left);
        }
        if ($left === 0.0) {
            return JsNumber::of($left); // preserves -0
        }
        return JsNumber::of(fmod($left, $right));
    }

    /**
     * ES spec ExponentiationExpression evaluation.
     *
     * Calls ToNumeric on both operands. If both are BigInt, performs
     * arbitrary-precision exponentiation via pure-PHP string arithmetic. If both are Number,
     * uses float exponentiation with IEEE 754 special cases. Mixed
     * types throw TypeError per spec.
     */
    public function exponentiate(JsValue $left, JsValue $right): JsValue
    {
        $lnum = TypeConversion::toNumeric($left);
        $rnum = TypeConversion::toNumeric($right);

        // BigInt ** BigInt.
        if ($lnum instanceof JsBigInt && $rnum instanceof JsBigInt) {
            if (self::bigStrComp($rnum->value, '0') < 0) {
                throw new \Phasis\Exceptions\RangeError('Exponent must be positive');
            }
            return new JsBigInt(self::bigStrBcPow($lnum->value, $rnum->value));
        }

        // Mixed types: one BigInt and one Number.
        if ($lnum instanceof JsBigInt || $rnum instanceof JsBigInt) {
            throw new TypeError('Cannot mix BigInt and other types, use explicit conversions');
        }

        // Both Number: float exponentiation with ES spec special cases.
        $base = TypeConversion::toNumber($lnum);
        $exp = TypeConversion::toNumber($rnum);

        if (abs($base) === 1.0 && is_infinite($exp)) {
            return JsNumber::of(NAN);
        }
        if ($base === 0.0 && $exp < 0) {
            if (JsNumber::isNegativeZero($base) && fmod($exp, 2) === -1.0) {
                return JsNumber::of(-INF);
            }
            return JsNumber::of(INF);
        }
        return JsNumber::of(@($base ** $exp));
    }

    public function relational(JsValue $x, JsValue $y, string $op): JsValue
    {
        $result = AbstractOperations::abstractRelational($x, $y, $op === '<' || $op === '>=');
        if ($result === null) {
            // Per spec, undefined (NaN or incomparable) always produces false for all relational operators.
            return new JsBoolean(false);
        }
        if ($op === '<=' || $op === '>=') {
            // a >= b is !(a < b), a <= b is !(b < a). If abstractRelational returned true,
            // the negated operator returns false, and vice versa.
            return new JsBoolean(!$result);
        }
        return new JsBoolean($result);
    }

    /**
     * Normalize a JsBigInt value string (which may use 0x, 0o, 0b prefixes)
     * to a decimal string suitable for bcmath operations.
     */
    private function bigIntToDecimal(string $value): string
    {
        $negative = false;
        $v = $value;
        if ($v !== '' && $v[0] === '-') {
            $negative = true;
            $v = substr($v, 1);
        }

        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/', $v, $m) === 1) {
            $dec = self::baseStringToDecimal($m[1], 16);
            return $negative ? '-' . $dec : $dec;
        }

        if (preg_match('/^0[oO]([0-7]+)$/', $v, $m) === 1) {
            $dec = self::baseStringToDecimal($m[1], 8);
            return $negative ? '-' . $dec : $dec;
        }

        if (preg_match('/^0[bB]([01]+)$/', $v, $m) === 1) {
            $dec = self::baseStringToDecimal($m[1], 2);
            return $negative ? '-' . $dec : $dec;
        }

        return $value;
    }

    private function evalInOperator(JsValue $left, JsValue $right): JsValue
    {
        if (!$right instanceof JsObject) {
            throw new TypeError(
                'Cannot use "in" operator to search for "'
                . (
                    $left instanceof JsSymbol
                        ? $left->toString()
                        : TypeConversion::toString($left)
                )
                . '" in ' . TypeConversion::toString($right)
            );
        }
        // Spec §13.10.2 step 5: the key goes through ToPropertyKey, which
        // returns the unboxed Symbol for a Symbol wrapper instead of
        // attempting ToString on it.
        $propKey = TypeConversion::toPropertyKey($left);
        if ($propKey instanceof JsSymbol) {
            return new JsBoolean($right->hasBySymbol($propKey));
        }
        return new JsBoolean($right->has(TypeConversion::toString($propKey)));
    }

    private function evalLogicalExpression(LogicalExpression $node, Environment $env): JsValue
    {
        $left = $this->evaluate($node->left, $env);

        return match ($node->operator) {
            '&&' => TypeConversion::toBoolean($left) ? $this->evaluate($node->right, $env) : $left,
            '||' => TypeConversion::toBoolean($left) ? $left : $this->evaluate($node->right, $env),
            // OptionalChain short-circuit returns JsOptionalUndefined
            // which is "logically undefined" — `??` must treat it the
            // same as undefined and evaluate the right operand.
            '??' => ($left instanceof JsNull || $left instanceof JsUndefined || $left instanceof JsOptionalUndefined)
                ? $this->evaluate($node->right, $env)
                : $left,
            default => throw new InternalError("Unknown logical operator: {$node->operator}"),
        };
    }

    private function evalUnaryExpression(UnaryExpression $node, Environment $env): JsValue
    {
        if ($node->operator === 'typeof') {
            return $this->evalTypeof($node->argument, $env);
        }
        if ($node->operator === 'delete') {
            return $this->evalDelete($node->argument, $env);
        }

        $value = $this->evaluate($node->argument, $env);

        if ($node->operator === '-') {
            $numeric = TypeConversion::toNumeric($value);
            if ($numeric instanceof JsBigInt) {
                $v = $numeric->value;
                if ($v === '0' || $v === '-0') {
                    return new JsBigInt('0');
                }
                $negated = str_starts_with($v, '-') ? substr($v, 1) : '-' . $v;
                return new JsBigInt($negated);
            }
            return JsNumber::of(-($numeric instanceof JsNumber ? $numeric->value : TypeConversion::toNumber($numeric)));
        }

        return match ($node->operator) {
            '!' => new JsBoolean(!TypeConversion::toBoolean($value)),
            '+' => $value instanceof JsBigInt
                ? throw new TypeError('Cannot convert a BigInt value to a number')
                : JsNumber::of(TypeConversion::toNumber($value)),
            '~' => (function () use ($value) {
                // Per §13.5.6.1 step 2: ToNumeric unboxes wrappers (e.g.
                // Object(1n) → 1n) and distinguishes BigInt from Number.
                $numeric = TypeConversion::toNumeric($value);
                if ($numeric instanceof JsBigInt) {
                    return new JsBigInt(self::bigIntBitwiseNot($numeric->value));
                }
                return JsNumber::of((float) (~TypeConversion::toInt32($numeric)));
            })(),
            'void' => JsUndefined::instance(),
            default => throw new InternalError("Unknown unary operator: {$node->operator}"),
        };
    }

    private function evalTypeof(Node $argument, Environment $env): JsValue
    {
        if ($argument instanceof Identifier) {
            if (!$env->has($argument->name)) {
                return new JsString('undefined');
            }
        }
        $value = $this->evaluate($argument, $env);
        return AbstractOperations::typeofOperator($value);
    }

    private function evalDelete(Node $argument, Environment $env): JsValue
    {
        // Spec §13.5.1.2: delete super.foo / delete super[expr] always
        // throws ReferenceError. Per 13.3.7.1, GetThisBinding runs BEFORE
        // evaluating the property expression — so in a derived constructor
        // before super(), the property expression must not run (this read
        // throws ReferenceError first). After this read succeeds, evaluate
        // the property expression for side effects, then throw the
        // 13.5.1.2 ReferenceError.
        if (
            $argument instanceof MemberExpression
            && $argument->object instanceof Identifier
            && $argument->object->name === 'super'
        ) {
            $env->get('this');
            if ($argument->computed) {
                $this->evaluate($argument->property, $env);
            }
            throw new ReferenceError(
                'Unsupported reference to "super"',
            );
        }

        if ($argument instanceof MemberExpression) {
            $obj = $this->evaluate($argument->object, $env);
            // Optional chain short-circuit: `delete a?.b` where a is null
            // or undefined produces an undefined Reference; delete on a
            // non-Reference returns true per §13.5.1.2 step 3.
            if (
                $argument->optional
                && ($obj instanceof JsNull || $obj instanceof JsUndefined || $obj instanceof JsOptionalUndefined)
            ) {
                return new JsBoolean(true);
            }
            // Optional chain short-circuit propagation: a continued chain
            // (e.g. `delete (a?.b).c`) carrying the sentinel also returns
            // true without further evaluation.
            if ($obj instanceof JsOptionalUndefined) {
                return new JsBoolean(true);
            }
            if ($obj instanceof JsNull || $obj instanceof JsUndefined) {
                throw new TypeError(
                    'Cannot read properties of ' . ($obj instanceof JsNull ? 'null' : 'undefined') . ' (deleting)',
                );
            }
            if ($obj instanceof JsObject) {
                $rawKey = null;
                if ($argument->computed) {
                    // ToPropertyKey on the computed key so a Symbol returned
                    // by a custom toString stays a Symbol key.
                    $rawKey = TypeConversion::toPropertyKey($this->evaluate($argument->property, $env));
                    if ($rawKey instanceof JsSymbol) {
                        // Delete symbol-keyed property.
                        $deleted = $obj->deleteBySymbol($rawKey);
                        if (!$deleted && $this->strictMode) {
                            throw new TypeError(
                                "Cannot delete property '" . $rawKey->toString() . "' of #<Object>"
                            );
                        }
                        return new JsBoolean($deleted);
                    }
                    $key = $rawKey instanceof JsString ? $rawKey->value : TypeConversion::toString($rawKey);
                } else {
                    $key = $argument->property instanceof Identifier ? $argument->property->name : '';
                }
                return new JsBoolean($obj->delete($key, $this->strictMode));
            }
            // Deleting a property on a primitive base: per spec [[Delete]] on
            // an Object Reference Record uses ToObject(GetBase) then attempts
            // delete. For string-indexed properties (non-configurable), this
            // returns false in sloppy mode and throws TypeError in strict.
            if ($obj instanceof JsString) {
                $rawKey = null;
                if ($argument->computed) {
                    $rawKey = TypeConversion::toPropertyKey($this->evaluate($argument->property, $env));
                    $key = $rawKey instanceof JsString ? $rawKey->value : TypeConversion::toString($rawKey);
                } else {
                    $key = $argument->property instanceof Identifier ? $argument->property->name : '';
                }
                // String indices and length are non-configurable on the
                // exotic String object — deletion fails.
                $u16 = JsString::utf8ToUtf16LE($obj->value);
                $u16Len = (int) (strlen($u16) / 2);
                if ($key === 'length' || (ctype_digit($key) && (int) $key < $u16Len)) {
                    if ($this->strictMode) {
                        throw new TypeError("Cannot delete property '{$key}' of '{$obj->value}'");
                    }
                    return new JsBoolean(false);
                }
                // Other properties (those on String.prototype etc.) cannot
                // be deleted via the exotic — also non-configurable on the
                // wrapper, so always true (no own slot to remove).
                return new JsBoolean(true);
            }
            // Other primitives: no own properties, delete is a no-op.
            return new JsBoolean(true);
        }

        // Delete on an identifier reference.
        if ($argument instanceof Identifier) {
            $name = $argument->name;
            // MetaProperty (new.target, import.meta) evaluates to a value,
            // not a Reference, so `delete (new.target)` returns true per
            // §13.5.1.2: "If ref is not a Reference Record, return true."
            if ($name === '[[NewTarget]]' || $name === '[[ImportMeta]]') {
                return new JsBoolean(true);
            }
            if ($this->strictMode) {
                // In strict mode, `delete identifier` is a SyntaxError, but since
                // we get here at runtime we throw it as a SyntaxError-like error.
                // The spec says deleting an unresolvable reference in strict mode
                // is a SyntaxError, but deleting a declared binding in strict mode
                // is also a SyntaxError. Some tests expect TypeError for certain
                // global object properties; those go through the MemberExpression
                // branch above. Here we handle the raw identifier case.
                throw new \Phasis\Exceptions\SyntaxError(
                    'Delete of an unqualified identifier in strict mode.'
                );
            }
            if (!$env->has($name)) {
                // Unresolvable reference: return true.
                return new JsBoolean(true);
            }
            return new JsBoolean($env->deleteBinding($name));
        }

        // For any other expression (call expression, literal, etc.): evaluate the
        // expression for side effects, then return true. Per spec, delete on a
        // non-Reference value returns true.
        $this->evaluate($argument, $env);
        return new JsBoolean(true);
    }

    private function evalUpdateExpression(UpdateExpression $node, Environment $env): JsValue
    {
        // In strict mode, ++/-- on `eval` or `arguments` is a SyntaxError.
        if ($this->strictMode && $node->argument instanceof Identifier) {
            if ($node->argument->name === 'eval' || $node->argument->name === 'arguments') {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Cannot modify '{$node->argument->name}' in strict mode",
                );
            }
        }

        // Fast path: `i++` / `++i` / `i--` / `--i` on a plain Identifier
        // when there is no with-environment reachable from the current
        // chain (covers both currently-active with statements and
        // with-envs captured by an enclosing closure). Skips the
        // Reference allocation, the deferred-key path, and the with-
        // trap dance for the case that dominates loop counters.
        if (
            $node->argument instanceof Identifier
            && $this->withEnvObjects === []
            && $node->argument->name !== 'undefined'
            && !$env->hasAnyWithObjectInChain()
        ) {
            $name = $node->argument->name;
            $current = $env->get($name, $this->strictMode);
            if ($current instanceof JsNumber) {
                $delta = $node->operator === '++' ? 1.0 : -1.0;
                $newValue = JsNumber::of($current->value + $delta);
                $env->set($name, $newValue, $this->strictMode);
                return $node->prefix ? $newValue : $current;
            }
            // Non-number paths (BigInt, ToNumeric coercion) fall through
            // to the spec path below.
        }

        $ref = $this->resolveReference($node->argument, $env);

        // Per spec 6.2.4.4: if the reference base is null or undefined, throw
        // TypeError before GetValue so that deferred property key ToString
        // (which may have side effects) is never triggered.
        if (
            !($ref->base instanceof Environment)
            && ($ref->base instanceof JsNull || $ref->base instanceof JsUndefined)
        ) {
            $typeName = $ref->base instanceof JsNull ? 'null' : 'undefined';
            throw new TypeError(
                "Cannot read properties of {$typeName} (reading '{$ref->name}')"
            );
        }

        // Use ToNumeric (not ToNumber) so BigInt values are preserved.
        // Use withGetBindingValue for spec-correct HasProperty trap order.
        $oldNumeric = TypeConversion::toNumeric($this->withGetBindingValue($ref));

        if ($oldNumeric instanceof JsBigInt) {
            // BigInt::add(oldValue, BigInt::unit) per spec.
            $decVal = $this->bigIntToDecimal($oldNumeric->value);
            $delta = $node->operator === '++' ? '1' : '-1';
            $raw = self::bigStrBcAdd($decVal, $delta);
            if ($raw === '-0') {
                $raw = '0';
            }
            $newValue = new JsBigInt($raw);
            $this->withSetMutableBindingCheck($ref, $newValue);
            return $node->prefix ? $newValue : $oldNumeric;
        }

        $oldValue = JsNumber::of(
            $oldNumeric instanceof JsNumber ? $oldNumeric->value : TypeConversion::toNumber($oldNumeric),
        );
        $delta = $node->operator === '++' ? 1.0 : -1.0;
        $newValue = JsNumber::of($oldValue->value + $delta);
        $this->withSetMutableBindingCheck($ref, $newValue);

        return $node->prefix ? $newValue : $oldValue;
    }

    private function evalAssignment(AssignmentExpression $node, Environment $env): JsValue
    {
        // In strict mode, assignment to `eval` or `arguments` is a SyntaxError.
        if ($this->strictMode && $node->left instanceof Identifier) {
            if ($node->left->name === 'eval' || $node->left->name === 'arguments') {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Cannot assign to '{$node->left->name}' in strict mode",
                );
            }
        }

        if ($node->operator === '=' && $this->isDestructuringTarget($node->left)) {
            $value = $this->evaluate($node->right, $env);
            $this->destructureAssign($node->left, $value, $env);
            return $value;
        }

        // Fast path: simple assignment (`x = rhs`) where LHS is a plain
        // Identifier and no with-environments are reachable from the
        // current scope chain. The hasAnyWithObjectInChain check covers
        // the case where the function was defined inside a `with` and
        // is still walking that with-env via its closure even after the
        // with statement has exited. Captures the binding env before RHS
        // evaluation, then writes through it. Name-inference rules
        // (13.15.2 step 1.e) for anonymous function / class expressions
        // are preserved.
        if (
            $node->operator === '='
            && $node->left instanceof Identifier
            && $this->withEnvObjects === []
            && $node->left->name !== 'eval'
            && $node->left->name !== 'arguments'
            && !$node->leftParenthesized
            && !$env->hasAnyWithObjectInChain()
        ) {
            $name = $node->left->name;
            // Reuse the Identifier scope-depth cache for writes too:
            // when programIsEvalFree, the depth previously memoised
            // by an Identifier read points at the same env we want
            // to write to. Skip the chain walk in resolveBindingEnvironment.
            if (
                $this->programIsEvalFree
                && $node->left->resolvedDepth !== null
            ) {
                $bindingEnv = $env->envAtDepth($node->left->resolvedDepth);
                if (
                    $bindingEnv === null
                    || (!$bindingEnv->hasOwnBinding($name)
                        && !($bindingEnv->getLinkedObject() !== null
                             && $bindingEnv->getParent() === null
                             && $bindingEnv->getLinkedObject()->hasOwnProperty($name)))
                ) {
                    $bindingEnv = $this->resolveBindingEnvironment($env, $name);
                }
            } else {
                $bindingEnv = $this->resolveBindingEnvironment($env, $name);
            }
            if ($bindingEnv !== null) {
                if (
                    $node->right instanceof ClassExpression
                    && $node->right->id === null
                ) {
                    $right = $this->evalClassExpression($node->right, $env, $name);
                } else {
                    $right = $this->evaluate($node->right, $env);
                }
                if (
                    $right instanceof JsFunction
                    && $this->isAnonymousFunctionDefinitionNode($node->right)
                    && !$this->hasExplicitNameProperty($right)
                ) {
                    $right->setName($name);
                }
                $bindingEnv->set($name, $right, $this->strictMode);
                return $right;
            }
            // Unresolvable LHS: in strict mode, the spec throws
            // ReferenceError before evaluating RHS. Fall through to the
            // slow path so it can produce that error correctly.
            if ($this->strictMode) {
                throw new ReferenceError("{$name} is not defined");
            }
            // Sloppy: evaluate RHS and let the slow path implicitly
            // create the global binding.
        }

        // Fast path: compound assignment (`x += y`) where LHS is a plain
        // Identifier and no with-environments are reachable from the
        // current scope chain. We capture the binding environment up
        // front, so even if the RHS injects a shadowing binding via eval
        // (`x *= (eval("var x = 2"), 4)`) the PutValue still targets the
        // originally-resolved environment — matching the spec's
        // "Reference frozen before initializer" requirement. Skips
        // Reference allocation, the deferred-key dance, and the with-
        // trap dispatch entirely.
        if (
            $node->operator !== '='
            && $node->operator !== '&&='
            && $node->operator !== '||='
            && $node->operator !== '??='
            && $node->left instanceof Identifier
            && $this->withEnvObjects === []
            && $node->left->name !== 'eval'
            && $node->left->name !== 'arguments'
            && !$env->hasAnyWithObjectInChain()
        ) {
            $name = $node->left->name;
            if (
                $this->programIsEvalFree
                && $node->left->resolvedDepth !== null
            ) {
                $bindingEnv = $env->envAtDepth($node->left->resolvedDepth);
                if (
                    $bindingEnv === null
                    || (!$bindingEnv->hasOwnBinding($name)
                        && !($bindingEnv->getLinkedObject() !== null
                             && $bindingEnv->getParent() === null
                             && $bindingEnv->getLinkedObject()->hasOwnProperty($name)))
                ) {
                    $bindingEnv = $this->resolveBindingEnvironment($env, $name);
                }
            } else {
                $bindingEnv = $this->resolveBindingEnvironment($env, $name);
            }
            if ($bindingEnv !== null) {
                $leftVal = $bindingEnv->get($name, $this->strictMode);
                $right = $this->evaluate($node->right, $env);
                $result = match ($node->operator) {
                    '+=' => $leftVal instanceof JsNumber && $right instanceof JsNumber
                        ? JsNumber::of($leftVal->value + $right->value)
                        : $this->addOperator($leftVal, $right),
                    '-=' => $leftVal instanceof JsNumber && $right instanceof JsNumber
                        ? JsNumber::of($leftVal->value - $right->value)
                        : $this->numericBinaryOp($leftVal, $right, '-'),
                    '*=' => $leftVal instanceof JsNumber && $right instanceof JsNumber
                        ? JsNumber::of($leftVal->value * $right->value)
                        : $this->numericBinaryOp($leftVal, $right, '*'),
                    '/=' => $this->numericBinaryOp($leftVal, $right, '/'),
                    '%=' => $this->numericBinaryOp($leftVal, $right, '%'),
                    '**=' => $this->exponentiate($leftVal, $right),
                    '<<=' => $this->bitwiseShift($leftVal, $right, '<<'),
                    '>>=' => $this->bitwiseShift($leftVal, $right, '>>'),
                    '>>>=' => $this->bitwiseShift($leftVal, $right, '>>>'),
                    '&=' => $this->bitwiseBinaryOp($leftVal, $right, '&'),
                    '|=' => $this->bitwiseBinaryOp($leftVal, $right, '|'),
                    '^=' => $this->bitwiseBinaryOp($leftVal, $right, '^'),
                    default => throw new InternalError("Unknown assignment operator: {$node->operator}"),
                };
                $bindingEnv->set($name, $result, $this->strictMode);
                return $result;
            }
        }

        $ref = $this->resolveReference($node->left, $env);

        // Per spec 13.15.2 step 1.c: if lref is a strict unresolvable reference,
        // throw ReferenceError BEFORE evaluating the RHS.
        if (
            $this->strictMode
            && $node->left instanceof Identifier
            && $ref->base instanceof Environment
            && !$ref->base->has($ref->name)
        ) {
            throw new ReferenceError("{$ref->name} is not defined");
        }

        if ($node->operator === '=') {
            // Function name inference per spec 13.15.2 step 1.e:
            // If IsAnonymousFunctionDefinition is true, then
            //   a. Let hasNameProperty be HasOwnProperty(rval, "name").
            //   b. If hasNameProperty is false, perform SetFunctionName(rval, lref).
            // Per spec, IsIdentifierRef returns false for parenthesized expressions
            // like (fn) = function() {}, so name inference must not apply.
            $isIdentRef = $node->left instanceof Identifier && !$node->leftParenthesized;
            // For anonymous class expressions, use NamedEvaluation so static
            // fields observe the binding name.
            if (
                $isIdentRef
                && $node->right instanceof ClassExpression
                && $node->right->id === null
            ) {
                $right = $this->evalClassExpression($node->right, $env, $node->left->name);
            } else {
                $right = $this->evaluate($node->right, $env);
            }
            if (
                $right instanceof JsFunction
                && $isIdentRef
                && $this->isAnonymousFunctionDefinitionNode($node->right)
                && !$this->hasExplicitNameProperty($right)
            ) {
                $right->setName($node->left->name);
            }
            $this->withSetMutableBindingCheck($ref, $right);
            return $right;
        }

        // Logical assignment operators (&&=, ||=, ??=) per spec 13.15.3:
        // GetValue before RHS, and RHS is evaluated conditionally.
        // Per spec 6.2.5.5 GetValue step 3a: if base is null/undefined,
        // ToObject throws TypeError before resolving the property key.
        if ($node->operator === '&&=' || $node->operator === '||=' || $node->operator === '??=') {
            if ($ref->base instanceof JsNull || $ref->base instanceof JsUndefined) {
                $typeName = $ref->base instanceof JsNull ? 'null' : 'undefined';
                throw new TypeError(
                    "Cannot read properties of {$typeName}",
                );
            }
            $leftVal = $this->withGetBindingValue($ref);
            $shouldAssign = match ($node->operator) {
                '&&=' => TypeConversion::toBoolean($leftVal),
                '||=' => !TypeConversion::toBoolean($leftVal),
                '??=' => $leftVal instanceof JsNull || $leftVal instanceof JsUndefined,
            };
            if (!$shouldAssign) {
                return $leftVal;
            }
            $right = $this->evaluate($node->right, $env);
            // NamedEvaluation: per spec 13.15.3 step 5, if the RHS is an
            // anonymous function definition and the LHS is an identifier ref,
            // set the function name to the identifier name.
            $isIdentRef = $node->left instanceof Identifier && !$node->leftParenthesized;
            if (
                $right instanceof JsFunction
                && $isIdentRef
                && $this->isAnonymousFunctionDefinitionNode($node->right)
                && !$this->hasExplicitNameProperty($right)
            ) {
                $right->setName($node->left->name);
            }
            $this->withSetMutableBindingCheck($ref, $right);
            return $right;
        }

        // Compound assignment operators per spec 13.15.2:
        // 1. GetValue(lref) before evaluating RHS.
        // 2. Evaluate RHS.
        // 3. Apply operation.
        // 4. PutValue(lref, result).
        // Per spec 6.2.5.5 GetValue step 3a: if base is null/undefined,
        // ToObject throws TypeError before resolving the property key.
        if ($ref->base instanceof JsNull || $ref->base instanceof JsUndefined) {
            $typeName = $ref->base instanceof JsNull ? 'null' : 'undefined';
            throw new TypeError(
                "Cannot read properties of {$typeName}",
            );
        }
        $leftVal = $this->withGetBindingValue($ref);
        $right = $this->evaluate($node->right, $env);

        $result = match ($node->operator) {
            '+=' => $this->addOperator($leftVal, $right),
            '-=' => $this->numericBinaryOp($leftVal, $right, '-'),
            '*=' => $this->numericBinaryOp($leftVal, $right, '*'),
            '/=' => $this->numericBinaryOp($leftVal, $right, '/'),
            '%=' => $this->numericBinaryOp($leftVal, $right, '%'),
            '**=' => $this->exponentiate($leftVal, $right),
            '<<=' => $this->bitwiseShift($leftVal, $right, '<<'),
            '>>=' => $this->bitwiseShift($leftVal, $right, '>>'),
            '>>>=' => $this->bitwiseShift($leftVal, $right, '>>>'),
            '&=' => $this->bitwiseBinaryOp($leftVal, $right, '&'),
            '|=' => $this->bitwiseBinaryOp($leftVal, $right, '|'),
            '^=' => $this->bitwiseBinaryOp($leftVal, $right, '^'),
            default => throw new InternalError("Unknown assignment operator: {$node->operator}"),
        };

        $this->withSetMutableBindingCheck($ref, $result);
        return $result;
    }

    private function evalConditional(ConditionalExpression $node, Environment $env): JsValue
    {
        $test = $this->evaluate($node->test, $env);
        return TypeConversion::toBoolean($test)
            ? $this->evaluate($node->consequent, $env)
            : $this->evaluate($node->alternate, $env);
    }

    private function evalCallExpression(CallExpression $node, Environment $env): JsValue
    {
        // Super call: super(args) inside a class constructor.
        // Calls the super class constructor with the current this and args.
        if ($node->callee instanceof Identifier && $node->callee->name === 'super') {
            // Spec §13.3.7.1 SuperCall: GetSuperConstructor (a [[GetPrototypeOf]]
            // on the active function) is captured BEFORE evaluating arguments,
            // so an arg expression that mutates the class's [[Prototype]] cannot
            // change which super-constructor we end up calling.
            try {
                $activeFunc = $env->get('[[ActiveFunction]]');
            } catch (\Throwable) {
                $activeFunc = null;
            }
            $superCtor = $activeFunc instanceof JsFunction ? $activeFunc->getPrototype() : null;
            $isDerived = $activeFunc instanceof JsFunction && $activeFunc->isDerivedConstructor();
            $args = $this->evaluateArguments($node->arguments, $env);

            // Per spec: check IsConstructor after evaluating arguments. A
            // JsProxy wrapping a constructable target also passes IsConstructor.
            // A JsFunction without [[Construct]] (e.g. %FunctionPrototype% used
            // when `class C extends null`) fails IsConstructor.
            $superIsConstructor = ($superCtor instanceof JsFunction && $superCtor->isConstructable())
                || ($superCtor instanceof \Phasis\Value\JsProxy && $superCtor->isConstructable());
            if (!$superIsConstructor) {
                throw new TypeError('Super constructor must be a constructor');
            }

            // For derived constructors, this is in TDZ until super() initializes it.
            if ($isDerived) {
                // Get the pending this object created by new.
                try {
                    $currentThis = $env->get('[[PendingThis]]');
                } catch (\Throwable) {
                    $currentThis = JsUndefined::instance();
                }
            } else {
                try {
                    $currentThis = $env->get('this');
                } catch (\Throwable) {
                    $currentThis = JsUndefined::instance();
                }
            }

            // Temporarily set [[NewTarget]] on currentThis for construct semantics.
            $superNewTarget = null;
            try {
                $snt = $env->get('[[NewTarget]]');
                if (!$snt instanceof JsUndefined) {
                    $superNewTarget = $snt;
                }
            } catch (\Throwable) {
            }
            $superHadNT = false;
            if ($currentThis instanceof JsObject) {
                $superHadNT = !($currentThis->get('[[NewTarget]]') instanceof JsUndefined);
                if (!$superHadNT && $superNewTarget instanceof JsFunction) {
                    $currentThis->defineOwnProperty(
                        '[[NewTarget]]',
                        \Phasis\Object\PropertyDescriptor::data($superNewTarget, false, false, false),
                    );
                }
            }
            try {
                // Proxy super: forward through the proxy's [[Construct]]
                // so handler.construct (if any) fires; result becomes the
                // new this binding.
                if ($superCtor instanceof \Phasis\Value\JsProxy) {
                    $newTarget = $superNewTarget instanceof JsValue ? $superNewTarget : $superCtor;
                    $result = $superCtor->construct($args, $newTarget);
                } else {
                    // If super is a base-class constructor (not itself derived),
                    // initialize its instance fields on the receiver before its
                    // body evaluates so default-param expressions like
                    // `constructor(o = this.#x)` can see the fields.
                    if (
                        $superCtor->isClassConstructor()
                        && !$superCtor->isDerivedConstructor()
                        && $currentThis instanceof JsObject
                    ) {
                        $this->initializeInstanceFields($superCtor, $currentThis, $env);
                    }
                    $result = $this->callFunction($superCtor, $currentThis, $args);
                }
            } finally {
                if ($currentThis instanceof JsObject && !$superHadNT) {
                    $currentThis->forceDelete('[[NewTarget]]');
                }
            }

            // Per spec 8.1.1.3.1 BindThisValue: if this is already initialized,
            // calling super() again throws ReferenceError. The check happens AFTER
            // the super constructor call (arguments already evaluated, constructor
            // already executed), matching the spec's BindThisValue ordering.
            if ($isDerived) {
                $thisVal = $result instanceof JsObject ? $result : $currentThis;
                // Check if this is already initialized (double super() call).
                // Walk up to find the env with the 'this' TDZ binding.
                $thisEnv = $env;
                while ($thisEnv !== null) {
                    if ($thisEnv->hasOwnBinding('this')) {
                        break;
                    }
                    $thisEnv = $thisEnv->getParent();
                }
                if ($thisEnv === null) {
                    $thisEnv = $env;
                }
                // If 'this' is no longer in TDZ, it was already initialized.
                $alreadyInitialized = false;
                try {
                    $thisEnv->get('this');
                    // Did not throw, so 'this' is already initialized.
                    $alreadyInitialized = true;
                } catch (\Throwable) {
                    // In TDZ, expected for first super() call.
                }
                if ($alreadyInitialized) {
                    throw new ReferenceError('Super constructor may only be called once');
                }
                // Walk the scope chain to find the environment where 'this' is in TDZ
                // (the constructor's function scope), so that super() called from arrow
                // functions or nested blocks still initializes the correct binding.
                $this->initializeThisBinding($env, $thisVal);

                // Per spec, instance field initializers run right after super() in derived classes.
                if ($thisVal instanceof JsObject) {
                    $this->initializeInstanceFields($activeFunc, $thisVal, $env);
                }

                return $thisVal;
            }

            // Non-derived: if super returned an object, bind that as this.
            if ($result instanceof JsObject) {
                $env->set('this', $result);
                return $result;
            }
            return $currentThis;
        }

        // Super member call: super.method(args) or super[expr](args).
        if (
            $node->callee instanceof MemberExpression
            && $node->callee->object instanceof Identifier
            && $node->callee->object->name === 'super'
        ) {
            try {
                $homeObject = $env->get('[[HomeObject]]');
            } catch (\Throwable) {
                $homeObject = null;
            }
            $superBase = $homeObject instanceof JsObject ? $homeObject->getPrototype() : null;
            if ($superBase === null) {
                throw new TypeError('Cannot read properties of undefined (super)');
            }
            // Per spec 12.3.5.1: evaluating super.property requires GetThisBinding().
            // In a derived constructor before super(), this throws ReferenceError.
            $thisValue = $env->get('this');
            // Resolve the property key. Computed keys go through ToPropertyKey
            // so a Symbol returned by a custom toString stays a Symbol key.
            if ($node->callee->computed) {
                $rawKey = TypeConversion::toPropertyKey($this->evaluate($node->callee->property, $env));
                $key = $rawKey instanceof JsSymbol ? '' : ($rawKey instanceof JsString ? $rawKey->value : TypeConversion::toString($rawKey));
            } else {
                $rawKey = null;
                $key = $node->callee->property instanceof Identifier
                    ? $node->callee->property->name
                    : TypeConversion::toString($this->evaluate($node->callee->property, $env));
            }
            $callee = $rawKey instanceof JsSymbol ? $superBase->getBySymbol($rawKey) : $superBase->get($key);
            if (!$callee instanceof JsFunction) {
                throw new TypeError("{$key} is not a function");
            }
            $args = $this->evaluateArguments($node->arguments, $env);
            return $this->callFunction($callee, $thisValue, $args);
        }

        // Direct eval detection: eval(code) called with the identifier 'eval'.
        // Direct eval executes in the current scope, not a fresh environment.
        // Per spec, eval?.() is NOT a direct eval (it is an indirect eval),
        // because the optional chain makes it an OptionalExpression, not a
        // direct CallExpression with eval as the callee.
        if ($node->callee instanceof Identifier && $node->callee->name === 'eval' && !$node->optional) {
            try {
                $callee = $env->get('eval');
            } catch (ReferenceError) {
                $callee = null;
            }
            // Per spec 12.3.4.1 step 3.a.i: SameValue(func, %eval%) where
            // %eval% is the *current realm's* eval intrinsic. If the eval
            // binding has been reassigned to another realm's eval (e.g.
            // `var eval = other.eval`), the call falls through to indirect
            // eval, which executes in the callee realm's global scope.
            $isCurrentRealmEval = $callee instanceof JsFunction
                && $callee->getName() === 'eval'
                && $callee->isNative()
                && ($callee->realm === null || $callee->realm === $this->engineRealm);
            if ($isCurrentRealmEval) {
                // Per spec 12.3.4.1 step 3.a: evaluate all arguments (including
                // spreads) to get argList, then use the first element as evalText.
                // If argList is empty, return undefined.
                $argList = $this->evaluateArguments($node->arguments, $env);
                if (count($argList) === 0) {
                    return JsUndefined::instance();
                }
                return $this->performDirectEval($argList[0], $env);
            }
        }

        $thisValue = JsUndefined::instance();
        $isMethodCall = false;

        if ($node->callee instanceof MemberExpression) {
            $rawObj = $this->evaluate($node->callee->object, $env);

            // Optional chain short-circuit: the base of the callee was
            // null/undefined via ?., so skip the call entirely.
            if ($rawObj instanceof JsOptionalUndefined) {
                return $rawObj;
            }

            // Optional call: obj?.method() where obj evaluates to null/undefined
            if ($node->callee->optional && ($rawObj instanceof JsNull || $rawObj instanceof JsUndefined)) {
                return JsOptionalUndefined::instance();
            }

            // Private method call: obj.#method(args)
            if ($node->callee->property instanceof PrivateIdentifier) {
                if (!($rawObj instanceof JsObject)) {
                    throw new TypeError(
                        'Cannot read private member ' . $node->callee->property->name . ' from a non-object',
                    );
                }
                $brandedName = $env->resolvePrivateName($node->callee->property->name);
                $callee = $rawObj->getPrivateField($brandedName);
                $args = $this->evaluateArguments($node->arguments, $env);
                if (!($callee instanceof JsFunction)) {
                    throw new TypeError(
                        TypeConversion::toString($callee) . ' is not a function',
                    );
                }
                return $this->callFunction($callee, $rawObj, $args);
            }

            $rawCallKey = null;
            if ($node->callee->computed) {
                $rawCallKey = $this->evaluate($node->callee->property, $env);
            }
            $isSymbolCallKey = $rawCallKey instanceof JsSymbol;
            $key = $isSymbolCallKey ? '' : ($node->callee->computed
                ? TypeConversion::toString($rawCallKey)
                : ($node->callee->property instanceof Identifier
                    ? $node->callee->property->name : ''));

            // String method calls: look up on __StringPrototype__
            if ($rawObj instanceof JsString && !$isSymbolCallKey) {
                $proto = $this->cachedStringPrototype ??= $this->resolveCachedPrototype('__StringPrototype__');
                if ($proto instanceof JsObject) {
                    $method = $proto->get($key);
                    if ($method instanceof JsFunction) {
                        $args = $this->evaluateArguments($node->arguments, $env);
                        return $this->callFunction($method, $rawObj, $args);
                    }
                    // Callable Proxy methods on String.prototype must also keep
                    // the primitive as `this` so strict-mode underlying
                    // functions observe the unboxed value.
                    if ($method instanceof JsProxy && $method->isCallable()) {
                        $args = $this->evaluateArguments($node->arguments, $env);
                        return $method->apply($rawObj, $args);
                    }
                }
            }

            // Number method calls: look up on Number.prototype
            if ($rawObj instanceof JsNumber && !$isSymbolCallKey) {
                $numCtor = $env->has('Number') ? $env->get('Number') : null;
                if ($numCtor instanceof JsFunction) {
                    $numProto = $numCtor->get('prototype');
                    if ($numProto instanceof JsObject) {
                        $method = $numProto->get($key);
                        if ($method instanceof JsFunction) {
                            $args = $this->evaluateArguments($node->arguments, $env);
                            return $this->callFunction($method, $rawObj, $args);
                        }
                    }
                }
            }

            // Boolean method calls: look up on Boolean wrapper
            if ($rawObj instanceof JsBoolean && !$isSymbolCallKey) {
                $boolCtor = $env->has('Boolean') ? $env->get('Boolean') : null;
                if ($boolCtor instanceof JsFunction) {
                    $boolProto = $boolCtor->get('prototype');
                    if ($boolProto instanceof JsObject) {
                        $method = $boolProto->get($key);
                        if ($method instanceof JsFunction) {
                            $args = $this->evaluateArguments($node->arguments, $env);
                            return $this->callFunction($method, $rawObj, $args);
                        }
                    }
                }
            }

            // BigInt method calls: look up on BigInt.prototype.
            if ($rawObj instanceof JsBigInt && !$isSymbolCallKey) {
                $bigintProto = JsBigInt::getPrototype();
                if ($bigintProto !== null) {
                    $method = $bigintProto->get($key);
                    if ($method instanceof JsFunction) {
                        $args = $this->evaluateArguments($node->arguments, $env);
                        return $this->callFunction($method, $rawObj, $args);
                    }
                }
            }

            // Symbol method calls: look up on Symbol.prototype, passing primitive as this.
            if ($rawObj instanceof JsSymbol) {
                $symProtoForCall = JsSymbol::getSymbolPrototype();
                if ($symProtoForCall !== null) {
                    $method = $isSymbolCallKey
                        ? $symProtoForCall->getBySymbol($rawCallKey)
                        : $symProtoForCall->get($key);
                    if ($method instanceof JsFunction) {
                        $args = $this->evaluateArguments($node->arguments, $env);
                        return $this->callFunction($method, $rawObj, $args);
                    }
                }
            }

            $obj = $rawObj instanceof JsObject ? $rawObj : TypeConversion::toObject($rawObj);
            $callee = $isSymbolCallKey ? $obj->getBySymbol($rawCallKey) : $obj->get($key);
            $thisValue = $obj;
            $isMethodCall = true;
        } else {
            // Per spec 12.3.4.1 step 4.b: if the callee is an identifier resolved
            // via an Object Environment Record (with statement), thisValue =
            // envRec.WithBaseObject(). Use Environment::get's out-param so the
            // proxy HasProperty trap does not fire a second time.
            if ($node->callee instanceof Identifier && !empty($this->withEnvObjects)) {
                $resolvedWithBase = null;
                $callee = $env->get($node->callee->name, false, $resolvedWithBase);
                if ($resolvedWithBase !== null) {
                    $thisValue = $resolvedWithBase;
                }
            } else {
                $callee = $this->evaluate($node->callee, $env);
            }
        }

        // Optional chain short-circuit: callee resolved to short-circuit sentinel.
        if ($callee instanceof JsOptionalUndefined) {
            return $callee;
        }

        // Optional call: fn?.() where fn is null/undefined.
        if ($node->optional && ($callee instanceof JsNull || $callee instanceof JsUndefined)) {
            return JsOptionalUndefined::instance();
        }

        // Per spec, arguments are evaluated before the callability check.
        $args = $this->evaluateArguments($node->arguments, $env);

        // Proxy apply trap: if the callee is a Proxy wrapping a function, invoke its apply().
        if ($callee instanceof \Phasis\Value\JsProxy) {
            return $callee->apply($thisValue, $args);
        }

        if (!$callee instanceof JsFunction) {
            // Prefer the callee's source-expression form ("obj.x", "[].__proto__")
            // over the value's stringification, matching V8/SpiderMonkey error
            // formatting that test fixtures rely on.
            $desc = $this->formatCalleeForError($node->callee, $callee);
            throw new TypeError("{$desc} is not a function");
        }

        // For simple (non-method) calls, thisValue is always undefined here.
        // executeFunction will convert undefined → globalObject for sloppy-mode functions
        // (OrdinaryCallBindThis step 5: non-strict, thisValue is null/undefined → global).
        // Strict functions (and native built-ins) receive undefined as-is.
        // We do NOT set thisValue = globalObject here so that explicit apply(globalObject)
        // calls pass the global object through without being cleared by executeFunction.

        return $this->callFunction($callee, $thisValue, $args);
    }

    /**
     * Perform a direct eval: parse and execute code in the given environment.
     *
     * Direct eval (called as `eval(code)`) has access to the calling scope's
     * variables and can declare new variables in that scope. If the argument
     * is not a string, it is returned as-is.
     */
    private function performDirectEval(JsValue $arg, Environment $env): JsValue
    {
        if (!$arg instanceof JsString) {
            return $arg;
        }

        // Parse and validate. Any SyntaxError from parsing or validation
        // must be thrown as a JS SyntaxError catchable by JS try/catch.
        $evalStrict = $this->strictMode;
        $directEvalSourceUrl = null;
        try {
            if (strlen($arg->value) > 64 * 1024 * 1024) {
                throw new \Phasis\Exceptions\SyntaxError('Source too large for eval');
            }
            $parser = new \Phasis\Parser\Parser($arg->value);
            // Direct eval inherits strict mode from its surrounding context.
            if ($this->strictMode) {
                $parser->setStrictMode(true);
            }
            // Allow `super` references at parse time when the direct eval is
            // inside a method-like context (method body, class field
            // initializer, or static block). The runtime checks below enforce
            // the spec's distinction between SuperProperty and SuperCall;
            // without this flag the parser would reject super before runtime
            // context inspection. isInMethodLikeContext walks past arrow
            // frames but stops at the first non-arrow function frame so a
            // nested non-arrow function inside a method does not inherit
            // super privileges from the outer method.
            $inMethodLike = $env->isInMethodLikeContext();
            if ($inMethodLike) {
                $parser->setInMethodLike(true);
            }
            $program = $parser->parse();
            $directEvalSourceUrl = $parser->getSourceURL();

            // Validate: return, break, and continue are not allowed at the top
            // level of eval code per spec.
            $this->validateEvalBody($program->body);

            // Per 18.2.1.1.1: super is a SyntaxError in eval unless the
            // direct eval is inside a method (environment has [[HomeObject]]).
            // Per 18.2.1.1.2: super() (SuperCall) is additionally restricted
            // to constructor methods only. Class field initializers have
            // HomeObject so super property access is allowed, but they are
            // NOT constructor methods so SuperCall is forbidden.
            // Checks are transparent through arrow functions since arrows
            // inherit super binding from their enclosing scope.
            $inConstructor = $env->has('[[ActiveFunction]]')
                && !$env->has('[[ClassFieldInitializer]]');
            if (!$inMethodLike && $this->astContainsSuperTransparent($program->body)) {
                throw new \Phasis\Exceptions\SyntaxError("'super' keyword unexpected here");
            }
            if ($inMethodLike && !$inConstructor && $this->astContainsSuperCallTransparent($program->body)) {
                throw new \Phasis\Exceptions\SyntaxError("'super' keyword unexpected here");
            }

            // Per spec 15.1.1: new.target in eval is a SyntaxError when the
            // direct eval is not contained in function code. Arrow functions
            // *inherit* new.target from their enclosing non-arrow function,
            // so eval inside an arrow function is fine as long as some
            // ancestor is a regular function. Walk past arrow frames before
            // deciding.
            if ($this->astContainsNewTarget($program->body)) {
                $nonArrowKind = $env->getEnclosingNonArrowFunctionKind();
                $inClassInit = $env->has('[[ClassFieldInitializer]]');
                if (!$inClassInit && $nonArrowKind === null) {
                    throw new \Phasis\Exceptions\SyntaxError("new.target expression is not allowed here");
                }
            }

            // Per spec AllPrivateNamesValid: every PrivateName reference in
            // eval code must correspond to a private name declared in the
            // enclosing class body. References to undeclared private names
            // are a SyntaxError.
            $this->validateEvalPrivateNames($program->body, $env);

            // Per spec: eval inside a class field initializer must not
            // contain `arguments` (ContainsArguments early error).
            if (
                $env->has('[[ClassFieldInitializer]]')
                && $this->astContainsIdentifier($program->body, 'arguments')
            ) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "'arguments' is not allowed in class field initializer or static initialization block",
                );
            }

            // Detect if the eval code itself enables strict mode. This must
            // happen before the var/lex conflict check because strict eval
            // isolates its var declarations in a separate scope.
            $evalStrict = $this->strictMode || $this->hasUseStrictDirective($program->body);

            // Per EvalDeclarationInstantiation: var-declared names in eval
            // must not conflict with existing lexical (let/const/TDZ) bindings
            // in the enclosing scope chain. This catches cases like:
            //   function f(p = eval("var arguments"), arguments) {}
            // where the TDZ binding for "arguments" (the following parameter)
            // would conflict with the var from eval.
            // Skip this check for strict eval: strict mode eval creates its own
            // variable scope, so var declarations do not leak and cannot conflict
            // with outer lexical bindings.
            if (!$evalStrict) {
                $evalVarNames = $this->collectEvalVarNames($program->body);
                foreach ($evalVarNames as $varName) {
                    if ($env->hasLexicalBindingInScope($varName)) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            "Identifier '{$varName}' has already been declared",
                        );
                    }
                }
                // Per EvalDeclarationInstantiation step 5.a: special handling
                // for "arguments" in eval when the varEnv is a Function
                // Environment Record.
                if (in_array('arguments', $evalVarNames, true)) {
                    $funcKind = $env->getEnclosingFunctionKind();
                    if ($funcKind !== null && $funcKind !== 'arrow') {
                        // Step 5.a.v.1: generators and async generators always
                        // throw SyntaxError for var arguments in eval.
                        if ($funcKind === 'generator' || $funcKind === 'async-generator') {
                            throw new \Phasis\Exceptions\SyntaxError(
                                "Identifier 'arguments' has already been declared",
                            );
                        }
                        // Per spec, var arguments is a conflict when:
                        // 1. arguments is a lexical binding in the enclosing scope, OR
                        // 2. the enclosing function has non-simple parameters
                        //    (defaults, rest, destructuring), because arguments
                        //    is treated as an immutable binding in that case.
                        if (
                            $env->hasLexicalBinding('arguments')
                            || $env->getEnclosingHasNonSimpleParams()
                        ) {
                            throw new \Phasis\Exceptions\SyntaxError(
                                "Identifier 'arguments' has already been declared",
                            );
                        }
                    }
                }
            }
        } catch (\Phasis\Exceptions\SyntaxError $e) {
            $this->throwJsValue($this->phpExceptionToJsValue($e));
        }
        $previousStrictMode = $this->strictMode;

        // In strict mode, additional early errors must be checked after parsing.
        if ($evalStrict) {
            try {
                $this->validateStrictModeRestrictions($program->body);
            } catch (\Phasis\Exceptions\SyntaxError $e) {
                $this->throwJsValue($this->phpExceptionToJsValue($e));
            }
        }
        try {
            $this->validateSelfStrictFunctions($program->body);
        } catch (\Phasis\Exceptions\SyntaxError $e) {
            $this->throwJsValue($this->phpExceptionToJsValue($e));
        }

        if ($evalStrict && !$this->strictMode) {
            $this->strictMode = true;
        }

        // Surface any `//# sourceURL=URL` directive in the eval'd code on
        // Error stack traces produced while it executes. Push here so the
        // matching pop runs even if execution throws.
        if ($directEvalSourceUrl !== null) {
            \Phasis\Engine::pushSourceURL($directEvalSourceUrl);
        }

        try {
            // In strict mode, eval gets its own variable scope so var and
            // function declarations do not leak to the caller.
            // In non-strict mode, the variable environment is the nearest
            // function scope or global scope per EvalDeclarationInstantiation.
            $varEnv = $evalStrict ? $env->createChild() : $env->getVariableEnvironment();

            // For strict eval, pre-declare all var names as own bindings in the
            // child environment. This prevents hoistDeclarations from skipping them
            // when the parent scope has a same-named binding (since has() walks the
            // chain). Without this, strict eval's var declarations would shadow
            // rather than isolate from the outer scope.
            if ($evalStrict && $varEnv !== $env) {
                $strictVarNames = $this->collectEvalVarNames($program->body);
                foreach ($strictVarNames as $vn) {
                    if (!$varEnv->hasOwnBinding($vn)) {
                        $varEnv->defineVar($vn, JsUndefined::instance());
                    }
                }
            }

            // Create a lexical environment for class/let/const TDZ bindings.
            // Per spec step 14: lexEnv = NewDeclarativeEnvironment(ctx.LexicalEnvironment).
            // For non-strict eval, this must be a child of the caller's lexical
            // environment ($env), not varEnv, so that with-scopes and block-scopes
            // from the calling context remain visible during execution.
            $lexEnv = ($evalStrict ? $varEnv : $env)->createChild();

            // Hoist var declarations and function declarations.
            // For eval at global scope, function/var bindings must be
            // configurable (per EvalDeclarationInstantiation step 15/18).
            // Per spec step 16, instantiated function objects close over
            // lexEnv, so hoist function decls AFTER lexEnv exists and use
            // it as the function's [[Environment]].
            $isGlobalEval = !$evalStrict && $varEnv->getLinkedObject() !== null;
            if ($isGlobalEval) {
                $this->hoistEvalGlobalDeclarations($program->body, $varEnv, $lexEnv);
            } elseif (!$evalStrict) {
                // Non-strict, non-global eval: per EvalDeclarationInstantiation,
                // var bindings are created in the caller's variable environment
                // even if a same-named binding exists in an outer scope.
                $this->hoistEvalLocalDeclarations($program->body, $varEnv, $lexEnv);
            } else {
                $this->hoistDeclarations($program->body, $varEnv);
            }

            $this->hoistEvalLexicalDeclarations($program->body, $lexEnv);

            // Execute the parsed program body in the lexical environment.
            $completion = $this->executeBody($program->body, $lexEnv);

            if ($completion->type === CompletionType::Throw) {
                $this->throwJsValue($completion->value);
            }

            return $completion->value;
        } finally {
            $this->strictMode = $previousStrictMode;
            if ($directEvalSourceUrl !== null) {
                \Phasis\Engine::popSourceURL();
            }
        }
    }

    /**
     * Validate that eval code does not contain top-level return, break, or
     * continue statements, which are SyntaxErrors per the spec.
     *
     * @param Node[] $statements
     */
    /**
     * Public entry point for validating a parsed eval program. Throws a
     * SyntaxError for top-level break/continue/return and any nested free
     * break/continue that escapes its target label set. Also rejects
     * new.target, super, and super() at the script top level (these are
     * function-body-only constructs and indirect eval is always script code).
     */
    public function validateEvalProgram(\Phasis\Ast\Program $program): void
    {
        $this->validateEvalBody($program->body);
        // For indirect eval (script context), new.target inside arrow functions
        // also refers to the eval program context since arrows inherit it from
        // their enclosing scope. Walk into arrow bodies to find them.
        if ($this->astContainsNewTargetTransparent($program->body)) {
            throw new \Phasis\Exceptions\SyntaxError(
                'new.target expression is not allowed here'
            );
        }
        // Super is also transparent through arrows: an arrow inherits super
        // binding from its enclosing scope, which for indirect eval is the
        // script (no home object).
        if ($this->astContainsSuperTransparent($program->body)) {
            throw new \Phasis\Exceptions\SyntaxError(
                "'super' keyword unexpected here"
            );
        }
        // Per AllPrivateNamesValid: indirect eval has no enclosing class,
        // so any private name reference must be declared within a class
        // defined inside the eval source.
        foreach ($program->body as $stmt) {
            $this->validatePrivateNamesIn($stmt, [], $this->getGlobalEnv());
        }
    }

    /**
     * @param Node[] $statements
     */
    private function astContainsSuperCallTransparent(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if ($this->nodeContainsSuperCallTransparent($stmt)) {
                return true;
            }
        }
        return false;
    }

    private function nodeContainsSuperCallTransparent(Node $node): bool
    {
        if ($node instanceof CallExpression && $node->callee instanceof Identifier && $node->callee->name === 'super') {
            return true;
        }
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof FunctionExpression
            || $node instanceof ClassDeclaration
            || $node instanceof ClassExpression
        ) {
            return false;
        }
        if ($node instanceof ArrowFunction) {
            if ($node->body instanceof BlockStatement) {
                return $this->astContainsSuperCallTransparent($node->body->body);
            }
            return $this->nodeContainsSuperCallTransparent($node->body);
        }
        foreach ((array) $node as $value) {
            if ($value instanceof Node) {
                if ($this->nodeContainsSuperCallTransparent($value)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && $this->nodeContainsSuperCallTransparent($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * @param Node[] $statements
     */
    private function astContainsSuperTransparent(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if ($this->nodeContainsSuperTransparent($stmt)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Detect a top-level await in module body. Stops at every function,
     * arrow, and class boundary because those open their own (possibly
     * async) closure where await refers to the inner function's
     * suspension, not the module's.
     *
     * @param Node[] $statements
     */
    public function astContainsTopLevelAwait(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if ($this->nodeContainsTopLevelAwait($stmt)) {
                return true;
            }
        }
        return false;
    }

    private function nodeContainsTopLevelAwait(Node $node): bool
    {
        if ($node instanceof AwaitExpression) {
            return true;
        }
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof FunctionExpression
            || $node instanceof ArrowFunction
            || $node instanceof ClassDeclaration
            || $node instanceof ClassExpression
        ) {
            return false;
        }
        foreach ((array) $node as $value) {
            if ($value instanceof Node) {
                if ($this->nodeContainsTopLevelAwait($value)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && $this->nodeContainsTopLevelAwait($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private function nodeContainsSuperTransparent(Node $node): bool
    {
        if ($node instanceof CallExpression && $node->callee instanceof Identifier && $node->callee->name === 'super') {
            return true;
        }
        if ($node instanceof MemberExpression && $node->object instanceof Identifier && $node->object->name === 'super') {
            return true;
        }
        if ($node instanceof Identifier && $node->name === 'super') {
            return true;
        }
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof FunctionExpression
            || $node instanceof ClassDeclaration
            || $node instanceof ClassExpression
        ) {
            return false;
        }
        if ($node instanceof ArrowFunction) {
            if ($node->body instanceof BlockStatement) {
                return $this->astContainsSuperTransparent($node->body->body);
            }
            return $this->nodeContainsSuperTransparent($node->body);
        }
        // Property key positions are IdentifierName, not IdentifierReference;
        // they may legally be `super` without it being a SuperExpression.
        if ($node instanceof Property) {
            if ($node->computed && $this->nodeContainsSuperTransparent($node->key)) {
                return true;
            }
            if ($this->nodeContainsSuperTransparent($node->value)) {
                return true;
            }
            return false;
        }
        if ($node instanceof MemberExpression) {
            if ($this->nodeContainsSuperTransparent($node->object)) {
                return true;
            }
            // The .property side is an IdentifierName, not a reference.
            if ($node->computed && $this->nodeContainsSuperTransparent($node->property)) {
                return true;
            }
            return false;
        }
        foreach ((array) $node as $value) {
            if ($value instanceof Node) {
                if ($this->nodeContainsSuperTransparent($value)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && $this->nodeContainsSuperTransparent($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Like astContainsNewTarget but walks into ArrowFunction bodies (arrows
     * inherit new.target from the enclosing scope). Stops at non-arrow
     * function and class boundaries.
     *
     * @param Node[] $statements
     */
    private function astContainsNewTargetTransparent(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if ($this->nodeContainsNewTargetTransparent($stmt)) {
                return true;
            }
        }
        return false;
    }

    private function nodeContainsNewTargetTransparent(Node $node): bool
    {
        if ($node instanceof Identifier && $node->name === '[[NewTarget]]') {
            return true;
        }
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof FunctionExpression
            || $node instanceof ClassDeclaration
            || $node instanceof ClassExpression
        ) {
            return false;
        }
        if ($node instanceof ArrowFunction) {
            if ($node->body instanceof BlockStatement) {
                return $this->astContainsNewTargetTransparent($node->body->body);
            }
            return $this->nodeContainsNewTargetTransparent($node->body);
        }
        foreach ((array) $node as $value) {
            if ($value instanceof Node) {
                if ($this->nodeContainsNewTargetTransparent($value)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && $this->nodeContainsNewTargetTransparent($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * @param array<mixed> $statements
     */
    private function validateEvalBody(array $statements): void
    {
        foreach ($statements as $stmt) {
            if ($stmt instanceof \Phasis\Ast\Statement\ReturnStatement) {
                throw new \Phasis\Exceptions\SyntaxError('Illegal return statement');
            }
            if ($stmt instanceof BreakStatement) {
                throw new \Phasis\Exceptions\SyntaxError('Illegal break statement');
            }
            if ($stmt instanceof ContinueStatement) {
                throw new \Phasis\Exceptions\SyntaxError('Illegal continue statement');
            }
            $this->validateEvalNoFreeJumps($stmt, []);
            $this->validateNoTopLevelReturn($stmt);
        }
    }

    /**
     * `return` is never allowed at script/eval top level, even inside
     * loops or switches (which validateEvalNoFreeJumps stops at because
     * break/continue would be valid there).
     */
    private function validateNoTopLevelReturn(Node $node): void
    {
        if ($node instanceof \Phasis\Ast\Statement\ReturnStatement) {
            throw new \Phasis\Exceptions\SyntaxError('Illegal return statement');
        }
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
            || $node instanceof ClassDeclaration
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
        ) {
            return;
        }
        if ($node instanceof BlockStatement) {
            foreach ($node->body as $s) {
                $this->validateNoTopLevelReturn($s);
            }
            return;
        }
        if (
            $node instanceof ForStatement
            || $node instanceof ForInStatement
            || $node instanceof ForOfStatement
            || $node instanceof \Phasis\Ast\Statement\WhileStatement
            || $node instanceof DoWhileStatement
            || $node instanceof \Phasis\Ast\Statement\WithStatement
            || $node instanceof LabeledStatement
        ) {
            $this->validateNoTopLevelReturn($node->body);
            return;
        }
        if ($node instanceof IfStatement) {
            $this->validateNoTopLevelReturn($node->consequent);
            if ($node->alternate !== null) {
                $this->validateNoTopLevelReturn($node->alternate);
            }
            return;
        }
        if ($node instanceof \Phasis\Ast\Statement\SwitchStatement) {
            foreach ($node->cases as $case) {
                foreach ($case->consequent as $s) {
                    $this->validateNoTopLevelReturn($s);
                }
            }
            return;
        }
        if ($node instanceof \Phasis\Ast\Statement\TryStatement) {
            $this->validateNoTopLevelReturn($node->block);
            if ($node->handler !== null) {
                $this->validateNoTopLevelReturn($node->handler->body);
            }
            if ($node->finalizer !== null) {
                $this->validateNoTopLevelReturn($node->finalizer);
            }
            return;
        }
    }

    /**
     * Check for break/continue/return that would escape eval code.
     *
     * Recurses into blocks and conditionals but stops at loops, switch
     * statements, and functions (which provide their own targets).
     */
    /**
     * @param string[] $labels Labels currently in scope
     */
    private function validateEvalNoFreeJumps(Node $node, array $labels): void
    {
        if (
            $node instanceof ForStatement
            || $node instanceof ForInStatement
            || $node instanceof ForOfStatement
            || $node instanceof \Phasis\Ast\Statement\WhileStatement
            || $node instanceof DoWhileStatement
            || $node instanceof \Phasis\Ast\Statement\SwitchStatement
            || $node instanceof FunctionDeclaration
        ) {
            return;
        }

        if ($node instanceof BlockStatement) {
            foreach ($node->body as $child) {
                if ($child instanceof \Phasis\Ast\Statement\ReturnStatement) {
                    throw new \Phasis\Exceptions\SyntaxError('Illegal return statement');
                }
                if ($child instanceof BreakStatement) {
                    if ($child->label === null || !in_array($child->label, $labels, true)) {
                        throw new \Phasis\Exceptions\SyntaxError('Illegal break statement');
                    }
                    continue;
                }
                if ($child instanceof ContinueStatement) {
                    if ($child->label === null || !in_array($child->label, $labels, true)) {
                        throw new \Phasis\Exceptions\SyntaxError('Illegal continue statement');
                    }
                    continue;
                }
                $this->validateEvalNoFreeJumps($child, $labels);
            }
            return;
        }

        if ($node instanceof IfStatement) {
            $this->validateEvalNoFreeJumps($node->consequent, $labels);
            if ($node->alternate !== null) {
                $this->validateEvalNoFreeJumps($node->alternate, $labels);
            }
        }

        if ($node instanceof \Phasis\Ast\Statement\TryStatement) {
            $this->validateEvalNoFreeJumps($node->block, $labels);
            if ($node->handler !== null) {
                $this->validateEvalNoFreeJumps($node->handler->body, $labels);
            }
            if ($node->finalizer !== null) {
                $this->validateEvalNoFreeJumps($node->finalizer, $labels);
            }
        }

        if ($node instanceof LabeledStatement) {
            $labels[] = $node->label;
            $this->validateEvalNoFreeJumps($node->body, $labels);
        }
    }

    /**
     * Validate that every PrivateIdentifier reference in eval code is
     * declared in the enclosing class body (per spec AllPrivateNamesValid).
     *
     * @param Node[] $statements
     */
    private function validateEvalPrivateNames(array $statements, Environment $env): void
    {
        // Walk into nested class bodies tracking each body's declared private
        // names. A private reference is valid only if it appears in the
        // declared set of some enclosing class — that includes classes
        // declared inside the eval source, AND classes resolvable via the
        // surrounding lexical scope (env.resolvePrivateName).
        foreach ($statements as $stmt) {
            $this->validatePrivateNamesIn($stmt, [], $env);
        }
    }

    /**
     * Recursive helper for validateEvalPrivateNames. $declaredStack is a list
     * of arrays, each containing the source-level private names declared by
     * an enclosing class. The most recently entered class is at the top.
     *
     * @param list<list<string>> $declaredStack
     */
    private function validatePrivateNamesIn(Node $node, array $declaredStack, Environment $env): void
    {
        if ($node instanceof PrivateIdentifier) {
            foreach ($declaredStack as $declared) {
                if (in_array($node->name, $declared, true)) {
                    return;
                }
            }
            // Not declared in any class lexically inside the eval; check the
            // surrounding lexical scope (the eval's enclosing class).
            $resolved = $env->resolvePrivateName($node->name);
            if ($resolved === $node->name) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Private field '{$node->name}' must be declared in an enclosing class",
                );
            }
            return;
        }
        if (
            $node instanceof \Phasis\Ast\Declaration\ClassDeclaration
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
        ) {
            $declared = [];
            foreach ($node->body as $element) {
                if (
                    ($element instanceof \Phasis\Ast\Expression\ClassMethod
                        || $element instanceof \Phasis\Ast\Expression\ClassProperty)
                    && $element->key instanceof PrivateIdentifier
                ) {
                    $declared[] = $element->key->name;
                }
            }
            $newStack = $declaredStack;
            $newStack[] = $declared;
            // Validate the superClass expression in the OUTER scope: it
            // is evaluated before the class body and cannot see the class's
            // own private names.
            if ($node->superClass !== null) {
                $this->validatePrivateNamesIn($node->superClass, $declaredStack, $env);
            }
            foreach ($node->body as $element) {
                $this->validatePrivateNamesIn($element, $newStack, $env);
            }
            return;
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                $this->validatePrivateNamesIn($value, $declaredStack, $env);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $this->validatePrivateNamesIn($item, $declaredStack, $env);
                    }
                }
            }
        }
    }

    /**
     * Check whether an AST contains a specific identifier reference.
     * Does not recurse into function bodies or class bodies (they create
     * their own scope for the identifier).
     *
     * @param Node[] $statements
     */
    private function astContainsIdentifier(array $statements, string $name): bool
    {
        foreach ($statements as $stmt) {
            if ($this->nodeContainsIdentifier($stmt, $name)) {
                return true;
            }
        }
        return false;
    }

    private function nodeContainsIdentifier(Node $node, string $name): bool
    {
        if ($node instanceof Identifier && $node->name === $name) {
            return true;
        }
        // Do not recurse into non-arrow function bodies or class bodies.
        // Arrow functions are recursed into because they do not create
        // their own `arguments` binding (per spec ContainsArguments).
        if (
            $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Declaration\FunctionDeclaration
            || $node instanceof \Phasis\Ast\Declaration\ClassDeclaration
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
        ) {
            return false;
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                if ($this->nodeContainsIdentifier($value, $name)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && $this->nodeContainsIdentifier($item, $name)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Check whether an AST contains super() call expressions (SuperCall).
     *
     * Does not recurse into nested functions. This is used to enforce
     * the restriction that super() is only allowed in constructors,
     * not in regular methods.
     *
     * @param Node[] $statements
     */
    private function astContainsSuperCall(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if ($this->nodeContainsSuperCall($stmt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check a single AST node for super() call expressions.
     */
    private function nodeContainsSuperCall(Node $node): bool
    {
        // Direct super() call.
        if ($node instanceof CallExpression && $node->callee instanceof Identifier && $node->callee->name === 'super') {
            return true;
        }

        // Stop recursion at function boundaries.
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof FunctionExpression
            || $node instanceof ArrowFunction
            || $node instanceof ClassDeclaration
            || $node instanceof ClassExpression
        ) {
            return false;
        }

        // Recurse into child nodes.
        if ($node instanceof ExpressionStatement) {
            return $this->nodeContainsSuperCall($node->expression);
        }
        if ($node instanceof BlockStatement) {
            return $this->astContainsSuperCall($node->body);
        }
        if ($node instanceof IfStatement) {
            if ($this->nodeContainsSuperCall($node->test)) {
                return true;
            }
            if ($this->nodeContainsSuperCall($node->consequent)) {
                return true;
            }

            return $node->alternate !== null && $this->nodeContainsSuperCall($node->alternate);
        }
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $decl) {
                if ($decl->init !== null && $this->nodeContainsSuperCall($decl->init)) {
                    return true;
                }
            }
        }
        if ($node instanceof CallExpression) {
            if ($this->nodeContainsSuperCall($node->callee)) {
                return true;
            }
            foreach ($node->arguments as $arg) {
                if ($this->nodeContainsSuperCall($arg)) {
                    return true;
                }
            }
        }
        if ($node instanceof AssignmentExpression) {
            return $this->nodeContainsSuperCall($node->left) || $this->nodeContainsSuperCall($node->right);
        }
        if ($node instanceof BinaryExpression) {
            return $this->nodeContainsSuperCall($node->left) || $this->nodeContainsSuperCall($node->right);
        }
        if ($node instanceof MemberExpression) {
            if ($this->nodeContainsSuperCall($node->object)) {
                return true;
            }
            if ($node->computed) {
                return $this->nodeContainsSuperCall($node->property);
            }
            return false;
        }
        if ($node instanceof \Phasis\Ast\Expression\SequenceExpression) {
            foreach ($node->expressions as $expr) {
                if ($this->nodeContainsSuperCall($expr)) {
                    return true;
                }
            }
        }
        if ($node instanceof \Phasis\Ast\Expression\ConditionalExpression) {
            return $this->nodeContainsSuperCall($node->test)
                || $this->nodeContainsSuperCall($node->consequent)
                || $this->nodeContainsSuperCall($node->alternate);
        }

        return false;
    }

    /**
     * Check whether an AST contains super property or super call references.
     *
     * Does not recurse into nested functions (they have their own [[HomeObject]]).
     *
     * @param Node[] $statements
     */
    private function astContainsSuper(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if ($this->nodeContainsSuper($stmt)) {
                return true;
            }
        }

        return false;
    }

    private function nodeContainsSuper(Node $node): bool
    {
        // Direct super references.
        if ($node instanceof CallExpression && $node->callee instanceof Identifier && $node->callee->name === 'super') {
            return true;
        }
        if ($node instanceof MemberExpression && $node->object instanceof Identifier && $node->object->name === 'super') {
            return true;
        }
        // Check if the node has a "super" keyword token directly (for super.prop, super[expr]).
        if ($node instanceof Identifier && $node->name === 'super') {
            return true;
        }

        // Stop recursion at function boundaries (they have their own [[HomeObject]]).
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof FunctionExpression
            || $node instanceof ArrowFunction
            || $node instanceof ClassDeclaration
            || $node instanceof ClassExpression
        ) {
            return false;
        }

        // Recurse into child nodes.
        if ($node instanceof ExpressionStatement) {
            return $this->nodeContainsSuper($node->expression);
        }
        if ($node instanceof BlockStatement) {
            return $this->astContainsSuper($node->body);
        }
        if ($node instanceof IfStatement) {
            if ($this->nodeContainsSuper($node->test)) {
                return true;
            }
            if ($this->nodeContainsSuper($node->consequent)) {
                return true;
            }

            return $node->alternate !== null && $this->nodeContainsSuper($node->alternate);
        }
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $decl) {
                if ($decl->init !== null && $this->nodeContainsSuper($decl->init)) {
                    return true;
                }
            }
        }
        if ($node instanceof CallExpression) {
            if ($this->nodeContainsSuper($node->callee)) {
                return true;
            }
            foreach ($node->arguments as $arg) {
                if ($this->nodeContainsSuper($arg)) {
                    return true;
                }
            }
        }
        if ($node instanceof MemberExpression) {
            return $this->nodeContainsSuper($node->object);
        }
        if ($node instanceof AssignmentExpression) {
            return $this->nodeContainsSuper($node->left) || $this->nodeContainsSuper($node->right);
        }
        if ($node instanceof BinaryExpression) {
            return $this->nodeContainsSuper($node->left) || $this->nodeContainsSuper($node->right);
        }
        if ($node instanceof TryStatement) {
            if ($this->astContainsSuper($node->block->body)) {
                return true;
            }
            if ($node->handler !== null && $this->astContainsSuper($node->handler->body->body)) {
                return true;
            }

            return $node->finalizer !== null && $this->astContainsSuper($node->finalizer->body);
        }

        return false;
    }

    /**
     * Validate strict-mode early errors in parsed eval code.
     *
     * Per spec 13.15.1, catch parameters named 'eval' or 'arguments'
     * are SyntaxErrors in strict mode.
     *
     * @param Node[] $statements
     */
    private function validateStrictModeRestrictions(array $statements): void
    {
        foreach ($statements as $stmt) {
            $this->validateStrictModeNode($stmt);
        }
    }

    /** Strict-mode future reserved words per ES 12.1.1. */
    private const STRICT_RESERVED_WORDS = [
        'implements', 'interface', 'let', 'package',
        'private', 'protected', 'public', 'static', 'yield',
    ];

    private function isStrictReservedWord(string $name): bool
    {
        return \in_array($name, self::STRICT_RESERVED_WORDS, true);
    }

    private function validateStrictModeNode(Node $node): void
    {
        // 'with' statement is forbidden in strict mode.
        if ($node instanceof WithStatement) {
            throw new \Phasis\Exceptions\SyntaxError('Strict mode code may not include a with statement');
        }

        // Variable declarations must not use strict-mode reserved words as binding names.
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $decl) {
                $this->checkStrictBindingNames($decl->id);
                if ($decl->init !== null) {
                    $this->validateStrictExpressions($decl->init);
                }
            }
        }

        // Function declarations must not use strict-mode reserved words as the function name.
        if ($node instanceof FunctionDeclaration && $node->id !== null) {
            if (
                $this->isStrictReservedWord($node->id->name)
                || $node->id->name === 'eval'
                || $node->id->name === 'arguments'
            ) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Unexpected strict mode reserved word '{$node->id->name}'",
                );
            }
        }

        // Expression statements may contain assignments to reserved words.
        if ($node instanceof ExpressionStatement) {
            $this->checkStrictExpressionNode($node->expression);
            // Recurse into function expressions within the expression.
            $this->validateStrictExpressions($node->expression);
        }

        // Function declarations: validate body (functions inherit strict mode).
        if ($node instanceof FunctionDeclaration) {
            if ($node->body instanceof BlockStatement) {
                foreach ($node->body->body as $child) {
                    $this->validateStrictModeNode($child);
                }
            }
            foreach ($node->params as $param) {
                $this->checkStrictBindingNames($param);
            }
            $this->checkDuplicateParams($node->params);
        }

        if ($node instanceof TryStatement) {
            if ($node->handler !== null && $node->handler->param !== null) {
                $this->checkStrictCatchParam($node->handler->param);
            }
            $this->validateStrictModeNode($node->block);
            if ($node->handler !== null) {
                $this->validateStrictModeNode($node->handler->body);
            }
            if ($node->finalizer !== null) {
                $this->validateStrictModeNode($node->finalizer);
            }
            return;
        }

        // Recurse into child statements (but not into function bodies,
        // which are their own strict-mode contexts).
        if ($node instanceof BlockStatement) {
            foreach ($node->body as $child) {
                $this->validateStrictModeNode($child);
            }
        } elseif ($node instanceof IfStatement) {
            $this->validateStrictModeNode($node->consequent);
            if ($node->alternate !== null) {
                $this->validateStrictModeNode($node->alternate);
            }
        } elseif ($node instanceof ForStatement || $node instanceof ForInStatement || $node instanceof ForOfStatement) {
            $this->validateStrictModeNode($node->body);
        } elseif ($node instanceof WhileStatement || $node instanceof DoWhileStatement) {
            $this->validateStrictModeNode($node->body);
        } elseif ($node instanceof LabeledStatement) {
            $this->validateStrictModeNode($node->body);
        } elseif ($node instanceof SwitchStatement) {
            foreach ($node->cases as $case) {
                foreach ($case->consequent as $child) {
                    $this->validateStrictModeNode($child);
                }
            }
        }
    }

    private function checkStrictCatchParam(Node $param): void
    {
        if ($param instanceof Identifier) {
            if ($param->name === 'eval' || $param->name === 'arguments') {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Binding 'eval' or 'arguments' in strict mode catch is not allowed",
                );
            }
            if ($this->isStrictReservedWord($param->name)) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Unexpected strict mode reserved word '{$param->name}'",
                );
            }
        }
    }

    /**
     * Check that binding names in a pattern do not use strict-mode reserved words.
     */
    private function checkStrictBindingNames(Node $node): void
    {
        if ($node instanceof Identifier) {
            if (
                $this->isStrictReservedWord($node->name)
                || $node->name === 'eval'
                || $node->name === 'arguments'
            ) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Unexpected strict mode reserved word '{$node->name}'",
                );
            }
        } elseif ($node instanceof ArrayPattern) {
            foreach ($node->elements as $el) {
                if ($el !== null) {
                    $this->checkStrictBindingNames($el);
                }
            }
        } elseif ($node instanceof ObjectPattern) {
            foreach ($node->properties as $prop) {
                if ($prop instanceof AssignmentProperty) {
                    $this->checkStrictBindingNames($prop->value);
                } elseif ($prop instanceof RestElement) {
                    $this->checkStrictBindingNames($prop->argument);
                }
            }
        } elseif ($node instanceof RestElement) {
            $this->checkStrictBindingNames($node->argument);
        } elseif ($node instanceof AssignmentPattern) {
            $this->checkStrictBindingNames($node->left);
        }
    }

    /**
     * Check that expressions do not assign to strict-mode reserved words.
     */
    private function checkStrictExpressionNode(Node $node): void
    {
        if ($node instanceof AssignmentExpression && $node->left instanceof Identifier) {
            if (
                $this->isStrictReservedWord($node->left->name)
                || $node->left->name === 'eval'
                || $node->left->name === 'arguments'
            ) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Assignment to eval or arguments is not allowed in strict mode",
                );
            }
        }
    }

    /**
     * Validate strict-mode restrictions in expressions (function expressions, arrow funcs, etc.)
     * that are nested inside strict code and therefore also strict.
     */
    private function validateStrictExpressions(Node $node): void
    {
        if ($node instanceof FunctionExpression || $node instanceof ArrowFunction) {
            // Function params must not use restricted names.
            $params = $node instanceof FunctionExpression ? $node->params : $node->params;
            foreach ($params as $param) {
                $this->checkStrictBindingNames($param);
            }
            $this->checkDuplicateParams($params);
            // Validate body statements.
            if ($node instanceof FunctionExpression && $node->body instanceof BlockStatement) {
                $body = $node->body->body;
            } elseif ($node instanceof ArrowFunction && $node->body instanceof BlockStatement) {
                $body = $node->body->body;
            } else {
                $body = [];
            }
            foreach ($body as $child) {
                $this->validateStrictModeNode($child);
            }
            // Check function name.
            if ($node instanceof FunctionExpression && $node->name !== null) {
                if (
                    $this->isStrictReservedWord($node->name)
                    || $node->name === 'eval'
                    || $node->name === 'arguments'
                ) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Unexpected eval or arguments in strict mode",
                    );
                }
            }
            return;
        }
        // Recurse into sub-expressions to find nested function expressions.
        if ($node instanceof CallExpression) {
            $this->validateStrictExpressions($node->callee);
            foreach ($node->arguments as $arg) {
                $this->validateStrictExpressions($arg);
            }
        } elseif ($node instanceof SequenceExpression) {
            foreach ($node->expressions as $expr) {
                $this->validateStrictExpressions($expr);
            }
        } elseif ($node instanceof AssignmentExpression) {
            $this->validateStrictExpressions($node->right);
        } elseif ($node instanceof BinaryExpression) {
            $this->validateStrictExpressions($node->left);
            $this->validateStrictExpressions($node->right);
        }
    }

    /** @param Node[] $params */
    private function checkDuplicateParams(array $params): void
    {
        $names = [];
        foreach ($params as $param) {
            $this->collectParamNames($param, $names);
        }
        $seen = [];
        foreach ($names as $name) {
            if (isset($seen[$name])) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Duplicate parameter name not allowed in this context",
                );
            }
            $seen[$name] = true;
        }
    }

    /** @param string[] &$names */
    private function collectParamNames(Node $node, array &$names): void
    {
        if ($node instanceof Identifier) {
            $names[] = $node->name;
        } elseif ($node instanceof AssignmentPattern) {
            $this->collectParamNames($node->left, $names);
        } elseif ($node instanceof RestElement) {
            $this->collectParamNames($node->argument, $names);
        } elseif ($node instanceof ArrayPattern) {
            foreach ($node->elements as $el) {
                if ($el !== null) {
                    $this->collectParamNames($el, $names);
                }
            }
        } elseif ($node instanceof ObjectPattern) {
            foreach ($node->properties as $prop) {
                if ($prop instanceof AssignmentProperty) {
                    $this->collectParamNames($prop->value, $names);
                } elseif ($prop instanceof RestElement) {
                    $this->collectParamNames($prop->argument, $names);
                }
            }
        }
    }

    /** @param Node[] $statements */
    private function validateSelfStrictFunctions(array $statements): void
    {
        foreach ($statements as $stmt) {
            $this->findAndValidateSelfStrictFunction($stmt);
        }
    }

    private function findAndValidateSelfStrictFunction(Node $node): void
    {
        if ($node instanceof FunctionDeclaration && $node->body instanceof BlockStatement) {
            if ($this->hasUseStrictDirective($node->body->body)) {
                foreach ($node->params as $param) {
                    $this->checkStrictBindingNames($param);
                }
                $this->checkDuplicateParams($node->params);
                if (
                    $node->id !== null && (
                    $this->isStrictReservedWord($node->id->name)
                    || $node->id->name === 'eval'
                    || $node->id->name === 'arguments')
                ) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Unexpected eval or arguments in strict mode",
                    );
                }
                $this->validateStrictModeRestrictions($node->body->body);
            }
            foreach ($node->body->body as $child) {
                $this->findAndValidateSelfStrictFunction($child);
            }
            return;
        }
        if ($node instanceof FunctionExpression && $node->body instanceof BlockStatement) {
            if ($this->hasUseStrictDirective($node->body->body)) {
                foreach ($node->params as $param) {
                    $this->checkStrictBindingNames($param);
                }
                $this->checkDuplicateParams($node->params);
                if ($node->name !== null) {
                    if (
                        $this->isStrictReservedWord($node->name)
                        || $node->name === 'eval'
                        || $node->name === 'arguments'
                    ) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            "Unexpected eval or arguments in strict mode",
                        );
                    }
                }
                $this->validateStrictModeRestrictions($node->body->body);
            }
            foreach ($node->body->body as $child) {
                $this->findAndValidateSelfStrictFunction($child);
            }
            return;
        }
        if ($node instanceof ExpressionStatement) {
            $this->findAndValidateSelfStrictFunction($node->expression);
        } elseif ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $decl) {
                if ($decl->init !== null) {
                    $this->findAndValidateSelfStrictFunction($decl->init);
                }
            }
        } elseif ($node instanceof BlockStatement) {
            foreach ($node->body as $child) {
                $this->findAndValidateSelfStrictFunction($child);
            }
        } elseif ($node instanceof IfStatement) {
            $this->findAndValidateSelfStrictFunction($node->consequent);
            if ($node->alternate !== null) {
                $this->findAndValidateSelfStrictFunction($node->alternate);
            }
        } elseif ($node instanceof AssignmentExpression) {
            $this->findAndValidateSelfStrictFunction($node->right);
        }
    }

    /**
     * Collect all var-declared names from eval code's top-level statements.
     * Does not descend into function/class bodies (they create their own scope).
     *
     * @param Node[] $statements
     * @return list<string>
     */
    private function collectEvalVarNames(array $statements): array
    {
        $names = [];
        foreach ($statements as $stmt) {
            $this->collectVarNamesFromNode($stmt, $names);
        }
        return $names;
    }

    /**
     * Recursively collect var-declared names from a node.
     * Stops at function/class boundaries.
     *
     * @param list<string> $names collected var names (passed by reference)
     */
    private function collectVarNamesFromNode(Node $node, array &$names): void
    {
        if ($node instanceof VariableDeclaration && $node->kind === 'var') {
            foreach ($node->declarations as $decl) {
                foreach ($this->patternBoundNames($decl->id) as $n) {
                    $names[] = $n;
                }
            }
            return;
        }
        if ($node instanceof FunctionDeclaration) {
            if ($node->id !== null) {
                $names[] = $node->id->name;
            }
            return;
        }

        // Stop at function/class expression boundaries.
        if (
            $node instanceof FunctionExpression
            || $node instanceof ArrowFunction
            || $node instanceof ClassDeclaration
            || $node instanceof ClassExpression
        ) {
            return;
        }

        // Recurse into compound statements.
        if ($node instanceof BlockStatement) {
            foreach ($node->body as $child) {
                $this->collectVarNamesFromNode($child, $names);
            }
        } elseif ($node instanceof IfStatement) {
            $this->collectVarNamesFromNode($node->consequent, $names);
            if ($node->alternate !== null) {
                $this->collectVarNamesFromNode($node->alternate, $names);
            }
        } elseif ($node instanceof ForStatement) {
            if ($node->init instanceof VariableDeclaration && $node->init->kind === 'var') {
                foreach ($node->init->declarations as $decl) {
                    foreach ($this->patternBoundNames($decl->id) as $n) {
                        $names[] = $n;
                    }
                }
            }
            $this->collectVarNamesFromNode($node->body, $names);
        } elseif ($node instanceof ForInStatement || $node instanceof ForOfStatement) {
            if ($node->left instanceof VariableDeclaration && $node->left->kind === 'var') {
                foreach ($node->left->declarations as $decl) {
                    foreach ($this->patternBoundNames($decl->id) as $n) {
                        $names[] = $n;
                    }
                }
            }
            $this->collectVarNamesFromNode($node->body, $names);
        } elseif ($node instanceof WhileStatement || $node instanceof DoWhileStatement) {
            $this->collectVarNamesFromNode($node->body, $names);
        } elseif ($node instanceof SwitchStatement) {
            foreach ($node->cases as $case) {
                foreach ($case->consequent as $child) {
                    $this->collectVarNamesFromNode($child, $names);
                }
            }
        } elseif ($node instanceof TryStatement) {
            $this->collectVarNamesFromNode($node->block, $names);
            if ($node->handler !== null) {
                $this->collectVarNamesFromNode($node->handler->body, $names);
            }
            if ($node->finalizer !== null) {
                $this->collectVarNamesFromNode($node->finalizer, $names);
            }
        } elseif ($node instanceof LabeledStatement) {
            $this->collectVarNamesFromNode($node->body, $names);
        } elseif ($node instanceof WithStatement) {
            $this->collectVarNamesFromNode($node->body, $names);
        }
    }

    /**
     * Check if the eval code contains new.target (represented as [[NewTarget]] identifier).
     * Per spec, new.target in eval is SyntaxError unless the eval is contained in
     * function code that is not the function code of an ArrowFunction.
     *
     * @param Node[] $statements
     */
    private function astContainsNewTarget(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if ($this->nodeContainsNewTarget($stmt)) {
                return true;
            }
        }
        return false;
    }

    private function nodeContainsNewTarget(Node $node): bool
    {
        if ($node instanceof Identifier && $node->name === '[[NewTarget]]') {
            return true;
        }

        // Walk into arrow functions — they inherit new.target from the
        // enclosing non-arrow function, so a new.target reference inside an
        // arrow is also gated on the eval's enclosing context.
        if ($node instanceof ArrowFunction) {
            $body = $node->body;
            if ($body instanceof BlockStatement) {
                return $this->astContainsNewTarget($body->body);
            }
            return $this->nodeContainsNewTarget($body);
        }

        // Stop at non-arrow function/class boundaries.
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof FunctionExpression
            || $node instanceof ClassDeclaration
            || $node instanceof ClassExpression
        ) {
            return false;
        }

        if ($node instanceof ExpressionStatement) {
            return $this->nodeContainsNewTarget($node->expression);
        }
        if ($node instanceof BlockStatement) {
            return $this->astContainsNewTarget($node->body);
        }
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $decl) {
                if ($decl->init !== null && $this->nodeContainsNewTarget($decl->init)) {
                    return true;
                }
            }
        }
        if ($node instanceof CallExpression) {
            if ($this->nodeContainsNewTarget($node->callee)) {
                return true;
            }
            foreach ($node->arguments as $arg) {
                if ($this->nodeContainsNewTarget($arg)) {
                    return true;
                }
            }
        }
        if ($node instanceof MemberExpression) {
            return $this->nodeContainsNewTarget($node->object) || $this->nodeContainsNewTarget($node->property);
        }
        if ($node instanceof AssignmentExpression) {
            return $this->nodeContainsNewTarget($node->left) || $this->nodeContainsNewTarget($node->right);
        }
        if ($node instanceof BinaryExpression) {
            return $this->nodeContainsNewTarget($node->left) || $this->nodeContainsNewTarget($node->right);
        }
        if ($node instanceof IfStatement) {
            if ($this->nodeContainsNewTarget($node->test)) {
                return true;
            }
            if ($this->nodeContainsNewTarget($node->consequent)) {
                return true;
            }
            return $node->alternate !== null && $this->nodeContainsNewTarget($node->alternate);
        }
        if ($node instanceof TryStatement) {
            if ($this->astContainsNewTarget($node->block->body)) {
                return true;
            }
            if ($node->handler !== null && $this->astContainsNewTarget($node->handler->body->body)) {
                return true;
            }
            return $node->finalizer !== null && $this->astContainsNewTarget($node->finalizer->body);
        }

        return false;
    }

    /**
     * Hoist class and let/const declarations in eval code as TDZ bindings.
     *
     * Per spec, class declarations in eval create bindings that throw
     * ReferenceError when accessed before initialization (TDZ).
     *
     * @param Node[] $statements
     */
    private function hoistEvalLexicalDeclarations(array $statements, Environment $env): void
    {
        foreach ($statements as $stmt) {
            // Unwrap `export let/const/class ...` to hoist the inner TDZ binding.
            if ($stmt instanceof ExportDeclaration && $stmt->declaration !== null) {
                $stmt = $stmt->declaration;
            }
            if ($stmt instanceof ClassDeclaration && $stmt->id !== null) {
                $env->declareLet($stmt->id->name);
            }
            if (
                $stmt instanceof VariableDeclaration && (
                $stmt->kind === 'let' || $stmt->kind === 'const'
                || $stmt->kind === 'using' || $stmt->kind === 'await using'
                )
            ) {
                $isConst = $stmt->kind !== 'let';
                foreach ($stmt->declarations as $decl) {
                    $this->declarePatternTdz($decl->id, $env, $isConst);
                }
            }
        }
    }

    /**
     * Declare a binding pattern as TDZ bindings.
     */
    private function declarePatternTdz(Node $pattern, Environment $env, bool $isConst): void
    {
        if ($pattern instanceof Identifier) {
            if ($isConst) {
                $env->declareConst($pattern->name);
            } else {
                $env->declareLet($pattern->name);
            }
        } elseif ($pattern instanceof ArrayPattern) {
            foreach ($pattern->elements as $elem) {
                if ($elem !== null) {
                    $this->declarePatternTdz($elem, $env, $isConst);
                }
            }
        } elseif ($pattern instanceof ObjectPattern) {
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof AssignmentProperty) {
                    $this->declarePatternTdz($prop->value, $env, $isConst);
                } elseif ($prop instanceof RestElement) {
                    $this->declarePatternTdz($prop->argument, $env, $isConst);
                }
            }
        } elseif ($pattern instanceof AssignmentPattern) {
            $this->declarePatternTdz($pattern->left, $env, $isConst);
        } elseif ($pattern instanceof RestElement) {
            $this->declarePatternTdz($pattern->argument, $env, $isConst);
        }
    }

    private function evalNewExpression(NewExpression $node, Environment $env): JsValue
    {
        $callee = $this->evaluate($node->callee, $env);

        // Per spec EvaluateNew: arguments are evaluated BEFORE the IsConstructor check (step 6 before step 7).
        $args = $this->evaluateArguments($node->arguments, $env);

        // Proxy construct trap: if the callee is a Proxy, invoke its construct().
        if ($callee instanceof \Phasis\Value\JsProxy) {
            return $callee->construct($args, $callee);
        }

        if (!$callee instanceof JsFunction || !$callee->isConstructable()) {
            throw new TypeError(TypeConversion::toString($callee) . ' is not a constructor');
        }

        // Create a new object with the constructor's prototype
        $proto = $callee->get('prototype');
        $newObj = new JsObject($proto instanceof JsObject ? $proto : null);
        // Mark as new.target so constructors can detect new vs call.
        // Use a non-enumerable, non-configurable property so it does not leak into iteration.
        $newObj->defineOwnProperty(
            '[[NewTarget]]',
            \Phasis\Object\PropertyDescriptor::data($callee, false, false, false),
        );

        // For base class constructors, initialize instance fields before
        // calling the constructor body, by running them on the new object.
        // For derived constructors, field initializers run after super().
        if ($callee->isClassConstructor() && !$callee->isDerivedConstructor()) {
            $this->initializeInstanceFields($callee, $newObj, $env);
        }

        $result = $this->callFunction($callee, $newObj, $args);

        // Per spec §10.2.2 [[Construct]]:
        // - If the constructor returned an Object, use that.
        // - If derived class constructor returned a non-Object non-undefined value, throw TypeError.
        // - Otherwise return newObj.
        // Clean up [[NewTarget]] internal marker so it does not leak to user code.
        $ctorId = spl_object_id($callee);
        if ($result instanceof JsObject) {
            // For derived class constructors whose default constructor is a
            // native callable (bypasses the AST-level super() path), instance
            // fields and private methods were never initialized by the body
            // itself. The post-call fallback only applies to native derived
            // constructors: user-written derived constructors run their fields
            // at the AST super() site (line 1442). Running it here for AST
            // constructors would double-init. Per spec PrivateMethodOrAccessorAdd,
            // a subsequent install of a private method on the same object
            // throws TypeError at install time, so we do not rely on
            // areFieldsInitialized here.
            if (
                $callee->isDerivedConstructor()
                && $callee->isNative()
                && ($callee->getPrivateMethodEntries() || $callee->getInstanceFieldInitializers())
            ) {
                $this->initializeInstanceFields($callee, $result, $env);
            }
            $result->forceDelete('[[NewTarget]]');
            return $result;
        }
        if ($callee->isDerivedConstructor() && !$result instanceof JsUndefined) {
            throw new TypeError('Derived constructors may only return object or undefined');
        }
        // For derived class constructors that returned undefined (falling
        // through to use $newObj), only native default constructors need
        // post-call init here; user-written constructors already handled it
        // at the AST super() site.
        if (
            $callee->isDerivedConstructor()
            && $callee->isNative()
            && ($callee->getPrivateMethodEntries() || $callee->getInstanceFieldInitializers())
        ) {
            $this->initializeInstanceFields($callee, $newObj, $env);
        }
        $newObj->forceDelete('[[NewTarget]]');
        return $newObj;
    }

    /**
     * Initialize instance fields and private methods on a newly created object.
     * Per spec, field initializers are evaluated with `this` bound to the instance.
     */
    /**
     * Spec: CopyDataProperties for object rest destructuring. Iterates
     * OrdinaryOwnPropertyKeys in order (integer indices, then string keys,
     * then symbols) and copies enumerable own properties not already consumed
     * by preceding destructuring properties.
     *
     * @param array<int,string|int> $usedStringKeys
     * @param array<int,bool> $usedSymbolIds
     */
    private function copyRestDataProperties(
        JsObject $source,
        JsObject $target,
        array $usedStringKeys,
        array $usedSymbolIds,
    ): void {
        foreach ($source->ordinaryOwnPropertyKeys() as $propKey) {
            if ($propKey instanceof JsSymbol) {
                if (isset($usedSymbolIds[$propKey->getId()])) {
                    continue;
                }
                $desc = $source->getSymbolPropertyDescriptor($propKey);
                if ($desc === null || $desc->enumerable === false) {
                    continue;
                }
                $target->setBySymbol($propKey, $source->getBySymbol($propKey));
                continue;
            }
            if (!$propKey instanceof JsString) {
                continue;
            }
            $strKey = $propKey->value;
            if (in_array($strKey, $usedStringKeys, true)) {
                continue;
            }
            $desc = $source->getOwnPropertyDescriptor($strKey);
            if ($desc === null || $desc->enumerable === false) {
                continue;
            }
            $target->defineOwnProperty($strKey, PropertyDescriptor::data($source->get($strKey)));
        }
    }

    /**
     * Classify and register an auto-accessor (`accessor name = init`) class element.
     *
     * Synthesises a getter / setter pair backed by a unique storage slot and
     * appends them to the appropriate per-class install lists in source order.
     * For non-static accessors, also registers an instance field initializer
     * that primes the storage slot on each new instance.
     *
     * @param array<int,JsValue> $computedKeys
     * @param list<array{0: string, 1: JsValue, 2: string, 3: ?JsSymbol}> $instanceMethods
     * @param list<array{0: string, 1: JsValue, 2: string, 3: ?JsSymbol}> $staticMethods
     * @param list<array{0: string, 1: JsValue, 2: string}> $privateInstanceMethods
     * @param list<array{0: string, 1: JsValue, 2: string}> $privateStaticMethods
     * @param list<array{0: string, 1: ?Node}> $instanceAutoAccessorInits
     * @param list<array{0: string, 1: ?Node, 2: bool}> $staticAutoAccessorInits
     * @param array<string,string> $privateNameMap
     */
    private function collectAutoAccessor(
        ClassProperty $element,
        int $i,
        Environment $privateEnv,
        array $privateNameMap,
        array $computedKeys,
        array &$instanceMethods,
        array &$staticMethods,
        array &$privateInstanceMethods,
        array &$privateStaticMethods,
        array &$instanceAutoAccessorInits,
        array &$staticAutoAccessorInits,
    ): void {
        $isPrivate = $element->key instanceof PrivateIdentifier;
        $storageKey = '[[AutoAccessor:' . self::$nextAutoAccessorId++ . ']]';

        // Resolve the public-facing key: string, symbol, or branded private name.
        $symbolKey = null;
        $publicKey = '';
        $displayName = '';
        if ($isPrivate) {
            $publicKey = $privateNameMap[$element->key->name] ?? $element->key->name;
            $displayName = $element->key->name;
        } elseif (isset($computedKeys[$i])) {
            $keyVal = TypeConversion::toPropertyKey($computedKeys[$i]);
            if ($keyVal instanceof \Phasis\Value\JsSymbol) {
                $symbolKey = $keyVal;
                $publicKey = '';
                $desc = $keyVal->getDescription();
                $displayName = $desc !== null ? "[{$desc}]" : '';
            } else {
                $publicKey = TypeConversion::toString($keyVal);
                $displayName = $publicKey;
            }
        } else {
            $publicKey = $element->key instanceof Identifier
                ? $element->key->name
                : TypeConversion::toString($this->evaluate($element->key, $privateEnv));
            $displayName = $publicKey;
        }

        [$getter, $setter] = $this->createPublicAutoAccessorPair($storageKey, $displayName);

        if ($isPrivate) {
            // Private auto-accessor: install a [get, set] private accessor
            // pair (mirror of `get #x()` + `set #x()`). Storage lives as a
            // hidden `[[AutoAccessor:N]]` data slot on the host object so
            // get/set don't recurse through the private brand.
            if ($element->static) {
                $privateStaticMethods[] = [$publicKey, $getter, 'get'];
                $privateStaticMethods[] = [$publicKey, $setter, 'set'];
                $staticAutoAccessorInits[] = [$storageKey, $element->value, true];
            } else {
                $privateInstanceMethods[] = [$publicKey, $getter, 'get'];
                $privateInstanceMethods[] = [$publicKey, $setter, 'set'];
                $instanceAutoAccessorInits[] = [$storageKey, $element->value];
            }
        } else {
            // Public auto-accessor: route through the regular instance/static
            // method installation by appending get and set entries in source
            // order so they merge with user-declared get/set descriptors.
            if ($element->static) {
                $staticMethods[] = [$publicKey, $getter, 'get', $symbolKey];
                $staticMethods[] = [$publicKey, $setter, 'set', $symbolKey];
                $staticAutoAccessorInits[] = [$storageKey, $element->value, false];
            } else {
                $instanceMethods[] = [$publicKey, $getter, 'get', $symbolKey];
                $instanceMethods[] = [$publicKey, $setter, 'set', $symbolKey];
                $instanceAutoAccessorInits[] = [$storageKey, $element->value];
            }
        }
    }

    /**
     * Build the getter / setter pair for a public auto-accessor (`accessor x = init`).
     *
     * Per ES2023 decorators §15.7.3, the synthesized getter and setter read and
     * write a hidden storage slot on the receiver. The brand check throws
     * TypeError when the receiver is missing the slot (e.g. a derived class
     * inheriting a static accessor without redeclaring it).
     *
     * @return array{0: JsFunction, 1: JsFunction}
     */
    private function createPublicAutoAccessorPair(string $storageKey, string $displayName): array
    {
        $getter = JsFunction::fromCallable(
            'get ' . $displayName,
            function (JsValue $thisVal, array $args) use ($storageKey, $displayName): JsValue {
                if (!$thisVal instanceof JsObject || !$thisVal->hasOwnProperty($storageKey)) {
                    throw new TypeError(
                        "Cannot read auto-accessor '{$displayName}' from an incompatible receiver",
                    );
                }
                return $thisVal->get($storageKey);
            },
        )->setNonConstructable();
        $setter = JsFunction::fromCallable(
            'set ' . $displayName,
            function (JsValue $thisVal, array $args) use ($storageKey, $displayName): JsValue {
                if (!$thisVal instanceof JsObject || !$thisVal->hasOwnProperty($storageKey)) {
                    throw new TypeError(
                        "Cannot write auto-accessor '{$displayName}' on an incompatible receiver",
                    );
                }
                $thisVal->set($storageKey, $args[0] ?? JsUndefined::instance(), false);
                return JsUndefined::instance();
            },
            1,
        )->setNonConstructable();
        // Auto-accessor get/set don't have a .prototype slot.
        $getter->forceDelete('prototype');
        $setter->forceDelete('prototype');
        return [$getter, $setter];
    }

    public function initializeInstanceFields(JsFunction $ctor, JsObject $instance, Environment $env): void
    {
        $ctorId = spl_object_id($ctor);
        $instance->markFieldsInitialized($ctorId);

        // Install private instance methods first (they are available in field initializers).
        // Per PrivateMethodOrAccessorAdd, installing a second time on the
        // same object is a TypeError. Track which keys were installed in
        // this pass so we can still merge a same-class getter with a
        // same-class setter without false double-init errors.
        $installedThisPass = [];
        foreach ($ctor->getPrivateMethodEntries() as [$name, $fn, $kind]) {
            if ($kind === 'get' || $kind === 'set') {
                $alreadyHad = $instance->hasPrivateField($name);
                $existing = $alreadyHad ? $instance->getPrivateFieldRaw($name) : null;
                $mergingInPass = isset($installedThisPass[$name]);
                if ($alreadyHad && !$mergingInPass) {
                    $displayName = preg_replace('/@\d+$/', '', $name);
                    throw new \Phasis\Exceptions\TypeError(
                        "Cannot initialize {$displayName} twice on the same object",
                    );
                }
                if ($kind === 'get') {
                    $setter = is_array($existing) ? $existing[1] : null;
                    $instance->setPrivateAccessor($name, [$fn, $setter]);
                } else {
                    $getter = is_array($existing) ? $existing[0] : null;
                    $instance->setPrivateAccessor($name, [$getter, $fn]);
                }
                $installedThisPass[$name] = true;
            } else {
                $instance->setPrivateMethod($name, $fn);
                $installedThisPass[$name] = true;
            }
        }

        // Run field initializers in order. Use the constructor's private
        // environment if available so branded private names resolve correctly.
        $baseEnv = $ctor->getPrivateEnv() ?? $env;
        $fieldEnv = $baseEnv->createChild();
        $fieldEnv->defineVar('this', $instance);
        // Per spec, class field initializers have an implicit [[HomeObject]]
        // so super.x property access works (resolves to the prototype).
        $proto = $ctor->get('prototype');
        if ($proto instanceof JsObject) {
            $fieldEnv->defineVar('[[HomeObject]]', $proto);
        }
        // Mark as field initializer context so eval knows to restrict `arguments`.
        $fieldEnv->defineVar('[[ClassFieldInitializer]]', new JsBoolean(true));
        // Per spec, class field initializers have an implicit [[NewTarget]]
        // of undefined (they run as a synthetic function call, not construct).
        $fieldEnv->defineVar('[[NewTarget]]', JsUndefined::instance());
        foreach ($ctor->getInstanceFieldInitializers() as [$key, $initNode, $computed, $isPrivate]) {
            $value = $initNode !== null
                ? $this->evaluate($initNode, $fieldEnv)
                : JsUndefined::instance();

            // NamedEvaluation: if the initializer evaluates to an anonymous
            // function, assign it the field's name (spec PropertyDefinition).
            if ($value instanceof JsFunction && !$this->hasExplicitNameProperty($value)) {
                $fieldName = null;
                if ($isPrivate && is_string($key)) {
                    // Strip the "@brandId" suffix from the mangled private name.
                    $at = strpos($key, '@');
                    $fieldName = $at === false ? $key : substr($key, 0, $at);
                } elseif ($key instanceof \Phasis\Value\JsSymbol) {
                    $desc = $key->getDescription();
                    $fieldName = $desc !== null ? "[{$desc}]" : '';
                } elseif (is_string($key)) {
                    $fieldName = $key;
                }
                if ($fieldName !== null) {
                    $value->setName($fieldName);
                }
            }

            if ($isPrivate) {
                $instance->setPrivateField($key, $value);
            } elseif ($key instanceof \Phasis\Value\JsSymbol) {
                $ok = $instance->definePropertyBySymbol($key, PropertyDescriptor::data(
                    $value,
                    true,
                    true,
                    true,
                ));
                if ($ok === false) {
                    $desc = $key->getDescription();
                    $shown = $desc !== null ? "Symbol({$desc})" : 'Symbol()';
                    throw new TypeError(
                        "Cannot create property '{$shown}' on object",
                    );
                }
            } else {
                $ok = $instance->defineOwnProperty((string) $key, PropertyDescriptor::data(
                    $value,
                    true,
                    true,
                    true,
                ));
                if ($ok === false) {
                    throw new TypeError(
                        "Cannot create property '{$key}' on object",
                    );
                }
            }
        }
    }

    /**
     * Format the call expression's callee for "X is not a function" errors.
     * Mirrors V8/SpiderMonkey error messages by serialising MemberExpression
     * and Identifier callees rather than using the value's toString. Falls
     * back to the value's stringification for opaque/computed callees.
     */
    private function formatCalleeForError(Node $node, JsValue $value): string
    {
        $text = $this->renderCalleeNode($node);
        if ($text !== null) {
            return $text;
        }
        return TypeConversion::toString($value);
    }

    /** Recursively render a MemberExpression / Identifier / ArrayExpression
     * chain into the source-like text that engine error messages quote. */
    private function renderCalleeNode(Node $node): ?string
    {
        if ($node instanceof Identifier) {
            return $node->name;
        }
        if ($node instanceof MemberExpression) {
            $obj = $this->renderCalleeNode($node->object);
            if ($obj === null) {
                return null;
            }
            if ($node->computed) {
                $prop = $this->renderCalleeNode($node->property);
                if ($prop === null) {
                    return null;
                }
                return "{$obj}[{$prop}]";
            }
            if ($node->property instanceof Identifier) {
                return "{$obj}.{$node->property->name}";
            }
            return null;
        }
        if ($node instanceof \Phasis\Ast\Expression\ArrayExpression && $node->elements === []) {
            return '[]';
        }
        if ($node instanceof \Phasis\Ast\Expression\ObjectExpression && $node->properties === []) {
            return '({})';
        }
        if ($node instanceof ThisExpression) {
            return 'this';
        }
        return null;
    }

    /**
     * @param Node[] $argNodes
     * @return JsValue[]
     */
    private function evaluateArguments(array $argNodes, Environment $env): array
    {
        $args = [];
        foreach ($argNodes as $argNode) {
            if ($argNode instanceof SpreadElement) {
                $iterable = $this->evaluate($argNode->argument, $env);
                if ($iterable instanceof \Phasis\Value\JsOptionalUndefined) {
                    $iterable = JsUndefined::instance();
                }
                $this->spreadInto($iterable, $args);
            } else {
                $val = $this->evaluate($argNode, $env);
                // Optional-chain sentinel: when an argument is a
                // MemberExpression / CallExpression that ends a chain
                // (e.g. `fn(undefined, foo()?.a?.b)`), evaluate keeps
                // the chain-internal sentinel so further `?.x` access
                // can short-circuit. Once the chain ends (handed to a
                // function as an argument), unwrap to JsUndefined so
                // the callee sees the spec value, not the sentinel.
                if ($val instanceof \Phasis\Value\JsOptionalUndefined) {
                    $val = JsUndefined::instance();
                }
                $args[] = $val;
            }
        }
        return $args;
    }

    /**
     * Spread an iterable value into the target array using the iterator protocol.
     *
     * @param list<JsValue> $target
     */
    private function spreadInto(JsValue $iterable, array &$target): void
    {
        $iterator = $this->getIterator($iterable);
        if ($iterator !== null) {
            $nextMethod = $iterator->get('next');
            if ($nextMethod instanceof JsFunction) {
                while (true) {
                    $result = $this->callFunction($nextMethod, $iterator, []);
                    // Spec IteratorStep: if next() does not return an Object,
                    // throw TypeError instead of treating it as iteration end.
                    if (!$result instanceof JsObject) {
                        throw new TypeError('iterator.next() returned a non-object value');
                    }
                    if (TypeConversion::toBoolean($result->get('done'))) {
                        break;
                    }
                    $target[] = $result->get('value');
                }
                return;
            }
        }

        // Fallback for plain JsArray without Symbol.iterator.
        if ($iterable instanceof JsArray) {
            $len = $iterable->getLength();
            for ($i = 0; $i < $len; $i++) {
                $target[] = $iterable->get((string) $i);
            }
            return;
        }

        // Per spec, if the value is not iterable, throw TypeError.
        throw new TypeError(
            TypeConversion::toString($iterable) . ' is not iterable',
        );
    }

    /** @param JsValue[] $args */
    public function callFunction(
        JsFunction $fn,
        JsValue $thisValue,
        array $args,
    ): JsValue {
        // Switch the currently-active realm (interpreter) to the function's
        // realm for the duration of the call so that any new JsFunction
        // instances created inside the body, plus realm-aware lookups, are
        // attributed to the callee's realm. Cross-realm tests rely on
        // GetFunctionRealm seeing the same answer for a function whether
        // looked up directly or after it has been called.
        $previousInterp = \Phasis\Engine::getCurrentInterpreter();
        $targetInterp = $fn->realm?->getInterpreter();
        $switched = false;
        if ($targetInterp !== null && $targetInterp !== $previousInterp) {
            \Phasis\Engine::setCurrentInterpreter($targetInterp);
            $switched = true;
        }
        try {
            // Trampoline loop for proper tail call optimization.
            // When a strict-mode function returns a TailCallThunk, we retry
            // with the thunk's function/args instead of recursing.
            while (true) {
                try {
                    $result = $this->callFunctionInner($fn, $thisValue, $args);
                } catch (\Phasis\Exceptions\JsThrowable $e) {
                    throw $e;
                } catch (\Phasis\Exceptions\RuntimeError | \Phasis\Exceptions\SyntaxError $e) {
                    // Per spec: a PHP RuntimeError / SyntaxError that escapes
                    // the callee's body must surface as a JS error constructed
                    // from the callee's realm — `c1.access(c2)` brand-check
                    // TypeError is realm1.TypeError, not the caller's. Wrap
                    // eagerly so execTryStatement on the caller side sees the
                    // right realm's constructor identity. (Native callees
                    // already handle this inside callFunctionInner.)
                    $fnRealm = $fn->realm;
                    if ($fnRealm !== null && $fnRealm !== $this->engineRealm) {
                        $realmInterp = $fnRealm->getInterpreter();
                        throw new \Phasis\Exceptions\JsThrowable(
                            $realmInterp->phpExceptionToJsValue($e)
                        );
                    }
                    throw $e;
                }
                if ($result instanceof TailCallThunk) {
                    $fn = $result->function;
                    $thisValue = $result->thisValue;
                    $args = $result->args;
                    // Tail-call to a function in a different realm: switch
                    // again. Common across realm-aware bound functions and
                    // species hooks.
                    $nextInterp = $fn->realm?->getInterpreter();
                    if ($nextInterp !== null && $nextInterp !== \Phasis\Engine::getCurrentInterpreter()) {
                        \Phasis\Engine::setCurrentInterpreter($nextInterp);
                        $switched = true;
                    }
                    continue;
                }
                return $result;
            }
        } finally {
            if ($switched) {
                \Phasis\Engine::setCurrentInterpreter($previousInterp);
            }
        }
    }

    /** @return JsValue|TailCallThunk */
    /**
     * @param array<mixed> $args
     */
    private function callFunctionInner(
        JsFunction $fn,
        JsValue $thisValue,
        array $args,
    ): JsValue|TailCallThunk {
        // Per spec: class constructors cannot be called without `new`.
        // The TypeError must come from the class's realm, not the caller's
        // realm — per spec class-ctor-realm: assert.throws(realm.TypeError, …)
        // requires `TE === other.TypeError`.
        if ($fn->isClassConstructor()) {
            $calledAsNew = $thisValue instanceof JsObject
                && !($thisValue->get('[[NewTarget]]') instanceof JsUndefined);
            if (!$calledAsNew) {
                $msg = "Class constructor {$fn->getName()} cannot be invoked without 'new'";
                $fnRealm = $fn->realm;
                if ($fnRealm !== null && $fnRealm !== $this->engineRealm) {
                    $realmInterp = $fnRealm->getInterpreter();
                    throw new \Phasis\Exceptions\JsThrowable(
                        $realmInterp->phpExceptionToJsValue(new TypeError($msg))
                    );
                }
                throw new TypeError($msg);
            }
        }

        // Native (PHP callable) function
        $nativeFn = $fn->getNativeCallable();
        if ($nativeFn !== null) {
            // When the function belongs to a different realm than the
            // caller, wrap any PHP RuntimeError / SyntaxError that escapes
            // the native body using the *callee's* realm. Per spec, the
            // error surfaces from the realm of the function being invoked,
            // so `otherFn.call(undefined, ...)` throws `other.TypeError`
            // even when the caller is in the parent realm. SyntaxError is
            // included for JSON.rawJSON / JSON.parse, whose parser failures
            // must surface as the JSON intrinsic's realm.
            $fnRealm = $fn->realm;
            if ($fnRealm !== null && $fnRealm !== $this->engineRealm) {
                try {
                    return $nativeFn($thisValue, $args, $this);
                } catch (\Phasis\Exceptions\JsThrowable $e) {
                    throw $e;
                } catch (\Phasis\Exceptions\RuntimeError | \Phasis\Exceptions\SyntaxError $e) {
                    $realmInterp = $fnRealm->getInterpreter();
                    throw new \Phasis\Exceptions\JsThrowable(
                        $realmInterp->phpExceptionToJsValue($e)
                    );
                }
            }
            return $nativeFn($thisValue, $args, $this);
        }

        // Async generator function: return a JsAsyncGenerator.
        if ($fn->isGenerator() && $fn->isAsync()) {
            return $this->createAsyncGenerator($fn, $thisValue, $args);
        }

        // Generator function: return a JsGenerator instead of executing.
        if ($fn->isGenerator()) {
            return $this->createGenerator($fn, $thisValue, $args);
        }

        // Async function: execute the body and wrap the result in a Promise.
        if ($fn->isAsync()) {
            return $this->executeAsyncFunction($fn, $thisValue, $args);
        }

        // JsToPhp fast path: if the body is already lowered to a PHP
        // closure, invoke it directly. Skips the entire executeFunction
        // prologue (callStack push, callerStack push, frame setup,
        // sloppy this coercion, env binding) — the JsToPhp emitter
        // verified at compile time that the body needs none of that.
        // Runtime bailouts (non-numeric arg, etc.) fall through to the
        // standard executeFunction path so spec semantics are preserved.
        if (
            $fn->phpCompiled !== null
            && !$fn->isClassConstructor()
            && !$fn->isDerivedConstructor()
            && $fn->getHomeObject() === null
        ) {
            try {
                return ($fn->phpCompiled)($args, $fn->getClosure(), $this, $fn->phpCompiledNodes);
            } catch (\Phasis\Bytecode\Bailout) {
                // Fall through to the slow path below.
            }
        }

        // Interpreted function
        return $this->executeFunction($fn, $thisValue, $args);
    }

    /**
     * Install the .prototype property on a newly created function.
     *
     * For normal functions: creates {constructor: fn} with Object.prototype as prototype.
     * For generator functions: creates a plain object with %GeneratorPrototype% as prototype,
     * NO constructor property (per spec §27.3.4).
     */
    private function installFunctionPrototype(JsFunction $fn, bool $isGenerator, bool $isAsync = false): void
    {
        // Per spec §25.7.1: async functions are not constructable and do not
        // have a .prototype property.
        if ($isAsync && !$isGenerator) {
            $fn->setNonConstructable();
            return;
        }

        if ($isGenerator) {
            // Async generators use %AsyncGeneratorPrototype%, sync use %GeneratorPrototype%.
            $protoKey = ($isAsync) ? '__AsyncGeneratorPrototype__' : '__GeneratorPrototype__';
            $generatorProto = null;
            if ($this->globalEnv->has($protoKey)) {
                $gp = $this->globalEnv->get($protoKey);
                if ($gp instanceof JsObject) {
                    $generatorProto = $gp;
                }
            }
            $proto = new JsObject($generatorProto);
            // Non-constructable: generators can't be called with new.
            $fn->setNonConstructable();
        } else {
            // Resolve %Object.prototype% for THIS realm so a sibling
            // Engine cannot leak its Object.prototype through the
            // process-wide JsObject::$globalPrototype static. Test
            // built-ins/Function/proto-from-ctor-realm-prototype
            // asserts Object.getPrototypeOf(fn.prototype) ===
            // realmA.Object.prototype when the Function was made in
            // realmA via Reflect.construct.
            $objProto = null;
            if ($this->globalEnv->has('__ObjectPrototype__')) {
                $candidate = $this->globalEnv->get('__ObjectPrototype__');
                if ($candidate instanceof JsObject) {
                    $objProto = $candidate;
                }
            }
            $proto = new JsObject($objProto);
            // Per spec, constructor is writable, non-enumerable, configurable.
            $proto->defineOwnProperty('constructor', PropertyDescriptor::data($fn, true, false, true));
        }
        // Per spec §27.3.4 / §10.2.4: .prototype is writable, non-enumerable, non-configurable for generators;
        // writable, non-enumerable, non-configurable for regular functions too.
        $fn->defineOwnProperty('prototype', PropertyDescriptor::data($proto, true, false, false));

        // Annex B legacy `function.caller` / `function.arguments`: install
        // own slots (default null) on non-strict, non-arrow, non-async,
        // non-generator, non-class-constructor regular functions so a
        // pre-call read does not fall through to the
        // Function.prototype.arguments / .caller poison-pill thrower.
        // A body-level "use strict" directive promotes the function to
        // strict — its body is observable, so check it here too.
        $body = $fn->getBody();
        $hasBodyStrict = $body instanceof BlockStatement
            && $this->hasUseStrictDirective($body->body);
        if (
            !$fn->isStrict()
            && !$hasBodyStrict
            && !$fn->isArrow()
            && !$fn->isNative()
            && !$isAsync
            && !$isGenerator
            && !$fn->isClassConstructor()
        ) {
            $fn->defineOwnProperty('arguments', PropertyDescriptor::data(JsNull::instance(), true, false, true));
            $fn->defineOwnProperty('caller', PropertyDescriptor::data(JsNull::instance(), true, false, true));
        }
    }

    /**
     * Execute an interpreted (non-generator) function body.
     *
     * @param list<JsValue> $args
     */
    /** @return JsValue|TailCallThunk */
    /**
     * @param array<mixed> $args
     */
    private function executeFunction(
        JsFunction $fn,
        JsValue $thisValue,
        array $args,
    ): JsValue|TailCallThunk {
        $this->callStack->push($fn->getName(), 0);

        // Annex B Function.caller: track caller only for non-strict, non-arrow
        // ordinary functions. Per spec, methods, arrow functions, async
        // functions, generators, and class constructors must not expose a
        // 'caller' own property regardless of containing code's strict mode.
        // Also detect body-level "use strict" directives so they suppress the
        // own 'caller' slot just like a strict flag at definition time.
        $callerFn = !empty($this->callerStack) ? $this->callerStack[count($this->callerStack) - 1] : null;
        $this->callerStack[] = $fn;
        $fnBody = $fn->getBody();
        // Only ordinary constructable functions (e.g. 'function f(){}') track
        // caller via Annex B. Methods, arrow functions, async functions,
        // generators, class constructors, and built-ins are all excluded.
        // The result depends only on immutable JsFunction flags + the body
        // strict directive, so memoise it once per function.
        if ($fn->setCallerPropCache === null) {
            $hasBodyStrict = $fnBody instanceof BlockStatement
                && $this->hasUseStrictDirective($fnBody->body);
            $fn->setCallerPropCache = !$fn->isStrict()
                && !$hasBodyStrict
                && !$fn->isArrow()
                && !$fn->isNative()
                && !$fn->isAsync()
                && !$fn->isGenerator()
                && !$fn->isClassConstructor()
                && $fn->isConstructable();
        }
        $setCallerProp = $fn->setCallerPropCache;
        $savedCaller = null;
        $savedArguments = null;
        $savedCallerValue = null;
        $savedArgumentsValue = null;
        $callerIsStrict = false;
        // Track whether the engine should auto-update the slot during the
        // call. If the user redefined caller/arguments to an accessor or a
        // non-default data shape, leave it alone — the user's definition
        // wins.
        $autoUpdateCaller = false;
        $autoUpdateArguments = false;
        if ($setCallerProp) {
            $savedCaller = $fn->getOwnPropertyDescriptor("caller");
            $savedArguments = $fn->getOwnPropertyDescriptor("arguments");
            // Engine-default slot: data, writable, non-enumerable,
            // configurable, with value null (the install-time default) or
            // the active-call value the engine previously wrote. If the
            // user has assigned a different value via `o.caller = 1`,
            // leave it alone — the forbidden-extension test verifies that
            // user-assigned values survive across a call.
            $autoUpdateCaller = self::isEngineDefaultCaller($savedCaller, $callerFn);
            $autoUpdateArguments = self::isEngineDefaultArguments($savedArguments);
            // Snapshot the values before the entry mutation: tryUpdateDataValue
            // mutates the live descriptor in place, so $savedCaller->value
            // would otherwise reflect the new (active-call) value by the time
            // we restore. We only consult these JsValues on exit, not the
            // descriptor shape, since autoUpdate already guaranteed the
            // shape was engine-default both at entry and across the call.
            if ($autoUpdateCaller) {
                $savedCallerValue = $savedCaller->value ?? JsNull::instance();
            }
            if ($autoUpdateArguments) {
                $savedArgumentsValue = $savedArguments->value ?? JsNull::instance();
            }

            $callerIsStrictMode = $this->strictMode
                || ($callerFn instanceof JsFunction && $callerFn->isStrict());
            $callerVal = ($callerIsStrictMode || !$callerFn instanceof JsFunction)
                ? JsNull::instance()
                : $callerFn;
            if ($callerIsStrictMode) {
                $callerIsStrict = true;
            }
            // Per the legacy function reflection proposal, the engine
            // auto-updates the caller / arguments slots ONLY if they
            // currently hold the default engine shape; user-defined
            // accessors or replaced descriptors are left alone.
            // tryUpdateDataValue mutates the existing PropertyDescriptor's
            // value field in place, skipping the two-allocation merge
            // path inside defineOwnProperty. autoUpdate already verified
            // the existing slot has the engine-default shape (writable,
            // configurable data descriptor).
            if ($autoUpdateCaller && !$fn->tryUpdateDataValue("caller", $callerVal)) {
                $fn->defineOwnProperty("caller", PropertyDescriptor::data(
                    $callerVal,
                    true,
                    false,
                    true,
                ));
            }
            if (
                $autoUpdateArguments
                && !$fn->tryUpdateDataValue("arguments", JsNull::instance())
            ) {
                $fn->defineOwnProperty("arguments", PropertyDescriptor::data(
                    JsNull::instance(),
                    true,
                    false,
                    true,
                ));
            }
        }

        // Save and potentially update strict mode for this function body.
        $previousStrictMode = $this->strictMode;

        // Per spec GetActiveScriptOrModule, code inside a function body
        // resolves import.meta against the module that DEFINED the
        // function, not the caller's module. Switch the interpreter's
        // currentModulePath to the function's defining path during the
        // call (only when the function captured one), and restore on
        // return.
        $previousModulePath = $this->currentModulePath;
        $switchModulePath = $fn->definingModulePath !== null
            && $fn->definingModulePath !== $this->currentModulePath;
        if ($switchModulePath) {
            $this->currentModulePath = $fn->definingModulePath;
        }

        // Hot path for VM-compiled bodies with no env-binding needs:
        // bytecode never reads `this` via env (needsThis=false), never
        // touches `arguments` / `new.target` / `eval` / `with` (compiler
        // bailouts), and the compiler routes every var/let/const to a
        // frame slot. The closure env is therefore semantically
        // equivalent to a fresh child env for the duration of this call,
        // so we hand it straight to the Frame and skip the per-call
        // Environment allocation. fn-recurse on fib(22) is ~4M calls;
        // dropping the createChild here cuts the dominant per-call cost.
        if (
            $fn->compiled !== null
            && $fn->compiled->canSkipEnvAlloc
            && !$fn->isClassConstructor()
            && !$fn->isDerivedConstructor()
            && $fn->getHomeObject() === null
        ) {
            if ($fn->effectiveStrictCache === null) {
                $body = $fn->getBody();
                $fn->effectiveStrictCache = $fn->isStrict()
                    || ($body instanceof BlockStatement
                        && $this->hasUseStrictDirective($body->body));
            }
            $this->strictMode = $fn->effectiveStrictCache;
            // Arrows inherit `this` from their closure — skip sloppy
            // coercion, which would only burn cycles since LOAD_THIS
            // (not emitted for canSkipEnvAlloc bodies) wouldn't read
            // the coerced value anyway.
            if (!$this->strictMode && !$fn->isArrow()) {
                if ($thisValue instanceof JsUndefined || $thisValue instanceof JsNull) {
                    $thisValue = $this->getGlobalObject();
                } elseif (
                    !$thisValue instanceof JsObject
                    && ($thisValue instanceof JsNumber
                        || $thisValue instanceof JsString
                        || $thisValue instanceof JsBoolean
                        || $thisValue instanceof JsSymbol
                        || $thisValue instanceof JsBigInt)
                ) {
                    $thisValue = TypeConversion::toObject($thisValue);
                }
            }
            $savedTailPos = $this->inTailPosition;
            $this->inTailPosition = $this->strictMode;
            try {
                $vmReturn = $this->tryRunOnVm($fn, $fn->getClosure(), $thisValue, $args);
            } catch (\Throwable $e) {
                $this->inTailPosition = $savedTailPos;
                $this->teardownExecuteFunction(
                    $fn,
                    $setCallerProp,
                    $autoUpdateCaller,
                    $autoUpdateArguments,
                    $savedCaller,
                    $savedArguments,
                    $savedCallerValue,
                    $savedArgumentsValue,
                    $previousStrictMode,
                    $previousModulePath,
                    $switchModulePath,
                );
                throw $e;
            }
            $this->inTailPosition = $savedTailPos;
            if ($vmReturn !== null) {
                $this->teardownExecuteFunction(
                    $fn,
                    $setCallerProp,
                    $autoUpdateCaller,
                    $autoUpdateArguments,
                    $savedCaller,
                    $savedArguments,
                    $savedCallerValue,
                    $savedArgumentsValue,
                    $previousStrictMode,
                    $previousModulePath,
                    $switchModulePath,
                );
                return $vmReturn;
            }
            // tryRunOnVm bailed (compiler returned null on first
            // attempt). Reset strict mode and fall through to the
            // normal slow path; the slow path's try/finally takes
            // over from here, including the caller-stack teardown.
            $this->strictMode = $previousStrictMode;
        }

        try {
            $fnEnv = $fn->getClosure()->createChild();

            // Tag the environment with the function kind so
            // EvalDeclarationInstantiation can enforce restrictions
            // and so evalAwaitExpression / for-await unwrap know whether
            // the current call frame is suspendable async. Async-ness
            // takes precedence over arrow-ness here: an async arrow
            // function STILL runs inside a fiber and must be tagged
            // 'async' so awaits suspend via Fiber::suspend rather than
            // falling back to the synchronous awaitValue path (which
            // returns the unresolved sentinel for never-settling
            // promises, breaking for-await of a pending stream). Arrow
            // semantics (no own this/arguments/new.target) are tracked
            // by the isArrow flag on JsFunction itself, not by this kind.
            if ($fn->isAsync()) {
                $fnEnv->setFunctionKind('async');
            } elseif ($fn->isArrow()) {
                $fnEnv->setFunctionKind('arrow');
            } else {
                $fnEnv->setFunctionKind('function');
            }

            // Per spec 10.2.1.2: A function's strict mode is determined by its own
            // [[Strict]] flag (set at definition time from the enclosing scope) OR by
            // a "use strict" directive in its body. The CALLER's strict mode is irrelevant.
            $body = $fn->getBody();
            // Cache the body-prologue scan result on the function: it never
            // changes for a given JsFunction, but recursive callers can hit
            // executeFunction millions of times.
            if ($fn->effectiveStrictCache === null) {
                $fn->effectiveStrictCache = $fn->isStrict()
                    || ($body instanceof BlockStatement && $this->hasUseStrictDirective($body->body));
            }
            $fnStrict = $fn->effectiveStrictCache;
            $this->strictMode = $fnStrict;

            // Per spec 9.2.1.2 OrdinaryCallBindThis:
            // In strict mode, this is passed as-is (no wrapping).
            // In sloppy mode:
            //   - null/undefined this -> globalThis (of the callee's realm)
            //   - primitive this -> ToObject(this)
            if (!$fn->isArrow()) {
                if ($this->strictMode) {
                    // Strict mode: thisValue is passed as-is (no boxing, no substitution).
                    // The caller is responsible for passing the correct value.
                } else {
                    // Sloppy mode: wrap null/undefined to global, primitives to Object.
                    if ($thisValue instanceof JsUndefined || $thisValue instanceof \Phasis\Value\JsNull) {
                        // Per spec, the global object substituted here is
                        // the function's *home* realm, not the caller's.
                        // Without this, `h.f.call(undefined)` would see
                        // the parent realm's globalThis.
                        $thisValue = $this->getFunctionGlobalObject($fn);
                    } elseif (
                        !$thisValue instanceof JsObject
                        && ($thisValue instanceof JsNumber
                            || $thisValue instanceof JsString
                            || $thisValue instanceof JsBoolean
                            || $thisValue instanceof JsSymbol
                            || $thisValue instanceof JsBigInt)
                    ) {
                        $thisValue = TypeConversion::toObject($thisValue);
                    }
                }
            }

            // Bind this
            if ($fn->isArrow()) {
                // Arrow functions inherit this and [[NewTarget]] from closure.
            } elseif ($fn->isDerivedConstructor()) {
                // Per spec 8.1.1.3.1 BindThisValue: in a derived constructor,
                // this starts uninitialized. Accessing it before super() throws
                // ReferenceError. We use TDZ (declareLet) to achieve this.
                $fnEnv->declareLet('this');
                // Store the newObj so super() can pass it to the parent constructor.
                $fnEnv->defineVar('[[PendingThis]]', $thisValue);
                $ntDesc = $thisValue instanceof JsObject
                    ? $thisValue->getOwnPropertyDescriptor('[[NewTarget]]')
                    : null;
                if ($ntDesc !== null) {
                    $nt = $ntDesc->value;
                    $fnEnv->defineVar('[[NewTarget]]', $nt instanceof JsValue ? $nt : $fn);
                } else {
                    $fnEnv->defineVar('[[NewTarget]]', $fn);
                }
            } else {
                // VM-compiled bodies whose bytecode never touches
                // `this` or `new.target` skip these env bindings —
                // the VM's LOAD_THIS reads via env->get('this') only
                // when its bytecode actually emits it, and the
                // compiler never emits LOAD_NEW_TARGET (no MetaProperty
                // support yet, so any new.target use bails). Frame
                // params come from $args directly via paramSlots.
                $vmCanSkipEnvBindings = $fn->compiled !== null;
                if (!$vmCanSkipEnvBindings || $fn->compiled->needsThis) {
                    $fnEnv->defineVar('this', $thisValue);
                }
                if (!$vmCanSkipEnvBindings) {
                    $ntDesc = ($thisValue instanceof JsObject && $fn->isConstructable())
                        ? $thisValue->getOwnPropertyDescriptor('[[NewTarget]]')
                        : null;
                    if ($ntDesc !== null) {
                        $nt = $ntDesc->value;
                        $fnEnv->defineVar('[[NewTarget]]', $nt instanceof JsValue ? $nt : $fn);
                    } else {
                        $fnEnv->defineVar('[[NewTarget]]', JsUndefined::instance());
                    }
                }
            }

            // Bind [[HomeObject]] so super property references work inside this function.
            $homeObject = $fn->getHomeObject();
            if ($homeObject !== null) {
                $fnEnv->defineVar('[[HomeObject]]', $homeObject);
            }

            // For class constructors in derived classes, bind [[ActiveFunction]] so
            // super() can resolve the super constructor via the function's [[Prototype]].
            if ($fn->isClassConstructor()) {
                $fnEnv->defineVar('[[ActiveFunction]]', $fn);
            }

            // Create arguments object before binding parameters, so default
            // parameter expressions can reference `arguments`.
            $params = $fn->getParams();
            // Both analyses are pure functions of $params; cache once.
            if ($fn->hasParamExpressionsCache === null) {
                $fn->hasParamExpressionsCache = $this->hasParameterExpressions($params);
            }
            if ($fn->nonSimpleParamsCache === null) {
                $fn->nonSimpleParamsCache = $this->isNonSimpleParameterList($params);
            }
            $hasDefaultParams = $fn->hasParamExpressionsCache;
            $isNonSimpleParams = $fn->nonSimpleParamsCache;

            // Per EvalDeclarationInstantiation, eval("var arguments") inside
            // a function with non-simple parameters is a SyntaxError. Tag the
            // environment so the eval check can detect this.
            if ($isNonSimpleParams) {
                $fnEnv->setHasNonSimpleParams(true);
            }

            $unmapped = true;
            $argsObj = null;
            if (!$fn->isArrow()) {
                // Per spec 10.2.11: non-simple parameter lists produce unmapped
                // arguments objects (poison-pill callee), same as strict mode.
                $unmapped = $this->strictMode || $isNonSimpleParams;
                // Fast path: if the body never references `arguments`,
                // doesn't `eval`, and isn't expected to be patched, skip
                // the full arguments-object construction. The hot path on
                // recursive functions like fib was spending real time
                // here. We still create a placeholder undefined binding
                // when the legacy `function.arguments` getter would
                // otherwise capture a stale value.
                $needsArgsObject = $setCallerProp && $autoUpdateArguments;
                if (!$needsArgsObject) {
                    if ($fn->bodyUsesArgumentsCache === null) {
                        $fn->bodyUsesArgumentsCache = $this->bodyUsesArguments(
                            $fn->getBody(),
                            $params,
                        );
                    }
                    $needsArgsObject = $fn->bodyUsesArgumentsCache;
                }
                if ($needsArgsObject) {
                    $argsObj = $this->makeArgumentsObject($args, $fn, $unmapped);
                    $fnEnv->defineVar('arguments', $argsObj);
                    if ($setCallerProp && $autoUpdateArguments) {
                        $fn->defineOwnProperty("arguments", PropertyDescriptor::data(
                            $argsObj,
                            true,
                            false,
                            true,
                        ));
                    }
                }
            }

            // Bind parameters into the env (pass the cached
            // hasParamExpressions to skip the re-walk inside).
            // VM-compiled bodies populate paramSlots from $args
            // directly via tryRunOnVm; their nested closures are
            // forbidden from observing outer locals via the env
            // (capture-detection bails). Skip the env bindings then.
            if ($fn->compiled === null) {
                $this->bindParameters($params, $args, $fnEnv, $hasDefaultParams);
            }

            // Set up mapped arguments aliasing per spec 10.4.4.7:
            // In sloppy mode with simple parameters, arguments[i] and the
            // corresponding parameter name share a live binding.
            if ($argsObj !== null && !$unmapped) {
                $this->setupMappedArguments($argsObj, $params, $args, $fnEnv);
            }

            // Collect parameter names for Annex B hoisting checks.
            // Per spec FunctionDeclarationInstantiation step 22.f, when
            // an arguments object is created "arguments" is appended to
            // parameterNames. Per Annex B B.3.2.1 step c, that disqualifies
            // a same-named block-scoped FunctionDeclaration from being
            // hoisted to the function scope, preserving the implicit
            // arguments binding (test262
            // annexB/language/function-code/block-decl-func-skip-arguments).
            //
            // currentParamNames is read only by hoistDeclarations'
            // canHoist check. If the body trips no hoisting at all
            // (cached on JsFunction for BlockStatement bodies), the
            // swap is pure overhead — skip it.
            $needsParamNames = $body instanceof BlockStatement
                && $fn->bodyNeedsHoistingCache !== false;
            $savedParamNames = $this->currentParamNames;
            if ($needsParamNames) {
                $this->currentParamNames = [];
                foreach ($params as $p) {
                    foreach ($this->patternBoundNames($p) as $pName) {
                        $this->currentParamNames[$pName] = true;
                    }
                }
                // Spec FunctionDeclarationInstantiation step 22.f appends
                // "arguments" to parameterNames whenever an implicit
                // arguments object is bound, which by Annex B B.3.2.1
                // step 1.b.ii would block every `function arguments() {}`
                // hoist. V8 / SpiderMonkey relax that: the hoist still
                // happens unless the body references `arguments` as a
                // free Identifier (outside nested non-arrow function /
                // class bodies) at a textual offset earlier than the
                // first block-scoped `function arguments() {}` decl. A
                // pre-block reference would otherwise see `undefined`
                // (the Annex B var placeholder), which is observable.
                // Post-block references already resolve to whatever the
                // block left behind, so they need no protection.
                //
                // Tests: passes annexB/.../block-decl-func-skip-arguments
                // (pre-block `arguments.toString()` keeps the args object)
                // and staging/sm/regress/regress-602621 (no pre-block ref
                // → Annex B hoists `function arguments(){}` over the args
                // object).
                if ($argsObj !== null) {
                    if ($fn->blockArgumentsAnnexBSuppressedCache === null) {
                        $fn->blockArgumentsAnnexBSuppressedCache =
                            $this->bodyShouldBlockArgumentsAnnexB($body->body);
                    }
                    if ($fn->blockArgumentsAnnexBSuppressedCache) {
                        $this->currentParamNames['arguments'] = true;
                    }
                }
            }

            // When the function has parameter expressions (defaults, destructuring
            // with defaults), the body gets a separate environment so closures in
            // the parameter list do not see body-scoped var declarations.
            if ($hasDefaultParams && $body instanceof BlockStatement) {
                $bodyEnv = $fnEnv->createChild();
                // Copy parameter bindings to the body environment as vars.
                foreach ($params as $p) {
                    $this->copyParamBindings($p, $fnEnv, $bodyEnv);
                }
                // arguments in body env should still be available, but only
                // when we actually built the arguments object on entry.
                if (!$fn->isArrow() && $argsObj !== null) {
                    $bodyEnv->defineVar('arguments', $argsObj);
                }
                // Force-hoist var names into bodyEnv so they shadow parent bindings.
                // This is necessary because the body environment is separate from
                // the parameter environment, and var declarations must create
                // bindings in the body scope, not update parent scope bindings.
                $this->forceHoistVarNames($body->body, $bodyEnv);
                $this->hoistDeclarations($body->body, $bodyEnv);
                $this->currentParamNames = $savedParamNames;
                $this->hoistEvalLexicalDeclarations($body->body, $bodyEnv);
                $savedTailPos = $this->inTailPosition;
                $this->inTailPosition = $this->strictMode;
                $completion = $this->executeBody($body->body, $bodyEnv);
                $this->inTailPosition = $savedTailPos;
                $completion = $this->applyDisposals($bodyEnv, $completion);
                if ($completion->type === CompletionType::Return) {
                    if ($completion->value instanceof TailCallThunk) {
                        return $completion->value;
                    }
                    return $this->derivedConstructorReturn($fn, $fnEnv, $completion->value);
                }
                if ($completion->type === CompletionType::Throw) {
                    $this->throwJsValue($completion->value);
                }
                return $this->derivedConstructorImplicitReturn($fn, $fnEnv);
            }

            // Execute body
            if ($body instanceof BlockStatement) {
                // Force-hoist var names into the function scope first so that
                // a var declaration in a nested function always creates a binding
                // in the function's own scope, even when a parent scope (e.g. global)
                // has a const/let binding with the same name.
                //
                // Trivial bodies (just a return / throw / expression with no
                // var/let/const/function/class declarations and no nested
                // control flow that could contain them) trip none of the
                // three hoist passes. Skip them on subsequent calls.
                if ($fn->bodyNeedsHoistingCache === null) {
                    $fn->bodyNeedsHoistingCache = $this->bodyNeedsHoisting($body->body);
                }
                if ($fn->bodyNeedsHoistingCache) {
                    $this->forceHoistVarNames($body->body, $fnEnv);
                    $this->hoistDeclarations($body->body, $fnEnv);
                    $this->hoistEvalLexicalDeclarations($body->body, $fnEnv);
                }
                $this->currentParamNames = $savedParamNames;
                $savedTailPos = $this->inTailPosition;
                $this->inTailPosition = $this->strictMode;

                // Bytecode VM fast path: lazy-compile this body the
                // first time we see it; subsequent calls dispatch via
                // the flat-bytecode loop instead of recursive AST
                // walking. Compilation bailouts fall back here, and
                // any runtime feature the compiler refused (eval,
                // with, generators, etc.) also keeps using executeBody.
                $vmReturn = $this->tryRunOnVm($fn, $fnEnv, $thisValue, $args);
                if ($vmReturn !== null) {
                    $this->inTailPosition = $savedTailPos;
                    if ($vmReturn instanceof TailCallThunk) {
                        return $vmReturn;
                    }
                    return $this->derivedConstructorReturn($fn, $fnEnv, $vmReturn);
                }

                $completion = $this->executeBody($body->body, $fnEnv);
                $this->inTailPosition = $savedTailPos;
                $completion = $this->applyDisposals($fnEnv, $completion);
                if ($completion->type === CompletionType::Return) {
                    if ($completion->value instanceof TailCallThunk) {
                        return $completion->value;
                    }
                    return $this->derivedConstructorReturn($fn, $fnEnv, $completion->value);
                }
                if ($completion->type === CompletionType::Throw) {
                    $this->throwJsValue($completion->value);
                }
                return $this->derivedConstructorImplicitReturn($fn, $fnEnv);
            }

            // Arrow with expression body — try the JsToPhp / VM path
            // first. JsToPhp can lower numeric arrow expressions like
            // `x => x + n` to native PHP closures that PHP's JIT then
            // compiles to machine code; that handily beats the
            // tree-walker even on these short bodies. If both
            // JsToPhp and bytecode compile bail, tryRunOnVm returns
            // null and we fall back to the tree-walker.
            $vmReturn = $this->tryRunOnVm($fn, $fnEnv, $thisValue, $args);
            if ($vmReturn !== null) {
                if ($vmReturn instanceof TailCallThunk) {
                    return $vmReturn;
                }
                return $vmReturn;
            }
            return $this->evaluate($body, $fnEnv);
        } finally {
            array_pop($this->callerStack);
            if ($setCallerProp) {
                // Only restore engine-managed slots; user-defined accessors
                // or replaced descriptors were never touched on entry.
                // Restore via in-place value mutation when possible to
                // skip the merge-and-allocate path inside defineOwnProperty.
                if ($autoUpdateCaller) {
                    if (!$fn->tryUpdateDataValue("caller", $savedCallerValue)) {
                        $fn->defineOwnProperty("caller", $savedCaller ?? PropertyDescriptor::data(
                            JsNull::instance(),
                            true,
                            false,
                            true,
                        ));
                    }
                }
                if ($autoUpdateArguments) {
                    if (!$fn->tryUpdateDataValue("arguments", $savedArgumentsValue)) {
                        $fn->defineOwnProperty("arguments", $savedArguments ?? PropertyDescriptor::data(
                            JsNull::instance(),
                            true,
                            false,
                            true,
                        ));
                    }
                }
            }
            $this->strictMode = $previousStrictMode;
            if ($switchModulePath) {
                $this->currentModulePath = $previousModulePath;
            }
            $this->callStack->pop();
        }
    }

    /**
     * Direct VM dispatch for canSkipEnvAlloc callees, called by the
     * bytecode Op::CALL when it can prove the callee fits the well-
     * trodden shape (VM-compiled, no class ctor, no homeObject, no
     * native callable, not async / generator). Bypasses callFunction's
     * tail-call trampoline + callFunctionInner's kind dispatcher and
     * instead inlines the executeFunction fast path, saving ~3-4
     * method calls per call site.
     *
     * If the body returns a TailCallThunk (proper tail call in strict
     * mode), this method resolves it in-line before returning a plain
     * JsValue, so callers (the VM) can treat the return as a value.
     *
     * @param list<JsValue> $args
     */
    public function executeVmFunctionDirect(
        JsFunction $fn,
        JsValue $thisValue,
        array $args,
    ): JsValue {
        // Direct field access: getName()/getClosure()/isArrow() were
        // each one method dispatch per call. The hot-path field reads
        // collapse to plain property fetches now that JsFunction
        // exposes these as public.
        $this->callStack->push($fn->name, 0);

        $callerFn = !empty($this->callerStack) ? $this->callerStack[count($this->callerStack) - 1] : null;
        $this->callerStack[] = $fn;

        if ($fn->setCallerPropCache === null) {
            $body = $fn->getBody();
            $hasBodyStrict = $body instanceof BlockStatement
                && $this->hasUseStrictDirective($body->body);
            $fn->setCallerPropCache = !$fn->isStrict()
                && !$hasBodyStrict
                && !$fn->isArrow()
                && !$fn->isNative()
                && !$fn->isAsync()
                && !$fn->isGenerator()
                && !$fn->isClassConstructor()
                && $fn->isConstructable();
        }
        $setCallerProp = $fn->setCallerPropCache;
        $savedCaller = null;
        $savedArguments = null;
        $savedCallerValue = null;
        $savedArgumentsValue = null;
        $autoUpdateCaller = false;
        $autoUpdateArguments = false;
        if ($setCallerProp) {
            $savedCaller = $fn->getOwnPropertyDescriptor("caller");
            $savedArguments = $fn->getOwnPropertyDescriptor("arguments");
            $autoUpdateCaller = self::isEngineDefaultCaller($savedCaller, $callerFn);
            $autoUpdateArguments = self::isEngineDefaultArguments($savedArguments);
            if ($autoUpdateCaller) {
                $savedCallerValue = $savedCaller->value ?? JsNull::instance();
            }
            if ($autoUpdateArguments) {
                $savedArgumentsValue = $savedArguments->value ?? JsNull::instance();
            }
            $callerIsStrictMode = $this->strictMode
                || ($callerFn instanceof JsFunction && $callerFn->isStrict());
            $callerVal = ($callerIsStrictMode || !$callerFn instanceof JsFunction)
                ? JsNull::instance()
                : $callerFn;
            if ($autoUpdateCaller && !$fn->tryUpdateDataValue("caller", $callerVal)) {
                $fn->defineOwnProperty("caller", PropertyDescriptor::data(
                    $callerVal,
                    true,
                    false,
                    true,
                ));
            }
            if (
                $autoUpdateArguments
                && !$fn->tryUpdateDataValue("arguments", JsNull::instance())
            ) {
                $fn->defineOwnProperty("arguments", PropertyDescriptor::data(
                    JsNull::instance(),
                    true,
                    false,
                    true,
                ));
            }
        }

        $previousStrictMode = $this->strictMode;
        $previousModulePath = $this->currentModulePath;
        $switchModulePath = $fn->definingModulePath !== null
            && $fn->definingModulePath !== $this->currentModulePath;
        if ($switchModulePath) {
            $this->currentModulePath = $fn->definingModulePath;
        }
        if ($fn->effectiveStrictCache === null) {
            $body = $fn->getBody();
            $fn->effectiveStrictCache = $fn->isStrict()
                || ($body instanceof BlockStatement
                    && $this->hasUseStrictDirective($body->body));
        }
        $this->strictMode = $fn->effectiveStrictCache;
        if (!$this->strictMode && !$fn->isArrow) {
            if ($thisValue instanceof JsUndefined || $thisValue instanceof JsNull) {
                $thisValue = $this->getGlobalObject();
            } elseif (
                !$thisValue instanceof JsObject
                && ($thisValue instanceof JsNumber
                    || $thisValue instanceof JsString
                    || $thisValue instanceof JsBoolean
                    || $thisValue instanceof JsSymbol
                    || $thisValue instanceof JsBigInt)
            ) {
                $thisValue = TypeConversion::toObject($thisValue);
            }
        }
        $savedTailPos = $this->inTailPosition;
        $this->inTailPosition = $this->strictMode;
        try {
            $vmReturn = $this->tryRunOnVm($fn, $fn->closure, $thisValue, $args);
        } catch (\Throwable $e) {
            $this->inTailPosition = $savedTailPos;
            $this->teardownExecuteFunction(
                $fn,
                $setCallerProp,
                $autoUpdateCaller,
                $autoUpdateArguments,
                $savedCaller,
                $savedArguments,
                $savedCallerValue,
                $savedArgumentsValue,
                $previousStrictMode,
                $previousModulePath,
                $switchModulePath,
            );
            throw $e;
        }
        $this->inTailPosition = $savedTailPos;
        $this->teardownExecuteFunction(
            $fn,
            $setCallerProp,
            $autoUpdateCaller,
            $autoUpdateArguments,
            $savedCaller,
            $savedArguments,
            $savedCallerValue,
            $savedArgumentsValue,
            $previousStrictMode,
            $previousModulePath,
            $switchModulePath,
        );
        if ($vmReturn === null) {
            // Compiler bailed (rare, on first call). Fall back via the
            // standard call entry point so the slow tree-walker takes
            // over correctly. The VM caller will pop the call stack
            // again — that's fine, callFunction's prologue pushes too.
            return $this->callFunction($fn, $thisValue, $args);
        }
        // Resolve tail-call thunks in-line so the VM caller gets a
        // plain JsValue back. Same loop as callFunction's trampoline.
        while ($vmReturn instanceof TailCallThunk) {
            $vmReturn = $this->callFunction(
                $vmReturn->function,
                $vmReturn->thisValue,
                $vmReturn->args,
            );
        }
        return $vmReturn;
    }

    /**
     * Restore Annex B caller / arguments slots and pop the call /
     * caller stacks. Shared by the executeFunction VM fast path and
     * (in spirit) the slow-path try/finally; the slow path inlines the
     * same teardown for clarity, but the fast path needs it from two
     * exit points (return + exception) so it lives here.
     */
    private function teardownExecuteFunction(
        JsFunction $fn,
        bool $setCallerProp,
        bool $autoUpdateCaller,
        bool $autoUpdateArguments,
        ?PropertyDescriptor $savedCaller,
        ?PropertyDescriptor $savedArguments,
        ?JsValue $savedCallerValue,
        ?JsValue $savedArgumentsValue,
        bool $previousStrictMode,
        ?string $previousModulePath = null,
        bool $restoreModulePath = false,
    ): void {
        if ($restoreModulePath) {
            $this->currentModulePath = $previousModulePath;
        }
        array_pop($this->callerStack);
        if ($setCallerProp) {
            if ($autoUpdateCaller) {
                if (!$fn->tryUpdateDataValue("caller", $savedCallerValue ?? JsNull::instance())) {
                    $fn->defineOwnProperty("caller", $savedCaller ?? PropertyDescriptor::data(
                        JsNull::instance(),
                        true,
                        false,
                        true,
                    ));
                }
            }
            if ($autoUpdateArguments) {
                if (!$fn->tryUpdateDataValue("arguments", $savedArgumentsValue ?? JsNull::instance())) {
                    $fn->defineOwnProperty("arguments", $savedArguments ?? PropertyDescriptor::data(
                        JsNull::instance(),
                        true,
                        false,
                        true,
                    ));
                }
            }
        }
        $this->strictMode = $previousStrictMode;
        $this->callStack->pop();
    }

    /**
     * Initialize the 'this' binding in the correct environment for derived constructors.
     * Walks the scope chain to find where 'this' is declared (in TDZ) and initializes it there.
     * This handles super() being called from arrow functions or nested blocks.
     */
    private function initializeThisBinding(Environment $env, JsValue $thisVal): void
    {
        // Walk up the scope chain to find the environment that has 'this' in TDZ.
        $current = $env;
        while ($current !== null) {
            if ($current->hasOwnBinding('this') || $current->hasLexicalBinding('this')) {
                // Found the environment with the 'this' binding.
                // Try to initialize it (if in TDZ) or set it (if already a var).
                try {
                    $current->initialize('this', $thisVal);
                } catch (\Throwable) {
                    // Not in TDZ, set it directly.
                    $current->set('this', $thisVal, false);
                }
                return;
            }
            $current = $current->getParent();
        }
        // Fallback: define in the current env.
        $env->defineLet('this', $thisVal);
    }

    /**
     * Handle implicit return (no return statement) for derived constructors.
     * Per spec 10.2.2 [[Construct]] step 13: if the derived constructor
     * completes without returning, GetThisBinding is called. If this was
     * never initialized (super() was never called), throw ReferenceError.
     */
    private function derivedConstructorImplicitReturn(JsFunction $fn, Environment $fnEnv): JsValue
    {
        if (!$fn->isDerivedConstructor()) {
            return JsUndefined::instance();
        }
        // Try to get the this binding. If it is still in TDZ, throw ReferenceError.
        // Per spec 10.2.2 [[Construct]] step 13/15: the callee context is
        // popped before the GetThisBinding check, so the ReferenceError is
        // built from the CALLER realm's intrinsics.
        try {
            return $fnEnv->get('this');
        } catch (\Throwable) {
            $err = new ReferenceError('Must call super constructor in derived class before returning from derived constructor');
            throw new \Phasis\Exceptions\JsThrowable($this->phpExceptionToJsValue($err));
        }
    }

    /**
     * Handle explicit return value for derived constructors.
     * Per spec 10.2.2 [[Construct]]:
     * - If result is an Object, return it (both base and derived).
     * - If derived and result is not undefined, throw TypeError.
     * - If derived and result is undefined, return GetThisBinding() (which may throw ReferenceError).
     */
    private function derivedConstructorReturn(JsFunction $fn, Environment $fnEnv, JsValue $value): JsValue
    {
        if (!$fn->isDerivedConstructor()) {
            return $value;
        }
        // If the constructor explicitly returned an object, use it.
        if ($value instanceof JsObject) {
            return $value;
        }
        // Per spec 10.2.2 [[Construct]] steps 13-14: pop the callee context
        // BEFORE the "non-Object return" / "this uninitialized" check, so
        // the TypeError / ReferenceError that surfaces here is constructed
        // from the CALLER realm's intrinsics (`$this->engineRealm`). The
        // callee realm switch in callFunction is purely an
        // Engine::currentInterpreter swap; `$this` is always the caller's
        // interpreter, so phpExceptionToJsValue on it produces the
        // caller-realm error the test262 cross-realm tests assert.
        if (!$value instanceof JsUndefined) {
            $err = new TypeError('Derived constructors may only return object or undefined');
            throw new \Phasis\Exceptions\JsThrowable($this->phpExceptionToJsValue($err));
        }
        // Returning undefined (or bare return): same as implicit return, check this binding.
        try {
            return $fnEnv->get('this');
        } catch (\Throwable) {
            $err = new ReferenceError('Must call super constructor in derived class before returning from derived constructor');
            throw new \Phasis\Exceptions\JsThrowable($this->phpExceptionToJsValue($err));
        }
    }

    /**
     * Execute an async function and wrap the result in a Promise.
     *
     * The body runs inside a PHP Fiber so each `await` can suspend
     * execution at the spec-correct point. The async function returns
     * a Promise immediately; the body's continuation runs after the
     * awaited promise settles, scheduled through the microtask queue
     * so that synchronous code in the caller observes the await as
     * a real suspension (matching V8's tick ordering).
     *
     * @param list<JsValue> $args
     */
    private function executeAsyncFunction(
        JsFunction $fn,
        JsValue $thisValue,
        array $args,
    ): \Phasis\Value\JsPromise {
        $promise = new \Phasis\Value\JsPromise();
        $interpreter = $this;
        $fiber = new \Fiber(function () use ($interpreter, $fn, $thisValue, $args): JsValue {
            try {
                return $interpreter->executeFunction($fn, $thisValue, $args);
            } catch (\Phasis\Exceptions\JsThrowable $e) {
                throw $e;
            }
        });
        // Persist the function's defining module path across fiber
        // resumes. After an `await`, the fiber's PHP stack has unwound,
        // so the executeFunction prologue's currentModulePath swap has
        // been reverted. Each fiber resume must restore it so dynamic
        // import() inside the body resolves relative specifiers
        // against the function's own module instead of the bare
        // top-level path that's active when the microtask drains.
        $this->driveAsyncFiber(
            $fiber,
            $promise,
            true,
            JsUndefined::instance(),
            modulePath: $fn->definingModulePath,
        );
        return $promise;
    }

    /**
     * Drive a Fiber that's running an async function body. Resumes the
     * fiber, follows AwaitSuspension markers through PromiseResolve,
     * and either schedules a microtask to continue or settles the outer
     * Promise when the fiber finishes.
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    private function driveAsyncFiber(
        \Fiber $fiber,
        \Phasis\Value\JsPromise $promise,
        bool $start,
        JsValue $resumeValue,
        bool $resumeAsThrow = false,
        ?JsValue $throwValue = null,
        ?string $modulePath = null,
    ): void {
        // Swap currentModulePath to the async function's defining
        // module for the duration of the resume so that import() and
        // import.meta inside the body see the right referrer.
        $previousModulePath = $this->currentModulePath;
        if ($modulePath !== null) {
            $this->currentModulePath = $modulePath;
        }
        try {
            if ($start) {
                $suspended = $fiber->start();
            } elseif ($resumeAsThrow) {
                $suspended = $fiber->throw(
                    new \Phasis\Exceptions\JsThrowable($throwValue ?? JsUndefined::instance()),
                );
            } else {
                $suspended = $fiber->resume($resumeValue);
            }
        } catch (\Phasis\Exceptions\JsThrowable $e) {
            $this->currentModulePath = $previousModulePath;
            $promise->reject($e->jsValue);
            return;
        } catch (\Phasis\Exceptions\RuntimeError $e) {
            $this->currentModulePath = $previousModulePath;
            $promise->reject($this->phpExceptionToJsValue($e));
            return;
        } catch (\Throwable $e) {
            $this->currentModulePath = $previousModulePath;
            $promise->reject($this->phpExceptionToJsValue($e));
            return;
        }
        // Restore on the normal-completion path too. If the fiber
        // suspended again we've already taken its module path off
        // the saved trail, and the next resume will reinstate it.
        $this->currentModulePath = $previousModulePath;

        if ($fiber->isTerminated()) {
            $returnValue = $fiber->getReturn();
            if (!$returnValue instanceof JsValue) {
                $returnValue = JsUndefined::instance();
            }
            $promise->resolve($returnValue);
            return;
        }

        if ($suspended instanceof \Phasis\Value\AwaitSuspension) {
            // Per spec Await(value): synchronously do PromiseResolve and
            // PerformPromiseThen, then suspend. The .then path itself queues
            // the resumption as a microtask, so we attach handlers
            // synchronously here. Wrapping in scheduleCallback would add
            // an extra tick that breaks await/microtask interleaving.
            $this->settleAwaitedAndResume($fiber, $promise, $suspended->value, $modulePath);
            return;
        }

        // Unknown suspension shape: treat as resolved with undefined.
        $promise->resolve(JsUndefined::instance());
    }

    /**
     * Resolve the awaited value (folding promise/thenable chains via
     * PromiseResolve) and resume the fiber once it settles.
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    public function settleAwaitedAndResume(
        \Fiber $fiber,
        \Phasis\Value\JsPromise $promise,
        JsValue $awaited,
        ?string $modulePath = null,
    ): void {
        // Per spec Await(value): PromiseResolve folds promises and thenables
        // into a single Promise; PerformPromiseThen attaches the resumption
        // handlers. .then on a fulfilled promise itself queues the handler
        // through one microtask, so we always go through .then to get spec
        // tick-ordering for await of fulfilled promises and plain values.
        $resolverPromise = $this->promiseResolve($awaited);
        $self = $this;
        $resolverPromise->then([
            JsFunction::fromCallable(
                '',
                static function (JsValue $this_, array $a) use ($self, $fiber, $promise, $modulePath): JsValue {
                    $self->driveAsyncFiber(
                        $fiber,
                        $promise,
                        false,
                        $a[0] ?? JsUndefined::instance(),
                        false,
                        null,
                        $modulePath,
                    );
                    return JsUndefined::instance();
                },
                1,
            ),
            JsFunction::fromCallable(
                '',
                static function (JsValue $this_, array $a) use ($self, $fiber, $promise, $modulePath): JsValue {
                    $self->driveAsyncFiber(
                        $fiber,
                        $promise,
                        false,
                        JsUndefined::instance(),
                        true,
                        $a[0] ?? JsUndefined::instance(),
                        $modulePath,
                    );
                    return JsUndefined::instance();
                },
                1,
            ),
        ]);
    }

    /**
     * Spec PromiseResolve: wrap a value in a Promise. JsPromise::resolve
     * already enqueues PromiseResolveThenableJob for thenables, so we
     * just delegate; the resulting promise stays PENDING until the
     * thenable's then() is called from the microtask queue.
     */
    private function promiseResolve(JsValue $value): \Phasis\Value\JsPromise
    {
        if ($value instanceof \Phasis\Value\JsPromise) {
            // Per spec PromiseResolve(C, x): if IsPromise(x), do
            // xConstructor = ? Get(x, "constructor"); if same as C, return x.
            // The .constructor getter is observable: tests intercept it via
            // Object.defineProperty(Promise.prototype, 'constructor', ...).
            try {
                $value->get('constructor');
            } catch (\Throwable) {
                // Getter throws: fall through and return the promise. The
                // observable side effect already fired.
            }
            return $value;
        }
        $p = new \Phasis\Value\JsPromise();
        $p->resolve($value);
        return $p;
    }

    /**
     * Create a JsGenerator for a generator function call.
     *
     * Instead of executing the body immediately, we wrap it in a Fiber
     * that the JsGenerator controls. The Fiber runs the function body
     * and suspends each time a yield expression is encountered.
     *
     * @param list<JsValue> $args
     */
    private function createGenerator(
        JsFunction $fn,
        JsValue $thisValue,
        array $args,
    ): JsGenerator {
        // Per spec, parameter binding (FunctionDeclarationInstantiation) happens
        // synchronously when the generator function is called, before the generator
        // object is returned. Only the body execution is deferred to the Fiber.
        $fnEnv = $fn->getClosure()->createChild();
        $fnEnv->setFunctionKind($fn->isAsync() ? 'async-generator' : 'generator');
        if ($this->isNonSimpleParameterList($fn->getParams())) {
            $fnEnv->setHasNonSimpleParams(true);
        }
        // Per spec OrdinaryCallBindThis: in sloppy mode, null/undefined `this`
        // is substituted with the global object and primitives are boxed;
        // in strict mode `this` is passed as-is.
        $body = $fn->getBody();
        $fnStrict = $fn->isStrict()
            || ($body instanceof BlockStatement && $this->hasUseStrictDirective($body->body));
        if (!$fn->isArrow() && !$fnStrict) {
            if ($thisValue instanceof JsUndefined || $thisValue instanceof \Phasis\Value\JsNull) {
                $thisValue = $this->getGlobalObject();
            } elseif (
                !$thisValue instanceof JsObject
                && ($thisValue instanceof JsNumber
                    || $thisValue instanceof JsString
                    || $thisValue instanceof JsBoolean
                    || $thisValue instanceof JsSymbol)
            ) {
                $thisValue = TypeConversion::toObject($thisValue);
            }
        }
        // Snapshot fast path: if the body is snapshot-safe (no
        // yield*, no try-with-yield, no await), the compiler will
        // have lowered it to bytecode with Op::YIELD. We can skip
        // the Fiber + env-bound param dance and hand the JsGenerator
        // an initial snapshot at pc=0 with params pre-loaded into
        // local slots. Falls through to the Fiber path on any
        // compile bailout.
        $snapshot = $this->tryBuildInitialGeneratorSnapshot(
            $fn,
            $thisValue,
            $args,
            $fnEnv,
            $fnStrict,
        );
        if ($snapshot !== null) {
            return JsGenerator::fromSnapshot($fn, $thisValue, $snapshot, $this);
        }
        $fnEnv->defineVar('this', $thisValue);
        // Bind [[HomeObject]] so super property access inside generator
        // methods resolves against the method's home object's prototype.
        $homeObject = $fn->getHomeObject();
        if ($homeObject !== null) {
            $fnEnv->defineVar('[[HomeObject]]', $homeObject);
        }
        // Per spec, generators are not [[Construct]]able so new.target inside
        // a generator body is always undefined. Bind it explicitly so
        // `new.target` and `eval('new.target')` resolve without walking up
        // and finding the caller's frame.
        $fnEnv->defineVar('[[NewTarget]]', JsUndefined::instance());
        $unmapped = $fnStrict || $this->isNonSimpleParameterList($fn->getParams());
        $argsObj = $this->makeArgumentsObject($args, $fn, $unmapped);
        $fnEnv->defineVar('arguments', $argsObj);
        $this->bindParameters($fn->getParams(), $args, $fnEnv);

        // Set up mapped arguments aliasing for generators.
        if (!$unmapped) {
            $this->setupMappedArguments($argsObj, $fn->getParams(), $args, $fnEnv);
        }

        $interpreter = $this;

        $executor = function (
            JsFunction $fn,
            JsValue $thisValue,
            array $args,
        ) use (
            $interpreter,
            $fnEnv
): JsValue {
            return $interpreter->executeGeneratorBody($fn, $thisValue, $args, $fnEnv);
        };

        return new JsGenerator($fn, $thisValue, $args, $executor);
    }

    /**
     * Drive a snapshot-mode JsGenerator one step: resume `VM::execute`
     * from the saved frame state and return the result of the next
     * suspend (yield) or completion (return).
     *
     * `$mode` controls how the resume value re-enters the body:
     *   - 'next':   value becomes the result of the yield expression
     *               that suspended the body
     *   - 'return': close the generator, returning $value
     *   - 'throw':  raise $value as an exception at the saved pc
     *
     * The Frame argument is a placeholder — execute() reads state
     * from $snapshot when $snapshot is non-null, but the signature
     * still requires a Frame.
     */
    public function resumeVmGenerator(
        \Phasis\Bytecode\GeneratorSnapshot $snapshot,
        ?JsValue $value,
        string $mode,
    ): \Phasis\Bytecode\YieldResult|JsValue {
        if ($this->vm === null) {
            $this->vm = new \Phasis\Bytecode\VM($this);
        }
        // 'return' mode for snapshot-mode generators: no try/finally
        // around any yield (the safety scan rejected those), so
        // closing without resuming is correct. The caller marks the
        // generator done and uses $value as the return value.
        if ($mode === 'return') {
            $snapshot->done = true;
            return $value ?? JsUndefined::instance();
        }
        $resumeThrow = $mode === 'throw';
        // VM::execute keeps a non-nullable Frame parameter for PHP
        // 8.5 tracing-JIT specialization (the nullable variant cost
        // ~13% on every test in the bench). Pass an empty
        // placeholder; execute() reads its state from $snapshot
        // when $resumeFrom is non-null, so the Frame's locals
        // array_fill is the only real overhead. Cheap.
        $placeholder = new \Phasis\Bytecode\Frame(
            env: $snapshot->env,
            thisValue: $snapshot->thisValue,
            slotCount: 1,
            undefined: JsUndefined::instance(),
        );
        $result = $this->vm->execute(
            $snapshot->cf,
            $placeholder,
            $snapshot,
            $value,
            $resumeThrow,
        );
        if (!$result instanceof \Phasis\Bytecode\YieldResult) {
            // Generator returned (RET at outer frame). Mark the
            // snapshot done so subsequent calls short-circuit.
            $snapshot->done = true;
        }
        return $result;
    }

    /**
     * Build an initial GeneratorSnapshot at pc=0 for a generator
     * whose body the bytecode compiler can lower. Returns null on
     * any bailout, in which case `createGenerator` falls back to
     * the Fiber-backed path.
     *
     * Snapshot mode skips the env-bound param dance (params go
     * directly into the compiled function's local slots) and the
     * arguments / [[HomeObject]] / [[NewTarget]] bindings (the
     * compiler bails on bodies that reference those, so they
     * can't be observed at runtime).
     *
     * @param list<JsValue> $args
     */
    private function tryBuildInitialGeneratorSnapshot(
        JsFunction $fn,
        JsValue $thisValue,
        array $args,
        \Phasis\Runtime\Environment $fnEnv,
        bool $fnStrict,
    ): ?\Phasis\Bytecode\GeneratorSnapshot {
        if ($fn->compileFailed) {
            return null;
        }
        // Compiler bailouts the runtime can't recover from also live
        // here: with-scope, async generators, derived constructors,
        // etc. createGenerator wouldn't have been called for the
        // latter two, but the with-scope check mirrors tryRunOnVm.
        if ($fn->getClosure()->isUnderWithScope()) {
            $fn->compileFailed = true;
            return null;
        }
        if ($fn->compiled === null) {
            try {
                $compiler = new \Phasis\Bytecode\Compiler();
                $fn->compiled = $compiler->compile($fn);
            } catch (\Phasis\Bytecode\CompilerBailout) {
                $fn->compileFailed = true;
                return null;
            } catch (\Throwable) {
                $fn->compileFailed = true;
                return null;
            }
        }
        $cf = $fn->compiled;
        $undef = JsUndefined::instance();
        $paramSlots = $cf->paramSlots;
        $paramCount = count($paramSlots);
        $locals = array_fill(0, max(1, $cf->slotCount), $undef);
        $argCount = count($args);
        for ($i = 0; $i < $paramCount; $i++) {
            $locals[$paramSlots[$i]] = $i < $argCount ? $args[$i] : $undef;
        }
        // The VM's LOAD_THIS opcode reads `this` via the env chain
        // (so derived-constructor TDZ + arrow lexical-this match the
        // tree-walker). Generator bodies that compile cleanly enough
        // to take this path can't reference new.target/arguments/eval
        // (compiler bails on those), but they CAN use `this` — bind
        // it in the env to ensure LOAD_THIS lands on the right value
        // and doesn't walk up to the global object's `this`.
        if ($cf->needsThis) {
            $fnEnv->defineVar('this', $thisValue);
        }
        // [[HomeObject]] for super references inside the generator
        // method's body. Mirrors the regular VM-compiled function
        // setup at line 4378.
        $homeObject = $fn->getHomeObject();
        if ($homeObject !== null) {
            $fnEnv->defineVar('[[HomeObject]]', $homeObject);
        }
        return new \Phasis\Bytecode\GeneratorSnapshot(
            cf: $cf,
            pc: 0,
            stack: [],
            sp: 0,
            locals: $locals,
            env: $fnEnv,
            thisValue: $thisValue,
            strict: $fnStrict,
        );
    }

    /**
     * Create a JsAsyncGenerator for an async generator function call.
     *
     * @param list<JsValue> $args
     */
    private function createAsyncGenerator(JsFunction $fn, JsValue $thisValue, array $args): JsAsyncGenerator
    {
        $fnEnv = $fn->getClosure()->createChild();
        $fnEnv->setFunctionKind('async-generator');
        if ($this->isNonSimpleParameterList($fn->getParams())) {
            $fnEnv->setHasNonSimpleParams(true);
        }
        $fnEnv->defineVar('this', $thisValue);
        // Bind [[HomeObject]] so super property access inside async generator
        // methods resolves against the method's home object's prototype.
        $homeObject = $fn->getHomeObject();
        if ($homeObject !== null) {
            $fnEnv->defineVar('[[HomeObject]]', $homeObject);
        }
        // Per spec, async generators are not [[Construct]]able so new.target
        // inside the body is always undefined.
        $fnEnv->defineVar('[[NewTarget]]', JsUndefined::instance());
        $unmapped = $this->strictMode || $this->isNonSimpleParameterList($fn->getParams());
        $argsObj = $this->makeArgumentsObject($args, $fn, $unmapped);
        $fnEnv->defineVar('arguments', $argsObj);
        $this->bindParameters($fn->getParams(), $args, $fnEnv);
        if (!$unmapped) {
            $this->setupMappedArguments($argsObj, $fn->getParams(), $args, $fnEnv);
        }
        $interpreter = $this;
        $executor = function (JsFunction $fn, JsValue $thisValue, array $args) use ($interpreter, $fnEnv): JsValue {
            return $interpreter->executeGeneratorBody($fn, $thisValue, $args, $fnEnv);
        };
        $errorConverter = function (\Throwable $e) use ($interpreter): JsValue {
            return $interpreter->phpExceptionToJsValue($e);
        };
        return new JsAsyncGenerator($fn, $thisValue, $args, $executor, $errorConverter);
    }

    /**
     * Execute a generator function body inside a Fiber.
     *
     * Called from inside the Fiber created by JsGenerator. This sets up the
     * environment and runs the body. When a yield expression is hit, the
     * interpreter calls Fiber::suspend(), which pauses execution until the
     * generator's next() method resumes the Fiber.
     *
     * @param list<JsValue> $args
     */
    public function executeGeneratorBody(
        JsFunction $fn,
        JsValue $thisValue,
        array $args,
        ?Environment $prebuiltEnv = null,
    ): JsValue {
        $this->callStack->push($fn->getName(), 0);

        try {
            // Use pre-built environment if provided (parameters already bound).
            if ($prebuiltEnv !== null) {
                $fnEnv = $prebuiltEnv;
            } else {
                $fnEnv = $fn->getClosure()->createChild();
                $fnEnv->defineVar('this', $thisValue);
                $unmapped = $this->strictMode || $this->isNonSimpleParameterList($fn->getParams());
                $argsObj = $this->makeArgumentsObject($args, $fn, $unmapped);
                $fnEnv->defineVar('arguments', $argsObj);
                $this->bindParameters($fn->getParams(), $args, $fnEnv);
                if (!$unmapped) {
                    $this->setupMappedArguments($argsObj, $fn->getParams(), $args, $fnEnv);
                }
            }

            // Execute body
            $body = $fn->getBody();

            // When the generator has parameter expressions (defaults, destructuring
            // with defaults), the body gets a separate environment so closures in
            // the parameter list do not see body-scoped var declarations.
            $params = $fn->getParams();
            $hasDefaultParams = $this->hasParameterExpressions($params);

            if ($hasDefaultParams && $body instanceof BlockStatement) {
                $bodyEnv = $fnEnv->createChild();
                foreach ($params as $p) {
                    $this->copyParamBindings($p, $fnEnv, $bodyEnv);
                }
                $bodyEnv->defineVar('arguments', $fnEnv->get('arguments'));
                $this->forceHoistVarNames($body->body, $bodyEnv);
                $this->hoistDeclarations($body->body, $bodyEnv);
                $this->hoistEvalLexicalDeclarations($body->body, $bodyEnv);
                $completion = $this->executeBody($body->body, $bodyEnv);
                $completion = $this->applyDisposals($bodyEnv, $completion);
                if ($completion->type === CompletionType::Return) {
                    return $completion->value;
                }
                if ($completion->type === CompletionType::Throw) {
                    $this->throwJsValue($completion->value);
                }
                return JsUndefined::instance();
            }

            if ($body instanceof BlockStatement) {
                $this->forceHoistVarNames($body->body, $fnEnv);
                $this->hoistDeclarations($body->body, $fnEnv);
                $this->hoistEvalLexicalDeclarations($body->body, $fnEnv);
                $completion = $this->executeBody($body->body, $fnEnv);
                $completion = $this->applyDisposals($fnEnv, $completion);
                if ($completion->type === CompletionType::Return) {
                    return $completion->value;
                }
                if ($completion->type === CompletionType::Throw) {
                    $this->throwJsValue($completion->value);
                }
                return JsUndefined::instance();
            }

            return $this->evaluate($body, $fnEnv);
        } finally {
            $this->callStack->pop();
        }
    }

    /**
     * @param Node[] $params
     * @param JsValue[] $args
     */
    /**
     * Create an arguments exotic object per ES spec 10.4.4.
     *
     * Non-strict: includes callee pointing to the function.
     * Strict or arrow: callee/caller are poison-pill accessors that throw TypeError.
     * Symbol.iterator is linked to Array.prototype[Symbol.iterator] if available.
     *
     * @param list<JsValue> $args
     */
    private function makeArgumentsObject(array $args, ?JsFunction $fn, bool $strictMode): JsObject
    {
        $argsObj = $strictMode ? new JsObject() : new JsArgumentsObject();
        $argsObj->defineOwnProperty('[[IsArguments]]', PropertyDescriptor::data(
            new JsBoolean(true),
            writable: false,
            enumerable: false,
            configurable: false,
        ));
        $argsObj->defineOwnProperty('length', PropertyDescriptor::data(
            JsNumber::of((float) count($args)),
            writable: true,
            enumerable: false,
            configurable: true,
        ));
        foreach ($args as $i => $arg) {
            $argsObj->defineOwnProperty((string) $i, PropertyDescriptor::data(
                $arg,
                writable: true,
                enumerable: true,
                configurable: true,
            ));
        }
        if (!$strictMode && $fn !== null) {
            $argsObj->defineOwnProperty('callee', PropertyDescriptor::data(
                $fn,
                writable: true,
                enumerable: false,
                configurable: true,
            ));
        } else {
            // Reuse the shared %ThrowTypeError% intrinsic if available,
            // so that the function identity matches across the engine per spec.
            $poisonPill = null;
            if ($this->globalEnv->has('__ThrowTypeError__')) {
                $tte = $this->globalEnv->get('__ThrowTypeError__');
                if ($tte instanceof JsFunction) {
                    $poisonPill = $tte;
                }
            }
            if ($poisonPill === null) {
                $poisonPill = JsFunction::fromCallable(
                    'ThrowTypeError',
                    function (): JsValue {
                        throw new TypeError(
                            "'caller', 'callee', and 'arguments' properties may not be accessed "
                            . 'on strict mode functions or the arguments objects for calls to them',
                        );
                    },
                );
            }
            $argsObj->defineOwnProperty('callee', PropertyDescriptor::accessor(
                get: $poisonPill,
                set: $poisonPill,
                enumerable: false,
                configurable: false,
            ));
            // Per ES2017+, strict mode arguments objects do NOT expose a
            // "caller" own property; only the poison-pilled "callee" remains.
            // (The historical ES5 erratum that added a poison-pilled "caller"
            // was reverted.)
        }
        // Link Symbol.iterator to Array.prototype[Symbol.iterator] if available.
        // Resolve through this realm's globalEnv rather than JsArray's
        // process-wide static so a sibling realm cannot leak its Array
        // intrinsic into a cross-realm arguments object.
        $iterSym = \Phasis\BuiltIn\SymbolConstructor::iterator();
        $arrayIterFn = null;
        $arrayProto = null;
        if ($this->globalEnv->has('Array')) {
            $arrayCtor = $this->globalEnv->get('Array');
            if ($arrayCtor instanceof JsObject) {
                $protoCandidate = $arrayCtor->get('prototype');
                if ($protoCandidate instanceof JsObject) {
                    $arrayProto = $protoCandidate;
                }
            }
        }
        if ($arrayProto === null) {
            $arrayProto = JsArray::getGlobalPrototype();
        }
        if ($arrayProto !== null && $arrayProto->hasBySymbol($iterSym)) {
            $iterVal = $arrayProto->getBySymbol($iterSym);
            if ($iterVal instanceof JsFunction) {
                $arrayIterFn = $iterVal;
            }
        }
        if ($arrayIterFn === null) {
            $arrayIterFn = JsFunction::fromCallable('values', function (JsValue $self_) use ($iterSym): JsValue {
                $obj = $self_ instanceof JsObject ? $self_ : new JsObject();
                $index = 0;
                $len = ($obj instanceof JsArray)
                    ? $obj->getLength()
                    : (int) \Phasis\Spec\TypeConversion::toNumber($obj->get('length'));
                $iterator = new JsObject();
                $nextFn = function () use ($obj, &$index, $len): JsValue {
                    $result = new JsObject();
                    if ($index < $len) {
                        $result->set('value', $obj->get((string) $index));
                        $result->set('done', new JsBoolean(false));
                        $index++;
                    } else {
                        $result->set('value', JsUndefined::instance());
                        $result->set('done', new JsBoolean(true));
                    }
                    return $result;
                };
                $iterator->set('next', JsFunction::fromCallable('next', $nextFn));
                $selfFn = JsFunction::fromCallable(
                    '[Symbol.iterator]',
                    function (JsValue $s): JsValue {
                        return $s;
                    },
                );
                $iterator->setBySymbol($iterSym, $selfFn);
                return $iterator;
            });
        }
        $argsObj->definePropertyBySymbol($iterSym, PropertyDescriptor::data(
            $arrayIterFn,
            writable: true,
            enumerable: false,
            configurable: true,
        ));
        return $argsObj;
    }

    /**
     * Set up mapped arguments aliasing per spec 10.4.4.7.
     *
     * In sloppy mode with simple parameters, for each parameter index i,
     * arguments[i] becomes an accessor that reads from and writes to the
     * parameter binding in the function environment. This creates live aliasing
     * so that modifying arguments[i] updates the parameter variable and vice versa.
     *
     * @param Node[] $params
     * @param list<JsValue> $args
     */
    private function setupMappedArguments(JsObject $argsObj, array $params, array $args, Environment $env): void
    {
        if (!$argsObj instanceof JsArgumentsObject) {
            return;
        }

        $mappedNames = [];
        // Only map indices up to the number of actual arguments passed.
        $argCount = count($args);
        // Walk parameters in reverse so that duplicate names map to the last occurrence.
        for ($i = min(count($params) - 1, $argCount - 1); $i >= 0; $i--) {
            $param = $params[$i];
            if (!$param instanceof Identifier) {
                continue;
            }
            $name = $param->name;
            if (isset($mappedNames[$name])) {
                continue;
            }
            $mappedNames[$name] = true;

            $index = (string) $i;
            // Register the mapping. The JsArgumentsObject handles get/set/defineProperty
            // through the parameter map per spec 10.4.4.
            $argsObj->addMapping($index, $name, $env);
        }
    }

    /**
     * @param array<mixed> $args
     * @param array<mixed> $params
     */
    private function bindParameters(array $params, array $args, Environment $env, ?bool $hasParamExpressions = null): void
    {
        // Per spec §10.2.1: when there are parameter default expressions,
        // all parameter names are initially in TDZ. Each is initialized in order,
        // so a default like `x = x` sees `x` as TDZ and throws ReferenceError.
        // The caller in the hot path passes the cached result so we avoid
        // re-walking the param list here.
        if ($hasParamExpressions ?? $this->hasParameterExpressions($params)) {
            foreach ($params as $param) {
                $target = $param instanceof RestElement
                    ? $param->argument
                    : ($param instanceof AssignmentPattern ? $param->left : $param);
                foreach ($this->patternBoundNames($target) as $name) {
                    $env->declareLet($name);
                }
            }
        }

        $paramCount = count($params);
        for ($i = 0; $i < $paramCount; $i++) {
            $param = $params[$i];
            // Fast path: simple Identifier param. The vast majority of
            // params are plain `(x, y, z)` and the bindPattern dispatch
            // is wasted work for them.
            if ($param instanceof Identifier) {
                $env->defineVar($param->name, $args[$i] ?? JsUndefined::instance());
                continue;
            }
            $value = $args[$i] ?? JsUndefined::instance();

            if ($param instanceof RestElement) {
                $restArray = JsArray::fromArray(array_slice($args, $i));
                $this->bindPattern($param->argument, $restArray, $env);
                break;
            }

            if ($param instanceof AssignmentPattern) {
                if ($value instanceof JsUndefined) {
                    $value = $this->evaluate($param->right, $env);
                    // Spec §10.2.1.2 step 8: when a single-name parameter
                    // initializer is an anonymous function definition, infer
                    // the parameter name onto the function. Mirrors the
                    // assignment / binding paths that already do this.
                    if (
                        $param->left instanceof Identifier
                        && $value instanceof JsFunction
                        && $this->isAnonymousFunctionDefinitionNode($param->right)
                        && !$this->hasExplicitNameProperty($value)
                    ) {
                        $value->setName($param->left->name);
                    }
                }
                $this->bindPattern($param->left, $value, $env);
                continue;
            }

            $this->bindPattern($param, $value, $env);
        }
    }

    /**
     * Per spec 10.2.11: a parameter list is non-simple if it contains
     * default values, rest elements, or destructuring patterns. Non-simple
     * parameter lists produce unmapped arguments objects.
     *
     * @param Node[] $params
     */
    private function isNonSimpleParameterList(array $params): bool
    {
        foreach ($params as $param) {
            if ($param instanceof AssignmentPattern) {
                return true;
            }
            if ($param instanceof RestElement) {
                return true;
            }
            if ($param instanceof ArrayPattern || $param instanceof ObjectPattern) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check whether any parameter uses a default value or destructuring with
     * a default, which requires a separate body environment per spec.
     *
     * @param Node[] $params
     */
    private function hasParameterExpressions(array $params): bool
    {
        foreach ($params as $param) {
            if ($param instanceof AssignmentPattern) {
                return true;
            }
            if ($param instanceof ArrayPattern || $param instanceof ObjectPattern) {
                if ($this->patternHasDefaults($param)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function patternHasDefaults(Node $pattern): bool
    {
        if ($pattern instanceof AssignmentPattern) {
            return true;
        }
        if ($pattern instanceof ArrayPattern) {
            foreach ($pattern->elements as $elem) {
                if ($elem !== null && $this->patternHasDefaults($elem)) {
                    return true;
                }
            }
        }
        if ($pattern instanceof ObjectPattern) {
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof AssignmentProperty && $this->patternHasDefaults($prop->value)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Check whether a node tree contains any closure-creating expression
     * (FunctionExpression, ArrowFunction, ClassExpression, or class/
     * function declarations nested inside expressions). Used to decide
     * whether a for-loop's per-iteration environment is observable. If
     * no closure is created in the body, the spec's per-iteration
     * binding semantics are not detectable and we can reuse the
     * loop env across iterations.
     */
    /**
     * Whether a function body (top-level statements) declares anything
     * the prologue passes (forceHoistVarNames / hoistDeclarations /
     * hoistEvalLexicalDeclarations) would act on. Recurses into the
     * control-flow nodes those passes descend into (if, for, for-in,
     * for-of, while, do-while, with, block, labeled, switch, try,
     * export); does NOT enter nested function/class bodies (each has
     * its own prologue scan). Returns true on any var/let/const/using/
     * function/class declaration. False means the three hoist calls
     * are pure overhead.
     *
     * @param Node[] $statements
     */
    private function bodyNeedsHoisting(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if ($this->statementHoists($stmt)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether top-level statements declare lexical bindings that
     * hoistEvalLexicalDeclarations needs to TDZ-declare: `let`, `const`,
     * `using`, `await using` VariableDeclarations, or top-level
     * ClassDeclarations. Mirrors the body of hoistEvalLexicalDeclarations
     * (which only walks the top level — no recursion into control flow).
     *
     * @param Node[] $statements
     */
    private function blockHoistsLexical(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if ($stmt instanceof ExportDeclaration && $stmt->declaration !== null) {
                $stmt = $stmt->declaration;
            }
            if ($stmt instanceof ClassDeclaration && $stmt->id !== null) {
                return true;
            }
            if (
                $stmt instanceof VariableDeclaration && (
                $stmt->kind === 'let'
                || $stmt->kind === 'const'
                || $stmt->kind === 'using'
                || $stmt->kind === 'await using'
                )
            ) {
                return true;
            }
        }
        return false;
    }

    private function statementHoists(Node $stmt): bool
    {
        if ($stmt instanceof ExportDeclaration && $stmt->declaration !== null) {
            $stmt = $stmt->declaration;
        }
        if (
            $stmt instanceof VariableDeclaration
            || $stmt instanceof FunctionDeclaration
            || $stmt instanceof ClassDeclaration
        ) {
            return true;
        }
        if ($stmt instanceof BlockStatement) {
            return $this->bodyNeedsHoisting($stmt->body);
        }
        if ($stmt instanceof IfStatement) {
            return $this->statementHoists($stmt->consequent)
                || ($stmt->alternate !== null && $this->statementHoists($stmt->alternate));
        }
        if ($stmt instanceof ForStatement) {
            if ($stmt->init instanceof VariableDeclaration) {
                return true;
            }
            return $this->statementHoists($stmt->body);
        }
        if ($stmt instanceof ForInStatement || $stmt instanceof ForOfStatement) {
            if ($stmt->left instanceof VariableDeclaration) {
                return true;
            }
            return $this->statementHoists($stmt->body);
        }
        if ($stmt instanceof WhileStatement || $stmt instanceof DoWhileStatement) {
            return $this->statementHoists($stmt->body);
        }
        if ($stmt instanceof WithStatement) {
            return $this->statementHoists($stmt->body);
        }
        if ($stmt instanceof LabeledStatement) {
            return $this->statementHoists($stmt->body);
        }
        if ($stmt instanceof SwitchStatement) {
            foreach ($stmt->cases as $case) {
                foreach ($case->consequent as $s) {
                    if ($this->statementHoists($s)) {
                        return true;
                    }
                }
            }
            return false;
        }
        if ($stmt instanceof TryStatement) {
            if ($this->bodyNeedsHoisting($stmt->block->body)) {
                return true;
            }
            if ($stmt->handler !== null) {
                if ($this->bodyNeedsHoisting($stmt->handler->body->body)) {
                    return true;
                }
            }
            if ($stmt->finalizer !== null && $this->bodyNeedsHoisting($stmt->finalizer->body)) {
                return true;
            }
            return false;
        }
        return false;
    }

    /**
     * Slow-path member read used by the bytecode VM when the receiver
     * is a primitive (string / number / boolean / bigint / symbol) or
     * a Symbol-wrapper object: just synthesise a MemberExpression
     * dispatch by calling the existing tree-walker helper rather than
     * duplicating the auto-box / prototype-walk logic.
     */
    public function vmLookupPrimitiveMember(JsValue $obj, string $name): JsValue
    {
        $synth = new MemberExpression(
            location: new \Phasis\Lexer\SourceLocation(0, 0, 0),
            object: new ThisExpression(new \Phasis\Lexer\SourceLocation(0, 0, 0)),
            property: new Identifier(new \Phasis\Lexer\SourceLocation(0, 0, 0), $name),
            computed: false,
            optional: false,
        );
        // Re-use evalMemberExpression's full path by stashing $obj as
        // the value of `this` for a one-shot scope. Cheaper to inline
        // the relevant branches here:
        if ($obj instanceof JsString) {
            if ($name === 'length') {
                return JsNumber::of((float) $obj->length());
            }
            if (ctype_digit($name)) {
                $idx = (int) $name;
                $u16 = JsString::utf8ToUtf16LE($obj->value);
                $u16Len = (int) (strlen($u16) / 2);
                if ($idx >= 0 && $idx < $u16Len) {
                    $codeUnit = ord($u16[$idx * 2]) | (ord($u16[$idx * 2 + 1]) << 8);
                    return new JsString(JsString::utf16CodeUnitToUtf8($codeUnit));
                }
                return JsUndefined::instance();
            }
            $proto = $this->cachedStringPrototype ??= $this->resolveCachedPrototype('__StringPrototype__');
            if ($proto instanceof JsObject) {
                $val = $proto->getWithValueReceiver($name, $obj);
                if (!$val instanceof JsUndefined) {
                    return $val;
                }
            }
            return JsUndefined::instance();
        }
        if ($obj instanceof JsObject) {
            // Symbol-wrapper → fall through to the full slow path.
            $primSlot = $obj->getOwnPropertyDescriptor('[[PrimitiveValue]]');
            if ($primSlot !== null && $primSlot->value instanceof JsSymbol) {
                $val = $this->lookupSymbolPrototypeProperty($primSlot->value, $name, false, null);
                if ($val !== null) {
                    return $val;
                }
            }
            return $obj->get($name);
        }
        // Number / Boolean / Symbol / BigInt primitive: route through
        // the auto-box helper that the tree-walker uses.
        $boxed = TypeConversion::toObject($obj);
        return $this->getPropertyWithPrimitiveReceiver($boxed, $name, false, null, $obj);
    }

    /**
     * VM helper: lower a nested ArrowFunction or FunctionExpression
     * AST node into a runtime JsFunction whose closure is the
     * caller's current env. Reuses the existing tree-walker
     * evaluators so spec-name-inference / strict-flag-propagation
     * stays correct.
     */
    /**
     * VM helper for Op::MAKE_CLASS: build a JsFunction (the class
     * constructor) by delegating to the tree-walker's class evaluator.
     * The class body, methods, super wiring, and instance fields all
     * still run in the tree-walker; this helper just lets the
     * enclosing function compile to bytecode rather than bailing on
     * the whole thing.
     */
    public function vmMakeClass(Node $node, Environment $env): JsValue
    {
        if ($node instanceof ClassExpression) {
            return $this->evalClassExpression($node, $env);
        }
        if ($node instanceof ClassDeclaration) {
            // Spec ClassDefinitionEvaluation: methods close over an
            // immutable inner binding for the class name. Replicate the
            // child env + declareConst + initialize pattern from
            // execClassDeclaration so super-method dispatch and
            // self-reference work the same way.
            $classEnv = $env;
            if ($node->id !== null) {
                $classEnv = $env->createChild();
                $classEnv->declareConst($node->id->name);
            }
            $cls = $this->buildClass($node->id?->name, $node->superClass, $node->body, $classEnv);
            if ($node->id !== null && $classEnv->isInTdz($node->id->name)) {
                $classEnv->initialize($node->id->name, $cls);
            }
            if ($node->sourceText !== null) {
                $cls->setSourceText($node->sourceText);
            }
            $cls = $this->applyClassDecorators($node->decorators, $cls, $env);
            return $cls;
        }
        throw new InternalError('vmMakeClass: ' . $node->type());
    }

    public function vmMakeFunction(Node $node, Environment $env): JsValue
    {
        if ($node instanceof ArrowFunction) {
            return $this->evalArrowFunction($node, $env);
        }
        if ($node instanceof FunctionExpression) {
            return $this->evalFunctionExpression($node, $env);
        }
        if ($node instanceof FunctionDeclaration) {
            // Materialise the JsFunction with $env as closure. The
            // prototype + name are wired by installFunctionPrototype.
            $fn = new JsFunction(
                $node->id !== null ? $node->id->name : '(anonymous)',
                $node->params,
                $node->body,
                $env,
                isGenerator: $node->generator,
                isAsync: $node->async,
                strict: $this->strictMode,
            );
            if ($node->sourceText !== null) {
                $fn->setSourceText($node->sourceText);
            }
            $this->installFunctionPrototype($fn, $node->generator, $node->async);
            return $fn;
        }
        throw new InternalError('vmMakeFunction: ' . $node->type());
    }

    /**
     * VM helper: spec-correct [[Set]] for a primitive receiver. The
     * tree-walker handles this inside Reference::setValue by walking
     * the wrapper's prototype chain manually (since JsObject::
     * internalSet requires a JsObject receiver). Replicate the same
     * walk here so the VM's STORE_MEMBER on a Symbol/Number/Boolean
     * primitive surfaces the spec-mandated TypeError in strict mode.
     */
    public function vmPrimitiveSet(JsValue $obj, string $name, JsValue $value): void
    {
        $strict = $this->strictMode;
        $wrapper = TypeConversion::toObject($obj);
        $cursor = $wrapper;
        $handled = false;
        while ($cursor !== null) {
            if ($cursor instanceof \Phasis\Value\JsProxy) {
                $handled = $cursor->internalSet($name, $value, $wrapper);
                break;
            }
            $desc = $cursor->getOwnPropertyDescriptor($name);
            if ($desc !== null) {
                if ($desc->set !== null) {
                    $desc->set->call($obj, [$value]);
                    $handled = true;
                } elseif ($desc->get !== null) {
                    $handled = false; // accessor without setter
                } else {
                    // Data property on prototype: would need to create
                    // a new own property on the receiver; primitives
                    // can't host own properties, so this is a no-op
                    // (sloppy) / TypeError (strict).
                    $handled = false;
                }
                break;
            }
            $cursor = $cursor->getPrototype();
        }
        if (!$handled && $strict) {
            throw new TypeError(
                "Cannot assign to read only property '{$name}' of a primitive"
            );
        }
    }

    /**
     * Symbol-keyed primitive [[Set]] for the VM (same shape as
     * vmPrimitiveSet but for Symbol keys).
     */
    public function vmPrimitiveSetSymbol(JsValue $obj, JsSymbol $sym, JsValue $value): void
    {
        $strict = $this->strictMode;
        $wrapper = TypeConversion::toObject($obj);
        $cursor = $wrapper;
        $handled = false;
        while ($cursor !== null) {
            if ($cursor instanceof \Phasis\Value\JsProxy) {
                $handled = $cursor->internalSetBySymbol($sym, $value, $wrapper);
                break;
            }
            $desc = $cursor->getSymbolPropertyDescriptor($sym);
            if ($desc !== null) {
                if ($desc->set !== null) {
                    $desc->set->call($obj, [$value]);
                    $handled = true;
                } else {
                    $handled = false;
                }
                break;
            }
            $cursor = $cursor->getPrototype();
        }
        if (!$handled && $strict) {
            throw new TypeError(
                'Cannot assign to read only symbol property of a primitive'
            );
        }
    }

    /**
     * Helpers the bytecode VM uses to delegate spec-correct branches
     * back into the tree-walker. Same-instance reuse keeps allocation
     * cost identical to the AST path.
     */
    public function vmNewObject(): JsObject
    {
        if ($this->cachedObjectPrototype === null) {
            if ($this->globalEnv->has('__ObjectPrototype__')) {
                $p = $this->globalEnv->get('__ObjectPrototype__');
                if ($p instanceof JsObject) {
                    $this->cachedObjectPrototype = $p;
                }
            }
        }
        return new JsObject($this->cachedObjectPrototype);
    }

    /**
     * @param list<JsValue> $args
     */
    public function vmNewExpression(JsValue $callee, array $args, Environment $env): JsValue
    {
        if ($callee instanceof \Phasis\Value\JsProxy) {
            return $callee->construct($args, $callee);
        }
        if (!$callee instanceof JsFunction || !$callee->isConstructable()) {
            throw new TypeError(TypeConversion::toString($callee) . ' is not a constructor');
        }
        // Mirror evalNewExpression's full setup so class field
        // initializers, derived-constructor checks, and the
        // [[NewTarget]] cleanup all match the tree-walker exactly.
        $proto = $callee->get('prototype');
        $newObj = new JsObject($proto instanceof JsObject ? $proto : null);
        $newObj->defineOwnProperty(
            '[[NewTarget]]',
            \Phasis\Object\PropertyDescriptor::data($callee, false, false, false),
        );
        // Base class constructor: initialize fields BEFORE the body
        // runs (derived constructors do this at their AST super() site).
        if ($callee->isClassConstructor() && !$callee->isDerivedConstructor()) {
            $this->initializeInstanceFields($callee, $newObj, $env);
        }
        $result = $this->callFunction($callee, $newObj, $args);
        if ($result instanceof JsObject) {
            // Native default-derived constructors don't run the AST
            // super() path so init their fields here post-call.
            if (
                $callee->isDerivedConstructor()
                && $callee->isNative()
                && ($callee->getPrivateMethodEntries() || $callee->getInstanceFieldInitializers())
            ) {
                $this->initializeInstanceFields($callee, $result, $env);
            }
            $result->forceDelete('[[NewTarget]]');
            return $result;
        }
        if ($callee->isDerivedConstructor() && !$result instanceof JsUndefined) {
            throw new TypeError('Derived constructors may only return object or undefined');
        }
        if (
            $callee->isDerivedConstructor()
            && $callee->isNative()
            && ($callee->getPrivateMethodEntries() || $callee->getInstanceFieldInitializers())
        ) {
            $this->initializeInstanceFields($callee, $newObj, $env);
        }
        $newObj->forceDelete('[[NewTarget]]');
        return $newObj;
    }

    public function vmLookupPrimitiveComputed(JsValue $obj, JsValue $key): JsValue
    {
        $resolved = TypeConversion::toPropertyKey($key);
        if ($resolved instanceof JsSymbol) {
            if ($obj instanceof JsObject) {
                return $obj->getBySymbol($resolved);
            }
            $boxed = TypeConversion::toObject($obj);
            return $this->getPropertyWithPrimitiveReceiver($boxed, '', true, $resolved, $obj);
        }
        $name = $resolved instanceof JsString ? $resolved->value : TypeConversion::toString($resolved);
        return $this->vmLookupPrimitiveMember($obj, $name);
    }

    /**
     * Try to dispatch a function body through the bytecode VM.
     * Returns the function's return JsValue (or TailCallThunk) on
     * success, or null when the function is not (or cannot be)
     * compiled — letting the caller fall back to executeBody.
     *
     * Compilation is lazy: the first call attempts to lower the body
     * via `Compiler::compile`. Bailouts mark the JsFunction so we
     * never retry. A successful compile populates `$fn->compiled` and
     * is reused on every subsequent call.
     *
     * @param list<JsValue> $args
     */
    private function tryRunOnVm(
        JsFunction $fn,
        Environment $fnEnv,
        JsValue $thisValue,
        array $args,
    ): ?JsValue {
        // JS-to-PHP fast path: when the body is a tight numeric subset
        // (arithmetic + locals + simple control flow, no calls / no
        // member access), JsToPhp lowers it to a PHP closure that
        // runs natively under PHP's tracing JIT. Bypasses the VM
        // dispatch loop entirely. First call attempts the lowering;
        // subsequent calls reuse the cached closure or skip the
        // attempt entirely on prior bailout.
        if (!$fn->phpCompileFailed) {
            // Functions defined inside a `with (...)` body resolve free
            // identifiers via the with-object's [[HasProperty]] hook,
            // which also threads the object as `this` for direct calls.
            // The PHP closure path skips that lookup so identifier-call
            // sites would never see the with-base. Fail compile in that
            // case so subsequent calls go through the interpreter.
            if ($fn->getClosure()->isUnderWithScope()) {
                $fn->phpCompileFailed = true;
            }
        }
        if (!$fn->phpCompileFailed) {
            if ($fn->phpCompiled === null) {
                try {
                    $fn->phpCompiled = \Phasis\Bytecode\JsToPhp::compile($fn);
                } catch (\Throwable) {
                    $fn->phpCompiled = null;
                }
                if ($fn->phpCompiled === null) {
                    $fn->phpCompileFailed = true;
                }
            }
            if ($fn->phpCompiled !== null) {
                try {
                    return ($fn->phpCompiled)($args, $fnEnv, $this, $fn->phpCompiledNodes);
                } catch (\Phasis\Bytecode\Bailout) {
                    // Numeric assumption broken at run time (e.g. a
                    // non-JsNumber arg). Fall through to the VM /
                    // tree-walker path so this call still completes
                    // correctly. The closure stays cached for future
                    // calls that satisfy the numeric invariant.
                }
            }
        }
        if ($fn->compileFailed) {
            return null;
        }
        // Functions defined under a `with (...)` body need with-aware
        // identifier lookups for free-variable callees so the call site
        // can read the with-base off the looked-up reference and pass
        // it as `this`. The bytecode compiler skips that path; route
        // such functions through the tree-walker instead.
        if ($fn->getClosure()->isUnderWithScope()) {
            $fn->compileFailed = true;
            return null;
        }
        if ($fn->compiled === null) {
            // Phase 2 compiler is conservative: any unsupported AST
            // node throws CompilerBailout; fail-closed and never retry.
            try {
                $compiler = new \Phasis\Bytecode\Compiler();
                $fn->compiled = $compiler->compile($fn);
            } catch (\Phasis\Bytecode\CompilerBailout) {
                $fn->compileFailed = true;
                return null;
            } catch (\Throwable $e) {
                // Defensive: a compiler bug must never break the
                // tree-walker fallback. Mark uncompilable and let the
                // body run normally.
                $fn->compileFailed = true;
                return null;
            }
        }
        $cf = $fn->compiled;
        $undef = JsUndefined::instance();
        $paramSlots = $cf->paramSlots;
        $paramCount = count($paramSlots);
        // Pool a Frame per active call depth: at depth N, reuse
        // framePool[N] if it exists, else allocate one and stash it.
        // Reset clears non-param slots only; param slots are about
        // to be overwritten by the param-binding loop. Stack is left
        // in place since sp = 0 makes prior entries unreachable.
        $depth = $this->framePoolDepth;
        if ($depth < count($this->framePool)) {
            $frame = $this->framePool[$depth];
            $frame->reset($fnEnv, $thisValue, $cf->slotCount, $paramCount, $undef);
        } else {
            $frame = new \Phasis\Bytecode\Frame(
                env: $fnEnv,
                thisValue: $thisValue,
                slotCount: $cf->slotCount,
                undefined: $undef,
            );
            $this->framePool[] = $frame;
        }
        $this->framePoolDepth = $depth + 1;
        // Wire parameter slots: the compiler assigned each parameter
        // a numbered slot in $paramSlots; the runtime args go straight
        // into those slots without going through env->defineVar.
        $argCount = count($args);
        for ($i = 0; $i < $paramCount; $i++) {
            $frame->locals[$paramSlots[$i]] = $i < $argCount ? $args[$i] : $undef;
        }
        if ($this->vm === null) {
            $this->vm = new \Phasis\Bytecode\VM($this);
        }
        try {
            return $this->vm->execute($cf, $frame);
        } finally {
            $this->framePoolDepth = $depth;
        }
    }

    /**
     * Annex B legacy `function.caller`: detect whether the descriptor
     * still holds the engine's default shape (data, writable, non-
     * enumerable, configurable, with null or a recognized prior caller
     * function value). User-assigned values are left untouched on entry
     * and exit.
     */
    private static function isEngineDefaultCaller(?PropertyDescriptor $d, ?JsFunction $callerFn): bool
    {
        if (
            $d === null
            || !$d->isDataDescriptor()
            || ($d->writable ?? false) !== true
            || ($d->enumerable ?? true) !== false
            || ($d->configurable ?? false) !== true
        ) {
            return false;
        }
        $v = $d->value;
        return $v instanceof JsNull
            || ($v instanceof JsFunction && $v === $callerFn);
    }

    /**
     * Annex B legacy `function.arguments`: detect whether the descriptor
     * holds the engine's default shape, with either null (install-time)
     * or a previously recorded arguments object. User-overridden values
     * survive the call unchanged.
     */
    private static function isEngineDefaultArguments(?PropertyDescriptor $d): bool
    {
        if (
            $d === null
            || !$d->isDataDescriptor()
            || ($d->writable ?? false) !== true
            || ($d->enumerable ?? true) !== false
            || ($d->configurable ?? false) !== true
        ) {
            return false;
        }
        $v = $d->value;
        if ($v instanceof JsNull) {
            return true;
        }
        if ($v instanceof JsObject) {
            return $v->getOwnPropertyDescriptor('[[IsArguments]]') !== null;
        }
        return false;
    }

    /**
     * Whether the program body references the identifier `eval`
     * anywhere — used as a callee, captured into a variable, passed
     * as an argument, etc. Conservatively treats any such reference
     * as a potential direct-eval site, disabling the Identifier
     * scope-depth cache. `with` statements are not considered here:
     * they are gated separately via hasAnyWithObjectInChain at
     * lookup time, which is O(1) thanks to the precomputed flag.
     *
     * @param Node[] $statements
     */
    private function programReferencesEval(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if ($this->subtreeReferencesEval($stmt)) {
                return true;
            }
        }
        return false;
    }

    private function subtreeReferencesEval(Node $node): bool
    {
        if ($node instanceof Identifier && $node->name === 'eval') {
            return true;
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                if ($this->subtreeReferencesEval($value)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && $this->subtreeReferencesEval($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private function nodeContainsClosure(?Node $node): bool
    {
        if ($node === null) {
            return false;
        }
        if (
            $node instanceof FunctionExpression
            || $node instanceof ArrowFunction
            || $node instanceof FunctionDeclaration
            || $node instanceof ClassExpression
            || $node instanceof ClassDeclaration
        ) {
            return true;
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                if ($this->nodeContainsClosure($value)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && $this->nodeContainsClosure($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Check whether a function body or its parameter list references the
     * `arguments` object (directly, via `eval`, or through a `with` block
     * that could shadow lookups). Used by executeFunction to skip building
     * the arguments-exotic object when the function provably does not
     * observe it. Result is cached per JsFunction.
     *
     * @param Node[] $params
     */
    private function bodyUsesArguments(mixed $body, array $params = []): bool
    {
        foreach ($params as $param) {
            if ($this->nodeReferencesArguments($param)) {
                return true;
            }
        }
        if ($body === null) {
            return false;
        }
        if (is_array($body)) {
            foreach ($body as $stmt) {
                if ($stmt instanceof Node && $this->nodeReferencesArguments($stmt)) {
                    return true;
                }
            }
            return false;
        }
        if ($body instanceof Node) {
            return $this->nodeReferencesArguments($body);
        }
        return false;
    }

    private function nodeReferencesArguments(Node $node): bool
    {
        if ($node instanceof Identifier) {
            // Direct eval can introspect arguments, so any `eval` reference
            // is treated as a use. Identifier `arguments` is the obvious case.
            return $node->name === 'arguments' || $node->name === 'eval';
        }
        if ($node instanceof WithStatement) {
            // `with` introduces a dynamic binding layer; conservatively
            // assume the body could read arguments through it.
            return true;
        }
        // Stop at nested non-arrow function and class boundaries: those
        // create their own arguments binding (or, for classes, none).
        // Arrow functions inherit the enclosing arguments per spec, so
        // we DO descend into them.
        if (
            $node instanceof FunctionExpression
            || $node instanceof FunctionDeclaration
            || $node instanceof ClassDeclaration
            || $node instanceof ClassExpression
        ) {
            return false;
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                if ($this->nodeReferencesArguments($value)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && $this->nodeReferencesArguments($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Decide whether the implicit `arguments` binding should block Annex B
     * legacy hoisting of a same-named block-scoped `function arguments() {}`.
     *
     * Spec FunctionDeclarationInstantiation step 22.f appends "arguments" to
     * parameterNames when an arguments object is created, which by Annex B
     * B.3.2.1 step 1.b.ii blocks the var-binding hoist. V8 / SpiderMonkey
     * relax that to keep `function arguments(){}` overriding the args object
     * AS LONG AS the enclosing block contains nothing besides the function
     * declaration that observes `arguments`. When a sibling statement in the
     * SAME block as `function arguments(){}` references the `arguments`
     * Identifier (excluding the func decl's own body and excluding nested
     * non-arrow function / class bodies), the var hoist is suppressed and
     * the implicit args object survives the block.
     *
     * Tests covered:
     *  - annexB/.../block-decl-func-skip-arguments: sibling `arguments()`
     *    calls in the block → suppress → args object stays.
     *  - staging/sm/lexical-environment/block-scoped-functions-annex-b-arguments:
     *    block has only the func decl → allow Annex B → arguments becomes
     *    the function after the block.
     *  - staging/sm/regress/regress-602621: block has only the func decl →
     *    allow Annex B → `return arguments` returns the function.
     *
     * @param Node[] $statements Function body statement list.
     */
    private function bodyShouldBlockArgumentsAnnexB(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if ($this->subtreeBlocksArgumentsAnnexB($stmt)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Recursively look for a BlockStatement that declares
     * `function arguments(){}` AND contains some other child statement
     * that references the `arguments` Identifier. Stops at nested non-arrow
     * function and class bodies (those are separate `arguments` scopes).
     */
    private function subtreeBlocksArgumentsAnnexB(Node $node): bool
    {
        if (
            $node instanceof FunctionExpression
            || $node instanceof FunctionDeclaration
            || $node instanceof ClassDeclaration
            || $node instanceof ClassExpression
        ) {
            return false;
        }
        if ($node instanceof BlockStatement && $this->blockSuppressesArgumentsAnnexB($node)) {
            return true;
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                if ($this->subtreeBlocksArgumentsAnnexB($value)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && $this->subtreeBlocksArgumentsAnnexB($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * True when this block declares `function arguments(){}` AND has at
     * least one OTHER child statement that textually references the
     * `arguments` Identifier.
     */
    private function blockSuppressesArgumentsAnnexB(BlockStatement $block): bool
    {
        $hasFuncDecl = false;
        $siblingRef = false;
        foreach ($block->body as $child) {
            $inner = $child;
            if ($inner instanceof LabeledStatement) {
                $inner = $inner->body;
            }
            $isArgsFuncDecl = $inner instanceof FunctionDeclaration
                && $inner->id !== null
                && $inner->id->name === 'arguments'
                && !$inner->async
                && !$inner->generator;
            if ($isArgsFuncDecl) {
                $hasFuncDecl = true;
                continue;
            }
            if (!$siblingRef && $this->nodeReferencesArgumentsIdentifier($child)) {
                $siblingRef = true;
            }
        }
        return $hasFuncDecl && $siblingRef;
    }

    /**
     * Whether the subtree contains a free `arguments` Identifier reference
     * (no descent into nested non-arrow function or class bodies). Differs
     * from nodeReferencesArguments by NOT treating bare `eval` references
     * or `with` as implicit arguments uses — only literal `arguments`.
     */
    private function nodeReferencesArgumentsIdentifier(Node $node): bool
    {
        if ($node instanceof Identifier) {
            return $node->name === 'arguments';
        }
        if (
            $node instanceof FunctionExpression
            || $node instanceof FunctionDeclaration
            || $node instanceof ClassDeclaration
            || $node instanceof ClassExpression
        ) {
            return false;
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                if ($this->nodeReferencesArgumentsIdentifier($value)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && $this->nodeReferencesArgumentsIdentifier($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Copy parameter name bindings from param environment to body environment.
     */
    private function copyParamBindings(Node $param, Environment $source, Environment $target): void
    {
        if ($param instanceof Identifier) {
            $target->defineVar($param->name, $source->get($param->name));
        } elseif ($param instanceof AssignmentPattern) {
            $this->copyParamBindings($param->left, $source, $target);
        } elseif ($param instanceof ArrayPattern) {
            foreach ($param->elements as $elem) {
                if ($elem !== null) {
                    $this->copyParamBindings($elem, $source, $target);
                }
            }
        } elseif ($param instanceof ObjectPattern) {
            foreach ($param->properties as $prop) {
                if ($prop instanceof AssignmentProperty) {
                    $this->copyParamBindings($prop->value, $source, $target);
                } elseif ($prop instanceof RestElement) {
                    $this->copyParamBindings($prop->argument, $source, $target);
                }
            }
        } elseif ($param instanceof RestElement) {
            $this->copyParamBindings($param->argument, $source, $target);
        }
    }

    private function bindPattern(Node $pattern, JsValue $value, Environment $env): void
    {
        if ($pattern instanceof Identifier) {
            // Use initializeTdz so that if the name was pre-declared in TDZ
            // (for default-param TDZ semantics), we properly clear it.
            // If not in TDZ, this falls back to defineVar.
            $env->initializeTdz($pattern->name, $value);
            return;
        }

        if ($pattern instanceof ArrayPattern) {
            $this->bindArrayPattern($pattern, $value, $env);
            return;
        }

        if ($pattern instanceof ObjectPattern) {
            $this->bindObjectPattern($pattern, $value, $env);
            return;
        }

        if ($pattern instanceof AssignmentPattern) {
            if ($value instanceof JsUndefined) {
                $value = $this->evaluate($pattern->right, $env);
                // Function name inference: only when the default is an anonymous function
                // definition (not a sequence expression, etc.) and the binding is a simple identifier.
                // Per spec 13.3.3.7: check HasOwnProperty('name') — only infer if name is absent
                // or is the default empty string (anonymous), not if overridden (e.g. static name() {}).
                if (
                    $value instanceof JsFunction
                    && $pattern->left instanceof Identifier
                    && $this->isAnonymousFunctionDefinitionNode($pattern->right)
                ) {
                    $nameDesc = $value->getOwnPropertyDescriptor('name');
                    $isEmptyName = $nameDesc === null
                        || ($nameDesc->value instanceof JsString && $nameDesc->value->value === '');
                    if ($isEmptyName) {
                        $value->setName($pattern->left->name);
                    }
                }
            }
            $this->bindPattern($pattern->left, $value, $env);
            return;
        }

        throw new InternalError('Unknown parameter pattern: ' . $pattern->type());
    }

    private function bindArrayPattern(ArrayPattern $pattern, JsValue $value, Environment $env): void
    {
        if ($value instanceof JsNull || $value instanceof JsUndefined) {
            throw new \Phasis\Exceptions\TypeError(
                TypeConversion::toString($value) . ' is not iterable',
            );
        }
        [$iterator, $nextMethod] = $this->getIteratorOrThrow($value);
        $done = false;
        try {
            foreach ($pattern->elements as $element) {
                if ($element instanceof RestElement) {
                    $this->bindPattern($element->argument, $this->iteratorRest($iterator, $nextMethod, $done), $env);
                    $done = true; // rest consumes remaining elements
                    break;
                }
                $elemValue = $this->iteratorNext($iterator, $nextMethod, $done);
                if ($element === null) {
                    // Elision: advance iterator but discard value.
                    continue;
                }
                $this->bindPattern($element, $elemValue, $env);
            }
        } catch (\Throwable $e) {
            if (!$done) {
                $this->iteratorClose($iterator, $e);
            }
            throw $e;
        }
        // Per spec 13.3.3.5: if iterator is not exhausted, close it via return().
        if (!$done) {
            $this->iteratorClose($iterator);
        }
    }

    private function bindObjectPattern(ObjectPattern $pattern, JsValue $value, Environment $env): void
    {
        if ($value instanceof JsNull || $value instanceof JsUndefined) {
            throw new \Phasis\Exceptions\TypeError(
                "Cannot destructure property of " . TypeConversion::toString($value),
            );
        }
        $usedKeys = [];
        $usedSymIds = [];
        foreach ($pattern->properties as $prop) {
            if ($prop instanceof RestElement) {
                $restObj = new JsObject();
                if ($value instanceof JsObject) {
                    // Per §14.3.3.1 RestBindingInitialization: delegate to
                    // CopyDataProperties so Proxy ownKeys traps fire, string
                    // and symbol keys are both copied, and prior destructuring
                    // captures are excluded.
                    $this->copyRestDataProperties($value, $restObj, $usedKeys, $usedSymIds);
                }
                $this->bindPattern($prop->argument, $restObj, $env);
                continue;
            }

            if ($prop instanceof AssignmentProperty) {
                if ($prop->computed) {
                    $rawKey = $this->evaluate($prop->key, $env);
                    if ($rawKey instanceof JsSymbol) {
                        $usedSymIds[$rawKey->getId()] = true;
                        $propValue = $this->getVForDestructuring($value, null, $rawKey);
                        $this->bindPattern($prop->value, $propValue, $env);
                        continue;
                    }
                    $key = TypeConversion::toString($rawKey);
                } else {
                    $key = $prop->key instanceof Identifier
                        ? $prop->key->name
                        : TypeConversion::toString($this->evaluate($prop->key, $env));
                }
                $usedKeys[] = $key;
                $propValue = $this->getVForDestructuring($value, $key, null);
                $this->bindPattern($prop->value, $propValue, $env);
            }
        }
    }

    /**
     * GetV for destructuring: ToObject(value) for the lookup, then [[Get]]
     * with the primitive as the receiver. Reading a property like
     * __proto__ from a primitive (e.g. `const {__proto__: x} = "s"`)
     * resolves through String.prototype, returning the prototype itself.
     */
    private function getVForDestructuring(JsValue $value, ?string $key, ?JsSymbol $symbolKey): JsValue
    {
        if ($value instanceof JsObject) {
            return $symbolKey !== null
                ? $value->getBySymbol($symbolKey)
                : $value->get($key);
        }
        if ($value instanceof JsUndefined || $value instanceof JsNull) {
            return JsUndefined::instance();
        }
        $boxed = TypeConversion::toObject($value);
        if ($symbolKey !== null) {
            return $boxed->getBySymbolWithReceiver($symbolKey, $value);
        }
        return $boxed->getWithValueReceiver($key, $value);
    }

    private function evalMemberExpression(MemberExpression $node, Environment $env): JsValue
    {
        // super.prop or super[expr]: look up on [[HomeObject]].[[Prototype]].
        if ($node->object instanceof Identifier && $node->object->name === 'super') {
            try {
                $homeObject = $env->get('[[HomeObject]]');
            } catch (\Throwable) {
                $homeObject = null;
            }
            // Per spec 13.3.7.1 step 2: GetThisBinding is called FIRST,
            // before evaluating the property expression. In a derived
            // constructor before super(), this throws ReferenceError —
            // the property expression must not run in that case.
            $superThisRead = $env->get('this');
            // Per spec 12.3.5.1: for super[expr], evaluate the property
            // expression next (yielding a JsValue, NOT yet a property key),
            // then read GetSuperBase. ToPropertyKey runs LAST, in
            // GetValue. The home object's [[Prototype]] may be mutated by
            // either the expression itself or the ToPropertyKey conversion
            // (via toString); GetSuperBase observes the value at the moment
            // between the two.
            $rawKey = null;
            if ($node->computed) {
                $rawKey = $this->evaluate($node->property, $env);
            }
            $superBase = $homeObject instanceof JsObject ? $homeObject->getPrototype() : null;
            // RequireObjectCoercible: if superBase is null, throw TypeError (spec §12.3.5.3 step 5).
            if ($superBase === null) {
                throw new TypeError(
                    "Cannot read properties of undefined (super)",
                );
            }
            // Now (after GetSuperBase) coerce the property name. A Symbol
            // returned via a custom toString stays a Symbol key.
            if ($node->computed) {
                $rawKey = TypeConversion::toPropertyKey($rawKey);
            }
            // Per GetThisValue + [[Get]], the actualThis (which may be a
            // primitive in strict mode methods) is passed to the getter as
            // its `this`. Use getWithValueReceiver so primitives pass through
            // unboxed instead of being substituted with the super base.
            if ($node->computed) {
                if ($rawKey instanceof JsSymbol) {
                    return $superBase->getBySymbolWithReceiver(
                        $rawKey,
                        $superThisRead instanceof JsObject ? $superThisRead : $superBase,
                    );
                }
                $keyStr = $rawKey instanceof JsString ? $rawKey->value : TypeConversion::toString($rawKey);
                return $superBase->getWithValueReceiver($keyStr, $superThisRead);
            }
            $key = $node->property instanceof Identifier
                ? $node->property->name
                : TypeConversion::toString($this->evaluate($node->property, $env));
            return $superBase->getWithValueReceiver($key, $superThisRead);
        }

        $obj = $this->evaluate($node->object, $env);

        // Propagate optional chain short-circuit through the chain.
        if ($obj instanceof JsOptionalUndefined) {
            return $obj;
        }

        if ($node->optional && ($obj instanceof JsNull || $obj instanceof JsUndefined)) {
            return JsOptionalUndefined::instance();
        }

        // Fast path: `obj.ident` on a plain object (not a primitive
        // wrapper, not a Function/Proxy needing special dispatch).
        // Skips the JsString/JsSymbol/JsBigInt/auto-box ladder and
        // the toPropertyKey conversion. Hot obj-prop case lives here.
        if (
            !$node->computed
            && $node->property instanceof Identifier
            && $obj instanceof JsObject
            && $obj->getOwnPropertyDescriptor('[[PrimitiveValue]]') === null
        ) {
            return $obj->get($node->property->name);
        }

        // Private identifier access: obj.#name
        if ($node->property instanceof PrivateIdentifier) {
            if (!($obj instanceof JsObject)) {
                throw new TypeError(
                    'Cannot read private member ' . $node->property->name . ' from a non-object',
                );
            }
            $brandedName = $env->resolvePrivateName($node->property->name);
            return $obj->getPrivateField($brandedName);
        }

        // Evaluate the property key expression. For computed access, the key
        // expression is evaluated first, but ToPropertyKey (which calls toString)
        // is deferred until after ToObject(base), per spec 13.3.3.
        $rawKey = null;
        if ($node->computed) {
            $rawKey = $this->evaluate($node->property, $env);
            // The computed property expression is a separate expression context.
            // If it contained an optional chain (e.g. c?.[a?.b]), the inner chain's
            // JsOptionalUndefined sentinel must be unwrapped to JsUndefined here.
            if ($rawKey instanceof JsOptionalUndefined) {
                $rawKey = JsUndefined::instance();
            }
        }

        // Per spec: ToObject(base) precedes ToPropertyKey(key). Accessing a
        // property on null or undefined must throw TypeError before converting
        // the key, so toString() on the key object is never called.
        if ($obj instanceof JsNull || $obj instanceof JsUndefined) {
            $baseDesc = $obj instanceof JsNull ? 'null' : 'undefined';
            // Use display() for the error message to avoid triggering toString on the key.
            if ($node->computed) {
                $keyDesc = $rawKey->display();
            } else {
                $keyDesc = $node->property instanceof Identifier ? $node->property->name : '';
            }
            throw new TypeError("Cannot read properties of {$baseDesc} (reading '{$keyDesc}')");
        }

        // Spec ToPropertyKey: a JsSymbol stays a Symbol key; everything else
        // funnels through ToPrimitive(value, "string") then ToString. A
        // Symbol wrapper object's @@toPrimitive returns the unboxed Symbol,
        // so the key still ends up as a Symbol (not "Symbol(...)").
        if ($node->computed) {
            $rawKey = TypeConversion::toPropertyKey($rawKey);
        }
        $isSymbolKey = $rawKey instanceof JsSymbol;
        $key = $isSymbolKey ? '' : ($node->computed
            ? ($rawKey instanceof JsString ? $rawKey->value : TypeConversion::toString($rawKey))
            : ($node->property instanceof Identifier ? $node->property->name : ''));

        // Symbol-keyed access on strings: only Symbol.iterator is meaningful.
        if ($obj instanceof JsString && $isSymbolKey) {
            $iterSym = \Phasis\BuiltIn\SymbolConstructor::iterator();
            if ($rawKey === $iterSym) {
                return $this->createStringIteratorFactory($obj);
            }
            return JsUndefined::instance();
        }

        // String property access (length, indices, prototype methods)
        if ($obj instanceof JsString) {
            if ($key === 'length') {
                return JsNumber::of((float) $obj->length());
            }
            if (ctype_digit($key)) {
                $idx = (int) $key;
                // JavaScript string indexing uses UTF-16 code units.
                $u16 = JsString::utf8ToUtf16LE($obj->value);
                $u16Len = (int) (strlen($u16) / 2);
                if ($idx >= 0 && $idx < $u16Len) {
                    $codeUnit = ord($u16[$idx * 2]) | (ord($u16[$idx * 2 + 1]) << 8);
                    return new JsString(JsString::utf16CodeUnitToUtf8($codeUnit));
                }
                return JsUndefined::instance();
            }
            // Check for String.prototype properties via global String.prototype.
            // Use getWithValueReceiver so accessor getters observe the
            // primitive string as their `this` (per spec OrdinaryGet for a
            // primitive receiver). Method-call this-binding is handled by
            // the CallExpression evaluator, not here.
            $proto = $this->cachedStringPrototype ??= $this->resolveCachedPrototype('__StringPrototype__');
            if ($proto instanceof JsObject) {
                $val = $proto->getWithValueReceiver($key, $obj);
                if (!$val instanceof JsUndefined) {
                    return $val;
                }
            }
            return JsUndefined::instance();
        }

        // Symbol property access (description, toString, valueOf via Symbol.prototype)
        if ($obj instanceof JsSymbol) {
            if ($key === 'description') {
                $desc = $obj->getDescription();
                return $desc !== null ? new JsString($desc) : JsUndefined::instance();
            }
            if ($key === 'toString') {
                $sym = $obj;
                return JsFunction::fromCallable('toString', function () use ($sym): JsValue {
                    return new JsString($sym->toString());
                }, 0);
            }
            if ($key === 'valueOf') {
                $sym = $obj;
                return JsFunction::fromCallable('valueOf', function () use ($sym): JsValue {
                    return $sym;
                }, 0);
            }
            // Check Symbol.prototype from global env. Use the primitive Symbol
            // as the receiver so accessor getters observe `this === sym`
            // instead of being boxed into a Symbol wrapper.
            if ($env->has('__SymbolPrototype__')) {
                $proto = $env->get('__SymbolPrototype__');
                if ($proto instanceof JsObject) {
                    $val = $isSymbolKey
                        ? $proto->getBySymbolWithReceiver($rawKey, $obj)
                        : $proto->getWithValueReceiver($key, $obj);
                    if (!$val instanceof JsUndefined) {
                        return $val;
                    }
                }
            }
            return JsUndefined::instance();
        }

        // BigInt primitive property access: look up on BigInt.prototype.
        if ($obj instanceof JsBigInt) {
            $bigintProto = JsBigInt::getPrototype();
            if ($bigintProto !== null && !$isSymbolKey) {
                $val = $bigintProto->get($key);
                if (!$val instanceof JsUndefined) {
                    return $val;
                }
            }
            return JsUndefined::instance();
        }

        if ($obj instanceof JsObject) {
            // Symbol wrapper objects created via Object(sym): look up on Symbol.prototype chain
            // so that description, toString, etc. are accessible with correct this-binding.
            $ownDesc = $obj->getOwnPropertyDescriptor('[[PrimitiveValue]]');
            if ($ownDesc !== null && $ownDesc->value instanceof JsSymbol) {
                $val = $this->lookupSymbolPrototypeProperty(
                    $ownDesc->value,
                    $key,
                    $isSymbolKey,
                    $rawKey,
                );
                if ($val !== null) {
                    return $val;
                }
            }
            if ($isSymbolKey) {
                return $obj->getBySymbol($rawKey);
            }
            return $obj->get($key);
        }

        // Auto-boxing for primitives (number, boolean). Per spec GetV(V, P)
        // (§6.2.4.5): ToObject(V) for the lookup, but invoke getters with V
        // (the primitive) as the receiver — the getter's own strict-mode flag
        // decides whether `this` gets boxed at OrdinaryCallBindThis. Always
        // route through getPropertyWithPrimitiveReceiver so this matches.
        $boxed = TypeConversion::toObject($obj);
        return $this->getPropertyWithPrimitiveReceiver($boxed, $key, $isSymbolKey, $rawKey, $obj);
    }

    /**
     * Walk the prototype chain starting from $object, looking for property $key.
     * If a getter is found, call it with $primitiveReceiver as `this`.
     * Otherwise return the data value.
     */
    private function getPropertyWithPrimitiveReceiver(
        JsObject $object,
        string $key,
        bool $isSymbolKey,
        ?JsValue $rawKey,
        JsValue $primitiveReceiver,
    ): JsValue {
        $current = $object;
        while ($current !== null) {
            // If we hit a Proxy in the prototype chain, delegate to its
            // [[Get]] trap with the primitive receiver preserved per spec
            // OrdinaryGet — the trap MUST observe the original primitive.
            if ($current instanceof \Phasis\Value\JsProxy) {
                if ($isSymbolKey && $rawKey instanceof JsSymbol) {
                    return $current->getBySymbolWithReceiver($rawKey, $primitiveReceiver);
                }
                return $current->getWithValueReceiver($key, $primitiveReceiver);
            }
            if ($isSymbolKey && $rawKey instanceof JsSymbol) {
                $desc = $current->getSymbolPropertyDescriptor($rawKey);
            } else {
                $desc = $current->getOwnPropertyDescriptor($key);
            }
            if ($desc !== null) {
                if ($desc->get !== null) {
                    return $desc->get->call($primitiveReceiver, []);
                }
                return $desc->value ?? JsUndefined::instance();
            }
            $current = $current->getPrototype();
        }
        return JsUndefined::instance();
    }

    /**
     * Look up a property on Symbol.prototype chain, correctly invoking accessor getters
     * with $sym as this. Returns null if not found (not JsUndefined).
     */
    private function lookupSymbolPrototypeProperty(
        JsSymbol $sym,
        string $key,
        bool $isSymbolKey,
        ?JsValue $rawKey,
    ): ?JsValue {
        $symProto = JsSymbol::getSymbolPrototype();
        if ($symProto === null) {
            return null;
        }
        // Walk the prototype chain of Symbol.prototype.
        $proto = $symProto;
        while ($proto !== null) {
            if ($isSymbolKey && $rawKey instanceof JsSymbol) {
                $desc = $proto->getSymbolPropertyDescriptor($rawKey);
            } else {
                $desc = $proto->getOwnPropertyDescriptor($key);
            }
            if ($desc !== null) {
                if ($desc->get !== null) {
                    // Accessor: call getter with the symbol primitive as this.
                    return $desc->get->call($sym, []);
                }
                return $desc->value ?? JsUndefined::instance();
            }
            $proto = $proto->getPrototype();
        }
        return null;
    }

    /** Create a factory function that returns a string iterator (for Symbol.iterator). */
    private function createStringIteratorFactory(JsString $str): JsFunction
    {
        $iteratorFactory = function () use ($str): JsValue {
            return \Phasis\BuiltIn\StringPrototype::createStringIterator($str);
        };

        return JsFunction::fromCallable('[Symbol.iterator]', $iteratorFactory);
    }

    private function evalArrayExpression(ArrayExpression $node, Environment $env): JsValue
    {
        $arr = new JsArray();
        $index = 0;
        foreach ($node->elements as $elem) {
            if ($elem === null) {
                // Hole (elision): skip this index, leaving it as a true hole.
                $index++;
                continue;
            }
            if ($elem instanceof SpreadElement) {
                $iterable = $this->evaluate($elem->argument, $env);
                $spreadItems = [];
                $this->spreadInto($iterable, $spreadItems);
                foreach ($spreadItems as $item) {
                    // Per spec, array literal initialization uses CreateDataProperty,
                    // not [[Set]], so non-writable prototype properties don't block it.
                    $arr->defineOwnProperty(
                        (string) $index,
                        \Phasis\Object\PropertyDescriptor::data($item, true, true, true),
                    );
                    $index++;
                }
                continue;
            }
            // Per spec, array literal initialization uses CreateDataProperty,
            // not [[Set]], so non-writable prototype properties don't block it.
            $arr->defineOwnProperty(
                (string) $index,
                \Phasis\Object\PropertyDescriptor::data(
                    $this->evaluate($elem, $env),
                    true,
                    true,
                    true,
                ),
            );
            $index++;
        }
        $arr->setLength($index);
        return $arr;
    }

    /**
     * Resolve a global `__XxxPrototype__` binding once and reuse the
     * JsObject reference. Used by hot paths that fetch the same
     * intrinsic prototype on every invocation (object literals,
     * string/number/etc. method dispatch).
     */
    private function resolveCachedPrototype(string $name): ?JsObject
    {
        if ($this->globalEnv->has($name)) {
            $p = $this->globalEnv->get($name);
            if ($p instanceof JsObject) {
                return $p;
            }
        }
        return null;
    }

    private function evalObjectExpression(ObjectExpression $node, Environment $env): JsValue
    {
        // Cache the global Object.prototype lookup. The binding is in
        // globalEnv and doesn't change across an Engine instance, so
        // looking it up via env-walk on every literal is wasted work.
        $obj = new JsObject(
            $this->cachedObjectPrototype ??= $this->resolveCachedPrototype('__ObjectPrototype__'),
        );

        foreach ($node->properties as $prop) {
            if ($prop instanceof SpreadElement) {
                $source = $this->evaluate($prop->argument, $env);
                if ($source instanceof JsObject) {
                    // Per spec 7.3.37 CopyDataProperties: call [[OwnPropertyKeys]]
                    // to get all keys (strings + symbols), then for each key,
                    // call [[GetOwnProperty]] and copy if enumerable.
                    // Using ordinaryOwnPropertyKeys ensures Proxy ownKeys traps
                    // return both string and symbol keys correctly.
                    $allKeys = $source->ordinaryOwnPropertyKeys();
                    foreach ($allKeys as $propKey) {
                        if ($propKey instanceof JsSymbol) {
                            $desc = $source->getSymbolPropertyDescriptor($propKey);
                            if ($desc !== null && $desc->enumerable !== false) {
                                $obj->setBySymbol($propKey, $source->getBySymbol($propKey));
                            }
                        } else {
                            $strKey = $propKey instanceof JsString
                                ? $propKey->value
                                : TypeConversion::toString($propKey);
                            $desc = $source->getOwnPropertyDescriptor($strKey);
                            if ($desc !== null && $desc->enumerable !== false) {
                                $obj->defineOwnProperty($strKey, PropertyDescriptor::data($source->get($strKey)));
                            }
                        }
                    }
                }
                continue;
            }

            if (!$prop instanceof Property) {
                continue;
            }

            // Evaluate computed key; may be a Symbol. Per spec
            // EvaluatePropertyKey, computed property name expressions go
            // through ToPropertyKey, which preserves Symbols (including
            // Symbols obtained from a custom toString/valueOf via
            // ToPrimitive(value, "string")).
            $rawKey = null;
            $isSymbolKey = false;
            if ($prop->computed) {
                $rawKey = TypeConversion::toPropertyKey($this->evaluate($prop->key, $env));
                $isSymbolKey = $rawKey instanceof JsSymbol;
            }

            $key = '';
            if (!$isSymbolKey) {
                $key = $prop->computed
                    ? ($rawKey instanceof JsString ? $rawKey->value : TypeConversion::toString($rawKey))
                    : ($prop->key instanceof Identifier
                        ? $prop->key->name
                        : ($prop->key instanceof Literal
                            ? TypeConversion::toString($this->evalLiteral($prop->key))
                            : ''));
            }

            if ($prop->kind === 'get' || $prop->kind === 'set') {
                $fn = $this->evaluate($prop->value, $env);
                if ($fn instanceof JsFunction) {
                    // Per spec, getter/setter functions are not constructable and
                    // do not have a .prototype property. They are also "newer-type"
                    // functions per Forbidden Extensions §16.2 — drop the legacy
                    // .arguments / .caller slots so prototype walk hits the
                    // Function.prototype thrower.
                    $fn->setNonConstructable();
                    $fn->forceDelete('prototype');
                    $fn->forceDelete('arguments');
                    $fn->forceDelete('caller');
                    // Per spec 14.3.8/14.3.9 SetFunctionName(closure, propKey, prefix):
                    // set the function name to "get <key>" or "set <key>".
                    if ($isSymbolKey) {
                        $symDesc = $rawKey->getDescription();
                        $displayName = $symDesc !== null ? "[{$symDesc}]" : '';
                    } else {
                        $displayName = $key;
                    }
                    $fn->setName("{$prop->kind} {$displayName}");
                    // Accessors in object literals have [[HomeObject]] = the object.
                    $fn->setHomeObject($obj);
                    if ($isSymbolKey) {
                        $existing = $obj->getSymbolPropertyDescriptor($rawKey);
                        // Use defineOwnSymbolProperty (direct set) rather than
                        // definePropertyBySymbol so the new accessor fully
                        // replaces the existing one — the merge logic ignores
                        // get/set fields without hasGet/hasSet flags and would
                        // keep the previous getter/setter.
                        if ($prop->kind === 'get') {
                            $obj->defineOwnSymbolProperty($rawKey, PropertyDescriptor::accessor($fn, $existing?->set));
                        } else {
                            $obj->defineOwnSymbolProperty($rawKey, PropertyDescriptor::accessor($existing?->get, $fn));
                        }
                    } else {
                        $existing = $obj->getOwnPropertyDescriptor($key);
                        // Use defineProperty (direct set) instead of defineOwnProperty to
                        // avoid the hasGet/hasSet merge logic re-merging the descriptor we
                        // already assembled from the existing one.
                        if ($prop->kind === 'get') {
                            $obj->defineProperty($key, PropertyDescriptor::accessor($fn, $existing?->set));
                        } else {
                            $obj->defineProperty($key, PropertyDescriptor::accessor($existing?->get, $fn));
                        }
                    }
                }
                continue;
            }

            $value = $this->evaluate($prop->value, $env);
            if ($isSymbolKey) {
                // Name inference: per spec, only if IsAnonymousFunctionDefinition(AssignmentExpression).
                if (
                    $value instanceof JsFunction
                    && $value->getName() === '(anonymous)'
                    && $this->isAnonymousFunctionDefinitionNode($prop->value)
                    && !$this->hasExplicitNameProperty($value)
                ) {
                    $desc = $rawKey->description;
                    $value->setName($desc !== null ? "[{$desc}]" : '');
                }
                // Method shorthand: set [[HomeObject]] for super references.
                if ($prop->method && $value instanceof JsFunction) {
                    $value->setHomeObject($obj);
                }
                $obj->setBySymbol($rawKey, $value);
            } else {
                // __proto__ assignment in object literal sets the prototype
                // ONLY for the 'PropertyName : AssignmentExpression' form.
                // Shorthand ({ __proto__ }) and method ({ __proto__() {} })
                // create a regular own data/function property (Annex B B.3.1).
                if (
                    !$prop->computed
                    && !$prop->shorthand
                    && !$prop->method
                    && $key === '__proto__'
                ) {
                    if ($value instanceof JsObject) {
                        $obj->setPrototype($value);
                    } elseif ($value instanceof JsNull) {
                        $obj->setPrototype(null);
                    }
                    continue;
                }
                // Name inference: per spec, only if IsAnonymousFunctionDefinition(AssignmentExpression).
                if (
                    $value instanceof JsFunction
                    && $value->getName() === '(anonymous)'
                    && $this->isAnonymousFunctionDefinitionNode($prop->value)
                    && !$this->hasExplicitNameProperty($value)
                ) {
                    $value->setName($key);
                }
                // Method shorthand: set [[HomeObject]] for super references.
                // Per spec, method definitions are not constructable and do
                // not have a .prototype property — EXCEPT generator and
                // async-generator methods (sync- or async-yielding) which
                // keep their .prototype (it controls the returned generator's
                // prototype). Async non-generator methods do NOT keep one.
                if ($prop->method && $value instanceof JsFunction) {
                    $value->setHomeObject($obj);
                    $value->setNonConstructable();
                    if (!$value->isGenerator()) {
                        $value->forceDelete('prototype');
                    }
                    // Methods are "newer-type" functions per Forbidden
                    // Extensions §16.2 — they MUST NOT carry the legacy
                    // .arguments / .caller own slots, so prototype walk
                    // hits Function.prototype's poison-pill thrower.
                    $value->forceDelete('arguments');
                    $value->forceDelete('caller');
                }
                // Per spec 13.2.5.5 PropertyDefinitionEvaluation: call
                // CreateDataPropertyOrThrow which uses [[DefineOwnProperty]],
                // NOT [[Set]]. Using set() would trigger prototype accessors
                // (e.g. __proto__ setter or non-writable prototype properties).
                // Fast path skips the PropertyDescriptor::data wrapper plus
                // the merge-branch allocation inside defineOwnProperty.
                $obj->defineOwnDataPropertyFast($key, $value);
            }
        }

        return $obj;
    }

    private function evalArrowFunction(ArrowFunction $node, Environment $env): JsValue
    {
        $fn = new JsFunction(
            '(anonymous)',
            $node->params,
            $node->body,
            $env,
            isArrow: true,
            isAsync: $node->async,
            strict: $this->strictMode,
        );
        if ($node->sourceText !== null) {
            $fn->setSourceText($node->sourceText);
        }
        $fn->definingModulePath = $this->currentModulePath;
        return $fn;
    }

    private function evalFunctionExpression(FunctionExpression $node, Environment $env): JsValue
    {
        $fnEnv = $node->name !== null ? $env->createChild() : $env;
        $fn = new JsFunction(
            $node->name ?? '(anonymous)',
            $node->params,
            $node->body,
            $fnEnv,
            isGenerator: $node->generator,
            isAsync: $node->async,
            strict: $this->strictMode,
        );
        if ($node->sourceText !== null) {
            $fn->setSourceText($node->sourceText);
        }
        $fn->definingModulePath = $this->currentModulePath;
        // Named function expressions have an immutable binding for their own name.
        // Per spec 15.2.5: the BindingIdentifier is created as an immutable binding
        // in the function's scope. In strict mode, assignment throws TypeError.
        // In non-strict mode, assignment is silently ignored.
        if ($node->name !== null) {
            // Use a linked object with a non-writable property so that
            // Environment::set() enforces immutability correctly:
            // strict mode throws TypeError, sloppy mode silently ignores.
            $immutableObj = new JsObject();
            $fnEnv->linkGlobalObject($immutableObj);
            $fnEnv->defineVar($node->name, $fn);
            $immutableObj->defineOwnProperty(
                $node->name,
                PropertyDescriptor::data($fn, false, false, false),
            );
        }
        $this->installFunctionPrototype($fn, $node->generator, $node->async);
        return $fn;
    }

    /**
     * Evaluate a yield expression inside a generator function.
     *
     * This works by calling Fiber::suspend() with the yielded value.
     * The Fiber is managed by JsGenerator, which resumes us when next()
     * is called. The value passed to next() is returned by Fiber::suspend()
     * and becomes the result of the yield expression.
     *
     * For yield* (delegate), we iterate the sub-generator/iterable and
     * yield each value from it.
     */
    private function evalYieldExpression(YieldExpression $node, Environment $env): JsValue
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            throw new InternalError('yield expression used outside of a generator');
        }

        if ($node->delegate) {
            return $this->evalYieldDelegate($node, $env);
        }

        // Evaluate the argument (or undefined if absent).
        $value = $node->argument !== null
            ? $this->evaluate($node->argument, $env)
            : JsUndefined::instance();

        // Per spec AsyncGeneratorYield step 5: Await(value). If the awaited
        // value rejects, the yield expression completes abruptly (throw),
        // allowing enclosing try/catch to handle it and closing the
        // generator otherwise. We only pre-await when the value is settled
        // synchronously; for a still-pending promise we hand the value off
        // to JsAsyncGenerator::asyncGeneratorYieldResult which will await
        // the promise and resolve the request promise asynchronously.
        $isAsyncGen = $env->getEnclosingFunctionKind() === 'async-generator';
        if ($isAsyncGen) {
            if (
                $value instanceof \Phasis\Value\JsPromise
                && $value->getState() === \Phasis\Value\JsPromise::STATE_PENDING
            ) {
                // Leave the pending promise alone; asyncGeneratorYieldResult
                // will subscribe and resolve the request asynchronously.
            } else {
                $value = $this->awaitValue($value);
            }
        }

        // Suspend the Fiber, yielding the value to JsGenerator::next().
        // The return value of suspend() is the value passed to the
        // subsequent next(value) call.
        $received = \Fiber::suspend($value);

        // If a GeneratorThrowSignal was thrown into the Fiber (via
        // generator.throw()), it will be thrown at this point by PHP,
        // not returned. So if we get here, we received a normal value.
        if ($received instanceof JsValue) {
            return $received;
        }

        return JsUndefined::instance();
    }

    /**
     * Handle yield* (delegate yield) per ES spec 27.5.3.7.
     *
     * Uses GetIterator to obtain the sub-iterator, then runs the full
     * delegation protocol: forwarding next(), throw(), and return() calls
     * from the outer generator to the inner iterator.
     */
    private function evalYieldDelegate(
        YieldExpression $node,
        Environment $env,
    ): JsValue {
        $iterable = $node->argument !== null
            ? $this->evaluate($node->argument, $env)
            : JsUndefined::instance();

        // For async generators, use async iteration protocol.
        $isAsyncGen = $env->getEnclosingFunctionKind() === 'async-generator';
        if ($isAsyncGen) {
            $iterator = $this->getAsyncIterator($iterable);
        } else {
            $iterator = $this->getIterator($iterable);
        }
        if ($iterator === null) {
            throw new TypeError(
                TypeConversion::toString($iterable) . ' is not iterable'
            );
        }

        $nextMethod = $iterator->get('next');
        if (!$nextMethod instanceof JsFunction) {
            throw new TypeError('Iterator result next is not a function');
        }

        // Step 4: received = NormalCompletion(undefined).
        // Step 5: Repeat.
        $receivedValue = JsUndefined::instance();
        $receivedType = 'normal'; // 'normal', 'throw', or 'return'

        while (true) {
            $innerResult = null;

            // Step 5a: received is normal.
            if ($receivedType === 'normal') {
                $innerResult = $this->callFunction($nextMethod, $iterator, [$receivedValue]);
                if ($isAsyncGen) {
                    $innerResult = $this->awaitInGenerator($innerResult);
                }
                if (!$innerResult instanceof JsObject) {
                    throw new TypeError('Iterator result is not an object');
                }
            } elseif ($receivedType === 'throw') {
                // Step 5b: received is throw.
                $throwMethod = $iterator->get('throw');
                if ($throwMethod instanceof JsUndefined || $throwMethod instanceof JsNull) {
                    // Per spec: if throw is undefined, IteratorClose the
                    // iterator then throw TypeError. If IteratorClose itself
                    // throws (e.g. the return getter or call throws), that
                    // error propagates instead of the TypeError.
                    $returnMethod = $iterator->get('return');
                    if ($returnMethod instanceof JsFunction) {
                        $closeResult = $this->callFunction($returnMethod, $iterator, []);
                        if (!($closeResult instanceof JsObject)) {
                            throw new TypeError('Iterator result is not an object');
                        }
                    }
                    throw new TypeError('The iterator does not provide a throw method');
                }
                if (!$throwMethod instanceof JsFunction) {
                    throw new TypeError('The iterator does not provide a throw method');
                }
                $innerResult = $this->callFunction($throwMethod, $iterator, [$receivedValue]);
                if ($isAsyncGen) {
                    $innerResult = $this->awaitInGenerator($innerResult);
                }
                if (!$innerResult instanceof JsObject) {
                    throw new TypeError('Iterator result is not an object');
                }
            } else {
                // Step 5c: received is return. Per spec
                // 14.4.14 / 27.6.3.7 (yield* with received.[[Type]]=return):
                //   If generatorKind is async, await received.[[Value]] first
                //   (reading the thenable's .then is observable).
                //   Then look up iterator.return. If undefined, await again
                //   and propagate the return completion.
                if ($isAsyncGen) {
                    $receivedValue = $this->awaitInGenerator($receivedValue);
                }
                $returnMethod = $iterator->get('return');
                if ($returnMethod instanceof JsUndefined || $returnMethod instanceof JsNull) {
                    if ($isAsyncGen) {
                        $receivedValue = $this->awaitInGenerator($receivedValue);
                    }
                    throw new GeneratorReturnSignal($receivedValue, $isAsyncGen);
                }
                if ($returnMethod instanceof \Phasis\Value\JsHTMLDDA) {
                    // HTMLDDA's [[Call]] returns null; fails the object check below.
                    $innerResult = JsNull::instance();
                } elseif (!$returnMethod instanceof JsFunction) {
                    if ($isAsyncGen) {
                        $receivedValue = $this->awaitInGenerator($receivedValue);
                    }
                    throw new GeneratorReturnSignal($receivedValue, $isAsyncGen);
                } else {
                    $innerResult = $this->callFunction($returnMethod, $iterator, [$receivedValue]);
                    if ($isAsyncGen) {
                        $innerResult = $this->awaitInGenerator($innerResult);
                    }
                }
                if (!$innerResult instanceof JsObject) {
                    throw new TypeError('Iterator result is not an object');
                }
            }

            // Check done.
            $done = $innerResult->get('done');
            if (TypeConversion::toBoolean($done)) {
                // Inner iterator is done. Return its value.
                $returnVal = $innerResult->get('value');
                if ($receivedType === 'return') {
                    // For return delegation: propagate the return completion.
                    throw new GeneratorReturnSignal($returnVal);
                }
                return $returnVal;
            }

            // Not done: yield the inner result to the outer caller.
            // For sync yield*, GeneratorYield passes the iterator-result object
            // through directly. For async yield*, the spec reads the value
            // property (triggering the getter, which may throw) and yields
            // it via AsyncGeneratorYield; AsyncGeneratorYield does NOT await
            // the value when the iterator is an async iterator proper, so
            // promises pass through unwrapped.
            if ($isAsyncGen) {
                // Per spec, async yield* reads the value (triggering the
                // getter) but the async iterator pathway does NOT await or
                // re-wrap the yielded value. Build an iterator result object
                // manually and suspend with YieldDelegateResult so the
                // JsAsyncGenerator consumer returns it as-is without piping
                // through asyncGeneratorYieldResult's await logic.
                $yieldValue = $innerResult->get('value');
                $resultObj = new JsObject();
                $resultObj->set('value', $yieldValue);
                $resultObj->set('done', new \Phasis\Value\JsBoolean(false));
                try {
                    $received = \Fiber::suspend(new \Phasis\Value\YieldDelegateResult($resultObj));
                    $receivedValue = $received instanceof JsValue ? $received : JsUndefined::instance();
                    $receivedType = 'normal';
                } catch (GeneratorThrowSignal $e) {
                    $receivedValue = $e->jsValue;
                    $receivedType = 'throw';
                } catch (GeneratorReturnSignal $e) {
                    $receivedValue = $e->value;
                    $receivedType = 'return';
                }
                continue;
            }
            try {
                $received = \Fiber::suspend(new \Phasis\Value\YieldDelegateResult($innerResult));
                $receivedValue = $received instanceof JsValue ? $received : JsUndefined::instance();
                $receivedType = 'normal';
            } catch (GeneratorThrowSignal $e) {
                $receivedValue = $e->jsValue;
                $receivedType = 'throw';
            } catch (GeneratorReturnSignal $e) {
                $receivedValue = $e->value;
                $receivedType = 'return';
            }
        }
    }

    /**
     * Evaluate an await expression inside an async function.
     *
     * If the awaited value is a JsPromise, extract its resolved value
     * (or re-throw if rejected). If it is a thenable, resolve it.
     * Otherwise, return the value as-is (like Promise.resolve(value)).
     */
    private function evalAwaitExpression(AwaitExpression $node, Environment $env): JsValue
    {
        $value = $this->evaluate($node->argument, $env);
        // If we're running inside an async function Fiber that was
        // created by executeAsyncFunction, suspend the Fiber with the
        // awaited value so the controller can drive Promise resolution
        // and schedule a microtask before resuming. The driver returns
        // the resolved value via Fiber::resume; rejection is delivered
        // by Fiber::throw with a JsThrowable.
        //
        // Async-arrow lexical envs are tagged kind='arrow' for `this`,
        // `new.target`, and `arguments` inheritance purposes; the actual
        // executeAsyncFunction Fiber still wraps the body. isInAsyncContext
        // walks past arrow frames so an await in an async arrow correctly
        // routes through the suspension path rather than synchronously
        // unwrapping a pending promise to undefined.
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null && $this->isInAsyncContext($env)) {
            try {
                $resumed = \Fiber::suspend(new \Phasis\Value\AwaitSuspension($value));
            } catch (\Phasis\Exceptions\JsThrowable $e) {
                $this->throwJsValue($e->jsValue);
            }
            if ($resumed instanceof JsValue) {
                return $resumed;
            }
            return JsUndefined::instance();
        }
        // Top-level await and awaits in our synchronous (non-fiber)
        // contexts fall back to the inline drain-and-unwrap strategy.
        return $this->resolveAwaitedValue($value);
    }

    /**
     * Determine whether the current lexical scope is inside an async
     * function (async or async-generator) for await-suspension purposes.
     *
     * Async arrow functions set kind='arrow' on their own env (so that
     * `this`, `new.target`, and `arguments` look up the enclosing
     * non-arrow frame). The parser only admits `await` inside async
     * lexical contexts, so an arrow-only walk to the root means the
     * await belongs to an async arrow that's already wrapped in an
     * executeAsyncFunction Fiber: suspension routes back to that fiber,
     * which is what we want. Module top-level await sets kind='async'
     * on the module env, so it's covered too.
     */
    private function isInAsyncContext(Environment $env): bool
    {
        $kind = $env->getEnclosingNonArrowFunctionKind();
        if ($kind === null) {
            // Either we're at global scope (no await context), or every
            // intervening function frame is an arrow. The await is only
            // syntactically valid in the second case (the arrow itself is
            // async), and that arrow runs inside an executeAsyncFunction
            // Fiber. Suspending here delivers the AwaitSuspension to the
            // right driver.
            return $env->getEnclosingFunctionKind() === 'arrow';
        }
        return $kind === 'async' || $kind === 'async-generator';
    }

    /**
     * Drive an awaited value through PromiseResolve semantics: drain
     * microtasks for pending promises, follow resolution chains, and
     * invoke thenable.then so a promise that resolves to a thenable
     * gets its then() callback fired before the await returns.
     *
     * Top-level await and awaits in our synchronous interpreter don't
     * actually suspend the interpreter, so we need to settle the chain
     * synchronously while preserving spec-observable side effects.
     */
    private function resolveAwaitedValue(JsValue $value): JsValue
    {
        $iterations = 0;
        while ($iterations++ < 32) {
            if ($value instanceof \Phasis\Value\JsPromise) {
                if ($value->getState() === \Phasis\Value\JsPromise::STATE_PENDING) {
                    \Phasis\Value\JsPromise::drainMicrotasks();
                }
                if ($value->getState() === \Phasis\Value\JsPromise::STATE_REJECTED) {
                    $this->throwJsValue($value->getResolvedValue());
                }
                $next = $value->getResolvedValue();
                if ($next === $value) {
                    return $next;
                }
                $value = $next;
                continue;
            }

            if ($value instanceof JsObject) {
                $thenMethod = $value->get('then');
                if ($thenMethod instanceof JsFunction) {
                    $resolved = JsUndefined::instance();
                    $rejected = null;
                    $resolveHandler = function (JsValue $this_, array $args) use (&$resolved): JsValue {
                        $resolved = $args[0] ?? JsUndefined::instance();
                        return JsUndefined::instance();
                    };
                    $rejectHandler = function (JsValue $this_, array $args) use (&$rejected): JsValue {
                        $rejected = $args[0] ?? JsUndefined::instance();
                        return JsUndefined::instance();
                    };
                    $resolveFn = JsFunction::fromCallable('', $resolveHandler, 1);
                    $rejectFn = JsFunction::fromCallable('', $rejectHandler, 1);
                    try {
                        $thenMethod->call($value, [$resolveFn, $rejectFn]);
                    } catch (\Throwable $e) {
                        if ($e instanceof \Phasis\Exceptions\JsThrowable) {
                            $this->throwJsValue($e->jsValue);
                        }
                        throw $e;
                    }
                    if ($rejected !== null) {
                        $this->throwJsValue($rejected);
                    }
                    if ($resolved === $value) {
                        return $resolved;
                    }
                    $value = $resolved;
                    continue;
                }
            }

            return $value;
        }
        return $value;
    }

    /**
     * Evaluate import(source) expression. Returns a Promise.
     *
     * Per spec, import() always returns a new Promise. The specifier is
     * evaluated, converted to string, and the module is loaded. On success
     * the promise is resolved with the module namespace object. On failure
     * the promise is rejected with the error.
     */
    private function evalImportExpression(ImportExpression $node, Environment $env): JsValue
    {
        // Per spec EvaluateImportCall, both the specifier and options
        // expressions are evaluated synchronously; any throw from evaluating
        // either propagates out of import() directly (not via rejection).
        $sourceValue = $this->evaluate($node->source, $env);
        $optionsValue = null;
        if ($node->options !== null) {
            $optionsValue = $this->evaluate($node->options, $env);
        }

        $promise = new \Phasis\Value\JsPromise();

        // Validate options: per spec, if options is not undefined, it must
        // be an Object. Otherwise reject with TypeError.
        if ($optionsValue !== null && !($optionsValue instanceof JsUndefined)) {
            if (!($optionsValue instanceof JsObject)) {
                $err = $this->phpExceptionToJsValue(
                    new TypeError('import() options must be an object')
                );
                $promise->reject($err);
                return $promise;
            }
            // If options has a "with" property, it must be an object. Then
            // its entries must be string-valued.
            try {
                $withVal = $optionsValue->get('with');
                if (!($withVal instanceof JsUndefined)) {
                    if (!($withVal instanceof JsObject)) {
                        $err = $this->phpExceptionToJsValue(
                            new TypeError('import() "with" attribute must be an object')
                        );
                        $promise->reject($err);
                        return $promise;
                    }
                    // Enumerate own enumerable string keys and ensure values are strings.
                    $keys = [];
                    foreach ($withVal->getOwnPropertyNames() as $k) {
                        $d = $withVal->getOwnPropertyDescriptor($k);
                        if ($d !== null && $d->enumerable) {
                            $keys[] = $k;
                        }
                    }
                    foreach ($keys as $k) {
                        $v = $withVal->get($k);
                        if (!($v instanceof JsString)) {
                            $err = $this->phpExceptionToJsValue(
                                new TypeError('import() attribute value must be a string')
                            );
                            $promise->reject($err);
                            return $promise;
                        }
                    }
                }
            } catch (\Phasis\Exceptions\JsThrowable $e) {
                $promise->reject($e->jsValue);
                return $promise;
            } catch (\Throwable $e) {
                $errorObj = $this->phpExceptionToJsValue($e);
                $promise->reject($errorObj);
                return $promise;
            }
        }

        try {
            $specifier = TypeConversion::toString($sourceValue);
            $loader = $this->getModuleLoader();
            // Source-phase imports: GetModuleSource for SourceTextModule
            // always returns an abrupt SyntaxError (16.2.1.7.2). Reject
            // the returned promise to match.
            if ($node->phase === 'source') {
                $err = $this->phpExceptionToJsValue(
                    new \Phasis\Exceptions\SyntaxError(
                        "import.source() is not supported on this module type"
                    )
                );
                $promise->reject($err);
                return $promise;
            }
            // Deferred phase: load the module eagerly (we don't yet
            // model true lazy evaluation) but return the deferred
            // namespace exotic so identity, toStringTag, and the
            // namespace's export bindings line up with the spec.
            if ($node->phase === 'defer') {
                $deferred = $loader->getDeferredNamespaceFor($specifier, $this->currentModulePath);
                $promise->resolve($deferred);
                return $promise;
            }
            $namespace = $loader->loadModule($specifier, $this->currentModulePath);
            $promise->resolve($namespace);
        } catch (\Phasis\Exceptions\JsThrowable $e) {
            $promise->reject($e->jsValue);
        } catch (\Throwable $e) {
            $errorObj = $this->phpExceptionToJsValue($e);
            $promise->reject($errorObj);
        }

        return $promise;
    }

    /**
     * Evaluate import.meta or new.target meta-property.
     */
    private function evalMetaProperty(MetaProperty $node, Environment $env): JsValue
    {
        if ($node->meta === 'import' && $node->property === 'meta') {
            $cacheKey = $this->currentModulePath ?? '<script>';
            if (isset($this->importMetaCache[$cacheKey])) {
                return $this->importMetaCache[$cacheKey];
            }
            // Per spec 16.2.1.7, an import.meta object's prototype is null
            // and it is an ordinary extensible object whose initial property
            // bag is determined by HostGetImportMetaProperties.
            $meta = new JsObject();
            $meta->setPrototype(null);
            if ($this->currentModulePath !== null) {
                $meta->set('url', new JsString('file://' . $this->currentModulePath));
            }
            $this->importMetaCache[$cacheKey] = $meta;
            return $meta;
        }

        // new.target is handled elsewhere as an Identifier '[[NewTarget]]'.
        return JsUndefined::instance();
    }

    /**
     * Execute an export declaration in module context.
     * The actual export bookkeeping is handled by the ModuleLoader; here we
     * only need to execute the declaration (if any) so its side effects and
     * bindings are established.
     */
    private function execExportDeclaration(ExportDeclaration $node, Environment $env): Completion
    {
        if ($node->declaration !== null) {
            // For export default with expressions (not declarations), evaluate the expression.
            if (
                $node->isDefault
                && !($node->declaration instanceof VariableDeclaration)
                && !($node->declaration instanceof FunctionDeclaration)
                && !($node->declaration instanceof ClassDeclaration)
                && !($node->declaration instanceof ExpressionStatement)
            ) {
                $value = $this->evaluate($node->declaration, $env);
                // Per spec: if the AssignmentExpression is an anonymous
                // function definition (or anonymous class), set its name
                // to "default". If the class/function defines its own
                // `name` member (e.g. static name method, getter), keep
                // that — only override the implicit empty-string name.
                if (
                    $value instanceof JsFunction
                    && ($value->getName() === '' || $value->getName() === '(anonymous)')
                ) {
                    $hasOwnName = $value->getOwnPropertyDescriptor('name') !== null;
                    $existing = $hasOwnName ? $value->get('name') : null;
                    $isImplicitEmpty = $existing instanceof JsString && $existing->value === '';
                    if (!$hasOwnName || $isImplicitEmpty) {
                        $value->setName('default');
                    }
                }
                // Populate the synthetic __default__ slot so the namespace
                // live binding sees the computed value.
                $this->storeDefaultExportSlot($env, $value);
                return Completion::normal($value);
            }
            // Anonymous export default function/class: evaluate as expression
            // and stash into __default__ slot.
            if (
                $node->isDefault
                && (
                    ($node->declaration instanceof FunctionDeclaration && $node->declaration->id === null)
                    || ($node->declaration instanceof ClassDeclaration && $node->declaration->id === null)
                )
            ) {
                $value = $this->evaluateAnonymousDefault($node->declaration, $env);
                // Per spec: anonymous function/class default exports get
                // their `name` set to "default".
                if (
                    $value instanceof JsFunction
                    && ($value->getName() === '' || $value->getName() === '(anonymous)')
                ) {
                    $hasOwnName = $value->getOwnPropertyDescriptor('name') !== null;
                    $existing = $hasOwnName ? $value->get('name') : null;
                    $isImplicitEmpty = $existing instanceof JsString && $existing->value === '';
                    if (!$hasOwnName || $isImplicitEmpty) {
                        $value->setName('default');
                    }
                }
                $this->storeDefaultExportSlot($env, $value);
                return Completion::normal($value);
            }
            return $this->executeStatement($node->declaration, $env);
        }
        return Completion::normal(JsUndefined::instance());
    }

    private function storeDefaultExportSlot(Environment $env, JsValue $value): void
    {
        // Walk up from the current env to find the module env that declared
        // __default__ via declareLet. Initialize or reassign it there.
        $cur = $env;
        while ($cur !== null) {
            if ($cur->hasOwnBinding('__default__')) {
                if ($cur->isInTdz('__default__')) {
                    $cur->initialize('__default__', $value);
                } else {
                    $cur->defineLet('__default__', $value);
                }
                return;
            }
            $cur = $cur->getParent();
        }
    }

    private function evaluateAnonymousDefault(Node $node, Environment $env): JsValue
    {
        if ($node instanceof \Phasis\Ast\Declaration\FunctionDeclaration) {
            $expr = new \Phasis\Ast\Expression\FunctionExpression(
                $node->location,
                null,
                $node->params,
                $node->body,
                $node->generator,
                $node->async,
                $node->sourceText,
            );
            $fn = $this->evaluate($expr, $env);
            // Per §16.2.3.7 for ExportDefault of an anonymous function:
            // the function's [[Name]] internal slot is set to "default".
            if ($fn instanceof JsFunction && ($fn->getName() === '' || $fn->getName() === '(anonymous)')) {
                $fn->setName('default');
            }
            return $fn;
        }
        if ($node instanceof ClassDeclaration) {
            $expr = new ClassExpression(
                $node->location,
                $node->id,
                $node->superClass,
                $node->body,
                $node->sourceText,
                $node->decorators,
            );
            // Per §16.2.3.7: for an anonymous class in `export default`, the
            // class name used by static field initializers and by the final
            // `name` property is "default". Use the nameHint path.
            return $this->evalClassExpression($expr, $env, 'default');
        }
        return $this->evaluate($node, $env);
    }

    /**
     * Execute module body statements. Used by the ModuleLoader during module evaluation.
     * Unlike execute(), this does not set global scope or handle directives at the top level.
     *
     * @param Node[] $body
     */
    /**
     * Perform function/var hoisting and TDZ declarations for a module body.
     * The module loader calls this separately so that exported hoisted bindings
     * are visible in the namespace before imports (including self-imports) are
     * resolved.
     *
     * @param Node[] $body
     */
    public function hoistModuleDeclarations(array $body, Environment $moduleEnv): void
    {
        $prev = $this->strictMode;
        $this->strictMode = true;
        try {
            $this->hoistDeclarations($body, $moduleEnv);
            // Create TDZ bindings for top-level let/const/class declarations
            // (including those inside export declarations) so `typeof x` before
            // the binding's initialization throws ReferenceError per spec.
            $this->hoistEvalLexicalDeclarations($body, $moduleEnv);
            // Per spec: `export default function() {}` and
            // `export default function* () {}` are hoisted like any other
            // function declaration, with name "default". Pre-evaluate the
            // anonymous function and store into the synthetic __default__
            // slot so same-module self-imports see its value.
            foreach ($body as $stmt) {
                if (
                    $stmt instanceof ExportDeclaration
                    && $stmt->isDefault
                    && $stmt->declaration instanceof \Phasis\Ast\Declaration\FunctionDeclaration
                    && $stmt->declaration->id === null
                ) {
                    $decl = $stmt->declaration;
                    $fn = new \Phasis\Value\JsFunction(
                        'default',
                        $decl->params,
                        $decl->body,
                        $moduleEnv,
                        isGenerator: $decl->generator,
                        isAsync: $decl->async,
                        strict: true,
                    );
                    if ($decl->sourceText !== null) {
                        $fn->setSourceText($decl->sourceText);
                    }
                    $this->installFunctionPrototype($fn, $decl->generator, $decl->async);
                    if (!$moduleEnv->hasOwnBinding('__default__')) {
                        $moduleEnv->defineLet('__default__', $fn);
                    } else {
                        $this->storeDefaultExportSlot($moduleEnv, $fn);
                    }
                }
            }
        } finally {
            $this->strictMode = $prev;
        }
    }

    /**
     * @param ?\Closure(\Phasis\Value\JsPromise): void $onAsyncStart If
     *   provided and the body has top-level await, the closure receives
     *   the body's pending evaluation promise. The body returns
     *   immediately to the caller without draining microtasks; the
     *   caller is responsible for draining once every sibling body has
     *   started.
     *
     * @param array<mixed> $body
     */
    public function executeModuleBody(array $body, Environment $moduleEnv, bool $alreadyHoisted = false, ?\Closure $onAsyncStart = null): JsValue
    {
        $prevStrict = $this->strictMode;
        // Modules are always strict per spec.
        $this->strictMode = true;

        // Per §9.4.1 ModuleEnvironmentRecord.GetThisBinding returns
        // undefined. Bind `this` explicitly so it shadows the global env.
        if (!$moduleEnv->hasOwnBinding('this')) {
            $moduleEnv->defineConst('this', JsUndefined::instance());
        }

        if (!$alreadyHoisted) {
            $this->hoistDeclarations($body, $moduleEnv);
            $this->hoistEvalLexicalDeclarations($body, $moduleEnv);
        }

        // Module top-level early errors — return/break/continue not in a
        // function/loop, super/new.target outside a method/constructor,
        // unbound private identifiers, and duplicate lexical names.
        $this->validateEvalBody($body);
        $this->validateModuleTopLevelDuplicateBindings($body);
        if ($this->astContainsNewTargetTransparent($body)) {
            throw new \Phasis\Exceptions\SyntaxError(
                'new.target expression is not allowed here'
            );
        }
        if ($this->astContainsSuperTransparent($body)) {
            throw new \Phasis\Exceptions\SyntaxError(
                "'super' keyword unexpected here"
            );
        }
        \Phasis\BuiltIn\GlobalObject::rejectPrivateIdentifiersInProgramPublic(
            new \Phasis\Ast\Program(
                $body[0]->location ?? new \Phasis\Lexer\SourceLocation(0, 0, 0),
                $body,
            ),
        );

        // Top-level await: when the module body contains an `await` outside
        // any nested function/arrow/class, run the body inside an async
        // Fiber so each await suspends the module body, lets the
        // microtask queue interleave (Promise.then continuations,
        // sibling import resolutions), then resumes the body. Without
        // this wrap, the synchronous loop blocks all microtasks until
        // each await unwraps inline, breaking spec tick ordering.
        if ($this->astContainsTopLevelAwait($body)) {
            $moduleEnv->setFunctionKind('async');
            $this->strictMode = $prevStrict;
            return $this->executeModuleBodyAsync($body, $moduleEnv, $onAsyncStart);
        }

        $result = JsUndefined::instance();
        foreach ($body as $stmt) {
            // Import declarations are already processed by the module loader.
            if ($stmt instanceof ImportDeclaration) {
                continue;
            }
            // Skip re-executing function declarations at top-level — they
            // were already instantiated during hoisting. Running their
            // statement form would replace the hoisted binding with a new
            // function instance and invalidate already-snapshotted imports.
            if ($stmt instanceof \Phasis\Ast\Declaration\FunctionDeclaration) {
                continue;
            }
            if (
                $stmt instanceof ExportDeclaration
                && $stmt->declaration instanceof \Phasis\Ast\Declaration\FunctionDeclaration
            ) {
                continue;
            }
            $completion = $this->executeStatement($stmt, $moduleEnv);
            if ($completion->type !== CompletionType::Normal) {
                $this->strictMode = $prevStrict;
                if ($completion->type === CompletionType::Throw) {
                    $this->throwJsValue($completion->value);
                }
                return $completion->value;
            }
            if (!$completion->empty) {
                $result = $completion->value;
            }
        }

        $this->strictMode = $prevStrict;
        return $result;
    }

    /**
     * Run a top-level-await module body in a Fiber so awaits suspend
     * the body and let unrelated microtasks (sibling Promise.then
     * chains, resolution of in-flight imports) interleave per spec.
     *
     * The synchronous interpreter doesn't truly run multiple stacks;
     * we drive the fiber to completion right here, but Fiber::suspend
     * on each await yields control back to driveAsyncFiber, which
     * uses .then on the awaited value. Promise's .then enqueues a
     * microtask, so other already-queued microtasks fire first when
     * we drain. The end effect is the spec-correct interleaved tick
     * order even within a single PHP call stack.
     *
     * @param Node[] $body
     */
    private function executeModuleBodyAsync(array $body, Environment $moduleEnv, ?\Closure $onAsyncStart = null): JsValue
    {
        $promise = new \Phasis\Value\JsPromise();
        $self = $this;
        $modulePath = $this->currentModulePath;
        $fiber = new \Fiber(function () use ($self, $body, $moduleEnv, $modulePath): JsValue {
            $prevStrict = $self->strictMode;
            $prevPath = $self->getCurrentModulePath();
            $self->strictMode = true;
            // Restore the module path inside the fiber: the fiber may
            // resume long after the loader has moved on to a sibling,
            // and our await suspensions need the correct module path
            // for import.meta and source-phase resolution.
            $self->setCurrentModulePath($modulePath);
            try {
                $last = JsUndefined::instance();
                foreach ($body as $stmt) {
                    if ($stmt instanceof ImportDeclaration) {
                        continue;
                    }
                    if ($stmt instanceof \Phasis\Ast\Declaration\FunctionDeclaration) {
                        continue;
                    }
                    if (
                        $stmt instanceof ExportDeclaration
                        && $stmt->declaration instanceof \Phasis\Ast\Declaration\FunctionDeclaration
                    ) {
                        continue;
                    }
                    $completion = $self->executeStatement($stmt, $moduleEnv);
                    if ($completion->type !== CompletionType::Normal) {
                        if ($completion->type === CompletionType::Throw) {
                            $self->throwJsValue($completion->value);
                        }
                        return $completion->value;
                    }
                    if (!$completion->empty) {
                        $last = $completion->value;
                    }
                }
                return $last;
            } finally {
                $self->strictMode = $prevStrict;
                $self->setCurrentModulePath($prevPath);
            }
        });
        $this->driveAsyncFiber(
            $fiber,
            $promise,
            true,
            JsUndefined::instance(),
            modulePath: $modulePath,
        );

        // If the caller registered an async-start hook (the module
        // loader does, so siblings can evaluate while this module is
        // suspended), hand the promise off and return without draining.
        if ($onAsyncStart !== null) {
            $onAsyncStart($promise);
            return JsUndefined::instance();
        }

        // No hook: drain microtasks until the body promise settles.
        // Bound the loop so a never-resolving promise can't hang.
        $iter = 0;
        while ($promise->getState() === \Phasis\Value\JsPromise::STATE_PENDING && $iter++ < 100000) {
            \Phasis\Value\JsPromise::drainMicrotasks();
        }

        if ($promise->getState() === \Phasis\Value\JsPromise::STATE_REJECTED) {
            $this->throwJsValue($promise->getResolvedValue());
        }
        return $promise->getResolvedValue();
    }

    private function evalClassExpression(ClassExpression $node, Environment $env, ?string $nameHint = null): JsValue
    {
        // Per spec 15.7.14 ClassDefinitionEvaluation step 2-4:
        // If the class has a name, create a new lexical scope and bind the
        // class name in it. Methods inside the class body will close over
        // this scope, so they can reference the class by name even if the
        // outer binding is shadowed. After evaluation, the binding is
        // not visible outside the class.
        $classEnv = $env;
        if ($node->id !== null) {
            $classEnv = $env->createChild();
            // Per spec, the class name binding inside the classScope is an
            // immutable binding so methods cannot reassign the class name.
            $classEnv->declareConst($node->id->name);
        }
        // For anonymous class expressions, NamedEvaluation passes the binding
        // name down so static fields observe it. The explicit class name (if
        // present) always wins.
        $effectiveName = $node->id !== null ? $node->id->name : $nameHint;
        // Only named class expressions get an inner class-name binding; an
        // anonymous class with a NamedEvaluation hint still has no inner
        // binding (the outer let must remain TDZ during static field eval).
        $hasInnerNameBinding = $node->id !== null;
        $cls = $this->buildClass($effectiveName, $node->superClass, $node->body, $classEnv, $hasInnerNameBinding);
        // Bind the class name to the constructor in the class scope.
        if ($node->id !== null && $classEnv->isInTdz($node->id->name)) {
            $classEnv->initialize($node->id->name, $cls);
        }
        // Per spec, Function.prototype.toString on a class returns the full class source text.
        if ($node->sourceText !== null) {
            $cls->setSourceText($node->sourceText);
        }
        // Apply class decorators.
        $cls = $this->applyClassDecorators($node->decorators, $cls, $env);
        return $cls;
    }

    private function evalThisExpression(Environment $env): JsValue
    {
        if ($env->has('this')) {
            return $env->get('this');
        }
        return JsUndefined::instance();
    }

    private function evalSequence(SequenceExpression $node, Environment $env): JsValue
    {
        $result = JsUndefined::instance();
        foreach ($node->expressions as $expr) {
            $result = $this->evaluate($expr, $env);
        }
        return $result;
    }

    private function evalTemplateLiteral(TemplateLiteral $node, Environment $env): JsValue
    {
        $parts = [];
        for ($i = 0; $i < count($node->quasis); $i++) {
            // Per ES2018, untagged templates with invalid escape sequences must
            // throw SyntaxError at runtime (null cookedValue signals this).
            if ($node->quasis[$i]->cookedValue === null) {
                throw new \Phasis\Exceptions\SyntaxError(
                    'Invalid escape sequence in template literal',
                    $node->quasis[$i]->location,
                );
            }
            $parts[] = $node->quasis[$i]->cookedValue;
            if ($i < count($node->expressions)) {
                $parts[] = TypeConversion::toString($this->evaluate($node->expressions[$i], $env));
            }
        }
        return new JsString(implode('', $parts));
    }

    /**
     * Template object cache: maps AST node identity to the frozen template
     * object, per spec GetTemplateObject (sec-gettemplateobject). Each Parse
     * Node (TemplateLiteral AST node) gets its own cache entry. Repeated
     * evaluation of the same parse node returns the same template object.
     *
     * @var array<int, JsArray>
     */
    private array $templateObjectCache = [];

    private function evalTaggedTemplate(TaggedTemplate $node, Environment $env): JsValue
    {
        // Resolve the tag function, preserving this-binding for member expressions.
        $tag = null;
        $thisValue = JsUndefined::instance();
        if ($node->tag instanceof MemberExpression) {
            $obj = $this->evaluate($node->tag->object, $env);
            $propName = $node->tag->computed
                ? TypeConversion::toString($this->evaluate($node->tag->property, $env))
                : ($node->tag->property instanceof Identifier
                    ? $node->tag->property->name
                    : TypeConversion::toString($this->evaluate($node->tag->property, $env)));
            $tag = $obj instanceof JsObject ? $obj->get($propName) : JsUndefined::instance();
            $thisValue = $obj;
        } else {
            $tag = $this->evaluate($node->tag, $env);
        }
        if (!$tag instanceof JsFunction) {
            throw new TypeError('Tag is not a function');
        }

        // GetTemplateObject: use cached template array if the same parse node
        // (same TemplateLiteral AST object) was seen before. Using spl_object_id
        // ensures each parse invocation (e.g. from eval) gets distinct entries
        // while re-executions of the same function body share the template object.
        $cacheKey = spl_object_id($node->quasi);
        if (isset($this->templateObjectCache[$cacheKey])) {
            $strings = $this->templateObjectCache[$cacheKey];
        } else {
            $strings = new JsArray();
            $raw = new JsArray();
            $count = count($node->quasi->quasis);
            foreach ($node->quasi->quasis as $i => $quasi) {
                $cookedVal = $quasi->cookedValue === null
                    ? JsUndefined::instance()
                    : new JsString($quasi->cookedValue);
                $strings->defineOwnProperty((string) $i, \Phasis\Object\PropertyDescriptor::data(
                    $cookedVal,
                    false,
                    true,
                    false,
                ));
                $raw->defineOwnProperty((string) $i, \Phasis\Object\PropertyDescriptor::data(
                    new JsString($quasi->rawValue),
                    false,
                    true,
                    false,
                ));
            }
            // Set length as non-writable, non-enumerable, non-configurable.
            $strings->defineOwnProperty('length', \Phasis\Object\PropertyDescriptor::data(
                JsNumber::of((float) $count),
                false,
                false,
                false,
            ));
            $raw->defineOwnProperty('length', \Phasis\Object\PropertyDescriptor::data(
                JsNumber::of((float) $count),
                false,
                false,
                false,
            ));
            // Freeze the raw array.
            $raw->preventExtensions();
            // Set raw as non-writable, non-enumerable, non-configurable on strings.
            $strings->defineOwnProperty('raw', \Phasis\Object\PropertyDescriptor::data(
                $raw,
                false,
                false,
                false,
            ));
            // Freeze the strings array.
            $strings->preventExtensions();

            $this->templateObjectCache[$cacheKey] = $strings;
        }

        $args = [$strings];
        foreach ($node->quasi->expressions as $expr) {
            $args[] = $this->evaluate($expr, $env);
        }

        return $this->callFunction($tag, $thisValue, $args);
    }
}
