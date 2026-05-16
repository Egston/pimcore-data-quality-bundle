<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition\Gate;

use Basilicom\DataQualityBundle\Definition\Gate;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Pimcore\Model\DataObject\ClassDefinition\Data;

/** Resolves an empty/null gate; the rule always applies. */
final class AlwaysApplyGate implements Gate
{
    public function evaluate(RuleContext $ctx, Data $fieldDef): bool
    {
        return true;
    }
}
