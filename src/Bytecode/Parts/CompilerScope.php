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

/**
 * Compiler trait part: CompilerScope. Composed into Compiler via
 * `use Parts\CompilerScope;`.
 */
trait CompilerScope
{
    /**
     * Captured names use the same Environment records as interpreted closures.
     * Only function-scope bindings are supported. Block and iteration captures
     * need distinct records and remain on the interpreter path.
     */
    private function collectCapturedLocals(Node $body): void
    {
        $references = [];
        $this->collectNestedLocalReferences($body, false, $references);
        if ($references === []) {
            return;
        }
        if ($this->inGenerator) {
            throw new CompilerBailout('generator captures local bindings');
        }
        $supported = [];
        foreach ($this->paramSlots as $slot) {
            $supported[$this->localNames[$slot]] = 'parameter';
        }
        foreach ($this->varDeclaredNames as $name => $_) {
            $supported[$name] = 'var';
        }
        if ($body instanceof BlockStatement) {
            foreach ($body->body as $statement) {
                if ($statement instanceof FunctionDeclaration && $statement->id !== null) {
                    $supported[$statement->id->name] = 'function';
                } elseif ($statement instanceof VariableDeclaration) {
                    foreach ($statement->declarations as $declaration) {
                        $bound = [];
                        $this->collectPatternBoundNames($declaration->id, $bound);
                        foreach ($bound as $name => $_) {
                            $supported[$name] = $statement->kind;
                        }
                    }
                }
            }
        }
        foreach ($references as $name => $_) {
            if (!isset($supported[$name])) {
                throw new CompilerBailout('closure captures block binding: ' . $name);
            }
            $this->capturedLocals[$name] = $supported[$name];
        }
    }

