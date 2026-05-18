<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WHATWG WebIDL DOMException.
 *
 * https://webidl.spec.whatwg.org/#idl-DOMException
 *
 * Constructor signature: DOMException(message = "", name = "Error").
 * Instances expose:
 *   - `name`     : the supplied name (or "Error").
 *   - `message`  : the supplied message (or "").
 *   - `code`     : the legacy numeric code corresponding to `name`,
 *                  per the legacy error-code table
 *                  (https://webidl.spec.whatwg.org/#dom-domexception-code).
 *                  Names not in the table yield code 0.
 *
 * The prototype inherits from Error.prototype so that
 * `new DOMException(...) instanceof Error` evaluates to true.
 */
class DomExceptionConstructor
{
    /**
     * Legacy `name` -> numeric `code` mapping per WebIDL.
     *
     * https://webidl.spec.whatwg.org/#dom-domexception-code
     */
    public const LEGACY_CODES = [
        'IndexSizeError' => 1,
        'DOMStringSizeError' => 2,
        'HierarchyRequestError' => 3,
        'WrongDocumentError' => 4,
        'InvalidCharacterError' => 5,
        'NoDataAllowedError' => 6,
        'NoModificationAllowedError' => 7,
        'NotFoundError' => 8,
        'NotSupportedError' => 9,
        'InUseAttributeError' => 10,
        'InvalidStateError' => 11,
        'SyntaxError' => 12,
        'InvalidModificationError' => 13,
        'NamespaceError' => 14,
        'InvalidAccessError' => 15,
        'ValidationError' => 16,
        'TypeMismatchError' => 17,
        'SecurityError' => 18,
        'NetworkError' => 19,
        'AbortError' => 20,
        'URLMismatchError' => 21,
        'QuotaExceededError' => 22,
        'TimeoutError' => 23,
        'InvalidNodeTypeError' => 24,
        'DataCloneError' => 25,
    ];

    public static function install(Environment $env): void
    {
        // Locate Error.prototype to chain DOMException.prototype off of it.
        // Falls back to a plain object if Error hasn't been installed yet
        // (which shouldn't happen given install order, but defensive).
        $errorProto = null;
        $errorCtor = null;
        if ($env->has('Error')) {
            $errorVal = $env->get('Error');
            if ($errorVal instanceof JsFunction) {
                $errorCtor = $errorVal;
                $maybeProto = $errorVal->get('prototype');
                if ($maybeProto instanceof JsObject) {
                    $errorProto = $maybeProto;
                }
            }
        }

        $proto = new JsObject($errorProto);
        $proto->defineOwnProperty(
            'name',
            PropertyDescriptor::data(new JsString('Error'), true, false, true),
        );
        $proto->defineOwnProperty(
            'message',
            PropertyDescriptor::data(new JsString(''), true, false, true),
        );
        // Mirror the legacy numeric `code` getter on the prototype.
        // Per WebIDL, instances expose a default of 0 when name has no
        // legacy mapping; instances set their own `code` property below.
        $proto->defineOwnProperty(
            'code',
            PropertyDescriptor::data(JsNumber::of(0.0), true, false, true),
        );

        $constructor = JsFunction::fromCallable(
            'DOMException',
            self::makeConstructor($proto),
            2,
        );
        $constructor->setConstructable();

        // Wire prototype <-> constructor.
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($constructor, true, false, true),
        );
        $constructor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($proto, false, false, false),
        );

        // Inherit static side from Error so DOMException's [[Prototype]] is Error.
        if ($errorCtor !== null) {
            $constructor->setPrototype($errorCtor);
        }

        // Expose the legacy code constants as static properties of the
        // constructor *and* the prototype, per WebIDL legacy constants list.
        foreach (self::LEGACY_CODES as $name => $code) {
            $constName = self::legacyConstName($name);
            if ($constName === null) {
                continue;
            }
            $desc = PropertyDescriptor::data(
                JsNumber::of((float) $code),
                false,
                true,
                false,
            );
            $constructor->defineOwnProperty($constName, $desc);
            $proto->defineOwnProperty($constName, $desc);
        }

        // Symbol.toStringTag = "DOMException" — overrides Error's tag
        // so Object.prototype.toString.call(new DOMException()) emits
        // "[object DOMException]" per WebIDL.
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('DOMException'), false, false, true),
        );

        $env->defineVar('DOMException', $constructor);
    }

    /**
     * Map a legacy error name to its WebIDL constant identifier
     * (e.g. "IndexSizeError" -> "INDEX_SIZE_ERR").
     */
    private static function legacyConstName(string $name): ?string
    {
        // Per WebIDL the constants are named after the legacy DOM error
        // codes, not the DOMException name. The canonical list:
        static $map = [
            'IndexSizeError' => 'INDEX_SIZE_ERR',
            'DOMStringSizeError' => 'DOMSTRING_SIZE_ERR',
            'HierarchyRequestError' => 'HIERARCHY_REQUEST_ERR',
            'WrongDocumentError' => 'WRONG_DOCUMENT_ERR',
            'InvalidCharacterError' => 'INVALID_CHARACTER_ERR',
            'NoDataAllowedError' => 'NO_DATA_ALLOWED_ERR',
            'NoModificationAllowedError' => 'NO_MODIFICATION_ALLOWED_ERR',
            'NotFoundError' => 'NOT_FOUND_ERR',
            'NotSupportedError' => 'NOT_SUPPORTED_ERR',
            'InUseAttributeError' => 'INUSE_ATTRIBUTE_ERR',
            'InvalidStateError' => 'INVALID_STATE_ERR',
            'SyntaxError' => 'SYNTAX_ERR',
            'InvalidModificationError' => 'INVALID_MODIFICATION_ERR',
            'NamespaceError' => 'NAMESPACE_ERR',
            'InvalidAccessError' => 'INVALID_ACCESS_ERR',
            'ValidationError' => 'VALIDATION_ERR',
            'TypeMismatchError' => 'TYPE_MISMATCH_ERR',
            'SecurityError' => 'SECURITY_ERR',
            'NetworkError' => 'NETWORK_ERR',
            'AbortError' => 'ABORT_ERR',
            'URLMismatchError' => 'URL_MISMATCH_ERR',
            'QuotaExceededError' => 'QUOTA_EXCEEDED_ERR',
            'TimeoutError' => 'TIMEOUT_ERR',
            'InvalidNodeTypeError' => 'INVALID_NODE_TYPE_ERR',
            'DataCloneError' => 'DATA_CLONE_ERR',
        ];
        return $map[$name] ?? null;
    }

    /**
     * Construct a DOMException instance from PHP, so other builtins
     * (atob/btoa, structured-clone, etc.) can throw a real JS DOMException.
     */
    public static function create(Environment $env, string $message, string $name = 'Error'): JsObject
    {
        $proto = null;
        if ($env->has('DOMException')) {
            $ctor = $env->get('DOMException');
            if ($ctor instanceof JsFunction) {
                $maybeProto = $ctor->get('prototype');
                if ($maybeProto instanceof JsObject) {
                    $proto = $maybeProto;
                }
            }
        }
        $obj = new JsObject($proto);
        self::populate($obj, $message, $name);
        return $obj;
    }

    private static function populate(JsObject $obj, string $message, string $name): void
    {
        $obj->defineOwnProperty(
            '[[ErrorData]]',
            PropertyDescriptor::data(JsUndefined::instance(), false, false, false),
        );
        $obj->defineOwnProperty(
            'name',
            PropertyDescriptor::data(new JsString($name), true, false, true),
        );
        $obj->defineOwnProperty(
            'message',
            PropertyDescriptor::data(new JsString($message), true, false, true),
        );
        $code = self::LEGACY_CODES[$name] ?? 0;
        $obj->defineOwnProperty(
            'code',
            PropertyDescriptor::data(JsNumber::of((float) $code), false, false, false),
        );
    }

    private static function makeConstructor(JsObject $proto): \Closure
    {
        return function (JsValue $this_, array $args) use ($proto): JsValue {
            // Per WebIDL, both args default: message -> "", name -> "Error".
            $messageArg = $args[0] ?? JsUndefined::instance();
            $nameArg = $args[1] ?? JsUndefined::instance();

            $message = $messageArg instanceof JsUndefined
                ? ''
                : TypeConversion::toString($messageArg);
            $name = $nameArg instanceof JsUndefined
                ? 'Error'
                : TypeConversion::toString($nameArg);

            if ($this_ instanceof JsObject && $this_->has('[[NewTarget]]')) {
                // Called via `new`: populate the pre-allocated object.
                // The prototype was already set by evalNewExpression based on
                // newTarget.prototype, so subclasses keep their chain.
                $obj = $this_;
            } else {
                // Called as a function (legal per WebIDL).
                $obj = new JsObject($proto);
            }
            self::populate($obj, $message, $name);
            return $obj;
        };
    }
}
