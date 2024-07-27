<?php

declare(strict_types=1);

namespace Psalm\Tests;

use Psalm\Tests\Traits\ValidCodeAnalysisTestTrait;

use function implode;

use const PHP_EOL;

/**
 * @psalm-type TestCase = array{
 *     code: non-empty-string,
 *     assertions: array<string, string>,
 *     ignored_issues: list<string>,
 *     php_version: '8.0',
 * }
 */
class ContextualInferenceTest extends TestCase
{
    use ValidCodeAnalysisTestTrait;

    private const PIPE_FUNCTION = <<<'PHP'
        /**
         * @template A
         * @template B
         * @param A $a
         * @param callable(A): B $ab
         * @return B
         */
        function pipe(mixed $a, callable $ab): mixed
        {
            return $ab($a);
        }
        PHP;

    private const IDENTITY_FUNCTION = <<<'PHP'
        /**
         * @template A 
         * @param A $value 
         * @return A 
         */
        function identity(mixed $value): mixed
        {
            return $value;
        }
        PHP;

    private const MAP_FUNCTION = <<<'PHP'
        /**
         * @template A
         * @template B
         * @param list<A> $fa
         * @param callable(A): B $ab
         * @return list<B>
         */
        function map(array $fa, callable $ab): array
        {
            return array_map($ab, $fa);
        }
        PHP;

    private const COMPOSE_FUNCTION = <<<'PHP'
        /**
         * @template A 
         * @template B 
         * @template C
         * @param callable(A): B $ab 
         * @param callable(B): C $bc
         * @return callable(A): C 
         */
        function compose2(callable $ab, $bc): callable
        {
            return fn ($a) => $bc($ab($a));
        }
        PHP;

    private const FUNCTIONAL_CLASS = <<<'PHP'
        final class Functional
        {
            /**
             * @template A 
             *
             * @param A $value 
             * @return A 
             */
            function identity(mixed $value): mixed
            {
                return $value;
            }

            /**
             * @template A
             * @template B
             *
             * @param list<A> $fa
             * @param callable(A): B $ab
             * @return list<B>
             */
            function map(array $fa, callable $ab): array
            {
                return array_map($ab, $fa);
            }

            /**
             * @template A 
             * @template B 
             * @template C
             *
             * @param callable(A): B $ab 
             * @param callable(B): C $bc
             * @return callable(A): C 
             */
            function compose2(callable $ab, $bc): callable
            {
                return fn ($a) => $bc($ab($a));
            }
        }
        const functional = new Functional();
        PHP;

    private const FUNCTIONAL_STATIC_CLASS = <<<'PHP'
        final class Functional
        {
            /**
             * @template A 
             *
             * @param A $value 
             * @return A 
             */
            public static function identity(mixed $value): mixed
            {
                return $value;
            }

            /**
             * @template A
             * @template B
             *
             * @param list<A> $fa
             * @param callable(A): B $ab
             * @return list<B>
             */
            public static function map(array $fa, callable $ab): array
            {
                return array_map($ab, $fa);
            }

            /**
             * @template A 
             * @template B 
             * @template C
             *
             * @param callable(A): B $ab 
             * @param callable(B): C $bc
             * @return callable(A): C 
             */
            public static function compose2(callable $ab, $bc): callable
            {
                return fn ($a) => $bc($ab($a));
            }
        }
        PHP;

    private const MAP_CLASS = <<<'PHP'
        /**
         * @template A
         * @template B
         */
        final class Map
        {
            /**
             * @param Closure(A): B $ab
             */
            public function __construct(
                private Closure $ab,
            ) {
            }
        
            /**
             * @param list<A> $fa
             * @return list<B>
             */
            public function __invoke(array $fa): mixed
            {
                return array_map($this->ab, $fa);
            }
        }
        PHP;

