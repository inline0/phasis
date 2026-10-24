<?php

declare(strict_types=1);

namespace Phasis\Node;

use Phasis\Engine;
use Phasis\Exceptions\TypeError;
use Phasis\Interop\PhpToJs;
use Phasis\Runtime\AsyncContextStorage;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/** Native nonblocking TCP and Unix sockets, driven by the realm's event loop. */
final class IoBindings
{
    /** @var array<int, SocketState> */
    private array $sockets = [];
    private int $sequence = 0;

    private function __construct(private readonly Engine $engine, private readonly NodeRuntime $runtime)
    {
        $engine->getEventLoop()->setIoDriver(fn (int $timeout): bool => $this->poll($timeout), fn (): bool => $this->hasReferences());
        $runtime->onClose(fn () => $this->closeAll());
    }

    public static function create(Engine $engine, NodeRuntime $runtime): JsObject
    {
        $io = new self($engine, $runtime);
        $host = new JsObject();
        $host->set('call', JsFunction::fromCallable('call', function (JsValue $this_, array $args) use ($io): JsValue {
            $operation = ($args[0] ?? JsUndefined::instance())->toJsString();
            return $io->operation($operation, array_slice($args, 1));
        }));
        return $host;
    }

    /** @param list<JsValue> $args */
    private function operation(string $operation, array $args): JsValue
    {
        $result = new JsObject();
        try {
            $result->set('value', match ($operation) {
                'listen' => $this->listen($args),
                'connect' => $this->connect($args),
                'callback' => $this->setCallback($args),
                'write' => $this->write($args),
                'end' => $this->end($args),
                'close' => $this->close((int) ($args[0] ?? JsUndefined::instance())->toNumber()),
                'pause' => $this->pause($args),
                'ref' => $this->reference($args),
                'address' => $this->address($args),
                'startTls' => $this->startTls($args),
                'tlsInfo' => $this->tlsInfo($this->socket($args[0] ?? JsUndefined::instance())),
                'isIP' => JsNumber::of($this->ipVersion(($args[0] ?? JsUndefined::instance())->toJsString())),
                'noDelay', 'keepAlive' => $this->socketOption($operation, $args),
                default => throw new TypeError("Unknown socket operation '{$operation}'"),
            });
        } catch (\RuntimeException $error) {
            $result->set('error', $this->error($error->getMessage(), $error->getCode(), $operation));
        }
        return $result;
    }

