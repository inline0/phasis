<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Iterator_;

use Phasis\BuiltIn\SymbolConstructor;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/** Joint iteration with reverse-order closing and cached next methods. */
trait JointIteration
{
    private static function zipMethod(bool $keyed): \Closure
    {
        return function (JsValue $this_, array $args) use ($keyed): JsValue {
            $iterables = $args[0] ?? JsUndefined::instance();
            if (!$iterables instanceof JsObject) {
                throw new TypeError('Iterator.zip requires an object');
            }
            $options = $args[1] ?? JsUndefined::instance();
            if (!$options instanceof JsUndefined && !$options instanceof JsObject) {
                throw new TypeError('Iterator zip options must be an object');
            }
            $modeValue = $options instanceof JsObject ? $options->get('mode') : JsUndefined::instance();
            if ($modeValue instanceof JsUndefined) {
                $modeValue = new JsString('shortest');
            }
            if (!$modeValue instanceof JsString || !in_array($modeValue->value, ['shortest', 'longest', 'strict'], true)) {
                throw new TypeError('Invalid iterator zip mode');
            }
            $mode = $modeValue->value;
            $paddingOption = $mode === 'longest' && $options instanceof JsObject
                ? $options->get('padding')
                : JsUndefined::instance();
            if (!$paddingOption instanceof JsUndefined && !$paddingOption instanceof JsObject) {
                throw new TypeError('Iterator zip padding must be an object');
            }

            $iters = [];
            $keys = [];
            if ($keyed) {
                foreach ($iterables->ordinaryOwnPropertyKeys() as $key) {
                    $key = $key instanceof JsSymbol ? $key : TypeConversion::toString($key);
                    try {
                        $desc = $key instanceof JsSymbol
                            ? $iterables->getSymbolPropertyDescriptor($key)
                            : $iterables->getOwnPropertyDescriptor($key);
                        if ($desc === null || !$desc->enumerable) {
                            continue;
                        }
                        $value = $key instanceof JsSymbol
                            ? $iterables->getBySymbol($key)
                            : $iterables->get($key);
                        if (!$value instanceof JsUndefined) {
                            $keys[] = $key;
                            $iters[] = self::jointIterator($value);
                        }
                    } catch (\Throwable $e) {
                        self::closeIteratorRecordsAbrupt($iters, $e);
                    }
                }
            } else {
                $input = self::jointIterator($iterables, true);
                while (true) {
                    try {
                        [$value, $finished] = self::jointStep($input);
                    } catch (\Throwable $e) {
                        self::closeIteratorRecordsAbrupt($iters, $e);
                    }
                    if ($finished) {
                        break;
                    }
                    try {
                        $iters[] = self::jointIterator($value);
                    } catch (\Throwable $e) {
                        self::closeIteratorRecordsAbrupt([$input, ...$iters], $e);
                    }
                }
            }

            $padding = [];
            if ($mode === 'longest') {
                if ($paddingOption instanceof JsUndefined) {
                    $padding = array_fill(0, count($iters), JsUndefined::instance());
                } elseif ($keyed) {
                    try {
                        foreach ($keys as $key) {
                            $padding[] = $key instanceof JsSymbol
                                ? $paddingOption->getBySymbol($key)
                                : $paddingOption->get($key);
                        }
                    } catch (\Throwable $e) {
                        self::closeIteratorRecordsAbrupt($iters, $e);
                    }
                } else {
                    try {
                        $paddingIter = self::jointIterator($paddingOption, true);
                        $finished = false;
                        foreach ($iters as $_) {
                            if ($finished) {
                                $padding[] = JsUndefined::instance();
                                continue;
                            }
                            [$value, $finished] = self::jointStep($paddingIter);
                            $padding[] = $value;
                        }
                        if (!$finished) {
                            self::closeIterator($paddingIter[0]);
                        }
                    } catch (\Throwable $e) {
                        self::closeIteratorRecordsAbrupt($iters, $e);
                    }
                }
            }
            return self::createZipIterator($iters, $mode, $padding, $keyed ? $keys : null);
        };
    }

    /** @return array{JsObject, JsValue} */
    private static function jointIterator(JsValue $value, bool $iterableOnly = false): array
    {
        if (!$value instanceof JsObject) {
            throw new TypeError('Iterator input must be an object');
        }
        $method = $value->getBySymbol(SymbolConstructor::iterator());
        if ($method instanceof JsUndefined || $method instanceof JsNull) {
            if ($iterableOnly) {
                throw new TypeError('Iterator input is not iterable');
            }
            $iterator = $value;
        } else {
            if (!$method instanceof JsFunction) {
                throw new TypeError('Symbol.iterator is not a function');
            }
            $iterator = $method->call($value, []);
            if (!$iterator instanceof JsObject) {
                throw new TypeError('Iterator method result is not an object');
            }
        }
        return self::getIteratorDirect($iterator);
    }

