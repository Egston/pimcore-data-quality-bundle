<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition;

use Basilicom\DataQualityBundle\Definition\DefinitionException;
use Basilicom\DataQualityBundle\Definition\DependentDefinition;
use Basilicom\DataQualityBundle\Definition\LocalizedAwareDefinition;
use Basilicom\DataQualityBundle\Definition\WeightedColumnBlend;
use Basilicom\DataQualityBundle\Tests\Helper\RuleContextBuilder;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\DataQualityConfig;

final class WeightedColumnBlendTest extends TestCase
{
    public function test_implements_localized_aware_and_dependent_markers(): void
    {
        $rule = new WeightedColumnBlend();

        self::assertInstanceOf(LocalizedAwareDefinition::class, $rule);
        self::assertInstanceOf(DependentDefinition::class, $rule);
    }

    public function test_three_inputs_all_full_passes_when_blended_score_is_100(): void
    {
        $rule = new WeightedColumnBlend();
        $object = $this->objectWithScores([
            'fillScore' => 100.0,
            'authenticityScore' => 100.0,
            'verifiedScore' => 100.0,
        ]);
        $context = RuleContextBuilder::empty()->object($object)->build();

        $valid = $rule->validate(null, $this->stubField(), [
            'columns' => 'fillScore:0.4;authenticityScore:0.3;verifiedScore:0.3',
        ], $context);

        self::assertTrue($valid);
        self::assertFalse($rule->skipFromScore());
    }

    public function test_three_inputs_partial_fails(): void
    {
        $rule = new WeightedColumnBlend();
        $object = $this->objectWithScores([
            'fillScore' => 80.0,
            'authenticityScore' => 100.0,
            'verifiedScore' => 100.0,
        ]);
        $context = RuleContextBuilder::empty()->object($object)->build();

        $valid = $rule->validate(null, $this->stubField(), [
            'columns' => 'fillScore:0.4;authenticityScore:0.3;verifiedScore:0.3',
        ], $context);

        self::assertFalse($valid, 'blended 92.0 < pass threshold 100');
        self::assertFalse($rule->skipFromScore());
    }

    public function test_one_null_input_is_skipped_and_remaining_weights_renormalised(): void
    {
        // verified is NULL → blend on fill (0.4) + authenticity (0.3) renormalised to 0.571 + 0.429.
        // Both surviving inputs at 100 → blended 100 → passes.
        $rule = new WeightedColumnBlend();
        $object = $this->objectWithScores([
            'fillScore' => 100.0,
            'authenticityScore' => 100.0,
            'verifiedScore' => null,
        ]);
        $context = RuleContextBuilder::empty()->object($object)->build();

        $valid = $rule->validate(null, $this->stubField(), [
            'columns' => 'fillScore:0.4;authenticityScore:0.3;verifiedScore:0.3',
        ], $context);

        self::assertTrue($valid, 'one NULL input is dropped and weights renormalise — surviving inputs at 100 → blend 100');
        self::assertFalse($rule->skipFromScore());
    }

    public function test_one_null_input_drags_renormalised_blend_below_threshold_when_others_partial(): void
    {
        $rule = new WeightedColumnBlend();
        $object = $this->objectWithScores([
            'fillScore' => 50.0,
            'authenticityScore' => 100.0,
            'verifiedScore' => null,
        ]);
        $context = RuleContextBuilder::empty()->object($object)->build();

        $valid = $rule->validate(null, $this->stubField(), [
            'columns' => 'fillScore:0.4;authenticityScore:0.3;verifiedScore:0.3',
        ], $context);

        self::assertFalse($valid, 'blend of (50, 100) at renormalised (0.571, 0.429) ≈ 71.4 < 100');
        self::assertFalse($rule->skipFromScore());
    }