    /** @param list<JsValue> $args */
    private function listen(array $args): JsValue
    {
        $options = $this->object($args[0] ?? JsUndefined::instance());
        $callback = $this->function($args[1] ?? JsUndefined::instance());
        $path = $options->get('path');
        $host = $options->get('host');
        $host = $host instanceof JsUndefined ? '::' : $host->toJsString();
        $port = (int) $options->get('port')->toNumber();
        $address = $path instanceof JsUndefined
            ? 'tcp://' . (str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host) . ':' . $port
            : 'unix://' . $this->runtime->absolutePath($path->toJsString());
        $backlog = $options->get('backlog');
        $context = stream_context_create(['socket' => [
            'backlog' => $backlog instanceof JsUndefined ? 511 : (int) $backlog->toNumber(),
            'ipv6_v6only' => $options->get('ipv6Only')->toBoolean(),
        ]]);
        $errno = 0;
        $message = '';
        $stream = @stream_socket_server($address, $errno, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if ($stream === false) {
            throw new \RuntimeException($message, $errno);
        }
        stream_set_blocking($stream, false);
        $id = ++$this->sequence;
        $this->sockets[$id] = new SocketState($id, $stream, true, $callback, AsyncContextStorage::active()->snapshot());
        return JsNumber::of($id);
    }

    /** @param list<JsValue> $args */
    private function connect(array $args): JsValue
    {
        $options = $this->object($args[0] ?? JsUndefined::instance());
        $callback = $this->function($args[1] ?? JsUndefined::instance());
        $path = $options->get('path');
        $host = $options->get('host');
        $host = $host instanceof JsUndefined ? 'localhost' : $host->toJsString();
        $address = $path instanceof JsUndefined
            ? 'tcp://' . (str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host) . ':' . (int) $options->get('port')->toNumber()
            : 'unix://' . $this->runtime->absolutePath($path->toJsString());
        $errno = 0;
        $message = '';
        $stream = @stream_socket_client(
            $address,
            $errno,
            $message,
            0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            stream_context_create(),
        );
        if ($stream === false) {
            throw new \RuntimeException($message, $errno);
        }
        stream_set_blocking($stream, false);
        $id = ++$this->sequence;
        $state = new SocketState($id, $stream, false, $callback, AsyncContextStorage::active()->snapshot());
        $state->connecting = true;
        $this->sockets[$id] = $state;
        return JsNumber::of($id);
    }

    /** @param list<JsValue> $args */
    private function setCallback(array $args): JsValue
    {
        $this->socket($args[0] ?? JsUndefined::instance())->callback = $this->function($args[1] ?? JsUndefined::instance());
        return JsUndefined::instance();
    }

    /** @param list<JsValue> $args */
    private function write(array $args): JsValue
    {
        $socket = $this->socket($args[0] ?? JsUndefined::instance());
        $data = $args[1] ?? JsUndefined::instance();
        if (!$data instanceof JsTypedArray || $data->getTypeName() !== 'Uint8Array') {
            throw new TypeError('Socket writes require Uint8Array bytes.');
        }
        $socket->output .= $data->getBuffer()->readBytes($data->getByteOffset(), $data->getLength());
        $socket->writeCallback = $this->function($args[2] ?? JsUndefined::instance());
        return JsUndefined::instance();
    }

    /** @param list<JsValue> $args */
    private function end(array $args): JsValue
    {
        $socket = $this->socket($args[0] ?? JsUndefined::instance());
        $socket->ending = true;
        return JsUndefined::instance();
    }

    /** @param list<JsValue> $args */
    private function pause(array $args): JsValue
    {
        $this->socket($args[0] ?? JsUndefined::instance())->paused = ($args[1] ?? JsUndefined::instance())->toBoolean();
        return JsUndefined::instance();
    }

    /** @param list<JsValue> $args */
    private function reference(array $args): JsValue
    {
        $this->socket($args[0] ?? JsUndefined::instance())->referenced = ($args[1] ?? JsUndefined::instance())->toBoolean();
        return JsUndefined::instance();
    }

    /** @param list<JsValue> $args */
    private function socketOption(string $operation, array $args): JsValue
    {
        $state = $this->socket($args[0] ?? JsUndefined::instance());
        $enabled = ($args[1] ?? JsUndefined::instance())->toBoolean();
        if (!function_exists('socket_import_stream')) {
            if ($operation === 'noDelay') {
                stream_context_set_option($state->stream, 'socket', 'tcp_nodelay', $enabled);
                return JsUndefined::instance();
            }
            throw new \RuntimeException('Socket keepalive requires the PHP sockets extension', 95);
        }
        $socket = @socket_import_stream($state->stream);
        if ($socket === false) {
            throw new \RuntimeException('Cannot access socket options', 9);
        }
        $ok = $operation === 'noDelay'
            ? @socket_set_option($socket, SOL_TCP, TCP_NODELAY, (int) $enabled)
            : @socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, (int) $enabled);
        if (!$ok) {
            $errno = socket_last_error($socket);
            throw new \RuntimeException(socket_strerror($errno), $errno);
        }
        return JsUndefined::instance();
    }

    /** @param list<JsValue> $args */
    private function address(array $args): JsValue
    {
        $socket = $this->socket($args[0] ?? JsUndefined::instance());
        $address = @stream_socket_get_name($socket->stream, ($args[1] ?? JsUndefined::instance())->toBoolean());
        if ($address === false) {
            return \Phasis\Value\JsNull::instance();
        }
        $colon = strrpos($address, ':');
        if ($colon === false) {
            return new JsString($address);
        }
        $host = trim(substr($address, 0, $colon), '[]');
        return PhpToJs::convert([
            'address' => $host,
            'port' => (int) substr($address, $colon + 1),
            'family' => str_contains($host, ':') ? 'IPv6' : 'IPv4',
        ]);
    }

