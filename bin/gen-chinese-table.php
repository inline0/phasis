#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate the pure-PHP Chinese calendar lookup table.
 *
 * The Chinese calendar is astronomically determined (new moons + major
 * solar terms computed in Beijing local time). Reingold-Dershowitz
 * "Calendrical Calculations" §19 gives the formulas, but the algorithm is
 * sensitive to small precision errors near month boundaries: a few
 * minutes around midnight Beijing time shifts a date by one day. To stay
 * independent of the host ICU version (Ubuntu CI runs ICU 70/74, macOS
 * runs ICU 78), we snapshot a known-good implementation here.
 *
 * The source of truth is ICU 76+ (Unicode 16) which matches V8 and the
 * test262 reference outputs. This script must be run on a machine with
 * ICU 76+ to regenerate the table. The resulting blob is committed into
 * src/BuiltIn/data/chinese_calendar.php and the runtime never depends on
 * IntlCalendar for chinese arithmetic.
 *
 * Range covered: chinese extended-year -2900 .. +3500. The extended-year is
 * what ICU exposes via FIELD_EXTENDED_YEAR (the Gregorian year that
 * contains M01-01 of that Chinese year). The Temporal `year` getter
 * returns this value directly.
 *
 * Per chinese year we record:
 *   - newYearDays   : days since ISO 1970-01-01 for M01-01
 *   - leapIcuMonth  : 0..11 if year has a leap month, -1 otherwise
 *   - monthLenBits  : bitmap, bit i = (chronological month i has 30 days)
 *   - monthCount    : 12 or 13
 *
 * Packed 8 bytes per year. Compressed + base64 for embedding in PHP source.
 *
 * Usage: php bin/gen-chinese-table.php chinese > src/BuiltIn/data/chinese_calendar.php
 *        php bin/gen-chinese-table.php dangi   > src/BuiltIn/data/dangi_calendar.php
 *
 * Dangi is the Korean ICU calendar — same R-D algorithm as chinese but
 * uses Seoul local time (UTC+9) for new-moon detection vs Beijing (UTC+8).
 * That shifts a small fraction of month boundaries by one day, so we
 * snapshot dangi separately rather than aliasing it to chinese.
 */

ini_set('memory_limit', '2G');

if (!class_exists('IntlCalendar', false)) {
    fwrite(STDERR, "IntlCalendar not available; install the intl extension.\n");
    exit(1);
}

$cal = $argv[1] ?? 'chinese';
if (!in_array($cal, ['chinese', 'dangi'], true)) {
    fwrite(STDERR, "Calendar must be 'chinese' or 'dangi'.\n");
    exit(1);
}

$icuVersion = defined('INTL_ICU_VERSION') ? INTL_ICU_VERSION : 'unknown';
if (version_compare((string) $icuVersion, '76.0', '<')) {
    fwrite(
        STDERR,
        "WARNING: ICU {$icuVersion} is older than 76. Chinese calendar leap-month placement may differ from V8 reference.\n",
    );
}

$START = -2900;
$END = 3500;

