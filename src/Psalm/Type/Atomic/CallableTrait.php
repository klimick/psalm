<?php

namespace Psalm\Type\Atomic;

use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Type\TemplateInferredTypeReplacer;
use Psalm\Internal\Type\TemplateResult;
use Psalm\Internal\Type\TemplateStandinTypeReplacer;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type\Atomic;
use Psalm\Type\Union;

use function array_values;
use function count;
use function implode;
use function ksort;

/**
 * @psalm-immutable
 */
trait CallableTrait
{
    /**
     * @var list<FunctionLikeParameter>|null
     */
    public $params = [];

    /**
     * @var Union|null
     */
    public $return_type;

    /**
     * @var ?bool
     */
    public $is_pure;

    /**
     * @var ?non-empty-list<TTemplateParam>
     */
    public $templates = null;

    /**
     * Constructs a new instance of a generic type
     *
     * @param list<FunctionLikeParameter> $params
     * @deprecated
     */
    public function __construct(
        string $value = 'callable',
        ?array $params = null,
        ?Union $return_type = null,
        ?bool $is_pure = null,
        bool $from_docblock = false
    ) {
        $this->value = $value;
        $this->params = $params;
        $this->return_type = $return_type;
        $this->is_pure = $is_pure;
        $this->from_docblock = $from_docblock;
    }

    /**
     * @return array<string, non-empty-array<string, Union>>
     */
    public function getTemplateMap(): array
    {
        $anonymous_template_type_map = [];

        foreach ($this->templates ?? [] as $template_param) {
            $anonymous_template_type_map[$template_param->param_name] = [
                'anonymous-fn' => $template_param->as,
            ];
        }

        return $anonymous_template_type_map;
    }

    /**
     * @param list<FunctionLikeParameter>|null $params
     * @return static
     */
    public function replace(?array $params, ?Union $return_type): self
    {
        if ($this->params === $params && $this->return_type === $return_type) {
            return $this;
        }
        $cloned = clone $this;
        $cloned->params = $params;
        $cloned->return_type = $return_type;
        return $cloned;
    }
    /** @return static */
    public function setIsPure(bool $is_pure): self
    {
        if ($this->is_pure === $is_pure) {
            return $this;
        }
        $cloned = clone $this;
        $cloned->is_pure = $is_pure;
        return $cloned;
    }

    public function getParamString(): string
    {
        $param_string = '';
        if ($this->params !== null) {
            $param_string .= '(';
            foreach ($this->params as $i => $param) {
                if ($i) {
                    $param_string .= ', ';
                }

                $param_string .= $param->getId();
            }

            $param_string .= ')';
        }

        return $param_string;
    }

    public function getTemplatesString(): ?string
    {
        $templates_string = '';
        if ($this->templates !== null) {
            $templates_string .= '<';
            foreach ($this->templates as $i => $template) {
                if ($i) {
                    $templates_string .= ', ';
                }

                $templates_string .= $template->getId();
            }

            $templates_string .= '>';
        }

        return $templates_string;
    }

    public function getReturnTypeString(): string
    {
        $return_type_string = '';

        if ($this->return_type !== null) {
            $return_type_multiple = count($this->return_type->getAtomicTypes()) > 1;
            $return_type_string = ':' . ($return_type_multiple ? '(' : '')
                . $this->return_type->getId() . ($return_type_multiple ? ')' : '');
        }

        return $return_type_string;
    }

    public function getKey(bool $include_extra = true): string
    {
        $templates_string = $this->getTemplatesString();
        $param_string = $this->getParamString();
        $return_type_string = $this->getReturnTypeString();

        return ($this->is_pure ? 'pure-' : ($this->is_pure === null ? '' : 'impure-'))
            . $this->value . $templates_string . $param_string . $return_type_string;
    }

    /**
     * @param  array<lowercase-string, string> $aliased_classes
     */
    public function toNamespacedString(
        ?string $namespace,
        array $aliased_classes,
        ?string $this_class,
        bool $use_phpdoc_format
    ): string {
        if ($use_phpdoc_format) {
            if ($this instanceof TNamedObject) {
                return parent::toNamespacedString($namespace, $aliased_classes, $this_class, true);
            }

            return $this->value;
        }

        $param_string = '';
        $return_type_string = '';

        if ($this->params !== null) {
            $params_array = [];

            foreach ($this->params as $param) {
                if (!$param->type) {
                    $type_string = 'mixed';
                } else {
                    $type_string = $param->type->toNamespacedString($namespace, $aliased_classes, $this_class, false);
                }

                $params_array[] = ($param->is_variadic ? '...' : '') . $type_string . ($param->is_optional ? '=' : '');
            }

            $param_string = '(' . implode(', ', $params_array) . ')';
        }

        if ($this->return_type !== null) {
            $return_type_multiple = count($this->return_type->getAtomicTypes()) > 1;

            $return_type_string = ':' . ($return_type_multiple ? '(' : '') . $this->return_type->toNamespacedString(
                $namespace,
                $aliased_classes,
                $this_class,
                false,
            ) . ($return_type_multiple ? ')' : '');
        }

        if ($this instanceof TNamedObject) {
            return parent::toNamespacedString($namespace, $aliased_classes, $this_class, true)
                . $param_string . $return_type_string;
        }

        return ($this->is_pure ? 'pure-' : '') . 'callable' . $param_string . $return_type_string;
    }