    private function hasReferences(): bool
    {
        foreach ($this->sockets as $socket) {
            if ($socket->referenced) {
                return true;
            }
        }
        return false;
    }

    private function poll(int $timeout): bool
    {
        $read = $write = $except = $map = [];
        foreach ($this->sockets as $socket) {
            $map[(int) $socket->stream] = $socket;
            if ($socket->tlsNegotiating || (!$socket->paused && !$socket->readEnded)) {
                $read[] = $socket->stream;
            }
            if (
                $socket->connecting || ($socket->tlsNegotiating && !$socket->tlsHandshakeStarted)
                || (!$socket->tlsNegotiating && ($socket->output !== '' || $socket->writeCallback !== null
                    || ($socket->ending && !$socket->writeEnded)))
            ) {
                $write[] = $socket->stream;
            }
        }
        if ($read === [] && $write === []) {
            if ($timeout > 0) {
                usleep($timeout * 1000);
            }
            return false;
        }
        $ready = @stream_select($read, $write, $except, intdiv($timeout, 1000), ($timeout % 1000) * 1000);
        if ($ready === false || $ready === 0) {
            return false;
        }
        foreach ($write as $stream) {
            $socket = $map[(int) $stream];
            if (!isset($this->sockets[$socket->id])) {
                continue;
            }
            if ($socket->connecting) {
                $socket->connecting = false;
                $errno = 0;
                if (function_exists('socket_import_stream')) {
                    $native = @socket_import_stream($stream);
                    if ($native !== false) {
                        $errno = (int) @socket_get_option($native, SOL_SOCKET, SO_ERROR);
                    }
                }
                if ($errno !== 0 || @stream_socket_get_name($stream, true) === false) {
                    $message = $errno !== 0 && function_exists('socket_strerror')
                        ? socket_strerror($errno) : 'Connection refused';
                    $this->emit($socket, 'error', $this->error($message, $errno, 'connect'));
                    $this->close($socket->id);
                    continue;
                }
                $this->emit($socket, 'connect');
            }
            if ($socket->tlsNegotiating && !$this->handshake($socket)) {
                continue;
            }
            if (!is_resource($stream) || !isset($this->sockets[$socket->id])) {
                continue;
            }
            if ($socket->output !== '') {
                $written = @fwrite($stream, $socket->output);
                if ($written === false) {
                    $this->emit($socket, 'error', $this->error('Broken pipe', 32, 'write'));
                    $this->close($socket->id);
                    continue;
                }
                $socket->output = substr($socket->output, $written);
            }
            if ($socket->output === '' && $socket->writeCallback !== null) {
                $callback = $socket->writeCallback;
                $socket->writeCallback = null;
                $this->runtime->nextTick($callback);
            }
            if ($socket->ending && $socket->output === '' && !$socket->writeEnded) {
                $socket->writeEnded = true;
                @stream_socket_shutdown($stream, STREAM_SHUT_WR);
                $this->emit($socket, 'shutdown');
            }
        }
        foreach ($read as $stream) {
            if (!is_resource($stream) || !isset($map[(int) $stream])) {
                continue;
            }
            $socket = $map[(int) $stream];
            if (!isset($this->sockets[$socket->id])) {
                continue;
            }
            if ($socket->server) {
                $connection = @stream_socket_accept($stream, 0);
                if ($connection !== false) {
                    stream_set_blocking($connection, false);
                    $id = ++$this->sequence;
                    $accepted = new SocketState($id, $connection, false, null, $socket->context);
                    $this->sockets[$id] = $accepted;
                    $this->emit($socket, 'connection', JsNumber::of($id));
                    $this->readSocket($accepted);
                }
                continue;
            }
            $this->readSocket($socket);
        }
        return true;
    }

