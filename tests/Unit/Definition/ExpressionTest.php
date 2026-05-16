<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition;

use Basilicom\DataQualityBundle\Definition\DefinitionException;
use Basilicom\DataQualityBundle\Definition\Expression;
use Basilicom\DataQualityBundle\Definition\LocalizedAwareDefinition;
use Basilicom\DataQualityBundle\Provider\LanguageFlagsProvider;
use Basilicom\DataQualityBundle\Registry\LanguageFlagsProviderRegistry;
use Basilicom\DataQualityBundle\Service\ExpressionEvaluator;
use Basilicom\DataQualityBundle\Service\SafeFunctionProvider;
use Basilicom\DataQualityBundle\Tests\Helper\RuleContextBuilder;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

final class ExpressionTest extends TestCase
{
    public function test_implements_localized_aware_marker(): void
    {
        $rule = $this->makeRule();

        self::assertInstanceOf(LocalizedAwareDefinition::class, $rule);
    }

    public function test_non_container_per_language_sweep_passes_when_every_iteration_truthy(): void
    {
        $rule = $this->makeRule();
        $context = RuleContextBuilder::withValues([
            'en' => 'Hello',
            'de' => 'Hallo',
            'fr' => 'Bonjour',
        ])->scoredLanguages(['en', 'de', 'fr'])->build();

        $valid = $rule->validate(null, $this->makeFieldDef('description'), [
            'expression' => 'value != null',
        ], $context);

        self::assertTrue($valid);
    }

    public function test_non_container_sweep_evaluates_all_languages_not_only_first(): void
    {
        $rule = $this->makeRule();
        $context = RuleContextBuilder::withValues([
            'en' => 'Hello',
            'de' => 'Hallo',
            'fr' => 'Bonjour',
        ])->scoredLanguages(['en', 'de', 'fr'])->build();

        $valid = $rule->validate(null, $this->makeFieldDef('description'), [
            'expression' => "lang != 'fr'",
        ], $context);

        self::assertFalse($valid, 'sweep must reach fr and return false; early-exit on first truthy would return true');
    }

    public function test_non_container_per_language_sweep_fails_on_first_falsy_iteration(): void
    {
        $rule = $this->makeRule();
        $context = RuleContextBuilder::withValues([
            'en' => 'Hello',
            'de' => null,
            'fr' => 'Bonjour',
        ])->scoredLanguages(['en', 'de', 'fr'])->build();

        $valid = $rule->validate(null, $this->makeFieldDef('description'), [
            'expression' => 'value != null',
        ], $context);

        self::assertFalse($valid);
    }

    public function test_non_container_binds_lang_per_iteration(): void
    {
        $rule = $this->makeRule();
        $context = RuleContextBuilder::withValues([
            'en' => 'x',
            'de' => 'x',
        ])->scoredLanguages(['en', 'de'])->build();

        $valid = $rule->validate(null, $this->makeFieldDef('description'), [
            'expression' => "lang in ['en', 'de']",
        ], $context);

        self::assertTrue($valid);
    }

    public function test_non_container_binds_value_per_iteration(): void
    {
        $rule = $this->makeRule();
        $context = RuleContextBuilder::withValues([
            'en' => 'Hello',
            'de' => 'Hallo',
        ])->scoredLanguages(['en', 'de'])->build();

        $valid = $rule->validate(null, $this->makeFieldDef('description'), [
            'expression' => "lang == 'en' ? value == 'Hello' : value == 'Hallo'",
        ], $context);

        self::assertTrue($valid);
    }

    public function test_container_path_evaluates_once_with_container_leaves_bound(): void
    {
        $rule = $this->makeRule();
        $context = RuleContextBuilder::withValues([
            'en' => 'a',
            'de' => 'b',
            'fr' => 'c',
        ])->scoredLanguages(['en', 'de', 'fr'])->build();

        $valid = $rule->validate(null, $this->makeFieldDef('sections[].title'), [
            'expression' => 'count(containerLeaves) >= 1',
        ], $context);

        self::assertTrue($valid);
    }

