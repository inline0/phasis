<?php

declare(strict_types=1);

namespace PhpJs\Tests\Oracle;

class TestResult
{
    public function __construct(
        public readonly string $testPath,
        public readonly TestStatus $status,
        public readonly string $message = '',
    ) {
    }
}
