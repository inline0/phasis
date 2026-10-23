<?php

declare(strict_types=1);

namespace Phasis\Node;

use Phasis\Ast\Node;
use Phasis\Engine;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/** V8's structured stack API, backed by the interpreter's actual call frames. */
final class ErrorStack
{
    public static function install(Engine $engine): void
    {
        $constructor = $engine->getGlobalEnv()->get('Error');
        if (!$constructor instanceof JsObject) {
            return;
        }
        $constructor->defineOwnProperty('stackTraceLimit', PropertyDescriptor::data(JsNumber::of(10), true, false, true));
        $capture = JsFunction::fromCallable('captureStackTrace', static function (JsValue $this_, array $args) use ($engine, $constructor): JsValue {
            $target = $args[0] ?? JsUndefined::instance();
            if (!$target instanceof JsObject) {
                throw new TypeError('Invalid argument');
            }
            $exclude = $args[1] ?? null;
            $frames = array_reverse($engine->getInterpreter()->getCallStack()->getFrames());
            if ($exclude instanceof JsFunction) {
                $index = null;
                foreach ($frames as $key => $frame) {
                    if ($frame->function === $exclude) {
                        $index = $key;
                        break;
                    }
                }
                $frames = $index === null ? [] : array_slice($frames, $index + 1);
            }
            $limit = $constructor->get('stackTraceLimit');
            $count = $limit instanceof JsNumber ? $limit->toNumber() : 0;
            $frames = array_slice($frames, 0, is_infinite($count) && $count > 0 ? count($frames) : max(0, (int) $count));
            $sites = [];
            foreach ($frames as $frame) {
                $function = $frame->function;
                $body = $function?->getBody();
                $file = $function->definingModulePath ?? Engine::getCurrentSourceURL();
                $line = $frame->line > 0 ? $frame->line : ($body instanceof Node ? $body->location->line : null);
                $column = $body instanceof Node ? $body->location->column + 1 : null;
                $name = $frame->name !== '' ? $frame->name : null;
                $site = new JsObject();
                $values = [
                    'getFileName' => $file === null ? JsNull::instance() : new JsString($file),
                    'getScriptNameOrSourceURL' => $file === null ? JsNull::instance() : new JsString($file),
                    'getLineNumber' => $line === null ? JsNull::instance() : JsNumber::of($line),
                    'getColumnNumber' => $column === null ? JsNull::instance() : JsNumber::of($column),
                    'getFunctionName' => $name === null ? JsNull::instance() : new JsString($name),
                    'getMethodName' => $name === null ? JsNull::instance() : new JsString($name),
                    'getFunction' => $function !== null && !$function->isStrict() ? $function : JsUndefined::instance(),
                    'getThis' => JsUndefined::instance(),
                    'getTypeName' => JsNull::instance(),
                    'getEvalOrigin' => JsUndefined::instance(),
                    'isToplevel' => new JsBoolean(false),
                    'isEval' => new JsBoolean($file === null),
                    'isNative' => new JsBoolean($function?->isNative() ?? false),
                    'isConstructor' => new JsBoolean($function?->isClassConstructor() ?? false),
                    'isAsync' => new JsBoolean($function?->isAsync() ?? false),
                    'isPromiseAll' => new JsBoolean(false),
                    'getPromiseIndex' => JsNull::instance(),
                ];
                foreach ($values as $method => $value) {
                    $site->set($method, JsFunction::fromCallable($method, static fn (): JsValue => $value));
                }
                $location = $file === null ? '<anonymous>' : $file . ($line === null ? '' : ':' . $line . ':' . $column);
                $formatted = $name === null ? $location : $name . ' (' . $location . ')';
                $site->set('toString', JsFunction::fromCallable('toString', static fn (): JsValue => new JsString($formatted)));
                $sites[] = $site;
            }
            $cache = null;
            $getter = JsFunction::fromCallable('get stack', static function () use ($target, $sites, $constructor, $engine, &$cache): JsValue {
                if ($cache !== null) {
                    return $cache;
                }
                $prepare = $constructor->get('prepareStackTrace');
                if ($prepare instanceof JsFunction) {
                    return $cache = $engine->getInterpreter()->callFunction($prepare, $constructor, [$target, JsArray::fromArray($sites)]);
                }
                $name = $target->get('name');
                $message = $target->get('message');
                $header = $name instanceof JsUndefined ? 'Error' : $name->toJsString();
                if (!$message instanceof JsUndefined && $message->toJsString() !== '') {
                    $header .= ': ' . $message->toJsString();
                }
                foreach ($sites as $site) {
                    $stringify = $site->get('toString');
                    if ($stringify instanceof JsFunction) {
                        $header .= "\n    at " . $stringify->call($site, [])->toJsString();
                    }
                }
                return $cache = new JsString($header);
            });
            $setter = JsFunction::fromCallable('set stack', static function (JsValue $this_, array $args) use (&$cache): JsValue {
                $cache = $args[0] ?? JsUndefined::instance();
                return JsUndefined::instance();
            });
            if (!$target->defineOwnProperty('stack', PropertyDescriptor::accessor($getter, $setter, false, true))) {
                throw new TypeError('Cannot define stack on target object');
            }
            return JsUndefined::instance();
        }, 2);
        $constructor->defineOwnProperty('captureStackTrace', PropertyDescriptor::data($capture, true, false, true));
    }
}
