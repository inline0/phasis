<?php

declare(strict_types=1);

namespace Phasis\Bytecode\Parts;

use Phasis\Ast\Expression\ArrayExpression;
use Phasis\Ast\Expression\AssignmentExpression;
use Phasis\Ast\Expression\BinaryExpression;
use Phasis\Ast\Expression\CallExpression;
use Phasis\Ast\Expression\ConditionalExpression;
use Phasis\Ast\Expression\Identifier;
use Phasis\Ast\Expression\Literal;
use Phasis\Ast\Expression\MemberExpression;
use Phasis\Ast\Expression\NewExpression;
use Phasis\Ast\Expression\ObjectExpression;
use Phasis\Ast\Expression\PrivateIdentifier;
use Phasis\Ast\Expression\Property;
use Phasis\Ast\Expression\SpreadElement;
use Phasis\Ast\Expression\TemplateElement;
use Phasis\Ast\Expression\TemplateLiteral;
use Phasis\Ast\Expression\ThisExpression;
use Phasis\Ast\Node;
use Phasis\Ast\Declaration\FunctionDeclaration;
use Phasis\Ast\Declaration\VariableDeclaration;
use Phasis\Ast\Expression\LogicalExpression;
use Phasis\Ast\Expression\SequenceExpression;
use Phasis\Ast\Expression\UnaryExpression;
use Phasis\Ast\Expression\UpdateExpression;
use Phasis\Ast\Pattern\ArrayPattern;
use Phasis\Ast\Pattern\AssignmentPattern;
use Phasis\Ast\Pattern\ObjectPattern;
use Phasis\Ast\Pattern\RestElement;
use Phasis\Ast\Statement\BlockStatement;
use Phasis\Ast\Statement\BreakStatement;
use Phasis\Ast\Statement\ContinueStatement;
use Phasis\Ast\Statement\DoWhileStatement;
use Phasis\Ast\Statement\EmptyStatement;
use Phasis\Ast\Statement\ExpressionStatement;
use Phasis\Ast\Statement\ForStatement;
use Phasis\Ast\Statement\IfStatement;
use Phasis\Ast\Statement\ReturnStatement;
use Phasis\Ast\Statement\ThrowStatement;
use Phasis\Ast\Statement\WhileStatement;
use Phasis\Value\JsBigInt;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\Bytecode\Op;
use Phasis\Bytecode\CompilerBailout;
use Phasis\Bytecode\HandlerEntry;

/**
 * Compiler trait part: CompilerStatement. Composed into Compiler via
 * `use Parts\CompilerStatement;`.
 */
