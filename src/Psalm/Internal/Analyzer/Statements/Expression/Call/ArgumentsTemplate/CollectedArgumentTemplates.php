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
     * @var array<string, non-empty-array<string, Union>>
     * @psalm-readonly
     */
    public array $template_types;

    /**
     * @var array<string, non-empty-array<string, Union>>
     * @psalm-readonly
     */
    public array $lower_bounds;

    /**
     * @param array<string, non-empty-array<string, Union>> $template_types
     * @param array<string, non-empty-array<string, Union>> $lower_bounds
     */
    public function __construct(
        array $template_types = [],
        array $lower_bounds = [],
    ) {
        $this->template_types = $template_types;
        $this->lower_bounds = $lower_bounds;
    }
}
