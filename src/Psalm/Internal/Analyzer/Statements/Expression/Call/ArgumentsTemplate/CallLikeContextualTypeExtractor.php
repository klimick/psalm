<?php

declare(strict_types=1);

namespace Psalm\Internal\Analyzer\Statements\Expression\Call\ArgumentsTemplate;

use Psalm\Codebase;
use Psalm\Context;
use Psalm\ContextualTypeResolver;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Type\TemplateContextualBoundsCollector;
use Psalm\Internal\Type\TemplateResult;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\Union;

use function array_merge;

/**
 * @internal
 */
final class CallLikeContextualTypeExtractor
{
    public static function extract(
        Context $context,
        StatementsAnalyzer $statements_analyzer,
        ?FunctionLikeStorage $function_storage,
        CollectedArgumentTemplates $collected_templates,
    ): ContextualTypeResolver {
        $empty_contextual_type = Type::getNever();

        if ($function_storage === null || $context->contextual_type_resolver === null) {
            $template_result_without_contextual_bounds = new TemplateResult(
                $collected_templates->template_types,
                $collected_templates->lower_bounds,
            );

            return new ContextualTypeResolver(
                $empty_contextual_type,
                $template_result_without_contextual_bounds,
                $statements_analyzer,
            );
        }

        $return_type = $function_storage->return_type
            ?? self::getReturnTypeFromDeclaringConstructor($statements_analyzer->getCodebase(), $function_storage);

        if ($return_type === null) {
            $template_result_without_contextual_bounds = new TemplateResult(
                $collected_templates->template_types,
                $collected_templates->lower_bounds,
            );

            return new ContextualTypeResolver(
                $empty_contextual_type,
                $template_result_without_contextual_bounds,
                $statements_analyzer,
            );
        }

        $template_result_with_contextual_bounds = new TemplateResult(
            $collected_templates->template_types,
            array_merge($collected_templates->lower_bounds, TemplateContextualBoundsCollector::collect(
                $statements_analyzer,
                $context->contextual_type_resolver->resolve(),
                $return_type,
                $collected_templates->template_types,
            )),
        );

        return new ContextualTypeResolver(
            $return_type,
            $template_result_with_contextual_bounds,
            $statements_analyzer,
        );
    }

    private static function getReturnTypeFromDeclaringConstructor(
        Codebase $codebase,
        FunctionLikeStorage $ctor_storage,
    ): ?Union {
        if ($ctor_storage instanceof MethodStorage
            && $ctor_storage->cased_name === '__construct'
            && $ctor_storage->defining_fqcln !== null
        ) {
            $atomic = $codebase->classlike_storage_provider
                ->get($ctor_storage->defining_fqcln)
                ->getNamedObjectAtomic();

            return $atomic !== null ? new Union([$atomic]) : null;
        }

        return null;
    }
}