trait CompilerStatement
{
    private function compileStatement(Node $node): void
    {
        if ($node instanceof ReturnStatement) {
            if ($this->inGenerator && $this->finallyLoopBoundaries !== []) {
                throw new CompilerBailout('generator return inside try-with-finally');
            }
            if ($node->argument === null) {
                $this->emit(Op::LOAD_UNDEF);
            } else {
                $this->compileReturnExpression($node->argument);
            }
            $this->emitReturnCompletion();
            return;
        }
        if ($node instanceof ExpressionStatement) {
            if ($this->tryEmitDiscardedUpdate($node->expression)) {
                return;
            }
            $this->compileExpression($node->expression);
            $this->emit(Op::POP);
            return;
        }
        if ($node instanceof BlockStatement) {
            foreach ($node->body as $inner) {
                $this->compileStatement($inner);
            }
            return;
        }
        if ($node instanceof EmptyStatement) {
            return;
        }
        if ($node instanceof IfStatement) {
            $this->compileIf($node);
            return;
        }
        if ($node instanceof ForStatement) {
            $this->compileFor($node);
            return;
        }
        if ($node instanceof \Phasis\Ast\Statement\SwitchStatement) {
            $this->compileSwitch($node);
            return;
        }
        if ($node instanceof \Phasis\Ast\Statement\ForOfStatement) {
            $this->compileForOf($node);
            return;
        }
        if ($node instanceof WhileStatement) {
            $this->compileWhile($node);
            return;
        }
        if ($node instanceof DoWhileStatement) {
            $this->compileDoWhile($node);
            return;
        }
        if ($node instanceof BreakStatement) {
            if ($node->label !== null) {
                throw new CompilerBailout('labeled break');
            }
            if ($this->loopStack === []) {
                throw new CompilerBailout('break outside loop');
            }
            $loopDepth = count($this->loopStack);
            foreach ($this->finallyLoopBoundaries as $boundary) {
                if ($boundary >= $loopDepth) {
                    throw new CompilerBailout('break escaping try-with-finally');
                }
            }
            $idx = $this->emitJump(Op::JUMP);
            $this->loopStack[$loopDepth - 1]['breaks'][] = $idx;
            return;
        }
        if ($node instanceof ContinueStatement) {
            if ($node->label !== null) {
                throw new CompilerBailout('labeled continue');
            }
            $loopDepth = count($this->loopStack);
            while ($loopDepth > 0 && $this->loopStack[$loopDepth - 1]['continue'] === -2) {
                $loopDepth--;
            }
            if ($loopDepth === 0) {
                throw new CompilerBailout('continue outside loop');
            }
            foreach ($this->finallyLoopBoundaries as $boundary) {
                if ($boundary >= $loopDepth) {
                    throw new CompilerBailout('continue escaping try-with-finally');
                }
            }
            $idx = $this->emitJump(Op::JUMP);
            $this->loopStack[$loopDepth - 1]['continues'][] = $idx;
            return;
        }
        if ($node instanceof VariableDeclaration) {
            $this->compileVarDecl($node);
            return;
        }
        if ($node instanceof ThrowStatement) {
            $this->compileExpression($node->argument);
            $this->emit(Op::THROW);
            return;
        }
        if ($node instanceof \Phasis\Ast\Statement\TryStatement) {
            $this->compileTryCatch($node);
            return;
        }
        if ($node instanceof \Phasis\Ast\Declaration\ClassDeclaration) {
            // Classes that reference outer locals (e.g. `class C extends
            // OuterParam { ... }`) need env-based resolution for those
            // names. The VM-compiled body keeps locals in frame slots,
            // so the class body would fail to find them. Bail to the
            // tree-walker for now.
            if ($this->capturesOuterLocal($node)) {
                throw new CompilerBailout('class captures outer local');
            }
            $idx = count($this->classNodes);
            $this->classNodes[] = $node;
            $this->emit(Op::MAKE_CLASS, $idx);
            if ($node->id !== null) {
                $this->emit(Op::STORE_LOCAL, $this->localSlots[$node->id->name]);
            } else {
                $this->emit(Op::POP);
            }
            return;
        }
        throw new CompilerBailout('unsupported statement: ' . $node->type());
    }

    /** Compile a return value, preserving strict tail positions recursively. */
    private function compileReturnExpression(Node $expression): void
    {
        if ($this->tailCallsEnabled && $expression instanceof CallExpression && $this->chainHasOptionalLink($expression)) {
            $this->compileOptionalChain($expression, tail: true);
            return;
        }
        if ($this->tailCallsEnabled && $expression instanceof ConditionalExpression) {
            $this->compileExpression($expression->test);
            $alternate = $this->emitJump(Op::JUMP_IF_FALSE);
            $this->compileReturnExpression($expression->consequent);
            $end = $this->emitJump(Op::JUMP);
            $this->patchJumpToHere($alternate);
            $this->compileReturnExpression($expression->alternate);
            $this->patchJumpToHere($end);
            return;
        }
        if ($this->tailCallsEnabled && $expression instanceof LogicalExpression) {
            // The left call is not in tail position: its result is tested
            // before deciding whether to evaluate the right operand.
            $this->compileExpression($expression->left);
            if ($expression->operator === '??') {
                $right = $this->emitJump(Op::JUMP_IF_NULLISH);
                $end = $this->emitJump(Op::JUMP);
                $this->patchJumpToHere($right);
            } else {
                $end = $this->emitJump($expression->operator === '&&'
                    ? Op::JUMP_IF_FALSY_KEEP
                    : Op::JUMP_IF_TRUTHY_KEEP);
            }
            $this->compileReturnExpression($expression->right);
            $this->patchJumpToHere($end);
            return;
        }
        if ($this->tailCallsEnabled && $expression instanceof SequenceExpression) {
            $last = count($expression->expressions) - 1;
            foreach ($expression->expressions as $index => $part) {
                if ($index === $last) {
                    $this->compileReturnExpression($part);
                } else {
                    $this->compileExpression($part);
                    $this->emit(Op::POP);
                }
            }
            return;
        }
        $this->compileExpression($expression);
        if (!$this->tailCallsEnabled || !$expression instanceof CallExpression) {
            return;
        }
        // compileCall preserves lookup/argument order and ends in a two-word
        // CALL instruction. Return a thunk through the interpreter's existing
        // trampoline instead of growing either the PHP or VM call stack.
        $pc = count($this->code) - 2;
        $this->code[$pc] = match ($this->code[$pc]) {
            Op::CALL => Op::TAIL_CALL,
            Op::CALL_METHOD => Op::TAIL_CALL_METHOD,
            default => throw new CompilerBailout('unsupported tail call lowering'),
        };
        $this->hasTailCalls = true;
    }

