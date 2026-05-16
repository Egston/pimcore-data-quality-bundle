<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Provider;

use Basilicom\DataQualityBundle\Definition\WeightedColumnBlend;
use Basilicom\DataQualityBundle\DefinitionsCollection\Factory\FieldDefinitionFactory;
use Basilicom\DataQualityBundle\DefinitionsCollection\FieldDefinition;
use Basilicom\DataQualityBundle\Provider\DataQualityProvider;
use Basilicom\DataQualityBundle\Resolver\FieldPathResolverInterface;
use Basilicom\DataQualityBundle\Tests\Helper\RuleContextBuilder;
use Basilicom\DataQualityBundle\Tests\Stubs\PercentRecordingDataObject;
use Basilicom\DataQualityBundle\View\DataQualityGroupViewModel;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;

/**
 * Composes the all-NULL blend chain end-to-end (kernel-free):
 *   validate() → skipFromScore=true
 *   → applySkipFromScoreOverride() → applied=false
 *   → setDataQualityPercent() → null
 *
 * A future refactor that silently breaks any one leg without breaking the
 * others would still be caught here.
 */
final class AllNullBlendChainTest extends TestCase
{
    public function test_all_null_blend_produces_null_percent(): void
    {
        $rule = new WeightedColumnBlend();

        // Object with all-NULL score getters.
        $object = new class extends Concrete {
            public function __construct() {}

            public function getFillScore(): mixed
            {
                return null;
            }

            public function getAuthenticityScore(): mixed
            {
                return null;
            }
        };

        $context = RuleContextBuilder::empty()->object($object)->build();
        $fieldStub = $this->createStub(Data::class);

        // Leg 1: validate() with all-NULL → skipFromScore true.
        $valid = $rule->validate(null, $fieldStub, [
            'columns' => 'fillScore:0.5;authenticityScore:0.5',
        ], $context);

        self::assertFalse($valid);
        self::assertTrue($rule->skipFromScore(), 'all-NULL must set skipFromScore=true');

        // Leg 2: applySkipFromScoreOverride() demotes to applied=false.
        $fieldDef = new FieldDefinition($rule, 'headline', 'Headline Score', 1, [], null, null);
        $provider = $this->makeProvider();

        [$outValid, $outValidFields, $outApplied] = $this->callOverride($provider, $fieldDef, true, false, []);

        self::assertTrue($outValid, 'N/A invariant: valid=true when applied=false');
        self::assertSame([], $outValidFields);
        self::assertFalse($outApplied, 'applied must be false after skipFromScore override');

        // Leg 3: setDataQualityPercent() with the resulting group → null.
        $dataObject = new PercentRecordingDataObject();
        $group = new DataQualityGroupViewModel('default', [
            new \Basilicom\DataQualityBundle\View\DataQualityFieldViewModel(
                'headline',
                1,
                $outValid,
                null,
                $outValidFields,
                $outApplied,
            ),
        ]);

        $percent = $this->callSetPercent($provider, $dataObject, [$group]);

        self::assertNull($percent, 'all-NULL blend chain must produce null percent (SQL NULL path)');
        self::assertNull($dataObject->setterValue, 'setter must receive null');
    }

    private function makeProvider(): DataQualityProvider
    {
        return new DataQualityProvider(
            (new \ReflectionClass(FieldDefinitionFactory::class))->newInstanceWithoutConstructor(),
            new class implements FieldPathResolverInterface {
                public function resolve(Concrete $object, string $path, string $language): array
                {
                    return [];
                }
            },
        );
    }

    /**
     * @param array<string, bool> $validFields
     *
     * @return array{0: bool, 1: array<string, bool>, 2: bool}
     */
    private function callOverride(
        DataQualityProvider $provider,
        FieldDefinition $fieldDef,
        bool $apply,
        bool $valid,
        array $validFields,
    ): array {
        $method = (new \ReflectionClass(DataQualityProvider::class))->getMethod('applySkipFromScoreOverride');
        $method->setAccessible(true);

        return $method->invoke($provider, $fieldDef, $apply, $valid, $validFields);
    }

    /**
     * @param DataQualityGroupViewModel[] $groups
     */
    private function callSetPercent(
        DataQualityProvider $provider,
        PercentRecordingDataObject $object,
        array $groups,
    ): ?int {
        $method = (new \ReflectionClass(DataQualityProvider::class))->getMethod('setDataQualityPercent');
        $method->setAccessible(true);

        return $method->invoke($provider, $object, $groups, 'dataQualityScore', false, false);
    }
}
