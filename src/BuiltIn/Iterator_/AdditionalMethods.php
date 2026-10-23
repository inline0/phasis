<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Iterator_;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Spec\AbstractOperations;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/** Additional eager and lazy iterator methods. */
trait AdditionalMethods
{
    private static function includesMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Iterator.prototype.includes called on non-object');
            }
            $search = $args[0] ?? JsUndefined::instance();
            $skippedElements = $args[1] ?? JsUndefined::instance();
            $toSkip = 0.0;
            try {
                if (!$skippedElements instanceof JsUndefined) {
                    if (
                        !$skippedElements instanceof JsNumber
                        || is_nan($skippedElements->value)
                        || floor($skippedElements->value) !== $skippedElements->value
                    ) {
                        throw new TypeError('Skipped elements must be an integer or Infinity');
                    }
                    $toSkip = $skippedElements->value;
                }
                if ($toSkip < 0 || (is_finite($toSkip) && $toSkip > 9007199254740991.0)) {
                    throw new RangeError('Skipped elements are out of range');
                }
            } catch (\Throwable $e) {
                self::closeIterator($this_, true);
                throw $e;
            }
            [$iterator, $next] = self::getIteratorDirect($this_);
            $done = false;
            while (true) {
                [$value, $finished] = self::iteratorStep($iterator, $next, $done);
                if ($finished) {
                    return new JsBoolean(false);
                }
                if ($toSkip > 0) {
                    $toSkip--;
                } elseif (AbstractOperations::sameValueZero($value, $search)) {
                    self::closeIterator($iterator);
                    return new JsBoolean(true);
                }
            }
        };
    }

    private static function joinMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Iterator.prototype.join called on non-object');
            }
            $separator = $args[0] ?? JsUndefined::instance();
            try {
                $separatorString = $separator instanceof JsUndefined ? ',' : TypeConversion::toString($separator);
            } catch (\Throwable $e) {
                self::closeIterator($this_, true);
                throw $e;
            }
            [$iterator, $next] = self::getIteratorDirect($this_);
            $result = '';
            $first = true;
            $done = false;
            while (true) {
                [$value, $finished] = self::iteratorStep($iterator, $next, $done);
                if ($finished) {
                    return new JsString($result);
                }
                if (!$first) {
                    $result .= $separatorString;
                }
                $first = false;
                if (!$value instanceof JsNull && !$value instanceof JsUndefined) {
                    try {
                        $result .= TypeConversion::toString($value);
                    } catch (\Throwable $e) {
                        self::closeIterator($iterator, true);
                        throw $e;
                    }
                }
            }
        };
    }

    private static function chunkSize(JsObject $iterator, JsValue $size): float
    {
        try {
            if (!$size instanceof JsNumber || !is_finite($size->value) || floor($size->value) !== $size->value) {
                throw new TypeError('Iterator chunk size must be an integral Number');
            }
            if ($size->value < 1 || $size->value > 4294967295.0) {
                throw new RangeError('Iterator chunk size is out of range');
            }
            return $size->value;
        } catch (\Throwable $e) {
            self::closeIterator($iterator, true);
            throw $e;
        }
    }

    private static function chunksMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Iterator.prototype.chunks called on non-object');
            }
            $size = self::chunkSize($this_, $args[0] ?? JsUndefined::instance());
            [$iterator, $next] = self::getIteratorDirect($this_);
            return self::createIteratorHelper(
                $iterator,
                $next,
                function (bool &$done, bool &$alive) use ($iterator, $next, $size): JsObject {
                    $buffer = [];
                    while (count($buffer) < $size) {
                        [$value, $finished] = self::iteratorStep($iterator, $next, $done);
                        if ($finished) {
                            $alive = false;
                            break;
                        }
                        $buffer[] = $value;
                    }
                    return $buffer === []
                        ? self::iterResult(JsUndefined::instance(), true)
                        : self::iterResult(new JsArray($buffer), false);
                },
            );
        };
    }

    private static function windowsMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Iterator.prototype.windows called on non-object');
            }
            $size = self::chunkSize($this_, $args[0] ?? JsUndefined::instance());
            $undersized = $args[1] ?? JsUndefined::instance();
            if ($undersized instanceof JsUndefined) {
                $undersized = new JsString('only-full');
            }
            if (!$undersized instanceof JsString || !in_array($undersized->value, ['only-full', 'allow-partial'], true)) {
                self::closeIterator($this_, true);
                throw new TypeError('Invalid undersized window mode');
            }
            $allowPartial = $undersized->value === 'allow-partial';
            [$iterator, $next] = self::getIteratorDirect($this_);
            $buffer = [];
            return self::createIteratorHelper(
                $iterator,
                $next,
                function (bool &$done, bool &$alive) use ($iterator, $next, $size, $allowPartial, &$buffer): JsObject {
                    do {
                        [$value, $finished] = self::iteratorStep($iterator, $next, $done);
                        if ($finished) {
                            $alive = false;
                            if ($allowPartial && $buffer !== [] && count($buffer) < $size) {
                                return self::iterResult(new JsArray($buffer), false);
                            }
                            return self::iterResult(JsUndefined::instance(), true);
                        }
                        if (count($buffer) === (int) $size) {
                            array_shift($buffer);
                        }
                        $buffer[] = $value;
                    } while (count($buffer) < $size);
                    return self::iterResult(new JsArray($buffer), false);
                },
            );
        };
    }
}
