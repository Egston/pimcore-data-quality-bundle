<?php

namespace Basilicom\DataQualityBundle\DefinitionsCollection\Factory;

use Basilicom\DataQualityBundle\Definition\DefinitionInterface;
use Basilicom\DataQualityBundle\DefinitionsCollection\FieldDefinition;
use Basilicom\DataQualityBundle\Registry\RuleRegistry;
use Pimcore\Model\DataObject\Fieldcollection\Data\AbstractData;

class FieldDefinitionFactory
{
    const DEFAULT_GROUP = '__default__';

    public function __construct(private readonly RuleRegistry $ruleRegistry)
    {
    }

    public function get(AbstractData $definition): FieldDefinition
    {
        $definitionField = $definition->getField();

        $fieldName = null;
        $title = '';
        $language = null;

        if (preg_match('/^(.*)@@@(.*)###(.*)$/', $definitionField, $matches)) {
            $fieldName = $matches[1];
            $title = $matches[2];
            $language = $matches[3];
        } elseif (preg_match('/^(.*)###(.*)$/', $definitionField, $matches)) {
            $fieldName = $matches[1];
            $language = $matches[2];
        } elseif (preg_match('/^(.*)@@@(.*)$/', $definitionField, $matches)) {
            $fieldName = $matches[1];
            $title= $matches[2];
        } else {
            $fieldName = $definitionField;
        }

        return new FieldDefinition(
            $this->getClass($definition->getCondition(), $definition),
            $fieldName,
            $title,
            empty($definition->getWeight()) ? 0 : (int) $definition->getWeight(),
            $this->parameterStringToArray((string) $definition->getParameters()),
            $language ?? null
        );
    }

    private function parameterStringToArray(string $parameterString): array
    {
        $parameters      = [];
        $parameterString = trim($parameterString);
        if (empty($parameterString)) {
            return $parameters;
        }

        foreach (str_getcsv($parameterString, ';') as $parameterItem) {
            $parameters[] = trim($parameterItem);
        }

        return $parameters;
    }

    private function getClass(?string $conditionKeyOrClass, AbstractData $definition): DefinitionInterface
    {
        $fieldName = $definition->getField() ?? '(unknown)';

        if ($conditionKeyOrClass === null || $conditionKeyOrClass === '') {
            throw new \RuntimeException(sprintf(
                'Data-quality field "%s" has no condition configured. '
                . 'Register a rule as a service tagged "data_quality.rule" with a "key" attribute.',
                $fieldName,
            ));
        }

        $rule = $this->ruleRegistry->get($conditionKeyOrClass);
        if ($rule !== null) {
            return $rule;
        }

        if (class_exists($conditionKeyOrClass)) {
            $instance = new $conditionKeyOrClass();

            if (!$instance instanceof DefinitionInterface) {
                throw new \RuntimeException(sprintf(
                    'Data-quality field "%s": condition "%s" resolves to a class that does not implement %s. '
                    . 'Register it as a service tagged "data_quality.rule" with a "key" attribute.',
                    $fieldName,
                    $conditionKeyOrClass,
                    DefinitionInterface::class,
                ));
            }

            // Production environments must have E_USER_DEPRECATED reporting enabled to see this.
            @trigger_error(sprintf(
                'Resolving data-quality rule "%s" (field "%s") via class_exists() fallback is deprecated; '
                . 'register it as a service tagged "data_quality.rule" with a "key" attribute.',
                $conditionKeyOrClass,
                $fieldName,
            ), E_USER_DEPRECATED);

            return $instance;
        }

        throw new \RuntimeException(sprintf(
            'Data-quality field "%s": condition "%s" is not a registered rule key and the class does not exist. '
            . 'Register it as a service tagged "data_quality.rule" with a "key" attribute.',
            $fieldName,
            $conditionKeyOrClass,
        ));
    }
}
