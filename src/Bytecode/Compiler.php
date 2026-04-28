<?php

declare(strict_types=1);

namespace PhpJs\Bytecode;

use PhpJs\Ast\Expression\ArrayExpression;
use PhpJs\Ast\Expression\AssignmentExpression;
use PhpJs\Ast\Expression\BinaryExpression;
use PhpJs\Ast\Expression\CallExpression;
use PhpJs\Ast\Expression\ConditionalExpression;
use PhpJs\Ast\Expression\Identifier;
use PhpJs\Ast\Expression\Literal;
use PhpJs\Ast\Expression\MemberExpression;
use PhpJs\Ast\Expression\NewExpression;
use PhpJs\Ast\Expression\ObjectExpression;
use PhpJs\Ast\Expression\PrivateIdentifier;
use PhpJs\Ast\Expression\Property;
use PhpJs\Ast\Expression\SpreadElement;
use PhpJs\Ast\Expression\TemplateElement;
use PhpJs\Ast\Expression\TemplateLiteral;
use PhpJs\Ast\Expression\ThisExpression;
use PhpJs\Ast\Node;
use PhpJs\Ast\Declaration\FunctionDeclaration;
use PhpJs\Ast\Declaration\VariableDeclaration;
use PhpJs\Ast\Expression\LogicalExpression;
use PhpJs\Ast\Expression\SequenceExpression;
use PhpJs\Ast\Expression\UnaryExpression;
use PhpJs\Ast\Expression\UpdateExpression;
use PhpJs\Ast\Pattern\ArrayPattern;
use PhpJs\Ast\Pattern\AssignmentPattern;
use PhpJs\Ast\Pattern\ObjectPattern;
use PhpJs\Ast\Pattern\RestElement;
use PhpJs\Ast\Statement\BlockStatement;
use PhpJs\Ast\Statement\BreakStatement;
use PhpJs\Ast\Statement\ContinueStatement;
use PhpJs\Ast\Statement\DoWhileStatement;
use PhpJs\Ast\Statement\EmptyStatement;
use PhpJs\Ast\Statement\ExpressionStatement;
use PhpJs\Ast\Statement\ForStatement;
use PhpJs\Ast\Statement\IfStatement;
use PhpJs\Ast\Statement\ReturnStatement;
use PhpJs\Ast\Statement\ThrowStatement;
use PhpJs\Ast\Statement\WhileStatement;
use PhpJs\Value\JsBigInt;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

/**
 * AST → bytecode lowering. Phase 2 supports the minimal subset
 * needed to run fib(): arithmetic, comparison, conditional
 * expression, return, simple identifier callee calls, parameter
 * locals, free-variable name lookups. Anything else throws
 * CompilerBailout and JsFunction falls back to the tree-walker.
 */
final class Compiler
{
    /** @var list<int> */
    private array $code = [];

    /** @var list<JsValue> */
    private array $consts = [];

    /** @var list<string> */
    private array $names = [];

    /** @var array<string,int> Reverse map for de-duping constants by string key. */
    private array $constIndex = [];

    /** @var array<string,int> Reverse map for de-duping names. */
    private array $nameIndex = [];

    /** @var list<string> Slot index → identifier name, for diagnostics. */
    private array $localNames = [];

    /** @var array<string,int> Identifier name → slot index. */
    private array $localSlots = [];

    /** @var list<int> */
    private array $paramSlots = [];

    /** @var list<\PhpJs\Ast\Node> */
    private array $nestedFns = [];

    /**
     * Slot indices that were declared `const`. Re-assignment to any
     * of these (by ordinary `=`, compound `+=`, or `++`/`--`) is a
     * TypeError per spec; the compiler bails so the tree-walker can
     * surface the spec-correct error.
     *
     * @var array<int,bool>
     */
    private array $constSlots = [];

    /**
     * Stack of (continueTargetPc, breakPatchOperandIndices). Each
     * loop / switch entry pushes; break/continue inside the body
     * read the top entry to emit jumps. Patched at scope close.
     *
     * @var list<array{continue: int, breaks: list<int>, continues: list<int>}>
     */
    private array $loopStack = [];

    /**
     * THROW opcode operand: a single int that the VM uses to look
     * up the right exception via thrown JsValue. Throw lowering
     * leaves the value on stack and emits THROW; the VM raises a
     * JsThrowable with that value.
     */

    public function compile(JsFunction $fn): CompiledFunction
    {
        if ($fn->isGenerator() || $fn->isAsync() || $fn->isNative() || $fn->isClassConstructor()) {
            throw new CompilerBailout('non-ordinary function kind');
        }
        $body = $fn->getBody();
        $isExpressionBody = !$body instanceof BlockStatement;
        if ($isExpressionBody && !$body instanceof Node) {
            throw new CompilerBailout('non-AST body');
        }

        // Reject bodies the tree-walker treats as "dynamic": eval
        // references, with statements, generators, etc. The caller
        // (Interpreter) already filters generators/async/etc. via
        // JsFunction flags, but eval/with require AST inspection.
        if ($isExpressionBody) {
            $this->scanBailout($body);
        } else {
            $this->ensureNoBailoutFeatures($body);
        }

        foreach ($fn->getParams() as $param) {
            if (!$param instanceof Identifier) {
                throw new CompilerBailout('non-simple parameter');
            }
            $slot = $this->declareLocal($param->name);
            $this->paramSlots[] = $slot;
        }

        if ($isExpressionBody) {
            // Arrow expression body: nothing to pre-walk (no var
            // decls allowed), no TDZ, no statements. Compile the
            // single expression directly.
            $this->compileExpression($body);
            $this->emit(Op::RET);
            $needsThis = in_array(Op::LOAD_THIS, $this->code, true);
            return new CompiledFunction(
                code: $this->code,
                consts: $this->consts,
                names: $this->names,
                localNames: $this->localNames,
                paramSlots: $this->paramSlots,
                slotCount: max(1, count($this->localNames)),
                nestedFns: $this->nestedFns,
                needsThis: $needsThis,
                needsArgsBinding: false,
                canSkipEnvAlloc: !$needsThis,
            );
        }

        // Pre-walk: collect every var/let/const/function name in
        // the body so each is assigned a unique frame slot up front.
        $this->collectFunctionLocals($body->body);

        // TDZ guard: bail if any let/const name appears in source
        // BEFORE its declaration.
        $this->ensureNoTdzViolations($body->body);

        // FunctionDeclaration hoisting: per spec, function decls bind
        // at the start of the enclosing function. Emit MAKE_FUNCTION
        // + STORE_LOCAL ahead of the regular statement loop so any
        // forward reference resolves correctly.
        $this->hoistFunctionDeclarations($body->body);

        foreach ($body->body as $stmt) {
            // Skip top-level FunctionDeclaration here — already
            // emitted above. Their identity must not be re-bound.
            if ($stmt instanceof FunctionDeclaration) {
                continue;
            }
            $this->compileStatement($stmt);
        }

        $this->emit(Op::LOAD_UNDEF);
        $this->emit(Op::RET);

        $needsThis = in_array(Op::LOAD_THIS, $this->code, true);
        return new CompiledFunction(
            code: $this->code,
            consts: $this->consts,
            names: $this->names,
            localNames: $this->localNames,
            paramSlots: $this->paramSlots,
            slotCount: max(1, count($this->localNames)),
            nestedFns: $this->nestedFns,
            needsThis: $needsThis,
            needsArgsBinding: false,
            canSkipEnvAlloc: !$needsThis,
        );
    }

