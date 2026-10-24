<?php

declare(strict_types=1);

namespace Phasis\Bytecode;

/** Round-trip JavaScript doubles into PHP source without display precision or locale loss. */
final class PhpNumericLiteral
{
    /** Return an explicit floating-point expression without modifying the original AST literal. */
    public static function render(float $value): string
    {
        if (is_nan($value)) {
            return 'NAN';
        }
        if ($value === INF || $value === -INF) {
            return $value < 0 ? '-INF' : 'INF';
        }
        if ($value === 0.0) {
            return fdiv(1.0, $value) < 0 ? '(-0.0)' : '0.0';
        }
        $literal = sprintf('%.17g', $value);
        $decimal = localeconv()['decimal_point'];
        if ($decimal !== '' && $decimal !== '.') {
            $literal = str_replace($decimal, '.', $literal);
        }
        if (!str_contains($literal, '.') && !str_contains($literal, 'e') && !str_contains($literal, 'E')) {
            $literal .= '.0';
        }
        return $literal;
    }
}