    private function readSocket(SocketState $socket): void
    {
        if (!isset($this->sockets[$socket->id]) || $socket->paused || $socket->readEnded) {
            return;
        }
        if ($socket->tlsNegotiating && !$this->handshake($socket)) {
            return;
        }
        $stream = $socket->stream;
        if (!is_resource($stream)) {
            return;
        }
        $data = @fread($stream, 65536);
        if ($data !== false && $data !== '') {
            $this->emit($socket, 'data', $this->runtime->byteArray($data));
        }
        if (is_resource($stream) && isset($this->sockets[$socket->id]) && feof($stream)) {
            $socket->readEnded = true;
            $this->emit($socket, 'end');
        }
    }

    private function emit(SocketState $socket, string $event, ?JsValue $value = null): void
    {
        if ($socket->callback === null) {
            return;
        }
        $previous = Engine::getCurrentInterpreter();
        $context = AsyncContextStorage::active()->snapshot();
        Engine::setCurrentInterpreter($this->engine->getInterpreter());
        AsyncContextStorage::active()->restore($socket->context);
        try {
            $this->engine->getInterpreter()->callFunction(
                $socket->callback,
                JsUndefined::instance(),
                [new JsString($event), $value ?? JsUndefined::instance()],
            );
        } finally {
            AsyncContextStorage::active()->restore($context);
            Engine::setCurrentInterpreter($previous);
        }
    }

    private function close(int $id): JsValue
    {
        $socket = $this->sockets[$id] ?? null;
        if ($socket !== null) {
            unset($this->sockets[$id]);
            if (is_resource($socket->stream)) {
                fclose($socket->stream);
            }
            foreach ($socket->tlsFiles as $file) {
                @unlink($file);
            }
            $this->emit($socket, 'close');
        }
        return JsUndefined::instance();
    }

    private function closeAll(): void
    {
        foreach ($this->sockets as $socket) {
            if (is_resource($socket->stream)) {
                fclose($socket->stream);
            }
            foreach ($socket->tlsFiles as $file) {
                @unlink($file);
            }
        }
        $this->sockets = [];
        $this->engine->getEventLoop()->setIoDriver(null, null);
    }

    private function socket(JsValue $id): SocketState
    {
        return $this->sockets[(int) $id->toNumber()] ?? throw new \RuntimeException('Socket is closed', 9);
    }

    private function object(JsValue $value): JsObject
    {
        return $value instanceof JsObject ? $value : throw new TypeError('Expected socket options.');
    }

    private function function(JsValue $value): JsFunction
    {
        return $value instanceof JsFunction ? $value : throw new TypeError('Expected socket callback.');
    }