$rows = [];
for ($cy = $START; $cy <= $END; $cy++) {
    $probe = \IntlCalendar::createInstance('UTC', "en@calendar={$cal}");
    $probe->set(\IntlCalendar::FIELD_EXTENDED_YEAR, $cy);
    $probe->set(\IntlCalendar::FIELD_MONTH, 0);
    $probe->set(\IntlCalendar::FIELD_IS_LEAP_MONTH, 0);
    $probe->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
    $ms = $probe->getTime();
    $verify = \IntlCalendar::createInstance('UTC', "en@calendar={$cal}");
    $verify->setTime($ms);
    if ($verify->get(\IntlCalendar::FIELD_EXTENDED_YEAR) !== $cy) {
        continue;
    }
    $newYearDays = (int) floor($ms / 86400000);

    $leapIcu = -1;
    for ($m = 0; $m < 12; $m++) {
        $p2 = \IntlCalendar::createInstance('UTC', "en@calendar={$cal}");
        $p2->set(\IntlCalendar::FIELD_EXTENDED_YEAR, $cy);
        $p2->set(\IntlCalendar::FIELD_MONTH, $m);
        $p2->set(\IntlCalendar::FIELD_IS_LEAP_MONTH, 1);
        $p2->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $ms2 = $p2->getTime();
        $v2 = \IntlCalendar::createInstance('UTC', "en@calendar={$cal}");
        $v2->setTime($ms2);
        if (
            $v2->get(\IntlCalendar::FIELD_EXTENDED_YEAR) === $cy
            && $v2->get(\IntlCalendar::FIELD_MONTH) === $m
            && $v2->get(\IntlCalendar::FIELD_IS_LEAP_MONTH) === 1
        ) {
            $leapIcu = $m;
            break;
        }
    }

    $chrono = [];
    if ($leapIcu === -1) {
        for ($m = 0; $m < 12; $m++) {
            $chrono[] = [$m, 0];
        }
    } else {
        for ($m = 0; $m <= $leapIcu; $m++) {
            $chrono[] = [$m, 0];
        }
        $chrono[] = [$leapIcu, 1];
        for ($m = $leapIcu + 1; $m < 12; $m++) {
            $chrono[] = [$m, 0];
        }
    }
    $monthStarts = [];
    foreach ($chrono as [$m, $isL]) {
        $p3 = \IntlCalendar::createInstance('UTC', "en@calendar={$cal}");
        $p3->set(\IntlCalendar::FIELD_EXTENDED_YEAR, $cy);
        $p3->set(\IntlCalendar::FIELD_MONTH, $m);
        $p3->set(\IntlCalendar::FIELD_IS_LEAP_MONTH, $isL);
        $p3->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $monthStarts[] = (int) floor($p3->getTime() / 86400000);
    }
    $p4 = \IntlCalendar::createInstance('UTC', "en@calendar={$cal}");
    $p4->set(\IntlCalendar::FIELD_EXTENDED_YEAR, $cy + 1);
    $p4->set(\IntlCalendar::FIELD_MONTH, 0);
    $p4->set(\IntlCalendar::FIELD_IS_LEAP_MONTH, 0);
    $p4->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
    $monthStarts[] = (int) floor($p4->getTime() / 86400000);

    $monthDays = [];
    for ($i = 0; $i < count($monthStarts) - 1; $i++) {
        $monthDays[] = $monthStarts[$i + 1] - $monthStarts[$i];
    }

    $bits = 0;
    foreach ($monthDays as $i => $d) {
        if ($d === 30) {
            $bits |= (1 << $i);
        } elseif ($d !== 29) {
            fwrite(STDERR, "WARNING: year={$cy} month={$i} days={$d} (expected 29 or 30)\n");
        }
    }
    $rows[$cy] = pack('l', $newYearDays) . pack('c', $leapIcu) . pack('v', $bits) . pack('C', count($monthDays));
    if ($cy % 500 === 0) {
        fwrite(STDERR, "year {$cy} done\n");
    }
}

$blob = '';
for ($cy = $START; $cy <= $END; $cy++) {
    $blob .= $rows[$cy] ?? str_repeat("\0", 8);
}

$compressed = gzcompress($blob, 9);
if ($compressed === false) {
    fwrite(STDERR, "gzcompress failed\n");
    exit(1);
}
$encoded = base64_encode($compressed);

echo "<?php\n\n";
echo "declare(strict_types=1);\n\n";
echo "// Auto-generated by bin/gen-chinese-table.php (calendar={$cal}) from ICU {$icuVersion}.\n";
echo "// DO NOT EDIT BY HAND. Run the generator to regenerate.\n";
echo "//\n";
echo "// Snapshot of Reingold-Dershowitz-equivalent {$cal} calendar arithmetic\n";
echo "// for the range extended-year {$START}..{$END}. ICU 76+ matches the\n";
echo "// V8 / test262 reference; ICU 70/74 on older Linux distros diverges, so\n";
echo "// php-js carries this snapshot rather than dispatching through IntlCalendar.\n";
echo "//\n";
echo "// Each extended-year maps to an 8-byte packed record:\n";
echo "//   int32 LE  newYearDays   = days since ISO 1970-01-01 for M01-01\n";
echo "//   int8      leapIcuMonth  = 0..11 if year has a leap month, -1 otherwise\n";
echo "//   uint16 LE monthLenBits  = bit i set when chronological month i has 30 days\n";
echo "//   uint8     monthCount    = 12 or 13\n";
echo "\n";
echo "return [\n";
echo "    'start' => {$START},\n";
echo "    'end' => {$END},\n";
echo "    'blob' => '{$encoded}',\n";
echo "];\n";
