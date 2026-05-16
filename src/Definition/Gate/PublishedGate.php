<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition\Gate;

use Basilicom\DataQualityBundle\Definition\Gate;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Pimcore\Model\DataObject\ClassDefinition\Data;

/** Gate that applies only when the object is published. */
final class PublishedGate implements Gate
{
    public function evaluate(RuleContext $ctx, Data $fieldDef): bool
    {
        return (bool) $ctx->getObject()->isPublished();
    }
}