    private function ipVersion(string $host): int
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 4
            : (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 6 : 0);
    }

    private function error(string $message, int $errno, string $syscall): JsObject
    {
        if ($errno === 0 && function_exists('socket_strerror')) {
            foreach (['SOCKET_EADDRINUSE', 'SOCKET_ECONNREFUSED', 'SOCKET_EACCES', 'SOCKET_ENOENT', 'SOCKET_EPIPE'] as $constant) {
                if (defined($constant)) {
                    $candidate = (int) constant($constant);
                    if (stripos($message, socket_strerror($candidate)) !== false) {
                        $errno = $candidate;
                        break;
                    }
                }
            }
        }
        $code = 'EIO';
        foreach (get_defined_constants() as $name => $value) {
            if (str_starts_with($name, 'SOCKET_E') && $value === abs($errno)) {
                $code = substr($name, 7);
                break;
            }
        }
        $error = new JsObject();
        $error->set('message', new JsString($code . ': ' . $message));
        $error->set('code', new JsString($code));
        $error->set('errno', JsNumber::of(-abs($errno)));
        $error->set('syscall', new JsString($syscall));
        return $error;
    }

    /** @param list<JsValue> $args */
    private function startTls(array $args): JsValue
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            throw new TypeError('TLS requires the PHP OpenSSL extension.');
        }
        $socket = $this->socket($args[0] ?? JsUndefined::instance());
        $options = $this->object($args[1] ?? JsUndefined::instance());
        $socket->tlsServer = $options->get('isServer')->toBoolean();
        $reject = $options->get('rejectUnauthorized');
        $reject = $reject instanceof JsUndefined || $reject->toBoolean();
        $requestCertificate = $options->get('requestCert')->toBoolean();
        if ($socket->tlsServer && $requestCertificate && !$reject) {
            throw new TypeError('Optional unverified client certificates are unavailable through PHP TLS streams.');
        }
        $ssl = [
            'verify_peer' => $socket->tlsServer ? $requestCertificate : $reject,
            'verify_peer_name' => !$socket->tlsServer && $reject && !$options->get('customIdentityCheck')->toBoolean(),
            'allow_self_signed' => false,
            'capture_peer_cert' => true,
            'capture_peer_cert_chain' => true,
            'disable_compression' => true,
            'SNI_enabled' => true,
        ];
        $servername = $options->get('servername');
        $hostname = $options->get('host');
        if (!$socket->tlsServer) {
            $peer = $servername instanceof JsUndefined || $servername->toJsString() === '' ? $hostname : $servername;
            if (!$peer instanceof JsUndefined) {
                $ssl['peer_name'] = $peer->toJsString();
            }
        }
        foreach (['cert' => 'local_cert', 'key' => 'local_pk', 'ca' => 'cafile'] as $name => $target) {
            $value = $options->get($name);
            if (!$value instanceof JsUndefined) {
                $file = $this->tlsFile($socket, $this->tlsBytes($value));
                $ssl[$target] = $file;
                if ($name === 'ca') {
                    $socket->tlsAuthorities = [$file];
                }
            }
        }
        foreach (['passphrase' => 'passphrase', 'ciphers' => 'ciphers', 'ecdhCurve' => 'ecdh_curve'] as $name => $target) {
            $value = $options->get($name);
            if (!$value instanceof JsUndefined) {
                $ssl[$target] = $value->toJsString();
            }
        }
        $alpn = $options->get('ALPNProtocols');
        if ($alpn instanceof JsObject) {
            $protocols = [];
            for ($i = 0, $length = (int) $alpn->get('length')->toNumber(); $i < $length; $i++) {
                $protocols[] = $alpn->get((string) $i)->toJsString();
            }
            $ssl['alpn_protocols'] = implode(',', $protocols);
        }
        $minimum = $options->get('minVersion');
        $maximum = $options->get('maxVersion');
        $minimum = $minimum instanceof JsUndefined ? 'TLSv1.2' : $minimum->toJsString();
        $maximum = $maximum instanceof JsUndefined ? 'TLSv1.3' : $maximum->toJsString();
        $versions = ['TLSv1' => 'TLSv1_0', 'TLSv1.1' => 'TLSv1_1', 'TLSv1.2' => 'TLSv1_2', 'TLSv1.3' => 'TLSv1_3'];
        $include = false;
        $method = 0;
        foreach ($versions as $name => $constant) {
            if ($name === $minimum) {
                $include = true;
            }
            if ($include) {
                $option = 'STREAM_CRYPTO_METHOD_' . $constant . ($socket->tlsServer ? '_SERVER' : '_CLIENT');
                if (defined($option)) {
                    $method |= (int) constant($option);
                }
            }
            if ($name === $maximum) {
                break;
            }
        }
        if ($method === 0 || !isset($versions[$minimum], $versions[$maximum])) {
            throw new TypeError('Invalid TLS version range.');
        }
        foreach ($ssl as $name => $value) {
            stream_context_set_option($socket->stream, 'ssl', $name, $value);
        }
        $socket->tlsMethod = $method;
        $socket->tlsNegotiating = true;
        $timeout = $options->get('handshakeTimeout');
        $socket->tlsDeadline = microtime(true) + ($timeout instanceof JsUndefined ? 120 : $timeout->toNumber() / 1000);
        return JsUndefined::instance();
    }

    private function handshake(SocketState $socket): bool
    {
        $socket->tlsHandshakeStarted = true;
        $message = '';
        set_error_handler(static function (int $number, string $text) use (&$message): bool {
            $message .= ($message === '' ? '' : ' ') . $text;
            return true;
        });
        try {
            $result = stream_socket_enable_crypto($socket->stream, true, $socket->tlsMethod);
        } finally {
            restore_error_handler();
        }
        if ($result === true) {
            $socket->tlsNegotiating = false;
            $socket->tls = true;
            $this->emit($socket, 'secure', $this->tlsInfo($socket));
            return is_resource($socket->stream) && isset($this->sockets[$socket->id]);
        }
        if ($result === false || microtime(true) > $socket->tlsDeadline) {
            $socket->tlsNegotiating = false;
            $error = new JsObject();
            $error->set('message', new JsString($message !== '' ? $message : 'TLS handshake timed out.'));
            $code = $result === false ? 'ERR_SSL_TLSV1_ALERT_INTERNAL_ERROR' : 'ERR_TLS_HANDSHAKE_TIMEOUT';
            if (stripos($message, 'certificate verify failed') !== false) {
                $code = 'CERTIFICATE_VERIFY_FAILED';
            }
            $error->set('code', new JsString($code));
            $this->emit($socket, 'error', $error);
            $this->close($socket->id);
        }
        return false;
    }

    private function tlsInfo(SocketState $socket): JsValue
    {
        $metadata = stream_get_meta_data($socket->stream);
        $context = stream_context_get_options($socket->stream);
        $peer = $context['ssl']['peer_certificate'] ?? null;
        $certificate = $peer instanceof \OpenSSLCertificate ? openssl_x509_parse($peer) : false;
        $authorized = false;
        $authorizationError = 'UNABLE_TO_GET_ISSUER_CERT';
        if ($certificate !== false && $peer instanceof \OpenSSLCertificate) {
            $purpose = $socket->tlsServer ? X509_PURPOSE_SSL_CLIENT : X509_PURPOSE_SSL_SERVER;
            $authorized = openssl_x509_checkpurpose($peer, $purpose, $socket->tlsAuthorities) === true;
            $authorizationError = ($certificate['validTo_time_t'] ?? PHP_INT_MAX) < time() ? 'CERT_HAS_EXPIRED' : 'UNABLE_TO_VERIFY_LEAF_SIGNATURE';
            $pem = '';
            openssl_x509_export($peer, $pem);
            $certificate['rawBase64'] = preg_replace('/-----[^-]+-----|\s/', '', $pem);
            $certificate['fingerprint'] = strtoupper(implode(':', str_split(openssl_x509_fingerprint($peer, 'sha1') ?: '', 2)));
            $certificate['fingerprint256'] = strtoupper(implode(':', str_split(openssl_x509_fingerprint($peer, 'sha256') ?: '', 2)));
            $certificate['serialNumber'] = $certificate['serialNumberHex'] ?? $certificate['serialNumber'] ?? '';
            $certificate['subjectaltname'] = $certificate['extensions']['subjectAltName'] ?? '';
            $certificate['valid_from'] = gmdate('M j H:i:s Y \G\M\T', (int) ($certificate['validFrom_time_t'] ?? 0));
            $certificate['valid_to'] = gmdate('M j H:i:s Y \G\M\T', (int) ($certificate['validTo_time_t'] ?? 0));
        }
        return PhpToJs::convert([
            'protocol' => $metadata['crypto']['protocol'] ?? null,
            'cipher' => [
                'name' => $metadata['crypto']['cipher_name'] ?? null,
                'version' => $metadata['crypto']['cipher_version'] ?? null,
                'bits' => $metadata['crypto']['cipher_bits'] ?? null,
            ],
            'alpnProtocol' => $metadata['crypto']['alpn_protocol'] ?? false,
            'authorized' => $authorized,
            'authorizationError' => $authorized ? null : $authorizationError,
            'certificate' => $certificate === false ? [] : $certificate,
        ]);
    }

    private function tlsFile(SocketState $socket, string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'phasis-tls-');
        if ($file === false) {
            throw new TypeError('Unable to create a TLS material file.');
        }
        $socket->tlsFiles[] = $file;
        chmod($file, 0600);
        if (file_put_contents($file, $contents) !== strlen($contents)) {
            throw new TypeError('Unable to write TLS material.');
        }
        return $file;
    }

    private function tlsBytes(JsValue $value): string
    {
        if ($value instanceof JsTypedArray) {
            return $value->getBuffer()->readBytes($value->getByteOffset(), $value->getLength() * $value->getBytesPerElement());
        }
        return $value->toJsString();
    }
}
