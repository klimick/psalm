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
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

use function array_map;
use function array_merge;
use function strtolower;

/**
 * @internal
 */
final class CreateTemplateResult
{
    public static function forMethod(
        CallLike $stmt,
        Context $context,
        Codebase $codebase,
        StatementsAnalyzer $statements_analyzer,
        Atomic $lhs_type_part,
        ?MethodStorage $method_storage,
        ClassLikeStorage $class_storage,
    ): CollectedArgumentTemplates {
        if ($method_storage === null ||
            $method_storage->cased_name === null ||
            $method_storage->defining_fqcln === null ||
            $method_storage->defining_fqcln === ''
        ) {
            return new CollectedArgumentTemplates();
        }

        $method_name_lc = strtolower($method_storage->cased_name);
        $self_call = !$statements_analyzer->isStatic() && $class_storage->name === $context->self;

        if ($self_call) {
            $trait_lower_bounds = self::getTraitLowerBounds(
                static_class_storage: $class_storage,
                statements_analyzer: $statements_analyzer,
                lhs_type_part: $lhs_type_part,
                method_name_lc: $method_name_lc,
            );

            if ($trait_lower_bounds !== null) {
                return new CollectedArgumentTemplates(
                    template_types: array_merge(
                        $class_storage->template_types ?? [],
                        $method_storage->template_types ?? [],
                    ),
                    lower_bounds: $trait_lower_bounds,
                );
            }
        }

        $class_lower_bounds = ClassTemplateParamCollector::collect(
            codebase: $codebase,
            class_storage: $codebase->methods->getClassLikeStorageForMethod(
                new MethodIdentifier($method_storage->defining_fqcln, $method_name_lc),
            ),
            static_class_storage: $class_storage,
            method_name: $method_name_lc,
            lhs_type_part: $lhs_type_part,
            self_call: $self_call,
        ) ?? [];

        return new CollectedArgumentTemplates(
            template_types: array_merge(
                $class_storage->template_types ?? [],
                $method_storage->template_types ?? [],
            ),
            lower_bounds: array_merge(
                self::isParentCall($stmt, $context)
                    ? self::mapSelfBoundsToParentBounds(
                        self_class_storage: $codebase->classlike_storage_provider->get($context->self),
                        self_bounds: $class_lower_bounds,
                    )
                    : $class_lower_bounds,
                self::getIfThisIsTypeLowerBounds($statements_analyzer, $method_storage, $lhs_type_part),
            ),
        );
    }

    /**
     * @psalm-assert-if-true string $context->self
     */
    private static function isParentCall(CallLike $stmt, Context $context): bool
    {
        return $context->self !== null
            && $stmt instanceof StaticCall
            && $stmt->class instanceof Name
            && $stmt->class->getParts() === ['parent'];
    }

    /**
     * @param array<string, non-empty-array<string, Union>> $self_bounds
     * @return array<string, non-empty-array<string, Union>>
     */
    private static function mapSelfBoundsToParentBounds(ClassLikeStorage $self_class_storage, array $self_bounds): array
    {
        $parent_bounds = $self_bounds;

        foreach ($self_class_storage->template_extended_params ?? [] as $template_fq_class_name => $extended_types) {
            foreach ($extended_types as $type_key => $extended_type) {
                if (isset($parent_bounds[$type_key][$template_fq_class_name])) {
                    $parent_bounds[$type_key][$template_fq_class_name] = $extended_type;
                    continue;
                }

                foreach ($extended_type->getAtomicTypes() as $t) {
                    $parent_bounds[$type_key][$template_fq_class_name] =
                        $t instanceof TTemplateParam && isset($parent_bounds[$t->param_name][$t->defining_class])
                            ? $parent_bounds[$t->param_name][$t->defining_class]
                            : $extended_type;
                }
            }
        }

        return $parent_bounds;
    }

    /**
     * @param lowercase-string $method_name_lc
     * @return ?array<string, non-empty-array<string, Union>>
     */
    private static function getTraitLowerBounds(
        ClassLikeStorage $static_class_storage,
        StatementsAnalyzer $statements_analyzer,
        Atomic $lhs_type_part,
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

        $codebase = $statements_analyzer->getCodebase();
        $trait_storage = $codebase->classlike_storage_provider->get($grandparent_source->getFQCLN());

        return isset($trait_storage->methods[$method_name_lc])
            ? ClassTemplateParamCollector::collect(
                codebase: $codebase,
                class_storage: $trait_storage,
                static_class_storage: $static_class_storage,
                method_name: $method_name_lc,
                lhs_type_part: $lhs_type_part,
                self_call: true,
            )
            : null;
    }

    /**
     * @return array<string, array<string, Union>>
     */
    private static function getIfThisIsTypeLowerBounds(
        StatementsAnalyzer $statements_analyzer,
        MethodStorage $method_storage,
        Atomic $lhs_type_part,
    ): array {
        if ($method_storage->if_this_is_type === null) {
            return [];
        }

        $codebase = $statements_analyzer->getCodebase();
        $if_this_is_template_result = new TemplateResult($method_storage->template_types ?? [], []);

        TemplateStandinTypeReplacer::fillTemplateResult(
            $method_storage->if_this_is_type,
            $if_this_is_template_result,
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
            $if_this_is_template_result->lower_bounds,
        );
    }
}