    public function test_all_null_inputs_returns_false_and_marks_skip_from_score(): void
    {
        $rule = new WeightedColumnBlend();
        $object = $this->objectWithScores([
            'fillScore' => null,
            'authenticityScore' => null,
            'verifiedScore' => null,
        ]);
        $context = RuleContextBuilder::empty()->object($object)->build();

        $valid = $rule->validate(null, $this->stubField(), [
            'columns' => 'fillScore:0.4;authenticityScore:0.3;verifiedScore:0.3',
        ], $context);

        self::assertFalse($valid, 'all-NULL → false (the N/A treatment threads via skipFromScore)');
        self::assertTrue($rule->skipFromScore(), 'all-NULL must signal N/A so the parent config writes SQL NULL');
    }

    public function test_skip_from_score_resets_to_false_when_validate_throws(): void
    {
        $rule = new WeightedColumnBlend();

        // Object A: all-NULL → sets skipFromScore true.
        $allNullObject = $this->objectWithScores(['fillScore' => null]);
        $rule->validate(null, $this->stubField(), [
            'columns' => 'fillScore:1.0',
        ], RuleContextBuilder::empty()->object($allNullObject)->build());
        self::assertTrue($rule->skipFromScore());

        // Object B: non-numeric value → validate() throws. skipFromScore must be false,
        // not inherited from object A's all-NULL run.
        $badObject = $this->objectWithScores(['fillScore' => 'not-a-number']);
        try {
            $rule->validate(null, $this->stubField(), [
                'columns' => 'fillScore:1.0',
            ], RuleContextBuilder::empty()->object($badObject)->build());
        } catch (DefinitionException) {
            // expected
        }
        self::assertFalse($rule->skipFromScore(), 'skipFromScore must be false after a throwing validate()');
    }

    public function test_skip_from_score_resets_between_validate_calls(): void
    {
        $rule = new WeightedColumnBlend();

        $allNullObject = $this->objectWithScores(['fillScore' => null]);
        $rule->validate(null, $this->stubField(), [
            'columns' => 'fillScore:1.0',
        ], RuleContextBuilder::empty()->object($allNullObject)->build());
        self::assertTrue($rule->skipFromScore());

        $someValueObject = $this->objectWithScores(['fillScore' => 100.0]);
        $rule->validate(null, $this->stubField(), [
            'columns' => 'fillScore:1.0',
        ], RuleContextBuilder::empty()->object($someValueObject)->build());
        self::assertFalse($rule->skipFromScore(), 'state must reset on the next validate call');
    }

    public function test_missing_column_getter_throws_definition_exception(): void
    {
        $rule = new WeightedColumnBlend();
        $object = $this->objectWithScores(['fillScore' => 100.0]);
        $context = RuleContextBuilder::empty()->object($object)->build();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('typoColumn');

        $rule->validate(null, $this->stubField(), [
            'columns' => 'typoColumn:1.0',
        ], $context);
    }

    public function test_non_numeric_column_value_throws_definition_exception(): void
    {
        $rule = new WeightedColumnBlend();
        $object = $this->objectWithScores(['fillScore' => 'not-a-number']);
        $context = RuleContextBuilder::empty()->object($object)->build();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('non-numeric');

        $rule->validate(null, $this->stubField(), [
            'columns' => 'fillScore:1.0',
        ], $context);
    }

    public function test_missing_columns_parameter_throws_definition_exception(): void
    {
        $rule = new WeightedColumnBlend();
        $context = RuleContextBuilder::empty()->object($this->objectWithScores([]))->build();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('columns');

        $rule->validate(null, $this->stubField(), [], $context);
    }

    public function test_empty_columns_parameter_throws_definition_exception(): void
    {
        $rule = new WeightedColumnBlend();
        $context = RuleContextBuilder::empty()->object($this->objectWithScores([]))->build();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('columns');

        $rule->validate(null, $this->stubField(), ['columns' => ''], $context);
    }

    public function test_malformed_entry_without_colon_throws_definition_exception(): void
    {
        $rule = new WeightedColumnBlend();
        $context = RuleContextBuilder::empty()->object($this->objectWithScores([]))->build();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('<column>:<weight>');

        $rule->validate(null, $this->stubField(), [
            'columns' => 'fillScore;authenticityScore:0.5',
        ], $context);
    }

