<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\TypedArray;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Exceptions\SyntaxError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\AbstractOperations;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsBigInt;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsDataView;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsSharedArrayBuffer;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\BuiltIn\SymbolConstructor;

/**
 * TypedArrayConstructor trait part: Uint8Methods. Composed into
 * TypedArrayConstructor via `use TypedArray\Uint8Methods;`.
 */
trait Uint8Methods
{
    /**
     * Decode a hex string into bytes per the ECMAScript Uint8Array.fromHex spec.
     *
     * Each pair of hex characters decodes to one byte. Uppercase and lowercase are
     * accepted. Any non-hex character or odd-length input causes a SyntaxError.
     *
     * When $maxBytes is non-null, decoding stops once $maxBytes bytes are produced.
     * $readCount is set to the number of input characters consumed.
     *
     * @param int|null $maxBytes Maximum bytes to write (null = unlimited).
     * @param int $readCount Set to the number of source chars consumed.
     * @return int[] Array of decoded byte values.
     */
    private static function decodeHex(string $input, ?int $maxBytes, int &$readCount): array
    {
        $bytes = [];
        $readCount = 0;
        $inputLen = strlen($input);

        if ($inputLen % 2 !== 0) {
            throw new SyntaxError('Uint8Array.fromHex: input must have even length');
        }

        for ($i = 0; $i < $inputLen; $i += 2) {
            if ($maxBytes !== null && count($bytes) >= $maxBytes) {
                $readCount = $i;
                return $bytes;
            }
            $hi = $input[$i];
            $lo = $input[$i + 1];
            if (!ctype_xdigit($hi) || !ctype_xdigit($lo)) {
                throw new SyntaxError("Invalid hex character in input: '{$hi}{$lo}'");
            }
            $bytes[] = (int) hexdec($hi . $lo);
            $readCount = $i + 2;
        }

        return $bytes;
    }
}