    /**
     * @param array<string, true> $references
     * @param array<string, bool> $shadow
     */
    private function collectNestedLocalReferences(Node $node, bool $nested, array &$references, array $shadow = []): void
    {
        if (
            $node instanceof AssignmentExpression
            && ($node->left instanceof ArrayPattern || $node->left instanceof ArrayExpression)
        ) {
            foreach ($node->left->elements as $element) {
                $target = $element instanceof RestElement || $element instanceof SpreadElement
                    ? $element->argument : $element;
                if ($target instanceof Node) {
                    // The iterator helper evaluates member references between
                    // steps. Reify their local reads and writes in the same
                    // environment used by nested interpreted closures.
                    $this->collectNestedLocalReferences(
                        $target,
                        $nested || $target instanceof MemberExpression,
                        $references,
                        $shadow,
                    );
                }
            }
            $this->collectNestedLocalReferences($node->right, $nested, $references, $shadow);
            return;
        }
        if ($node instanceof Identifier) {
            if ($nested && isset($this->localSlots[$node->name]) && !isset($shadow[$node->name])) {
                $references[$node->name] = true;
            }
            return;
        }
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
        ) {
            foreach ($node->params as $parameter) {
                $this->collectPatternBoundNames($parameter, $shadow);
            }
            if ($node instanceof \Phasis\Ast\Expression\FunctionExpression && $node->name !== null) {
                $shadow[$node->name] = true;
            }
            foreach ($node->params as $parameter) {
                $this->collectCapturePatternInitializers($parameter, true, $references, $shadow);
            }
            // Body var declarations cannot shadow reads from parameter defaults.
            $this->collectCaptureVarNames($node->body, $shadow);
            $this->collectNestedLocalReferences($node->body, true, $references, $shadow);
            return;
        }
        if ($nested && $node instanceof BlockStatement) {
            foreach ($node->body as $statement) {
                if ($statement instanceof VariableDeclaration && $statement->kind !== 'var') {
                    foreach ($statement->declarations as $declaration) {
                        $this->collectPatternBoundNames($declaration->id, $shadow);
                    }
                } elseif (
                    ($statement instanceof FunctionDeclaration || $statement instanceof \Phasis\Ast\Declaration\ClassDeclaration)
                    && $statement->id !== null
                ) {
                    $shadow[$statement->id->name] = true;
                }
            }
        }
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $declaration) {
                if ($declaration->init !== null) {
                    $this->collectNestedLocalReferences($declaration->init, $nested, $references, $shadow);
                }
                $this->collectCapturePatternInitializers($declaration->id, $nested, $references, $shadow);
            }
            return;
        }
        $nested = $nested
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
            || $node instanceof \Phasis\Ast\Declaration\ClassDeclaration;
        if ($node instanceof MemberExpression && !$node->computed) {
            $this->collectNestedLocalReferences($node->object, $nested, $references, $shadow);
            return;
        }
        if ($node instanceof Property && !$node->computed) {
            $this->collectNestedLocalReferences($node->value, $nested, $references, $shadow);
            return;
        }
        foreach (get_object_vars($node) as $value) {
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    $this->collectNestedLocalReferences($child, $nested, $references, $shadow);
                }
            }
        }
    }

    /**
     * @param array<string, true> $references
     * @param array<string, bool> $shadow
     */
    private function collectCapturePatternInitializers(Node $pattern, bool $nested, array &$references, array $shadow): void
    {
        if ($pattern instanceof AssignmentPattern) {
            $this->collectNestedLocalReferences($pattern->right, $nested, $references, $shadow);
            $this->collectCapturePatternInitializers($pattern->left, $nested, $references, $shadow);
        } elseif ($pattern instanceof ObjectPattern) {
            foreach ($pattern->properties as $property) {
                if ($property instanceof \Phasis\Ast\Pattern\AssignmentProperty) {
                    if ($property->computed) {
                        $this->collectNestedLocalReferences($property->key, $nested, $references, $shadow);
                    }
                    $this->collectCapturePatternInitializers($property->value, $nested, $references, $shadow);
                } elseif ($property instanceof RestElement) {
                    $this->collectCapturePatternInitializers($property->argument, $nested, $references, $shadow);
                }
            }
        } elseif ($pattern instanceof ArrayPattern) {
            foreach ($pattern->elements as $element) {
                if ($element !== null) {
                    $this->collectCapturePatternInitializers($element, $nested, $references, $shadow);
                }
            }
        } elseif ($pattern instanceof RestElement) {
            $this->collectCapturePatternInitializers($pattern->argument, $nested, $references, $shadow);
        }
    }

    /** @param array<string, bool> $shadow */
    private function collectCaptureVarNames(Node $node, array &$shadow): void
    {
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
            || $node instanceof \Phasis\Ast\Declaration\ClassDeclaration
        ) {
            return;
        }
        if ($node instanceof VariableDeclaration && $node->kind === 'var') {
            foreach ($node->declarations as $declaration) {
                $this->collectPatternBoundNames($declaration->id, $shadow);
            }
            return;
        }
        foreach (get_object_vars($node) as $value) {
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    $this->collectCaptureVarNames($child, $shadow);
                }
            }
        }
    }

    /** Record program var bindings while reserving only lexical temporary slots. */
    private function collectProgramVarLocals(Node $stmt): void
    {
        if ($stmt instanceof VariableDeclaration) {
            if ($stmt->kind !== 'var') {
                // compileProgram already validated; defensive only.
                throw new CompilerBailout('non-var top-level decl');
            }
            foreach ($stmt->declarations as $decl) {
                if (!($decl->id instanceof Identifier)) {
                    throw new CompilerBailout('top-level destructuring var');
                }
                $name = $decl->id->name;
                $this->varDeclaredNames[$name] = true;
                $this->environmentVarNames[$name] = true;
            }
            return;
        }
        if ($stmt instanceof FunctionDeclaration) {
            // Skip: pre-hoisted onto globalEnv by Interpreter::execute.
            return;
        }
        if ($stmt instanceof BlockStatement) {
            foreach ($stmt->body as $inner) {
                $this->collectProgramVarLocals($inner);
            }
            return;
        }
        if ($stmt instanceof IfStatement) {
            $this->collectProgramVarLocals($stmt->consequent);
            if ($stmt->alternate !== null) {
                $this->collectProgramVarLocals($stmt->alternate);
            }
            return;
        }
        if ($stmt instanceof ForStatement) {
            if ($stmt->init instanceof VariableDeclaration) {
                if ($stmt->init->kind !== 'var') {
                    throw new CompilerBailout('top-level let/const for-init');
                }
                foreach ($stmt->init->declarations as $decl) {
                    if (!($decl->id instanceof Identifier)) {
                        throw new CompilerBailout('top-level destructuring for-init');
                    }
                    $name = $decl->id->name;
                    $this->varDeclaredNames[$name] = true;
                    $this->environmentVarNames[$name] = true;
                }
            }
            $this->collectProgramVarLocals($stmt->body);
            return;
        }
        if ($stmt instanceof WhileStatement || $stmt instanceof DoWhileStatement) {
            $this->collectProgramVarLocals($stmt->body);
            return;
        }
        if ($stmt instanceof \Phasis\Ast\Statement\LabeledStatement) {
            $this->collectProgramVarLocals($stmt->body);
            return;
        }
        if ($stmt instanceof \Phasis\Ast\Statement\TryStatement) {
            foreach ($stmt->block->body as $inner) {
                $this->collectProgramVarLocals($inner);
            }
            if ($stmt->handler !== null) {
                if ($stmt->handler->param instanceof Identifier) {
                    $catchName = $stmt->handler->param->name;
                    if (isset($this->localSlots[$catchName]) || isset($this->environmentVarNames[$catchName])) {
                        throw new CompilerBailout('catch param shadows top-level var');
                    }
                    $this->declareLocal($catchName);
                }
                foreach ($stmt->handler->body->body as $inner) {
                    $this->collectProgramVarLocals($inner);
                }
            }
            if ($stmt->finalizer !== null) {
                foreach ($stmt->finalizer->body as $inner) {
                    $this->collectProgramVarLocals($inner);
                }
            }
            return;
        }
        // ExpressionStatement / ReturnStatement / ThrowStatement / etc.
        // introduce no new bindings; nothing to declare here.
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
        if ($stmt instanceof \Phasis\Ast\Statement\SwitchStatement) {
            $this->validateSwitchBindings($stmt);
            foreach ($stmt->cases as $case) {
                $this->collectFunctionLocals($case->consequent);
            }
            return;
        }
        if ($stmt instanceof \Phasis\Ast\Statement\ForOfStatement) {
            if ($this->forOfBody === null || !$stmt->left instanceof VariableDeclaration) {
                throw new CompilerBailout('for-of assignment target');
            }
            $this->validateForOfScopes($this->forOfBody);
            $this->collectStatementLocals($stmt->left);
            $this->collectStatementLocals($stmt->body);
            return;
        }
        if ($stmt instanceof VariableDeclaration) {
            $isLexical = $stmt->kind === 'let' || $stmt->kind === 'const';
            foreach ($stmt->declarations as $decl) {
                if ($decl->id instanceof Identifier) {
                    // let/const shadowing an existing slot (param or
                    // earlier var) would need separate slot allocation
                    // per scope, which the flat-slot compiler doesn't
                    // model. Bail.
                    if (
                        $isLexical && isset($this->localSlots[$decl->id->name])
                        && !isset($this->reusableBlockLocals[$decl->id->name])
                    ) {
                        throw new CompilerBailout('let/const shadowing existing local');
                    }
                    if (!$isLexical) {
                        $this->varDeclaredNames[$decl->id->name] = true;
                    }
                    $this->declareLocal($decl->id->name);
                    continue;
                }
                if ($decl->id instanceof ObjectPattern) {
                    foreach ($decl->id->properties as $prop) {
                        $target = $prop instanceof \Phasis\Ast\Pattern\AssignmentProperty ? $prop->value : null;
                        if ($target instanceof AssignmentPattern) {
                            $target = $target->left;
                        }
                        if (
                            $prop instanceof \Phasis\Ast\Pattern\AssignmentProperty
                            && !$prop->computed
                            && $target instanceof Identifier
                        ) {
                            if ($isLexical && isset($this->localSlots[$target->name])) {
                                throw new CompilerBailout('let/const pattern shadowing');
                            }
                            if (!$isLexical) {
                                $this->varDeclaredNames[$target->name] = true;
                            }
                            $this->declareLocal($target->name);
                            continue;
                        }
                        throw new CompilerBailout('complex object pattern');
                    }
                    continue;
                }
                if ($decl->id instanceof ArrayPattern) {
                    foreach ($decl->id->elements as $element) {
                        if ($element === null) {
                            continue;
                        }
                        $target = $element instanceof RestElement ? $element->argument : $element;
                        if (!$target instanceof Identifier) {
                            throw new CompilerBailout('complex array pattern');
                        }
                        if ($isLexical && isset($this->localSlots[$target->name])) {
                            throw new CompilerBailout('let/const pattern shadowing');
                        }
                        if (!$isLexical) {
                            $this->varDeclaredNames[$target->name] = true;
                        }
                        $this->declareLocal($target->name);
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
                $isLexical = $stmt->init->kind === 'let' || $stmt->init->kind === 'const';
                foreach ($stmt->init->declarations as $decl) {
                    if (!($decl->id instanceof Identifier)) {
                        throw new CompilerBailout('destructuring for-init');
                    }
                    if (!$isLexical) {
                        $this->varDeclaredNames[$decl->id->name] = true;
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
        if ($stmt instanceof \Phasis\Ast\Statement\LabeledStatement) {
            $this->collectStatementLocals($stmt->body);
            return;
        }
        if ($stmt instanceof \Phasis\Ast\Declaration\ClassDeclaration) {
            // Class declarations create a let-style binding under the
            // class name. Allocate a slot just like a function decl;
            // the actual class object is built by MAKE_CLASS at run
            // time and stored via STORE_LOCAL.
            if ($stmt->id !== null && !isset($this->localSlots[$stmt->id->name])) {
                $this->declareLocal($stmt->id->name);
            }
            return;
        }
        if ($stmt instanceof \Phasis\Ast\Statement\TryStatement) {
            $this->collectFunctionLocals($stmt->block->body);
            if ($stmt->handler !== null) {
                if ($stmt->handler->param instanceof \Phasis\Ast\Expression\Identifier) {
                    $catchName = $stmt->handler->param->name;
                    if (isset($this->localSlots[$catchName])) {
                        // catch (a) where `a` is already a local would
                        // alias the outer binding's slot. Per spec the
                        // catch param introduces a fresh block-scoped
                        // binding that shadows outer names; the slot-based
                        // compiler can't model that — bail to the tree
                        // walker.
                        throw new CompilerBailout('catch param shadows outer local');
                    }
                    $this->declareLocal($catchName);
                }
                $this->collectFunctionLocals($stmt->handler->body->body);
            }
            if ($stmt->finalizer !== null) {
                $this->collectFunctionLocals($stmt->finalizer->body);
            }
            return;
        }
        // ExpressionStatement / ReturnStatement / ThrowStatement / etc:
        // no new bindings.
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

    /**
     * Emit MAKE_FUNCTION + STORE_LOCAL for every top-level
     * FunctionDeclaration in the body — hoisted to the start so
     * forward references resolve. Each declaration's slot was
     * already reserved by collectFunctionLocals.
     *
     * Captured function-scope names resolve through their shared environment.
     * Unsupported captures and generator/async declarations still fall back.
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
            $name = $stmt->id->name;
            $idx = count($this->nestedFns);
            $this->nestedFns[] = $stmt;
            $this->emit(Op::MAKE_FUNCTION, $idx);
            $slot = $this->localSlots[$name] ?? $this->declareLocal($name);
            // Nested closures and self-references read the environment,
            // while this body can read the frame slot. Both must receive
            // the same function, including when interpreter hoisting was
            // deferred to this bytecode prologue.
            $this->emit(Op::DUP);
            $this->emit(Op::STORE_LOCAL, $slot);
            // DEFINE_NAME (not STORE_NAME): defineVar semantics, so it
            // overwrites any existing binding on the local env without
            // walking the chain. STORE_NAME with strict=true throws
            // ReferenceError when the binding doesn't yet exist higher
            // up — which can happen when ramda-style libraries declare
            // many top-level `function r(t,n){...}` shapes whose tree-
            // walker hoist binding got eclipsed or never created.
            $this->functionDefinitionSites[count($this->code)] = [$name, $slot];
            $this->emit(Op::DEFINE_NAME, $this->internName($name));
        }
    }

    /**
     * Lower a nested function or arrow expression into a
     * MAKE_FUNCTION opcode that the VM materialises into a
     * JsFunction at runtime, capturing the current Frame's env as
     * the closure.
     *
     * Captured function-scope names were assigned Environment storage by the
     * capture prepass. Any remaining slot capture needs scope semantics the
     * VM cannot represent and stays on the tree-walker.
     */
    private function compileNestedFunction(Node $node): void
    {
        if ($node instanceof \Phasis\Ast\Expression\FunctionExpression) {
            if ($node->generator || $node->async) {
                throw new CompilerBailout('nested generator/async fn');
            }
        }
        if ($node instanceof \Phasis\Ast\Expression\ArrowFunction) {
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
                if ($prop instanceof \Phasis\Ast\Pattern\AssignmentProperty) {
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
        if ($node instanceof \Phasis\Ast\Statement\SwitchStatement) {
            foreach ($node->cases as $case) {
                $this->collectInnerDeclaredNames($case->consequent, $out);
            }
            return;
        }
        if ($node instanceof \Phasis\Ast\Statement\ForOfStatement) {
            if ($node->left instanceof VariableDeclaration) {
                $this->collectInnerDeclaredNamesNode($node->left, $out);
            }
            $this->collectInnerDeclaredNamesNode($node->body, $out);
            return;
        }
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
