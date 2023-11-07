<?php

namespace Psalm\Internal\Analyzer;

use Psalm\ContextualTypeResolver;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClosure;

use function count;

/**
 * @internal
 */
final class ClosureAnalyzerContextualTypeExtractor
{
    /**
     * @return null|TClosure|TCallable
     */
    public static function extract(ContextualTypeResolver $contextual_type_resolver): ?Atomic
    {
        $candidates = [];

        $atomics = $contextual_type_resolver
            ->resolve()
            ->getAtomicTypes();

        foreach ($atomics as $atomic) {
            if ($atomic instanceof TClosure || $atomic instanceof TCallable) {
                $candidates[] = $atomic;
            }
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }
}
