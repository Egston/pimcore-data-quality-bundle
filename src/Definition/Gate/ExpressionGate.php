<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition\Gate;

use Basilicom\DataQualityBundle\Definition\Gate;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\ParsedExpression;

/**
 * Symfony-ExpressionLanguage-backed gate. The expression is parsed
 * eagerly at construction time (by `GateFactory::fromString()` — a
 * `SyntaxError` there surfaces immediately as `InvalidGateException`,
 * keeping the recompute-time path predicate-pure).
 *
 * Variable binding is intentionally narrow: `{object, valuesByLang,
 * sourceLanguage}`. `lang` is NOT bound — a gate is evaluated once per
 * rule, not per language; a `lang` reference is undefined at gate time
 * and the parser rejects it at construction. Per-language semantics
 * belong to the per-language rule sweep, not the gate.
 */
final class ExpressionGate implements Gate
{
    public const VARIABLE_NAMES = ['object', 'valuesByLang', 'sourceLanguage'];

    public function __construct(
        private readonly ParsedExpression $expression,
        private readonly ExpressionLanguage $expressionLanguage,
        private readonly string $source,
    ) {
    }

    public function evaluate(RuleContext $ctx, Data $fieldDef): bool
    {
        $values = [
            'object' => $ctx->getObject(),
            'valuesByLang' => $ctx->getValuesByLang($fieldDef->getName()),
            'sourceLanguage' => $ctx->getSourceLanguage(),
        ];

        return (bool) $this->expressionLanguage->evaluate($this->expression, $values);
    }

    public function getSource(): string
    {
        return $this->source;
    }
}
