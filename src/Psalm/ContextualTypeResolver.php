<?php

declare(strict_types=1);

namespace Psalm;

use Psalm\Internal\Type\TemplateInferredTypeReplacer;
use Psalm\Internal\Type\TemplateResult;
use Psalm\Internal\Type\TemplateStandinTypeReplacer;
use Psalm\Type\Union;

final class ContextualTypeResolver
{
    private Union $contextual_type;
    private TemplateResult $template_result;
    private Codebase $codebase;

    public function __construct(
        Union $contextual_type,
        TemplateResult $template_result,
        Codebase $codebase,
    ) {
        $this->contextual_type = $contextual_type;
        $this->template_result = $template_result;
        $this->codebase = $codebase;
    }

    public function getCodebase(): Codebase
    {
        return $this->codebase;
    }

    /**
     * @return ($type is Union ? self : null)
     */
    public function withContextualType(?Union $type): ?self
    {
        return $type !== null
            ? new self($type, $this->template_result, $this->codebase)
            : null;
    }

    public function resolve(): Union
    {
        return TemplateInferredTypeReplacer::replace(
            $this->contextual_type,
            $this->template_result,
            $this->codebase,
        );
    }

    public function fillTemplateResult(Union $input_type): void
    {
        TemplateStandinTypeReplacer::fillTemplateResult(
            $this->contextual_type,
            $this->template_result,
            $this->codebase,
            null,
            $input_type,
        );
    }
}
