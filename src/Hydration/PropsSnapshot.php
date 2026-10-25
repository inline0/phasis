<?php

declare(strict_types=1);

namespace Phasis\Hydration;

use Phasis\BuiltIn\Jsx\React\ReactModule;

/** @internal Validates data without invoking PHP interop or serialization callbacks. */
final class PropsSnapshot
{
    public const PROTOCOL = 1;
    private const MAX_DEPTH = 128;
    private const MAX_SAFE_INTEGER = 9007199254740991;

    /**
     * Capture the complete, script-safe wire payload before any component module executes.
     *
     * @param array<array-key, mixed>|\stdClass $props Ordinary JSON data, with an object-shaped root.
     */
    public static function capture(array|\stdClass $props, string $export, HydrationOptions $options): string
    {
        if ($export === '' || preg_match('//u', $export) !== 1) {
            throw new \InvalidArgumentException('Hydration export must be a nonempty UTF-8 name.');
        }
        if (is_array($props) && $props !== [] && array_is_list($props)) {
            throw new \InvalidArgumentException('Hydration root props must be an object or a PHP map, not a list.');
        }
        self::validate($props, [], 0);
        $payload = [
            'protocol' => self::PROTOCOL,
            'entryId' => $options->entryId,
            'export' => $export,
            'buildId' => $options->buildId,
            'rootId' => $options->rootId,
            'identifierPrefix' => $options->identifierPrefix,
            'reactVersion' => ReactModule::VERSION,
            'props' => $props === [] ? new \stdClass() : $props,
        ];
        return self::withPrecision(static fn (): string => json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRESERVE_ZERO_FRACTION,
            self::MAX_DEPTH + 4,
        ));
    }

    /**
     * Keep both PHP JSON encoding and React numeric output independent of host precision.
     *
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    public static function withPrecision(\Closure $operation): mixed
    {
        $precision = ini_set('serialize_precision', '-1');
        if ($precision === false) {
            throw new \RuntimeException('Cannot configure round-trip JSON precision for hydration.');
        }
        try {
            return $operation();
        } finally {
            ini_set('serialize_precision', $precision);
        }
    }

    /** @param array<int, true> $ancestors Object identities on this branch, not shared siblings. */
    private static function validate(mixed $value, array $ancestors, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('Hydration props contain an array cycle or exceed the maximum depth of 128.');
        }
        if ($value === null || is_bool($value) || is_string($value)) {
            return;
        }
        if (is_int($value)) {
            if ($value < -self::MAX_SAFE_INTEGER || $value > self::MAX_SAFE_INTEGER) {
                throw new \InvalidArgumentException(
                    'Hydration integers must be within JavaScript safe-integer bounds; use a string for larger integers.',
                );
            }
            return;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('Hydration numbers must be finite.');
            }
            return;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::validate($item, $ancestors, $depth + 1);
            }
            return;
        }
        if (is_object($value) && $value::class === \stdClass::class) {
            $identity = spl_object_id($value);
            if (isset($ancestors[$identity])) {
                throw new \InvalidArgumentException('Hydration props contain an object cycle.');
            }
            $ancestors[$identity] = true;
            foreach (get_object_vars($value) as $item) {
                self::validate($item, $ancestors, $depth + 1);
            }
            return;
        }
        throw new \InvalidArgumentException('Hydration props accept only JSON data and plain stdClass objects.');
    }
}
