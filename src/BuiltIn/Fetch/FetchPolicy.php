<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Fetch;

use Phasis\BuiltIn\HeadersConstructor;
use Phasis\BuiltIn\RequestConstructor;
use Phasis\Engine;
use Phasis\Exceptions\JsThrowable;
use Phasis\Exceptions\TypeError;
use Phasis\Runtime\Environment;
use Phasis\Value\JsArray;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

final class FetchPolicy
{
    public static function active(): bool
    {
        return Engine::getCurrentRealm()?->getFetchPolicy() !== null;
    }

    public static function apply(JsObject $request): JsObject
    {
        $policy = Engine::getCurrentRealm()?->getFetchPolicy();
        if ($policy === null) {
            return $request;
        }
        $result = $policy($request);
        if ($result === null) {
            return $request;
        }
        if (!$result instanceof JsObject || !RequestConstructor::isRequest($result)) {
            throw new TypeError('Fetch policy hook must return a Request, null, or undefined');
        }
        return $result;
    }

    /**
     * @param list<array{0:string,1:string}> $headers
     */
    public static function request(
        Environment $env,
        string $url,
        string $method,
        array $headers,
        string $body,
        string $redirect,
    ): JsObject {
        $ctor = $env->getIntrinsic('Request');
        if (!$ctor instanceof JsFunction) {
            Engine::getCurrentRealm()?->getLazyBuiltinRegistry()->realize('Request');
            $ctor = $env->getIntrinsic('Request');
        }
        if (!$ctor instanceof JsFunction) {
            throw new TypeError('Request intrinsic is not installed');
        }
        $init = new JsObject();
        $init->set('method', new JsString($method));
        $init->set('redirect', new JsString($redirect));
        $request = $ctor->construct([new JsString($url), $init]);
        if (!$request instanceof JsObject || !RequestConstructor::isRequest($request)) {
            throw new TypeError('Request constructor did not return a Request');
        }
        $list = $request->getInternalProperty('[[Headers]]');
        if ($list instanceof JsObject) {
            HeadersConstructor::setGuard($list, 'none');
            foreach ($headers as [$name, $value]) {
                HeadersConstructor::appendFromPhp($list, $name, $value);
            }
        }
        BodyMixin::markAsBodyHost($request, $body === '' ? null : $body, null);
        return $request;
    }

    public static function redirectHook(Environment $env): JsFunction
    {
        return JsFunction::fromCallable('', static function (JsValue $this_, array $args) use ($env): JsValue {
            $headers = [];
            $source = $args[2] ?? null;
            $pairs = $source instanceof JsObject ? $source->getInternalProperty(HeadersConstructor::SLOT_LIST) : null;
            if (is_array($pairs)) {
                foreach ($pairs as $pair) {
                    if (is_array($pair) && isset($pair[0], $pair[1]) && is_string($pair[0]) && is_string($pair[1])) {
                        $headers[] = [$pair[0], $pair[1]];
                    }
                }
            }
            $replay = $args[3] ?? null;
            $body = '';
            if ($replay instanceof JsTypedArray) {
                $body = substr($replay->getBuffer()->getData(), $replay->getByteOffset(), $replay->getLength());
            }
            try {
                $hop = self::describe(self::apply(self::request(
                    $env,
                    ($args[0] ?? JsUndefined::instance())->toJsString(),
                    ($args[1] ?? JsUndefined::instance())->toJsString(),
                    $headers,
                    $body,
                    'follow',
                )));
            } catch (JsThrowable $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new TypeError($e->getMessage());
            }
            $list = [];
            foreach ($hop['headers'] as [$name, $value]) {
                $list[] = JsArray::fromArray([new JsString($name), new JsString($value)]);
            }
            $result = new JsObject();
            $result->set('url', new JsString($hop['url']));
            $result->set('method', new JsString($hop['method']));
            $result->set('headers', JsArray::fromArray($list));
            $bytes = JsNull::instance();
            if ($hop['body'] !== '') {
                $buffer = new JsArrayBuffer(strlen($hop['body']));
                $buffer->setData($hop['body']);
                $bytes = new JsTypedArray('Uint8Array', $buffer, 0, strlen($hop['body']));
            }
            $result->set('body', $bytes);
            return $result;
        });
    }

    /**
     * @return array{url:string,method:string,headers:list<array{0:string,1:string}>,body:string}
     */
    public static function describe(JsObject $request): array
    {
        $url = $request->getInternalProperty('[[Url]]');
        $method = $request->getInternalProperty('[[Method]]');
        $headers = [];
        $list = $request->getInternalProperty('[[Headers]]');
        $pairs = $list instanceof JsObject ? $list->getInternalProperty(HeadersConstructor::SLOT_LIST) : null;
        if (is_array($pairs)) {
            foreach ($pairs as $pair) {
                if (is_array($pair) && count($pair) >= 2) {
                    $headers[] = [(string) $pair[0], (string) $pair[1]];
                }
            }
        }
        return [
            'url' => is_string($url) ? $url : '',
            'method' => is_string($method) ? $method : 'GET',
            'headers' => $headers,
            'body' => BodyMixin::bodyBytes($request) ?? '',
        ];
    }
}
