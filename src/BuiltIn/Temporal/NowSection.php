<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Temporal;

use Phasis\Object\PropertyDescriptor;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * Temporal.Now namespace object. Composed into TemporalObject via
 * `use NowSection;` — the per-section split is purely organisational
 * (Temporal is a 17-class proposal and one mega-file is unreadable).
 * `self::` references resolve into the composing class, so the helper
 * calls (createInstantObject, resolveTimeZone, etc.) still hit the
 * private statics defined elsewhere in TemporalObject.
 */
trait NowSection
{
    private static function installNow(
        JsObject $temporal,
        JsObject $instantProto,
        JsObject $plainDateProto,
        JsObject $plainTimeProto,
        JsObject $plainDateTimeProto,
    ): void {
        $now = new JsObject();

        $m = static fn (string $n, \Closure $fn, int $len = 0) => $now->defineOwnProperty(
            $n,
            PropertyDescriptor::data(JsFunction::fromCallable($n, $fn, $len), true, false, true),
        );

        $m('instant', function (JsValue $this_): JsValue {
            $ms = (int) (microtime(true) * 1000);
            $ns = bcmul((string) $ms, '1000000', 0);
            return self::createInstantObject($ns);
        });

        $m('timeZoneId', function (JsValue $this_): JsValue {
            return new JsString(date_default_timezone_get());
        });

        $m('plainDateISO', function (JsValue $this_, array $args): JsValue {
            $tz = date_default_timezone_get();
            if (isset($args[0]) && !($args[0] instanceof JsUndefined)) {
                $tz = self::toTemporalTimeZoneIdentifier($args[0]);
            }
            $dt = new \DateTimeImmutable('now', self::resolveTimeZone($tz));
            return self::createPlainDateObject(
                (int) $dt->format('Y'),
                (int) $dt->format('n'),
                (int) $dt->format('j'),
                'iso8601',
            );
        }, 0);

        $m('plainTimeISO', function (JsValue $this_, array $args): JsValue {
            $tz = date_default_timezone_get();
            if (isset($args[0]) && !($args[0] instanceof JsUndefined)) {
                $tz = self::toTemporalTimeZoneIdentifier($args[0]);
            }
            $dt = new \DateTimeImmutable('now', self::resolveTimeZone($tz));
            return self::createPlainTimeObject(
                (int) $dt->format('G'),
                (int) $dt->format('i'),
                (int) $dt->format('s'),
                0,
                0,
                0,
            );
        }, 0);

        $m('plainDateTimeISO', function (JsValue $this_, array $args): JsValue {
            $tz = date_default_timezone_get();
            if (isset($args[0]) && !($args[0] instanceof JsUndefined)) {
                $tz = self::toTemporalTimeZoneIdentifier($args[0]);
            }
            $dt = new \DateTimeImmutable('now', self::resolveTimeZone($tz));
            return self::createPlainDateTimeObject(
                (int) $dt->format('Y'),
                (int) $dt->format('n'),
                (int) $dt->format('j'),
                (int) $dt->format('G'),
                (int) $dt->format('i'),
                (int) $dt->format('s'),
                0,
                0,
                0,
                'iso8601',
            );
        }, 0);

        $m('zonedDateTimeISO', function (JsValue $this_, array $args): JsValue {
            $tz = date_default_timezone_get();
            if (isset($args[0]) && !($args[0] instanceof JsUndefined)) {
                $tz = self::toTemporalTimeZoneIdentifier($args[0]);
            }
            $ms = (int) (microtime(true) * 1000);
            $ns = bcmul((string) $ms, '1000000', 0);
            return self::createZonedDateTimeObject($ns, $tz, 'iso8601');
        }, 0);

        self::setToStringTag($now, 'Temporal.Now');

        $temporal->defineOwnProperty('Now', PropertyDescriptor::data($now, true, false, true));
    }
}
