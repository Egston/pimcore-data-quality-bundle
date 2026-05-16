<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Provider;

use Basilicom\DataQualityBundle\Definition\DefinitionInterface;
use Basilicom\DataQualityBundle\Definition\DependentDefinition;
use Basilicom\DataQualityBundle\Definition\LocalizedAwareDefinition;
use Basilicom\DataQualityBundle\Definition\NotEmptyDefinition;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Basilicom\DataQualityBundle\DefinitionsCollection\Factory\FieldDefinitionFactory;
use Basilicom\DataQualityBundle\DefinitionsCollection\FieldDefinition;
use Basilicom\DataQualityBundle\Provider\DataQualityProvider;
use Basilicom\DataQualityBundle\Resolver\FieldPathResolverInterface;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\DataQualityConfig;

/**
 * Pins the post-dispatch override path: a `DependentDefinition` rule
 * reporting `skipFromScore() === true` from its most recent
 * `validate()` must surface as `applied=false, valid=true,
 * validFields=[]` in the field-view-model row. This composes with the
 * existing N/A scoring math (`setDataQualityPercent` returns `null`
 * when total weight is zero) and threads SQL NULL down to
 * `writeFieldDirect`.
 *
 * Tested via reflection on a private seam because full
 * `calculateDataQuality()` orchestration needs kernel-bound
 * `Tool::getDefaultLanguage()` and a real class registry.
 */
final class DependentDefinitionSkipFromScoreTest extends TestCase
{
    public function test_skip_from_score_true_overrides_to_applied_false(): void
    {
        $rule = $this->fakeDependentRule(skip: true);
        $fieldDef = $this->fieldDefForRule($rule);

        [$valid, $validFields, $applied] = $this->invokeOverride($fieldDef, apply: true, valid: false, validFields: []);

        self::assertTrue($valid, 'applied=false N/A invariant requires valid=true');
        self::assertSame([], $validFields);
        self::assertFalse($applied, 'skipFromScore=true must demote applied to false');
    }

    public function test_skip_from_score_false_passes_through(): void
    {
        $rule = $this->fakeDependentRule(skip: false);
        $fieldDef = $this->fieldDefForRule($rule);

        [$valid, $validFields, $applied] = $this->invokeOverride($fieldDef, apply: true, valid: true, validFields: ['en' => true]);

        self::assertTrue($valid);
        self::assertSame(['en' => true], $validFields);
        self::assertTrue($applied);
    }

    public function test_gate_already_excluded_does_not_invoke_skip_check(): void
    {
        $rule = new class implements DefinitionInterface, LocalizedAwareDefinition, DependentDefinition {
            public int $skipCalls = 0;

            public function validate($content, Data $fieldDefinition, array $parameters, RuleContext $context): bool
            {
                return false;
            }

            public function getNecessaryParameterCount(): int
            {
                return 0;
            }

            public function dependsOnColumns(array $parameters, DataQualityConfig $config): array
            {
                return [];
            }

            public function skipFromScore(): bool
            {
                $this->skipCalls++;

                return true;
            }
        };
        $fieldDef = $this->fieldDefForRule($rule);

        [, , $applied] = $this->invokeOverride($fieldDef, apply: false, valid: true, validFields: []);

        self::assertFalse($applied, 'gate-excluded rule stays applied=false regardless of skipFromScore');
        self::assertSame(0, $rule->skipCalls, 'skipFromScore must not be queried when the gate already excluded');
    }

    public function test_non_dependent_rule_passes_through_unchanged(): void
    {
        $rule = new NotEmptyDefinition();
        $fieldDef = $this->fieldDefForRule($rule);

        [$valid, $validFields, $applied] = $this->invokeOverride($fieldDef, apply: true, valid: false, validFields: ['de' => false]);

        self::assertFalse($valid);
        self::assertSame(['de' => false], $validFields);
        self::assertTrue($applied);
    }

    private function fakeDependentRule(bool $skip): DefinitionInterface
    {
        return new class ($skip) implements DefinitionInterface, LocalizedAwareDefinition, DependentDefinition {
            public function __construct(private readonly bool $skip) {}

            public function validate($content, Data $fieldDefinition, array $parameters, RuleContext $context): bool
            {
                return !$this->skip;
            }

            public function getNecessaryParameterCount(): int
            {
                return 0;
            }

            public function dependsOnColumns(array $parameters, DataQualityConfig $config): array
            {
                return [];
            }

            public function skipFromScore(): bool
            {
                return $this->skip;
            }
        };
    }

    private function fieldDefForRule(DefinitionInterface $rule): FieldDefinition
    {
        return new FieldDefinition($rule, 'fillScore', 'fillScore', 1, [], null, null);
    }

    /**
     * @param array<string, bool> $validFields
     *
     * @return array{0: bool, 1: array<string, bool>, 2: bool}
     */
    private function invokeOverride(FieldDefinition $fieldDef, bool $apply, bool $valid, array $validFields): array
    {
        $provider = new DataQualityProvider(
            (new \ReflectionClass(FieldDefinitionFactory::class))->newInstanceWithoutConstructor(),
            new class implements FieldPathResolverInterface {
                public function resolve(Concrete $object, string $path, string $language): array
                {
                    return [];
                }
            },
        );

        $reflection = new \ReflectionClass(DataQualityProvider::class);
        $method = $reflection->getMethod('applySkipFromScoreOverride');
        $method->setAccessible(true);

        return $method->invoke($provider, $fieldDef, $apply, $valid, $validFields);
    }
}
