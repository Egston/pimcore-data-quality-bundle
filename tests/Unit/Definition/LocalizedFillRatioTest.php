<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition;

use Basilicom\DataQualityBundle\Definition\LanguageScope;
use Basilicom\DataQualityBundle\Definition\LocalizedFillRatio;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Basilicom\DataQualityBundle\Resolver\FieldPathResolverInterface;
use Basilicom\DataQualityBundle\Resolver\ResolvedLeaf;
use Basilicom\DataQualityBundle\Tests\Helper\RuleContextBuilder;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\DataQualityConfig;

final class LocalizedFillRatioTest extends TestCase
{
    public function test_all_filled_passes_any_threshold(): void
    {
        $rule = new LocalizedFillRatio();
        $context = RuleContextBuilder::withValues([
            'en' => 'Hello',
            'de' => 'Hallo',
            'fr' => 'Bonjour',
        ])->scoredLanguages(['en', 'de', 'fr'])->build();

        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), [], $context));
        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), ['1.0'], $context));
        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), ['0.5'], $context));
    }

    public function test_one_of_three_filled_with_default_threshold_passes(): void
    {
        $rule = new LocalizedFillRatio();
        $context = RuleContextBuilder::withValues([
            'en' => 'Hello',
            'de' => '',
            'fr' => null,
        ])->scoredLanguages(['en', 'de', 'fr'])->build();

        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), [], $context));
        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), ['0.0'], $context));
    }

    public function test_one_of_three_filled_fails_min_ratio_half(): void
    {
        $rule = new LocalizedFillRatio();
        $context = RuleContextBuilder::withValues([
            'en' => 'Hello',
            'de' => '',
            'fr' => null,
        ])->scoredLanguages(['en', 'de', 'fr'])->build();

        self::assertFalse($rule->validate(null, $this->makeFieldDef('name'), ['0.5'], $context));
    }

    public function test_source_only_filled_passes_default_threshold(): void
    {
        $rule = new LocalizedFillRatio();
        $context = RuleContextBuilder::withValues([
            'en' => 'Hello',
            'de' => '',
            'fr' => '',
        ])
            ->sourceLanguage('en')
            ->scoredLanguages(['en', 'de', 'fr'])
            ->build();

        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), [], $context));
        self::assertFalse($rule->validate(null, $this->makeFieldDef('name'), ['0.5'], $context));
    }

    public function test_empty_scored_returns_true(): void
    {
        $rule = new LocalizedFillRatio();
        $context = RuleContextBuilder::withValues(['en' => 'Hello'])
            ->scoredLanguages([])
            ->allLanguages(['en'])
            ->build();

        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), ['1.0'], $context));
    }

    public function test_memoisation_avoids_re_resolving_per_call(): void
    {
        $resolveCount = 0;

        $resolver = new class (['en' => 'Hello', 'de' => 'Hallo', 'fr' => 'Bonjour'], $resolveCount) implements FieldPathResolverInterface {
            public function __construct(
                private readonly array $values,
                public int &$count,
            ) {}

            public function resolve(Concrete $object, string $path, string $language): array
            {
                $this->count++;
                if (!array_key_exists($language, $this->values)) {
                    return [];
                }

                return [new ResolvedLeaf($path, $language, $this->values[$language])];
            }
        };

        $reflection = new \ReflectionClass(RuleContext::class);
        $context = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('object')->setValue(
            $context,
            (new \ReflectionClass(Concrete::class))->newInstanceWithoutConstructor(),
        );
        $reflection->getProperty('config')->setValue(
            $context,
            (new \ReflectionClass(DataQualityConfig::class))->newInstanceWithoutConstructor(),
        );
        $reflection->getProperty('resolver')->setValue($context, $resolver);
        $reflection->getProperty('flagsProvider')->setValue($context, null);
        $reflection->getProperty('languageScope')->setValue(
            $context,
            new LanguageScope('en', ['en', 'de', 'fr'], ['en', 'de', 'fr']),
        );

        $rule = new LocalizedFillRatio();
        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), [], $context));
        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), [], $context));

        self::assertSame(3, $resolveCount, 'resolver must be called once per language on first validate(); zero calls on second (memoised)');
    }

    private function makeFieldDef(string $name): Data
    {
        $stub = $this->createStub(Data::class);
        $stub->method('getName')->willReturn($name);

        return $stub;
    }
}
