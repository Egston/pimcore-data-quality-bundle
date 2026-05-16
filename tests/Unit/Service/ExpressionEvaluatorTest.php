<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Service;

use Basilicom\DataQualityBundle\Definition\DefinitionException;
use Basilicom\DataQualityBundle\Service\ExpressionEvaluator;
use Basilicom\DataQualityBundle\Service\SafeFunctionProvider;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\Concrete;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\ParsedExpression;

final class ExpressionEvaluatorTest extends TestCase
{
    public function test_evaluate_binds_object_variable(): void
    {
        $evaluator = $this->makeEvaluator();
        $object = $this->object();

        $result = $evaluator->evaluate(
            'object.getValue() > 5',
            [
                'object' => $object,
                'valuesByLang' => [],
                'flagsByLang' => null,
                'lang' => 'en',
                'value' => null,
                'sourceLanguage' => 'en',
            ],
            ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
        );

        self::assertTrue($result);
    }

    public function test_evaluate_binds_values_by_lang(): void
    {
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            "valuesByLang['de'] == 'Hallo'",
            [
                'object' => $this->object(),
                'valuesByLang' => ['en' => 'Hello', 'de' => 'Hallo'],
                'flagsByLang' => null,
                'lang' => 'de',
                'value' => 'Hallo',
                'sourceLanguage' => 'en',
            ],
            ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
        );

