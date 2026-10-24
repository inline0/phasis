<?php

declare(strict_types=1);

$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
if ($key === false) {
    throw new RuntimeException('Unable to generate the test certificate key.');
}
$request = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
if ($request === false) {
    throw new RuntimeException('Unable to generate the test certificate request.');
}
$certificate = openssl_csr_sign($request, null, $key, 1, ['digest_alg' => 'sha256']);
if ($certificate === false) {
    throw new RuntimeException('Unable to generate the test certificate.');
}
$private = $public = '';
openssl_pkey_export($key, $private);
openssl_x509_export($certificate, $public);
echo json_encode(['key' => $private, 'cert' => $public], JSON_THROW_ON_ERROR);
