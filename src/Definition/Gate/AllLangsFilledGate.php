<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition\Gate;

use Basilicom\DataQualityBundle\Definition\DefinitionAbstract;
use Basilicom\DataQualityBundle\Definition\Gate;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Pimcore\Model\DataObject\ClassDefinition\Data;

/**
 * Gate that applies when every scored language has a non-empty value
 * for the rule's field. Iterates `getScoredLanguages()` — a locale
 * excluded from the config's `dataQualityLanguages` allow-list must not
 * be counted against the gate. An empty scored set degenerates to
 * "apply": there is no language that fails the predicate.
 */
final class AllLangsFilledGate implements Gate
{
    public function evaluate(RuleContext $ctx, Data $fieldDef): bool
    {
        $fieldName = $fieldDef->getName();
        foreach ($ctx->getScoredLanguages() as $language) {
            if (!DefinitionAbstract::isFilled($ctx->getValue($fieldName, $language))) {
                return false;
            }
        }

        return true;
    }
}
