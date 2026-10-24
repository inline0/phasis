<?php

declare(strict_types=1);

namespace Phasis\Node;

use Phasis\Exceptions\TypeError;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/** Pure path-segment operations used by the pinned Node path implementation. */
final class PathBindings
{
    /** Create the native normalizer without consulting the host filesystem. */
    public static function create(): JsObject
    {
        $host = new JsObject();
        $host->set('normalizeString', JsFunction::fromCallable(
            'normalizeString',
            static function (JsValue $this_, array $args): JsValue {
                $path = $args[0] ?? JsUndefined::instance();
                $separator = $args[2] ?? JsUndefined::instance();
                if (!$path instanceof JsString || !$separator instanceof JsString) {
                    throw new TypeError('Path normalization requires string arguments.');
                }
                if ($separator->value !== '/' && $separator->value !== '\\') {
                    throw new TypeError('Invalid path separator.');
                }
                $allowAboveRoot = ($args[1] ?? JsUndefined::instance())->toBoolean();
                // Separators and dot segments are ASCII. Splitting their bytes
                // preserves every other UTF-16 value, including lone surrogates.
                $input = $separator->value === '\\' ? str_replace('\\', '/', $path->value) : $path->value;
                $segments = [];
                foreach (explode('/', $input) as $segment) {
                    if ($segment === '' || $segment === '.') {
                        continue;
                    }
                    if ($segment === '..') {
                        if ($segments !== [] && $segments[count($segments) - 1] !== '..') {
                            array_pop($segments);
                        } elseif ($allowAboveRoot) {
                            $segments[] = '..';
                        }
                    } else {
                        $segments[] = $segment;
                    }
                }
                return new JsString(implode($separator->value, $segments));
            },
            3,
        ));
        return $host;
    }
}
