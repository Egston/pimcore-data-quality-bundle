<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition;

use Basilicom\DataQualityBundle\Definition\NotEmptyDefinition;
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

        self::assertTrue($definition->validate('x', $input, []));
        self::assertFalse($definition->validate('', $input, []));
        self::assertFalse($definition->validate('   ', $input, []));
        self::assertTrue($definition->validate(0, $numeric, []));
        self::assertFalse($definition->validate('', $numeric, []));
        self::assertFalse($definition->validate(null, $numeric, []));
        self::assertTrue($definition->validate(5, $numeric, []));
    }
}
