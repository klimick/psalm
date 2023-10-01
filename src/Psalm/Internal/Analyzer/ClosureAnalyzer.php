<?php

namespace Psalm\Internal\Analyzer;

use PhpParser;
use Psalm\CodeLocation;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Codebase\VariableUseGraph;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Internal\PhpVisitor\ShortClosureVisitor;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\Issue\DuplicateParam;
use Psalm\Issue\PossiblyUndefinedVariable;
use Psalm\Issue\UndefinedVariable;
use Psalm\IssueBuffer;
use Psalm\Type;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

use function in_array;
use function is_string;
use function preg_match;
use function strpos;
use function strtolower;

/**
 * @internal
 * @extends FunctionLikeAnalyzer<PhpParser\Node\Expr\Closure|PhpParser\Node\Expr\ArrowFunction>
 */
class ClosureAnalyzer extends FunctionLikeAnalyzer
{
    /**
     * @param PhpParser\Node\Expr\Closure|PhpParser\Node\Expr\ArrowFunction $function
     */
    public function __construct(PhpParser\Node\FunctionLike $function, SourceAnalyzer $source)
    {
        $codebase = $source->getCodebase();

        $function_id = strtolower($source->getFilePath())
            . ':' . $function->getLine()
            . ':' . (int)$function->getAttribute('startFilePos')
            . ':-:closure';

        $storage = $codebase->getClosureStorage($source->getFilePath(), $function_id);

        parent::__construct($function, $source, $storage);
    }


    /** @psalm-mutation-free */
    public function getTemplateTypeMap(): ?array
    {
        return $this->source->getTemplateTypeMap();
    }

    /**
     * @return non-empty-lowercase-string
     */
    public function getClosureId(): string
    {
        return strtolower($this->getFilePath())
            . ':' . $this->function->getLine()
            . ':' . (int)$this->function->getAttribute('startFilePos')
            . ':-:closure';
    }

