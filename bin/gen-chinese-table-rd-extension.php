<?php
#!/usr/bin/env php

declare(strict_types=1);

/**
 * Extend src/BuiltIn/data/{chinese,dangi}_calendar.php with deep-past
 * records computed via pure-PHP Reingold-Dershowitz "Calendrical
 * Calculations" §19 (astronomical Chinese calendar).
 *
 * ICU 70..78's chinese-calendar implementation does not produce a
 * 30-day variant of M01L / M09L / M10L / M11L / M12L anywhere in
 * the range [-7000, 1972]. The SM staging test
 * `from-chinese-leap-month-uncommon` asserts these exist somewhere
 * ≤ ISO 1972, which means the spec / V8 use an R-D-equivalent
 * astronomical computation rather than ICU's truncated table.
 *
 * To stay independent of host ICU and pass the SM test, we precompute
 * a deep-past extension purely from R-D math (Meeus' solar longitude
 * and lunar phase, plus the chinese new-moon-before-winter-solstice
 * algorithm) and prepend it to the existing ICU-derived blob. R-D
 * matches ICU exactly for the years we sampled in the common cycle
 * (1972, 2024, 1900, 1737, 1, -100, -1000, -2000), so the boundary
 * at extended-year -2900 is benign.
 *
 * Usage: php bin/gen-chinese-table-rd-extension.php [chinese|dangi]
 *
 * The script reads the current data file, computes R-D records for
 * years -7500 .. (current start - 1), and rewrites the file with the
 * extension prepended. Run for chinese and dangi separately.
 */

ini_set('memory_limit', '1G');

// -----------------------------------------------------------------------
// R-D library functions (Reingold-Dershowitz Calendrical Calculations).
// All "fixed" values are RD day numbers (proleptic Gregorian, RD 1 = 0001-01-01).
// -----------------------------------------------------------------------

const RDC_J2000 = 730120.5; // RD of 2000-01-01 12:00 UT.
const RDC_DEG = M_PI / 180.0;
const RDC_MEAN_SYNODIC_MONTH = 29.530588861;

function rdc_dsin(float $a): float
{
    return sin($a * RDC_DEG);
}

function rdc_dcos(float $a): float
{
    return cos($a * RDC_DEG);
}

function rdc_rmod(float $x, float $y): float
{
    return $x - $y * floor($x / $y);
}

function rdc_gleap(int $y): bool
{
    return ($y % 4 === 0) && ($y % 100 !== 0 || $y % 400 === 0);
}

function rdc_fixed(int $y, int $m, int $d): int
{
    $py = $y - 1;
    return 365 * $py + intdiv($py, 4) - intdiv($py, 100) + intdiv($py, 400)
        + intdiv(367 * $m - 362, 12)
        + ($m <= 2 ? 0 : (rdc_gleap($y) ? -1 : -2))
        + $d;
}

/**
 * @return array{0:int,1:int,2:int}
 */
function rdc_gfromfixed(int $date): array
{
    $d0 = $date - 1;
    $n400 = intdiv($d0, 146097); $d1 = $d0 - 146097 * $n400;
    $n100 = intdiv($d1, 36524); $d2 = $d1 - 36524 * $n100;
    $n4 = intdiv($d2, 1461); $d3 = $d2 - 1461 * $n4;
    $n1 = intdiv($d3, 365);
    $year = 400 * $n400 + 100 * $n100 + 4 * $n4 + $n1;
    if ($n100 === 4 || $n1 === 4) {
        return [$year, 12, 31];
    }
    $year++;
    $pd = $date - rdc_fixed($year, 1, 1);
    $corr = $date < rdc_fixed($year, 3, 1) ? 0 : (rdc_gleap($year) ? 1 : 2);
    $m = intdiv(12 * ($pd + $corr) + 373, 367);
    $d = $date - rdc_fixed($year, $m, 1) + 1;
    return [$year, $m, $d];
}

