<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition;

use Basilicom\DataQualityBundle\Definition\DefinitionException;
use Basilicom\DataQualityBundle\Definition\LanguageFlagRatio;
use Basilicom\DataQualityBundle\Definition\LocalizedAwareDefinition;
use Basilicom\DataQualityBundle\Provider\LanguageFlagsProvider;
use Basilicom\DataQualityBundle\Registry\LanguageFlagsProviderRegistry;
use Basilicom\DataQualityBundle\Tests\Helper\RuleContextBuilder;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;

final class LanguageFlagRatioTest extends TestCase
{
    public function test_implements_localized_aware_marker(): void
    {
        $rule = new LanguageFlagRatio(new LanguageFlagsProviderRegistry());

        self::assertInstanceOf(LocalizedAwareDefinition::class, $rule);
    }

    public function test_passes_when_ratio_meets_threshold(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.x', $this->fakeProvider(['en' => true, 'de' => true, 'fr' => true]));

        $context = RuleContextBuilder::withValues(['en' => null, 'de' => null, 'fr' => null])
            ->sourceLanguage('en')
            ->scoredLanguages(['en', 'de', 'fr'])
            ->build();

        $rule = new LanguageFlagRatio($registry);

        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), [
            'provider_service_id' => 'app.flags.x',
            'min_ratio' => 0.6,
        ], $context));
    }

    public function test_fails_when_ratio_below_threshold(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.x', $this->fakeProvider(['en' => true, 'de' => false, 'fr' => false]));

        $context = RuleContextBuilder::withValues(['en' => null, 'de' => null, 'fr' => null])
            ->sourceLanguage('en')
            ->scoredLanguages(['en', 'de', 'fr'])
            ->build();

        $rule = new LanguageFlagRatio($registry);

        self::assertFalse($rule->validate(null, $this->makeFieldDef('name'), [
            'provider_service_id' => 'app.flags.x',
            'min_ratio' => 0.6,
        ], $context));
    }

    public function test_default_min_ratio_is_six_tenths(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.partial', $this->fakeProvider([
            'en' => true,
            'de' => true,
            'fr' => false,
            'es' => false,
        ]));

        $context = RuleContextBuilder::withValues(['en' => null, 'de' => null, 'fr' => null, 'es' => null])
            ->sourceLanguage('en')
            ->scoredLanguages(['en', 'de', 'fr', 'es'])
            ->build();

        $rule = new LanguageFlagRatio($registry);

        self::assertFalse($rule->validate(null, $this->makeFieldDef('name'), [
            'provider_service_id' => 'app.flags.partial',
        ], $context), 'default min_ratio of 0.6 must be applied when param absent');
    }

    public function test_source_language_excluded_from_numerator_and_denominator(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.s', $this->fakeProvider(['en' => false, 'de' => true]));

        $context = RuleContextBuilder::withValues(['en' => null, 'de' => null])
            ->sourceLanguage('en')
            ->scoredLanguages(['en', 'de'])
            ->build();

        $rule = new LanguageFlagRatio($registry);

        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), [
            'provider_service_id' => 'app.flags.s',
            'min_ratio' => 1.0,
        ], $context));
    }

    public function test_unknown_language_distinct_from_false(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        // FR is "unknown" (absent from map); DE is true. 1/1 = 1.0 (not 1/2).
        $registry->register('app.flags.u', $this->fakeProvider(['en' => true, 'de' => true]));

        $context = RuleContextBuilder::withValues(['en' => null, 'de' => null, 'fr' => null])
            ->sourceLanguage('en')
            ->scoredLanguages(['en', 'de', 'fr'])
            ->build();

        $rule = new LanguageFlagRatio($registry);

        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), [
            'provider_service_id' => 'app.flags.u',
            'min_ratio' => 1.0,
        ], $context), 'absent FR must not pull the denominator up to 2');
    }

    public function test_zero_denominator_fails_open(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.empty', $this->fakeProvider([]));

        $context = RuleContextBuilder::withValues(['en' => null, 'de' => null, 'fr' => null])
            ->sourceLanguage('en')
            ->scoredLanguages(['en', 'de', 'fr'])
            ->build();

        $rule = new LanguageFlagRatio($registry);

        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), [
            'provider_service_id' => 'app.flags.empty',
            'min_ratio' => 1.0,
        ], $context));
    }

    public function test_zero_denominator_fails_open_when_only_source_is_in_map(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.source_only', $this->fakeProvider(['en' => true]));

        $context = RuleContextBuilder::withValues(['en' => null, 'de' => null])
            ->sourceLanguage('en')
            ->scoredLanguages(['en', 'de'])
            ->build();

        $rule = new LanguageFlagRatio($registry);

        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), [
            'provider_service_id' => 'app.flags.source_only',
            'min_ratio' => 1.0,
        ], $context));
    }

    public function test_scored_languages_source_only_fails_open(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.src', $this->fakeProvider(['en' => true, 'de' => true]));

        $context = RuleContextBuilder::withValues(['en' => null])
            ->sourceLanguage('en')
            ->scoredLanguages(['en'])
            ->build();

        $rule = new LanguageFlagRatio($registry);

        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), [
            'provider_service_id' => 'app.flags.src',
            'min_ratio' => 1.0,
        ], $context), 'only source in scoredLanguages → denominator zero → fail-open');
    }

    public function test_missing_provider_service_id_throws_definition_exception(): void
    {
        $rule = new LanguageFlagRatio(new LanguageFlagsProviderRegistry());
        $context = RuleContextBuilder::withValues(['en' => null])->build();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('provider_service_id');

        $rule->validate(null, $this->makeFieldDef('name'), [], $context);
    }

    public function test_empty_provider_service_id_throws_definition_exception(): void
    {
        $rule = new LanguageFlagRatio(new LanguageFlagsProviderRegistry());
        $context = RuleContextBuilder::withValues(['en' => null])->build();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('provider_service_id');

        $rule->validate(null, $this->makeFieldDef('name'), ['provider_service_id' => ''], $context);
    }

    public function test_unregistered_provider_throws_definition_exception(): void
    {
        $rule = new LanguageFlagRatio(new LanguageFlagsProviderRegistry());
        $context = RuleContextBuilder::withValues(['en' => null])->build();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('app.flags.nope');

        $rule->validate(null, $this->makeFieldDef('name'), [
            'provider_service_id' => 'app.flags.nope',
        ], $context);
    }

    public function test_non_numeric_min_ratio_throws_definition_exception(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.x', $this->fakeProvider([]));
        $rule = new LanguageFlagRatio($registry);
        $context = RuleContextBuilder::withValues(['en' => null])->build();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('min_ratio');

        $rule->validate(null, $this->makeFieldDef('name'), [
            'provider_service_id' => 'app.flags.x',
            'min_ratio' => 'not-a-number',
        ], $context);
    }

    public function test_out_of_range_min_ratio_throws_definition_exception(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.x', $this->fakeProvider([]));
        $rule = new LanguageFlagRatio($registry);
        $context = RuleContextBuilder::withValues(['en' => null])->build();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('min_ratio');

        $rule->validate(null, $this->makeFieldDef('name'), [
            'provider_service_id' => 'app.flags.x',
            'min_ratio' => 1.5,
        ], $context);
    }

    public function test_negative_min_ratio_throws_definition_exception(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.x', $this->fakeProvider([]));
        $rule = new LanguageFlagRatio($registry);
        $context = RuleContextBuilder::withValues(['en' => null])->build();

        $this->expectException(DefinitionException::class);

        $rule->validate(null, $this->makeFieldDef('name'), [
            'provider_service_id' => 'app.flags.x',
            'min_ratio' => -0.1,
        ], $context);
    }

    public function test_min_ratio_zero_always_passes(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.allfalse', $this->fakeProvider(['en' => true, 'de' => false, 'fr' => false]));

        $context = RuleContextBuilder::withValues(['en' => null, 'de' => null, 'fr' => null])
            ->sourceLanguage('en')
            ->scoredLanguages(['en', 'de', 'fr'])
            ->build();

        $rule = new LanguageFlagRatio($registry);

        self::assertTrue($rule->validate(null, $this->makeFieldDef('name'), [
            'provider_service_id' => 'app.flags.allfalse',
            'min_ratio' => 0.0,
        ], $context), 'min_ratio=0.0 must pass even when all non-source flags are false');
    }

    public function test_min_ratio_one_requires_all_flags_true(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.partial', $this->fakeProvider(['en' => true, 'de' => true, 'fr' => false]));

        $context = RuleContextBuilder::withValues(['en' => null, 'de' => null, 'fr' => null])
            ->sourceLanguage('en')
            ->scoredLanguages(['en', 'de', 'fr'])
            ->build();

        $rule = new LanguageFlagRatio($registry);

        self::assertFalse($rule->validate(null, $this->makeFieldDef('name'), [
            'provider_service_id' => 'app.flags.partial',
            'min_ratio' => 1.0,
        ], $context), 'min_ratio=1.0 must fail when any non-source flag is false');
    }

    public function test_container_path_rejected(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.x', $this->fakeProvider(['en' => true, 'de' => true]));
        $rule = new LanguageFlagRatio($registry);
        $context = RuleContextBuilder::withValues(['en' => null, 'de' => null])->build();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('container');

        $rule->validate(null, $this->makeFieldDef('sections[].title'), [
            'provider_service_id' => 'app.flags.x',
        ], $context);
    }

    public function test_dotted_path_rejected(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.x', $this->fakeProvider(['en' => true]));
        $rule = new LanguageFlagRatio($registry);
        $context = RuleContextBuilder::withValues(['en' => null])->build();

        $this->expectException(DefinitionException::class);

        $rule->validate(null, $this->makeFieldDef('brick.field'), [
            'provider_service_id' => 'app.flags.x',
        ], $context);
    }

    /**
     * @param array<string, bool> $flags
     */
    private function fakeProvider(array $flags): LanguageFlagsProvider
    {
        return new class ($flags) implements LanguageFlagsProvider {
            /** @param array<string, bool> $flags */
            public function __construct(private readonly array $flags) {}

            public function getName(): string
            {
                return 'fake';
            }

            public function getFlags(Concrete $object): array
            {
                return $this->flags;
            }

            public function supportedClassIds(): array
            {
                return [];
            }
        };
    }

    private function makeFieldDef(string $name): Data
    {
        $stub = $this->createStub(Data::class);
        $stub->method('getName')->willReturn($name);

        return $stub;
    }
}
