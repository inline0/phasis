<?php

declare(strict_types=1);

namespace Phasis\Bytecode;

use Phasis\Value\JsBoolean;
use Phasis\Value\JsNumber;

/** Reject changed bytecode layouts before a pure region can execute any part of its body. */
final class StringLoopRegionVerifier
{
    /** @param list<int> $exits */
    public static function verify(
        CompiledFunction $function,
        StringLoopRegionPlan $plan,
        int $entry,
        int $normalExit,
        int $opcodePc,
        int $loopId,
        array $exits,
    ): bool {
        $code = $function->code;
        if (($code[$entry] ?? null) !== Op::CHECK_BUDGET) {
            return false;
        }
        $boundaries = [];
        try {
            for ($pc = 0, $count = count($code); $pc < $count;) {
                $boundaries[$pc] = true;
                $width = 1 + Op::operandCount($code[$pc]);
                if ($pc + $width > $count) {
                    return false;
                }
                $pc += $width;
            }
        } catch (\LogicException) {
            return false;
        }
        $stops = array_fill_keys([...$exits, $normalExit], true);
        foreach ($stops as $pc => $_) {
            if (!isset($boundaries[$pc]) || $pc < $entry || $pc > $normalExit) {
                return false;
            }
        }
        $slotTypes = $written = $initial = [];
        foreach ($plan->bindingTypes as $name => $type) {
            $binding = $plan->inputs[$name] ?? $plan->outputs[$name] ?? null;
            if ($binding === null || isset($slotTypes[$binding['slot']])) {
                return false;
            }
            $slotTypes[$binding['slot']] = $type;
        }
        foreach ($plan->inputs as $binding) {
            $initial[$binding['slot']] = true;
        }
        foreach ($plan->outputs as $binding) {
            $written[$binding['slot']] = true;
        }
        $queue = [[$entry, 0, $initial, false]];
        $seen = [];
        $backedge = false;
        $tickSeen = false;
        $steps = 0;
        while ($queue !== []) {
            if (++$steps > 100000) {
                return false;
            }
            [$pc, $height, $initialized, $ticked] = array_pop($queue);
            if ($height < 0 || !isset($boundaries[$pc])) {
                return false;
            }
            if (isset($stops[$pc])) {
                if ($height !== 0 || ($pc !== $normalExit && !$ticked)) {
                    return false;
                }
                continue;
            }
            if ($pc < $entry || $pc >= $normalExit) {
                return false;
            }
            if (isset($seen[$pc])) {
                if ($seen[$pc][0] !== $height || $seen[$pc][2] !== $ticked) {
                    return false;
                }
                $merged = array_intersect_key($initialized, $seen[$pc][1]);
                if (count($merged) === count($seen[$pc][1])) {
                    continue;
                }
                $initialized = $merged;
            }
            $seen[$pc] = [$height, $initialized, $ticked];
            $op = $code[$pc];
            $next = $pc + 1 + Op::operandCount($op);
            $edges = [];
            if ($op === Op::LOAD_LOCAL) {
                $slot = $code[$pc + 1];
                if (!isset($slotTypes[$slot], $initialized[$slot])) {
                    return false;
                }
                ++$height;
            } elseif ($op === Op::STORE_LOCAL) {
                $slot = $code[$pc + 1];
                if ($height < 1 || !isset($written[$slot])) {
                    return false;
                }
                $initialized[$slot] = true;
                --$height;
            } elseif ($op === Op::INC_LOCAL || $op === Op::DEC_LOCAL) {
                $slot = $code[$pc + 1];
                if (!isset($written[$slot], $initialized[$slot]) || $slotTypes[$slot] !== 'number') {
                    return false;
                }
            } elseif ($op === Op::LOAD_CONST) {
                $constant = $function->consts[$code[$pc + 1]] ?? null;
                if (!$constant instanceof JsNumber && !$constant instanceof JsBoolean) {
                    return false;
                }
                ++$height;
            } elseif ($op === Op::LOAD_TRUE || $op === Op::LOAD_FALSE) {
                ++$height;
            } elseif ($op === Op::DUP) {
                if ($height < 1) {
                    return false;
                }
                ++$height;
            } elseif ($op === Op::POP) {
                if ($height < 1) {
                    return false;
                }
                --$height;
            } elseif ($op === Op::LOAD_MEMBER) {
                if ($height < 1 || !in_array($function->names[$code[$pc + 1]] ?? null, ['length', 'charCodeAt'], true)) {
                    return false;
                }
            } elseif ($op === Op::CALL_METHOD) {
                if ($height < 3 || $code[$pc + 1] !== 1) {
                    return false;
                }
                $height -= 2;
            } elseif (
                in_array($op, [Op::ADD, Op::SUB, Op::MUL, Op::DIV, Op::MOD, Op::LT, Op::GT, Op::LE,
                Op::GE, Op::EQ, Op::NEQ, Op::SEQ, Op::SNEQ], true)
            ) {
                if ($height < 2) {
                    return false;
                }
                --$height;
            } elseif (in_array($op, [Op::NEG, Op::POS, Op::NOT], true)) {
                if ($height < 1) {
                    return false;
                }
            } elseif ($op === Op::CHECK_BUDGET) {
                if (!self::sameHandlers($function, $opcodePc, $pc)) {
                    return false;
                }
            } elseif ($op === Op::LOOP_TICK) {
                if ($ticked || $code[$pc + 1] !== $loopId || !self::sameHandlers($function, $opcodePc, $pc)) {
                    return false;
                }
                $tickSeen = true;
                $ticked = true;
            } elseif ($op === Op::JUMP) {
                $edges[] = [$pc + $code[$pc + 1], $height];
            } elseif ($op === Op::JUMP_IF_FALSE || $op === Op::JUMP_IF_TRUE) {
                if ($height < 1) {
                    return false;
                }
                --$height;
                $edges = [[$next, $height], [$pc + $code[$pc + 1], $height]];
            } elseif ($op === Op::JUMP_IF_TRUTHY_KEEP || $op === Op::JUMP_IF_FALSY_KEEP) {
                if ($height < 1) {
                    return false;
                }
                $edges = [[$next, $height - 1], [$pc + $code[$pc + 1], $height]];
            } else {
                return false;
            }
            if ($edges === []) {
                $edges[] = [$next, $height];
            }
            foreach ($edges as [$target, $targetHeight]) {
                $edgeTicked = $ticked;
                if ($target === $entry && $target <= $pc) {
                    if (!$ticked) {
                        return false;
                    }
                    $backedge = true;
                    $edgeTicked = false;
                }
                $queue[] = [$target, $targetHeight, $initialized, $edgeTicked];
            }
        }
        return $backedge && $tickSeen;
    }

    private static function sameHandlers(CompiledFunction $function, int $first, int $second): bool
    {
        foreach ($function->handlers as $handler) {
            if (
                ($first >= $handler->tryStart && $first < $handler->tryEnd)
                !== ($second >= $handler->tryStart && $second < $handler->tryEnd)
            ) {
                return false;
            }
        }
        return true;
    }
}
