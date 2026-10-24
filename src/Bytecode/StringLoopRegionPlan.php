<?php

declare(strict_types=1);

namespace Phasis\Bytecode;

use Phasis\Ast\Node;
use Phasis\Ast\Statement\BlockStatement;
use Phasis\Ast\Statement\WhileStatement;

/** A pure first segment of a while loop; opaque statements remain owned by the VM. */
final readonly class StringLoopRegionPlan
{
    /**
     * @param array<string, array{slot: int, type: string}> $inputs
     * @param array<string, array{slot: int, type: string}> $outputs
     * @param array<string, string> $bindingTypes
     * @param list<Node> $exits Original statements at which execution must return to the VM.
     */
    public function __construct(
        public WhileStatement $loop,
        public BlockStatement $body,
        public array $inputs,
        public array $outputs,
        public array $bindingTypes,
        public array $exits,
    ) {
    }

    /**
     * No partial effects are permitted when a proof or entry guard fails.
     *
     * @param array<string, int> $slots
     * @param array<string, string> $captured
     * @param array<int, bool> $constants
     */
    public static function analyze(WhileStatement $loop, array $slots, array $captured, array $constants): ?self
    {
        return StringLoopRegionAnalysis::analyze($loop, $slots, $captured, $constants);
    }
}
