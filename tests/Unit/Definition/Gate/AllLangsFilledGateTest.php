<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition\Gate;

use Basilicom\DataQualityBundle\Definition\Gate\AllLangsFilledGate;
use Basilicom\DataQualityBundle\Tests\Helper\RuleContextBuilder;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;

final class AllLangsFilledGateTest extends TestCase
{
    public function test_applies_when_every_scored_language_is_filled(): void
    {
        $gate = new AllLangsFilledGate();
        $context = RuleContextBuilder::withValues(['en' => 'Hello', 'de' => 'Hallo', 'fr' => 'Bonjour'])
            ->scoredLanguages(['en', 'de', 'fr'])
            ->build();

        self::assertTrue($gate->evaluate($context, $this->makeFieldDef('name')));
    }

    public function test_does_not_apply_when_one_scored_language_is_empty(): void
    {
        $gate = new AllLangsFilledGate();
        $context = RuleContextBuilder::withValues(['en' => 'Hello', 'de' => '', 'fr' => 'Bonjour'])
            ->scoredLanguages(['en', 'de', 'fr'])
            ->build();

        self::assertFalse($gate->evaluate($context, $this->makeFieldDef('name')));
    }

    public function test_iterates_scored_set_not_all_languages(): void
    {
        $gate = new AllLangsFilledGate();
        $context = RuleContextBuilder::withValues(['en' => 'Hello', 'de' => 'Hallo', 'fr' => ''])
            ->scoredLanguages(['en', 'de'])
            ->allLanguages(['en', 'de', 'fr'])
            ->build();

        self::assertTrue(
            $gate->evaluate($context, $this->makeFieldDef('name')),
            'fr is empty but excluded from scoring; gate must not be lowered',
        );
    }

    public function test_excluded_source_language_does_not_affect_decision(): void
    {
        $gate = new AllLangsFilledGate();
        $context = RuleContextBuilder::withValues(['en' => '', 'de' => 'Hallo', 'fr' => 'Bonjour'])
            ->sourceLanguage('en')
            ->scoredLanguages(['de', 'fr'])
            ->allLanguages(['en', 'de', 'fr'])
            ->build();

        self::assertTrue(
            $gate->evaluate($context, $this->makeFieldDef('name')),
            'EN excluded from scoring; AllLangsFilled must not ask for it',
        );
    }

    private function makeFieldDef(string $name): Data
    {
        $stub = $this->createStub(Data::class);
        $stub->method('getName')->willReturn($name);

        return $stub;
    }
}