    /** Consume a return value, routing it through each pending finalizer. */
    private function emitReturnCompletion(): void
    {
        if ($this->finallyContexts === []) {
            $this->emit(Op::RET);
            return;
        }
        $index = count($this->finallyContexts) - 1;
        $context = $this->finallyContexts[$index];
        $this->emit(Op::STORE_LOCAL, $context['valueSlot']);
        $this->emit(Op::LOAD_TRUE);
        $this->emit(Op::STORE_LOCAL, $context['completionSlot']);
        $this->finallyContexts[$index]['jumps'][] = $this->emitJump(Op::JUMP);
    }

    /**
     * Keep finalizers outside their protected ranges so their abrupt
     * completions override, rather than re-enter, the pending completion.
     */
    private function compileTryCatch(\Phasis\Ast\Statement\TryStatement $node): void
    {
        $stackBase = $this->tryEntryStackDepth;
        $tailCallsWereEnabled = $this->tailCallsEnabled;
        $this->tailCallsEnabled = false;

        if ($node->finalizer === null) {
            $tryStart = count($this->code);
            try {
                foreach ($node->block->body as $stmt) {
                    $this->compileStatement($stmt);
                }
            } finally {
                $this->tailCallsEnabled = $tailCallsWereEnabled;
            }
            $tryEnd = count($this->code);
            $jmpPastCatch = $this->emitJump(Op::JUMP);
            $catchPc = count($this->code);
            $exceptionSlot = -1;
            if ($node->handler->param instanceof Identifier) {
                $name = $node->handler->param->name;
                $exceptionSlot = $this->localSlots[$name]
                    ?? $this->declareLocal($name);
            }
            foreach ($node->handler->body->body as $stmt) {
                $this->compileStatement($stmt);
            }
            $this->patchJumpToHere($jmpPastCatch);
            $this->handlers[] = new HandlerEntry(
                tryStart: $tryStart,
                tryEnd: $tryEnd,
                catchPc: $catchPc,
                exceptionSlot: $exceptionSlot,
                stackBase: $stackBase,
            );
            return;
        }

        // undefined, false and true distinguish normal, throw and return.
        // The pending value stays in its own slot across nested finalizers.
        $valueSlot = count($this->localNames);
        $this->localNames[] = '[[finallyValue]]';
        $completionSlot = count($this->localNames);
        $this->localNames[] = '[[finallyCompletion]]';
        $this->finallyContexts[] = [
            'valueSlot' => $valueSlot,
            'completionSlot' => $completionSlot,
            'jumps' => [],
        ];
        $this->finallyLoopBoundaries[] = count($this->loopStack);

        $tryStart = count($this->code);
        foreach ($node->block->body as $stmt) {
            $this->compileStatement($stmt);
        }
        $tryEnd = count($this->code);
        $this->emit(Op::LOAD_UNDEF);
        $this->emit(Op::STORE_LOCAL, $completionSlot);
        $normalJumps = [$this->emitJump(Op::JUMP)];
        $catchPc = count($this->code);
        $exceptionSlot = $valueSlot;

        if ($node->handler !== null) {
            if ($node->handler->param instanceof Identifier) {
                $name = $node->handler->param->name;
                $exceptionSlot = $this->localSlots[$name]
                    ?? $this->declareLocal($name);
            }
            $catchStart = count($this->code);
            foreach ($node->handler->body->body as $stmt) {
                $this->compileStatement($stmt);
            }
            $catchEnd = count($this->code);
            $this->emit(Op::LOAD_UNDEF);
            $this->emit(Op::STORE_LOCAL, $completionSlot);
            $normalJumps[] = $this->emitJump(Op::JUMP);
            $this->handlers[] = new HandlerEntry(
                tryStart: $catchStart,
                tryEnd: $catchEnd,
                catchPc: count($this->code),
                exceptionSlot: $valueSlot,
                stackBase: $stackBase,
            );
        }
        $this->emit(Op::LOAD_FALSE);
        $this->emit(Op::STORE_LOCAL, $completionSlot);

        $context = array_pop($this->finallyContexts);
        foreach ([...$normalJumps, ...$context['jumps']] as $jump) {
            $this->patchJumpToHere($jump);
        }
        $this->tailCallsEnabled = $tailCallsWereEnabled;
        foreach ($node->finalizer->body as $stmt) {
            $this->compileStatement($stmt);
        }
        array_pop($this->finallyLoopBoundaries);

        $this->emit(Op::LOAD_LOCAL, $completionSlot);
        $this->emit(Op::LOAD_FALSE);
        $this->emit(Op::SEQ);
        $notThrow = $this->emitJump(Op::JUMP_IF_FALSE);
        $this->emit(Op::LOAD_LOCAL, $valueSlot);
        $this->emit(Op::THROW);
        $this->patchJumpToHere($notThrow);
        $this->emit(Op::LOAD_LOCAL, $completionSlot);
        $normal = $this->emitJump(Op::JUMP_IF_FALSE);
        $this->emit(Op::LOAD_LOCAL, $valueSlot);
        $this->emitReturnCompletion();
        $this->patchJumpToHere($normal);

        $this->handlers[] = new HandlerEntry(
            tryStart: $tryStart,
            tryEnd: $tryEnd,
            catchPc: $catchPc,
            exceptionSlot: $exceptionSlot,
            stackBase: $stackBase,
        );
    }

