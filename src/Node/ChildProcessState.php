<?php

declare(strict_types=1);

namespace Phasis\Node;

use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsValue;

/** Resources and lifecycle of one native child owned by a Node realm. */
final class ChildProcessState
{
    public bool $referenced = true;
    public bool $spawnPending = true;
    public bool $ending = false;
    public bool $exited = false;
    public bool $exitEmitted = false;
    public bool $closed = false;
    public ?int $exitCode = null;
    public ?string $signal = null;
    public string $input = '';
    public ?JsFunction $writeCallback = null;
    /** @var array<int, bool> */
    public array $paused = [1 => true, 2 => true];
    /** @var array<int, bool> */
    public array $pipeReferences = [0 => true, 1 => true, 2 => true];

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     * @param array{array<int, JsObject>, array<int, JsValue>} $context
     */
    public function __construct(
        public readonly int $id,
        public mixed $process,
        public readonly int $pid,
        public array $pipes,
        public readonly ?JsFunction $callback,
        public readonly array $context,
    ) {
    }
}