    /**
     * @param array{JsObject, JsValue} $record
     * @return array{JsValue, bool}
     */
    private static function jointStep(array $record, bool $readValue = true): array
    {
        [$iterator, $next] = $record;
        if (!$next instanceof JsFunction) {
            throw new TypeError('Iterator next is not a function');
        }
        $result = $next->call($iterator, []);
        if (!$result instanceof JsObject) {
            throw new TypeError('Iterator result is not an object');
        }
        $done = TypeConversion::toBoolean($result->get('done'));
        return [$done || !$readValue ? JsUndefined::instance() : $result->get('value'), $done];
    }

    /**
     * @param array<int, array{JsObject, JsValue}> $records
     */
    private static function closeIteratorRecords(array $records): void
    {
        $error = null;
        foreach (array_reverse($records) as [$iterator]) {
            try {
                self::closeIterator($iterator);
            } catch (\Throwable $e) {
                $error ??= $e;
            }
        }
        if ($error !== null) {
            throw $error;
        }
    }

    /** @param array<int, array{JsObject, JsValue}> $records */
    private static function closeIteratorRecordsAbrupt(array $records, \Throwable $error): never
    {
        foreach (array_reverse($records) as [$iterator]) {
            self::closeIterator($iterator, true);
        }
        throw $error;
    }

    /**
     * @param list<array{JsObject, JsValue}> $iters
     * @param list<JsValue> $padding
     * @param ?list<string|JsSymbol> $keys
     */
    private static function createZipIterator(array $iters, string $mode, array $padding, ?array $keys): JsObject
    {
        $open = $iters;
        return self::createIteratorHelper(
            null,
            JsUndefined::instance(),
            function (bool &$done, bool &$alive) use ($iters, $mode, $padding, $keys, &$open): JsObject {
                if ($open === []) {
                    $done = true;
                    $alive = false;
                    return self::iterResult(JsUndefined::instance(), true);
                }
                $results = [];
                foreach ($iters as $index => $record) {
                    if (!isset($open[$index])) {
                        $results[] = $padding[$index];
                        continue;
                    }
                    try {
                        [$value, $finished] = self::jointStep($record);
                    } catch (\Throwable $e) {
                        unset($open[$index]);
                        self::closeIteratorRecordsAbrupt($open, $e);
                    }
                    if ($finished) {
                        unset($open[$index]);
                        if ($mode === 'shortest') {
                            self::closeIteratorRecords($open);
                            $done = true;
                            $alive = false;
                            return self::iterResult(JsUndefined::instance(), true);
                        }
                        if ($mode === 'strict') {
                            if ($index !== 0) {
                                self::closeIteratorRecordsAbrupt($open, new TypeError('Iterator lengths differ'));
                            }
                            for ($remaining = 1; $remaining < count($iters); $remaining++) {
                                try {
                                    [, $finished] = self::jointStep($iters[$remaining], false);
                                } catch (\Throwable $e) {
                                    unset($open[$remaining]);
                                    self::closeIteratorRecordsAbrupt($open, $e);
                                }
                                if (!$finished) {
                                    self::closeIteratorRecordsAbrupt($open, new TypeError('Iterator lengths differ'));
                                }
                                unset($open[$remaining]);
                            }
                            $done = true;
                            $alive = false;
                            return self::iterResult(JsUndefined::instance(), true);
                        }
                        if ($open === []) {
                            $done = true;
                            $alive = false;
                            return self::iterResult(JsUndefined::instance(), true);
                        }
                        $value = $padding[$index];
                    }
                    $results[] = $value;
                }
                if ($keys === null) {
                    $value = new JsArray($results);
                } else {
                    $value = JsObject::createNullPrototype();
                    foreach ($keys as $index => $key) {
                        $desc = PropertyDescriptor::data($results[$index], true, true, true);
                        if ($key instanceof JsSymbol) {
                            $value->definePropertyBySymbol($key, $desc);
                        } else {
                            $value->defineOwnProperty($key, $desc);
                        }
                    }
                }
                return self::iterResult($value, false);
            },
            function () use (&$open): void {
                $closing = $open;
                $open = [];
                self::closeIteratorRecords($closing);
            },
        );
    }
}
