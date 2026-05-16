<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Provider;

use Basilicom\DataQualityBundle\Definition\DefinitionInterface;
use Basilicom\DataQualityBundle\Definition\Expression;
use Basilicom\DataQualityBundle\Definition\LocalizedAwareDefinition;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Basilicom\DataQualityBundle\DefinitionsCollection\Factory\FieldDefinitionFactory;
use Basilicom\DataQualityBundle\DefinitionsCollection\FieldDefinition;
use Basilicom\DataQualityBundle\Provider\DataQualityProvider;
use Basilicom\DataQualityBundle\Registry\LanguageFlagsProviderRegistry;
use Basilicom\DataQualityBundle\Resolver\FieldPathResolverInterface;
use Basilicom\DataQualityBundle\Service\ExpressionEvaluator;
use Basilicom\DataQualityBundle\Service\SafeFunctionProvider;
use Basilicom\DataQualityBundle\Tests\Helper\RuleContextBuilder;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\DataQualityConfig;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * The two dispatch arms are tested simultaneously because regressing
 * one without the other would still pass.
 */
final class DataQualityProviderLocalizedAwareDispatchTest extends TestCase
{
    public function test_marker_rule_on_localized_field_validates_once_with_full_context(): void
    {
        $rule = new class implements DefinitionInterface, LocalizedAwareDefinition {
            public int $calls = 0;

            /** @var array<int, mixed> */
            public array $contents = [];

            public function validate($content, Data $fieldDefinition, array $parameters, RuleContext $context): bool
            {
                $this->calls++;
                $this->contents[] = $content;

                return true;
            }

            public function getNecessaryParameterCount(): int
            {
                return 0;
            }
        };

        $context = RuleContextBuilder::withValues([
            'en' => 'Hello',
            'de' => 'Hallo',
            'fr' => 'Bonjour',
        ])->scoredLanguages(['en', 'de', 'fr'])->build();

        [$valid, $validFields] = $this->invokeDispatchRule(
            $rule,
            isLocalizedField: true,
            apply: true,
            context: $context,
        );

        self::assertTrue($valid);
        self::assertSame([], $validFields, 'marker dispatch does not emit per-language validity map');
        self::assertSame(1, $rule->calls, 'marker rule must be validated once, not once-per-language');
        self::assertNull($rule->contents[0], 'marker dispatch passes null content — rule reads via context');
    }

    public function test_throwing_marker_rule_returns_false_and_does_not_propagate(): void
    {
        $rule = new class implements DefinitionInterface, LocalizedAwareDefinition {
            public function validate($content, Data $fieldDefinition, array $parameters, RuleContext $context): bool
            {
                throw new \LogicException('source language excluded from scored set');
            }

            public function getNecessaryParameterCount(): int
            {
                return 0;
            }
        };

        $context = RuleContextBuilder::withValues(['en' => 'Hello'])->scoredLanguages(['en'])->build();

        [$valid, $validFields] = $this->invokeDispatchRule(
            $rule,
            isLocalizedField: true,
            apply: true,
            context: $context,
        );

        self::assertFalse($valid, 'throwing marker rule must degrade to false rather than propagating');
        self::assertSame([], $validFields);
    }

    public function test_throwing_marker_rule_with_type_error_returns_false_and_does_not_propagate(): void
    {
        $rule = new class implements DefinitionInterface, LocalizedAwareDefinition {
            public function validate($content, Data $fieldDefinition, array $parameters, RuleContext $context): bool
            {
                throw new \TypeError('type mismatch in rule');
            }

            public function getNecessaryParameterCount(): int
            {
                return 0;
            }
        };

        $context = RuleContextBuilder::withValues(['en' => 'Hello'])->scoredLanguages(['en'])->build();

        [$valid, $validFields] = $this->invokeDispatchRule(
            $rule,
            isLocalizedField: true,
            apply: true,
            context: $context,
        );

        self::assertFalse($valid, 'TypeError from marker rule must degrade to false rather than propagating');
        self::assertSame([], $validFields);
    }

    public function test_gate_excluded_rule_returns_na_tuple(): void
    {
        $rule = new class implements DefinitionInterface, LocalizedAwareDefinition {
            public int $calls = 0;

            public function validate($content, Data $fieldDefinition, array $parameters, RuleContext $context): bool
            {
                $this->calls++;

                return false;
            }

            public function getNecessaryParameterCount(): int
            {
                return 0;
            }
        };

        $context = RuleContextBuilder::withValues(['en' => 'Hello'])->scoredLanguages(['en'])->build();

        [$valid, $validFields] = $this->invokeDispatchRule(
            $rule,
            isLocalizedField: true,
            apply: false,
            context: $context,
        );

        self::assertTrue($valid, 'gate-excluded rule returns [true, []] N/A tuple');
        self::assertSame([], $validFields);
        self::assertSame(0, $rule->calls, 'rule must not be invoked when gate excluded');
    }