    /**
     * @param  array<lowercase-string, string> $aliased_classes
     */
    public function toPhpString(
        ?string $namespace,
        array $aliased_classes,
        ?string $this_class,
        int $analysis_php_version_id
    ): string {
        if ($this instanceof TNamedObject) {
            return parent::toNamespacedString($namespace, $aliased_classes, $this_class, true);
        }

        return $this->value;
    }

    public function getId(bool $exact = true, bool $nested = false): string
    {
        $templates_string = '';
        $param_string = '';
        $return_type_string = '';

        if ($this->params !== null) {
            $param_string .= '(';
            foreach ($this->params as $i => $param) {
                if ($i) {
                    $param_string .= ', ';
                }

                $param_string .= $param->getId();
            }

            $param_string .= ')';
        }

        if ($this->templates !== null) {
            $templates_string .= '<';
            foreach ($this->templates as $i => $template) {
                if ($i) {
                    $templates_string .= ', ';
                }

                $templates_string .= $template->getId();
            }

            $templates_string .= '>';
        }

        if ($this->return_type !== null) {
            $return_type_multiple = count($this->return_type->getAtomicTypes()) > 1;
            $return_type_string = ':' . ($return_type_multiple ? '(' : '')
                . $this->return_type->getId($exact) . ($return_type_multiple ? ')' : '');
        }

        return ($this->is_pure ? 'pure-' : ($this->is_pure === null ? '' : 'impure-'))
            . $this->value . $templates_string . $param_string . $return_type_string;
    }

    /**
     * @param TCallable|TClosure $container_callable
     * @return static
     */
    public function replaceGenericCallableWithContextualType(Atomic $container_callable, Codebase $codebase): Atomic
    {
        if ($container_callable->params === null || $this->params === null) {
            return $this;
        }

        $input_callable_template_result = new TemplateResult(
            $this->getTemplateMap(),
            [],
        );

        foreach ($container_callable->params as $offset => $container_param) {
            $input_param = $this->params[$offset] ?? null;

            if ($input_param === null
                || $input_param->type === null
                || $container_param->type === null
            ) {
                continue;
            }

            /** @psalm-suppress ImpureMethodCall */
            TemplateStandinTypeReplacer::fillTemplateResult(
                $input_param->type,
                $input_callable_template_result,
                $codebase,
                null,
                $container_param->type,
            );
        }

        return $this->replaceTemplateTypesWithArgTypes(
            $input_callable_template_result,
            $codebase,
        );
    }

    /**
     * @param TCallable|TClosure $container_callable
     * @return static
     */
    public function fillTemplateResult(
        Atomic $container_callable,
        Codebase $codebase,
        TemplateResult $template_result
    ): Atomic {
        if ($container_callable->params === null || $this->params === null) {
            return $this;
        }

        foreach ($container_callable->params as $offset => $container_param) {
            $input_param = $this->params[$offset] ?? null;

            if ($input_param === null || $input_param->type === null || $container_param->type === null) {
                continue;
            }

            /** @psalm-suppress ImpureMethodCall */
            TemplateStandinTypeReplacer::fillTemplateResult(
                $container_param->type,
                $template_result,
                $codebase,
                null,
                $input_param->type,
            );
        }

        if ($container_callable->return_type !== null) {
            /** @psalm-suppress ImpureMethodCall */
            TemplateStandinTypeReplacer::fillTemplateResult(
                $container_callable->return_type,
                $template_result,
                $codebase,
                null,
                $this->return_type,
            );
        }

        return $this;
    }

    /**
     * @return static
     */
    public function resolveTemplateCollisions(TemplateResult $template_result, Codebase $codebase): Atomic
    {
        $collisions = [];

        foreach ($this->templates ?? [] as $t) {
            if (!isset($template_result->used_anonymous_template_names[$t->param_name])) {
                $template_result->used_anonymous_template_names[$t->param_name] = 1;
                continue;
            }

            $param_offset = $template_result->used_anonymous_template_names[$t->param_name]++;

            $collisions[$t->param_name][$t->defining_class] = new Union([
                $t->replaceParamName($t->param_name.$param_offset),
            ]);
        }

        return $collisions !== []
            ? $this->replaceTemplateTypesWithArgTypes(new TemplateResult([], $collisions), $codebase)
            : $this;
    }