    /**
     * @param PhpParser\Node\Expr\Closure|PhpParser\Node\Expr\ArrowFunction $stmt
     */
    public static function analyzeExpression(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\FunctionLike $stmt,
        Context $context
    ): bool {
        $closure_analyzer = new ClosureAnalyzer($stmt, $statements_analyzer);

        if ($context->inside_return) {
            self::potentiallyInferTypesOnClosureFromParentReturnType(
                $statements_analyzer,
                $closure_analyzer,
            );
        }

        if ($stmt instanceof PhpParser\Node\Expr\Closure
            && self::analyzeClosureUses($statements_analyzer, $stmt, $context) === false
        ) {
            return false;
        }

        $use_context = new Context($context->self);

        $codebase = $statements_analyzer->getCodebase();

        if (!$statements_analyzer->isStatic() && !$closure_analyzer->isStatic()) {
            if ($context->collect_mutations &&
                $context->self &&
                $codebase->classExtends(
                    $context->self,
                    (string)$statements_analyzer->getFQCLN(),
                )
            ) {
                /** @psalm-suppress PossiblyUndefinedStringArrayOffset */
                $use_context->vars_in_scope['$this'] = $context->vars_in_scope['$this'];
            } elseif ($context->self) {
                $this_atomic = new TNamedObject($context->self, true);

                $use_context->vars_in_scope['$this'] = new Union([$this_atomic]);
            }
        }

        foreach ($context->vars_in_scope as $var => $type) {
            if (strpos($var, '$this->') === 0) {
                $use_context->vars_in_scope[$var] = $type;
            }
        }

        if ($context->self) {
            $self_class_storage = $codebase->classlike_storage_provider->get($context->self);

            ClassAnalyzer::addContextProperties(
                $statements_analyzer,
                $self_class_storage,
                $use_context,
                $context->self,
                $statements_analyzer->getParentFQCLN(),
            );
        }

        foreach ($context->vars_possibly_in_scope as $var => $_) {
            if (strpos($var, '$this->') === 0) {
                $use_context->vars_possibly_in_scope[$var] = true;
            }
        }

        if ($stmt instanceof PhpParser\Node\Expr\Closure) {
            foreach ($stmt->uses as $use) {
                if (!is_string($use->var->name)) {
                    continue;
                }

                $use_var_id = '$' . $use->var->name;

                // insert the ref into the current context if passed by ref, as whatever we're passing
                // the closure to could execute it straight away.
                if ($use->byRef && !$context->hasVariable($use_var_id)) {
                    $context->vars_in_scope[$use_var_id] = new Union([new TMixed()], ['by_ref' => true]);
                }

                if ($statements_analyzer->data_flow_graph instanceof VariableUseGraph
                    && $context->hasVariable($use_var_id)
                ) {
                    $parent_nodes = $context->vars_in_scope[$use_var_id]->parent_nodes;

                    foreach ($parent_nodes as $parent_node) {
                        $statements_analyzer->data_flow_graph->addPath(
                            $parent_node,
                            new DataFlowNode('closure-use', 'closure use', null),
                            'closure-use',
                        );
                    }
                }

                $use_context->vars_in_scope[$use_var_id] =
                    $context->hasVariable($use_var_id) && !$use->byRef
                    ? $context->vars_in_scope[$use_var_id]
                    : Type::getMixed();

                if ($use->byRef) {
                    $use_context->vars_in_scope[$use_var_id] =
                        $use_context->vars_in_scope[$use_var_id]->setProperties(['by_ref' => true]);
                    $use_context->references_to_external_scope[$use_var_id] = true;
                }

                $use_context->vars_possibly_in_scope[$use_var_id] = true;

                foreach ($context->vars_in_scope as $var_id => $type) {
                    if (preg_match('/^\$' . $use->var->name . '[\[\-]/', $var_id)) {
                        $use_context->vars_in_scope[$var_id] = $type;
                        $use_context->vars_possibly_in_scope[$var_id] = true;
                    }
                }
            }
        } else {
            $traverser = new PhpParser\NodeTraverser;

            $short_closure_visitor = new ShortClosureVisitor();

            $traverser->addVisitor($short_closure_visitor);
            $traverser->traverse($stmt->getStmts());

            foreach ($short_closure_visitor->getUsedVariables() as $use_var_id => $_) {
                if ($context->hasVariable($use_var_id)) {
                    $use_context->vars_in_scope[$use_var_id] = $context->vars_in_scope[$use_var_id];

                    if ($statements_analyzer->data_flow_graph instanceof VariableUseGraph) {
                        $parent_nodes = $context->vars_in_scope[$use_var_id]->parent_nodes;

                        foreach ($parent_nodes as $parent_node) {
                            $statements_analyzer->data_flow_graph->addPath(
                                $parent_node,
                                new DataFlowNode('closure-use', 'closure use', null),
                                'closure-use',
                            );
                        }
                    }
                }

                $use_context->vars_possibly_in_scope[$use_var_id] = true;
            }
        }

        $use_context->calling_method_id = $context->calling_method_id;
        $use_context->phantom_classes = $context->phantom_classes;

        $closure_analyzer->analyze($use_context, $statements_analyzer->node_data, $context, false);

        if ($closure_analyzer->inferred_impure
            && $statements_analyzer->getSource() instanceof FunctionLikeAnalyzer
        ) {
            $statements_analyzer->getSource()->inferred_impure = true;
        }

        if ($closure_analyzer->inferred_has_mutation
            && $statements_analyzer->getSource() instanceof FunctionLikeAnalyzer
        ) {
            $statements_analyzer->getSource()->inferred_has_mutation = true;
        }

        if (!$statements_analyzer->node_data->getType($stmt)) {
            $statements_analyzer->node_data->setType($stmt, Type::getClosure());
        }

        return true;
    }