    private function compileIf(IfStatement $node): void
    {
        $this->compileExpression($node->test);
        $jmpFalse = $this->emitJump(Op::JUMP_IF_FALSE);
        $this->compileStatement($node->consequent);
        if ($node->alternate !== null) {
            $jmpEnd = $this->emitJump(Op::JUMP);
            $this->patchJumpToHere($jmpFalse);
            $this->compileStatement($node->alternate);
            $this->patchJumpToHere($jmpEnd);
        } else {
            $this->patchJumpToHere($jmpFalse);
        }
    }

    /**
     * Detect a canonical for-loop test of the form `local op rhs`
     * where op is `<` or `<=` and rhs is either a numeric literal or
     * another local. Emits one of the fused 3-operand jump opcodes
     * so the dispatcher hits a single arm per iteration instead of
     * LOAD_LOCAL + (LOAD_LOCAL|LOAD_CONST) + LT|LE + JUMP_IF_FALSE.
     * Returns the opcode pc on success so the caller can patch the
     * offset cell at +3 once the loop body is compiled. Returns -1
     * for any unsupported test shape.
     */
    private function tryEmitFusedLoopTest(\Phasis\Ast\Node $test): int
    {
        if (!$test instanceof BinaryExpression) {
            return -1;
        }
        $op = $test->operator;
        if ($op !== '<' && $op !== '<=') {
            return -1;
        }
        $left = $test->left;
        $right = $test->right;
        if (!$left instanceof Identifier || !isset($this->localSlots[$left->name]) || isset($this->capturedLocals[$left->name])) {
            return -1;
        }
        $localSlot = $this->localSlots[$left->name];

        if ($right instanceof Literal && (is_int($right->value) || is_float($right->value))) {
            // Match compileLiteral's full-precision dedupe key so the
            // fused for-loop test never collides with the canonical
            // literal slot (e.g. `for (i = 0; i < 1; ...)` vs a stray
            // 1.0000000000000001 elsewhere in the same Program).
            $constIdx = $this->internConst(
                JsNumber::of((float) $right->value),
                'n:' . serialize((float) $right->value),
            );
            $opcodePc = count($this->code);
            $this->code[] = $op === '<' ? Op::JUMP_IF_LOCAL_GE_CONST : Op::JUMP_IF_LOCAL_GT_CONST;
            $this->code[] = $localSlot;
            $this->code[] = $constIdx;
            $this->code[] = 0; // placeholder offset
            return $opcodePc;
        }
        if ($right instanceof Identifier && isset($this->localSlots[$right->name]) && !isset($this->capturedLocals[$right->name])) {
            $rightSlot = $this->localSlots[$right->name];
            $opcodePc = count($this->code);
            $this->code[] = $op === '<' ? Op::JUMP_IF_LOCAL_GE_LOCAL : Op::JUMP_IF_LOCAL_GT_LOCAL;
            $this->code[] = $localSlot;
            $this->code[] = $rightSlot;
            $this->code[] = 0; // placeholder offset
            return $opcodePc;
        }
        return -1;
    }

