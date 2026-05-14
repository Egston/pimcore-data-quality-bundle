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
}
