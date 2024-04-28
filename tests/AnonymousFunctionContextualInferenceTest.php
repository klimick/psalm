<?php

declare(strict_types=1);

namespace Psalm\Tests;

use Psalm\Tests\Traits\ValidCodeAnalysisTestTrait;

class AnonymousFunctionContextualInferenceTest extends TestCase
{
    use ValidCodeAnalysisTestTrait;

    public function providerValidCodeParse(): iterable
    {
        yield 'staticMethodContextualInference' => [
            'code' => '<?php
                final class Functional
                {
                    /**
                     * @template A
                     * @template B
                     * @param list<A> $list
                     * @param callable(A): B $callback
                     * @return list<B>
                     */
                    public static function map(array $list, callable $callback)
                    {
                        return array_map($callback, $list);
                    }

                    /**
                     * @template A
                     * @param A $value
                     * @return A
                     */
                    public static function identity(mixed $value): mixed
                    {
                        return $value;
                    }
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function noTypehint(array $objects): array
                {
                    return Functional::map($objects, fn ($o) => $o["value"]);
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function noTypehintOnVariable(array $objects): array
                {
                    $F = Functional::class;
                    return $F::map($objects, fn ($o) => $o["value"]);
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withTypehint(array $objects): array
                {
                    return Functional::map($objects, fn (array $o) => $o["value"]);
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withTypehintOnVariable(array $objects): array
                {
                    $F = Functional::class;
                    return $F::map($objects, fn (array $o) => $o["value"]);
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withTernary(array $objects): array
                {
                    return Functional::map($objects, rand(0, 1)
                        ? fn (array $o) => $o["value"] + 1
                        : fn (array $o) => $o["value"]);
                }
                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withTernaryOnVariable(array $objects): array
                {
                    $F = Functional::class;

                    return $F::map($objects, rand(0, 1)
                        ? fn (array $o) => $o["value"] + 1
                        : fn (array $o) => $o["value"]);
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withMatch(array $objects): array
                {
                    return Functional::map($objects, match (rand(0, 2)) {
                        0 => fn ($o) => $o["value"] + 1,
                        1 => fn ($o) => $o["value"] + 1,
                        2 => fn ($o) => $o["value"] + 2,
                    });
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withMatchOnVariable(array $objects): array
                {
                    $F = Functional::class;

                    return $F::map($objects, match (rand(0, 2)) {
                        0 => fn ($o) => $o["value"] + 1,
                        1 => fn ($o) => $o["value"] + 1,
                        2 => fn ($o) => $o["value"] + 2,
                    });
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withIdentity(array $objects): array
                {
                    return Functional::map($objects, Functional::identity(fn (array $o) => $o["value"]));
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withIdentityOnVariable(array $objects): array
                {
                    $F = Functional::class;

                    return $F::map($objects, $F::identity(fn (array $o) => $o["value"]));
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withMatchIdentity(array $objects): array
                {
                    return Functional::map($objects, match (rand(0, 2)) {
                        0 => Functional::identity(fn ($o) => $o["value"] + 1),
                        1 => Functional::identity(fn ($o) => $o["value"] + 1),
                        2 => Functional::identity(fn ($o) => $o["value"] + 2),
                    });
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withMatchIdentityOnVariable(array $objects): array
                {
                    $F = Functional::class;

                    return $F::map($objects, match (rand(0, 2)) {
                        0 => $F::identity(fn ($o) => $o["value"] + 1),
                        1 => $F::identity(fn ($o) => $o["value"] + 1),
                        2 => $F::identity(fn ($o) => $o["value"] + 2),
                    });
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withIdentityMatch(array $objects): array
                {
                    return Functional::map($objects, Functional::identity(match (rand(0, 2)) {
                        0 => fn ($o) => $o["value"] + 1,
                        1 => fn ($o) => $o["value"] + 1,
                        2 => fn ($o) => $o["value"] + 2,
                    }));
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withIdentityMatchOnVariable(array $objects): array
                {
                    $F = Functional::class;

                    return $F::map($objects, $F::identity(match (rand(0, 2)) {
                        0 => fn ($o) => $o["value"] + 1,
                        1 => fn ($o) => $o["value"] + 1,
                        2 => fn ($o) => $o["value"] + 2,
                    }));
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withIdentityAnywhere(array $objects): array
                {
                    return Functional::map($objects, Functional::identity(match (rand(0, 2)) {
                        0 => rand(0, 1)
                            ? Functional::identity(fn ($o) => $o["value"] + 1)
                            : fn ($o) => $o["value"],
                        1 => Functional::identity(
                            rand(0, 1)
                                ? fn ($o) => $o["value"] + 1
                                : fn ($o) => $o["value"],
                        ),
                        2 => Functional::identity(
                            rand(0, 1)
                                ? Functional::identity(fn ($o) => $o["value"] + 2)
                                : Functional::identity(fn ($o) => $o["value"]),
                        ),
                    }));
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withIdentityAnywhereOnVariable(array $objects): array
                {
                    $F = Functional::class;

                    return $F::map($objects, $F::identity(match (rand(0, 2)) {
                        0 => rand(0, 1)
                            ? $F::identity(fn ($o) => $o["value"] + 1)
                            : fn ($o) => $o["value"],
                        1 => $F::identity(
                            rand(0, 1)
                                ? fn ($o) => $o["value"] + 1
                                : fn ($o) => $o["value"],
                        ),
                        2 => $F::identity(
                            rand(0, 1)
                                ? $F::identity(fn ($o) => $o["value"] + 2)
                                : $F::identity(fn ($o) => $o["value"]),
                        ),
                    }));
                }',
            'assertions' => [],
            'ignored_issues' => [],
            'php_version' => '8.0',
        ];

        yield 'methodContextualInference' => [
            'code' => '<?php
                final class Functional
                {
                    /**
                     * @template A
                     * @template B
                     * @param list<A> $list
                     * @param callable(A): B $callback
                     * @return list<B>
                     */
                    public function map(array $list, callable $callback)
                    {
                        return array_map($callback, $list);
                    }

                    /**
                     * @template A
                     * @param A $value
                     * @return A
                     */
                    public function identity(mixed $value): mixed
                    {
                        return $value;
                    }
                }
                const F = new Functional();
                
                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function noTypehint(array $objects): array
                {
                    return F->map($objects, fn ($o) => $o["value"]);
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withTypehint(array $objects): array
                {
                    return F->map($objects, fn (array $o) => $o["value"]);
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withTernary(array $objects): array
                {
                    return F->map($objects, rand(0, 1)
                        ? fn (array $o) => $o["value"] + 1
                        : fn (array $o) => $o["value"]);
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withMatch(array $objects): array
                {
                    return F->map($objects, match (rand(0, 2)) {
                        0 => fn ($o) => $o["value"] + 1,
                        1 => fn ($o) => $o["value"] + 1,
                        2 => fn ($o) => $o["value"] + 2,
                    });
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withIdentity(array $objects): array
                {
                    return F->map($objects, F->identity(fn (array $o) => $o["value"]));
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withMatchIdentity(array $objects): array
                {
                    return F->map($objects, match (rand(0, 2)) {
                        0 => F->identity(fn ($o) => $o["value"] + 1),
                        1 => F->identity(fn ($o) => $o["value"] + 1),
                        2 => F->identity(fn ($o) => $o["value"] + 2),
                    });
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withIdentityMatch(array $objects): array
                {
                    return F->map($objects, F->identity(match (rand(0, 2)) {
                        0 => fn ($o) => $o["value"] + 1,
                        1 => fn ($o) => $o["value"] + 1,
                        2 => fn ($o) => $o["value"] + 2,
                    }));
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withIdentityAnywhere(array $objects): array
                {
                    return F->map($objects, F->identity(match (rand(0, 2)) {
                        0 => rand(0, 1)
                            ? F->identity(fn ($o) => $o["value"] + 1)
                            : fn ($o) => $o["value"],
                        1 => F->identity(
                            rand(0, 1)
                                ? fn ($o) => $o["value"] + 1
                                : fn ($o) => $o["value"],
                        ),
                        2 => F->identity(
                            rand(0, 1)
                                ? F->identity(fn ($o) => $o["value"] + 2)
                                : F->identity(fn ($o) => $o["value"]),
                        ),
                    }));
                }',
            'assertions' => [],
            'ignored_issues' => [],
            'php_version' => '8.0',
        ];

        yield 'functionContextualInference' => [
            'code' => '<?php
                /**
                 * @template A
                 * @template B
                 * @param list<A> $list
                 * @param callable(A): B $callback
                 * @return list<B>
                 */
                function map(array $list, callable $callback): array
                {
                    return array_map($callback, $list);
                }

                /**
                 * @template A
                 * @param A $value
                 * @return A
                 */
                function identity(mixed $value): mixed
                {
                    return $value;
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function noTypehint(array $objects): array
                {
                    return map($objects, fn ($o) => $o["value"]);
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withTypehint(array $objects): array
                {
                    return map($objects, fn (array $o) => $o["value"]);
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withTernary(array $objects): array
                {
                    return map($objects, rand(0, 1)
                        ? fn (array $o) => $o["value"] + 1
                        : fn (array $o) => $o["value"]);
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withMatch(array $objects): array
                {
                    return map($objects, match (rand(0, 2)) {
                        0 => fn ($o) => $o["value"] + 1,
                        1 => fn ($o) => $o["value"] + 1,
                        2 => fn ($o) => $o["value"] + 2,
                    });
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withIdentity(array $objects): array
                {
                    return map($objects, identity(fn (array $o) => $o["value"]));
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withMatchIdentity(array $objects): array
                {
                    return map($objects, match (rand(0, 2)) {
                        0 => identity(fn ($o) => $o["value"] + 1),
                        1 => identity(fn ($o) => $o["value"] + 1),
                        2 => identity(fn ($o) => $o["value"] + 2),
                    });
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withIdentityMatch(array $objects): array
                {
                    return map($objects, identity(match (rand(0, 2)) {
                        0 => fn ($o) => $o["value"] + 1,
                        1 => fn ($o) => $o["value"] + 1,
                        2 => fn ($o) => $o["value"] + 2,
                    }));
                }

                /**
                 * @param list<array{value: int}> $objects
                 * @return list<int>
                 */
                function withIdentityAnywhere(array $objects): array
                {
                    return map($objects, identity(match (rand(0, 2)) {
                        0 => rand(0, 1)
                            ? identity(fn ($o) => $o["value"] + 1)
                            : fn ($o) => $o["value"],
                        1 => identity(
                            rand(0, 1)
                                ? fn ($o) => $o["value"] + 1
                                : fn ($o) => $o["value"],
                        ),
                        2 => identity(
                            rand(0, 1)
                                ? identity(fn ($o) => $o["value"] + 2)
                                : identity(fn ($o) => $o["value"]),
                        ),
                    }));
                }',
            'assertions' => [],
            'ignored_issues' => [],
            'php_version' => '8.0',
        ];
    }
}