    private function compileFor(ForStatement $node): void
    {
        // Init.
        if ($node->init !== null) {
            if ($node->init instanceof VariableDeclaration) {
                $this->compileVarDecl($node->init);
            } else {
                $this->compileExpression($node->init);
                $this->emit(Op::POP);
            }
        }
        $loopId = $this->loopCount++;
        $this->emit(Op::LOOP_INIT, $loopId);
        $loopStart = count($this->code);
        // Test. Try the fused JUMP_IF_LOCAL_GE_CONST shortcut first
        // for the canonical `for (let i = 0; i < N; i++)` pattern.
        $jmpExit = -1;
        $fusedOpcodePc = -1;
        if ($node->test !== null) {
            $fusedOpcodePc = $this->tryEmitFusedLoopTest($node->test);
            if ($fusedOpcodePc === -1) {
                $this->compileExpression($node->test);
                $jmpExit = $this->emitJump(Op::JUMP_IF_FALSE);
            }
        }
        $this->emit(Op::LOOP_TICK, $loopId);
        // Body.
        $this->loopStack[] = [
            'continue' => -1, // patched after body via patchContinues
            'breaks' => [],
            'continues' => [],
        ];
        $this->compileStatement($node->body);
        $continueTarget = count($this->code);
        // Update.
        if ($node->update !== null) {
            if (!$this->tryEmitDiscardedUpdate($node->update)) {
                $this->compileExpression($node->update);
                $this->emit(Op::POP);
            }
        }
        $this->emit(Op::JUMP, $loopStart - count($this->code) + 1);
        // Patch the JUMP we just emitted: operand is the second int
        // we wrote, so its operandIndex = count - 1 - 0 = count - 2.
        // Actually emit(Op::JUMP, X) wrote opcode then operand. The
        // operand cell index is len - 1 with the value already X.
        // Recompute relative jump: target - opcodePc.
        $jumpOpcodePc = count($this->code) - 2;
        $this->code[$jumpOpcodePc + 1] = $loopStart - $jumpOpcodePc;

        $loopExit = count($this->code);
        if ($jmpExit !== -1) {
            $this->patchJumpToHere($jmpExit);
        }
        if ($fusedOpcodePc !== -1) {
            // JUMP_IF_LOCAL_GE_CONST layout: opcode at fusedOpcodePc,
            // operands at +1, +2, +3. The dispatcher does pc += offset
            // where pc starts at the opcode position.
            $this->code[$fusedOpcodePc + 3] = $loopExit - $fusedOpcodePc;
        }
        $frame = array_pop($this->loopStack);
        foreach ($frame['continues'] as $idx) {
            $opcodePc = $idx - 1;
            $this->code[$idx] = $continueTarget - $opcodePc;
        }
        foreach ($frame['breaks'] as $idx) {
            $opcodePc = $idx - 1;
            $this->code[$idx] = $loopExit - $opcodePc;
        }
    }

