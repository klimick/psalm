<?php

declare(strict_types=1);

namespace Psalm\Internal\Analyzer\Statements\Expression\Call\ArgumentsTemplate;

use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\FunctionLikeAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\ClassTemplateParamCollector;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Analyzer\TraitAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Type\TemplateResult;
use Psalm\Internal\Type\TemplateStandinTypeReplacer;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

use function array_map;
use function array_merge;
use function strtolower;

/**
 * @internal
 */
final class ArgumentsTemplateResultCollector
{
    public static function collect(
        CallLike $stmt,
        Context $context,
        StatementsAnalyzer $statements_analyzer,
        ?FunctionLikeStorage $function_storage,
        ?Atomic $lhs_type_part = null,
    ): CollectedArgumentTemplates {
        return new CollectedArgumentTemplates(
            array_merge(
                $function_storage !== null ? $function_storage->template_types ?? [] : [],
                $function_storage !== null ? self::getClassTemplates($function_storage, $statements_analyzer) : [],
            ),
            $function_storage !== null && $lhs_type_part instanceof TNamedObject && !$lhs_type_part instanceof TClosure
                ? array_merge(
                    self::getClassLowerBounds($stmt, $context, $statements_analyzer, $function_storage, $lhs_type_part),
                    self::getIfThisIsTypeLowerBounds($statements_analyzer, $function_storage, $lhs_type_part),
                )
                : [],
        );
    }

    /**
     * @return array<string, array<string, Union>>
     */
    private static function getClassTemplates(
        FunctionLikeStorage $function_like_storage,
        StatementsAnalyzer $statements_analyzer,
    ): array {
        $codebase = $statements_analyzer->getCodebase();

        if ($function_like_storage instanceof MethodStorage
            && $function_like_storage->defining_fqcln
        ) {
            $classlike_storage = $codebase->classlikes->getStorageFor($function_like_storage->defining_fqcln);

            return $classlike_storage !== null
                ? $classlike_storage->template_types ?? []
                : [];
        }

        return [];
    }

    /**
     * @return array<string, non-empty-array<string, Union>>
     */
    private static function getClassLowerBounds(
        CallLike $stmt,
        Context $context,
        StatementsAnalyzer $statements_analyzer,
        FunctionLikeStorage $function_like_storage,
        TNamedObject $lhs_type_part,
    ): array {
        if ($function_like_storage->cased_name === null
            || !$function_like_storage instanceof MethodStorage
            || $function_like_storage->defining_fqcln === null
        ) {
            return [];
        }

        $codebase = $statements_analyzer->getCodebase();

        $fq_classlike_name = $codebase->classlikes->getUnAliasedName($lhs_type_part->value);
        $method_name_lc = strtolower($function_like_storage->cased_name);

        $method_id = new MethodIdentifier(
            $function_like_storage->defining_fqcln,
            strtolower($function_like_storage->cased_name),
        );

        $self_call = !$statements_analyzer->isStatic() && $method_id->fq_class_name === $context->self;

        if ($self_call) {
            $trait_lower_bounds = self::getTraitLowerBounds(
                $codebase,
                $statements_analyzer,
                $lhs_type_part,
                $fq_classlike_name,
                $method_name_lc,
            );

            if ($trait_lower_bounds !== null) {
                return $trait_lower_bounds;
            }
        }

        $lower_bounds = ClassTemplateParamCollector::collect(
            $codebase,
            $codebase->methods->getClassLikeStorageForMethod($method_id),
            $codebase->classlike_storage_provider->get($fq_classlike_name),
            $method_name_lc,
            $lhs_type_part,
            $self_call,
        ) ?? [];

        $parent_call = $stmt instanceof StaticCall
            && $stmt->class instanceof Name
            && $stmt->class->getParts() === ['parent'];

        $template_extended_params = $parent_call && $context->self !== null
            ? $codebase->classlike_storage_provider->get($context->self)->template_extended_params ?? []
            : [];

        foreach ($template_extended_params as $template_fq_class_name => $extended_types) {
            foreach ($extended_types as $type_key => $extended_type) {
                if (isset($lower_bounds[$type_key][$template_fq_class_name])) {
                    $lower_bounds[$type_key][$template_fq_class_name] = $extended_type;
                    continue;
                }

                foreach ($extended_type->getAtomicTypes() as $t) {
                    $lower_bounds[$type_key][$template_fq_class_name] =
                        $t instanceof TTemplateParam && isset($lower_bounds[$t->param_name][$t->defining_class])
                            ? $lower_bounds[$t->param_name][$t->defining_class]
                            : $extended_type;
                }
            }
        }

        return $lower_bounds;
    }

    /**
     * @param lowercase-string $method_name_lc
     * @return array<string, non-empty-array<string, Union>>
     */
    private static function getTraitLowerBounds(
        Codebase $codebase,
        StatementsAnalyzer $statements_analyzer,
        TNamedObject $lhs_type_part,
        string $fq_classlike_name,
        string $method_name_lc,
    ): ?array {
        $parent_source = $statements_analyzer->getSource();

        if (!$parent_source instanceof FunctionLikeAnalyzer) {
            return null;
        }

        $grandparent_source = $parent_source->getSource();

        if (!$grandparent_source instanceof TraitAnalyzer) {
            return null;
        }

        $fq_trait_name_lc = strtolower($grandparent_source->getFQCLN());
        $trait_storage = $codebase->classlike_storage_provider->get($fq_trait_name_lc);

        if (!isset($trait_storage->methods[$method_name_lc])) {
            return null;
        }

        $trait_method_id = new MethodIdentifier($trait_storage->name, $method_name_lc);

        return ClassTemplateParamCollector::collect(
            $codebase,
            $codebase->methods->getClassLikeStorageForMethod($trait_method_id),
            $codebase->classlike_storage_provider->get($fq_classlike_name),
            $method_name_lc,
            $lhs_type_part,
            true,
        ) ?? [];
    }

    /**
     * @return array<string, array<string, Union>>
     */
    private static function getIfThisIsTypeLowerBounds(
        StatementsAnalyzer $statements_analyzer,
        FunctionLikeStorage $function_like_storage,
        TNamedObject $lhs_type_part,
    ): array {
        $codebase = $statements_analyzer->getCodebase();

        if (!$function_like_storage instanceof MethodStorage
            || $function_like_storage->if_this_is_type === null
        ) {
            return [];
        }

        $method_template_result = new TemplateResult($function_like_storage->template_types ?: [], []);

        TemplateStandinTypeReplacer::fillTemplateResult(
            $function_like_storage->if_this_is_type,
            $method_template_result,
            $codebase,
            $statements_analyzer,
            new Union([$lhs_type_part]),
        );

        return array_map(
            static fn(array $template_map): array => array_map(
                static fn(array $lower_bounds): Union => TemplateStandinTypeReplacer::getMostSpecificTypeFromBounds(
                    $lower_bounds,
                    $codebase,
                ),
                $template_map,
            ),
            $method_template_result->lower_bounds,
        );
    }
}
