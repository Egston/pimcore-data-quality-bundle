<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition;

use Basilicom\DataQualityBundle\Definition\DistinctFromSource;
use Basilicom\DataQualityBundle\Tests\Helper\RuleContextBuilder;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;

final class DistinctFromSourceTest extends TestCase
{
    public function test_calendar_event_stealth_english_pattern_fails(): void
    {
        $rule = new DistinctFromSource();
        $stealth = 'Quarterly results announcement';
        $context = RuleContextBuilder::withValues([
            'en' => $stealth,
            'de' => $stealth,
            'fr' => $stealth,
            'ja' => $stealth,
            'zh' => $stealth,
            'es' => $stealth,
        ])
            ->sourceLanguage('en')
            ->scoredLanguages(['en', 'de', 'fr', 'ja', 'zh', 'es'])
            ->build();

        self::assertFalse($rule->validate(null, $this->makeFieldDef('name'), [], $context));
    }

    public function test_all_distinct_translations_pass(): void
    {
        $rule = new DistinctFromSource();
        $context = RuleContextBuilder::withValues([
            'en' => 'Hello',
            'de' => 'Hallo',
            'fr' => 'Bonjour',
        ])
            ->sourceLanguage('en')
            ->scoredLanguages(['en', 'de', 'fr'])
            ->build();

        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), [], $context));
    }

    public function test_source_excluded_from_scored_still_used_as_baseline(): void
    {
        $rule = new DistinctFromSource();
        $context = RuleContextBuilder::withValues([
            'en' => 'Hello',
            'de' => 'Hello',
            'fr' => 'Bonjour',
        ])
            ->sourceLanguage('en')
            ->scoredLanguages(['de', 'fr'])
            ->allLanguages(['en', 'de', 'fr'])
            ->build();

        self::assertFalse(
            $rule->validate(null, $this->makeFieldDef('name'), [], $context),
            'source language must remain the comparison baseline even when excluded from the scored set'
        );
    }

    public function test_empty_target_is_skipped_not_flagged(): void
    {
        $rule = new DistinctFromSource();
        $context = RuleContextBuilder::withValues([
            'en' => 'Hello',
            'de' => 'Hallo',
            'fr' => '',
        ])
            ->sourceLanguage('en')
            ->scoredLanguages(['en', 'de', 'fr'])
            ->build();

        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), [], $context));
    }

    public function test_whitespace_only_difference_still_flagged(): void
    {
        $rule = new DistinctFromSource();
        $context = RuleContextBuilder::withValues([
            'en' => 'Foo',
            'de' => ' Foo ',
            'fr' => 'Bar',
        ])
            ->sourceLanguage('en')
            ->scoredLanguages(['en', 'de', 'fr'])
            ->build();

        self::assertFalse(
            $rule->validate(null, $this->makeFieldDef('name'), [], $context),
            'leading/trailing whitespace must not lift the stealth-English flag'
        );
    }

    public function test_source_override_param_uses_named_language(): void
    {
        $rule = new DistinctFromSource();
        $context = RuleContextBuilder::withValues([
            'en' => 'Hello',
            'de' => 'Hello',
            'fr' => 'Salut',
        ])
            ->sourceLanguage('en')
            ->scoredLanguages(['en', 'de', 'fr'])
            ->build();

        self::assertTrue(
            $rule->validate(null, $this->makeFieldDef('name'), ['fr'], $context),
            'overriding the source language to FR makes EN/DE both distinct from "Salut"'
        );
    }

    public function test_empty_source_returns_true(): void
    {
        $rule = new DistinctFromSource();
        $context = RuleContextBuilder::withValues([
            'en' => '',
            'de' => 'Hallo',
        ])
            ->sourceLanguage('en')
            ->scoredLanguages(['en', 'de'])
            ->build();

        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), [], $context));
    }

    private function makeFieldDef(string $name): Data
    {
        $stub = $this->createStub(Data::class);
        $stub->method('getName')->willReturn($name);

        return $stub;
    }
}
