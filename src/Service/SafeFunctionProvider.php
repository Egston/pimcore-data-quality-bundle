<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Service;

use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;

/**
 * Intentionally small function set for admin-authored data-quality rules.
 * Wired at construction time to avoid the `register()`-after-`parse()`
 * LogicException. `filter`'s closure receives `(value, key)`.
 */
final class SafeFunctionProvider implements ExpressionFunctionProviderInterface
{
    /**
     * @return ExpressionFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new ExpressionFunction(
                'count',
                static fn($arr): string => sprintf('\count(%s)', $arr),
                static fn($values, $arr): int => \count($arr),
            ),
            new ExpressionFunction(
                'filter',
                static fn($map, $predicate): string => sprintf(
                    '\array_filter(%s, %s, \ARRAY_FILTER_USE_BOTH)',
                    $map,
                    $predicate,
                ),
                static fn($values, $map, $predicate): array => \array_filter(
                    $map,
                    $predicate,
                    \ARRAY_FILTER_USE_BOTH,
                ),
            ),
            new ExpressionFunction(
                'same',
                static fn($a, $b): string => sprintf(
                    '(\is_string(%1$s) && \is_string(%2$s) ? \trim(%1$s) === \trim(%2$s) : %1$s === %2$s)',
                    $a,
                    $b,
                ),
                static function ($values, $a, $b): bool {
                    if (\is_string($a) && \is_string($b)) {
                        return trim($a) === trim($b);
                    }

                    return $a === $b;
                },
            ),
            new ExpressionFunction(
                'different',
                static fn($a, $b): string => sprintf(
                    '!(\is_string(%1$s) && \is_string(%2$s) ? \trim(%1$s) === \trim(%2$s) : %1$s === %2$s)',
                    $a,
                    $b,
                ),
                static function ($values, $a, $b): bool {
                    if (\is_string($a) && \is_string($b)) {
                        return trim($a) !== trim($b);
                    }

                    return $a !== $b;
                },
            ),
        ];
    }
}