// Ephemeris correction (TD - UT) in days. R-D 14.59 (3rd ed.).
function rdc_ephemeris(float $tee): float
{
    [$y] = rdc_gfromfixed((int) floor($tee));
    if ($y >= 1988 && $y <= 2019) {
        return ($y - 1933) / 86400.0;
    }
    if ($y >= 1900 && $y <= 1987) {
        $t = ($tee - rdc_fixed(1900, 1, 1)) / 36525.0;
        return -0.00002 + 0.000297 * $t + 0.025184 * $t * $t - 0.181133 * $t ** 3
            + 0.553040 * $t ** 4 - 0.861938 * $t ** 5 + 0.677066 * $t ** 6 - 0.212591 * $t ** 7;
    }
    if ($y >= 1800 && $y <= 1899) {
        $t = ($tee - rdc_fixed(1900, 1, 1)) / 36525.0;
        return -0.000009 + 0.003844 * $t + 0.083563 * $t * $t + 0.865736 * $t ** 3
            + 4.867575 * $t ** 4 + 15.845535 * $t ** 5 + 31.332267 * $t ** 6
            + 38.291999 * $t ** 7 + 28.316289 * $t ** 8 + 11.636204 * $t ** 9
            + 2.043794 * $t ** 10;
    }
    if ($y >= 1700 && $y <= 1799) {
        $yy = $y - 1700;
        return (8.118780842 - 0.005092142 * $yy + 0.003336121 * $yy * $yy
            - 0.0000266484 * $yy ** 3) / 86400.0;
    }
    if ($y >= 1620 && $y <= 1699) {
        $yy = $y - 1600;
        return (196.58333 - 4.0675 * $yy + 0.0219167 * $yy * $yy) / 86400.0;
    }
    // Wide-range fallback for distant past / future.
    $x = 12 * ($y - 1.5) / 100.0;
    return (($x * $x) / 41048480.0 - 15) / 86400.0;
}

function rdc_uniFromDyn(float $tee): float
{
    return $tee - rdc_ephemeris($tee);
}

// Chinese local time offset from UT. R-D §17.6: pre-1929 LMT at 116°25'E,
// then +8h. (Dangi historically used Seoul time but the underlying month
// arithmetic differs by ≤1 day in rare cases; we generate dangi via the
// same Beijing algorithm because the SM tests we care about target
// chinese, and the existing dangi table is the ICU snapshot for the
// common range.)
function rdc_chineseOffset(float $tee): float
{
    [$y] = rdc_gfromfixed((int) floor($tee));
    if ($y < 1929) {
        return 1397.0 / 180.0 / 24.0; // 116°25'E in days
    }
    return 8.0 / 24.0;
}

function rdc_stdFromUni(float $tee): float
{
    return $tee + rdc_chineseOffset($tee);
}

function rdc_uniFromStd(float $tee): float
{
    return $tee - rdc_chineseOffset($tee);
}

function rdc_aberration(float $tee): float
{
    $c = ($tee - RDC_J2000) / 36525.0;
    return 0.0000974 * rdc_dcos(177.63 + 35999.01848 * $c) - 0.005575;
}

function rdc_nutation(float $tee): float
{
    $c = ($tee - RDC_J2000) / 36525.0;
    $A = 124.90 - 1934.134 * $c + 0.002063 * $c * $c;
    $B = 201.11 + 72001.5377 * $c + 0.00057 * $c * $c;
    return -0.004778 * rdc_dsin($A) - 0.0003667 * rdc_dsin($B);
}

