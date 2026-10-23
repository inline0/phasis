<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Node;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class TlsTest extends TestCase
{
    public function testHttpsUsesEncryptedSocketsAndVerifiesTheCertificate(): void
    {
        [$key, $certificate] = $this->certificate();
        $engine = new Engine(node: true);
        $engine->setExecutionBudget(10);
        $engine->setGlobal('privateKey', $key);
        $engine->setGlobal('certificate', $certificate);
        try {
            $engine->eval(<<<'JS'
const https = require('https');
var received, failure;
const server = https.createServer({ key: privateKey, cert: certificate }, (request, response) => {
  response.end(request.method + ':' + request.url + ':' + request.socket.encrypted);
});
server.on('tlsClientError', error => { failure = error.message; });
server.listen(0, '127.0.0.1', () => {
  const request = https.get({ host: '127.0.0.1', port: server.address().port, path: '/tls',
    ca: certificate, servername: 'localhost', agent: false }, response => {
    let body = '';
    const socket = response.socket;
    response.setEncoding('utf8');
    response.on('data', chunk => { body += chunk; });
    response.on('end', () => {
      received = [body, socket.encrypted, socket.authorized, socket.getPeerCertificate().subject.CN, socket.getProtocol().startsWith('TLSv1.')];
      server.close();
    });
  });
  request.on('error', error => { failure = error.message; server.close(); });
});
JS);
            self::assertSame('undefined', $engine->eval('typeof failure'), (string) $engine->eval('failure'));
            self::assertSame('["GET:/tls:true",true,true,"localhost",true]', $engine->eval('JSON.stringify(received)'));
        } finally {
            $engine->getNodeRuntime()?->close();
        }
    }

    public function testUntrustedCertificateRejectsTheClientConnection(): void
    {
        [$key, $certificate] = $this->certificate();
        $engine = new Engine(node: true);
        $engine->setExecutionBudget(10);
        $engine->setGlobal('privateKey', $key);
        $engine->setGlobal('certificate', $certificate);
        try {
            $engine->eval(<<<'JS'
const tls = require('tls');
var rejected = false, connected = false, rejection;
const server = tls.createServer({ key: privateKey, cert: certificate }, socket => socket.end('secret'));
server.on('tlsClientError', () => {});
server.listen(0, '127.0.0.1', () => {
  const client = tls.connect({ host: '127.0.0.1', port: server.address().port, servername: 'localhost' });
  client.on('secureConnect', () => { connected = true; client.destroy(); server.close(); });
  client.on('error', error => { rejection = error.message; rejected = /certificate/i.test(error.message); server.close(); });
});
JS);
            self::assertTrue($engine->eval('rejected'), (string) $engine->eval('rejection'));
            self::assertFalse($engine->eval('connected'));
        } finally {
            $engine->getNodeRuntime()?->close();
        }
    }

    public function testTlsServerVerifiesAnAuthenticatedClient(): void
    {
        [$key, $certificate] = $this->certificate();
        $clientKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($clientKey);
        $clientRequest = openssl_csr_new(['commonName' => 'client'], $clientKey, ['digest_alg' => 'sha256']);
        self::assertNotFalse($clientRequest);
        $clientCertificate = openssl_csr_sign($clientRequest, $certificate, $key, 1, ['digest_alg' => 'sha256']);
        self::assertNotFalse($clientCertificate);
        $clientKeyPem = $clientCertificatePem = '';
        self::assertTrue(openssl_pkey_export($clientKey, $clientKeyPem));
        self::assertTrue(openssl_x509_export($clientCertificate, $clientCertificatePem));
        $engine = new Engine(node: true);
        $engine->setExecutionBudget(10);
        $engine->setGlobal('privateKey', $key);
        $engine->setGlobal('certificate', $certificate);
        $engine->setGlobal('clientKey', $clientKeyPem);
        $engine->setGlobal('clientCertificate', $clientCertificatePem);
        try {
            $engine->eval(<<<'JS'
const tls = require('tls');
var authenticated, echoed, failure;
const server = tls.createServer({ key: privateKey, cert: certificate, ca: certificate, requestCert: true }, socket => {
  authenticated = [socket.authorized, socket.getPeerCertificate().subject.CN];
  socket.on('data', data => socket.end(data));
});
server.on('tlsClientError', error => { failure = error.message; server.close(); });
server.listen(0, '127.0.0.1', () => {
  const client = tls.connect({ host: '127.0.0.1', port: server.address().port, servername: 'localhost',
    key: clientKey, cert: clientCertificate, ca: certificate }, () => client.write('authenticated'));
  client.on('data', data => { echoed = data.toString(); client.destroy(); server.close(); });
  client.on('error', error => { failure = error.message; server.close(); });
});
JS);
            self::assertSame('undefined', $engine->eval('typeof failure'), (string) $engine->eval('failure'));
            self::assertSame('[true,"client"]', $engine->eval('JSON.stringify(authenticated)'));
            self::assertSame('authenticated', $engine->eval('echoed'));
        } finally {
            $engine->getNodeRuntime()?->close();
        }
    }

    /** @return array{string, string} */
    private function certificate(): array
    {
        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($privateKey);
        $request = openssl_csr_new(['commonName' => 'localhost'], $privateKey, ['digest_alg' => 'sha256']);
        self::assertNotFalse($request);
        $certificate = openssl_csr_sign($request, null, $privateKey, 1, ['digest_alg' => 'sha256']);
        self::assertNotFalse($certificate);
        $keyPem = $certificatePem = '';
        self::assertTrue(openssl_pkey_export($privateKey, $keyPem));
        self::assertTrue(openssl_x509_export($certificate, $certificatePem));
        return [$keyPem, $certificatePem];
    }
}