    public function test_marker_rule_on_objectbricks_field_routes_to_validateObjectBricks(): void
    {
        $rule = new class implements DefinitionInterface, LocalizedAwareDefinition {
            public int $calls = 0;

            public function validate($content, Data $fieldDefinition, array $parameters, RuleContext $context): bool
            {
                $this->calls++;

                return true;
            }

            public function getNecessaryParameterCount(): int
            {
                return 0;
            }
        };

        $context = RuleContextBuilder::withValues(['en' => 'Hello'])->scoredLanguages(['en'])->build();

        $provider = new DataQualityProvider(
            (new \ReflectionClass(FieldDefinitionFactory::class))->newInstanceWithoutConstructor(),
            $this->emptyResolver(),
        );

        $fieldDef = new FieldDefinition($rule, 'name', 'name', 1, [], null, null);

        $classFieldDef = $this->createStub(Data::class);
        $classFieldDef->method('getFieldtype')->willReturn('objectbricks');
        $classFieldDef->method('getName')->willReturn('name');

        $brickContainer = new class {
            public int $itemCalls = 0;

            public function getItems(): array
            {
                $this->itemCalls++;

                return [];
            }
        };

        $object = new class ($brickContainer) extends Concrete {
            public function __construct(private readonly object $bricks) {}

            public function getName(): object
            {
                return $this->bricks;
            }
        };

        $config = (new \ReflectionClass(DataQualityConfig::class))->newInstanceWithoutConstructor();

        $reflection = new \ReflectionClass(DataQualityProvider::class);
        $method = $reflection->getMethod('dispatchRule');
        $method->setAccessible(true);

        [$valid, $validFields] = $method->invoke(
            $provider,
            $object,
            'getName',
            $fieldDef,
            $classFieldDef,
            true,
            true,
            $context,
            $config,
            0,
        );

        self::assertTrue($valid);
        self::assertSame([], $validFields, 'objectbricks with zero items returns empty validFields');
        self::assertSame(0, $rule->calls, 'marker rule must not be called directly for objectbricks — brick fork takes the path');
        self::assertSame(1, $brickContainer->itemCalls, 'objectbricks fork must call getItems() on the brick container');
    }

    public function test_expression_rule_routes_through_marker_dispatch_arm(): void
    {
        $el = new ExpressionLanguage(null, [new SafeFunctionProvider()]);
        $rule = new Expression(new ExpressionEvaluator($el), new LanguageFlagsProviderRegistry());

        $context = RuleContextBuilder::withValues([
            'en' => 'Hello',
            'de' => 'Hallo',
        ])->scoredLanguages(['en', 'de'])->build();

        [$valid, $validFields] = $this->invokeDispatchRule(
            $rule,
            isLocalizedField: true,
            apply: true,
            context: $context,
            getterValueByLang: ['en' => 'Hello', 'de' => 'Hallo'],
            parameters: ['expression' => 'value != null'],
        );

        self::assertTrue($valid);
        self::assertSame([], $validFields, 'marker dispatch arm must not emit per-language validity map for Expression');
    }

    public function test_non_marker_rule_on_localized_field_iterates_per_language(): void
    {
        $rule = new class implements DefinitionInterface {
            public int $calls = 0;

            /** @var array<int, mixed> */
            public array $contents = [];

            public function validate($content, Data $fieldDefinition, array $parameters, RuleContext $context): bool
            {
                $this->calls++;
                $this->contents[] = $content;

                return true;
            }

            public function getNecessaryParameterCount(): int
            {
                return 0;
            }
        };

        $context = RuleContextBuilder::withValues([
            'en' => 'Hello',
            'de' => 'Hallo',
            'fr' => 'Bonjour',
        ])->scoredLanguages(['en', 'de', 'fr'])->build();

        [$valid, $validFields] = $this->invokeDispatchRule(
            $rule,
            isLocalizedField: true,
            apply: true,
            context: $context,
            getterValueByLang: ['en' => 'Hello', 'de' => 'Hallo', 'fr' => 'Bonjour'],
        );

        self::assertTrue($valid);
        self::assertSame(3, $rule->calls, 'non-marker rule must be validated once per scored language');
        self::assertSame(['en' => true, 'de' => true, 'fr' => true], $validFields);
    }

    /**
     * @param array<string, mixed> $getterValueByLang
     * @param array<string, mixed> $parameters
     *
     * @return array{0: bool, 1: array<string, bool>}
     */
    private function invokeDispatchRule(
        DefinitionInterface $rule,
        bool $isLocalizedField,
        bool $apply,
        RuleContext $context,
        array $getterValueByLang = [],
        array $parameters = [],
    ): array {
        $provider = new DataQualityProvider(
            (new \ReflectionClass(FieldDefinitionFactory::class))->newInstanceWithoutConstructor(),
            $this->emptyResolver(),
        );

        $fieldDef = new FieldDefinition(
            $rule,
            'name',
            'name',
            1,
            $parameters,
            null,
            null,
        );

        $classFieldDef = $this->createStub(Data::class);
        $classFieldDef->method('getFieldtype')->willReturn('input');
        $classFieldDef->method('getName')->willReturn('name');

        $object = new class extends Concrete {
            /** @var array<string, mixed> */
            public array $values = [];

            public function __construct()
            {
                // Skip Concrete's constructor — touches the DI container.
            }

            public function getName(?string $language = null): mixed
            {
                if ($language === null) {
                    return reset($this->values) ?: null;
                }

                return $this->values[$language] ?? null;
            }
        };
        $object->values = $getterValueByLang;

        $reflection = new \ReflectionClass(DataQualityProvider::class);
        $method = $reflection->getMethod('dispatchRule');
        $method->setAccessible(true);

        $config = (new \ReflectionClass(DataQualityConfig::class))->newInstanceWithoutConstructor();

        return $method->invoke(
            $provider,
            $object,
            'getName',
            $fieldDef,
            $classFieldDef,
            $isLocalizedField,
            $apply,
            $context,
            $config,
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
