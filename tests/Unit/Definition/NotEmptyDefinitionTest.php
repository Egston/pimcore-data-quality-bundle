<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition;

use Basilicom\DataQualityBundle\Definition\NotEmptyDefinition;
use Basilicom\DataQualityBundle\Tests\Helper\RuleContextFactory;
use Pimcore\Model\DataObject\ClassDefinition\Data\Input;
use Pimcore\Model\DataObject\ClassDefinition\Data\Numeric;
use PHPUnit\Framework\TestCase;

final class NotEmptyDefinitionTest extends TestCase
{
    public function testValidatesInputAndNumericFieldtypes(): void
    {
        $definition = new NotEmptyDefinition();
        $input = new Input();
        $numeric = new Numeric();
        $context = RuleContextFactory::stub();

        self::assertTrue($definition->validate('x', $input, [], $context));
        self::assertFalse($definition->validate('', $input, [], $context));
        self::assertFalse($definition->validate('   ', $input, [], $context));
        self::assertTrue($definition->validate(0, $numeric, [], $context));
        self::assertFalse($definition->validate('', $numeric, [], $context));
        self::assertFalse($definition->validate(null, $numeric, [], $context));
        self::assertTrue($definition->validate(5, $numeric, [], $context));
    }
}
