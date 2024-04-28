<?php

declare(strict_types=1);

namespace Psalm\Internal\Analyzer\Statements\Expression;

use Psalm\ContextualTypeResolver;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

/**
 * @internal
 */
final class ArrayAnalyzerContextualTypeExtractor
{
    public static function extract(
        Union $array_key_type,
        ContextualTypeResolver $contextual_type_resolver,
    ): ?ContextualTypeResolver {
        $codebase = $contextual_type_resolver->getCodebase();
        $contextual_type = $contextual_type_resolver->resolve();

        if (!$contextual_type->hasArray()) {
            return null;
        }

        $contextual_atomic = $contextual_type->getArray();

        if ($contextual_atomic instanceof TArray) {
            $contextual_key_type = $contextual_atomic->type_params[0];

            if ($contextual_key_type->isSingle()) {
                $contextual_key_atomic = $contextual_key_type->getSingleAtomic();

                if ($contextual_key_atomic instanceof TTemplateParam) {
                    $contextual_key_type = $contextual_key_atomic->as;
                }
            }

            return $codebase->isTypeContainedByType($array_key_type, $contextual_key_type)
                ? $contextual_type_resolver->withContextualType($contextual_atomic->type_params[1])
                : null;
        }

        if ($contextual_atomic instanceof TKeyedArray) {
            if ($array_key_type->isInt() && $contextual_atomic->isGenericList()) {
                return $contextual_type_resolver->withContextualType($contextual_atomic->getGenericValueType());
            }

            if ($array_key_type->isSingleStringLiteral()) {
                $literal = $array_key_type->getSingleStringLiteral()->value;

                return isset($contextual_atomic->properties[$literal])
                    ? $contextual_type_resolver->withContextualType($contextual_atomic->properties[$literal])
                    : null;
            }

            if ($array_key_type->isSingleIntLiteral()) {
                $literal = $array_key_type->getSingleIntLiteral()->value;

                return isset($contextual_atomic->properties[$literal])
                    ? $contextual_type_resolver->withContextualType($contextual_atomic->properties[$literal])
                    : null;
            }
        }

        return null;
    }
}
