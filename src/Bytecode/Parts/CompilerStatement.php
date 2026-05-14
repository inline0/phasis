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
            if ($this->finallyLoopBoundaries !== []) {
                throw new CompilerBailout('return inside try-with-finally');
            }
            if ($node->argument === null) {
                $this->emit(Op::LOAD_UNDEF);
            } else {
                $this->compileExpression($node->argument);
            }
            $this->emit(Op::RET);
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
            if ($this->loopStack === []) {
                throw new CompilerBailout('continue outside loop');
            }
            $loopDepth = count($this->loopStack);
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

    /**
     * Lower a TryStatement to a bytecode block plus handler-table
     * entries. Three shapes are supported:
     *
     *  - try / catch (Phase 1): one handler protecting the try body;
     *    on throw, the VM stores the value in the catch param slot
     *    and jumps to the catch body. Normal exit JUMPs past catch.
     *
     *  - try / finally (Phase 2): the finally body is inlined twice —
     *    once on the normal exit path, once on the exception-rethrow
     *    path that runs after the handler captures the thrown value.
     *
     *  - try / catch / finally (Phase 2): both handlers are emitted.
     *    Handler1 covers the try body and routes the exception
     *    through the catch body, then to the normal-finally inline
     *    (the catch already swallowed the original exception).
     *    Handler2 covers the catch body and routes any catch-body
     *    exception to the rethrow-finally inline so the catch's
     *    exception still surfaces after finally runs.
     *
     * Phase 2 enforces (via finallyLoopBoundaries / finallyDepth) that
     * no return / break / continue escapes the protected blocks; those
     * cases bail to the tree-walker because the inlined-finally
     * approach would otherwise need to inline at every exit point.
     */
    private function compileTryCatch(\Phasis\Ast\Statement\TryStatement $node): void
    {
        $hasFinally = $node->finalizer !== null;
        $hasCatch = $node->handler !== null;
        $stackBase = $this->tryEntryStackDepth;
        $finallyBoundary = count($this->loopStack);

        // Phase 1 fast path: try / catch with no finalizer. The
        // implementation is the original Phase 1 layout.
        if (!$hasFinally) {
            $tryStart = count($this->code);
            foreach ($node->block->body as $stmt) {
                $this->compileStatement($stmt);
            }
            $tryEnd = count($this->code);
            $jmpPastCatch = $this->emitJump(Op::JUMP);
            $catchPc = count($this->code);
            $exceptionSlot = -1;
            if ($node->handler->param instanceof \Phasis\Ast\Expression\Identifier) {
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

        // Phase 2: finalizer present (with or without catch).
        // Layout (annotated PCs):
        //   [tryStart..tryEnd)          : try body
        //   tryEnd                      : JUMP -> finallyNormalPc
        //   tryHandlerCatchPc           : (catch present) catch param
        //                                 mirror + catch body, then
        //                                 JUMP -> finallyNormalPc
        //                                 (no catch) JUMP -> finallyRethrowPc
        //   catchHandlerCatchPc         : JUMP -> finallyRethrowPc
        //   finallyNormalPc             : finally body, JUMP -> afterTryPc
        //   finallyRethrowPc            : finally body, LOAD rethrowSlot, THROW
        //   afterTryPc                  : continuation
        $rethrowSlot = count($this->localNames);
        $this->localNames[] = '[[finallyRethrow]]';

        $tryStart = count($this->code);
        $this->finallyLoopBoundaries[] = $finallyBoundary;
        foreach ($node->block->body as $stmt) {
            $this->compileStatement($stmt);
        }
        array_pop($this->finallyLoopBoundaries);
        $tryEnd = count($this->code);
        $jmpFromTry = $this->emitJump(Op::JUMP);

        $tryHandlerCatchPc = count($this->code);
        $tryHandlerExceptionSlot = -1;
        $catchStart = -1;
        $catchEnd = -1;
        $jmpFromCatch = -1;
        $jmpFromTryHandlerNoCatch = -1;

        if ($hasCatch) {
            // Handler1 stores the thrown value in the catch param
            // slot. Mirror it into rethrowSlot so a catch-body throw
            // can later observe its OWN exception, not the original.
            if ($node->handler->param instanceof \Phasis\Ast\Expression\Identifier) {
                $name = $node->handler->param->name;
                $tryHandlerExceptionSlot = $this->localSlots[$name]
                    ?? $this->declareLocal($name);
                $this->emit(Op::LOAD_LOCAL, $tryHandlerExceptionSlot);
                $this->emit(Op::STORE_LOCAL, $rethrowSlot);
            } else {
                // No catch param: still need to seed rethrowSlot from
                // the param slot so handler2 can re-throw on
                // catch-body exceptions. Allocate a temp.
                $tryHandlerExceptionSlot = count($this->localNames);
                $this->localNames[] = '[[catchTmp]]';
                $this->emit(Op::LOAD_LOCAL, $tryHandlerExceptionSlot);
                $this->emit(Op::STORE_LOCAL, $rethrowSlot);
            }
            $catchStart = count($this->code);
            $this->finallyLoopBoundaries[] = $finallyBoundary;
            foreach ($node->handler->body->body as $stmt) {
                $this->compileStatement($stmt);
            }
            array_pop($this->finallyLoopBoundaries);
            $catchEnd = count($this->code);
            $jmpFromCatch = $this->emitJump(Op::JUMP);
        } else {
            // No catch: handler1 stores the thrown value directly in
            // rethrowSlot, then jumps to the rethrow-finally inline.
            $tryHandlerExceptionSlot = $rethrowSlot;
            $jmpFromTryHandlerNoCatch = $this->emitJump(Op::JUMP);
        }

        // Handler2 (only meaningful if there's a catch): catches
        // exceptions thrown inside the catch body and routes them
        // through finally before re-raising.
        $catchHandlerCatchPc = $hasCatch ? count($this->code) : -1;
        $jmpFromHandler2 = -1;
        if ($hasCatch) {
            $jmpFromHandler2 = $this->emitJump(Op::JUMP);
        }

        // Finally inlines.
        $finallyNormalPc = count($this->code);
        $this->finallyLoopBoundaries[] = $finallyBoundary;
        foreach ($node->finalizer->body as $stmt) {
            $this->compileStatement($stmt);
        }
        array_pop($this->finallyLoopBoundaries);
        $jmpAfterTry = $this->emitJump(Op::JUMP);

        $finallyRethrowPc = count($this->code);
        $this->finallyLoopBoundaries[] = $finallyBoundary;
        foreach ($node->finalizer->body as $stmt) {
            $this->compileStatement($stmt);
        }
        array_pop($this->finallyLoopBoundaries);
        $this->emit(Op::LOAD_LOCAL, $rethrowSlot);
        $this->emit(Op::THROW);

        // afterTry. patchJumpToHere targets the current PC; the
        // jmpAfterTry was emitted right before this comment so it
        // already lines up.
        $this->patchJumpToHere($jmpAfterTry);
        $this->code[$jmpFromTry] = $finallyNormalPc - ($jmpFromTry - 1);
        if ($jmpFromCatch !== -1) {
            $this->code[$jmpFromCatch] = $finallyNormalPc - ($jmpFromCatch - 1);
        }
        if ($jmpFromTryHandlerNoCatch !== -1) {
            $this->code[$jmpFromTryHandlerNoCatch] =
                $finallyRethrowPc - ($jmpFromTryHandlerNoCatch - 1);
        }
        if ($jmpFromHandler2 !== -1) {
            $this->code[$jmpFromHandler2] =
                $finallyRethrowPc - ($jmpFromHandler2 - 1);
        }

        $this->handlers[] = new HandlerEntry(
            tryStart: $tryStart,
            tryEnd: $tryEnd,
            catchPc: $tryHandlerCatchPc,
            exceptionSlot: $tryHandlerExceptionSlot,
            stackBase: $stackBase,
        );
        if ($hasCatch) {
            $this->handlers[] = new HandlerEntry(
                tryStart: $catchStart,
                tryEnd: $catchEnd,
                catchPc: $catchHandlerCatchPc,
                exceptionSlot: $rethrowSlot,
                stackBase: $stackBase,
            );
        }
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
        if (!$left instanceof Identifier || !isset($this->localSlots[$left->name])) {
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
        if ($right instanceof Identifier && isset($this->localSlots[$right->name])) {
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
        $loopStart = count($this->code);
        $this->compileExpression($node->test);
        $jmpExit = $this->emitJump(Op::JUMP_IF_FALSE);
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
        $loopStart = count($this->code);
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
                    if (!($prop->value instanceof Identifier)) {
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
                    $slot = $this->localSlots[$prop->value->name] ?? $this->declareLocal($prop->value->name);
                    $this->emitStoreLocal($slot);
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
            // §B.3.2 var-vs-function-decl name conflict at program scope:
            // collectProgramVarLocals deliberately left this name OFF the
            // localSlots table because the FunctionDeclaration hoists it
            // onto globalEnv. Emit the var binding via the env path so
            // a later read sees the hoisted function rather than an
            // undefined frame-slot. Pure declarations (no init) become
            // a no-op because the FD hoist already created the binding;
            // declarations with an initializer (e.g. `var f = 1; function
            // f() {}` — initializer order matters per source-order
            // execution) emit a STORE_NAME so the var-init clobbers the
            // FD-installed value, matching the tree-walker's behaviour
            // where statement-list evaluation reaches the var initializer
            // after both have been hoisted.
            if ($node->kind === 'var' && isset($this->fnDeclShadowedVarNames[$name])) {
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
                $this->emitStoreLocal($slot);
            } else {
                if ($node->kind === 'const') {
                    throw new CompilerBailout('const without initializer');
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
