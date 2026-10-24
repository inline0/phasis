<?php

declare(strict_types=1);

namespace Phasis\Node;

use Phasis\Engine;
use Phasis\Exceptions\TypeError;
use Phasis\Interop\PhpToJs;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsDataView;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/** Cryptographic operations implemented by PHP's native cryptography providers. */
final class CryptoBindings
{
    /** Create the native operations used by this realm's crypto module. */
    public static function create(Engine $engine, NodeRuntime $runtime): JsObject
    {
        $host = new JsObject();
        self::method($host, 'hashes', static fn (array $args): JsValue => PhpToJs::convert(self::hashes()));
        self::method($host, 'hashLength', static fn (array $args): JsValue => JsNumber::of(strlen(hash(self::algorithm($args[0]), '', true))));
        self::method($host, 'createHash', static function (array $args): JsValue {
            $algorithm = self::algorithm($args[0]);
            $key = $args[1] ?? JsUndefined::instance();
            $context = $key instanceof JsUndefined
                ? hash_init($algorithm)
                : hash_init($algorithm, HASH_HMAC, self::bytes($key) === '' ? "\0" : self::bytes($key));
            return self::token($context);
        });
        self::method($host, 'copyHash', static fn (array $args): JsValue => self::token(hash_copy(self::context($args[0]))));
        self::method($host, 'updateHash', static function (array $args): JsValue {
            hash_update(self::context($args[0]), self::bytes($args[1]));
            return JsUndefined::instance();
        });
        self::method($host, 'digestHash', static function (array $args) use ($engine): JsValue {
            $token = $args[0];
            $result = hash_final(self::context($token), true);
            if ($token instanceof JsObject) {
                $token->setInternalProperty('[[NodeHashContext]]', null);
            }
            return self::byteArray($engine, $result);
        });
        self::method($host, 'randomBytes', static function (array $args) use ($engine): JsValue {
            $length = (int) $args[0]->toNumber();
            return self::byteArray($engine, $length === 0 ? '' : random_bytes($length));
        });
        self::method($host, 'randomInt', static fn (array $args): JsValue => JsNumber::of(random_int(
            (int) $args[0]->toNumber(),
            (int) $args[1]->toNumber() - 1,
        )));
        self::method($host, 'timingSafeEqual', static fn (array $args): JsValue => new JsBoolean(hash_equals(
            self::bytes($args[0]),
            self::bytes($args[1]),
        )));
        self::method($host, 'pbkdf2', static function (array $args) use ($engine): JsValue {
            $length = (int) $args[3]->toNumber();
            $result = $length === 0 ? '' : hash_pbkdf2(
                self::algorithm($args[4]),
                self::bytes($args[0]),
                self::bytes($args[1]),
                (int) $args[2]->toNumber(),
                $length,
                true,
            );
            return self::byteArray($engine, $result);
        });
        self::method($host, 'hkdf', static function (array $args) use ($engine): JsValue {
            $length = (int) $args[4]->toNumber();
            $algorithm = self::algorithm($args[0]);
            $key = hash_hmac($algorithm, self::bytes($args[1]), self::bytes($args[2]), true);
            $info = self::bytes($args[3]);
            $previous = '';
            $result = '';
            for ($block = 1; strlen($result) < $length; $block++) {
                $previous = hash_hmac($algorithm, $previous . $info . chr($block), $key, true);
                $result .= $previous;
            }
            $result = substr($result, 0, $length);
            return self::byteArray($engine, $result);
        });
        return $host;
    }

    /** @param \Closure(list<JsValue>): JsValue $callback */
    private static function method(JsObject $target, string $name, \Closure $callback): void
    {
        $target->set($name, JsFunction::fromCallable(
            $name,
            static fn (JsValue $this_, array $args): JsValue => $callback($args),
        ));
    }

    private static function algorithm(JsValue $value): string
    {
        $name = strtolower($value->toJsString());
        $name = match ($name) {
            'sha-1', 'rsa-sha1' => 'sha1',
            'sha-224', 'rsa-sha224' => 'sha224',
            'sha-256', 'rsa-sha256' => 'sha256',
            'sha-384', 'rsa-sha384' => 'sha384',
            'sha-512', 'rsa-sha512' => 'sha512',
            default => $name,
        };
        if (!in_array($name, self::hashes(), true)) {
            throw new TypeError('Digest method not supported: ' . $name);
        }
        return $name;
    }

    /** @return list<string> */
    private static function hashes(): array
    {
        $openssl = function_exists('openssl_get_md_methods')
            ? openssl_get_md_methods()
            : ['md4', 'md5', 'ripemd160', 'sha1', 'sha224', 'sha256', 'sha384', 'sha512', 'sha3-224', 'sha3-256', 'sha3-384', 'sha3-512'];
        return array_values(array_intersect(hash_algos(), $openssl));
    }

    private static function token(\HashContext $context): JsObject
    {
        $token = new JsObject();
        $token->setInternalProperty('[[NodeHashContext]]', $context);
        return $token;
    }

    private static function context(JsValue $value): \HashContext
    {
        $context = $value instanceof JsObject ? $value->getInternalProperty('[[NodeHashContext]]') : null;
        if (!$context instanceof \HashContext) {
            throw new TypeError('Digest already called.');
        }
        return $context;
    }

    private static function bytes(JsValue $value): string
    {
        if ($value instanceof JsTypedArray) {
            $value->validateNotDetached();
            return $value->getBuffer()->readBytes($value->getByteOffset(), $value->getLength() * $value->getBytesPerElement());
        }
        if ($value instanceof JsDataView) {
            $value->validateNotDetached();
            return $value->getBuffer()->readBytes($value->getByteOffset(), $value->getByteLength());
        }
        if ($value instanceof JsArrayBuffer && !$value->isDetached()) {
            return $value->getData();
        }
        throw new TypeError('Expected an ArrayBuffer or ArrayBufferView.');
    }

    private static function byteArray(Engine $engine, string $bytes): JsTypedArray
    {
        $constructor = $engine->getGlobalEnv()->get('Uint8Array');
        $prototype = $constructor instanceof JsObject ? $constructor->get('prototype') : null;
        $array = JsTypedArray::fromLength('Uint8Array', strlen($bytes), $prototype instanceof JsObject ? $prototype : null);
        $array->getBuffer()->setData($bytes);
        return $array;
    }
}
