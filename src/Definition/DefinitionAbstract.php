<?php

namespace Basilicom\DataQualityBundle\Definition;

use Pimcore\Model\DataObject\ClassDefinition\Data;

abstract class DefinitionAbstract implements DefinitionInterface
{
    public const NECESSARY_PARAMETER_COUNT = 0;

    public function getNecessaryParameterCount(): int
    {
        return static::NECESSARY_PARAMETER_COUNT;
    }

    /**
     * @throws DefinitionException
     */
    public function validate($content, Data $fieldDefinition, array $parameters, RuleContext $context): bool
    {
        return false;
    }

    /**
     * `null` / empty string / empty array → false (empty). All other
     * values → true (filled). Whitespace-only strings are NOT trimmed —
     * fieldtype-aware trimming is a caller concern; this helper stays
     * fieldtype-blind so it can be applied to arbitrary resolved leaves.
     */
    public static function isFilled(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }
}
