<?php

declare(strict_types=1);

namespace Phasis\Value;

/** String iterator internal state, independent of JavaScript property mutations. */
final class JsStringIterator extends JsObject
{
    private int $nextIndex = 0;

    private ?string $units;

    public function __construct(
        private ?JsString $string,
        private readonly JsFunction $intrinsicNext,
        JsObject $prototype,
    ) {
        parent::__construct($prototype);
        $this->units = $string?->utf16LE();
    }

    /** Whether the cached method can advance without creating a result object. */
    public function hasIntrinsicNext(JsValue $method): bool
    {
        return $method === $this->intrinsicNext
            && ($this->intrinsicNext->realm === null || $this->intrinsicNext->realm === \Phasis\Engine::getCurrentRealm());
    }

    /** Advance by one code point, retaining individual unpaired surrogates. */
    public function nextValue(bool &$done): JsValue
    {
        $string = $this->string;
        if ($string === null) {
            $done = true;
            return JsUndefined::instance();
        }
        $units = $this->units ?? '';
        $offset = $this->nextIndex * 2;
        $length = strlen($units);
        if ($offset >= $length) {
            $this->string = null;
            $this->units = null;
            $done = true;
            return JsUndefined::instance();
        }
        $first = ord($units[$offset]) | (ord($units[$offset + 1]) << 8);
        if ($first >= 0xD800 && $first <= 0xDBFF && $offset + 3 < $length) {
            $second = ord($units[$offset + 2]) | (ord($units[$offset + 3]) << 8);
            if ($second >= 0xDC00 && $second <= 0xDFFF) {
                $this->nextIndex += 2;
                $done = false;
                return new JsString(mb_chr(($first - 0xD800) * 0x400 + ($second - 0xDC00) + 0x10000, 'UTF-8'));
            }
        }
        $this->nextIndex++;
        $done = false;
        return new JsString(JsString::utf16CodeUnitToUtf8($first));
    }
}