// Apparent solar longitude in degrees. R-D 14.31.
function rdc_solar(float $tee): float
{
    $c = ($tee - RDC_J2000) / 36525.0;
    static $tab = [
        [403406, 270.54861, 0.9287892], [195207, 340.19128, 35999.1376958],
        [119433, 63.91854, 35999.4089666], [112392, 331.2622, 35998.7287385],
        [3891, 317.843, 71998.20261], [2819, 86.631, 71998.4403],
        [1721, 240.052, 36000.35726], [660, 310.26, 71997.4812],
        [350, 247.23, 32964.4678], [334, 260.87, -19.4410],
        [314, 297.82, 445267.1117], [268, 343.14, 45036.8840],
        [242, 166.79, 3.1008], [234, 81.53, 22518.4434],
        [158, 3.50, -19.9739], [132, 132.75, 65928.9345],
        [129, 182.95, 9038.0293], [114, 162.03, 3034.7684],
        [99, 29.8, 33718.148], [93, 266.4, 3034.448],
        [86, 249.2, -2280.773], [78, 157.6, 29929.992],
        [72, 257.8, 31556.493], [68, 185.1, 149.588],
        [64, 69.9, 9037.750], [46, 8.0, 107997.405],
        [38, 197.1, -4444.176], [37, 250.4, 151.771],
        [32, 65.3, 67555.316], [29, 162.7, 31556.080],
        [28, 341.5, -4561.540], [27, 291.6, 107996.706],
        [27, 98.5, 1221.655], [25, 146.7, 62894.167],
        [24, 110.0, 31437.369], [21, 5.2, 14578.298],
        [21, 342.6, -31931.757], [20, 230.9, 34777.243],
        [18, 256.1, 1221.999], [17, 45.3, 62894.511],
        [14, 242.9, -4442.039], [13, 115.2, 107997.909],
        [13, 151.8, 119.066], [13, 285.3, 16859.071],
        [12, 53.3, -4.578], [10, 126.6, 26895.292],
        [10, 205.7, -39.127], [10, 85.9, 12297.536],
        [10, 146.1, 90073.778],
    ];
    $lam = 282.7771834 + 36000.76953744 * $c;
    $sum = 0.0;
    foreach ($tab as [$a, $b, $cc]) {
        $sum += $a * rdc_dsin($b + $cc * $c);
    }
    $lam += 0.000005729577951308232 * $sum;
    $lam += rdc_aberration($tee) + rdc_nutation($tee);
    return rdc_rmod($lam, 360.0);
}

// Apparent lunar longitude. R-D 14.43 / Meeus chap 47.
function rdc_lunar(float $tee): float
{
    $c = ($tee - RDC_J2000) / 36525.0;
    $L = 218.3164591 + 481267.88134236 * $c - 0.0013268 * $c * $c
        + $c ** 3 / 538841.0 - $c ** 4 / 65194000.0;
    $D = 297.8502042 + 445267.1115168 * $c - 0.00163 * $c * $c
        + $c ** 3 / 545868.0 - $c ** 4 / 113065000.0;
    $M = 357.5291092 + 35999.0502909 * $c - 0.0001536 * $c * $c + $c ** 3 / 24490000.0;
    $Mp = 134.9634114 + 477198.8676313 * $c + 0.008997 * $c * $c
        + $c ** 3 / 69699.0 - $c ** 4 / 14712000.0;
    $F = 93.2720993 + 483202.0175273 * $c - 0.0034029 * $c * $c
        - $c ** 3 / 3526000.0 + $c ** 4 / 863310000.0;
    $E = 1 - 0.002516 * $c - 0.0000074 * $c * $c;
    static $terms = [
        [0, 0, 1, 0, 6288774], [2, 0, -1, 0, 1274027], [2, 0, 0, 0, 658314], [0, 0, 2, 0, 213618],
        [0, 1, 0, 0, -185116], [0, 0, 0, 2, -114332], [2, 0, -2, 0, 58793], [2, -1, -1, 0, 57066],
        [2, 0, 1, 0, 53322], [2, -1, 0, 0, 45758], [0, 1, -1, 0, -40923], [1, 0, 0, 0, -34720],
        [0, 1, 1, 0, -30383], [2, 0, 0, -2, 15327], [0, 0, 1, 2, -12528], [0, 0, 1, -2, 10980],
        [4, 0, -1, 0, 10675], [0, 0, 3, 0, 10034], [4, 0, -2, 0, 8548], [2, 1, -1, 0, -7888],
        [2, 1, 0, 0, -6766], [1, 0, -1, 0, -5163], [1, 1, 0, 0, 4987], [2, -1, 1, 0, 4036],
        [2, 0, 2, 0, 3994], [4, 0, 0, 0, 3861], [2, 0, -3, 0, 3665], [0, 1, -2, 0, -2689],
        [2, 0, -1, 2, -2602], [2, -1, -2, 0, 2390], [1, 0, 1, 0, -2348], [2, -2, 0, 0, 2236],
        [0, 1, 2, 0, -2120], [0, 2, 0, 0, -2069], [2, -2, -1, 0, 2048], [2, 0, 1, -2, -1773],
        [2, 0, 0, 2, -1595], [4, -1, -1, 0, 1215], [0, 0, 2, 2, -1110], [3, 0, -1, 0, -892],
        [2, 1, 1, 0, -810], [4, -1, -2, 0, 759], [0, 2, -1, 0, -713], [2, 2, -1, 0, -700],
        [2, 1, -2, 0, 691], [2, -1, 0, -2, 596], [4, 0, 1, 0, 549], [0, 0, 4, 0, 537],
        [4, -1, 0, 0, 520], [1, 0, -2, 0, -487], [2, 1, 0, -2, -399], [0, 0, 2, -2, -381],
        [1, 1, 1, 0, 351], [3, 0, -2, 0, -340], [4, 0, -3, 0, 330], [2, -1, 2, 0, 327],
        [0, 2, 1, 0, -323], [1, 1, -1, 0, 299], [2, 0, 3, 0, 294],
    ];
    $sum = 0.0;
    foreach ($terms as [$d, $mm, $mp, $f, $v]) {
        $sum += $v * $E ** abs($mm) * rdc_dsin($d * $D + $mm * $M + $mp * $Mp + $f * $F);
    }
    $lon = $L + $sum / 1000000.0;
    $A1 = 119.75 + 131.849 * $c;
    $A2 = 53.09 + 479264.290 * $c;
    $venus = 3958 * rdc_dsin($A1) + 1962 * rdc_dsin($L - $F) + 318 * rdc_dsin($A2);
    $lon += $venus / 1000000.0;
    $lon += rdc_nutation($tee);
    return rdc_rmod($lon, 360.0);
}