    public function test_missing_expression_parameter_throws_definition_exception(): void
    {
        $rule = $this->makeRule();
        $context = RuleContextBuilder::withValues(['en' => 'x'])->build();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('expression');

        $rule->validate(null, $this->makeFieldDef('description'), [], $context);
    }

    public function test_empty_expression_parameter_throws_definition_exception(): void
    {
        $rule = $this->makeRule();
        $context = RuleContextBuilder::withValues(['en' => 'x'])->build();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('expression');

        $rule->validate(null, $this->makeFieldDef('description'), [
            'expression' => '',
        ], $context);
    }

    public function test_non_string_expression_parameter_throws_definition_exception(): void
    {
        $rule = $this->makeRule();
        $context = RuleContextBuilder::withValues(['en' => 'x'])->build();

        $this->expectException(DefinitionException::class);

        $rule->validate(null, $this->makeFieldDef('description'), [
            'expression' => 123,
        ], $context);
    }

    public function test_flags_by_lang_is_null_when_no_provider_id_given(): void
    {
        $rule = $this->makeRule();
        $context = RuleContextBuilder::withValues([
            'en' => 'x',
            'de' => 'y',
        ])->scoredLanguages(['en', 'de'])->build();

        $valid = $rule->validate(null, $this->makeFieldDef('description'), [
            'expression' => 'flagsByLang == null',
        ], $context);

        self::assertTrue($valid);
    }

    public function test_flags_by_lang_bound_when_provider_id_supplied(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.x', $this->fakeProvider(['en' => true, 'de' => true]));

        $rule = $this->makeRule($registry);
        $context = RuleContextBuilder::withValues([
            'en' => 'x',
            'de' => 'y',
        ])->scoredLanguages(['en', 'de'])->build();

        $valid = $rule->validate(null, $this->makeFieldDef('description'), [
            'expression' => "flagsByLang['de'] == true",
            'provider_service_id' => 'app.flags.x',
        ], $context);

        self::assertTrue($valid);
    }

    public function test_empty_provider_id_behaves_like_absent_flags_by_lang_is_null(): void
    {
        $rule = $this->makeRule();
        $context = RuleContextBuilder::withValues(['en' => 'x'])->scoredLanguages(['en'])->build();

        $valid = $rule->validate(null, $this->makeFieldDef('description'), [
            'expression' => 'flagsByLang == null',
            'provider_service_id' => '',
        ], $context);

        self::assertTrue($valid, 'empty provider_service_id must treat flagsByLang as null');
    }

    public function test_flags_by_lang_map_has_expected_shape(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.y', $this->fakeProvider(['en' => true, 'de' => false, 'fr' => true]));

        $rule = $this->makeRule($registry);
        $context = RuleContextBuilder::withValues([
            'en' => 'x',
            'de' => 'y',
            'fr' => 'z',
        ])->scoredLanguages(['en', 'de', 'fr'])->build();

        $valid = $rule->validate(null, $this->makeFieldDef('description'), [
            'expression' => "count(flagsByLang) == 3 and flagsByLang['en'] == true and flagsByLang['de'] == false",
            'provider_service_id' => 'app.flags.y',
        ], $context);

        self::assertTrue($valid, 'flagsByLang must expose every key passed by the provider');
    }

    public function test_unregistered_provider_id_throws_definition_exception(): void
    {
        $rule = $this->makeRule();
        $context = RuleContextBuilder::withValues(['en' => 'x', 'de' => 'y'])->build();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('app.flags.nope');

        $rule->validate(null, $this->makeFieldDef('description'), [
            'expression' => 'true',
            'provider_service_id' => 'app.flags.nope',
        ], $context);
    }

