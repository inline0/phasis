<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WHATWG xhr §4 FormData.
 *
 *  https://xhr.spec.whatwg.org/#interface-formdata
 *
 * Internal layout:
 *   - [[FormDataEntries]]   list<array{0:string,1:JsValue}>  insertion order
 *   - [[IsFormData]]        true  (brand check marker)
 *
 * Each entry value is either a JsString or a Blob/File (per spec
 * §4.2 "create an entry"). When a Blob is appended with a `filename`,
 * we materialize a File around the same bytes so subsequent reads see
 * the filename as the spec requires.
 *
 * `new FormData(form)` accepts an HTML <form> element; since Phasis has
 * no DOM the form parameter is currently no-op (the entries list starts
 * empty). The shape is preserved so libraries that pass a form for
 * progressive enhancement don't crash.
 */
class FormDataConstructor
{
    private static ?JsObject $iteratorPrototype = null;
    private static ?JsObject $intrinsicIteratorPrototype = null;

    /**
     * Brand check for FormData. True iff $v carries the [[IsFormData]]
     * internal slot set during construction.
     */
    public static function isFormData(JsValue $v): bool
    {
        return $v instanceof JsObject && $v->getInternalProperty('[[IsFormData]]') === true;
    }

    /**
     * Read the underlying entries list as `[name, value]` pairs. Value is
     * a JsString or a Blob/File. Used by the Body mixin to serialize a
     * FormData body into multipart/form-data on Request construction.
     *
     * @return list<array{0:string,1:JsValue}>
     */
    public static function getEntries(JsObject $self): array
    {
        if (!self::isFormData($self)) {
            return [];
        }
        $list = $self->getInternalProperty('[[FormDataEntries]]');
        if (!is_array($list)) {
            return [];
        }
        /** @var list<array{0:string,1:JsValue}> $list */
        return $list;
    }

    public static function install(Environment $env): void
    {
        self::$iteratorPrototype = null;

        // Capture %IteratorPrototype% so FormData iterators chain into it
        // (mirrors URLSearchParams setup so Array.from(fd.keys()) works).
        $iterProto = $env->has('__IteratorPrototype__') ? $env->get('__IteratorPrototype__') : null;
        self::$intrinsicIteratorPrototype = $iterProto instanceof JsObject ? $iterProto : null;

        $proto = self::buildPrototype();
        $ctor = self::buildConstructor($proto);

        $env->defineVar('FormData', $ctor);
    }

    private static function buildConstructor(JsObject $proto): JsFunction
    {
        $ctor = JsFunction::fromCallable(
            'FormData',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError(
                        "Failed to construct 'FormData': Please use the 'new' operator"
                    );
                }
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $this_->setPrototype($ntProto instanceof JsObject ? $ntProto : $proto);
                }

                // Per WebIDL the optional argument is `HTMLFormElement?`.
                // We don't ship a DOM, so any non-undefined value (including
                // null) fails the type check and surfaces as a TypeError —
                // matching browser behavior. WPT verifies this with
                // `new FormData(null)` and `new FormData("string")`.
                $form = $args[0] ?? JsUndefined::instance();
                if (!$form instanceof JsUndefined) {
                    throw new TypeError(
                        "Failed to construct 'FormData': parameter 1 is not of "
                        . "type 'HTMLFormElement'."
                    );
                }

