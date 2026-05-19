<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

/**
 * Signal that a SubtleCrypto operation rejects with a
 * NotSupportedError DOMException — algorithm name is well-formed
 * but not registered for the operation in question (e.g.
 * `digest('AES-GCM', ...)`). Distinguished from generic
 * OperationError by exception class so `rejectFromThrow` can
 * pick the right rejection shape.
 */
final class NotSupportedAlgorithm extends \RuntimeException
{
}