    private function compileWhile(WhileStatement $node): void
    {
        $loopId = $this->loopCount++;
        $this->emit(Op::LOOP_INIT, $loopId);
        $loopStart = count($this->code);
        $this->compileExpression($node->test);
        $jmpExit = $this->emitJump(Op::JUMP_IF_FALSE);
        $this->emit(Op::LOOP_TICK, $loopId);
        $this->loopStack[] = ['continue' => -1, 'breaks' => [], 'continues' => []];
        $this->compileStatement($node->body);
        $continueTarget = count($this->code);
        $this->emit(Op::JUMP, 0);
        $jumpOpcodePc = count($this->code) - 2;
        $this->code[$jumpOpcodePc + 1] = $loopStart - $jumpOpcodePc;
        $loopExit = count($this->code);
        $this->patchJumpToHere($jmpExit);
        $frame = array_pop($this->loopStack);
        foreach ($frame['continues'] as $idx) {
            $opcodePc = $idx - 1;
            $this->code[$idx] = $continueTarget - $opcodePc;
        }
        foreach ($frame['breaks'] as $idx) {
            $opcodePc = $idx - 1;
            $this->code[$idx] = $loopExit - $opcodePc;
        }
    }

    private function compileDoWhile(DoWhileStatement $node): void
    {
        $loopId = $this->loopCount++;
        $this->emit(Op::LOOP_INIT, $loopId);
        $loopStart = count($this->code);
        $this->emit(Op::LOOP_TICK, $loopId);
        $this->loopStack[] = ['continue' => -1, 'breaks' => [], 'continues' => []];
        $this->compileStatement($node->body);
        $continueTarget = count($this->code);
        $this->compileExpression($node->test);
        // JUMP_IF_TRUE back to start.
        $this->emit(Op::JUMP_IF_TRUE, 0);
        $jumpOpcodePc = count($this->code) - 2;
        $this->code[$jumpOpcodePc + 1] = $loopStart - $jumpOpcodePc;
        $loopExit = count($this->code);
        $frame = array_pop($this->loopStack);
        foreach ($frame['continues'] as $idx) {
            $opcodePc = $idx - 1;
            $this->code[$idx] = $continueTarget - $opcodePc;
        }
        foreach ($frame['breaks'] as $idx) {
            $opcodePc = $idx - 1;
            $this->code[$idx] = $loopExit - $opcodePc;
        }
    }

