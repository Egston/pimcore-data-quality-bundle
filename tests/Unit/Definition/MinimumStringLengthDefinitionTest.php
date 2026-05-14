<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition;

use Basilicom\DataQualityBundle\Definition\DefinitionException;
use Basilicom\DataQualityBundle\Definition\MinimumStringLengthDefinition;
use Basilicom\DataQualityBundle\Tests\Helper\RuleContextFactory;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\Data\Input;
use Pimcore\Model\DataObject\ClassDefinition\Data\Textarea;
use PHPUnit\Framework\TestCase;

final class MinimumStringLengthDefinitionTest extends TestCase
{
    public function test_input_field_passes_when_content_meets_min_length(): void
    {
        $definition = new MinimumStringLengthDefinition();
        $input = new Input();
        $context = RuleContextFactory::stub();

        self::assertTrue($definition->validate('hello', $input, [3], $context));
        self::assertTrue($definition->validate('hi', $input, [2], $context));
        self::assertFalse($definition->validate('hi', $input, [3], $context));
        self::assertFalse($definition->validate('', $input, [1], $context));
    }

    public function test_textarea_field_trims_before_measuring(): void
    {
        $definition = new MinimumStringLengthDefinition();
        $textarea = new Textarea();
        $context = RuleContextFactory::stub();

        self::assertFalse($definition->validate('   ', $textarea, [1], $context));
        self::assertTrue($definition->validate('  abc  ', $textarea, [3], $context));
    }

    public function test_number_fieldtype_branch_via_stubbed_fieldtype(): void
    {
        $definition = new MinimumStringLengthDefinition();
        $context = RuleContextFactory::stub();

        $numberField = $this->stubFieldDefinition('number', 'price');

        self::assertTrue($definition->validate('1234', $numberField, [3], $context));
        self::assertFalse($definition->validate('12', $numberField, [3], $context));
    }

    public function test_empty_parameter_list_treats_min_length_as_zero(): void
    {
        $definition = new MinimumStringLengthDefinition();
        $input = new Input();
        $context = RuleContextFactory::stub();

        self::assertTrue($definition->validate('', $input, [], $context));
        self::assertTrue($definition->validate('anything', $input, [], $context));
    }

    public function test_unsupported_fieldtype_throws_definition_exception(): void
    {
        $definition = new MinimumStringLengthDefinition();
        $context = RuleContextFactory::stub();

        $unsupported = $this->stubFieldDefinition('select', 'category');

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('select');
        $this->expectExceptionMessage('category');

        $definition->validate('whatever', $unsupported, [3], $context);
    }

    private function stubFieldDefinition(string $fieldType, string $fieldName): Data
    {
        $stub = $this->createStub(Data::class);
        $stub->method('getFieldtype')->willReturn($fieldType);
        $stub->method('getName')->willReturn($fieldName);

        return $stub;
    }
}
