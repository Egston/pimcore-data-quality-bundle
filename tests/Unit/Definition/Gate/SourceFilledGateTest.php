<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition\Gate;

use Basilicom\DataQualityBundle\Definition\Gate\SourceFilledGate;
use Basilicom\DataQualityBundle\Tests\Helper\RuleContextBuilder;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;

final class SourceFilledGateTest extends TestCase
{
    public function test_applies_when_source_language_value_is_non_empty(): void
    {
        $gate = new SourceFilledGate();
        $context = RuleContextBuilder::withValues(['en' => 'Hello', 'de' => ''])->build();

        self::assertTrue($gate->evaluate($context, $this->makeFieldDef('name')));
    }

    public function test_does_not_apply_when_source_language_value_is_empty(): void
    {
        $gate = new SourceFilledGate();
        $context = RuleContextBuilder::withValues(['en' => '', 'de' => 'Hallo'])->build();

        self::assertFalse($gate->evaluate($context, $this->makeFieldDef('name')));
    }

    public function test_does_not_apply_when_source_language_value_is_null(): void
    {
        $gate = new SourceFilledGate();
        $context = RuleContextBuilder::withValues(['en' => null, 'de' => 'Hallo'])->build();

        self::assertFalse($gate->evaluate($context, $this->makeFieldDef('name')));
    }

    private function makeFieldDef(string $name): Data
    {
        $stub = $this->createStub(Data::class);
        $stub->method('getName')->willReturn($name);

        return $stub;
    }
}
