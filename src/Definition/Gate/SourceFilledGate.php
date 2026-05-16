<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition\Gate;

use Basilicom\DataQualityBundle\Definition\Gate;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Pimcore\Model\DataObject\ClassDefinition\Data;

/**
 * Gate that applies when the source-language value of the rule's
 * field is non-empty. Reads through `getSourceLanguage()` which is in
 * `getAllLanguages()` regardless of the scoring allow-list, so the
 * source read is safe even when the source language is excluded from
 * the scored set.
 */
final class SourceFilledGate implements Gate
{
    public function evaluate(RuleContext $ctx, Data $fieldDef): bool
    {
        $value = $ctx->getValue($fieldDef->getName(), $ctx->getSourceLanguage());

        return $value !== null && $value !== '' && $value !== [];
    }
}