    public function test_non_numeric_weight_throws_definition_exception(): void
    {
        $rule = new WeightedColumnBlend();
        $context = RuleContextBuilder::empty()->object($this->objectWithScores([]))->build();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('weight');

        $rule->validate(null, $this->stubField(), [
            'columns' => 'fillScore:not-a-number',
        ], $context);
    }

    public function test_negative_weight_throws_definition_exception(): void
    {
        $rule = new WeightedColumnBlend();
        $context = RuleContextBuilder::empty()->object($this->objectWithScores([]))->build();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('non-positive');

        $rule->validate(null, $this->stubField(), [
            'columns' => 'fillScore:-0.5',
        ], $context);
    }

    public function test_zero_weight_throws_definition_exception(): void
    {
        $rule = new WeightedColumnBlend();
        $context = RuleContextBuilder::empty()->object($this->objectWithScores([]))->build();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('non-positive');

        $rule->validate(null, $this->stubField(), [
            'columns' => 'fillScore:0',
        ], $context);
    }

    public function test_positional_shape_from_field_definition_factory_parses_correctly(): void
    {
        // FieldDefinitionFactory::parameterStringToArray() splits on ';' and returns a
        // positional list: ['columns=fillScore:0.4', 'authenticityScore:0.3', 'verifiedScore:0.3'].
        // extractColumnsValue() joins these with ';', strips the 'columns=' prefix,
        // and returns the same value string as the associative form would.
        $rule = new WeightedColumnBlend();
        $object = $this->objectWithScores([
            'fillScore' => 100.0,
            'authenticityScore' => 100.0,
            'verifiedScore' => 100.0,
        ]);
        $context = RuleContextBuilder::empty()->object($object)->build();

        $valid = $rule->validate(null, $this->stubField(), [
            'columns=fillScore:0.4',
            'authenticityScore:0.3',
            'verifiedScore:0.3',
        ], $context);

        self::assertTrue($valid, 'positional shape must parse identically to associative shape');
        self::assertFalse($rule->skipFromScore());
    }

    public function test_depends_on_columns_returns_class_and_column_pairs(): void
    {
        $rule = new WeightedColumnBlend();
        $config = (new \ReflectionClass(DataQualityConfig::class))->newInstanceWithoutConstructor();
        $config->setDataQualityClass('ResourceLibraryItem');

        $entries = $rule->dependsOnColumns([
            'columns' => 'fillScore:0.4;authenticityScore:0.3;verifiedScore:0.3',
        ], $config);

        self::assertSame([
            ['class' => 'ResourceLibraryItem', 'column' => 'fillScore'],
            ['class' => 'ResourceLibraryItem', 'column' => 'authenticityScore'],
            ['class' => 'ResourceLibraryItem', 'column' => 'verifiedScore'],
        ], $entries);
    }

    /**
     * The blend rule resolves columns via `method_exists(...)` which only
     * sees declared methods; magic `__call` is invisible to it. The
     * fixture therefore defines real `getFillScore` / `getAuthenticityScore`
     * / `getVerifiedScore` getters reading from a per-instance score map.
     *
     * @param array<string, mixed> $scores
     */
    private function objectWithScores(array $scores): Concrete
    {
        $object = new class extends Concrete {
            /** @var array<string, mixed> */
            public array $scores = [];

            public function __construct()
            {
                // Skip Concrete's constructor — touches the DI container.
            }

            public function getFillScore(): mixed
            {
                return $this->scores['fillScore'] ?? null;
            }

            public function getAuthenticityScore(): mixed
            {
                return $this->scores['authenticityScore'] ?? null;
            }

            public function getVerifiedScore(): mixed
            {
                return $this->scores['verifiedScore'] ?? null;
            }
        };
        $object->scores = $scores;

        return $object;
    }

    private function stubField(): Data
    {
        $stub = $this->createStub(Data::class);
        $stub->method('getName')->willReturn('dataQualityPercentage');

        return $stub;
    }
}