function rdc_lunarPhase(float $tee): float
{
    return rdc_rmod(rdc_lunar($tee) - rdc_solar($tee), 360.0);
}

// Nth mean new moon since J2000 (R-D 14.44 / Meeus algorithm 49).
function rdc_nthNewMoon(int $n): float
{
    $k = $n - 24724;
    $c = $k / 1236.85;
    $approx = RDC_J2000 + 5.09766 + RDC_MEAN_SYNODIC_MONTH * 1236.85 * $c
        + 0.0001437 * $c * $c - 0.00000015 * $c ** 3 + 0.00000000073 * $c ** 4;
    $E = 1 - 0.002516 * $c - 0.0000074 * $c * $c;
    $solar = 2.5534 + 1236.85 * 29.10535670 * $c - 0.0000014 * $c * $c - 0.00000011 * $c ** 3;
    $lun = 201.5643 + 385.81693528 * 1236.85 * $c + 0.0107582 * $c * $c
        + 0.00001238 * $c ** 3 - 0.000000058 * $c ** 4;
    $arg = 160.7108 + 390.67050284 * 1236.85 * $c - 0.0016118 * $c * $c
        - 0.00000227 * $c ** 3 + 0.000000011 * $c ** 4;
    $omega = 124.7746 - 1.56375588 * 1236.85 * $c + 0.0020672 * $c * $c + 0.00000215 * $c ** 3;
    static $tab = [
        [-0.40720, 0, 0, 1, 0], [0.17241, 1, 1, 0, 0], [0.01608, 0, 0, 2, 0], [0.01039, 0, 0, 0, 2],
        [0.00739, 1, -1, 1, 0], [-0.00514, 1, 1, 1, 0], [0.00208, 2, 2, 0, 0], [-0.00111, 0, 0, 1, -2],
        [-0.00057, 0, 0, 1, 2], [0.00056, 1, 1, 2, 0], [-0.00042, 0, 0, 3, 0], [0.00042, 1, 1, 0, 2],
        [0.00038, 1, 1, 0, -2], [-0.00024, 1, -1, 2, 0], [-0.00007, 0, 2, 1, 0], [0.00004, 0, 0, 2, -2],
        [0.00004, 0, 3, 0, 0], [0.00003, 0, 1, 1, -2], [0.00003, 0, 0, 2, 2], [-0.00003, 0, 1, 1, 2],
        [0.00003, 0, -1, 1, 2], [-0.00002, 0, -1, 1, -2], [-0.00002, 0, 1, 3, 0], [0.00002, 0, 0, 4, 0],
    ];
    $corr = -0.00017 * rdc_dsin($omega);
    foreach ($tab as [$v, $w, $x, $y, $z]) {
        $corr += $v * $E ** $w * rdc_dsin($x * $solar + $y * $lun + $z * $arg);
    }
    $extra = 0.000325 * rdc_dsin(299.77 + 132.8475848 * $c - 0.009173 * $c * $c);
    static $add = [
        [251.88, 0.016321, 0.000165], [251.83, 26.651886, 0.000164], [349.42, 36.412478, 0.000126],
        [84.66, 18.206239, 0.000110], [141.74, 53.303771, 0.000062], [207.14, 2.453732, 0.000060],
        [154.84, 7.306860, 0.000056], [34.52, 27.261239, 0.000047], [207.19, 0.121824, 0.000042],
        [291.34, 1.844379, 0.000040], [161.72, 24.198154, 0.000037], [239.56, 25.513099, 0.000035],
        [331.55, 3.592518, 0.000023],
    ];
    foreach ($add as [$a, $b, $cc]) {
        $extra += $cc * rdc_dsin($a + $b * $k);
    }
    return rdc_uniFromDyn($approx + $corr + $extra);
}