    private const TAP_FUNCTION = <<<'PHP'
        /**
         * @template K of array-key
         * @template A
         * @template B
         * @param callable(K, A): B $ab
         * @return callable(array<K, A>): array<K, A>
         */
        function tap(callable $ab): callable
        {
            return function (array $fa) use ($ab) {
                foreach ($fa as $key => $a) {
                    $ab($key, $a);
                }
        
                return $fa;
            };
        }
        PHP;

    /**
     * @param non-empty-list<non-empty-string> $prepend
     * @param non-empty-string $code
     * @return TestCase
     */
    private static function test(array $prepend, string $code): array
    {
        return [
            'code' => implode('', ['<?php', PHP_EOL, implode(PHP_EOL, $prepend), PHP_EOL, $code]),
            'assertions' => [],
            'ignored_issues' => [],
            'php_version' => '8.0',
        ];
    }

    /**
     * @return iterable<string, TestCase>
     */
    private static function validFunctionTests(): iterable
    {
        yield 'simple' => self::test([self::MAP_FUNCTION], <<<'PHP'
            $result = map(
                fa: [1, 2, 3],
                ab: fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i],
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3}> */;
            PHP);

        yield 'recursive (function)' => self::test([self::IDENTITY_FUNCTION, self::MAP_FUNCTION], <<<'PHP'
            $result = map(
                fa: [1, 2, 3],
                ab: identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i]),
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3}> */;
            PHP);

        yield 'recursive (ternary)' => self::test([self::MAP_FUNCTION], <<<'PHP'
            $result = map(
                fa: [1, 2, 3],
                ab: rand(0, 1)
                    ? fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i]
                    : fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 1],
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3|4}> */;
            PHP);

        yield 'recursive (match)' => self::test([self::MAP_FUNCTION], <<<'PHP'
            $result = map(
                fa: [1, 2, 3],
                ab: match (rand(0, 1)) {
                    0 => fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i],
                    1 => fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 1],
                },
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3|4}> */;
            PHP);

        yield 'recursive (ternary + function)' => self::test([self::IDENTITY_FUNCTION, self::MAP_FUNCTION], <<<'PHP'
            $result = map(
                fa: [1, 2, 3],
                ab: rand(0, 1)
                    ? identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i])
                    : identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 1]),
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3|4}> */;
            PHP);

        yield 'recursive (ternary + function + match)' => self::test([self::IDENTITY_FUNCTION, self::MAP_FUNCTION], <<<'PHP'
            $result = map(
                fa: [1, 2, 3],
                ab: match (rand(0, 1))  {
                    0 => rand(0, 1)
                        ? identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i])
                        : identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 1]),
                    1 => rand(0, 1)
                        ? identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 2])
                        : identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 3]),
                },
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3|4|5|6}> */;
            PHP);

        yield 'recursive (ternary + function + match + plain)' => self::test([self::IDENTITY_FUNCTION, self::MAP_FUNCTION], <<<'PHP'
            $result = map(
                fa: [1, 2, 3],
                ab: match (rand(0, 1))  {
                    0 => fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 3],
                    1 => rand(0, 1)
                        ? identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i])
                        : fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 1],
                },
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3|4|5|6}> */;
            PHP);

        yield 'complex-recursion' => self::test([self::IDENTITY_FUNCTION, self::MAP_FUNCTION, self::COMPOSE_FUNCTION], <<<'PHP'
            $result1 = map(
                fa: [1, 2, 3],
                ab: compose2(
                    fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 1],
                    fn ($i) => /** @psalm-check-type-exact $i = array{value: 2|3|4} */  ['boxed' => $i],
                ),
            );
            /** @psalm-check-type-exact $result1 = list<array{boxed: array{value: 2|3|4}}> */;

            $result2 = map(
                fa: [1, 2, 3],
                ab: compose2(
                    identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 1]),
                    identity(fn ($i) => /** @psalm-check-type-exact $i = array{value: 2|3|4} */  ['boxed' => $i]),
                ),
            );
            /** @psalm-check-type-exact $result2 = list<array{boxed: array{value: 2|3|4}}> */;

            $result3 = map(
                fa: [1, 2, 3],
                ab: compose2(
                    rand(0, 1)
                        ? fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 1]
                        : fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 2],
                    rand(0, 1)
                        ? fn ($i) => /** @psalm-check-type-exact $i = array{value: 2|3|4|5} */  $i
                        : fn ($i) => /** @psalm-check-type-exact $i = array{value: 2|3|4|5} */  ['value' => rand(0, 1) ? $i['value'] : 6],
                ),
            );
            /** @psalm-check-type-exact $result3 = list<array{value: 2|3|4|5|6}> */;

            $result4 = map(
                fa: [1, 2, 3],
                ab: compose2(
                    rand(0, 1)
                        ? fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 1]
                        : fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 2],
                    match (rand(0, 1)) {
                        0 => fn ($i) => /** @psalm-check-type-exact $i = array{value: 2|3|4|5} */  $i,
                        1 => fn ($i) => /** @psalm-check-type-exact $i = array{value: 2|3|4|5} */  ['value' => rand(0, 1) ? $i['value'] : 6],
                    },
                ),
            );
            /** @psalm-check-type-exact $result4 = list<array{value: 2|3|4|5|6}> */;
            PHP);

        yield 'infer types with omitted closure params' => self::test([self::PIPE_FUNCTION, self::TAP_FUNCTION], <<<'PHP'
            $_result1 = pipe(
                ['fst' => 1, 'snd' => 2, 'thr' => 3],
                tap(fn ($k, $v) => print_r("Key: $k; Value: {$v};")),
            );
            /** @psalm-check-type-exact $_result1 = array<'fst'|'snd'|'thr', 1|2|3> */

            $_result2 = pipe(
                ['fst' => 1, 'snd' => 2, 'thr' => 3],
                tap(fn ($k) => print_r("Key: $k;")),
            );
            /** @psalm-check-type-exact $_result2 = array<'fst'|'snd'|'thr', 1|2|3> */

            $_result3 = pipe(
                ['fst' => 1, 'snd' => 2, 'thr' => 3],
                tap(fn () => print_r('Just log something')),
            );
            /** @psalm-check-type-exact $_result3 = array<'fst'|'snd'|'thr', 1|2|3> */
            PHP);
    }

    /**
     * @return iterable<string, TestCase>
     */
    private static function validInstanceMethodTests(): iterable
    {
        yield 'simple' => self::test([self::FUNCTIONAL_CLASS], <<<'PHP'
            $result = functional->map(
                fa: [1, 2, 3],
                ab: fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i],
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3}> */;
            PHP);

        yield 'recursive (method)' => self::test([self::FUNCTIONAL_CLASS], <<<'PHP'
            $result = functional->map(
                fa: [1, 2, 3],
                ab: functional->identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i]),
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3}> */;
            PHP);

        yield 'recursive (ternary)' => self::test([self::FUNCTIONAL_CLASS], <<<'PHP'
            $result = functional->map(
                fa: [1, 2, 3],
                ab: rand(0, 1)
                    ? fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i]
                    : fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 1],
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3|4}> */;
            PHP);

        yield 'recursive (match)' => self::test([self::FUNCTIONAL_CLASS], <<<'PHP'
            $result = functional->map(
                fa: [1, 2, 3],
                ab: match (rand(0, 1)) {
                    0 => fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i],
                    1 => fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 1],
                },
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3|4}> */;
            PHP);

        yield 'recursive (ternary + method)' => self::test([self::FUNCTIONAL_CLASS], <<<'PHP'
            $result = functional->map(
                fa: [1, 2, 3],
                ab: rand(0, 1)
                    ? functional->identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i])
                    : functional->identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 1]),
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3|4}> */;
            PHP);

        yield 'recursive (ternary + method + match)' => self::test([self::FUNCTIONAL_CLASS], <<<'PHP'
            $result = functional->map(
                fa: [1, 2, 3],
                ab: match (rand(0, 1))  {
                    0 => rand(0, 1)
                        ? functional->identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i])
                        : functional->identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 1]),
                    1 => rand(0, 1)
                        ? functional->identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 2])
                        : functional->identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 3]),
                },
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3|4|5|6}> */;
            PHP);
    }

    /**
     * @return iterable<string, TestCase>
     */
    private static function validStaticMethodTests(): iterable
    {
        yield 'simple' => self::test([self::FUNCTIONAL_STATIC_CLASS], <<<'PHP'
            $result = Functional::map(
                fa: [1, 2, 3],
                ab: fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i],
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3}> */;
            PHP);

        yield 'recursive (method)' => self::test([self::FUNCTIONAL_STATIC_CLASS], <<<'PHP'
            $result = Functional::map(
                fa: [1, 2, 3],
                ab: Functional::identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i]),
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3}> */;
            PHP);

        yield 'recursive (ternary)' => self::test([self::FUNCTIONAL_STATIC_CLASS], <<<'PHP'
            $result = Functional::map(
                fa: [1, 2, 3],
                ab: rand(0, 1)
                    ? fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i]
                    : fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 1],
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3|4}> */;
            PHP);

        yield 'recursive (match)' => self::test([self::FUNCTIONAL_STATIC_CLASS], <<<'PHP'
            $result = Functional::map(
                fa: [1, 2, 3],
                ab: match (rand(0, 1)) {
                    0 => fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i],
                    1 => fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 1],
                },
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3|4}> */;
            PHP);

        yield 'recursive (ternary + method)' => self::test([self::FUNCTIONAL_STATIC_CLASS], <<<'PHP'
            $result = Functional::map(
                fa: [1, 2, 3],
                ab: rand(0, 1)
                    ? Functional::identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i])
                    : Functional::identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 1]),
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3|4}> */;
            PHP);

        yield 'recursive (ternary + method + match)' => self::test([self::FUNCTIONAL_STATIC_CLASS], <<<'PHP'
            $result = Functional::map(
                fa: [1, 2, 3],
                ab: match (rand(0, 1))  {
                    0 => rand(0, 1)
                        ? Functional::identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i])
                        : Functional::identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 1]),
                    1 => rand(0, 1)
                        ? Functional::identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 2])
                        : Functional::identity(fn ($i) => /** @psalm-check-type-exact $i = 1|2|3 */  ['value' => $i + 3]),
                },
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3|4|5|6}> */;
            PHP);
    }

    /**
     * @return iterable<string, TestCase>
     */
    private static function validConstructorTests(): iterable
    {
        yield 'recursive' => self::test([self::PIPE_FUNCTION, self::MAP_CLASS], <<<'PHP'
            $result = pipe(
                [1, 2, 3],
                new Map(fn ($a) => ['value' => $a]),
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3}> */
            PHP);

        yield 'recursive (function)' => self::test([self::PIPE_FUNCTION, self::IDENTITY_FUNCTION, self::MAP_CLASS], <<<'PHP'
            $result = pipe(
                [1, 2, 3],
                identity(new Map(fn ($a) => ['value' => $a])),
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3}> */
            PHP);

        yield 'recursive (ternary)' => self::test([self::PIPE_FUNCTION, self::MAP_CLASS], <<<'PHP'
            $result = pipe(
                [1, 2, 3],
                rand(0, 1)
                    ? new Map(fn ($a) => ['value' => $a])
                    : new Map(fn ($a) => ['value' => $a + 1]),
            );
            /** @psalm-check-type-exact $result = list<array{value: 1|2|3|4}> */
            PHP);
    }

    public function providerValidCodeParse(): iterable
    {
        foreach (self::validFunctionTests() as $name => $test) {
            yield "function: {$name}" => $test;
        }

        foreach (self::validInstanceMethodTests() as $name => $test) {
            yield "method: {$name}" => $test;
        }

        foreach (self::validStaticMethodTests() as $name => $test) {
            yield "static method: {$name}" => $test;
        }

        foreach (self::validConstructorTests() as $name => $test) {
            yield "constructor: {$name}" => $test;
        }
    }
}
