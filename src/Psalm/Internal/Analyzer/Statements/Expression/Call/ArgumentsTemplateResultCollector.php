<?php

namespace Psalm\Internal\Analyzer\Statements\Expression\Call;

use InvalidArgumentException;
use Psalm\Context;
use Psalm\Internal\Analyzer\FunctionLikeAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Analyzer\TraitAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Type\TemplateResult;
use Psalm\Internal\Type\TemplateStandinTypeReplacer;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;
use Throwable;

use function array_map;
use function array_merge;
use function explode;
use function ltrim;
use function strpos;
use function strtolower;

/**
 * @internal
 */
final class ArgumentsTemplateResultCollector
{
    public static function collect(
        Context $context,
        StatementsAnalyzer $statements_analyzer,
        ?string $function_like_id,
        ?Atomic $lhs_type_part = null,
        bool $call_parent = false
    ): TemplateResult {
        try {
            if ($function_like_id === null || $function_like_id === '' || $function_like_id === 'object::__invoke') {
                return new TemplateResult([], []);
            }

            $function_like_storage =
                self::getFunctionStorage($function_like_id, $statements_analyzer) ??
                self::getMethodStorage($function_like_id, $statements_analyzer);

            if ($function_like_storage === null) {
                return new TemplateResult([], []);
            }

            $template_types = array_merge(
                $function_like_storage->template_types ?? [],
                self::getClassTemplates($function_like_storage, $statements_analyzer),
            );

            $lower_bounds = $lhs_type_part instanceof TNamedObject
                ? array_merge(
                    self::getClassLowerBounds($context, $statements_analyzer, $function_like_storage, $lhs_type_part),
                    self::getIfThisIsTypeLowerBounds($statements_analyzer, $function_like_storage, $lhs_type_part),
                )
                : [];

            if ($call_parent && $context->self !== null) {
                $codebase = $statements_analyzer->getCodebase();
                $self_cs = $codebase->classlike_storage_provider->get($context->self);

                foreach ($self_cs->template_extended_params ?? [] as $template_fq_class_name => $extended_types) {
                    foreach ($extended_types as $type_key => $extended_type) {
                        if (isset($lower_bounds[$type_key][$template_fq_class_name])) {
                            $lower_bounds[$type_key][$template_fq_class_name] = $extended_type;
                            continue;
                        }

                        foreach ($extended_type->getAtomicTypes() as $t) {
                            if ($t instanceof TTemplateParam
                                && isset($lower_bounds[$t->param_name][$t->defining_class])
                            ) {
                                $lower_bounds[$type_key][$template_fq_class_name]
                                    = $lower_bounds[$t->param_name][$t->defining_class];
                            } else {
                                $lower_bounds[$type_key][$template_fq_class_name]
                                    = $extended_type;
                                break;
                            }
                        }
                    }
                }
            }

            return new TemplateResult($template_types, $lower_bounds);
        } catch (Throwable $e) {
            return new TemplateResult([], []);
        }
    }

    /**
     * @param non-empty-string $function_like_id
     */
    private static function getFunctionStorage(
        string $function_like_id,
        StatementsAnalyzer $statements_analyzer
    ): ?FunctionLikeStorage {
        if (false !== strpos($function_like_id, '::')) {
            return null;
        }

        $codebase = $statements_analyzer->getCodebase();
        $function_like_id_lc = strtolower($function_like_id);

        if ($codebase->functions->existence_provider->has($function_like_id_lc)) {
            return null;
        }

        return $codebase->functions->functionExists($statements_analyzer, $function_like_id_lc)
            ? $codebase->functions->getStorage($statements_analyzer, $function_like_id_lc)
            : null;
    }

    /**
     * @param non-empty-string $function_like_id
     */
    private static function getMethodStorage(
        string $function_like_id,
        StatementsAnalyzer $statements_analyzer
    ): ?FunctionLikeStorage {
        if (false === strpos($function_like_id, '::')) {
            return null;
        }

        $function_like_id_parts = explode('::', ltrim($function_like_id, '\\'));

        if (!isset($function_like_id_parts[0]) || !isset($function_like_id_parts[1])) {
            return null;
        }

        $codebase = $statements_analyzer->getCodebase();
        [$class_name, $method_name] = $function_like_id_parts;

        $appearing_method_id = new MethodIdentifier(
            $codebase->classlikes->getUnAliasedName($class_name),
            strtolower($method_name),
        );

        $declaring_method_id = $codebase->methods->getDeclaringMethodId($appearing_method_id);

        try {
            return $codebase->methods->getStorage($declaring_method_id ?? $appearing_method_id);
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }

    /**
     * @return array<string, array<string, Union>>
     */
    private static function getClassTemplates(
        FunctionLikeStorage $function_like_storage,
        StatementsAnalyzer $statements_analyzer
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
     * @return array<string, array<string, Union>>
     */
    private static function getClassLowerBounds(
        Context $context,
        StatementsAnalyzer $statements_analyzer,
        FunctionLikeStorage $function_like_storage,
        TNamedObject $lhs_type_part
    ): array {
        if ($function_like_storage->cased_name === null) {
            return [];
        }

        $codebase = $statements_analyzer->getCodebase();
        $parent_source = $statements_analyzer->getSource();

        $fq_classlike_name = $codebase->classlikes->getUnAliasedName($lhs_type_part->value);
        $method_name_lc = strtolower($function_like_storage->cased_name);

        $class_storage = $codebase->classlike_storage_provider->get($fq_classlike_name);

        $class_method_id = new MethodIdentifier($fq_classlike_name, $method_name_lc);
        $self_call = !$statements_analyzer->isStatic() && $class_method_id->fq_class_name === $context->self;

        $class_lower_bounds = ClassTemplateParamCollector::collect(
            $codebase,
            $codebase->methods->getClassLikeStorageForMethod($class_method_id),
            $class_storage,
            $method_name_lc,
            $lhs_type_part,
            $self_call,
        ) ?? [];

        if ($self_call && $parent_source instanceof FunctionLikeAnalyzer) {
            $grandparent_source = $parent_source->getSource();

            if (!$grandparent_source instanceof TraitAnalyzer) {
                return $class_lower_bounds;
            }

            $fq_trait_name_lc = strtolower($grandparent_source->getFQCLN());
            $trait_storage = $codebase->classlike_storage_provider->get($fq_trait_name_lc);

            if (!isset($trait_storage->methods[$method_name_lc])) {
                return $class_lower_bounds;
            }

            $trait_method_id = new MethodIdentifier($trait_storage->name, $method_name_lc);

            return ClassTemplateParamCollector::collect(
                $codebase,
                $codebase->methods->getClassLikeStorageForMethod($trait_method_id),
                $class_storage,
                $method_name_lc,
                $lhs_type_part,
                true,
            ) ?? [];
        }

        return $class_lower_bounds;
    }

    /**
     * @return array<string, array<string, Union>>
     */
    private static function getIfThisIsTypeLowerBounds(
        StatementsAnalyzer $statements_analyzer,
        FunctionLikeStorage $function_like_storage,
        TNamedObject $lhs_type_part
    ): array {
        $codebase = $statements_analyzer->getCodebase();

        if ($function_like_storage instanceof MethodStorage && $function_like_storage->if_this_is_type !== null) {
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

        return [];
    }
}