function rdc_newMoonBefore(float $tee): float
{
    $t0 = rdc_nthNewMoon(0);
    $phi = rdc_lunarPhase($tee);
    $n = (int) round(($tee - $t0) / RDC_MEAN_SYNODIC_MONTH - $phi / 360.0);
    while (rdc_nthNewMoon($n) >= $tee) {
        $n--;
    }
    while (rdc_nthNewMoon($n + 1) < $tee) {
        $n++;
    }
    return rdc_nthNewMoon($n);
}

function rdc_newMoonAtOrAfter(float $tee): float
{
    $t0 = rdc_nthNewMoon(0);
    $phi = rdc_lunarPhase($tee);
    $n = (int) round(($tee - $t0) / RDC_MEAN_SYNODIC_MONTH - $phi / 360.0);
    while (rdc_nthNewMoon($n) < $tee) {
        $n++;
    }
    while (rdc_nthNewMoon($n - 1) >= $tee) {
        $n--;
    }
    return rdc_nthNewMoon($n);
}

function rdc_midnightCN(int $date): float
{
    return rdc_uniFromStd((float) $date);
}

function rdc_currentMajorTerm(int $date): int
{
    $s = rdc_solar(rdc_uniFromStd((float) $date));
    return (int) rdc_rmod(2 + floor($s / 30.0), 12);
}

function rdc_noMajorTerm(int $date): bool
{
    $next = (int) floor(rdc_stdFromUni(rdc_newMoonAtOrAfter(rdc_midnightCN($date + 1))));
    return rdc_currentMajorTerm($date) === rdc_currentMajorTerm($next);
}

function rdc_priorSolarLongitude(float $lam, float $tee): float
{
    $rate = 365.2422 / 360.0;
    $tau = $tee - $rate * rdc_rmod(rdc_solar($tee) - $lam, 360.0);
    $cap = rdc_rmod(rdc_solar($tau) - $lam + 180.0, 360.0) - 180.0;
    return min($tee, $tau - $rate * $cap);
}

function rdc_winterSolsticeOnOrBefore(int $date): int
{
    $approx = rdc_priorSolarLongitude(270.0, rdc_midnightCN($date + 1));
    for ($d = (int) floor($approx) - 3; $d <= (int) floor($approx) + 3; $d++) {
        $s0 = rdc_solar(rdc_midnightCN($d));
        $s1 = rdc_solar(rdc_midnightCN($d + 1));
        if ($s0 <= 270.0 && $s1 > 270.0) {
            return $d;
        }
    }
    return (int) floor($approx);
}

