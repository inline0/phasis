<?php

declare(strict_types=1);

namespace Phasis\Node;

/** A host filesystem failure translated to a JavaScript system error. */
final class FileSystemError extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $syscall,
        public readonly ?string $path = null,
        public readonly ?string $destination = null,
    ) {
        parent::__construct($errorCode);
    }
}