    /**
     * Walk the body looking for features the compiler refuses to
     * handle. Mirrors the tree-walker's lazy-arguments / no-eval
     * guarantees so the VM's optimised resolution paths stay safe.
     *
     * @param BlockStatement $body
     */
    private function ensureNoBailoutFeatures(BlockStatement $body): void
    {
        $this->scanBailout($body->body);
    }

    /**
     * @param Node[]|Node|null $statements
     */
    private function scanBailout(mixed $statements): void
    {
        if ($statements === null) {
            return;
        }
        if (is_array($statements)) {
            foreach ($statements as $s) {
                $this->scanBailout($s);
            }
            return;
        }
        $node = $statements;
        if ($node instanceof Identifier && ($node->name === 'eval' || $node->name === 'arguments')) {
            // `arguments` in body forces argsObj construction; let
            // the tree-walker keep that path. `eval` is dynamic.
            throw new CompilerBailout('uses ' . $node->name);
        }
        if ($node instanceof Identifier && $node->name === '[[NewTarget]]') {
            // `new.target` reads need an env binding the VM-compiled
            // prologue skips. Bail to keep the prologue-skip optimization
            // safe for the common case.
            throw new CompilerBailout('uses new.target');
        }
        if ($node instanceof \PhpJs\Ast\Statement\WithStatement) {
            throw new CompilerBailout('with statement');
        }
        if ($node instanceof \PhpJs\Ast\Statement\TryStatement) {
            throw new CompilerBailout('try/catch'); // Phase later
        }
        if (
            $node instanceof \PhpJs\Ast\Expression\YieldExpression
            || $node instanceof \PhpJs\Ast\Expression\AwaitExpression
        ) {
            throw new CompilerBailout('yield/await');
        }
        // Don't recurse into nested function/arrow/class bodies —
        // each gets its own compile attempt at call time.
        if (
            $node instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $node instanceof \PhpJs\Ast\Expression\ArrowFunction
            || $node instanceof \PhpJs\Ast\Declaration\FunctionDeclaration
            || $node instanceof \PhpJs\Ast\Expression\ClassExpression
            || $node instanceof \PhpJs\Ast\Declaration\ClassDeclaration
        ) {
            return;
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node || is_array($value)) {
                $this->scanBailout($value);
            }
        }
    }

    /**
     * @param Node[] $statements
     */
    private function collectFunctionLocals(array $statements): void
    {
        foreach ($statements as $stmt) {
            $this->collectStatementLocals($stmt);
        }
    }

    private function collectStatementLocals(Node $stmt): void
    {
        if ($stmt instanceof VariableDeclaration) {
            $isLexical = $stmt->kind === 'let' || $stmt->kind === 'const';
            foreach ($stmt->declarations as $decl) {
                if ($decl->id instanceof Identifier) {
                    // let/const shadowing an existing slot (param or
                    // earlier var) would need separate slot allocation
                    // per scope, which the flat-slot compiler doesn't
                    // model. Bail.
                    if ($isLexical && isset($this->localSlots[$decl->id->name])) {
                        throw new CompilerBailout('let/const shadowing existing local');
                    }
                    $this->declareLocal($decl->id->name);
                    continue;
                }
                if ($decl->id instanceof ObjectPattern) {
                    foreach ($decl->id->properties as $prop) {
                        if (
                            $prop instanceof \PhpJs\Ast\Pattern\AssignmentProperty
                            && !$prop->computed
                            && !($prop->value instanceof \PhpJs\Ast\Pattern\AssignmentPattern)
                            && $prop->value instanceof Identifier
                        ) {
                            if ($isLexical && isset($this->localSlots[$prop->value->name])) {
                                throw new CompilerBailout('let/const pattern shadowing');
                            }
                            $this->declareLocal($prop->value->name);
                            continue;
                        }
                        throw new CompilerBailout('complex object pattern');
                    }
                    continue;
                }
                throw new CompilerBailout('destructuring declaration');
            }
            return;
        }
        if ($stmt instanceof FunctionDeclaration) {
            // Function declarations are hoisted: assign a slot for
            // the binding name. The VM materialises the JsFunction
            // value when the declaration statement runs.
            if ($stmt->id !== null) {
                $this->declareLocal($stmt->id->name);
            }
            return;
        }
        if ($stmt instanceof BlockStatement) {
            $this->collectFunctionLocals($stmt->body);
            return;
        }
        if ($stmt instanceof IfStatement) {
            $this->collectStatementLocals($stmt->consequent);
            if ($stmt->alternate !== null) {
                $this->collectStatementLocals($stmt->alternate);
            }
            return;
        }
        if ($stmt instanceof ForStatement) {
            if ($stmt->init instanceof VariableDeclaration) {
                foreach ($stmt->init->declarations as $decl) {
                    if (!($decl->id instanceof Identifier)) {
                        throw new CompilerBailout('destructuring for-init');
                    }
                    $this->declareLocal($decl->id->name);
                }
            }
            $this->collectStatementLocals($stmt->body);
            return;
        }
        if ($stmt instanceof WhileStatement || $stmt instanceof DoWhileStatement) {
            $this->collectStatementLocals($stmt->body);
            return;
        }
        if ($stmt instanceof \PhpJs\Ast\Statement\LabeledStatement) {
            $this->collectStatementLocals($stmt->body);
            return;
        }
        // ExpressionStatement / ReturnStatement / ThrowStatement / etc:
        // no new bindings.
    }

    /**
     * Bail if any let/const-declared name has a reference in the
     * function body that lexically precedes its declaration, since
     * the slot-based compiler can't enforce TDZ for those reads.
     *
     * @param Node[] $body
     */
    private function ensureNoTdzViolations(array $body): void
    {
        // Collect (name → declaration index) for every let/const at
        // any depth; index reflects source order across the flat
        // function-body sequence.
        $declOrder = [];
        $idx = 0;
        $this->collectLetConstOrder($body, $declOrder, $idx);
        if ($declOrder === []) {
            return;
        }
        // Walk the body again and gate every Identifier reference
        // against the declaration index. A reference whose visit
        // index is < the declaration index is a TDZ candidate.
        $idx = 0;
        $this->checkTdzReferences($body, $declOrder, $idx);
    }

    /**
     * @param Node[] $body
     * @param array<string,int> $out
     */
    private function collectLetConstOrder(array $body, array &$out, int &$idx): void
    {
        foreach ($body as $stmt) {
            $this->collectLetConstOrderNode($stmt, $out, $idx);
        }
    }

    /**
     * @param array<string,int> $out
     */
    private function collectLetConstOrderNode(Node $node, array &$out, int &$idx): void
    {
        $idx++;
        if ($node instanceof VariableDeclaration && ($node->kind === 'let' || $node->kind === 'const')) {
            // Per spec, the binding is in TDZ until the init
            // expression has fully executed. Visit the init first so
            // its identifier indices are < the declaration index.
            foreach ($node->declarations as $decl) {
                if ($decl->init !== null) {
                    $this->collectLetConstOrderNode($decl->init, $out, $idx);
                }
                if ($decl->id instanceof Identifier && !isset($out[$decl->id->name])) {
                    $idx++;
                    $out[$decl->id->name] = $idx;
                }
            }
            return;
        }
        // Stop at function/class boundaries — separate scope.
        if (
            $node instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $node instanceof \PhpJs\Ast\Expression\ArrowFunction
            || $node instanceof FunctionDeclaration
            || $node instanceof \PhpJs\Ast\Expression\ClassExpression
            || $node instanceof \PhpJs\Ast\Declaration\ClassDeclaration
        ) {
            return;
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                $this->collectLetConstOrderNode($value, $out, $idx);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $this->collectLetConstOrderNode($item, $out, $idx);
                    }
                }
            }
        }
    }

    /**
     * @param Node[] $body
     * @param array<string,int> $declOrder
     */
    private function checkTdzReferences(array $body, array $declOrder, int &$idx): void
    {
        foreach ($body as $stmt) {
            $this->checkTdzReferencesNode($stmt, $declOrder, $idx);
        }
    }

    /**
     * @param array<string,int> $declOrder
     */
    private function checkTdzReferencesNode(Node $node, array $declOrder, int &$idx): void
    {
        $idx++;
        $myIdx = $idx;
        if ($node instanceof Identifier && isset($declOrder[$node->name])) {
            if ($myIdx < $declOrder[$node->name]) {
                throw new CompilerBailout('let/const used before declaration: ' . $node->name);
            }
        }
        if ($node instanceof VariableDeclaration && ($node->kind === 'let' || $node->kind === 'const')) {
            // Mirror collectLetConstOrder's traversal: visit the init
            // first so identifier indices inside it are < the
            // declaration index. Skip the binding-id Identifier of
            // each declarator (that's the new name, not a reference).
            foreach ($node->declarations as $decl) {
                if ($decl->init !== null) {
                    $this->checkTdzReferencesNode($decl->init, $declOrder, $idx);
                }
                if ($decl->id instanceof Identifier) {
                    $idx++;
                } elseif ($decl->id instanceof Node) {
                    // Destructuring decl id — bailout already triggers.
                    $this->checkTdzReferencesNode($decl->id, $declOrder, $idx);
                }
            }
            return;
        }
        if (
            $node instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $node instanceof \PhpJs\Ast\Expression\ArrowFunction
            || $node instanceof FunctionDeclaration
            || $node instanceof \PhpJs\Ast\Expression\ClassExpression
            || $node instanceof \PhpJs\Ast\Declaration\ClassDeclaration
        ) {
            // Closures: any reference to outer let/const we haven't
            // yet declared would race with slot init at call time.
            $this->scanIdentifiersForTdz($node, $declOrder, $myIdx);
            return;
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                $this->checkTdzReferencesNode($value, $declOrder, $idx);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $this->checkTdzReferencesNode($item, $declOrder, $idx);
                    }
                }
            }
        }
    }

    /**
     * Scan an inner closure for identifier references to outer let/
     * const bindings; if any are still pre-declaration, bail.
     *
     * @param array<string,int> $declOrder
     */
    private function scanIdentifiersForTdz(Node $node, array $declOrder, int $closurePos): void
    {
        if ($node instanceof Identifier) {
            if (isset($declOrder[$node->name]) && $closurePos < $declOrder[$node->name]) {
                throw new CompilerBailout('inner closure refs let/const pre-decl: ' . $node->name);
            }
            return;
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                $this->scanIdentifiersForTdz($value, $declOrder, $closurePos);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $this->scanIdentifiersForTdz($item, $declOrder, $closurePos);
                    }
                }
            }
        }
    }

    private function declareLocal(string $name): int
    {
        if (isset($this->localSlots[$name])) {
            return $this->localSlots[$name];
        }
        $slot = count($this->localNames);
        $this->localNames[] = $name;
        $this->localSlots[$name] = $slot;
        return $slot;
    }

    private function emit(int $op, int ...$operands): void
    {
        $this->code[] = $op;
        foreach ($operands as $o) {
            $this->code[] = $o;
        }
    }

    /**
     * Reserve a placeholder operand for a forward jump and return its
     * index so the caller can patch it once the target offset is known.
     */
    private function emitJump(int $op): int
    {
        $this->code[] = $op;
        $this->code[] = 0; // placeholder
        return count($this->code) - 1;
    }

    private function patchJumpToHere(int $operandIndex): void
    {
        // Operand semantics: pc += operand. The dispatcher reads the
        // operand at $pc + 1 then advances $pc += operand. So the
        // operand is "from the opcode pc to the target pc". Compute
        // target - opcode_pc.
        $opcodePc = $operandIndex - 1;
        $targetPc = count($this->code);
        $this->code[$operandIndex] = $targetPc - $opcodePc;
    }

    private function compileStatement(Node $node): void
    {
        if ($node instanceof ReturnStatement) {
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
            $idx = $this->emitJump(Op::JUMP);
            $this->loopStack[count($this->loopStack) - 1]['breaks'][] = $idx;
            return;
        }
        if ($node instanceof ContinueStatement) {
            if ($node->label !== null) {
                throw new CompilerBailout('labeled continue');
            }
            if ($this->loopStack === []) {
                throw new CompilerBailout('continue outside loop');
            }
            $idx = $this->emitJump(Op::JUMP);
            $this->loopStack[count($this->loopStack) - 1]['continues'][] = $idx;
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
        throw new CompilerBailout('unsupported statement: ' . $node->type());
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
     * Detect the canonical for-loop test `local < numericLiteral` and
     * emit a single fused JUMP_IF_LOCAL_GE_CONST opcode. Returns the
     * opcode pc on success (used by compileFor to patch the offset
     * cell at opcodePc + 3 directly, since patchJumpToHere assumes a
     * 1-operand jump). Returns -1 if the pattern does not apply and
     * the caller should fall back to LT + JUMP_IF_FALSE.
     */
    private function tryEmitFusedLoopTest(\PhpJs\Ast\Node $test): int
    {
        if (!$test instanceof BinaryExpression || $test->operator !== '<') {
            return -1;
        }
        $left = $test->left;
        $right = $test->right;
        if (!$left instanceof Identifier) {
            return -1;
        }
        if (!isset($this->localSlots[$left->name])) {
            return -1;
        }
        if (!$right instanceof Literal || (!is_int($right->value) && !is_float($right->value))) {
            return -1;
        }
        $localSlot = $this->localSlots[$left->name];
        $constIdx = $this->internConst(
            new JsNumber((float) $right->value),
            'n:' . $right->value,
        );
        $opcodePc = count($this->code);
        // Layout: opcode, localSlot, constIdx, offset (patched later).
        $this->code[] = Op::JUMP_IF_LOCAL_GE_CONST;
        $this->code[] = $localSlot;
        $this->code[] = $constIdx;
        $this->code[] = 0; // placeholder offset
        return $opcodePc;
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
                    if (!($prop instanceof \PhpJs\Ast\Pattern\AssignmentProperty)) {
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
                    $this->emit(Op::STORE_LOCAL, $slot);
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
            $slot = $this->localSlots[$name] ?? $this->declareLocal($name);
            if ($decl->init !== null) {
                $this->compileExpression($decl->init);
                $this->emit(Op::STORE_LOCAL, $slot);
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

    private function compileExpression(Node $node): void
    {
        if ($node instanceof Literal) {
            $this->compileLiteral($node);
            return;
        }
        if ($node instanceof Identifier) {
            $this->compileIdentifier($node);
            return;
        }
        if ($node instanceof BinaryExpression) {
            $this->compileBinary($node);
            return;
        }
        if ($node instanceof ConditionalExpression) {
            $this->compileConditional($node);
            return;
        }
        if ($node instanceof CallExpression) {
            $this->compileCall($node);
            return;
        }
        if ($node instanceof MemberExpression) {
            $this->compileMember($node);
            return;
        }
        if ($node instanceof AssignmentExpression) {
            $this->compileAssignment($node);
            return;
        }
        if ($node instanceof ThisExpression) {
            $this->emit(Op::LOAD_THIS);
            return;
        }
        if ($node instanceof UpdateExpression) {
            $this->compileUpdate($node);
            return;
        }
        if ($node instanceof UnaryExpression) {
            $this->compileUnary($node);
            return;
        }
        if ($node instanceof LogicalExpression) {
            $this->compileLogical($node);
            return;
        }
        if ($node instanceof SequenceExpression) {
            $count = count($node->expressions);
            for ($i = 0; $i < $count; $i++) {
                $this->compileExpression($node->expressions[$i]);
                if ($i !== $count - 1) {
                    $this->emit(Op::POP);
                }
            }
            return;
        }
        if ($node instanceof ObjectExpression) {
            $this->compileObjectLiteral($node);
            return;
        }
        if ($node instanceof ArrayExpression) {
            $this->compileArrayLiteral($node);
            return;
        }
        if ($node instanceof TemplateLiteral) {
            $this->compileTemplate($node);
            return;
        }
        if ($node instanceof NewExpression) {
            $this->compileNew($node);
            return;
        }
        if (
            $node instanceof \PhpJs\Ast\Expression\ArrowFunction
            || $node instanceof \PhpJs\Ast\Expression\FunctionExpression
        ) {
            $this->compileNestedFunction($node);
            return;
        }
        throw new CompilerBailout('unsupported expression: ' . $node->type());
    }

    /**
     * Emit MAKE_FUNCTION + STORE_LOCAL for every top-level
     * FunctionDeclaration in the body — hoisted to the start so
     * forward references resolve. Each declaration's slot was
     * already reserved by collectFunctionLocals.
     *
     * Bails if the declaration captures an outer local (its inner
     * body would fail the env-walk) or is a generator / async
     * function (those still need the tree-walker's full prologue).
     *
     * @param Node[] $statements
     */
    private function hoistFunctionDeclarations(array $statements): void
    {
        foreach ($statements as $stmt) {
            if (!($stmt instanceof FunctionDeclaration) || $stmt->id === null) {
                continue;
            }
            if ($stmt->generator || $stmt->async) {
                throw new CompilerBailout('hoisted generator/async fn');
            }
            if ($this->capturesOuterLocal($stmt)) {
                throw new CompilerBailout('hoisted fn captures outer local');
            }
            $idx = count($this->nestedFns);
            $this->nestedFns[] = $stmt;
            $this->emit(Op::MAKE_FUNCTION, $idx);
            $slot = $this->localSlots[$stmt->id->name] ?? $this->declareLocal($stmt->id->name);
            $this->emit(Op::STORE_LOCAL, $slot);
        }
    }

    /**
     * Lower a nested function or arrow expression into a
     * MAKE_FUNCTION opcode that the VM materialises into a
     * JsFunction at runtime, capturing the current Frame's env as
     * the closure.
     *
     * Capture safety: the outer function's locals live in frame
     * slots, NOT in the env. A nested function that references any
     * outer local would walk the env chain at call time and not
     * find it. Bail when such a capture exists so the tree-walker
     * keeps owning the outer function (which uses env-based
     * bindings the inner closure can read).
     */
    private function compileNestedFunction(Node $node): void
    {
        if ($node instanceof \PhpJs\Ast\Expression\FunctionExpression) {
            if ($node->generator || $node->async) {
                throw new CompilerBailout('nested generator/async fn');
            }
        }
        if ($node instanceof \PhpJs\Ast\Expression\ArrowFunction) {
            if ($node->async) {
                throw new CompilerBailout('nested async arrow');
            }
        }
        if ($this->capturesOuterLocal($node)) {
            throw new CompilerBailout('nested fn captures outer local');
        }
        $idx = count($this->nestedFns);
        $this->nestedFns[] = $node;
        $this->emit(Op::MAKE_FUNCTION, $idx);
    }

    /**
     * Walk a nested function's params + body for any Identifier
     * reference to a name in the OUTER function's local slots that
     * the nested function does not itself shadow. Returns true if
     * any such capture exists.
     */
    private function capturesOuterLocal(Node $node): bool
    {
        // Collect names declared INSIDE the nested fn — those shadow
        // outer locals and don't constitute a capture.
        $shadow = [];
        if ($node instanceof \PhpJs\Ast\Expression\FunctionExpression) {
            $params = $node->params;
            $body = $node->body;
            if ($node->name !== null) {
                // Named function expression's own name shadows
                // outer bindings inside the body.
                $shadow[$node->name] = true;
            }
        } elseif ($node instanceof \PhpJs\Ast\Expression\ArrowFunction) {
            $params = $node->params;
            $body = $node->body;
        } else {
            return true; // unknown shape — bail conservatively
        }
        foreach ($params as $param) {
            $this->collectPatternBoundNames($param, $shadow);
        }
        if ($body instanceof BlockStatement) {
            $this->collectInnerDeclaredNames($body->body, $shadow);
        }
        return $this->scanForOuterCapture($body, $shadow);
    }

    /**
     * @param array<string,bool> $out
     */
    private function collectPatternBoundNames(Node $pattern, array &$out): void
    {
        if ($pattern instanceof Identifier) {
            $out[$pattern->name] = true;
            return;
        }
        if ($pattern instanceof AssignmentPattern) {
            $this->collectPatternBoundNames($pattern->left, $out);
            return;
        }
        if ($pattern instanceof RestElement) {
            $this->collectPatternBoundNames($pattern->argument, $out);
            return;
        }
        if ($pattern instanceof ArrayPattern) {
            foreach ($pattern->elements as $elem) {
                if ($elem instanceof Node) {
                    $this->collectPatternBoundNames($elem, $out);
                }
            }
            return;
        }
        if ($pattern instanceof ObjectPattern) {
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof \PhpJs\Ast\Pattern\AssignmentProperty) {
                    $this->collectPatternBoundNames($prop->value, $out);
                } elseif ($prop instanceof RestElement) {
                    $this->collectPatternBoundNames($prop->argument, $out);
                }
            }
            return;
        }
    }

    /**
     * @param Node[] $statements
     * @param array<string,bool> $out
     */
    private function collectInnerDeclaredNames(array $statements, array &$out): void
    {
        foreach ($statements as $stmt) {
            $this->collectInnerDeclaredNamesNode($stmt, $out);
        }
    }

    /**
     * @param array<string,bool> $out
     */
    private function collectInnerDeclaredNamesNode(Node $node, array &$out): void
    {
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $decl) {
                $this->collectPatternBoundNames($decl->id, $out);
            }
            return;
        }
        if ($node instanceof FunctionDeclaration && $node->id !== null) {
            $out[$node->id->name] = true;
            return;
        }
        if ($node instanceof BlockStatement) {
            foreach ($node->body as $s) {
                $this->collectInnerDeclaredNamesNode($s, $out);
            }
            return;
        }
        if ($node instanceof IfStatement) {
            $this->collectInnerDeclaredNamesNode($node->consequent, $out);
            if ($node->alternate !== null) {
                $this->collectInnerDeclaredNamesNode($node->alternate, $out);
            }
            return;
        }
        if ($node instanceof ForStatement) {
            if ($node->init instanceof VariableDeclaration) {
                foreach ($node->init->declarations as $d) {
                    $this->collectPatternBoundNames($d->id, $out);
                }
            }
            $this->collectInnerDeclaredNamesNode($node->body, $out);
            return;
        }
        if ($node instanceof WhileStatement || $node instanceof DoWhileStatement) {
            $this->collectInnerDeclaredNamesNode($node->body, $out);
            return;
        }
    }

    /**
     * @param array<string,bool> $shadow
     */
    private function scanForOuterCapture(?Node $node, array $shadow): bool
    {
        if ($node === null) {
            return false;
        }
        if ($node instanceof Identifier) {
            $name = $node->name;
            if (
                isset($this->localSlots[$name])
                && !isset($shadow[$name])
            ) {
                return true;
            }
            return false;
        }
        // Don't recurse into deeper nested functions — they'll bail
        // independently when their compile attempt happens.
        if (
            $node instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $node instanceof \PhpJs\Ast\Expression\ArrowFunction
            || $node instanceof FunctionDeclaration
            || $node instanceof \PhpJs\Ast\Expression\ClassExpression
            || $node instanceof \PhpJs\Ast\Declaration\ClassDeclaration
        ) {
            // Even so, the inner-inner closure may reference names
            // from THIS scope — its compile will catch it. But for
            // the OUTER's safety, also check directly: if the
            // inner-inner refs match this scope's locals, that's a
            // capture too.
            return $this->capturesOuterLocal($node);
        }
        $ref = new \ReflectionObject($node);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                if ($this->scanForOuterCapture($value, $shadow)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && $this->scanForOuterCapture($item, $shadow)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private function compileObjectLiteral(ObjectExpression $node): void
    {
        $this->emit(Op::NEW_OBJECT);
        // Stack: [obj]
        foreach ($node->properties as $prop) {
            if ($prop instanceof SpreadElement) {
                throw new CompilerBailout('spread in object literal');
            }
            if (!$prop instanceof Property) {
                throw new CompilerBailout('object literal: ' . get_class($prop));
            }
            if ($prop->kind !== 'init') {
                throw new CompilerBailout('getter/setter literal');
            }
            if ($prop->method) {
                throw new CompilerBailout('method shorthand');
            }
            if ($prop->computed) {
                // [expr]: val — push key, push val, SET_COMPUTED.
                $this->compileExpression($prop->key);
                $this->compileExpression($prop->value);
                $this->emit(Op::SET_COMPUTED);
                continue;
            }
            // Static name (Identifier or string Literal).
            if ($prop->key instanceof Identifier) {
                $name = $prop->key->name;
            } elseif ($prop->key instanceof Literal) {
                if (!is_string($prop->key->value) && !is_int($prop->key->value) && !is_float($prop->key->value)) {
                    throw new CompilerBailout('non-string-or-numeric literal key');
                }
                $name = (string) $prop->key->value;
            } else {
                throw new CompilerBailout('non-identifier non-literal key');
            }
            // `__proto__: value` is the special prototype-set sugar
            // when shorthand=false (method=false has already bailed
            // above); the tree-walker handles it via setPrototype rather
            // than defining a property. Bail to keep spec semantics.
            if ($name === '__proto__' && !$prop->shorthand) {
                throw new CompilerBailout('__proto__ literal');
            }
            $this->compileExpression($prop->value);
            $this->emit(Op::SET_PROP, $this->internName($name));
        }
        // Stack: [obj] (unchanged net by SET_PROP / SET_COMPUTED).
    }

    private function compileArrayLiteral(ArrayExpression $node): void
    {
        // Use NEW_ARRAY <count> for dense literals with no holes /
        // spread. Sparse / spread arrays bail.
        $count = count($node->elements);
        $hasHoles = false;
        $hasSpread = false;
        foreach ($node->elements as $el) {
            if ($el === null) {
                $hasHoles = true;
            } elseif ($el instanceof SpreadElement) {
                $hasSpread = true;
            }
        }
        if ($hasHoles || $hasSpread) {
            throw new CompilerBailout('array literal with hole/spread');
        }
        foreach ($node->elements as $el) {
            $this->compileExpression($el);
        }
        $this->emit(Op::NEW_ARRAY, $count);
    }

    private function compileTemplate(TemplateLiteral $node): void
    {
        // Untagged template: alternate quasis and expressions, build a
        // string by concatenation. ToString happens via ADD's string
        // path when one operand is a string.
        $quasis = $node->quasis;
        $expressions = $node->expressions;
        $count = count($quasis);
        if ($count === 0) {
            $this->emit(Op::LOAD_CONST, $this->internConst(new JsString(''), 's:'));
            return;
        }
        for ($i = 0; $i < $count; $i++) {
            $part = $quasis[$i];
            $cookedRaw = $part->cookedValue;
            if ($cookedRaw === null) {
                throw new CompilerBailout('template element with invalid escape');
            }
            $idx = $this->internConst(new JsString($cookedRaw), 's:' . $cookedRaw);
            $this->emit(Op::LOAD_CONST, $idx);
            if ($i > 0) {
                // After the first quasi, concatenate with whatever
                // is already on the stack from the previous expr.
                $this->emit(Op::ADD);
            }
            if ($i < count($expressions)) {
                $this->compileExpression($expressions[$i]);
                $this->emit(Op::ADD);
            }
        }
    }

    private function compileNew(NewExpression $node): void
    {
        foreach ($node->arguments as $arg) {
            if ($arg instanceof SpreadElement) {
                throw new CompilerBailout('spread in new');
            }
        }
        $this->compileExpression($node->callee);
        foreach ($node->arguments as $arg) {
            $this->compileExpression($arg);
        }
        $this->emit(Op::NEW_CALL, count($node->arguments));
    }

    /**
     * Compile `i++` / `i--` / `++i` / `--i` when the result is unused
     * (ExpressionStatement at top level, ForStatement::update slot) into
     * a single INC_LOCAL / DEC_LOCAL op. Returns true on success so the
     * caller can skip the standard compile-then-pop path.
     */
    private function tryEmitDiscardedUpdate(\PhpJs\Ast\Node $expr): bool
    {
        if (!$expr instanceof UpdateExpression) {
            return false;
        }
        $arg = $expr->argument;
        if (!$arg instanceof Identifier) {
            return false;
        }
        $name = $arg->name;
        if ($name === 'eval' || $name === 'arguments' || $name === '[[NewTarget]]') {
            return false;
        }
        if (!isset($this->localSlots[$name])) {
            return false;
        }
        $slot = $this->localSlots[$name];
        if (isset($this->constSlots[$slot])) {
            return false;
        }
        $this->emit($expr->operator === '++' ? Op::INC_LOCAL : Op::DEC_LOCAL, $slot);
        return true;
    }

    private function compileUpdate(UpdateExpression $node): void
    {
        $arg = $node->argument;
        $isInc = $node->operator === '++';
        if ($arg instanceof Identifier) {
            $name = $arg->name;
            if ($name === 'eval' || $name === 'arguments') {
                throw new CompilerBailout('update eval/arguments');
            }
            if (isset($this->localSlots[$name])) {
                $slot = $this->localSlots[$name];
                if (isset($this->constSlots[$slot])) {
                    throw new CompilerBailout('update of const');
                }
                $this->emit(Op::LOAD_LOCAL, $slot);
                if (!$node->prefix) {
                    $this->emit(Op::DUP);
                }
                $oneIdx = $this->internConst(new JsNumber(1.0), 'n:1');
                $this->emit(Op::LOAD_CONST, $oneIdx);
                $this->emit($isInc ? Op::ADD : Op::SUB);
                if ($node->prefix) {
                    $this->emit(Op::DUP);
                }
                $this->emit(Op::STORE_LOCAL, $slot);
                return;
            }
            // Free variable: bail to tree-walker (Reference path is
            // spec-correct for Update on names that may resolve via
            // a with-environment etc.).
            throw new CompilerBailout('update free var');
        }
        // Member targets (`o.x++`, `o[k]++`) come in a later phase.
        throw new CompilerBailout('update non-identifier');
    }

    private function compileUnary(UnaryExpression $node): void
    {
        if (!$node->prefix) {
            throw new CompilerBailout('postfix unary');
        }
        $op = $node->operator;
        // `delete`, `typeof` on identifiers, `void` are easy enough,
        // but the spec edge cases (delete on with-bindings, typeof
        // on undeclared) are subtle. Bail to tree-walker for safety.
        if ($op === 'delete' || $op === 'typeof') {
            throw new CompilerBailout('delete/typeof');
        }
        if ($op === 'void') {
            $this->compileExpression($node->argument);
            $this->emit(Op::POP);
            $this->emit(Op::LOAD_UNDEF);
            return;
        }
        $this->compileExpression($node->argument);
        switch ($op) {
            case '-':
                $this->emit(Op::NEG);
                return;
            case '+':
                $this->emit(Op::POS);
                return;
            case '!':
                $this->emit(Op::NOT);
                return;
            case '~':
                $this->emit(Op::BNOT);
                return;
            default:
                throw new CompilerBailout('unary: ' . $op);
        }
    }

    private function compileLogical(LogicalExpression $node): void
    {
        // && and ||: short-circuit by peeking.
        if ($node->operator === '&&' || $node->operator === '||') {
            $this->compileExpression($node->left);
            $jmp = $node->operator === '&&'
                ? $this->emitJump(Op::JUMP_IF_FALSY_KEEP)
                : $this->emitJump(Op::JUMP_IF_TRUTHY_KEEP);
            $this->compileExpression($node->right);
            $this->patchJumpToHere($jmp);
            return;
        }
        if ($node->operator === '??') {
            // VM's JUMP_IF_NULLISH pops on jump and keeps on fall-
            // through, so the @nullish path starts with a clean stack
            // and can compile right directly.
            $this->compileExpression($node->left);
            $jmp = $this->emitJump(Op::JUMP_IF_NULLISH);
            // not nullish: left already on stack; skip right.
            $jmpEnd = $this->emitJump(Op::JUMP);
            $this->patchJumpToHere($jmp);
            $this->compileExpression($node->right);
            $this->patchJumpToHere($jmpEnd);
            return;
        }
        throw new CompilerBailout('logical: ' . $node->operator);
    }

    private function compileLiteral(Literal $node): void
    {
        $value = $node->value;
        if ($value === null) {
            $this->emit(Op::LOAD_NULL);
            return;
        }
        if (is_bool($value)) {
            $this->emit($value ? Op::LOAD_TRUE : Op::LOAD_FALSE);
            return;
        }
        if (is_int($value) || is_float($value)) {
            $idx = $this->internConst(new JsNumber((float) $value), 'n:' . $value);
            $this->emit(Op::LOAD_CONST, $idx);
            return;
        }
        if (is_string($value)) {
            // RegExp literal: bail — fresh regex per evaluation, plus
            // BigInt literal sharing the string slot.
            if (str_starts_with($node->raw, '__REGEXP__')) {
                throw new CompilerBailout('regexp literal');
            }
            if (str_starts_with($node->raw, '__BIGINT__')) {
                throw new CompilerBailout('bigint literal');
            }
            $idx = $this->internConst(new JsString($value), 's:' . $value);
            $this->emit(Op::LOAD_CONST, $idx);
            return;
        }
        throw new CompilerBailout('unknown literal kind');
    }

    private function compileIdentifier(Identifier $node): void
    {
        $name = $node->name;
        if ($name === 'undefined') {
            $this->emit(Op::LOAD_UNDEF);
            return;
        }
        if (isset($this->localSlots[$name])) {
            $this->emit(Op::LOAD_LOCAL, $this->localSlots[$name]);
            return;
        }
        // Free variable — let the VM walk the env via the existing
        // (already cached) Environment::get path.
        $this->emit(Op::LOAD_NAME, $this->internName($name));
    }

    private function compileBinary(BinaryExpression $node): void
    {
        // Bail on operators that need special left-side handling (in,
        // instanceof, with-private-field) for now. They're not on the
        // hot path of fib / loop microbenchmarks.
        $op = $node->operator;
        $opcode = match ($op) {
            '+'   => Op::ADD,
            '-'   => Op::SUB,
            '*'   => Op::MUL,
            '/'   => Op::DIV,
            '%'   => Op::MOD,
            '**'  => Op::POW,
            '<'   => Op::LT,
            '>'   => Op::GT,
            '<='  => Op::LE,
            '>='  => Op::GE,
            '=='  => Op::EQ,
            '!='  => Op::NEQ,
            '===' => Op::SEQ,
            '!==' => Op::SNEQ,
            '&'   => Op::BAND,
            '|'   => Op::BOR,
            '^'   => Op::BXOR,
            '<<'  => Op::SHL,
            '>>'  => Op::SHR,
            '>>>' => Op::USHR,
            default => throw new CompilerBailout('binary operator: ' . $op),
        };
        $this->compileExpression($node->left);
        $this->compileExpression($node->right);
        $this->emit($opcode);
    }

    private function compileConditional(ConditionalExpression $node): void
    {
        $this->compileExpression($node->test);
        $jmpElse = $this->emitJump(Op::JUMP_IF_FALSE);
        $this->compileExpression($node->consequent);
        $jmpEnd = $this->emitJump(Op::JUMP);
        $this->patchJumpToHere($jmpElse);
        $this->compileExpression($node->alternate);
        $this->patchJumpToHere($jmpEnd);
    }

    private function compileCall(CallExpression $node): void
    {
        // No optional calls / direct eval / spread args yet.
        if ($node->optional) {
            throw new CompilerBailout('optional call');
        }
        if ($node->callee instanceof Identifier && $node->callee->name === 'eval') {
            throw new CompilerBailout('direct eval');
        }
        if ($node->callee instanceof Identifier && $node->callee->name === 'super') {
            throw new CompilerBailout('super call');
        }
        foreach ($node->arguments as $arg) {
            if ($arg instanceof SpreadElement) {
                throw new CompilerBailout('spread call argument');
            }
        }

        // Method call: `obj.m(...)` and `obj[k](...)`. Emit
        // CALL_METHOD with the receiver on the stack so the dispatcher
        // can use it as `this`.
        if (
            $node->callee instanceof MemberExpression
            && !$node->callee->optional
            && !($node->callee->property instanceof PrivateIdentifier)
            && !($node->callee->object instanceof Identifier && $node->callee->object->name === 'super')
        ) {
            if ($node->callee->computed) {
                throw new CompilerBailout('computed method call');
            }
            if (!($node->callee->property instanceof Identifier)) {
                throw new CompilerBailout('non-identifier method name');
            }
            // Spec evaluation order: look up the method (and surface
            // any TypeError on null/undefined receivers) BEFORE
            // evaluating any arguments. Stack layout:
            //   [..., obj, obj]                via DUP
            //   [..., obj, method]             via LOAD_MEMBER
            //   [..., obj, method, arg0..argN] after arg compile
            // CALL_METHOD argc pops method + args, peeks obj as
            // `this`, leaves result.
            $this->compileExpression($node->callee->object);
            $this->emit(Op::DUP);
            $this->emit(Op::LOAD_MEMBER, $this->internName($node->callee->property->name));
            foreach ($node->arguments as $arg) {
                $this->compileExpression($arg);
            }
            $this->emit(Op::CALL_METHOD, count($node->arguments));
            return;
        }

        // Direct function call: callee is anything except a member
        // expression (i.e. `f(...)` not `obj.m(...)`).
        $this->compileExpression($node->callee);
        foreach ($node->arguments as $arg) {
            $this->compileExpression($arg);
        }
        $this->emit(Op::CALL, count($node->arguments));
    }

    private function compileMember(MemberExpression $node): void
    {
        if ($node->optional) {
            throw new CompilerBailout('optional member');
        }
        if ($node->object instanceof Identifier && $node->object->name === 'super') {
            throw new CompilerBailout('super member access');
        }
        if ($node->property instanceof PrivateIdentifier) {
            throw new CompilerBailout('private field access');
        }
        if ($node->computed) {
            $this->compileExpression($node->object);
            $this->compileExpression($node->property);
            $this->emit(Op::LOAD_COMPUTED);
            return;
        }
        if (!($node->property instanceof Identifier)) {
            throw new CompilerBailout('non-identifier member property');
        }
        $this->compileExpression($node->object);
        $this->emit(Op::LOAD_MEMBER, $this->internName($node->property->name));
    }

    private function compileAssignment(AssignmentExpression $node): void
    {
        if ($node->leftParenthesized) {
            throw new CompilerBailout('parenthesized lhs');
        }
        $left = $node->left;
        $op = $node->operator;

        // Compound op (`+=`, `-=`, ...) gets lowered to:
        //   load lhs; compile rhs; <op>; <store-keep>
        // Logical compound (`&&=` / `||=` / `??=`) goes to the
        // tree-walker because of subtle short-circuit semantics
        // around with-environments.
        $binaryOpcode = match ($op) {
            '='   => null,
            '+='  => Op::ADD,
            '-='  => Op::SUB,
            '*='  => Op::MUL,
            '/='  => Op::DIV,
            '%='  => Op::MOD,
            '**=' => Op::POW,
            '&='  => Op::BAND,
            '|='  => Op::BOR,
            '^='  => Op::BXOR,
            '<<=' => Op::SHL,
            '>>=' => Op::SHR,
            '>>>='=> Op::USHR,
            default => throw new CompilerBailout('logical compound assignment'),
        };

        if ($left instanceof Identifier) {
            $name = $left->name;
            if ($name === 'eval' || $name === 'arguments') {
                throw new CompilerBailout('assign to eval/arguments');
            }
            $isLocal = isset($this->localSlots[$name]);

            if ($isLocal && isset($this->constSlots[$this->localSlots[$name]])) {
                throw new CompilerBailout('assignment to const');
            }
            if ($op === '=') {
                $this->compileExpression($node->right);
                $this->emit(Op::DUP);
                if ($isLocal) {
                    $this->emit(Op::STORE_LOCAL, $this->localSlots[$name]);
                } else {
                    $this->emit(Op::STORE_NAME, $this->internName($name));
                }
                return;
            }
            // Compound on identifier:
            //   load lhs; compile rhs; <op>; DUP; STORE_*
            if ($isLocal) {
                $this->emit(Op::LOAD_LOCAL, $this->localSlots[$name]);
            } else {
                $this->emit(Op::LOAD_NAME, $this->internName($name));
            }
            $this->compileExpression($node->right);
            $this->emit($binaryOpcode);
            $this->emit(Op::DUP);
            if ($isLocal) {
                $this->emit(Op::STORE_LOCAL, $this->localSlots[$name]);
            } else {
                $this->emit(Op::STORE_NAME, $this->internName($name));
            }
            return;
        }

        if ($left instanceof MemberExpression) {
            if ($left->optional) {
                throw new CompilerBailout('optional assign');
            }
            if ($left->object instanceof Identifier && $left->object->name === 'super') {
                throw new CompilerBailout('super assign');
            }
            if ($left->property instanceof PrivateIdentifier) {
                throw new CompilerBailout('private assign');
            }
            if ($left->computed) {
                throw new CompilerBailout('computed assign');
            }
            if (!($left->property instanceof Identifier)) {
                throw new CompilerBailout('non-identifier assign target');
            }
            $nameIdx = $this->internName($left->property->name);

            if ($op === '=') {
                $this->compileExpression($left->object);
                $this->compileExpression($node->right);
                $this->emit(Op::STORE_MEMBER, $nameIdx);
                return;
            }
            // Compound on `obj.x`:
            //   compile obj; DUP; LOAD_MEMBER name; compile rhs;
            //   <op>; STORE_MEMBER name
            // Stack at STORE_MEMBER: [..., obj, newVal] → [..., newVal]
            $this->compileExpression($left->object);
            $this->emit(Op::DUP);
            $this->emit(Op::LOAD_MEMBER, $nameIdx);
            $this->compileExpression($node->right);
            $this->emit($binaryOpcode);
            $this->emit(Op::STORE_MEMBER, $nameIdx);
            return;
        }

        throw new CompilerBailout('unsupported assignment target');
    }

    private function internConst(JsValue $value, string $key): int
    {
        if (isset($this->constIndex[$key])) {
            return $this->constIndex[$key];
        }
        $idx = count($this->consts);
        $this->consts[] = $value;
        $this->constIndex[$key] = $idx;
        return $idx;
    }

    private function internName(string $name): int
    {
        if (isset($this->nameIndex[$name])) {
            return $this->nameIndex[$name];
        }
        $idx = count($this->names);
        $this->names[] = $name;
        $this->nameIndex[$name] = $idx;
        return $idx;
    }
}