        self::assertTrue($result);
    }

    public function test_evaluate_binds_flags_by_lang(): void
    {
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            "flagsByLang['de'] == true",
            [
                'object' => $this->object(),
                'valuesByLang' => [],
                'flagsByLang' => ['en' => true, 'de' => true],
                'lang' => 'de',
                'value' => null,
                'sourceLanguage' => 'en',
            ],
            ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
        );

        self::assertTrue($result);
    }

    public function test_evaluate_binds_lang_and_value(): void
    {
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            "lang == 'de' and value == 'Hallo'",
            [
                'object' => $this->object(),
                'valuesByLang' => [],
                'flagsByLang' => null,
                'lang' => 'de',
                'value' => 'Hallo',
                'sourceLanguage' => 'en',
            ],
            ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
        );

        self::assertTrue($result);
    }

    public function test_evaluate_binds_source_language(): void
    {
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            "sourceLanguage == 'en'",
            [
                'object' => $this->object(),
                'valuesByLang' => [],
                'flagsByLang' => null,
                'lang' => 'en',
                'value' => null,
                'sourceLanguage' => 'en',
            ],
            ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
        );

        self::assertTrue($result);
    }

    public function test_count_safe_function(): void
    {
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            'count(valuesByLang) == 3',
            [
                'object' => $this->object(),
                'valuesByLang' => ['en' => 'a', 'de' => 'b', 'fr' => 'c'],
                'flagsByLang' => null,
                'lang' => 'en',
                'value' => 'a',
                'sourceLanguage' => 'en',
            ],
            ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
        );

        self::assertTrue($result);
    }

    public function test_filter_safe_function_preserves_keys_and_receives_value_and_key(): void
    {
        $evaluator = $this->makeEvaluator();

        $sawKeys = [];
        $predicate = static function ($value, $key) use (&$sawKeys): bool {
            $sawKeys[] = $key;

            return $value !== null && $key !== 'en';
        };

        $variables = [
            'object' => $this->object(),
            'valuesByLang' => ['en' => 'Hello', 'de' => 'Hallo', 'fr' => 'Bonjour', 'es' => null],
            'flagsByLang' => null,
            'lang' => 'en',
            'value' => 'Hello',
            'sourceLanguage' => 'en',
            'pred' => $predicate,
        ];

        $count = $evaluator->evaluate(
            'count(filter(valuesByLang, pred))',
            $variables,
            [...ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER, 'pred'],
        );

        self::assertSame(2, $count, 'filter must drop en (key match) and es (null value), keeping de + fr');
        self::assertSame(['en', 'de', 'fr', 'es'], $sawKeys, 'closure must receive (value, key) — keys observed in iteration order');
    }

    public function test_filter_safe_function_filtered_keys_survive(): void
    {
        $evaluator = $this->makeEvaluator();

        $predicate = static fn($value, $key): bool => $key !== 'en';

        $filtered = $evaluator->evaluate(
            'filter(valuesByLang, pred)',
            [
                'object' => $this->object(),
                'valuesByLang' => ['en' => 'Hello', 'de' => 'Hallo', 'fr' => 'Bonjour'],
                'flagsByLang' => null,
                'lang' => 'en',
                'value' => 'Hello',
                'sourceLanguage' => 'en',
                'pred' => $predicate,
            ],
            [...ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER, 'pred'],
        );

        self::assertSame(['de' => 'Hallo', 'fr' => 'Bonjour'], $filtered, 'string keys must be preserved');
    }

    public function test_same_safe_function_trims_whitespace_on_strings(): void
    {
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            "same('Hello', '  Hello  ')",
            $this->emptyNonContainerVars(),
            ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
        );

        self::assertTrue($result);
    }

    public function test_same_safe_function_strict_compare_on_non_strings(): void
    {
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            'same(1, 1)',
            $this->emptyNonContainerVars(),
            ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
        );

        self::assertTrue($result);
    }

    public function test_same_safe_function_strict_compare_distinguishes_int_and_string(): void
    {
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            "same(1, '1')",
            $this->emptyNonContainerVars(),
            ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
        );

        self::assertFalse($result);
    }

    public function test_different_safe_function_is_logical_negation_of_same(): void
    {
        $evaluator = $this->makeEvaluator();

        self::assertTrue($evaluator->evaluate(
            "different('Hello', 'Bonjour')",
            $this->emptyNonContainerVars(),
            ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
        ));
        self::assertFalse($evaluator->evaluate(
            "different('Hello', '  Hello  ')",
            $this->emptyNonContainerVars(),
            ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
        ));
    }

    public function test_same_source_string_returns_same_parsed_expression_instance(): void
    {
        $evaluator = $this->makeEvaluator();
        $vars = $this->emptyNonContainerVars();

        $evaluator->evaluate('value == null', $vars, ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER);

        $reflection = new \ReflectionObject($evaluator);
        $cacheProp = $reflection->getProperty('parsedCache');
        $cacheProp->setAccessible(true);
        $cacheAfter1 = $cacheProp->getValue($evaluator);
        $firstInstance = array_values($cacheAfter1)[0];

        $evaluator->evaluate('value == null', $vars, ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER);
        $evaluator->evaluate('value == null', $vars, ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER);
        $cacheAfter3 = $cacheProp->getValue($evaluator);

        self::assertIsArray($cacheAfter3);
        self::assertCount(1, $cacheAfter3, 'identical source must reuse the cached ParsedExpression');
        self::assertContainsOnlyInstancesOf(ParsedExpression::class, $cacheAfter3);
        self::assertSame($firstInstance, array_values($cacheAfter3)[0], 'identical source must reuse the parsed instance, not re-parse');
    }

    public function test_distinct_source_strings_populate_separate_cache_entries(): void
    {
        $evaluator = $this->makeEvaluator();
        $vars = $this->emptyNonContainerVars();

        $evaluator->evaluate('value == null', $vars, ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER);
        $evaluator->evaluate('value != null', $vars, ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER);

        $reflection = new \ReflectionObject($evaluator);
        $cacheProp = $reflection->getProperty('parsedCache');
        $cacheProp->setAccessible(true);

        self::assertCount(2, $cacheProp->getValue($evaluator));
    }

    public function test_related_object_chain_reach(): void
    {
        $related = new class extends Concrete {
            public function __construct() {}

            public function getTranslationVerifiedScore(): int
            {
                return 85;
            }
        };
        $object = new class ($related) extends Concrete {
            public function __construct(private readonly object $rel) {}

            public function getResourceType(): object
            {
                return $this->rel;
            }
        };

        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            'object.getResourceType().getTranslationVerifiedScore() >= 80',
            [
                'object' => $object,
                'valuesByLang' => [],
                'flagsByLang' => null,
                'lang' => 'en',
                'value' => null,
                'sourceLanguage' => 'en',
            ],
            ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
        );

        self::assertTrue($result);
    }

    public function test_compile_error_throws_definition_exception_with_source_embedded(): void
    {
        $evaluator = $this->makeEvaluator();

        try {
            $evaluator->evaluate(
                "value ===== 'broken'",
                $this->emptyNonContainerVars(),
                ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
            );
            self::fail('expected DefinitionException');
        } catch (DefinitionException $e) {
            self::assertStringContainsString("value ===== 'broken'", $e->getMessage());
        }
    }

    public function test_count_empty_array_returns_zero(): void
    {
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            'count(valuesByLang) == 0',
            [
                'object' => $this->object(),
                'valuesByLang' => [],
                'flagsByLang' => null,
                'lang' => 'en',
                'value' => null,
                'sourceLanguage' => 'en',
            ],
            ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
        );

        self::assertTrue($result);
    }

    public function test_count_with_null_value_entry_returns_one(): void
    {
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            'count(valuesByLang) == 1',
            [
                'object' => $this->object(),
                'valuesByLang' => ['en' => null],
                'flagsByLang' => null,
                'lang' => 'en',
                'value' => null,
                'sourceLanguage' => 'en',
            ],
            ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
        );

        self::assertTrue($result);
    }

    public function test_filter_count_canonical_idiom(): void
    {
        $evaluator = $this->makeEvaluator();
        $sourceLanguage = 'en';
        $nonNullNonSource = static fn($v, $k) => $v !== null && $k !== $sourceLanguage;

        $result = $evaluator->evaluate(
            'count(filter(valuesByLang, pred)) >= 1',
            [
                'object' => $this->object(),
                'valuesByLang' => ['en' => 'Hello', 'de' => 'Hallo', 'fr' => null],
                'flagsByLang' => null,
                'lang' => 'en',
                'value' => 'Hello',
                'sourceLanguage' => $sourceLanguage,
                'pred' => $nonNullNonSource,
            ],
            [...ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER, 'pred'],
        );

        self::assertTrue($result, 'de has value and is not sourceLanguage — filter must keep it');
    }

    public function test_same_null_and_null_returns_true(): void
    {
        $evaluator = $this->makeEvaluator();

        self::assertTrue($evaluator->evaluate(
            'same(null, null)',
            $this->emptyNonContainerVars(),
            ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
        ));
    }

    public function test_same_null_and_empty_string_returns_false(): void
    {
        $evaluator = $this->makeEvaluator();

        self::assertFalse($evaluator->evaluate(
            "same(null, '')",
            $this->emptyNonContainerVars(),
            ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
        ));
    }

    public function test_same_null_and_non_empty_string_returns_false(): void
    {
        $evaluator = $this->makeEvaluator();

        self::assertFalse($evaluator->evaluate(
            "same(null, 'x')",
            $this->emptyNonContainerVars(),
            ExpressionEvaluator::VARIABLE_NAMES_NON_CONTAINER,
        ));
    }

    public function test_lang_reference_in_container_variable_set_is_rejected_at_parse(): void
    {
        $evaluator = $this->makeEvaluator();

        $this->expectException(DefinitionException::class);

        $evaluator->evaluate(
            "lang == 'en'",
            [
                'object' => $this->object(),
                'containerLeaves' => [],
                'flagsByLang' => null,
                'sourceLanguage' => 'en',
            ],
            ExpressionEvaluator::VARIABLE_NAMES_CONTAINER,
        );
    }

    private function makeEvaluator(): ExpressionEvaluator
    {
        $el = new ExpressionLanguage(null, [new SafeFunctionProvider()]);

        return new ExpressionEvaluator($el);
    }

    private function object(): Concrete
    {
        return new class extends Concrete {
            public function __construct() {}

            public function getValue(): int
            {
                return 10;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyNonContainerVars(): array
    {
        return [
            'object' => $this->object(),
            'valuesByLang' => [],
            'flagsByLang' => null,
            'lang' => 'en',
            'value' => null,
            'sourceLanguage' => 'en',
        ];
    }
}