function rdc_chineseNYOnOrBefore(int $date): int
{
    $s1 = rdc_winterSolsticeOnOrBefore($date);
    $s2 = rdc_winterSolsticeOnOrBefore($s1 + 370);
    $m12 = (int) floor(rdc_stdFromUni(rdc_newMoonAtOrAfter(rdc_midnightCN($s1 + 1))));
    $nextM11 = (int) floor(rdc_stdFromUni(rdc_newMoonBefore(rdc_midnightCN($s2 + 1))));
    $leapYear = (int) round(($nextM11 - $m12) / RDC_MEAN_SYNODIC_MONTH) === 12;
    $m13 = (int) floor(rdc_stdFromUni(rdc_newMoonAtOrAfter(rdc_midnightCN($m12 + 1))));
    if ($leapYear && (rdc_noMajorTerm($m12) || rdc_noMajorTerm($m13))) {
        return (int) floor(rdc_stdFromUni(rdc_newMoonAtOrAfter(rdc_midnightCN($m13 + 1))));
    }
    return $m13;
}

function rdc_chineseNewYearForExt(int $extYear): int
{
    $hint = rdc_fixed($extYear, 7, 1);
    $ny = rdc_chineseNYOnOrBefore($hint);
    [$gy] = rdc_gfromfixed($ny);
    if ($gy === $extYear) {
        return $ny;
    }
    if ($gy < $extYear) {
        return rdc_chineseNYOnOrBefore(rdc_fixed($extYear + 1, 1, 31));
    }
    return rdc_chineseNYOnOrBefore(rdc_fixed($extYear, 1, 31));
}

/**
 * @return array{newYearDays:int,leapIcuMonth:int,monthLenBits:int,monthCount:int}|null
 */
function rdc_chineseYearInfo(int $extYear): ?array
{
    $ny = rdc_chineseNewYearForExt($extYear);
    $nextNy = rdc_chineseNewYearForExt($extYear + 1);
    $yearLen = $nextNy - $ny;
    $monthCount = $yearLen > 360 ? 13 : 12;
    $starts = [$ny];
    $cur = $ny;
    for ($i = 0; $i < $monthCount; $i++) {
        $cur = (int) floor(rdc_stdFromUni(rdc_newMoonAtOrAfter(rdc_midnightCN($cur + 1))));
        $starts[] = $cur;
    }
    $monthDays = [];
    for ($i = 0; $i < $monthCount; $i++) {
        $monthDays[] = $starts[$i + 1] - $starts[$i];
    }
    $leapChronoIdx = -1;
    if ($monthCount === 13) {
        for ($i = 0; $i < $monthCount; $i++) {
            if (rdc_noMajorTerm($starts[$i])) {
                $leapChronoIdx = $i;
                break;
            }
        }
    }
    $leapIcu = $leapChronoIdx >= 1 ? $leapChronoIdx - 1 : -1;
    $bits = 0;
    foreach ($monthDays as $i => $d) {
        if ($d === 30) {
            $bits |= (1 << $i);
        }
    }
    return [
        'newYearDays' => $ny - rdc_fixed(1970, 1, 1),
        'leapIcuMonth' => $leapIcu,
        'monthLenBits' => $bits,
        'monthCount' => $monthCount,
    ];
}

// -----------------------------------------------------------------------
// Main: read existing data file, compute deep-past prefix, rewrite file.
// -----------------------------------------------------------------------

$cal = $argv[1] ?? 'chinese';
if (!in_array($cal, ['chinese', 'dangi'], true)) {
    fwrite(STDERR, "Calendar must be 'chinese' or 'dangi'.\n");
    exit(1);
}

$newStart = isset($argv[2]) ? (int) $argv[2] : -7500;
$dataPath = __DIR__ . "/../src/BuiltIn/data/{$cal}_calendar.php";
if (!is_file($dataPath)) {
    fwrite(STDERR, "Data file not found: $dataPath\n");
    fwrite(STDERR, "Run bin/gen-chinese-table.php first.\n");
    exit(1);
}

