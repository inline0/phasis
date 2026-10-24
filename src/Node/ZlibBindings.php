<?php

declare(strict_types=1);

namespace Phasis\Node;

use Phasis\Engine;
use Phasis\Interop\PhpToJs;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/** Incremental zlib codecs with native state scoped to one JavaScript realm. */
final class ZlibBindings
{
    /** @var array<int, array{mode: string, options: array<string, int|string>, context: \InflateContext|\DeflateContext|null, pending: string, consumed: int, ended: bool}> */
    private array $contexts = [];

    private int $nextId = 1;

    private function __construct(private readonly Engine $engine)
    {
    }

    /** Create the host codec bridge and register cleanup for live contexts. */
    public static function create(Engine $engine, NodeRuntime $runtime): JsObject
    {
        $bindings = new self($engine);
        $runtime->onClose(static function () use ($bindings): void {
            $bindings->contexts = [];
        });
        $host = new JsObject();
        $host->set('call', JsFunction::fromCallable('call', static function (JsValue $this_, array $args) use ($bindings): JsValue {
            $operation = array_shift($args)?->toJsString() ?? '';
            $result = new JsObject();
            try {
                $result->set('value', $bindings->dispatch($operation, $args));
            } catch (\RuntimeException $error) {
                $result->set('error', PhpToJs::convert([
                    'message' => $error->getMessage(),
                    'errno' => $error->getCode(),
                    'code' => match ($error->getCode()) {
                        2 => 'Z_NEED_DICT', -2 => 'Z_STREAM_ERROR', -4 => 'Z_MEM_ERROR',
                        -5 => 'Z_BUF_ERROR', default => 'Z_DATA_ERROR',
                    },
                ]));
            }
            return $result;
        }));
        return $host;
    }

    /** @param list<JsValue> $args */
    private function dispatch(string $operation, array $args): JsValue
    {
        if ($operation === 'create') {
            $mode = $args[0]->toJsString();
            $config = $args[1];
            $options = [];
            if ($config instanceof JsObject) {
                foreach (['level', 'windowBits' => 'window', 'memLevel' => 'memory', 'strategy'] as $key => $name) {
                    $value = $config->get(is_string($key) ? $key : $name);
                    if (!$value instanceof JsUndefined) {
                        $options[$name] = (int) $value->toNumber();
                    }
                }
                $dictionary = $config->get('dictionary');
                if ($dictionary instanceof JsTypedArray) {
                    $options['dictionary'] = self::readBytes($dictionary);
                }
            }
            $id = $this->nextId++;
            $this->contexts[$id] = [
                'mode' => $mode, 'options' => $options, 'context' => $this->initialize($mode, $options),
                'pending' => '', 'consumed' => 0, 'ended' => false,
            ];
            return JsNumber::of($id);
        }
        $id = (int) $args[0]->toNumber();
        if ($operation === 'close') {
            unset($this->contexts[$id]);
            return JsUndefined::instance();
        }
        if (!isset($this->contexts[$id])) {
            throw new \RuntimeException('zlib binding closed', -2);
        }
        $entry = &$this->contexts[$id];
        if ($operation === 'reset') {
            $entry['context'] = $this->initialize($entry['mode'], $entry['options']);
            $entry['pending'] = '';
            $entry['consumed'] = 0;
            $entry['ended'] = false;
            return JsUndefined::instance();
        }
        if ($operation !== 'process' || !$args[1] instanceof JsTypedArray) {
            throw new \RuntimeException('Invalid zlib operation', -2);
        }
        $bytes = $entry['pending'] . self::readBytes($args[1]);
        $entry['pending'] = '';
        $flush = (int) $args[2]->toNumber();
        if ($entry['context'] === null) {
            if (strlen($bytes) < 2 && $flush !== ZLIB_FINISH) {
                $entry['pending'] = $bytes;
                return $this->output('', $entry['consumed']);
            }
            $entry['mode'] = str_starts_with($bytes, "\x1f\x8b") ? 'Gunzip' : 'Inflate';
            $entry['context'] = $this->initialize($entry['mode'], $entry['options']);
        }
        $context = $entry['context'];
        if ($context instanceof \DeflateContext) {
            $output = @deflate_add($context, $bytes, $flush);
            if ($output === false) {
                throw new \RuntimeException('stream error', -2);
            }
            $entry['consumed'] += strlen($bytes);
            return $this->output($output, $entry['consumed']);
        }
        if (!$context instanceof \InflateContext) {
            throw new \RuntimeException('stream error', -2);
        }
        $output = '';
        do {
            if ($entry['ended']) {
                if ($entry['mode'] !== 'Gunzip' || $bytes === '' || $bytes[0] === "\0") {
                    break;
                }
                $context = $entry['context'] = $this->initialize('Gunzip', $entry['options']);
                if (!$context instanceof \InflateContext) {
                    throw new \RuntimeException('stream error', -2);
                }
                $entry['ended'] = false;
            }
            $before = inflate_get_read_len($context);
            $chunk = @inflate_add($context, $bytes, $flush);
            $read = inflate_get_read_len($context) - $before;
            $status = inflate_get_status($context);
            if ($chunk === false || $status < 0 && $status !== ZLIB_BUF_ERROR) {
                $message = $status === ZLIB_NEED_DICT ? 'Missing dictionary' : 'incorrect header check';
                throw new \RuntimeException($message, $status === ZLIB_NEED_DICT ? 2 : -3);
            }
            $output .= $chunk;
            $entry['consumed'] += $read;
            $entry['ended'] = $status === ZLIB_STREAM_END;
            $bytes = substr($bytes, $read);
        } while ($entry['ended'] && $entry['mode'] === 'Gunzip' && $bytes !== '');
        if ($flush === ZLIB_FINISH && !$entry['ended']) {
            throw new \RuntimeException('unexpected end of file', -5);
        }
        return $this->output($output, $entry['consumed']);
    }

    /** @param array<string, int|string> $options */
    private function initialize(string $mode, array $options): \InflateContext|\DeflateContext|null
    {
        if ($mode === 'Unzip') {
            return null;
        }
        $encoding = match ($mode) {
            'Gzip', 'Gunzip' => ZLIB_ENCODING_GZIP,
            'DeflateRaw', 'InflateRaw' => ZLIB_ENCODING_RAW,
            default => ZLIB_ENCODING_DEFLATE,
        };
        $compress = in_array($mode, ['Deflate', 'DeflateRaw', 'Gzip'], true);
        try {
            $context = $compress ? @deflate_init($encoding, $options) : @inflate_init($encoding, $options);
        } catch (\ValueError $error) {
            throw new \RuntimeException($error->getMessage(), -2, $error);
        }
        if ($context === false) {
            throw new \RuntimeException('Failed to initialize zlib context', -2);
        }
        return $context;
    }

    private static function readBytes(JsTypedArray $view): string
    {
        $view->validateNotDetached();
        return $view->getBuffer()->readBytes($view->getByteOffset(), $view->getLength() * $view->getBytesPerElement());
    }

    private function output(string $bytes, int $consumed): JsObject
    {
        $constructor = $this->engine->getGlobalEnv()->get('Uint8Array');
        $prototype = $constructor instanceof JsObject ? $constructor->get('prototype') : null;
        $view = JsTypedArray::fromLength('Uint8Array', strlen($bytes), $prototype instanceof JsObject ? $prototype : null);
        $view->getBuffer()->setData($bytes);
        $result = new JsObject();
        $result->set('bytes', $view);
        $result->set('consumed', JsNumber::of($consumed));
        return $result;
    }
}
