<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition\Gate;

use Basilicom\DataQualityBundle\Definition\DefinitionAbstract;
use Basilicom\DataQualityBundle\Definition\Gate;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Pimcore\Model\DataObject\ClassDefinition\Data;

/**
 * Gate that applies when at least one scored language has a non-empty
 * value for the rule's field. Iterates the scoring axis (the per-config
 * allow-list), not the full Pimcore language set — a value that exists
 * only in a non-scored locale must not lift the gate.
 */
final class AnyLangFilledGate implements Gate
{
    public function evaluate(RuleContext $ctx, Data $fieldDef): bool
    {
        $fieldName = $fieldDef->getName();
        foreach ($ctx->getScoredLanguages() as $language) {
            if (DefinitionAbstract::isFilled($ctx->getValue($fieldName, $language))) {
                return true;
            }
        }

        return false;
    }
}