    /**
     * @return array{list<FunctionLikeParameter>|null, Union|null}|null
     */
    protected function replaceCallableTemplateTypesWithStandins(
        TemplateResult $template_result,
        Codebase $codebase,
        ?StatementsAnalyzer $statements_analyzer = null,
        ?Atomic $input_type = null,
        ?int $input_arg_offset = null,
        ?string $calling_class = null,
        ?string $calling_function = null,
        bool $replace = true,
        bool $add_lower_bound = false,
        int $depth = 0
    ): ?array {
        if (($input_type instanceof TClosure || $input_type instanceof TCallable)
            && $input_type->templates !== null
            && $this->templates === null
        ) {
            $input_type = null !== $template_result->contextual_template_result
                ? $input_type->replaceGenericCallableWithContextualType(
                    $this->replaceTemplateTypesWithArgTypes($template_result->contextual_template_result, $codebase),
                    $codebase,
                )
                : $input_type
                    ->resolveTemplateCollisions($template_result, $codebase)
                    ->fillTemplateResult(
                        $this->replaceTemplateTypesWithArgTypes($template_result, $codebase),
                        $codebase,
                        $template_result,
                    );
        }

        $replaced = false;
        $params = $this->params;
        if ($params) {
            foreach ($params as $offset => $param) {
                if (!$param->type) {
                    continue;
                }

                $input_param_type = null;

                if (($input_type instanceof TClosure || $input_type instanceof TCallable)
                    && isset($input_type->params[$offset])
                ) {
                    $input_param_type = $input_type->params[$offset]->type;
                }

                $new_param = $param->setType(TemplateStandinTypeReplacer::replace(
                    $param->type,
                    $template_result,
                    $codebase,
                    $statements_analyzer,
                    $input_param_type,
                    $input_arg_offset,
                    $calling_class,
                    $calling_function,
                    $replace,
                    !$add_lower_bound,
                    null,
                    $depth,
                ));
                $replaced = $replaced || $new_param !== $param;
                $params[$offset] = $new_param;
            }
        }

        $return_type = $this->return_type;
        if ($return_type) {
            $return_type = TemplateStandinTypeReplacer::replace(
                $return_type,
                $template_result,
                $codebase,
                $statements_analyzer,
                $input_type instanceof TCallable || $input_type instanceof TClosure
                    ? $input_type->return_type
                    : null,
                $input_arg_offset,
                $calling_class,
                $calling_function,
                $replace,
                $add_lower_bound,
            );
            $replaced = $replaced || $this->return_type !== $return_type;
        }

        if ($replaced) {
            return [$params, $return_type];
        }
        return null;
    }

    /**
     * @return static
     */
    public function toGeneralizedRepresentation(Codebase $codebase): Atomic
    {
        if ($this->templates === null) {
            return $this;
        }

        $templates = [];

        foreach ($this->templates as $template) {
            $templates[$template->param_name] = $template;
        }

        ksort($templates);

        $offset = 0;
        $generalized_templates = [];

        foreach ($templates as $template) {
            $generalized_templates[$template->param_name][$template->defining_class] = new Union([
                $template->toGeneralized('T'.$offset),
            ]);

            $offset++;
        }

        return $this->replaceTemplateTypesWithArgTypes(new TemplateResult([], $generalized_templates), $codebase);
    }

    /**
     * @return array{list<FunctionLikeParameter>|null, Union|null, non-empty-list<TTemplateParam>|null}|null
     */
    protected function replaceCallableTemplateTypesWithArgTypes(
        TemplateResult $template_result,
        ?Codebase $codebase
    ): ?array {
        $replaced = false;

        $templates = [];
        $params = $this->params;

        if ($params) {
            $locally_defined_anon_templates = [];

            foreach ($this->templates ?? [] as $t) {
                $locally_defined_anon_templates[$t->param_name] = true;
            }

            foreach ($params as $k => $param) {
                if ($param->type) {
                    $was_defined_anon_template = $template_result->defined_anon_template;
                    /** @psalm-suppress ImpurePropertyAssignment */
                    $template_result->defined_anon_template = $locally_defined_anon_templates;

                    $type = TemplateInferredTypeReplacer::replace(
                        $param->type,
                        $template_result,
                        $codebase,
                    );

                    /** @psalm-suppress ImpurePropertyAssignment */
                    $template_result->defined_anon_template = $was_defined_anon_template;

                    foreach ($type->getTemplateTypes() as $t) {
                        if ($t->defining_class === 'anonymous-fn'
                            && !isset($template_result->defined_anon_template[$t->param_name])
                            && !isset($templates[$t->param_name])
                        ) {
                            $template_result->defined_anon_template[$t->param_name] = true;
                            $templates[$t->param_name] = $t;
                        }
                    }

                    $new_param = $param->setType($type);

                    $replaced = $replaced || $new_param !== $param;
                    $params[$k] = $new_param;
                }
            }
        }

        $return_type = $this->return_type;

        if ($return_type) {
            $return_type = TemplateInferredTypeReplacer::replace(
                $return_type,
                $template_result,
                $codebase,
            );

            $replaced = $replaced || $return_type !== $this->return_type;
        }

        /** @psalm-suppress ImpurePropertyAssignment */
        $template_result->defined_anon_template = [];

        if ($replaced) {
            return [$params, $return_type, $templates !== [] ? array_values($templates) : null];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function getCallableChildNodeKeys(): array
    {
        return ['params', 'return_type'];
    }
}