    public function test_throwing_provider_propagates_exception_not_fail_closed(): void
    {
        $throwingProvider = new class implements LanguageFlagsProvider {
            public function getName(): string
            {
                return 'throwing';
            }

            public function getFlags(Concrete $object): array
            {
                throw new \RuntimeException('provider is broken');
            }

            public function supportedClassIds(): array
            {
                return [];
            }
        };

        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.broken', $throwingProvider);

        $rule = $this->makeRule($registry);
        $context = RuleContextBuilder::withValues(['en' => 'x'])->scoredLanguages(['en'])->build();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('provider is broken');

        $rule->validate(null, $this->makeFieldDef('description'), [
            'expression' => 'true',
            'provider_service_id' => 'app.flags.broken',
        ], $context);
    }

    public function test_compile_error_throws_definition_exception(): void
    {
        $rule = $this->makeRule();
        $context = RuleContextBuilder::withValues(['en' => 'x'])
            ->scoredLanguages(['en'])
            ->build();

        $this->expectException(DefinitionException::class);

        $rule->validate(null, $this->makeFieldDef('description'), [
            'expression' => "value ===== 'broken'",
        ], $context);
    }

    public function test_runtime_error_in_expression_returns_false_fail_closed(): void
    {
        $rule = $this->makeRule();
        $context = RuleContextBuilder::withValues([
            'en' => 'x',
            'de' => 'y',
        ])->scoredLanguages(['en', 'de'])->build();

        $valid = $rule->validate(null, $this->makeFieldDef('description'), [
            'expression' => 'object.thisMethodDoesNotExist()',
        ], $context);

        self::assertFalse($valid, 'runtime error in expression must fail-CLOSED for rules');
    }

    public function test_empty_scored_languages_returns_true_non_container(): void
    {
        $rule = $this->makeRule();
        $context = RuleContextBuilder::withValues(['en' => 'x'])
            ->scoredLanguages([])
            ->allLanguages(['en'])
            ->build();

        $valid = $rule->validate(null, $this->makeFieldDef('description'), [
            'expression' => 'false',
        ], $context);

        self::assertTrue($valid, 'empty scored set returns true regardless of expression');
    }

    public function test_container_path_binds_container_leaves_with_language_property(): void
    {
        $rule = $this->makeRule();
        $context = RuleContextBuilder::withValues([
            'en' => 'Hello',
            'de' => 'Hallo',
        ])->scoredLanguages(['en', 'de'])->build();

        $valid = $rule->validate(null, $this->makeFieldDef('sections[].title'), [
            'expression' => 'count(containerLeaves) == 2',
        ], $context);

        self::assertTrue($valid, 'containerLeaves must contain one leaf per scored language');

        $validLang = $rule->validate(null, $this->makeFieldDef('sections[].title'), [
            'expression' => "containerLeaves[0].language == 'en'",
        ], $context);

        self::assertTrue($validLang, 'containerLeaves elements must expose the language property');
    }

    public function test_container_path_runtime_error_returns_false(): void
    {
        $rule = $this->makeRule();
        $context = RuleContextBuilder::withValues(['en' => 'x'])
            ->scoredLanguages(['en'])
            ->build();

        $valid = $rule->validate(null, $this->makeFieldDef('sections[].title'), [
            'expression' => 'containerLeaves.thisMethodDoesNotExist()',
        ], $context);

        self::assertFalse($valid);
    }

    public function test_container_path_with_lang_reference_throws_definition_exception(): void
    {
        $rule = $this->makeRule();
        $context = RuleContextBuilder::withValues(['en' => 'x'])->scoredLanguages(['en'])->build();

        $this->expectException(DefinitionException::class);

        $rule->validate(null, $this->makeFieldDef('sections[].title'), [
            'expression' => "lang == 'en'",
        ], $context);
    }

    private function makeRule(?LanguageFlagsProviderRegistry $registry = null): Expression
    {
        $el = new ExpressionLanguage(null, [new SafeFunctionProvider()]);
        $evaluator = new ExpressionEvaluator($el);

        return new Expression($evaluator, $registry ?? new LanguageFlagsProviderRegistry());
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
