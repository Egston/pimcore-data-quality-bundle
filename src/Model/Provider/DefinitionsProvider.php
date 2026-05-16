<?php

namespace Basilicom\DataQualityBundle\Model\Provider;

use Basilicom\DataQualityBundle\Registry\RuleRegistry;
use Pimcore\Model\DataObject\ClassDefinition\DynamicOptionsProvider\SelectOptionsProviderInterface;

class DefinitionsProvider implements SelectOptionsProviderInterface
{
    public function __construct(private readonly RuleRegistry $ruleRegistry) {}

    public function getOptions($context, $fieldDefinition): array
    {
        $options = [];
        foreach ($this->ruleRegistry->all() as $definitionKey => $rule) {
            $options[] = [
                'value' => $rule::class,
                'key'   => $definitionKey,
            ];
        }

        return $options;
    }

    public function hasStaticOptions($context, $fieldDefinition): bool
    {
        return false;
    }

    public function getDefaultValue($context, $fieldDefinition): ?string
    {
        return $fieldDefinition->getDefaultValue();
    }
}
