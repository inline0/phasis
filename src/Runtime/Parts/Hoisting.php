<?php

declare(strict_types=1);

namespace Phasis\Runtime\Parts;

use Phasis\Ast\Declaration\ClassDeclaration;
use Phasis\Ast\Declaration\ExportDeclaration;
use Phasis\Ast\Declaration\FunctionDeclaration;
use Phasis\Ast\Declaration\ImportDeclaration;
use Phasis\Ast\Declaration\VariableDeclaration;
use Phasis\Ast\Declaration\VariableDeclarator;
use Phasis\Ast\Expression\ArrayExpression;
use Phasis\Ast\Expression\ArrowFunction;
use Phasis\Ast\Expression\AssignmentExpression;
use Phasis\Ast\Expression\AwaitExpression;
use Phasis\Ast\Expression\BinaryExpression;
use Phasis\Ast\Expression\CallExpression;
use Phasis\Ast\Expression\ClassExpression;
use Phasis\Ast\Expression\ClassMethod;
use Phasis\Ast\Expression\ClassProperty;
use Phasis\Ast\Expression\PrivateIdentifier;
use Phasis\Ast\Expression\StaticBlock;
use Phasis\Ast\Expression\ConditionalExpression;
use Phasis\Ast\Expression\FunctionExpression;
use Phasis\Ast\Expression\ImportExpression;
use Phasis\Ast\Expression\MetaProperty;
use Phasis\Ast\Expression\Identifier;
use Phasis\Ast\Expression\Literal;
use Phasis\Ast\Expression\LogicalExpression;
use Phasis\Ast\Expression\MemberExpression;
use Phasis\Ast\Expression\NewExpression;
use Phasis\Ast\Expression\ObjectExpression;
use Phasis\Ast\Expression\Property;
use Phasis\Ast\Expression\SequenceExpression;
use Phasis\Ast\Expression\SpreadElement;
use Phasis\Ast\Expression\TaggedTemplate;
use Phasis\Ast\Expression\TemplateLiteral;
use Phasis\Ast\Expression\ThisExpression;
use Phasis\Ast\Expression\UnaryExpression;
use Phasis\Ast\Expression\UpdateExpression;
use Phasis\Ast\Expression\YieldExpression;
use Phasis\Ast\Node;
use Phasis\Ast\Pattern\ArrayPattern;
use Phasis\Ast\Pattern\AssignmentPattern;
use Phasis\Ast\Pattern\AssignmentProperty;
use Phasis\Ast\Pattern\ObjectPattern;
use Phasis\Ast\Pattern\RestElement;
use Phasis\Ast\Program;
use Phasis\Ast\Statement\BlockStatement;
use Phasis\Ast\Statement\BreakStatement;
use Phasis\Ast\Statement\ContinueStatement;
use Phasis\Ast\Statement\DebuggerStatement;
use Phasis\Ast\Statement\DoWhileStatement;
use Phasis\Ast\Statement\EmptyStatement;
use Phasis\Ast\Statement\ExpressionStatement;
use Phasis\Ast\Statement\ForInStatement;
use Phasis\Ast\Statement\ForOfStatement;
use Phasis\Ast\Statement\ForStatement;
use Phasis\Ast\Statement\IfStatement;
use Phasis\Ast\Statement\LabeledStatement;
use Phasis\Ast\Statement\ReturnStatement;
use Phasis\Ast\Statement\SwitchCase;
use Phasis\Ast\Statement\SwitchStatement;
use Phasis\Ast\Statement\ThrowStatement;
use Phasis\Ast\Statement\TryStatement;
use Phasis\Ast\Statement\WhileStatement;
use Phasis\Ast\Statement\WithStatement;
use Phasis\Exceptions\InternalError;
use Phasis\Exceptions\ReferenceError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Spec\AbstractOperations;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBigInt;
use Phasis\Value\JsBoolean;
use Phasis\Value\GeneratorReturnSignal;
use Phasis\Value\GeneratorThrowSignal;
use Phasis\Value\JsAsyncGenerator;
use Phasis\Value\JsFunction;
use Phasis\Value\JsGenerator;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsArgumentsObject;
use Phasis\Value\JsObject;
use Phasis\Value\JsOptionalUndefined;
use Phasis\Value\JsProxy;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\Runtime\Environment;

/**
 * Interpreter part: Hoisting. Composed into Interpreter via
 * `use Parts\Hoisting;`. `self::`/`$this->` references resolve
 * into the composing class.
 */
trait Hoisting
{
    // -------------------------------------------------------------------------
    // Hoisting
    // -------------------------------------------------------------------------