    private function compileVarDecl(VariableDeclaration $node): void
    {
        if ($node->kind === 'using' || $node->kind === 'await using') {
            // `using` declarations require resource-tracking on the
            // current env's disposal stack; the slot-based compiler
            // doesn't model that yet.
            throw new CompilerBailout('using declaration');
        }
        foreach ($node->declarations as $decl) {
            if ($decl->id instanceof ArrayPattern) {
                if ($decl->init === null) {
                    throw new CompilerBailout('destructure without init');
                }
                $elements = [];
                foreach ($decl->id->elements as $element) {
                    if ($element === null) {
                        $elements[] = null;
                        continue;
                    }
                    $target = $element instanceof RestElement ? $element->argument : $element;
                    if (!$target instanceof Identifier) {
                        throw new CompilerBailout('complex array pattern');
                    }
                    $slot = $this->localSlots[$target->name];
                    $elements[] = [
                        'slot' => $slot,
                        'capturedName' => isset($this->capturedLocals[$target->name]) ? $target->name : null,
                        'rest' => $element instanceof RestElement,
                    ];
                    if ($node->kind === 'const') {
                        $this->constSlots[$slot] = true;
                    }
                }
                $this->compileExpression($decl->init);
                $index = count($this->arrayBindings);
                $this->arrayBindings[] = ['kind' => $node->kind, 'elements' => $elements];
                $this->emit(Op::BIND_ARRAY, $index);
                continue;
            }
            if ($decl->id instanceof ObjectPattern) {
                if ($decl->init === null) {
                    throw new CompilerBailout('destructure without init');
                }
                $count = count($decl->id->properties);
                if ($count === 0) {
                    // Empty pattern still requires the spec ToObject
                    // check on init (TypeError on null/undefined).
                    // Tree-walker handles the edge case; bail.
                    throw new CompilerBailout('empty object pattern');
                }
                $this->compileExpression($decl->init);
                $i = 0;
                foreach ($decl->id->properties as $prop) {
                    $i++;
                    if (!($prop instanceof \Phasis\Ast\Pattern\AssignmentProperty)) {
                        throw new CompilerBailout('rest in pattern');
                    }
                    if ($prop->computed) {
                        throw new CompilerBailout('computed key in pattern');
                    }
                    $target = $prop->value instanceof AssignmentPattern ? $prop->value->left : $prop->value;
                    if (!$target instanceof Identifier) {
                        throw new CompilerBailout('non-identifier pattern target');
                    }
                    $keyName = $prop->key instanceof Identifier
                        ? $prop->key->name
                        : ($prop->key instanceof Literal && is_string($prop->key->value)
                            ? $prop->key->value
                            : throw new CompilerBailout('weird pattern key'));
                    if ($i < $count) {
                        $this->emit(Op::DUP);
                    }
                    $this->emit(Op::LOAD_MEMBER, $this->internName($keyName));
                    if ($prop->value instanceof AssignmentPattern) {
                        if (self::initNeedsNamedEvaluation($prop->value->right)) {
                            throw new CompilerBailout('pattern initializer needs named evaluation');
                        }
                        $this->emit(Op::DUP);
                        $this->emit(Op::LOAD_UNDEF);
                        $this->emit(Op::SEQ);
                        $provided = $this->emitJump(Op::JUMP_IF_FALSE);
                        $this->emit(Op::POP);
                        $this->compileExpression($prop->value->right);
                        $this->patchJumpToHere($provided);
                    }
                    $slot = $this->localSlots[$target->name] ?? $this->declareLocal($target->name);
                    $this->emitInitializeLocal($slot, $node->kind);
                    if ($node->kind === 'const') {
                        $this->constSlots[$slot] = true;
                    }
                }
                continue;
            }
            if (!($decl->id instanceof Identifier)) {
                throw new CompilerBailout('destructuring var');
            }
            $name = $decl->id->name;
            // Program var bindings are shared with the global object.
            // Hoisting already created the binding, so only an initializer
            // emits a write; a declaration without one preserves its value.
            if ($node->kind === 'var' && isset($this->environmentVarNames[$name])) {
                if ($decl->init !== null) {
                    if (self::initNeedsNamedEvaluation($decl->init)) {
                        throw new CompilerBailout('var init needs named evaluation');
                    }
                    $this->compileExpression($decl->init);
                    $this->emit(Op::STORE_NAME, $this->internName($name));
                }
                continue;
            }
            $slot = $this->localSlots[$name] ?? $this->declareLocal($name);
            if ($decl->init !== null) {
                // NamedEvaluation: per spec 14.3.2.1, when the initializer
                // is an anonymous function/class definition, the declared
                // binding name becomes the function's .name. The bytecode
                // emit path stores the value verbatim with no rename hook,
                // so bail to the tree-walker (which calls JsFunction::setName).
                if (self::initNeedsNamedEvaluation($decl->init)) {
                    throw new CompilerBailout('var init needs named evaluation');
                }
                $this->compileExpression($decl->init);
                $this->emitInitializeLocal($slot, $node->kind);
            } else {
                if ($node->kind === 'const') {
                    throw new CompilerBailout('const without initializer');
                }
                if (
                    $node->kind === 'let'
                    && ($this->forOfBodyDepth > 0 || isset($this->capturedLocals[$name]) || isset($this->reusableBlockLocals[$name]))
                ) {
                    $this->emit(Op::LOAD_UNDEF);
                    $this->emitInitializeLocal($slot, $node->kind);
                }
            }
            if ($node->kind === 'const') {
                $this->constSlots[$slot] = true;
            }
        }
    }

    /**
     * Return true when the spec's IsAnonymousFunctionDefinition is
     * true for $init, i.e. a NamedEvaluation should fire on the
     * binding name. Anonymous function expressions, arrow functions
     * and anonymous class expressions all qualify.
     */
    private static function initNeedsNamedEvaluation(Node $init): bool
    {
        if (
            $init instanceof \Phasis\Ast\Expression\FunctionExpression
            && $init->name === null
        ) {
            return true;
        }
        if ($init instanceof \Phasis\Ast\Expression\ArrowFunction) {
            return true;
        }
        if (
            $init instanceof \Phasis\Ast\Expression\ClassExpression
            && $init->id === null
        ) {
            return true;
        }
        return false;
    }
}
