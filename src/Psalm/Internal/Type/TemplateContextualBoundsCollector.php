<?php

declare(strict_types=1);

namespace Psalm\Internal\Type;

use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Type\Comparator\CallableTypeComparator;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TIterable;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

use function array_map;

/**
 * @internal
 */
final class TemplateContextualBoundsCollector
{
    /** @var array<string, non-empty-array<string, non-empty-list<Atomic>>> */
    private array $collected_atomics = [];

    /**
     * @param array<string, non-empty-array<string, Union>> $template_types
     */
    private function __construct(
        private readonly StatementsAnalyzer $statements_analyzer,
        private readonly array $template_types,
    ) {
    }

    /**
     * @param array<string, non-empty-array<string, Union>> $template_types
     * @return array<string, non-empty-array<string, Union>>
     */
    public static function collect(
        StatementsAnalyzer $statements_analyzer,
        Union $contextual_type,
        Union $return_type,
        array $template_types,
    ): array {
        $collector = new self($statements_analyzer, $template_types);
        $collector->collectUnion($contextual_type, $return_type);

        $codebase = $statements_analyzer->getCodebase();

        return array_map(
            static fn(array $template_map): array => array_map(
                static fn(array $collected_atomics): Union => TypeCombiner::combine($collected_atomics, $codebase),
                $template_map,
            ),
            $collector->collected_atomics,
        );
    }

    private function collectUnion(Union $contextual_type, Union $return_type): void
    {
        foreach ($contextual_type->getAtomicTypes() as $contextual_atomic) {
            foreach ($return_type->getAtomicTypes() as $return_atomic) {
                $this->collectAtomic($contextual_atomic, $return_atomic);
            }
        }
    }

    private function collectAtomic(Atomic $contextual_atomic, Atomic $return_atomic): void
    {
        if ($return_atomic instanceof TTemplateParam) {
            $this->collectTemplateType($contextual_atomic, $return_atomic);
        } elseif ($contextual_atomic instanceof TCallable || $contextual_atomic instanceof TClosure) {
            $this->collectCallable($contextual_atomic, $return_atomic);
        } elseif ($contextual_atomic instanceof TArray || $contextual_atomic instanceof TIterable) {
            $this->collectIterable($contextual_atomic, $return_atomic);
        } elseif ($contextual_atomic instanceof TKeyedArray) {
            $this->collectKeyedArray($contextual_atomic, $return_atomic);
        } elseif ($contextual_atomic instanceof TGenericObject) {
            $this->collectGenericObject($contextual_atomic, $return_atomic);
        }
    }

    private function collectTemplateType(Atomic $contextual_atomic, TTemplateParam $return_atomic): void
    {
        foreach ($return_atomic->as->getAtomicTypes() as $extends) {
            self::collectAtomic($contextual_atomic, $extends);
        }

        $param_name = $return_atomic->param_name;
        $defining_class = $return_atomic->defining_class;

        if (isset($this->template_types[$param_name][$defining_class])) {
            $this->collected_atomics[$param_name][$defining_class][] = $contextual_atomic;
        }
    }

    private function collectCallable(TCallable|TClosure $contextual_atomic, Atomic $return_atomic): void
    {
        $codebase = $this->statements_analyzer->getCodebase();

        if ($return_atomic instanceof TNamedObject
            && $return_atomic->value !== 'Closure'
            && $codebase->classOrInterfaceExists($return_atomic->value)
            && $codebase->methodExists($return_atomic->value . '::__invoke')
        ) {
            $return_atomic = CallableTypeComparator::getCallableFromAtomic($codebase, $return_atomic);
        }

        if ($return_atomic instanceof TCallable || $return_atomic instanceof TClosure) {
            foreach ($return_atomic->params ?? [] as $offset => $return_param) {
                $contextual_param = $contextual_atomic->params[$offset] ?? null;

                if (!isset($contextual_param->type) || !isset($return_param->type)) {
                    continue;
                }

                $this->collectUnion($contextual_param->type, $return_param->type);
            }
        }
    }