                $this_->setInternalProperty('[[IsFormData]]', true);
                $this_->setInternalProperty('[[FormDataEntries]]', []);
                return $this_;
            },
            0,
        );
        $ctor->setConstructable();
        $ctor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($proto, false, false, false),
        );
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($ctor, true, false, true),
        );
        return $ctor;
    }

    private static function buildPrototype(): JsObject
    {
        $proto = new JsObject();

        // append(name, value, filename?)
        $proto->defineOwnProperty('append', PropertyDescriptor::data(
            JsFunction::fromCallable('append', function (JsValue $this_, array $args): JsValue {
                self::assert($this_);
                if (count($args) < 2) {
                    throw new TypeError(
                        "Failed to execute 'append' on 'FormData': 2 arguments required."
                    );
                }
                /** @var JsObject $this_ */
                $name = TypeConversion::toString($args[0]);
                $value = self::convertValue($args[1], $args[2] ?? JsUndefined::instance());
                $list = self::entriesOf($this_);
                $list[] = [$name, $value];
                self::setEntries($this_, $list);
                return JsUndefined::instance();
            }, 2),
            true,
            false,
            true,
        ));

        // delete(name)
        $proto->defineOwnProperty('delete', PropertyDescriptor::data(
            JsFunction::fromCallable('delete', function (JsValue $this_, array $args): JsValue {
                self::assert($this_);
                if (count($args) < 1) {
                    throw new TypeError(
                        "Failed to execute 'delete' on 'FormData': 1 argument required."
                    );
                }
                /** @var JsObject $this_ */
                $name = TypeConversion::toString($args[0]);
                $filtered = [];
                foreach (self::entriesOf($this_) as $entry) {
                    if ($entry[0] !== $name) {
                        $filtered[] = $entry;
                    }
                }
                self::setEntries($this_, $filtered);
                return JsUndefined::instance();
            }, 1),
            true,
            false,
            true,
        ));

        // get(name) -> first value or null
        $proto->defineOwnProperty('get', PropertyDescriptor::data(
            JsFunction::fromCallable('get', function (JsValue $this_, array $args): JsValue {
                self::assert($this_);
                if (count($args) < 1) {
                    throw new TypeError(
                        "Failed to execute 'get' on 'FormData': 1 argument required."
                    );
                }
                /** @var JsObject $this_ */
                $name = TypeConversion::toString($args[0]);
                foreach (self::entriesOf($this_) as $entry) {
                    if ($entry[0] === $name) {
                        return $entry[1];
                    }
                }
                return JsNull::instance();
            }, 1),
            true,
            false,
            true,
        ));

        // getAll(name) -> JsArray of values
        $proto->defineOwnProperty('getAll', PropertyDescriptor::data(
            JsFunction::fromCallable('getAll', function (JsValue $this_, array $args): JsValue {
                self::assert($this_);
                if (count($args) < 1) {
                    throw new TypeError(
                        "Failed to execute 'getAll' on 'FormData': 1 argument required."
                    );
                }
                /** @var JsObject $this_ */
                $name = TypeConversion::toString($args[0]);
                $matches = [];
                foreach (self::entriesOf($this_) as $entry) {
                    if ($entry[0] === $name) {
                        $matches[] = $entry[1];
                    }
                }
                return JsArray::fromArray($matches);
            }, 1),
            true,
            false,
            true,
        ));

        // has(name) -> boolean
        $proto->defineOwnProperty('has', PropertyDescriptor::data(
            JsFunction::fromCallable('has', function (JsValue $this_, array $args): JsValue {
                self::assert($this_);
                if (count($args) < 1) {
                    throw new TypeError(
                        "Failed to execute 'has' on 'FormData': 1 argument required."
                    );
                }
                /** @var JsObject $this_ */
                $name = TypeConversion::toString($args[0]);
                foreach (self::entriesOf($this_) as $entry) {
                    if ($entry[0] === $name) {
                        return JsBoolean::of(true);
                    }
                }
                return JsBoolean::of(false);
            }, 1),
            true,
            false,
            true,
        ));

        // set(name, value, filename?) — replaces all existing entries with the
        // given name; the new entry is placed at the position of the first
        // existing match (or appended).
        $proto->defineOwnProperty('set', PropertyDescriptor::data(
            JsFunction::fromCallable('set', function (JsValue $this_, array $args): JsValue {
                self::assert($this_);
                if (count($args) < 2) {
                    throw new TypeError(
                        "Failed to execute 'set' on 'FormData': 2 arguments required."
                    );
                }
                /** @var JsObject $this_ */
                $name = TypeConversion::toString($args[0]);
                $value = self::convertValue($args[1], $args[2] ?? JsUndefined::instance());
                $list = self::entriesOf($this_);
                $seen = false;
                $newList = [];
                foreach ($list as $entry) {
                    if ($entry[0] === $name) {
                        if (!$seen) {
                            $newList[] = [$name, $value];
                            $seen = true;
                        }
                    } else {
                        $newList[] = $entry;
                    }
                }
                if (!$seen) {
                    $newList[] = [$name, $value];
                }
                self::setEntries($this_, $newList);
                return JsUndefined::instance();
            }, 2),
            true,
            false,
            true,
        ));

        // forEach(callback, thisArg?) — iterate snapshot-then-live like USP.
        $proto->defineOwnProperty('forEach', PropertyDescriptor::data(
            JsFunction::fromCallable('forEach', function (JsValue $this_, array $args): JsValue {
                self::assert($this_);
                $cb = $args[0] ?? JsUndefined::instance();
                if (!$cb instanceof JsFunction) {
                    throw new TypeError(
                        "Failed to execute 'forEach' on 'FormData': callback must be a function"
                    );
                }
                $thisArg = $args[1] ?? JsUndefined::instance();
                /** @var JsObject $this_ */
                $i = 0;
                while (true) {
                    $list = self::entriesOf($this_);
                    if ($i >= count($list)) {
                        break;
                    }
                    $entry = $list[$i];
                    $cb->call($thisArg, [
                        $entry[1],
                        new JsString($entry[0]),
                        $this_,
                    ]);
                    $i++;
                }
                return JsUndefined::instance();
            }, 1),
            true,
            false,
            true,
        ));

        $entriesFn = JsFunction::fromCallable(
            'entries',
            function (JsValue $this_, array $args): JsValue {
                self::assert($this_);
                return self::createIterator($this_, 'entries');
            },
            0,
        );
        $proto->defineOwnProperty(
            'entries',
            PropertyDescriptor::data($entriesFn, true, false, true),
        );

        $proto->defineOwnProperty(
            'keys',
            PropertyDescriptor::data(
                JsFunction::fromCallable(
                    'keys',
                    function (JsValue $this_, array $args): JsValue {
                        self::assert($this_);
                        return self::createIterator($this_, 'keys');
                    },
                    0,
                ),
                true,
                false,
                true,
            ),
        );

        $proto->defineOwnProperty(
            'values',
            PropertyDescriptor::data(
                JsFunction::fromCallable(
                    'values',
                    function (JsValue $this_, array $args): JsValue {
                        self::assert($this_);
                        return self::createIterator($this_, 'values');
                    },
                    0,
                ),
                true,
                false,
                true,
            ),
        );

        // @@iterator -> entries
        $proto->definePropertyBySymbol(
            SymbolConstructor::iterator(),
            PropertyDescriptor::data($entriesFn, true, false, true),
        );

        // @@toStringTag
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('FormData'), false, false, true),
        );

        return $proto;
    }

    /**
     * Per spec "create an entry":
     *  - String values become JsString.
     *  - Blob values become a Blob; if a filename is supplied we materialize
     *    a File around the same bytes so the spec's filename semantics
     *    survive. When the Blob is already a File and no filename is
     *    given, keep it as-is (spec preserves the existing name).
     *  - All other types are stringified.
     */
    private static function convertValue(JsValue $value, JsValue $filename): JsValue
    {
        $hasFilename = !$filename instanceof JsUndefined;
        $filenameStr = $hasFilename ? TypeConversion::toString($filename) : null;

        if (BlobConstructor::isBlob($value)) {
            /** @var JsObject $value */
            $bytes = BlobConstructor::getBytes($value);
            $type = BlobConstructor::getType($value);

            // If value is already a File and no filename override, keep as-is.
            if (BlobConstructor::isFile($value) && !$hasFilename) {
                return $value;
            }

            // Default filename per xhr spec: "blob" when none supplied and
            // the value is a Blob (not a File). The historical "/" → ":"
            // replacement was removed from the spec (matches the
            // File-constructor.any.js fixture).
            $name = $filenameStr ?? 'blob';

            // When the input was already a File, preserve its lastModified
            // — only the filename changes per the spec.
            $lastMod = null;
            if (BlobConstructor::isFile($value)) {
                $lm = $value->getInternalProperty('[[LastModified]]');
                if (is_int($lm)) {
                    $lastMod = $lm;
                }
            }

            return BlobConstructor::createFile($bytes, $name, $type, $lastMod);
        }

        // Non-Blob: stringify.
        return new JsString(TypeConversion::toString($value));
    }

    /**
     * @return list<array{0:string,1:JsValue}>
     */
    private static function entriesOf(JsObject $self): array
    {
        $list = $self->getInternalProperty('[[FormDataEntries]]');
        if (!is_array($list)) {
            return [];
        }
        /** @var list<array{0:string,1:JsValue}> $list */
        return $list;
    }

    /**
     * @param list<array{0:string,1:JsValue}> $list
     */
    private static function setEntries(JsObject $self, array $list): void
    {
        $self->setInternalProperty('[[FormDataEntries]]', $list);
    }

    private static function assert(JsValue $v): void
    {
        if (
            !$v instanceof JsObject
            || $v->getInternalProperty('[[IsFormData]]') !== true
        ) {
            throw new TypeError(
                'FormData method called on incompatible receiver (not FormData)'
            );
        }
    }

    // ===== Iterator =======================================================

    private static function getIteratorPrototype(): JsObject
    {
        if (self::$iteratorPrototype !== null) {
            return self::$iteratorPrototype;
        }
        $proto = new JsObject(self::$intrinsicIteratorPrototype);
        $nextFn = JsFunction::fromCallable(
            'next',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject) {
                    throw new TypeError('not a FormData iterator');
                }
                $stateDesc = $this_->getOwnPropertyDescriptor('[[FDIterState]]');
                if ($stateDesc === null) {
                    throw new TypeError('not a FormData iterator');
                }
                $state = $stateDesc->value;
                if (!$state instanceof JsObject) {
                    return self::iteratorResult(JsUndefined::instance(), true);
                }
                $target = $state->get('target');
                if (!$target instanceof JsObject) {
                    return self::iteratorResult(JsUndefined::instance(), true);
                }
                $indexVal = $state->get('index');
                $index = $indexVal instanceof JsNumber ? (int) $indexVal->value : 0;
                $kindVal = $state->get('kind');
                $kind = $kindVal instanceof JsString ? $kindVal->value : 'entries';

                $list = self::entriesOf($target);
                if ($index >= count($list)) {
                    return self::iteratorResult(JsUndefined::instance(), true);
                }
                $entry = $list[$index];
                $state->set('index', JsNumber::of((float) ($index + 1)));
                $value = match ($kind) {
                    'keys' => new JsString($entry[0]),
                    'values' => $entry[1],
                    default => JsArray::fromArray([new JsString($entry[0]), $entry[1]]),
                };
                return self::iteratorResult($value, false);
            },
            0,
        );
        $nextFn->setNonConstructable();
        $proto->defineOwnProperty(
            'next',
            PropertyDescriptor::data($nextFn, true, false, true),
        );
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('FormData Iterator'), false, false, true),
        );
        self::$iteratorPrototype = $proto;
        return $proto;
    }

    private static function createIterator(JsValue $self, string $kind): JsObject
    {
        $proto = self::getIteratorPrototype();
        $iter = new JsObject($proto);
        $state = new JsObject();
        $state->set('target', $self);
        $state->set('index', JsNumber::of(0.0));
        $state->set('kind', new JsString($kind));
        $iter->setInternalProperty('[[FDIterState]]', $state);
        // Mirror as a hidden non-enumerable descriptor so the shared next()
        // function can fetch it via getOwnPropertyDescriptor — same trick
        // URLSearchParams uses for its iterators.
        $iter->defineOwnProperty(
            '[[FDIterState]]',
            PropertyDescriptor::data($state, false, false, false),
        );
        return $iter;
    }

    private static function iteratorResult(JsValue $value, bool $done): JsObject
    {
        $r = new JsObject();
        $r->set('value', $value);
        $r->set('done', JsBoolean::of($done));
        return $r;
    }
}
