<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition\Gate;

use Basilicom\DataQualityBundle\Definition\Gate\AlwaysApplyGate;
use Basilicom\DataQualityBundle\Tests\Helper\RuleContextBuilder;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;

final class AlwaysApplyGateTest extends TestCase
{
    public function test_always_returns_true_regardless_of_context(): void
    {
        $gate    = new AlwaysApplyGate();
        $context = RuleContextBuilder::empty()->build();
        $fieldDef = $this->createStub(Data::class);

        self::assertTrue($gate->evaluate($context, $fieldDef));
    }
}