    /**
     * @return  false|null
     */
    public static function analyzeClosureUses(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\Expr\Closure $stmt,
        Context $context
    ): ?bool {
        $param_names = [];

        foreach ($stmt->params as $i => $param) {
            if ($param->var instanceof PhpParser\Node\Expr\Variable && is_string($param->var->name)) {
                $param_names[$i] = $param->var->name;
            } else {
                $param_names[$i] = '';
            }
        }

        foreach ($stmt->uses as $use) {
            if (!is_string($use->var->name)) {
                continue;
            }

            $use_var_id = '$' . $use->var->name;

            if (in_array($use->var->name, $param_names)) {
                if (IssueBuffer::accepts(
                    new DuplicateParam(
                        'Closure use duplicates param name ' . $use_var_id,
                        new CodeLocation($statements_analyzer->getSource(), $use->var),
                    ),
                    $statements_analyzer->getSuppressedIssues(),
                )) {
                    return false;
                }
            }

            if (!$context->hasVariable($use_var_id)) {
                if ($use_var_id === '$argv' || $use_var_id === '$argc') {
                    continue;
                }

                if ($use->byRef) {
                    $context->vars_in_scope[$use_var_id] = Type::getMixed();
                    $context->vars_possibly_in_scope[$use_var_id] = true;

                    if (!$statements_analyzer->hasVariable($use_var_id)) {
                        $statements_analyzer->registerVariable(
                            $use_var_id,
                            new CodeLocation($statements_analyzer, $use->var),
                            null,
                        );
                    }

                    return null;
                }

                if (!isset($context->vars_possibly_in_scope[$use_var_id])) {
                    if ($context->check_variables) {
                        if (IssueBuffer::accepts(
                            new UndefinedVariable(
                                'Cannot find referenced variable ' . $use_var_id,
                                new CodeLocation($statements_analyzer->getSource(), $use->var),
                            ),
                            $statements_analyzer->getSuppressedIssues(),
                        )) {
                            return false;
                        }

                        return null;
                    }
                }

                $first_appearance = $statements_analyzer->getFirstAppearance($use_var_id);

                if ($first_appearance) {
                    if (IssueBuffer::accepts(
                        new PossiblyUndefinedVariable(
                            'Possibly undefined variable ' . $use_var_id . ', first seen on line ' .
                                $first_appearance->getLineNumber(),
                            new CodeLocation($statements_analyzer->getSource(), $use->var),
                        ),
                        $statements_analyzer->getSuppressedIssues(),
                    )) {
                        return false;
                    }

                    continue;
                }

                if ($context->check_variables) {
                    if (IssueBuffer::accepts(
                        new UndefinedVariable(
                            'Cannot find referenced variable ' . $use_var_id,
                            new CodeLocation($statements_analyzer->getSource(), $use->var),
                        ),
                        $statements_analyzer->getSuppressedIssues(),
                    )) {
                        return false;
                    }

                    continue;
                }
            } elseif ($use->byRef) {
                $new_type = new Union([new TMixed()], [
                    'parent_nodes' => $context->vars_in_scope[$use_var_id]->parent_nodes,
                ]);

                $context->remove($use_var_id);

                $context->vars_in_scope[$use_var_id] = $new_type;
            }
        }

        return null;
    }

    /**
     * If a function returns a closure, we try to infer the param/return types of
     * the inner closure.
     *
     * @see \Psalm\Tests\ReturnTypeTest:756
     */
    private static function potentiallyInferTypesOnClosureFromParentReturnType(
        StatementsAnalyzer $statements_analyzer,
        ClosureAnalyzer $closure_analyzer
    ): void {
        $parent_source = $statements_analyzer->getSource();

        // if not returning from inside a function, return
        if (!$parent_source instanceof ClosureAnalyzer && !$parent_source instanceof FunctionAnalyzer) {
            return;
        }

        $closure_id = $closure_analyzer->getClosureId();
        $closure_storage = $statements_analyzer
            ->getCodebase()
            ->getFunctionLikeStorage($statements_analyzer, $closure_id);

        $parent_fn_storage = $parent_source->getFunctionLikeStorage($statements_analyzer);

        if ($parent_fn_storage->return_type === null) {
            return;
        }

        // can't infer returned closure if the parent doesn't have a callable return type
        if (!$parent_fn_storage->return_type->hasCallableType()) {
            return;
        }

        // cannot infer if we have union/intersection types
        if (!$parent_fn_storage->return_type->isSingle()) {
            return;
        }

        /** @var TClosure|TCallable $parent_callable_return_type */
        $parent_callable_return_type = $parent_fn_storage->return_type->getSingleAtomic();

        if ($parent_callable_return_type->params === null && $parent_callable_return_type->return_type === null) {
            return;
        }

        foreach ($closure_storage->params as $key => $param) {
            $parent_param = $parent_callable_return_type->params[$key] ?? null;
            $param->type = self::inferInnerClosureTypeFromParent(
                $statements_analyzer->getCodebase(),
                $param->type,
                $parent_param->type ?? null,
            );
        }

        $closure_storage->return_type = self::inferInnerClosureTypeFromParent(
            $statements_analyzer->getCodebase(),
            $closure_storage->return_type,
            $parent_callable_return_type->return_type,
        );

        if (!$closure_storage->template_types && $parent_callable_return_type->templates) {
            $closure_storage->template_types = $parent_callable_return_type->getTemplateMap();
        }
    }

    /**
     * - If non parent type, do nothing
     * - If no return type, infer from parent
     * - If parent return type is more specific, infer from parent
     * - else, do nothing
     */
    private static function inferInnerClosureTypeFromParent(
        Codebase $codebase,
        ?Union $return_type,
        ?Union $parent_return_type
    ): ?Union {
        if (!$parent_return_type) {
            return $return_type;
        }
        if (!$return_type || UnionTypeComparator::isContainedBy($codebase, $parent_return_type, $return_type)) {
            return $parent_return_type;
        }
        return $return_type;
    }
}
