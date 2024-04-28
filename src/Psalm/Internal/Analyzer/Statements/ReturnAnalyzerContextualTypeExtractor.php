<?php

declare(strict_types=1);

namespace Psalm\Internal\Analyzer\Statements;

use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClosureAnalyzer;
use Psalm\Internal\Analyzer\FunctionAnalyzer;
use Psalm\Internal\Analyzer\MethodAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\ClassTemplateParamCollector;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Type\TemplateInferredTypeReplacer;
use Psalm\Internal\Type\TemplateResult;
use Psalm\Type\Union;

/**
 * @internal
 */
final class ReturnAnalyzerContextualTypeExtractor
{
    public static function extract(Codebase $codebase, StatementsAnalyzer $statements_analyzer): ?Union
    {
        $parent_source = $statements_analyzer->getSource();

        if ($parent_source instanceof ClosureAnalyzer && $parent_source->possibly_return_type !== null) {
            return $parent_source->possibly_return_type;
        }

        if ($parent_source instanceof MethodAnalyzer) {
            $method_identifier = $parent_source->getMethodId();

            // $codebase->methods->getMethodReturnType gets it by ref.
            $self_class = null;

            $method_return_type = $codebase->methods->getMethodReturnType(
                $method_identifier,
                $self_class,
                $statements_analyzer,
            );

            if ($method_return_type === null) {
                return null;
            }

            $class_storage = $codebase->methods->getClassLikeStorageForMethod($method_identifier);

            $found_generic_params = ClassTemplateParamCollector::collect(
                $codebase,
                $class_storage,
                $class_storage,
                $method_identifier->method_name,
                null,
                true,
            );

            $template_result = new TemplateResult(
                $class_storage->template_types ?? [],
                $found_generic_params ?? [],
            );

            return TemplateInferredTypeReplacer::replace($method_return_type, $template_result, $codebase);
        }

        if ($parent_source instanceof FunctionAnalyzer) {
            return $parent_source
                ->getFunctionLikeStorage($statements_analyzer)
                ->return_type;
        }

        return null;
    }
}
