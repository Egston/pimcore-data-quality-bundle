<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Provider;

use Basilicom\DataQualityBundle\Definition\GateFactory;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Basilicom\DataQualityBundle\DefinitionsCollection\Factory\FieldDefinitionFactory;
use Basilicom\DataQualityBundle\DefinitionsCollection\FieldDefinition;
use Basilicom\DataQualityBundle\Provider\DataQualityProvider;
use Basilicom\DataQualityBundle\Resolver\FieldPathResolverInterface;
use Basilicom\DataQualityBundle\Tests\Stubs\PercentRecordingDataObject;
use Basilicom\DataQualityBundle\View\DataQualityFieldViewModel;
use Basilicom\DataQualityBundle\View\DataQualityGroupViewModel;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\DataQualityConfig;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Pins the N/A scoring math invariant: a rule whose gate returns
 * `false` contributes zero to both numerator and denominator. The
 * `setDataQualityPercent()` invariant is the load-bearing one — exercise
 * it through reflection with view models that already carry the
 * gate-evaluated `applied` flag. Full orchestration through
 * `calculateDataQuality()` requires a booted kernel (real class
 * definitions, real rule registry); covered by integration tests.
 *
 * `evaluateGate()` is the seam that converts a gate-source string to a
 * pass/fail; tested directly via reflection.
 */
final class CalculateDataQualityGatedRuleTest extends TestCase
{
    public function test_gated_out_rule_contributes_zero_to_numerator_and_denominator(): void
    {
        $object = new PercentRecordingDataObject();
        $group = new DataQualityGroupViewModel('default', [
            new DataQualityFieldViewModel('valid-rule', 1, true, null, null, true),
            new DataQualityFieldViewModel('gated-out', 1, true, null, null, false),
        ]);

        $value = $this->invokeSetDataQualityPercent($object, [$group]);

        self::assertSame(100, $value, 'gated-out invalid rule must not pull average down');
        self::assertSame(100.0, $object->setterValue);
    }

    public function test_all_rules_gated_out_returns_null_percent(): void
    {
        $object = new PercentRecordingDataObject();
        $group = new DataQualityGroupViewModel('default', [
            new DataQualityFieldViewModel('rule-a', 1, true, null, null, false),
            new DataQualityFieldViewModel('rule-b', 1, true, null, null, false),
        ]);

        $value = $this->invokeSetDataQualityPercent($object, [$group]);

        self::assertNull($value, 'all rules N/A => no scoring rows => percentage is null');
        self::assertNull($object->setterValue);
    }

    public function test_mix_of_three_rules_uses_only_applied_ones(): void
    {
        $object = new PercentRecordingDataObject();
        $group = new DataQualityGroupViewModel('default', [
            new DataQualityFieldViewModel('rule-a-gated-out', 1, true, null, null, false),
            new DataQualityFieldViewModel('rule-b-valid', 1, true, null, null, true),
            new DataQualityFieldViewModel('rule-c-invalid', 1, false, null, null, true),
        ]);

        $value = $this->invokeSetDataQualityPercent($object, [$group]);

        self::assertSame(50, $value, 'gated-out rule excluded; remaining 1 valid of 2 -> 50%');
    }

    public function test_evaluate_gate_returns_true_for_default_always_apply(): void
    {
        $provider = $this->makeProviderWithGateFactory();
        $fieldDef = new FieldDefinition(
            (new \ReflectionClass(\Basilicom\DataQualityBundle\Definition\NotEmptyDefinition::class))
                ->newInstanceWithoutConstructor(),
            'name',
            'name',
            1,
            [],
            null,
            null,
        );

        $apply = $this->invokeEvaluateGate($provider, $fieldDef);

        self::assertTrue($apply, 'null gate string defaults to AlwaysApplyGate');
    }

    public function test_evaluate_gate_returns_false_when_gate_throws(): void
    {
        $provider = $this->makeProviderWithGateFactory();
        $fieldDef = new FieldDefinition(
            (new \ReflectionClass(\Basilicom\DataQualityBundle\Definition\NotEmptyDefinition::class))
                ->newInstanceWithoutConstructor(),
            'name',
            'name',
            1,
            [],
            null,
            'foobar_unknown',
        );

        $apply = $this->invokeEvaluateGate($provider, $fieldDef);

        self::assertFalse($apply, 'gate-factory throw must be caught and treated as N/A');
    }

    public function test_evaluate_gate_returns_true_when_factory_not_injected(): void
    {
        $provider = new DataQualityProvider(
            (new \ReflectionClass(FieldDefinitionFactory::class))->newInstanceWithoutConstructor(),
            $this->emptyResolver(),
            null,
        );
        $fieldDef = new FieldDefinition(
            (new \ReflectionClass(\Basilicom\DataQualityBundle\Definition\NotEmptyDefinition::class))
                ->newInstanceWithoutConstructor(),
            'name',
            'name',
            1,
            [],
            null,
            'source_filled',
        );

        $apply = $this->invokeEvaluateGate($provider, $fieldDef);

        self::assertTrue($apply, 'absent factory degrades to "always apply" so existing callers stay green');
    }

    /**
     * @param DataQualityGroupViewModel[] $groups
     */
    private function invokeSetDataQualityPercent(
        PercentRecordingDataObject $object,
        array $groups,
    ): ?int {
        $provider = new DataQualityProvider(
            (new \ReflectionClass(FieldDefinitionFactory::class))->newInstanceWithoutConstructor(),
            $this->emptyResolver(),
        );

        $reflection = new \ReflectionClass(DataQualityProvider::class);
        $method = $reflection->getMethod('setDataQualityPercent');
        $method->setAccessible(true);

        return $method->invoke($provider, $object, $groups, 'dataQualityScore', false, false);
    }

    private function makeProviderWithGateFactory(): DataQualityProvider
    {
        return new DataQualityProvider(
            (new \ReflectionClass(FieldDefinitionFactory::class))->newInstanceWithoutConstructor(),
            $this->emptyResolver(),
            new GateFactory(new ExpressionLanguage()),
        );
    }

    private function invokeEvaluateGate(DataQualityProvider $provider, FieldDefinition $fieldDef): bool
    {
        $reflection = new \ReflectionClass(DataQualityProvider::class);
        $method = $reflection->getMethod('evaluateGate');
        $method->setAccessible(true);

        return (bool) $method->invoke(
            $provider,
            $fieldDef,
            $this->createStub(Data::class),
            (new \ReflectionClass(RuleContext::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(DataQualityConfig::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(Concrete::class))->newInstanceWithoutConstructor(),
            0,
        );
    }

    private function emptyResolver(): FieldPathResolverInterface
    {
        return new class implements FieldPathResolverInterface {
            public function resolve(Concrete $object, string $path, string $language): array
            {
                return [];
            }
        };
    }
}
