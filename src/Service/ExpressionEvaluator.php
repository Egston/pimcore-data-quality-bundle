<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Service;

use Basilicom\DataQualityBundle\Definition\DefinitionException;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\ParsedExpression;
use Symfony\Component\ExpressionLanguage\SyntaxError;

/**
 * Per-process `ParsedExpression` cache layered over the shared
 * Symfony `ExpressionLanguage`. Each unique source string is parsed
 * once and the resulting `ParsedExpression` is reused on the
 * recompute hot path across every scored language for every row.
 *
 * Variable-name binding is split into two const sets: non-container
 * mode binds `lang` + `value` per iteration; container mode binds
 * `containerLeaves` instead — a `lang` reference in a container-path
 * expression is rejected at parse time.
 */
final class ExpressionEvaluator
{
    public const VARIABLE_NAMES_NON_CONTAINER = [
        'object',
        'valuesByLang',
        'flagsByLang',
        'lang',
        'value',
        'sourceLanguage',
    ];

    public const VARIABLE_NAMES_CONTAINER = [
        'object',
        'containerLeaves',
        'flagsByLang',
        'sourceLanguage',
    ];

    /** @var array<string, ParsedExpression> */
    private array $parsedCache = [];

    public function __construct(
        private readonly ExpressionLanguage $expressionLanguage,
    ) {}

    /**
     * @param array<string, mixed> $variables
     * @param string[]             $allowedNames
     *
     * @throws DefinitionException on parse failure
     */
    public function evaluate(string $expression, array $variables, array $allowedNames): mixed
    {
        $parsed = $this->parse($expression, $allowedNames);

        return $this->expressionLanguage->evaluate($parsed, $variables);
    }

    /**
     * @param string[] $allowedNames
     *
     * @throws DefinitionException
     */
    private function parse(string $expression, array $allowedNames): ParsedExpression
    {
        $cacheKey = $expression . "\0" . implode("\0", $allowedNames);
        if (isset($this->parsedCache[$cacheKey])) {
            return $this->parsedCache[$cacheKey];
        }

        try {
            $parsed = $this->expressionLanguage->parse($expression, $allowedNames);
        } catch (SyntaxError $e) {
            throw new DefinitionException(
                sprintf('Expression "%s" failed to parse: %s', $expression, $e->getMessage()),
                0,
                $e,
            );
        }

        $this->parsedCache[$cacheKey] = $parsed;

        return $parsed;
    }
}
