<?php

declare(strict_types=1);

namespace Psalm\Internal\Analyzer\Statements\Expression\Call\ArgumentsTemplate;

use Psalm\Type\Union;

/**
 * @internal
 */
final class CollectedArgumentTemplates
{
    /**
     * @param array<string, non-empty-array<string, Union>> $template_types
     * @param array<string, non-empty-array<string, Union>> $lower_bounds
     */
    public function __construct(
        public readonly array $template_types = [],
        public readonly array $lower_bounds = [],
    ) {
    }
}
