<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition;

use Pimcore\Model\DataObject\ClassDefinition\Data;

/**
 * Rule-scope predicate: returns true if the rule applies to this object,
 * false if it is N/A (contributes zero to both numerator and denominator).
 */
interface Gate
{
    public function evaluate(RuleContext $ctx, Data $fieldDef): bool;
}
