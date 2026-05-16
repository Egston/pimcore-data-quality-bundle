<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition\Gate;

use Basilicom\DataQualityBundle\Definition\Gate\AnyLangFilledGate;
use Basilicom\DataQualityBundle\Tests\Helper\RuleContextBuilder;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;

final class AnyLangFilledGateTest extends TestCase
{
    public function test_applies_when_one_scored_language_is_filled(): void
    {
        $gate    = new AnyLangFilledGate();
        $context = RuleContextBuilder::withValues(['en' => '', 'de' => 'Hallo'])
            ->scoredLanguages(['en', 'de'])
            ->build();

        self::assertTrue($gate->evaluate($context, $this->makeFieldDef('name')));
    }

    public function test_does_not_apply_when_all_scored_languages_are_empty(): void
    {
        $gate    = new AnyLangFilledGate();
        $context = RuleContextBuilder::withValues(['en' => '', 'de' => ''])
            ->scoredLanguages(['en', 'de'])
            ->build();

        self::assertFalse($gate->evaluate($context, $this->makeFieldDef('name')));
    }

    public function test_array_value_treated_as_empty(): void
    {
        $gate    = new AnyLangFilledGate();
        $context = RuleContextBuilder::withValues(['en' => [], 'de' => ''])
            ->scoredLanguages(['en', 'de'])
            ->build();

        self::assertFalse($gate->evaluate($context, $this->makeFieldDef('name')));
    }

    public function test_null_resolver_result_treated_as_empty(): void
    {
        $gate    = new AnyLangFilledGate();
        $context = RuleContextBuilder::withValues(['en' => null, 'de' => ''])
            ->scoredLanguages(['en', 'de'])
            ->build();

        self::assertFalse($gate->evaluate($context, $this->makeFieldDef('name')));
    }

    public function test_non_scored_language_does_not_lift_gate(): void
    {
        $gate    = new AnyLangFilledGate();
        $context = RuleContextBuilder::withValues(['en' => '', 'de' => '', 'fr' => 'Bonjour'])
            ->scoredLanguages(['en', 'de'])
            ->allLanguages(['en', 'de', 'fr'])
            ->build();

        self::assertFalse(
            $gate->evaluate($context, $this->makeFieldDef('name')),
            'fr is filled but excluded from scoring; gate must not be lifted',
        );
    }

    private function makeFieldDef(string $name): Data
    {
        $stub = $this->createStub(Data::class);
        $stub->method('getName')->willReturn($name);

        return $stub;
    }
}
