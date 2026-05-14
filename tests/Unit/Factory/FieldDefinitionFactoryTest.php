<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Factory;

use Basilicom\DataQualityBundle\Definition\DefinitionInterface;
use Basilicom\DataQualityBundle\Definition\LocalizedAwareDefinition;
use Basilicom\DataQualityBundle\Definition\NotEmptyDefinition;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Basilicom\DataQualityBundle\DefinitionsCollection\Factory\FieldDefinitionFactory;
use Basilicom\DataQualityBundle\Registry\RuleRegistry;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Fieldcollection\Data\AbstractData;

final class FieldDefinitionFactoryTest extends TestCase
{
    public function test_legacy_fqcn_falls_back_via_class_exists_with_deprecation(): void
    {
        $registry = new RuleRegistry();
        $factory = new FieldDefinitionFactory($registry);

        $fieldDef = $this->makeFieldDef('someField', NotEmptyDefinition::class);

        $deprecationFired = false;
        set_error_handler(static function (int $errno, string $errstr) use (&$deprecationFired): bool {
            if ($errno === E_USER_DEPRECATED) {
                $deprecationFired = true;
            }
            return true;
        }, E_USER_DEPRECATED);

        try {
            $result = $factory->get($fieldDef);
        } finally {
            restore_error_handler();
        }

        self::assertTrue($deprecationFired, 'E_USER_DEPRECATED was not fired for class_exists() fallback');
        self::assertInstanceOf(NotEmptyDefinition::class, $result->getConditionClass());
    }

    public function test_unknown_rule_throws_runtime_exception_with_field_name(): void
    {
        $registry = new RuleRegistry();
        $factory = new FieldDefinitionFactory($registry);

        $fieldDef = $this->makeFieldDef('myField', 'Not Emty');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not Emty');
        $this->expectExceptionMessage('myField');

        $factory->get($fieldDef);
    }

    public function test_class_exists_but_wrong_interface_throws(): void
    {
        $registry = new RuleRegistry();
        $factory = new FieldDefinitionFactory($registry);

        $fieldDef = $this->makeFieldDef('someField', \stdClass::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stdClass');
        $this->expectExceptionMessage('someField');
        $this->expectExceptionMessage(DefinitionInterface::class);

        $factory->get($fieldDef);
    }

    public function test_null_condition_throws_runtime_exception_with_field_name(): void
    {
        $registry = new RuleRegistry();
        $factory = new FieldDefinitionFactory($registry);

        $fieldDef = $this->makeFieldDef('aField', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('aField');

        $factory->get($fieldDef);
    }

    public function test_legacy_path_shapes_continue_to_parse(): void
    {
        $registry = new RuleRegistry();
        $registry->register('Not Empty', new NotEmptyDefinition());
        $factory = new FieldDefinitionFactory($registry);

        $simple = $factory->get($this->makeFieldDef('name', 'Not Empty'));
        self::assertSame('name', $simple->getFieldName());
        self::assertNull($simple->getLanguage());

        $localized = $factory->get($this->makeFieldDef('name###de', 'Not Empty'));
        self::assertSame('name', $localized->getFieldName());
        self::assertSame('de', $localized->getLanguage());

        $titled = $factory->get($this->makeFieldDef('name@@@Display Title###de', 'Not Empty'));
        self::assertSame('name', $titled->getFieldName());
        self::assertSame('Display Title', $titled->getTitle());
        self::assertSame('de', $titled->getLanguage());

        $allLangs = $factory->get($this->makeFieldDef('name###All', 'Not Empty'));
        self::assertSame('name', $allLangs->getFieldName());
        self::assertSame('All', $allLangs->getLanguage());
    }

    public function test_dotted_path_requires_localized_aware_rule(): void
    {
        $registry = new RuleRegistry();
        $registry->register('Not Empty', new NotEmptyDefinition());
        $factory = new FieldDefinitionFactory($registry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pricing.salePrice');
        $this->expectExceptionMessage(LocalizedAwareDefinition::class);

        $factory->get($this->makeFieldDef('pricing.salePrice###de', 'Not Empty'));
    }

    public function test_bracket_iterator_path_requires_localized_aware_rule(): void
    {
        $registry = new RuleRegistry();
        $registry->register('Not Empty', new NotEmptyDefinition());
        $factory = new FieldDefinitionFactory($registry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sections[].title');
        $this->expectExceptionMessage(LocalizedAwareDefinition::class);

        $factory->get($this->makeFieldDef('sections[].title###All', 'Not Empty'));
    }

    public function test_dotted_path_accepted_when_rule_implements_marker(): void
    {
        $registry = new RuleRegistry();
        $registry->register('Marker Rule', new MarkedAlwaysTrueRule());
        $factory = new FieldDefinitionFactory($registry);

        $parsed = $factory->get($this->makeFieldDef('pricing.salePrice###de', 'Marker Rule'));
        self::assertSame('pricing.salePrice', $parsed->getFieldName());
        self::assertSame('de', $parsed->getLanguage());
    }

    public function test_bracket_iterator_path_accepted_when_rule_implements_marker(): void
    {
        $registry = new RuleRegistry();
        $registry->register('Marker Rule', new MarkedAlwaysTrueRule());
        $factory = new FieldDefinitionFactory($registry);

        $parsed = $factory->get($this->makeFieldDef('sections[].title###All', 'Marker Rule'));
        self::assertSame('sections[].title', $parsed->getFieldName());
        self::assertSame('All', $parsed->getLanguage());
    }

    public function test_bare_container_shape_stays_in_legacy_lane(): void
    {
        $registry = new RuleRegistry();
        $registry->register('Not Empty', new NotEmptyDefinition());
        $factory = new FieldDefinitionFactory($registry);

        $parsed = $factory->get($this->makeFieldDef('sections###de', 'Not Empty'));
        self::assertSame('sections', $parsed->getFieldName());
        self::assertSame('de', $parsed->getLanguage());
    }

    private function makeFieldDef(string $fieldName, ?string $condition): object
    {
        return new class($fieldName, $condition) extends AbstractData {
            public function __construct(
                private readonly string $fieldNameValue,
                private readonly ?string $conditionValue,
            ) {
            }

            public function getField(): ?string
            {
                return $this->fieldNameValue;
            }

            public function getCondition(): ?string
            {
                return $this->conditionValue;
            }

            public function getWeight(): ?float
            {
                return null;
            }

            public function getParameters(): ?string
            {
                return null;
            }
        };
    }
}

final class MarkedAlwaysTrueRule implements DefinitionInterface, LocalizedAwareDefinition
{
    public function validate($content, Data $fieldDefinition, array $parameters, RuleContext $context): bool
    {
        return true;
    }

    public function getNecessaryParameterCount(): int
    {
        return 0;
    }
}