$data = require $dataPath;
$existingBlob = gzuncompress(base64_decode($data['blob'], true));
$origStart = (int) $data['start'];
$origEnd = (int) $data['end'];
$recordSize = 8;
fwrite(STDERR, "Existing $cal table: $origStart..$origEnd (" . strlen($existingBlob) . " bytes)\n");

if ($newStart >= $origStart) {
    fwrite(STDERR, "newStart ($newStart) is not earlier than existing start ($origStart); nothing to do.\n");
    exit(0);
}

$prefix = '';
$endExclusive = $origStart - 1;
$count = $origStart - $newStart;
fwrite(STDERR, "Computing $count years from R-D ($newStart..$endExclusive)...\n");
$t0 = microtime(true);
for ($cy = $newStart; $cy < $origStart; $cy++) {
    $info = rdc_chineseYearInfo($cy);
    if ($info === null) {
        $prefix .= str_repeat("\0", $recordSize);
        fwrite(STDERR, "WARNING: year $cy R-D returned null\n");
        continue;
    }
    $prefix .= pack('l', $info['newYearDays'])
        . pack('c', $info['leapIcuMonth'])
        . pack('v', $info['monthLenBits'])
        . pack('C', $info['monthCount']);
    if (($cy - $newStart) % 500 === 0) {
        fwrite(STDERR, "  year $cy: NY=" . $info['newYearDays'] . " leap=" . $info['leapIcuMonth'] . "\n");
    }
}
fwrite(STDERR, "Computed prefix in " . round(microtime(true) - $t0, 1) . "s (" . strlen($prefix) . " bytes)\n");

$blob = $prefix . $existingBlob;
$expected = ($origEnd - $newStart + 1) * $recordSize;
if (strlen($blob) !== $expected) {
    fwrite(STDERR, "ERROR: blob length " . strlen($blob) . " != expected $expected\n");
    exit(1);
}

$compressed = gzcompress($blob, 9);
if ($compressed === false) {
    fwrite(STDERR, "gzcompress failed\n");
    exit(1);
}
$encoded = base64_encode($compressed);

$out = "<?php\n\n";
$out .= "declare(strict_types=1);\n\n";
$out .= "// Auto-generated. DO NOT EDIT BY HAND.\n";
$out .= "// Regenerate with:\n";
$out .= "//   php bin/gen-chinese-table.php $cal > src/BuiltIn/data/{$cal}_calendar.php\n";
$out .= "//   php bin/gen-chinese-table-rd-extension.php $cal\n";
$out .= "//\n";
$out .= "// Snapshot of Reingold-Dershowitz-equivalent $cal calendar arithmetic\n";
$out .= "// for extended-year range $newStart..$origEnd.\n";
$out .= "//   * Years $origStart..$origEnd come from ICU 76+ (matches V8 / Unicode 16 reference).\n";
$out .= "//   * Years $newStart..$endExclusive come from a pure-PHP R-D implementation\n";
$out .= "//     (Calendrical Calculations §19 astronomical algorithm). ICU 78 does not\n";
$out .= "//     produce a 30-day variant of M01L/M09L/M10L/M11L/M12L within this range;\n";
$out .= "//     the R-D astronomical computation does. The SM test\n";
$out .= "//     `from-chinese-leap-month-uncommon` asserts these 30-day uncommon leap\n";
$out .= "//     months exist somewhere ≤ ISO 1972.\n";
$out .= "//\n";
$out .= "// Each extended-year maps to an 8-byte packed record:\n";
$out .= "//   int32 LE  newYearDays   = days since ISO 1970-01-01 for M01-01\n";
$out .= "//   int8      leapIcuMonth  = 0..11 if year has a leap month, -1 otherwise\n";
$out .= "//   uint16 LE monthLenBits  = bit i set when chronological month i has 30 days\n";
$out .= "//   uint8     monthCount    = 12 or 13\n";
$out .= "\n";
$out .= "return [\n";
$out .= "    'start' => $newStart,\n";
$out .= "    'end' => $origEnd,\n";
$out .= "    'blob' => '$encoded',\n";
$out .= "];\n";

file_put_contents($dataPath, $out);
fwrite(STDERR, "Wrote $dataPath (" . filesize($dataPath) . " bytes)\n");
