<?php declare(strict_types=1);
namespace Phan\Analysis;

use Phan\AST\Visitor\KindVisitorImplementation;
use Phan\CodeBase;
use Phan\Language\Context;
use Phan\Language\Element\Variable;
use Phan\Language\Scope;
use Phan\Language\Type\NullType;
use Phan\Language\UnionType;
use ast\Node;

class ContextMergeVisitor extends KindVisitorImplementation
{

    /**
     * @var CodeBase
     */
    private $code_base;

    /**
     * @var Context
     * The context in which the node we're going to be looking
     * at exits.
     */
    private $context;

    /**
     * @var array<int,Context>
     * A list of the contexts returned after depth-first
     * parsing of all first-level children of this node
     */
    private $child_context_list;

    /**
     * @param CodeBase $code_base
     * A code base needs to be passed in because we require
     * it to be initialized before any classes or files are
     * loaded.
     *
     * @param Context $context
     * The context of the parser at the node for which we'd
     * like to determine a type
     *
     * @param array<int,Context> $child_context_list
     * A list of the contexts returned after depth-first
     * parsing of all first-level children of this node
     */
    public function __construct(
        CodeBase $code_base,
        Context $context,
        array $child_context_list
    ) {
        $this->code_base = $code_base;
        $this->context = $context;
        $this->child_context_list = $child_context_list;
    }

    /**
     * Default visitor for node kinds that do not have
     * an overriding method
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     */
    public function visit(Node $node) : Context
    {

        // TODO: if ($this->context->isInGlobalScope()) {
        //            copy local to global
        //       }

        return \end($this->child_context_list) ?: $this->context;
    }

    /**
     * Merges the only try block of a try/catch node into the parent context.
     * This acts as though the entire block succeeds or throws on the first statement, which isn't necessarily the case.
     *
     * visitTry() was split out into multiple functions for the following reasons:
     *
     * 1. The try{} block affects the Context of catch blocks (and finally block), if any
     * 2. The catch blocks affect the Context of the finally block, if any
     *
     * TODO: Look at ways to improve accuracy based on inferences of the exit status of the node?
     */
    public function mergeTryContext(Node $node) : Context
    {
        \assert(\count($this->child_context_list) === 1);

        // Get the list of scopes for each branch of the
        // conditional
        $context = $this->context;
        $try_context = $this->child_context_list[0];

        if ($this->willRemainingStatementsBeAnalyzedAsIfTryMightFail($node)) {
            return $this->combineScopeList([
                $context->getScope(),
                $try_context->getScope()
            ]);
        }
        return $try_context;
    }

    private function willRemainingStatementsBeAnalyzedAsIfTryMightFail(Node $node) : bool
    {
        if ($node->children['finally'] !== null) {
            // We want to analyze finally as if the try block (and one or more of the catch blocks) was or wasn't executed.
            // ... This isn't optimal.
            // A better solution would be to analyze finally{} twice,
            // 1. As if try could fail
            // 2. As if try did not fail, using the latter to analyze statements after the finally{}.
            return true;
        }
        // E.g. after analyzing the following code:
        //      try { $x = expr(); } catch (Exception $e) { echo "Caught"; return; } catch (OtherException $e) { continue; }
        // Phan should infer that $x is guaranteed to be defined.
        foreach ($node->children['catches']->children ?? [] as $catch_node) {
            if (BlockExitStatusChecker::willUnconditionallySkipRemainingStatements($catch_node->children['stmts'])) {
                return true;
            }
        }
        return false;
    }

    public function mergeCatchContext(Node $node) : Context
    {
        \assert(\count($this->child_context_list) >= 2);  // first context is the try
        // Get the list of scopes for each branch of the
        // conditional
        $scope_list = \array_map(function (Context $context) {
            return $context->getScope();
        }, $this->child_context_list);

        $catch_scope_list = [];
        $catch_nodes = $node->children['catches']->children ?? [];
        foreach ($catch_nodes as $i => $catch_node) {
            if (!BlockExitStatusChecker::willUnconditionallySkipRemainingStatements($catch_node)) {
                $catch_scope_list[] = $scope_list[$i + 1];
            }
        }
        // TODO: Check if try node unconditionally returns.

        // Merge in the types for any variables found in a catch.
        // if ($node->children['finally'] !== null) {
            // If we have to analyze a finally statement later,
            // then be conservative and assume the try statement may or may not have failed.
            // E.g. the below example must have a inferred type of string|false
            //      $x = new stdClass(); try {...; $x = (string)fn(); } catch(Exception $e) { $x = false; }
            // $try_scope = $this->context->getScope();
        // } else {
            // If we don't have to worry about analyzing the finally statement, then assume that the entire try statement succeeded or the a catch statement succeeded.
            $try_scope = \reset($this->child_context_list)->getScope();
        // }
        foreach ($try_scope->getVariableMap() as $variable_name => $variable) {
            $variable_name = (string)$variable_name;  // e.g. ${42}
            foreach ($catch_scope_list as $catch_scope) {
                // Merge types if try and catch have a variable in common
                if ($catch_scope->hasVariableWithName($variable_name)) {
                    $catch_variable = $catch_scope->getVariableByName(
                        $variable_name
                    );

                    $variable->getUnionType()->addUnionType(
                        $catch_variable->getUnionType()
                    );
                }
            }
        }

        // Look for variables that exist in catch, but not try
        foreach ($catch_scope_list as $catch_scope) {
            foreach ($catch_scope->getVariableMap() as $variable_name => $variable) {
                $variable_name = (string)$variable_name;
                if (!$try_scope->hasVariableWithName($variable_name)) {
                    // Note that it can be null
                    $variable->getUnionType()->addType(
                        NullType::instance(false)
                    );

                    // Add it to the try scope
                    $try_scope->addVariable($variable);
                }
            }
        }

        // Set the new scope with only the variables and types
        // that are common to all branches
        return $this->context->withScope($try_scope);
    }

