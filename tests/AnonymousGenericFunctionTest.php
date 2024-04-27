<?php

namespace Psalm\Tests;

use Psalm\Tests\Traits\InvalidCodeAnalysisTestTrait;
use Psalm\Tests\Traits\ValidCodeAnalysisTestTrait;

class AnonymousGenericFunctionTest extends TestCase
{
    use InvalidCodeAnalysisTestTrait;
    use ValidCodeAnalysisTestTrait;

    public function providerValidCodeParse(): iterable
    {
        yield 'identity' => [
            'code' => '<?php
                /**
                 * @param callable<A>(A): A $identity
                 */
                function testIdentity($identity): int
                {
                    return $identity(42);
                }',
        ];

        yield 'identityPipe' => [
            'code' => '<?php
                /**
                 * @template A
                 * @template B
                 * @param A $a
                 * @param callable(A): B $ab
                 * @return B
                 */
                function pipe($a, $ab)
                {
                    return $ab($a);
                }

                /**
                 * @param callable<A>(A): A $identity
                 */
                function testIdentity($identity): int
                {
                    return pipe(42, $identity);
                }',
        ];

        yield 'identityAnonymousPipe' => [
            'code' => '<?php
                /**
                 * @param callable<A, B>(A, callable(A): B): B $pipe
                 * @param callable<A>(A): A $identity
                 */
                function testIdentity($pipe, $identity): int
                {
                    return $pipe(42, $identity);
                }',
        ];

        yield 'flipZip' => [
            'code' => '<?php
                /**
                 * @param Closure<X, Y, Z>(callable(X, Y): Z): Closure(Y, X): Z $flip
                 * @param Closure<A, B>(list<A>, list<B>): list<array{A, B}> $zip
                 * @return Closure<X, Y>(list<Y>, list<X>): list<array{X, Y}>
                 */
                function testFlipZip($flip, $zip): Closure
                {
                    return $flip($zip);
                }',
        ];

        yield 'conditionalReturn' => [
            'code' => '<?php
                /**
                 * @param Closure<A>(A): (A is int ? "yes" : "no") $isInt
                 * @return array{a: "yes", b: "no"}
                 */
                function testAnonymousMap($isInt): array
                {
                    return [
                        "a" => $isInt(1),
                        "b" => $isInt("str"),
                    ];
                }',
        ];

        yield 'conditionalReturn (map with monomorphic function)' => [
            'code' => '<?php
                /**
                 * @param Closure<A, B, TList of list<A>>(TList, callable(A): B): (
                 *     TList is non-empty-list<A>
                 *         ? non-empty-list<B>
                 *         : list<B>
                 * ) $map
                 * @param list<int> $list
                 * @param non-empty-list<int> $nonEmptyList
                 * @param callable(int): array{value: int} $wrap
                 * @return array{
                 *     a: non-empty-list<array{value: int}>,
                 *     b: list<array{value: int}>
                 * }
                 */
                function testAnonymousMap($list, $nonEmptyList, $wrap, $map): array
                {
                    return [
                        "a" => $map($nonEmptyList, $wrap),
                        "b" => $map($list, $wrap),
                    ];
                }',
        ];

        yield 'conditionalReturn (map with polymorphic function)' => [
            'code' => '<?php
                /**
                 * @param Closure<A, B, TList of list<A>>(TList, callable(A): B): (
                 *     TList is non-empty-list<A>
                 *         ? non-empty-list<B>
                 *         : list<B>
                 * ) $map
                 * @param list<int> $list
                 * @param non-empty-list<int> $nonEmptyList
                 * @param callable<T>(T): array{value: T} $wrap
                 * @return array{
                 *     a: non-empty-list<array{value: int}>,
                 *     b: list<array{value: int}>
                 * }
                 */
                function testAnonymousMap($list, $nonEmptyList, $wrap, $map): array
                {
                    return [
                        "a" => $map($nonEmptyList, $wrap),
                        "b" => $map($list, $wrap),
                    ];
                }',
        ];

        yield 'conditionalReturn (pipe with map and monomorphic/polymorphic anonymous function)' => [
            'code' => '<?php
                /**
                 * @template A
                 * @template B
                 * @param callable(A): B $ab
                 * @return Closure<TList of list<A>>(TList): (TList is non-empty-list<A> ? non-empty-list<B> : list<B>)
                 */
                function map($ab): Closure
                {
                    throw new \RuntimeException("not implemented");
                }

                /**
                 * @template A
                 * @template B
                 * @param A $a
                 * @param callable(A): B $ab
                 * @return B
                 */
                function pipe($a, $ab)
                {
                    throw new \RuntimeException("not implemented");
                }

                /**
                 * @param list<int> $list
                 * @param non-empty-list<int> $nonEmptyList
                 * @param callable<T>(T): array{value: T} $wrap
                 * @return array{
                 *     a: non-empty-list<array{value: int}>,
                 *     b: list<array{value: int}>
                 * }
                 */
                function testAnonymousMapPoly($list, $nonEmptyList, $wrap): array
                {
                    return [
                        "a" => pipe($nonEmptyList, map($wrap)),
                        "b" => pipe($list, map($wrap)),
                    ];
                }

                /**
                 * @param list<int> $list
                 * @param non-empty-list<int> $nonEmptyList
                 * @param callable(int): array{value: int} $wrap
                 * @return array{
                 *     a: non-empty-list<array{value: int}>,
                 *     b: list<array{value: int}>
                 * }
                 */
                function testAnonymousMapMono($list, $nonEmptyList, $wrap): array
                {
                    return [
                        "a" => pipe($nonEmptyList, map($wrap)),
                        "b" => pipe($list, map($wrap)),
                    ];
                }',
        ];

        yield 'resolveTemplateNameCollisions' => [
            'code' => '<?php
                /**
                 * @param Closure<A, B, C, D>(
                 *     Closure(A): B,
                 *     Closure(C): D
                 * ): Closure(list{A, C}): list{B, D} $compose2
                 * @param Closure<T>(T): T $identity
                 * @return list{int, string}
                 */
                function testAnonymousMap($compose2, $identity): array
                {
                    $composed = $compose2($identity, $identity);
                    /** @psalm-check-type-exact $composed = Closure<T, T1>(list{T, T1}): list{T, T1} */

                    return $composed([1, "str"]);
                }',
        ];

        yield 'curriedGenericAnonymousFunction' => [
            'code' => '<?php
                /**
                 * @param Closure<A>(A): (Closure<B>(B): (Closure<C>(C): list{A, B, C})) $curried
                 * @return list{1, 2, 3}
                 */
                function testAnonymousMap($curried): array
                {
                    return $curried(1)(2)(3);
                }',
        ];

        yield 'foldMap' => [
            'code' => '<?php
                /**
                 * @template A
                 */
                interface Monoid
                {
                    /**
                     * @param A $lhs
                     * @param A $rhs
                     * @return A
                     */
                    public function concat($lhs, $rhs);

                    /**
                     * @return A
                     */
                    public function zero();
                }

                /**
                 * @template A
                 * @template B
                 *
                 * @param A $a
                 * @param callable(A): B $ab
                 * @return B
                 */
                function pipe($a, $ab)
                {
                    return $ab($a);
                }

                /**
                 * @template M
                 *
                 * @param Monoid<M> $monoid
                 * @return (Closure<A>(callable(A): M): Closure(list<A>): M)
                 */
                function foldMap(Monoid $monoid): Closure
                {
                    return fn ($toM) => function ($listA) use ($toM, $monoid) {
                        $acc = $monoid->zero();

                        foreach ($listA as $a) {
                            $acc = $monoid->concat($acc, $toM($a));
                        }

                        return $acc;
                    };
                }

                /**
                 * @param Monoid<int> $M
                 * @param list<array{value: int}> $objects
                 */
                function testFoldMap($M, $objects): int
                {
                    return pipe(
                        $objects,
                        foldMap($M)(fn ($o) => $o["value"]),
                    );
                }',
        ];
    }