    /** @param Node[] $statements */
    private function hoistDeclarations(array $statements, Environment $env): void
    {
        // Collect top-level lexical names (let/const) so hoistBlockFunctionDeclarations
        // can skip names that would conflict per B.3.3.1 step ii.
        $lexicalNames = [];
        if (!$this->strictMode) {
            foreach ($statements as $s) {
                if (
                    $s instanceof VariableDeclaration && (
                    $s->kind === 'let' || $s->kind === 'const'
                    || $s->kind === 'using' || $s->kind === 'await using'
                    )
                ) {
                    foreach ($s->declarations as $d) {
                        foreach ($this->patternBoundNames($d->id) as $n) {
                            $lexicalNames[$n] = true;
                        }
                    }
                }
            }
        }

        foreach ($statements as $stmt) {
            // Unwrap export declarations to hoist their inner declaration.
            if ($stmt instanceof ExportDeclaration && $stmt->declaration !== null) {
                $stmt = $stmt->declaration;
            }
            if ($stmt instanceof FunctionDeclaration && $stmt->id !== null) {
                $fn = new JsFunction(
                    $stmt->id->name,
                    $stmt->params,
                    $stmt->body,
                    $env,
                    isGenerator: $stmt->generator,
                    isAsync: $stmt->async,
                    strict: $this->strictMode,
                );
                if ($stmt->sourceText !== null) {
                    $fn->setSourceText($stmt->sourceText);
                }
                $fn->definingModulePath = $this->currentModulePath;
                $this->installFunctionPrototype($fn, $stmt->generator, $stmt->async);
                // At global scope, function declarations are enumerable, non-configurable properties.
                // In nested scopes (env has no linked object), use defineVar as usual.
                if ($env->getLinkedObject() !== null) {
                    $env->defineGlobalVar($stmt->id->name, $fn);
                } else {
                    $env->defineVar($stmt->id->name, $fn);
                }
            } elseif ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
                foreach ($stmt->declarations as $decl) {
                    $this->hoistVarNames($decl->id, $env);
                }
            } elseif ($stmt instanceof ForOfStatement || $stmt instanceof ForInStatement) {
                // Hoist var declarations from for-of/for-in headers.
                if ($stmt->left instanceof VariableDeclaration && $stmt->left->kind === 'var') {
                    foreach ($stmt->left->declarations as $decl) {
                        $this->hoistVarNames($decl->id, $env);
                    }
                }
                // Recurse into for-of/for-in body for nested var hoisting only.
                if ($stmt->body instanceof \Phasis\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->body->body, $env);
                } else {
                    $this->hoistVarDeclarationsOnly([$stmt->body], $env);
                }
            } elseif ($stmt instanceof ForStatement) {
                // Hoist var declarations from for-statement init.
                if ($stmt->init instanceof VariableDeclaration && $stmt->init->kind === 'var') {
                    foreach ($stmt->init->declarations as $decl) {
                        $this->hoistVarNames($decl->id, $env);
                    }
                }
                if ($stmt->body instanceof \Phasis\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->body->body, $env);
                } else {
                    $this->hoistVarDeclarationsOnly([$stmt->body], $env);
                }
            } elseif (
                $stmt instanceof \Phasis\Ast\Statement\WhileStatement
                || $stmt instanceof \Phasis\Ast\Statement\DoWhileStatement
            ) {
                if ($stmt->body instanceof \Phasis\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->body->body, $env);
                } elseif ($stmt->body instanceof VariableDeclaration && $stmt->body->kind === 'var') {
                    // Handle non-block body: `do var x; while(false);`
                    foreach ($stmt->body->declarations as $decl) {
                        $this->hoistVarNames($decl->id, $env);
                    }
                } else {
                    // Handle non-block, non-var body (e.g. a single statement).
                    $this->hoistVarDeclarationsOnly([$stmt->body], $env);
                }
            } elseif ($stmt instanceof \Phasis\Ast\Statement\IfStatement) {
                // Only hoist var declarations from if/else block bodies.
                // Function declarations in if/else are block-scoped per ES2015+.
                if ($stmt->consequent instanceof \Phasis\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->consequent->body, $env);
                }
                if ($stmt->alternate instanceof \Phasis\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->alternate->body, $env);
                } elseif ($stmt->alternate instanceof \Phasis\Ast\Statement\IfStatement) {
                    $this->hoistDeclarations([$stmt->alternate], $env);
                }
            } elseif ($stmt instanceof \Phasis\Ast\Statement\BlockStatement) {
                $this->hoistVarDeclarationsOnly($stmt->body, $env);
            } elseif ($stmt instanceof TryStatement) {
                // Hoist var declarations from try, catch, and finally blocks.
                $this->hoistVarDeclarationsOnly($stmt->block->body, $env);
                if ($stmt->handler !== null) {
                    $this->hoistVarDeclarationsOnly($stmt->handler->body->body, $env);
                }
                if ($stmt->finalizer !== null) {
                    $this->hoistVarDeclarationsOnly($stmt->finalizer->body, $env);
                }
            } elseif ($stmt instanceof SwitchStatement) {
                // Hoist var declarations from switch case bodies. Function
                // declarations in switch are block-scoped; only the var binding
                // name is hoisted via hoistBlockFunctionDeclarations.
                foreach ($stmt->cases as $case) {
                    foreach ($case->consequent as $inner) {
                        if ($inner instanceof VariableDeclaration && $inner->kind === 'var') {
                            $this->hoistDeclarations([$inner], $env);
                        } elseif (!($inner instanceof FunctionDeclaration)) {
                            $this->hoistVarDeclarationsOnly(
                                $inner instanceof \Phasis\Ast\Statement\BlockStatement ? $inner->body : [$inner],
                                $env,
                            );
                        }
                    }
                }
            } elseif ($stmt instanceof LabeledStatement) {
                // Recurse into labeled statement body for var hoisting.
                $this->hoistDeclarations([$stmt->body], $env);
            } elseif ($stmt instanceof WithStatement) {
                // Var declarations inside with statements hoist to the enclosing scope.
                if ($stmt->body instanceof \Phasis\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->body->body, $env);
                } else {
                    $this->hoistDeclarations([$stmt->body], $env);
                }
            }

            // Annex B: in sloppy mode, hoist function declaration names from
            // nested blocks (if, for, while, etc.) to the enclosing scope.
            // Skip when processing catch bodies (handled at function scope level).
            if (!$this->strictMode && !$this->skipAnnexBHoisting) {
                $this->hoistBlockFunctionDeclarations($stmt, $env, $lexicalNames);
            }
        }
    }

    /**
     * Hoist declarations in non-strict, non-global eval code.
     *
     * Per EvalDeclarationInstantiation step 15/16, var and function bindings
     * created by eval in a local (function) scope are deletable. This means
     * they use CreateMutableBinding(name, true) so that `delete name` works.
     *
     * @param Node[] $statements
     */
    private function hoistEvalLocalDeclarations(array $statements, Environment $env, ?Environment $lexEnv = null): void
    {
        // Per Annex B.3.4, a top-level LabeledStatement wrapping a
        // FunctionDeclaration is treated as a plain FunctionDeclaration
        // for hoisting purposes. Unwrap labels (which can nest) up front
        // so the rest of this method sees the FunctionDeclaration directly.
        $unwrapLabels = function (Node $stmt): Node {
            while ($stmt instanceof LabeledStatement) {
                $stmt = $stmt->body;
            }
            return $stmt;
        };

        // Collect declared function and var names for Annex B step a check.
        $declaredFuncOrVarNames = [];
        foreach ($statements as $stmt) {
            $stmt = $unwrapLabels($stmt);
            if ($stmt instanceof FunctionDeclaration) {
                $declaredFuncOrVarNames[$stmt->id->name] = true;
            } elseif ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
                foreach ($stmt->declarations as $decl) {
                    foreach ($this->patternBoundNames($decl->id) as $n) {
                        $declaredFuncOrVarNames[$n] = true;
                    }
                }
            }
        }

        // Collect top-level lexical names that block Annex B hoisting.
        $lexicalNames = [];
        foreach ($statements as $stmt) {
            if (
                $stmt instanceof VariableDeclaration && (
                $stmt->kind === 'let' || $stmt->kind === 'const'
                || $stmt->kind === 'using' || $stmt->kind === 'await using'
                )
            ) {
                foreach ($stmt->declarations as $d) {
                    foreach ($this->patternBoundNames($d->id) as $n) {
                        $lexicalNames[$n] = true;
                    }
                }
            }
        }

        // Per EvalDeclarationInstantiation step 16: function objects close
        // over the eval's lexEnv (so they can see let/const declared inside
        // the eval), even though the binding lives in varEnv.
        $funcParentEnv = $lexEnv ?? $env;
        foreach ($statements as $stmt) {
            $stmt = $unwrapLabels($stmt);
            if ($stmt instanceof FunctionDeclaration) {
                $fn = new JsFunction(
                    $stmt->id->name,
                    $stmt->params,
                    $stmt->body,
                    $funcParentEnv,
                    isGenerator: $stmt->generator,
                    isAsync: $stmt->async,
                    strict: $this->strictMode,
                );
                if ($stmt->sourceText !== null) {
                    $fn->setSourceText($stmt->sourceText);
                }
                $this->installFunctionPrototype($fn, $stmt->generator, $stmt->async);
                // Eval-created local function bindings are deletable per spec.
                // If a binding already exists, just update its value.
                if ($env->hasOwnBinding($stmt->id->name)) {
                    $env->set($stmt->id->name, $fn);
                } else {
                    $env->defineDeletable($stmt->id->name, $fn);
                }
            } elseif ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
                foreach ($stmt->declarations as $decl) {
                    $this->hoistEvalLocalVarNames($decl->id, $env);
                }
            } else {
                $this->hoistEvalLocalVarCompound($stmt, $env);
            }
        }

        // Annex B.3.3.3: Hoist function declarations from blocks/if/switch
        // in eval code to the variable environment.
        if (!$this->strictMode) {
            $annexBDecls = $this->collectEvalAnnexBFunctions(
                $statements,
                $declaredFuncOrVarNames,
                $lexicalNames,
            );
            foreach ($annexBDecls as $decl) {
                $name = $decl->id->name;
                // Check if replacing with var would produce early errors.
                if ($this->evalAnnexBWouldProduceEarlyError($statements, $name, $decl)) {
                    continue;
                }
                // Mark for runtime update (step b).
                $this->annexBEligible[spl_object_id($decl)] = true;
                // Step a: create binding if not already in declaredFuncOrVarNames.
                if (!isset($declaredFuncOrVarNames[$name])) {
                    if (!$env->has($name)) {
                        $env->defineDeletable($name, JsUndefined::instance());
                        $env->markAnnexBHoisted($name);
                    } else {
                        // Binding exists (e.g. from function parameter). Still mark
                        // as annexB hoisted so runtime update can find it.
                        $env->markAnnexBHoisted($name);
                    }
                } else {
                    // Name is in declaredFuncOrVarNames: binding already exists
                    // from the regular hoisting above. Mark it so runtime update
                    // can find it.
                    $env->markAnnexBHoisted($name);
                }
            }
        }
    }

    /**
     * Hoist a var name from eval local code as a deletable binding.
     */
    private function hoistEvalLocalVarNames(Node $pattern, Environment $env): void
    {
        if ($pattern instanceof Identifier) {
            // Create binding even if name exists in an outer scope.
            if (!$env->hasOwnBinding($pattern->name)) {
                $env->defineDeletable($pattern->name, JsUndefined::instance());
            }
        } elseif ($pattern instanceof ArrayPattern) {
            foreach ($pattern->elements as $elem) {
                if ($elem !== null) {
                    $this->hoistEvalLocalVarNames($elem, $env);
                }
            }
        } elseif ($pattern instanceof ObjectPattern) {
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof AssignmentProperty) {
                    $this->hoistEvalLocalVarNames($prop->value, $env);
                } elseif ($prop instanceof RestElement) {
                    $this->hoistEvalLocalVarNames($prop->argument, $env);
                }
            }
        } elseif ($pattern instanceof AssignmentPattern) {
            $this->hoistEvalLocalVarNames($pattern->left, $env);
        } elseif ($pattern instanceof RestElement) {
            $this->hoistEvalLocalVarNames($pattern->argument, $env);
        }
    }

    /**
     * Recurse into compound statements for eval local var hoisting.
     */
    private function hoistEvalLocalVarCompound(Node $stmt, Environment $env): void
    {
        if ($stmt instanceof ForStatement) {
            if ($stmt->init instanceof VariableDeclaration && $stmt->init->kind === 'var') {
                foreach ($stmt->init->declarations as $decl) {
                    $this->hoistEvalLocalVarNames($decl->id, $env);
                }
            }
            if ($stmt->body instanceof BlockStatement) {
                foreach ($stmt->body->body as $s) {
                    $this->hoistEvalLocalVarCompound($s, $env);
                }
            }
        } elseif ($stmt instanceof ForOfStatement || $stmt instanceof ForInStatement) {
            if ($stmt->left instanceof VariableDeclaration && $stmt->left->kind === 'var') {
                foreach ($stmt->left->declarations as $decl) {
                    $this->hoistEvalLocalVarNames($decl->id, $env);
                }
            }
            if ($stmt->body instanceof BlockStatement) {
                foreach ($stmt->body->body as $s) {
                    $this->hoistEvalLocalVarCompound($s, $env);
                }
            }
        } elseif ($stmt instanceof WhileStatement || $stmt instanceof DoWhileStatement) {
            if ($stmt->body instanceof BlockStatement) {
                foreach ($stmt->body->body as $s) {
                    $this->hoistEvalLocalVarCompound($s, $env);
                }
            }
        } elseif ($stmt instanceof IfStatement) {
            if ($stmt->consequent instanceof BlockStatement) {
                foreach ($stmt->consequent->body as $s) {
                    $this->hoistEvalLocalVarCompound($s, $env);
                }
            }
            if ($stmt->alternate instanceof BlockStatement) {
                foreach ($stmt->alternate->body as $s) {
                    $this->hoistEvalLocalVarCompound($s, $env);
                }
            } elseif ($stmt->alternate instanceof IfStatement) {
                $this->hoistEvalLocalVarCompound($stmt->alternate, $env);
            }
        } elseif ($stmt instanceof BlockStatement) {
            foreach ($stmt->body as $s) {
                $this->hoistEvalLocalVarCompound($s, $env);
            }
        } elseif ($stmt instanceof TryStatement) {
            foreach ($stmt->block->body as $s) {
                $this->hoistEvalLocalVarCompound($s, $env);
            }
            if ($stmt->handler !== null) {
                foreach ($stmt->handler->body->body as $s) {
                    $this->hoistEvalLocalVarCompound($s, $env);
                }
            }
            if ($stmt->finalizer !== null) {
                foreach ($stmt->finalizer->body as $s) {
                    $this->hoistEvalLocalVarCompound($s, $env);
                }
            }
        } elseif ($stmt instanceof SwitchStatement) {
            foreach ($stmt->cases as $case) {
                foreach ($case->consequent as $inner) {
                    if ($inner instanceof VariableDeclaration && $inner->kind === 'var') {
                        foreach ($inner->declarations as $decl) {
                            $this->hoistEvalLocalVarNames($decl->id, $env);
                        }
                    } else {
                        $this->hoistEvalLocalVarCompound($inner, $env);
                    }
                }
            }
        } elseif ($stmt instanceof LabeledStatement) {
            $this->hoistEvalLocalVarCompound($stmt->body, $env);
        } elseif ($stmt instanceof WithStatement) {
            if ($stmt->body instanceof BlockStatement) {
                foreach ($stmt->body->body as $s) {
                    $this->hoistEvalLocalVarCompound($s, $env);
                }
            } else {
                $this->hoistEvalLocalVarCompound($stmt->body, $env);
            }
        } elseif ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
            foreach ($stmt->declarations as $decl) {
                $this->hoistEvalLocalVarNames($decl->id, $env);
            }
        }
    }

    /**
     * Hoist declarations in eval code at global scope.
     * Per EvalDeclarationInstantiation, function and var bindings created by eval
     * at the global level use configurable: true (unlike script-level which uses
     * configurable: false). This mirrors CreateGlobalFunctionBinding(fn, fo, true)
     * and CreateGlobalVarBinding(vn, true) from the spec.
     *
     * @param Node[] $statements
     */
    private function hoistEvalGlobalDeclarations(array $statements, Environment $env, ?Environment $lexEnv = null): void
    {
        $globalObj = $env->getLinkedObject();
        $isExtensible = $globalObj !== null ? $globalObj->isExtensible() : true;

        // Per EvalDeclarationInstantiation step 5.a.i: for each var name in
        // the eval code, if the global env already has a lexical binding of
        // that name, throw SyntaxError. This prevents `let x; eval('var x')`.
        $allVarNames = [];
        foreach ($statements as $stmt) {
            if ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
                foreach ($stmt->declarations as $decl) {
                    foreach ($this->patternBoundNames($decl->id) as $n) {
                        $allVarNames[$n] = true;
                    }
                }
            } elseif ($stmt instanceof FunctionDeclaration && $stmt->id !== null) {
                $allVarNames[$stmt->id->name] = true;
            }
        }
        foreach (array_keys($allVarNames) as $vn) {
            if ($env->hasLexicalBinding($vn)) {
                $this->throwJsValue(
                    $this->phpExceptionToJsValue(
                        new \Phasis\Exceptions\SyntaxError(
                            "Identifier '{$vn}' has already been declared",
                        ),
                    ),
                );
            }
        }

        // Per EvalDeclarationInstantiation step 8: collect function declarations
        // in reverse order (last wins) and perform CanDeclareGlobalFunction.
        $declaredFuncNames = [];
        $funcsToInit = [];
        for ($i = count($statements) - 1; $i >= 0; $i--) {
            $stmt = $statements[$i];
            if ($stmt instanceof FunctionDeclaration) {
                $fname = $stmt->id->name;
                if (!isset($declaredFuncNames[$fname])) {
                    $declaredFuncNames[$fname] = true;
                    // CanDeclareGlobalFunction check.
                    if ($globalObj !== null) {
                        $existingProp = $globalObj->getOwnPropertyDescriptor($fname);
                        if ($existingProp === null) {
                            if (!$isExtensible) {
                                $this->throwJsValue(
                                    $this->phpExceptionToJsValue(
                                        new TypeError("Cannot define property {$fname}, object is not extensible"),
                                    ),
                                );
                            }
                        } elseif (!$existingProp->configurable) {
                            $isOk = $existingProp->isDataDescriptor()
                                && $existingProp->writable === true
                                && $existingProp->enumerable === true;
                            if (!$isOk) {
                                $this->throwJsValue(
                                    $this->phpExceptionToJsValue(
                                        new TypeError("Cannot redefine property: {$fname}"),
                                    ),
                                );
                            }
                        }
                    }
                    array_unshift($funcsToInit, $stmt);
                }
            }
        }

        // Collect declared var names for Annex B step a check.
        $declaredVarNames = [];
        foreach ($statements as $stmt) {
            if ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
                foreach ($stmt->declarations as $decl) {
                    foreach ($this->patternBoundNames($decl->id) as $n) {
                        $declaredVarNames[$n] = true;
                    }
                }
            }
        }
        $declaredFuncOrVarNames = array_merge($declaredFuncNames, $declaredVarNames);

        // Collect top-level lexical names that block Annex B hoisting.
        $lexicalNames = [];
        foreach ($statements as $stmt) {
            if (
                $stmt instanceof VariableDeclaration && (
                $stmt->kind === 'let' || $stmt->kind === 'const'
                || $stmt->kind === 'using' || $stmt->kind === 'await using'
                )
            ) {
                foreach ($stmt->declarations as $d) {
                    foreach ($this->patternBoundNames($d->id) as $n) {
                        $lexicalNames[$n] = true;
                    }
                }
            }
        }

        // Per EvalDeclarationInstantiation step 10: CanDeclareGlobalVar for
        // each var name not already a declared function name.
        if ($globalObj !== null) {
            foreach ($statements as $stmt) {
                if ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
                    foreach ($stmt->declarations as $decl) {
                        foreach ($this->patternBoundNames($decl->id) as $vn) {
                            if (!isset($declaredFuncNames[$vn])) {
                                if (!$globalObj->hasOwnProperty($vn) && !$isExtensible) {
                                    $this->throwJsValue(
                                        $this->phpExceptionToJsValue(
                                            new TypeError("Cannot define property {$vn}, object is not extensible"),
                                        ),
                                    );
                                }
                            }
                        }
                    }
                }
            }
        }

        // Initialize function declarations.
        // Per EvalDeclarationInstantiation step 16: function objects close
        // over the eval's lexEnv (so they can see let/const declared inside
        // the eval), even though the binding lives in varEnv.
        $funcParentEnv = $lexEnv ?? $env;
        foreach ($funcsToInit as $stmt) {
            $fn = new JsFunction(
                $stmt->id->name,
                $stmt->params,
                $stmt->body,
                $funcParentEnv,
                isGenerator: $stmt->generator,
                isAsync: $stmt->async,
                strict: $this->strictMode,
            );
            if ($stmt->sourceText !== null) {
                $fn->setSourceText($stmt->sourceText);
            }
            $this->installFunctionPrototype($fn, $stmt->generator, $stmt->async);
            // Eval-created global function bindings are configurable.
            $env->defineGlobalVar($stmt->id->name, $fn, true);
        }

        // Hoist var declarations.
        foreach ($statements as $stmt) {
            if ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
                foreach ($stmt->declarations as $decl) {
                    $this->hoistEvalGlobalVarNames($decl->id, $env);
                }
            } elseif (!($stmt instanceof FunctionDeclaration)) {
                // Recurse into compound statements for nested var declarations.
                $this->hoistEvalGlobalVarCompound($stmt, $env);
            }
        }

        // Annex B.3.3.3: Hoist function declarations from blocks/if/switch
        // in eval code to the global variable environment.
        if (!$this->strictMode) {
            $annexBDecls = $this->collectEvalAnnexBFunctions(
                $statements,
                $declaredFuncOrVarNames,
                $lexicalNames,
            );
            foreach ($annexBDecls as $decl) {
                $name = $decl->id->name;
                // Check if replacing with var would produce early errors.
                if ($this->evalAnnexBWouldProduceEarlyError($statements, $name, $decl)) {
                    continue;
                }
                // Mark for runtime update (step b).
                $this->annexBEligible[spl_object_id($decl)] = true;
                // Step a: create binding if not already in declaredFuncOrVarNames.
                if (!isset($declaredFuncOrVarNames[$name])) {
                    // Per B.3.3.3 step i: if global, use CreateGlobalVarBinding(F, true).
                    // This only creates the property if it does not already exist.
                    if ($globalObj !== null) {
                        if (!$globalObj->hasOwnProperty($name)) {
                            if ($isExtensible) {
                                $env->defineGlobalVar($name, JsUndefined::instance(), true);
                            }
                        }
                    } elseif (!$env->has($name)) {
                        $env->defineDeletable($name, JsUndefined::instance());
                    }
                    $env->markAnnexBHoisted($name);
                } else {
                    // Name exists from regular hoisting. Mark for runtime update.
                    $env->markAnnexBHoisted($name);
                }
            }
        }
    }

    /**
     * Hoist a var name into global scope from eval, using configurable: true.
     */
    private function hoistEvalGlobalVarNames(Node $pattern, Environment $env): void
    {
        if ($pattern instanceof Identifier) {
            if (!$env->has($pattern->name)) {
                $env->defineGlobalVar($pattern->name, JsUndefined::instance(), true);
            }
        } elseif ($pattern instanceof ArrayPattern) {
            foreach ($pattern->elements as $elem) {
                if ($elem !== null) {
                    $this->hoistEvalGlobalVarNames($elem, $env);
                }
            }
        } elseif ($pattern instanceof ObjectPattern) {
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof AssignmentProperty) {
                    $this->hoistEvalGlobalVarNames($prop->value, $env);
                } elseif ($prop instanceof RestElement) {
                    $this->hoistEvalGlobalVarNames($prop->argument, $env);
                }
            }
        } elseif ($pattern instanceof AssignmentPattern) {
            $this->hoistEvalGlobalVarNames($pattern->left, $env);
        } elseif ($pattern instanceof RestElement) {
            $this->hoistEvalGlobalVarNames($pattern->argument, $env);
        }
    }

    /**
     * Recurse into compound statements for eval global var hoisting.
     */
    private function hoistEvalGlobalVarCompound(Node $stmt, Environment $env): void
    {
        if ($stmt instanceof ForOfStatement || $stmt instanceof ForInStatement) {
            if ($stmt->left instanceof VariableDeclaration && $stmt->left->kind === 'var') {
                foreach ($stmt->left->declarations as $decl) {
                    $this->hoistEvalGlobalVarNames($decl->id, $env);
                }
            }
            if ($stmt->body instanceof BlockStatement) {
                foreach ($stmt->body->body as $s) {
                    $this->hoistEvalGlobalVarCompound($s, $env);
                }
            }
        } elseif ($stmt instanceof ForStatement) {
            if ($stmt->init instanceof VariableDeclaration && $stmt->init->kind === 'var') {
                foreach ($stmt->init->declarations as $decl) {
                    $this->hoistEvalGlobalVarNames($decl->id, $env);
                }
            }
            if ($stmt->body instanceof BlockStatement) {
                foreach ($stmt->body->body as $s) {
                    $this->hoistEvalGlobalVarCompound($s, $env);
                }
            }
        } elseif ($stmt instanceof WhileStatement || $stmt instanceof DoWhileStatement) {
            if ($stmt->body instanceof BlockStatement) {
                foreach ($stmt->body->body as $s) {
                    $this->hoistEvalGlobalVarCompound($s, $env);
                }
            }
        } elseif ($stmt instanceof IfStatement) {
            if ($stmt->consequent instanceof BlockStatement) {
                foreach ($stmt->consequent->body as $s) {
                    $this->hoistEvalGlobalVarCompound($s, $env);
                }
            }
            if ($stmt->alternate instanceof BlockStatement) {
                foreach ($stmt->alternate->body as $s) {
                    $this->hoistEvalGlobalVarCompound($s, $env);
                }
            } elseif ($stmt->alternate instanceof IfStatement) {
                $this->hoistEvalGlobalVarCompound($stmt->alternate, $env);
            }
        } elseif ($stmt instanceof BlockStatement) {
            foreach ($stmt->body as $s) {
                $this->hoistEvalGlobalVarCompound($s, $env);
            }
        } elseif ($stmt instanceof TryStatement) {
            foreach ($stmt->block->body as $s) {
                $this->hoistEvalGlobalVarCompound($s, $env);
            }
            if ($stmt->handler !== null) {
                foreach ($stmt->handler->body->body as $s) {
                    $this->hoistEvalGlobalVarCompound($s, $env);
                }
            }
            if ($stmt->finalizer !== null) {
                foreach ($stmt->finalizer->body as $s) {
                    $this->hoistEvalGlobalVarCompound($s, $env);
                }
            }
        } elseif ($stmt instanceof SwitchStatement) {
            foreach ($stmt->cases as $case) {
                foreach ($case->consequent as $inner) {
                    if ($inner instanceof VariableDeclaration && $inner->kind === 'var') {
                        foreach ($inner->declarations as $decl) {
                            $this->hoistEvalGlobalVarNames($decl->id, $env);
                        }
                    } else {
                        $this->hoistEvalGlobalVarCompound($inner, $env);
                    }
                }
            }
        } elseif ($stmt instanceof LabeledStatement) {
            $this->hoistEvalGlobalVarCompound($stmt->body, $env);
        } elseif ($stmt instanceof WithStatement) {
            if ($stmt->body instanceof BlockStatement) {
                foreach ($stmt->body->body as $s) {
                    $this->hoistEvalGlobalVarCompound($s, $env);
                }
            } else {
                $this->hoistEvalGlobalVarCompound($stmt->body, $env);
            }
        } elseif ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
            foreach ($stmt->declarations as $decl) {
                $this->hoistEvalGlobalVarNames($decl->id, $env);
            }
        }
    }

    /**
     * Hoist only var declarations (not function declarations) from nested blocks.
     *
     * Used when recursing into block-like structures during hoisting. Function
     * declarations inside blocks are block-scoped per ES2015+; only their var
     * binding name is hoisted via hoistBlockFunctionDeclarations (Annex B).
     *
     * @param Node[] $statements
     */
    private function hoistVarDeclarationsOnly(array $statements, Environment $env): void
    {
        foreach ($statements as $stmt) {
            if ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
                foreach ($stmt->declarations as $decl) {
                    $this->hoistVarNames($decl->id, $env);
                }
            } elseif ($stmt instanceof ForOfStatement || $stmt instanceof ForInStatement) {
                if ($stmt->left instanceof VariableDeclaration && $stmt->left->kind === 'var') {
                    foreach ($stmt->left->declarations as $decl) {
                        $this->hoistVarNames($decl->id, $env);
                    }
                }
                if ($stmt->body instanceof \Phasis\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->body->body, $env);
                } else {
                    $this->hoistVarDeclarationsOnly([$stmt->body], $env);
                }
            } elseif ($stmt instanceof ForStatement) {
                if ($stmt->init instanceof VariableDeclaration && $stmt->init->kind === 'var') {
                    foreach ($stmt->init->declarations as $decl) {
                        $this->hoistVarNames($decl->id, $env);
                    }
                }
                if ($stmt->body instanceof \Phasis\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->body->body, $env);
                } else {
                    $this->hoistVarDeclarationsOnly([$stmt->body], $env);
                }
            } elseif (
                $stmt instanceof \Phasis\Ast\Statement\WhileStatement
                || $stmt instanceof \Phasis\Ast\Statement\DoWhileStatement
            ) {
                if ($stmt->body instanceof \Phasis\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->body->body, $env);
                } else {
                    $this->hoistVarDeclarationsOnly([$stmt->body], $env);
                }
            } elseif ($stmt instanceof \Phasis\Ast\Statement\IfStatement) {
                if ($stmt->consequent instanceof \Phasis\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->consequent->body, $env);
                } else {
                    $this->hoistVarDeclarationsOnly([$stmt->consequent], $env);
                }
                if ($stmt->alternate instanceof \Phasis\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->alternate->body, $env);
                } elseif ($stmt->alternate instanceof \Phasis\Ast\Statement\IfStatement) {
                    $this->hoistVarDeclarationsOnly([$stmt->alternate], $env);
                } elseif ($stmt->alternate !== null) {
                    $this->hoistVarDeclarationsOnly([$stmt->alternate], $env);
                }
            } elseif ($stmt instanceof \Phasis\Ast\Statement\BlockStatement) {
                $this->hoistVarDeclarationsOnly($stmt->body, $env);
            } elseif ($stmt instanceof TryStatement) {
                $this->hoistVarDeclarationsOnly($stmt->block->body, $env);
                if ($stmt->handler !== null) {
                    $this->hoistVarDeclarationsOnly($stmt->handler->body->body, $env);
                }
                if ($stmt->finalizer !== null) {
                    $this->hoistVarDeclarationsOnly($stmt->finalizer->body, $env);
                }
            } elseif ($stmt instanceof SwitchStatement) {
                foreach ($stmt->cases as $case) {
                    $this->hoistVarDeclarationsOnly($case->consequent, $env);
                }
            } elseif ($stmt instanceof LabeledStatement) {
                $this->hoistVarDeclarationsOnly([$stmt->body], $env);
            } elseif ($stmt instanceof WithStatement) {
                if ($stmt->body instanceof \Phasis\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->body->body, $env);
                } else {
                    $this->hoistVarDeclarationsOnly([$stmt->body], $env);
                }
            }
        }
    }

    /**
     * Annex B block-scoped function hoisting for sloppy mode.
     *
     * Recurse into block-like structures and hoist any function declaration
     * names found inside to the given environment as undefined. The actual
     * value is assigned when the block executes.
     */
    /**
     * @param array<string, bool> $lexicalNames Top-level let/const names to skip.
     */
    private function hoistBlockFunctionDeclarations(
        Node $stmt,
        Environment $env,
        array $lexicalNames = [],
    ): void {
        $children = match (true) {
            $stmt instanceof BlockStatement => $stmt->body,
            $stmt instanceof IfStatement => array_filter([
                $stmt->consequent,
                $stmt->alternate,
            ]),
            $stmt instanceof LabeledStatement => [$stmt->body],
            // Per Annex B.3.3.4, a function declaration directly inside a
            // `with` body still hoists to the enclosing variable scope —
            // the with object's binding is irrelevant to the hoist target.
            $stmt instanceof \Phasis\Ast\Statement\WithStatement => [$stmt->body],
            default => [],
        };

        // Per B.3.3.1 step ii: skip if the name would conflict with a lexical
        // declaration or is a parameter name. A pre-existing var binding does
        // NOT block hoisting; we still mark it for Annex B update.
        $canHoist = function (string $name) use ($env, $lexicalNames): bool {
            if (isset($lexicalNames[$name])) {
                return false;
            }
            if ($env->hasLexicalBinding($name)) {
                return false;
            }
            // Per B.3.3.1: "F is not an element of BoundNames of argumentsList"
            if (isset($this->currentParamNames[$name])) {
                return false;
            }
            return true;
        };

        // Switch statements: collect function declarations from case bodies.
        if ($stmt instanceof SwitchStatement) {
            foreach ($stmt->cases as $case) {
                foreach ($case->consequent as $inner) {
                    // Per Annex B.3.4: unwrap LabeledStatement so labeled
                    // function decls inside switch cases also hoist.
                    if ($inner instanceof LabeledStatement) {
                        $inner = $inner->body;
                    }
                    if ($inner instanceof FunctionDeclaration && !$inner->async && !$inner->generator) {
                        if ($canHoist($inner->id->name)) {
                            $env->defineAnnexBVar($inner->id->name, JsUndefined::instance(), $this->isEvalContext);
                            $this->annexBEligible[spl_object_id($inner)] = true;
                        }
                    }
                }
            }
        }

        // TryStatement: recursively scan try body, catch body, and finally body
        // for block-scoped function declarations.
        if ($stmt instanceof TryStatement) {
            foreach ($stmt->block->body as $inner) {
                $this->hoistBlockFunctionDeclarations($inner, $env, $lexicalNames);
            }
            if ($stmt->handler !== null) {
                // Per B.3.5: if the catch parameter is a destructuring pattern,
                // any name bound by it blocks Annex B hoisting of same-named
                // function declarations inside the catch body. A simple
                // BindingIdentifier catch param does NOT block hoisting.
                $catchBlockedNames = $lexicalNames;
                $catchParam = $stmt->handler->param;
                if ($catchParam !== null && !($catchParam instanceof Identifier)) {
                    $catchBound = $this->collectBoundNames($catchParam);
                    foreach ($catchBound as $bn) {
                        $catchBlockedNames[$bn] = true;
                    }
                }
                foreach ($stmt->handler->body->body as $inner) {
                    $this->hoistBlockFunctionDeclarations($inner, $env, $catchBlockedNames);
                }
            }
            if ($stmt->finalizer !== null) {
                foreach ($stmt->finalizer->body as $inner) {
                    $this->hoistBlockFunctionDeclarations($inner, $env, $lexicalNames);
                }
            }
        }

        // Collect lexical names (let/const/class/function declarations) directly
        // in this block. These create lexical bindings that block nested Annex B
        // hoisting. Per B.3.3.1: "replacing the FunctionDeclaration f with a
        // VariableStatement ... would not produce any Early Errors".
        $blockLexNames = [];
        foreach ($children as $child) {
            if ($child instanceof FunctionDeclaration && !$child->async && !$child->generator) {
                $blockLexNames[$child->id->name] = true;
            }
            if (
                $child instanceof VariableDeclaration && (
                $child->kind === 'let' || $child->kind === 'const'
                || $child->kind === 'using' || $child->kind === 'await using'
                )
            ) {
                foreach ($child->declarations as $decl) {
                    foreach ($this->patternBoundNames($decl->id) as $n) {
                        $blockLexNames[$n] = true;
                    }
                }
            }
            if ($child instanceof ClassDeclaration && $child->id !== null) {
                $blockLexNames[$child->id->name] = true;
            }
        }

        foreach ($children as $child) {
            // Per Annex B.3.4, a LabeledFunctionDeclaration hoists like a
            // plain FunctionDeclaration. Unwrap the label so the same
            // checks apply.
            if ($child instanceof LabeledStatement) {
                $child = $child->body;
            }
            if ($child instanceof FunctionDeclaration && !$child->async && !$child->generator) {
                if ($canHoist($child->id->name)) {
                    $env->defineAnnexBVar($child->id->name, JsUndefined::instance(), $this->isEvalContext);
                    $this->annexBEligible[spl_object_id($child)] = true;
                }
            } elseif ($child instanceof BlockStatement) {
                foreach ($child->body as $inner) {
                    // Same labeled-decl unwrap inside nested blocks.
                    if ($inner instanceof LabeledStatement) {
                        $inner = $inner->body;
                    }
                    // Annex B.3 hoisting only applies to plain function
                    // declarations — generator and async function
                    // declarations stay block-scoped and are not promoted
                    // to var bindings.
                    if ($inner instanceof FunctionDeclaration && !$inner->async && !$inner->generator) {
                        // Per B.3.3.1: skip if the enclosing block already has a
                        // lexical binding for this name (let/const/class/function).
                        // Replacing with var would be an Early Error.
                        if (isset($blockLexNames[$inner->id->name])) {
                            continue;
                        }
                        if ($canHoist($inner->id->name)) {
                            $env->defineAnnexBVar($inner->id->name, JsUndefined::instance(), $this->isEvalContext);
                            $this->annexBEligible[spl_object_id($inner)] = true;
                        }
                    }
                }
            }
        }
    }

    /**
     * Annex B.3.3.3: Collect function declarations inside blocks, if statements,
     * and switch cases within eval code that need var-binding hoisting.
     *
     * Returns an array of FunctionDeclaration nodes that are eligible for
     * Annex B hoisting. Only non-generator, non-async function declarations
     * directly contained in blocks/if/switch are eligible.
     *
     * @param Node[] $statements The top-level eval code body
     * @param array<string, bool> $declaredFuncOrVarNames Names already declared as
     *     top-level function or var (these block binding creation but not update)
     * @param array<string, bool> $lexicalNames Top-level lexical names (let/const)
     *     that block hoisting entirely
     * @return FunctionDeclaration[] Eligible function declarations
     */
    private function collectEvalAnnexBFunctions(
        array $statements,
        array $declaredFuncOrVarNames,
        array $lexicalNames,
    ): array {
        $result = [];
        $seen = [];
        foreach ($statements as $stmt) {
            $this->scanEvalAnnexBFunctions(
                $stmt,
                $declaredFuncOrVarNames,
                $lexicalNames,
                $result,
                $seen,
            );
        }
        return $result;
    }

    /**
     * Recursively scan a statement for Annex B eligible function declarations
     * inside blocks, if statements, and switch cases.
     *
     * @param array<string, bool> $declaredFuncOrVarNames
     * @param array<string, bool> $lexicalNames
     * @param FunctionDeclaration[] $result Collected eligible declarations
     * @param array<string, bool> $seen Names already processed (first wins for init)
     */
    private function scanEvalAnnexBFunctions(
        Node $stmt,
        array $declaredFuncOrVarNames,
        array $lexicalNames,
        array &$result,
        array &$seen,
    ): void {
        // Per B.3.3.3: only scan blocks, if statements, labeled statements,
        // switch statements, and try statements.
        if ($stmt instanceof BlockStatement) {
            foreach ($stmt->body as $child) {
                // Per Annex B.3.4 unwrap label so `l: function f() {}` hoists
                // like a plain function declaration.
                $unwrapped = $child instanceof LabeledStatement ? $child->body : $child;
                if (
                    $unwrapped instanceof FunctionDeclaration
                    && !$unwrapped->async
                    && !$unwrapped->generator
                ) {
                    $this->addEvalAnnexBCandidate(
                        $unwrapped,
                        $declaredFuncOrVarNames,
                        $lexicalNames,
                        $result,
                        $seen,
                    );
                }
            }
        } elseif ($stmt instanceof IfStatement) {
            // Check function declarations directly as consequent or alternate.
            if (
                $stmt->consequent instanceof FunctionDeclaration
                && !$stmt->consequent->async && !$stmt->consequent->generator
            ) {
                $this->addEvalAnnexBCandidate(
                    $stmt->consequent,
                    $declaredFuncOrVarNames,
                    $lexicalNames,
                    $result,
                    $seen,
                );
            } elseif ($stmt->consequent instanceof BlockStatement) {
                $this->scanEvalAnnexBFunctions(
                    $stmt->consequent,
                    $declaredFuncOrVarNames,
                    $lexicalNames,
                    $result,
                    $seen,
                );
            }
            if (
                $stmt->alternate instanceof FunctionDeclaration
                && !$stmt->alternate->async && !$stmt->alternate->generator
            ) {
                $this->addEvalAnnexBCandidate(
                    $stmt->alternate,
                    $declaredFuncOrVarNames,
                    $lexicalNames,
                    $result,
                    $seen,
                );
            } elseif ($stmt->alternate instanceof IfStatement) {
                $this->scanEvalAnnexBFunctions(
                    $stmt->alternate,
                    $declaredFuncOrVarNames,
                    $lexicalNames,
                    $result,
                    $seen,
                );
            } elseif ($stmt->alternate instanceof BlockStatement) {
                $this->scanEvalAnnexBFunctions(
                    $stmt->alternate,
                    $declaredFuncOrVarNames,
                    $lexicalNames,
                    $result,
                    $seen,
                );
            }
        } elseif ($stmt instanceof SwitchStatement) {
            foreach ($stmt->cases as $case) {
                foreach ($case->consequent as $child) {
                    // Per Annex B.3.4 unwrap label so `l: function f() {}`
                    // inside a switch case hoists like a plain function decl.
                    $unwrapped = $child instanceof LabeledStatement ? $child->body : $child;
                    if (
                        $unwrapped instanceof FunctionDeclaration
                        && !$unwrapped->async
                        && !$unwrapped->generator
                    ) {
                        $this->addEvalAnnexBCandidate(
                            $unwrapped,
                            $declaredFuncOrVarNames,
                            $lexicalNames,
                            $result,
                            $seen,
                        );
                    }
                }
            }
        } elseif ($stmt instanceof LabeledStatement) {
            // A labeled function declaration is itself eligible per
            // Annex B.3.4. Otherwise, recurse into the body.
            if (
                $stmt->body instanceof FunctionDeclaration
                && !$stmt->body->async
                && !$stmt->body->generator
            ) {
                $this->addEvalAnnexBCandidate(
                    $stmt->body,
                    $declaredFuncOrVarNames,
                    $lexicalNames,
                    $result,
                    $seen,
                );
            } else {
                $this->scanEvalAnnexBFunctions(
                    $stmt->body,
                    $declaredFuncOrVarNames,
                    $lexicalNames,
                    $result,
                    $seen,
                );
            }
        } elseif ($stmt instanceof TryStatement) {
            foreach ($stmt->block->body as $child) {
                $this->scanEvalAnnexBFunctions(
                    $child,
                    $declaredFuncOrVarNames,
                    $lexicalNames,
                    $result,
                    $seen,
                );
            }
            if ($stmt->handler !== null) {
                // Per B.3.5: if the catch parameter is a destructuring pattern,
                // names bound by it block Annex B hoisting of same-named function
                // declarations inside the catch body. A simple BindingIdentifier
                // catch param does NOT block hoisting.
                $catchBlockedNames = $lexicalNames;
                $catchParam = $stmt->handler->param;
                if ($catchParam !== null && !($catchParam instanceof Identifier)) {
                    $catchBound = $this->collectBoundNames($catchParam);
                    foreach ($catchBound as $bn) {
                        $catchBlockedNames[$bn] = true;
                    }
                }
                foreach ($stmt->handler->body->body as $child) {
                    $this->scanEvalAnnexBFunctions(
                        $child,
                        $declaredFuncOrVarNames,
                        $catchBlockedNames,
                        $result,
                        $seen,
                    );
                }
            }
            if ($stmt->finalizer !== null) {
                foreach ($stmt->finalizer->body as $child) {
                    $this->scanEvalAnnexBFunctions(
                        $child,
                        $declaredFuncOrVarNames,
                        $lexicalNames,
                        $result,
                        $seen,
                    );
                }
            }
        }
    }

    /**
     * Check if a function declaration is eligible for Annex B hoisting in eval
     * code and add it to the result list if so.
     *
     * Per B.3.3.3: "If replacing the FunctionDeclaration f with a
     * VariableStatement that has F as a BindingIdentifier would not produce
     * any Early Errors for body, then..."
     *
     * @param array<string, bool> $declaredFuncOrVarNames
     * @param array<string, bool> $lexicalNames
     * @param FunctionDeclaration[] $result
     * @param array<string, bool> $seen
     */
    private function addEvalAnnexBCandidate(
        FunctionDeclaration $decl,
        array $declaredFuncOrVarNames,
        array $lexicalNames,
        array &$result,
        array &$seen,
    ): void {
        $name = $decl->id->name;

        // Skip if there's a lexical binding (let/const) with the same name
        // at the top level of the eval code. A var declaration with this name
        // would produce an early error.
        if (isset($lexicalNames[$name])) {
            return;
        }

        // All eligible declarations get marked for runtime update.
        $result[] = $decl;

        // Track that we've seen this name (for init: only first wins when
        // determining whether to create a new binding).
        if (!isset($seen[$name])) {
            $seen[$name] = true;
        }
    }

    /**
     * Check if a function declaration name would produce early errors in
     * the eval code body when replaced with a var declaration.
     *
     * This checks for lexical bindings (let/const/class) in enclosing blocks
     * within the eval code that would conflict.
     *
     * @param Node[] $statements The eval code body
     * @param string $name The function name to check
     * @param Node $target The function declaration node to find
     * @return bool True if early errors would occur (should skip hoisting)
     */
    private function evalAnnexBWouldProduceEarlyError(
        array $statements,
        string $name,
        Node $target,
    ): bool {
        // Walk up from the target to find enclosing blocks and check for
        // lexical bindings with the same name.
        return $this->checkEvalAnnexBEarlyError($statements, $name, $target);
    }

    /**
     * Recursively check if a target function declaration is enclosed by a
     * block that has a lexical binding for the given name.
     *
     * @param Node[] $nodes
     */
    private function checkEvalAnnexBEarlyError(
        array $nodes,
        string $name,
        Node $target,
    ): bool {
        foreach ($nodes as $node) {
            if ($node === $target) {
                return false;
            }
            if ($node instanceof BlockStatement) {
                // Check if this block contains the target and has a lexical
                // binding for the name.
                if ($this->blockContainsNode($node, $target)) {
                    // Check for lexical bindings in this block scope.
                    foreach ($node->body as $child) {
                        if (
                            $child instanceof VariableDeclaration
                            && ($child->kind === 'let' || $child->kind === 'const'
                                || $child->kind === 'using' || $child->kind === 'await using')
                        ) {
                            foreach ($child->declarations as $d) {
                                foreach ($this->patternBoundNames($d->id) as $n) {
                                    if ($n === $name) {
                                        return true;
                                    }
                                }
                            }
                        }
                    }
                    return $this->checkEvalAnnexBEarlyError($node->body, $name, $target);
                }
            } elseif ($node instanceof ForStatement) {
                if (
                    $node->init instanceof VariableDeclaration
                    && ($node->init->kind === 'let' || $node->init->kind === 'const')
                ) {
                    foreach ($node->init->declarations as $d) {
                        foreach ($this->patternBoundNames($d->id) as $n) {
                            if ($n === $name && $this->nodeContainsTarget($node, $target)) {
                                return true;
                            }
                        }
                    }
                }
                if ($this->nodeContainsTarget($node, $target)) {
                    if ($node->body instanceof BlockStatement) {
                        return $this->checkEvalAnnexBEarlyError($node->body->body, $name, $target);
                    }
                }
            } elseif ($node instanceof ForInStatement || $node instanceof ForOfStatement) {
                if (
                    $node->left instanceof VariableDeclaration
                    && ($node->left->kind === 'let' || $node->left->kind === 'const')
                ) {
                    foreach ($node->left->declarations as $d) {
                        foreach ($this->patternBoundNames($d->id) as $n) {
                            if ($n === $name && $this->nodeContainsTarget($node, $target)) {
                                return true;
                            }
                        }
                    }
                }
                if ($this->nodeContainsTarget($node, $target)) {
                    if ($node->body instanceof BlockStatement) {
                        return $this->checkEvalAnnexBEarlyError($node->body->body, $name, $target);
                    }
                }
            } elseif ($node instanceof SwitchStatement) {
                if ($this->nodeContainsTarget($node, $target)) {
                    // Switch body is a single block scope containing all cases.
                    // Check for lexical bindings across all case clauses.
                    foreach ($node->cases as $case) {
                        foreach ($case->consequent as $child) {
                            if (
                                $child instanceof VariableDeclaration
                                && ($child->kind === 'let' || $child->kind === 'const'
                                    || $child->kind === 'using' || $child->kind === 'await using')
                            ) {
                                foreach ($child->declarations as $d) {
                                    foreach ($this->patternBoundNames($d->id) as $n) {
                                        if ($n === $name) {
                                            return true;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            } elseif ($node instanceof TryStatement) {
                if ($this->nodeContainsTarget($node, $target)) {
                    if (
                        $node->handler !== null
                        && $this->nodeContainsTarget($node->handler->body, $target)
                    ) {
                        // Check destructuring catch parameter.
                        $catchParam = $node->handler->param;
                        if ($catchParam !== null && !($catchParam instanceof Identifier)) {
                            $catchBound = $this->collectBoundNames($catchParam);
                            if (in_array($name, $catchBound, true)) {
                                return true;
                            }
                        }
                        return $this->checkEvalAnnexBEarlyError(
                            $node->handler->body->body,
                            $name,
                            $target,
                        );
                    }
                }
            }
        }
        return false;
    }

    /**
     * Check if a block statement (or its descendants) contains a specific node.
     */
    private function blockContainsNode(BlockStatement $block, Node $target): bool
    {
        foreach ($block->body as $child) {
            if ($child === $target || $this->nodeContainsTarget($child, $target)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a node (or any of its descendants) is or contains the target node.
     */
    private function nodeContainsTarget(Node $node, Node $target): bool
    {
        if ($node === $target) {
            return true;
        }
        if ($node instanceof BlockStatement) {
            return $this->blockContainsNode($node, $target);
        }
        if ($node instanceof IfStatement) {
            if ($node->consequent === $target || $this->nodeContainsTarget($node->consequent, $target)) {
                return true;
            }
            if (
                $node->alternate !== null
                && ($node->alternate === $target || $this->nodeContainsTarget($node->alternate, $target))
            ) {
                return true;
            }
            return false;
        }
        if ($node instanceof SwitchStatement) {
            foreach ($node->cases as $case) {
                foreach ($case->consequent as $child) {
                    if ($child === $target || $this->nodeContainsTarget($child, $target)) {
                        return true;
                    }
                }
            }
            return false;
        }
        if ($node instanceof TryStatement) {
            if ($this->nodeContainsTarget($node->block, $target)) {
                return true;
            }
            if ($node->handler !== null && $this->nodeContainsTarget($node->handler->body, $target)) {
                return true;
            }
            if ($node->finalizer !== null && $this->nodeContainsTarget($node->finalizer, $target)) {
                return true;
            }
            return false;
        }
        if ($node instanceof LabeledStatement) {
            return $node->body === $target || $this->nodeContainsTarget($node->body, $target);
        }
        if (
            $node instanceof ForStatement || $node instanceof WhileStatement
            || $node instanceof DoWhileStatement
        ) {
            return $node->body === $target || $this->nodeContainsTarget($node->body, $target);
        }
        if ($node instanceof ForInStatement || $node instanceof ForOfStatement) {
            return $node->body === $target || $this->nodeContainsTarget($node->body, $target);
        }
        if ($node instanceof WithStatement) {
            return $node->body === $target || $this->nodeContainsTarget($node->body, $target);
        }
        return false;
    }

    /**
     * Collect all bound identifier names from a destructuring pattern node.
     *
     * @return string[]
     */
    private function collectBoundNames(Node $node): array
    {
        if ($node instanceof Identifier) {
            return [$node->name];
        }
        if ($node instanceof \Phasis\Ast\Pattern\ObjectPattern) {
            $names = [];
            foreach ($node->properties as $prop) {
                if ($prop instanceof \Phasis\Ast\Pattern\RestElement) {
                    $names = array_merge($names, $this->collectBoundNames($prop->argument));
                } elseif ($prop instanceof \Phasis\Ast\Pattern\AssignmentProperty) {
                    $names = array_merge($names, $this->collectBoundNames($prop->value));
                } elseif ($prop instanceof \Phasis\Ast\Expression\Property) {
                    $names = array_merge($names, $this->collectBoundNames($prop->value));
                } elseif ($prop instanceof \Phasis\Ast\Pattern\AssignmentPattern) {
                    $names = array_merge($names, $this->collectBoundNames($prop->left));
                } else {
                    $names = array_merge($names, $this->collectBoundNames($prop));
                }
            }
            return $names;
        }
        if ($node instanceof \Phasis\Ast\Pattern\ArrayPattern) {
            $names = [];
            foreach ($node->elements as $elem) {
                if ($elem !== null) {
                    $names = array_merge($names, $this->collectBoundNames($elem));
                }
            }
            return $names;
        }
        if ($node instanceof \Phasis\Ast\Pattern\RestElement) {
            return $this->collectBoundNames($node->argument);
        }
        if ($node instanceof \Phasis\Ast\Pattern\AssignmentPattern) {
            return $this->collectBoundNames($node->left);
        }
        return [];
    }

    private function hoistVarNames(Node $pattern, Environment $env): void
    {
        // Per spec, var declarations inside `with` blocks hoist to the enclosing
        // function/global scope, bypassing the with-environment's binding object.
        // When hoisting inside a with-body, the env may be a child block-env
        // of the with-env. Walk up past both with-envs and their children to
        // avoid triggering Proxy has traps. We detect "inside a with" by
        // checking if any ancestor env is a tracked with-environment.
        $hoistEnv = $env;
        if (!empty($this->withEnvObjects)) {
            $checkEnv = $hoistEnv;
            $insideWith = false;
            while ($checkEnv !== null) {
                if (isset($this->withEnvObjects[spl_object_id($checkEnv)])) {
                    $insideWith = true;
                    break;
                }
                $checkEnv = $checkEnv->getParent();
            }
            if ($insideWith && $checkEnv->getParent() !== null) {
                // Skip past the with-environment to its parent (the outer scope).
                $hoistEnv = $checkEnv->getParent();
            }
        }
        if ($pattern instanceof Identifier) {
            // For function/module/static-block scopes, only check if the binding
            // already exists in THIS scope (not up the chain) to allow var
            // declarations to shadow outer bindings.
            $alreadyDeclared = $hoistEnv->getFunctionKind() !== null
                ? $hoistEnv->hasOwnBinding($pattern->name)
                : $hoistEnv->has($pattern->name);
            if (!$alreadyDeclared) {
                // At global scope use the correct user-var descriptor; in nested
                // scopes use defineVar (no linked object).
                if ($hoistEnv->getLinkedObject() !== null) {
                    $hoistEnv->defineGlobalVar($pattern->name, JsUndefined::instance());
                } else {
                    $hoistEnv->defineVar($pattern->name, JsUndefined::instance());
                }
            }
        } elseif ($pattern instanceof ArrayPattern) {
            foreach ($pattern->elements as $elem) {
                if ($elem !== null) {
                    $this->hoistVarNames($elem, $env);
                }
            }
        } elseif ($pattern instanceof ObjectPattern) {
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof AssignmentProperty) {
                    $this->hoistVarNames($prop->value, $env);
                } elseif ($prop instanceof RestElement) {
                    $this->hoistVarNames($prop->argument, $env);
                }
            }
        } elseif ($pattern instanceof AssignmentPattern) {
            $this->hoistVarNames($pattern->left, $env);
        } elseif ($pattern instanceof RestElement) {
            $this->hoistVarNames($pattern->argument, $env);
        }
    }

    /**
     * Collect all identifier names bound by a binding pattern (Identifier,
     * ArrayPattern, ObjectPattern, RestElement, AssignmentPattern).
     * Used to populate TDZ environments for for-of/for-in head evaluation.
     *
     * @return list<string>
     */
    private function patternBoundNames(Node $pattern): array
    {
        if ($pattern instanceof Identifier) {
            return [$pattern->name];
        }
        if ($pattern instanceof ArrayPattern) {
            $names = [];
            foreach ($pattern->elements as $elem) {
                if ($elem !== null) {
                    $names = array_merge($names, $this->patternBoundNames($elem));
                }
            }
            return $names;
        }
        if ($pattern instanceof ObjectPattern) {
            $names = [];
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof AssignmentProperty) {
                    $names = array_merge($names, $this->patternBoundNames($prop->value));
                } elseif ($prop instanceof RestElement) {
                    $names = array_merge($names, $this->patternBoundNames($prop->argument));
                }
            }
            return $names;
        }
        if ($pattern instanceof AssignmentPattern) {
            return $this->patternBoundNames($pattern->left);
        }
        if ($pattern instanceof RestElement) {
            return $this->patternBoundNames($pattern->argument);
        }
        return [];
    }

    /**
     * Force-hoist var names into the target environment, even when a binding
     * of the same name exists in a parent scope. Used for the separate body
     * environment in functions with parameter expressions, where body vars
     * must shadow parameter/parent bindings.
     *
     * @param Node[] $statements
     */
    private function forceHoistVarNames(array $statements, Environment $env): void
    {
        foreach ($statements as $stmt) {
            if ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
                foreach ($stmt->declarations as $decl) {
                    $this->forceDefineVarName($decl->id, $env);
                }
            } elseif ($stmt instanceof ForOfStatement || $stmt instanceof ForInStatement) {
                if ($stmt->left instanceof VariableDeclaration && $stmt->left->kind === 'var') {
                    foreach ($stmt->left->declarations as $decl) {
                        $this->forceDefineVarName($decl->id, $env);
                    }
                }
                if ($stmt->body instanceof BlockStatement) {
                    $this->forceHoistVarNames($stmt->body->body, $env);
                }
            } elseif ($stmt instanceof ForStatement) {
                if ($stmt->init instanceof VariableDeclaration && $stmt->init->kind === 'var') {
                    foreach ($stmt->init->declarations as $decl) {
                        $this->forceDefineVarName($decl->id, $env);
                    }
                }
                if ($stmt->body instanceof BlockStatement) {
                    $this->forceHoistVarNames($stmt->body->body, $env);
                }
            } elseif ($stmt instanceof \Phasis\Ast\Statement\WhileStatement || $stmt instanceof DoWhileStatement) {
                if ($stmt->body instanceof BlockStatement) {
                    $this->forceHoistVarNames($stmt->body->body, $env);
                }
            } elseif ($stmt instanceof IfStatement) {
                // Recurse into consequent + alternate as single-statement
                // lists. Wrapping in [] is correct for both: a Block becomes
                // [Block] (which our own elseif-BlockStatement branch
                // unwraps), and a non-block (like `else if`, or a brace-less
                // `if (x) var y;`) becomes [IfStatement] / [VarDecl] and the
                // outer foreach dispatches by node kind. Without this,
                // `var` decls inside else-if chains never got hoisted to
                // the function scope, so the assignment `f = ...` walked
                // up the closure chain and clobbered an outer binding —
                // observed corrupting fuse.js's `function f` spread helper.
                $this->forceHoistVarNames([$stmt->consequent], $env);
                if ($stmt->alternate !== null) {
                    $this->forceHoistVarNames([$stmt->alternate], $env);
                }
            } elseif ($stmt instanceof BlockStatement) {
                $this->forceHoistVarNames($stmt->body, $env);
            } elseif ($stmt instanceof WithStatement) {
                if ($stmt->body instanceof BlockStatement) {
                    $this->forceHoistVarNames($stmt->body->body, $env);
                } else {
                    $this->forceHoistVarNames([$stmt->body], $env);
                }
            } elseif ($stmt instanceof \Phasis\Ast\Statement\TryStatement) {
                $this->forceHoistVarNames($stmt->block->body, $env);
                if ($stmt->handler !== null) {
                    $this->forceHoistVarNames($stmt->handler->body->body, $env);
                }
                if ($stmt->finalizer !== null) {
                    $this->forceHoistVarNames($stmt->finalizer->body, $env);
                }
            } elseif ($stmt instanceof \Phasis\Ast\Statement\SwitchStatement) {
                foreach ($stmt->cases as $case) {
                    $this->forceHoistVarNames($case->consequent, $env);
                }
            } elseif ($stmt instanceof \Phasis\Ast\Statement\LabeledStatement) {
                $this->forceHoistVarNames([$stmt->body], $env);
            }
        }
    }

    private function forceDefineVarName(Node $pattern, Environment $env): void
    {
        if ($pattern instanceof Identifier) {
            // Per spec 10.2.11 step 28: if there is already a binding for the
            // name (e.g. a formal parameter), the var declaration is a no-op.
            // Only create the binding if it does not exist yet.
            if (!$env->hasOwnBinding($pattern->name)) {
                $env->defineVar($pattern->name, JsUndefined::instance());
            }
        } elseif ($pattern instanceof ArrayPattern) {
            foreach ($pattern->elements as $elem) {
                if ($elem !== null) {
                    $this->forceDefineVarName($elem, $env);
                }
            }
        } elseif ($pattern instanceof ObjectPattern) {
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof AssignmentProperty) {
                    $this->forceDefineVarName($prop->value, $env);
                } elseif ($prop instanceof RestElement) {
                    $this->forceDefineVarName($prop->argument, $env);
                }
            }
        } elseif ($pattern instanceof AssignmentPattern) {
            $this->forceDefineVarName($pattern->left, $env);
        } elseif ($pattern instanceof RestElement) {
            $this->forceDefineVarName($pattern->argument, $env);
        }
    }

    /**
     * Collect all binding names from a pattern (Identifier or destructuring).
     *
     * @param string[] $names Collected names (by reference).
     */
    private function collectBindingNames(Node $pattern, array &$names): void
    {
        if ($pattern instanceof Identifier) {
            $names[] = $pattern->name;
        } elseif ($pattern instanceof \Phasis\Ast\Pattern\ArrayPattern) {
            foreach ($pattern->elements as $elem) {
                if ($elem !== null) {
                    if ($elem instanceof \Phasis\Ast\Pattern\RestElement) {
                        $this->collectBindingNames($elem->argument, $names);
                    } elseif ($elem instanceof \Phasis\Ast\Pattern\AssignmentPattern) {
                        $this->collectBindingNames($elem->left, $names);
                    } else {
                        $this->collectBindingNames($elem, $names);
                    }
                }
            }
        } elseif ($pattern instanceof \Phasis\Ast\Pattern\ObjectPattern) {
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof \Phasis\Ast\Pattern\RestElement) {
                    $this->collectBindingNames($prop->argument, $names);
                } elseif ($prop instanceof \Phasis\Ast\Pattern\AssignmentProperty) {
                    $this->collectBindingNames($prop->value, $names);
                }
            }
        } elseif ($pattern instanceof \Phasis\Ast\Pattern\AssignmentPattern) {
            $this->collectBindingNames($pattern->left, $names);
        }
    }
}
