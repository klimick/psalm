<?php

declare(strict_types=1);

namespace Psalm;

use Psalm\Internal\Type\TemplateInferredTypeReplacer;
use Psalm\Internal\Type\TemplateResult;
use Psalm\Internal\Type\TemplateStandinTypeReplacer;
use Psalm\Type\Union;

final class ContextualTypeResolver
{
    public function __construct(
        private readonly Union $contextual_type,
        private readonly TemplateResult $template_result,
        private readonly Codebase $codebase,
    ) {
    }

    public function withContextualType(Union $type): self
    {
        return new self($type, $this->template_result, $this->codebase);
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