    private function collectIterable(TIterable|TArray $contextual_atomic, Atomic $return_atomic): void
    {
        if ($return_atomic instanceof TArray || $return_atomic instanceof TIterable) {
            $this->collectUnion($contextual_atomic->type_params[0], $return_atomic->type_params[0]);
            $this->collectUnion($contextual_atomic->type_params[1], $return_atomic->type_params[1]);
        }
    }

    private function collectKeyedArray(TKeyedArray $contextual_atomic, Atomic $return_atomic): void
    {
        if ($return_atomic instanceof TKeyedArray
            && $contextual_atomic->is_list
            && $contextual_atomic->isSealed()
            && $return_atomic->isGenericList()
        ) {
            $this->collectUnion($contextual_atomic->getGenericValueType(), $return_atomic->getGenericValueType());
        } elseif ($return_atomic instanceof TKeyedArray) {
            foreach ($return_atomic->properties as $return_key => $return_property) {
                if (!isset($contextual_atomic->properties[$return_key])) {
                    continue;
                }

                $this->collectUnion($contextual_atomic->properties[$return_key], $return_property);
            }

            if ($contextual_atomic->fallback_params !== null && $return_atomic->fallback_params !== null) {
                $this->collectUnion($contextual_atomic->fallback_params[0], $return_atomic->fallback_params[0]);
                $this->collectUnion($contextual_atomic->fallback_params[1], $return_atomic->fallback_params[1]);
            }
        } elseif ($return_atomic instanceof TArray || $return_atomic instanceof TIterable) {
            $this->collectUnion($contextual_atomic->getGenericKeyType(), $return_atomic->type_params[0]);
            $this->collectUnion($contextual_atomic->getGenericValueType(), $return_atomic->type_params[1]);
        }
    }

    private function collectGenericObject(TGenericObject $contextual_atomic, Atomic $return_atomic): void
    {
        $codebase = $this->statements_analyzer->getCodebase();

        if ($return_atomic instanceof TGenericObject
            && $codebase->classExists($return_atomic->value)
            && $codebase->classExtends($return_atomic->value, $contextual_atomic->value)
        ) {
            $contextual_storage = $codebase->classlike_storage_provider->get($contextual_atomic->value);
            $return_storage = $codebase->classlike_storage_provider->get($return_atomic->value);

            $contextual_raw_atomic = $contextual_storage->getNamedObjectAtomic();
            $return_raw_atomic = $return_storage->getNamedObjectAtomic();

            if ($contextual_raw_atomic === null || $return_raw_atomic === null) {
                return;
            }

            $template_result = new TemplateResult($contextual_storage->template_types ?? [], []);

            TemplateStandinTypeReplacer::fillTemplateResult(
                new Union([$contextual_raw_atomic]),
                $template_result,
                $codebase,
                $this->statements_analyzer,
                new Union([$return_raw_atomic]),
            );

            $return_atomic = $contextual_raw_atomic->replaceTemplateTypesWithArgTypes($template_result, $codebase);
        }

        if ($return_atomic instanceof TIterable
            && $contextual_atomic->value === 'Generator'
        ) {
            $this->collectAtomic(new TIterable([
                $contextual_atomic->type_params[0] ?? Type::getMixed(),
                $contextual_atomic->type_params[1] ?? Type::getMixed(),
            ]), $return_atomic);
        }

        if ($return_atomic instanceof TGenericObject
            && $contextual_atomic->value === $return_atomic->value
        ) {
            foreach ($return_atomic->type_params as $offset => $return_type_param) {
                $contextual_type_param = $contextual_atomic->type_params[$offset] ?? null;

                if (!isset($contextual_type_param)) {
                    continue;
                }

                $this->collectUnion($contextual_type_param, $return_type_param);
            }
        }
    }
}