    public function providerInvalidCodeParse(): iterable
    {
        yield 'identity' => [
            'code' => '<?php
                /**
                 * @param callable<T>(T): T $identity
                 */
                function identityInvalidReturnStatement($identity): int
                {
                    return $identity(true);
                }',
            'error_message' => 'InvalidReturnStatement',
        ];

        yield 'intIdentity' => [
            'code' => '<?php
                /**
                 * @param callable<T of int>(T): T $identity
                 */
                function identityInvalidReturnStatement($identity): int
                {
                    return $identity(true);
                }',
            'error_message' => 'InvalidArgument',
        ];

        yield 'identityPipe' => [
            'code' => '<?php
                /**
                 * @template A
                 * @template B
                 * @param A $a
                 * @param callable(A): B $ab
                 * @return B
                 */
                function pipe($a, $ab)
                {
                    return $ab($a);
                }

                /**
                 * @param callable<A>(A): A $identity
                 */
                function testIdentity($identity): string
                {
                    return pipe(42, $identity);
                }',
            'error_message' => 'InvalidReturnStatement',
        ];

        yield 'identityAnonymousPipe' => [
            'code' => '<?php
                /**
                 * @param callable<A, B>(A, callable(A): B): B $pipe
                 * @param callable<A>(A): A $identity
                 */
                function testIdentity($pipe, $identity): string
                {
                    return $pipe(42, $identity);
                }',
            'error_message' => 'InvalidReturnStatement',
        ];

        yield 'flipZip' => [
            'code' => '<?php
                /**
                 * @param Closure<X, Y, Z>(callable(X, Y): Z): callable(Y, X): Z $flip
                 * @param Closure<A, B>(list<A>, list<B>): list<array{A, B}> $zip
                 * @return Closure<A, B>(list<A>, list<B>): list<array{A, B}>
                 */
                function flipZip($flip, $zip): Closure
                {
                    return $flip($zip);
                }',
            'error_message' => 'InvalidReturnStatement',
        ];

        yield 'conditionalReturn' => [
            'code' => '<?php
                /**
                 * @param Closure<A>(A): (A is int ? "yes" : "no") $isInt
                 * @return array{a: "no", b: "yes"}
                 */
                function testAnonymousMap($isInt): array
                {
                    return [
                        "a" => $isInt(1),
                        "b" => $isInt("str"),
                    ];
                }',
            'error_message' => 'InvalidReturnStatement',
        ];

        yield 'curriedGenericAnonymousFunction' => [
            'code' => '<?php
                /**
                 * @param Closure<A>(A): (Closure<B>(B): (Closure<C>(C): list{A, B, C})) $curried
                 * @return list{int, int, int}
                 */
                function testAnonymousMap($curried): array
                {
                    return $curried("fst")(2)(3);
                }',
            'error_message' => 'InvalidReturnStatement',
        ];
    }
}