    /**
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     */
    public function visitIf(Node $node) : Context
    {
        // Get the list of scopes for each branch of the
        // conditional
        $scope_list = \array_map(function (Context $context) {
            return $context->getScope();
        }, $this->child_context_list);

        $has_else = \array_reduce(
            $node->children ?? [],
            function (bool $carry, $child_node) {
                return $carry || (
                    $child_node instanceof Node
                    && \is_null($child_node->children['cond'])
                );
            },
            false
        );

        // If we're not guaranteed to hit at least one
        // branch, mark the incoming scope as a possibility
        if (!$has_else) {
            $scope_list[] = $this->context->getScope();
        }

        // If there weren't multiple branches, continue on
        // as if the conditional never happened
        if (\count($scope_list) < 2) {
            return \reset($this->child_context_list);
        }

        return $this->combineScopeList($scope_list);
    }

    /**
     * @param array<int,Scope> $scope_list
     */
    public function combineScopeList(array $scope_list) : Context
    {
        \assert(\count($scope_list) >= 2);
        // Get a list of all variables in all scopes
        $variable_map = [];
        foreach ($scope_list as $scope) {
            foreach ($scope->getVariableMap() as $name => $variable) {
                $variable_map[$name] = $variable;
            }
        }

        // A function that determins if a variable is defined on
        // every branch
        $is_defined_on_all_branches =
            function (string $variable_name) use ($scope_list) {
                foreach ($scope_list as $scope) {
                    if (!$scope->hasVariableWithName($variable_name)) {
                        return false;
                    }
                }
                return true;
            };

        // Get the intersection of all types for all versions of
        // the variable from every side of the branch
        $union_type =
            function (string $variable_name) use ($scope_list) {

                // Get a list of all variables with the given name from
                // each scope
                $type_list = \array_filter(\array_map(
                    function (Scope $scope) use ($variable_name) {
                        if (!$scope->hasVariableWithName($variable_name)) {
                            return null;
                        }

                        return $scope->getVariableByName($variable_name)->getUnionType();
                    },
                    $scope_list
                ));

                if (\count($type_list) < 2) {
                    return new UnionType(\count($type_list) === 1 ? \reset($type_list)->getTypeSet() : []);
                }
                // compute the un-normalized types
                $result = UnionType::merge($type_list);

                $result_count = $result->typeCount();
                foreach ($type_list as $type) {
                    if ($type->typeCount() < $result_count) {
                        // normalize it if any of the types varied
                        // (i.e. one of the types lacks types in the type union)
                        //
                        // This is useful to avoid ending up with "bool|?false|true" (Will convert to "?bool")
                        return $result->asNormalizedTypes();
                    }
                }
                // Otherwise, don't normalize it - The different contexts didn't differ in the union types
                return $result;
            };

        // Clone the incoming scope so we can modify it
        // with the outgoing merged scope
        $scope = clone($this->context->getScope());

        foreach ($variable_map as $name => $variable) {
            // Skip variables that are only partially defined
            if (!$is_defined_on_all_branches($name)) {
                if ($this->context->getIsStrictTypes()) {
                    continue;
                } else {
                    // Limit the type of the variable to the subset
                    // of types that are common to all branches
                    // Record that it can be null, as the best available equivalent for undefined.
                    $variable = clone($variable);

                    $variable->setUnionType(
                        $union_type($name)
                    );

                    // TODO: convert to nullable?
                    $variable->getUnionType()->addType(
                        NullType::instance(false)
                    );

                    // Add the variable to the outgoing scope
                    $scope->addVariable($variable);
                    continue;
                }
            }

            // Limit the type of the variable to the subset
            // of types that are common to all branches
            $variable = clone($variable);

            $variable->setUnionType(
                $union_type($name)
            );

            // Add the variable to the outgoing scope
            $scope->addVariable($variable);
        }

        // Set the new scope with only the variables and types
        // that are common to all branches
        return $this->context->withScope($scope);
    }
}
