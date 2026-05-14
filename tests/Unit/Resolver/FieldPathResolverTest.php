<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Resolver;

use Basilicom\DataQualityBundle\Resolver\FieldPathResolver;
use Basilicom\DataQualityBundle\Resolver\InvalidFieldPathException;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data\Fieldcollections;
use Pimcore\Model\DataObject\ClassDefinition\Data\Input;
use Pimcore\Model\DataObject\ClassDefinition\Data\Localizedfields;
use Pimcore\Model\DataObject\ClassDefinition\Data\Objectbricks;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Fieldcollection\Data\AbstractData as FieldcollectionItem;
use Pimcore\Model\DataObject\Objectbrick;
use Pimcore\Model\DataObject\Objectbrick\Data\AbstractData as ObjectbrickItem;

final class FieldPathResolverTest extends TestCase
{
    public function test_simple_top_level_non_localized_field_resolves(): void
    {
        $object = $this->buildObjectWithSku('SKU-123');

        $leaves = (new FieldPathResolver())->resolve($object, 'sku', 'de');

        self::assertCount(1, $leaves);
        self::assertSame('sku', $leaves[0]->leafPath);
        self::assertSame('de', $leaves[0]->language);
        self::assertSame('SKU-123', $leaves[0]->value);
    }

    public function test_top_level_localized_field_resolves_with_language_arg(): void
    {
        $object = $this->buildObjectWithLocalizedName(['de' => 'Hallo', 'en' => 'Hello']);

        $leaves = (new FieldPathResolver())->resolve($object, 'name', 'de');

        self::assertCount(1, $leaves);
        self::assertSame('Hallo', $leaves[0]->value);
    }

    public function test_empty_fieldcollection_yields_zero_leaves(): void
    {
        $object = $this->buildObjectWithSections([]);

        $leaves = (new FieldPathResolver())->resolve($object, 'sections[].title', 'de');

        self::assertSame([], $leaves);
    }

    public function test_fieldcollection_iteration_resolves_per_item(): void
    {
        $object = $this->buildObjectWithSections([
            ['de' => 'A-de', 'en' => 'A-en'],
            ['de' => 'B-de', 'en' => 'B-en'],
        ]);

        $leaves = (new FieldPathResolver())->resolve($object, 'sections[].title', 'de');

        self::assertCount(2, $leaves);
        self::assertSame('sections[0].title', $leaves[0]->leafPath);
        self::assertSame('A-de', $leaves[0]->value);
        self::assertSame('sections[1].title', $leaves[1]->leafPath);
        self::assertSame('B-de', $leaves[1]->value);
    }

    public function test_container_terminated_fc_path_fans_out_to_localized_leaves(): void
    {
        $object = $this->buildObjectWithSections([
            ['de' => 'A-de', 'en' => 'A-en'],
            ['de' => 'B-de', 'en' => 'B-en'],
        ]);

        $leaves = (new FieldPathResolver())->resolve($object, 'sections', 'de');

        self::assertCount(2, $leaves);
        self::assertSame('de', $leaves[0]->language);
        self::assertSame('A-de', $leaves[0]->value);
        self::assertSame('B-de', $leaves[1]->value);
    }

    public function test_objectbrick_intermediate_segment_resolves_inner_localized_field(): void
    {
        $object = $this->buildObjectWithPricingBrick(['de' => '9,99', 'en' => '9.99']);

        $leaves = (new FieldPathResolver())->resolve($object, 'pricing.salePrice', 'de');

        self::assertCount(1, $leaves);
        self::assertSame('9,99', $leaves[0]->value);
    }

    public function test_objectbrick_container_terminated_fans_out_to_all_localized_leaves(): void
    {
        $object = $this->buildObjectWithPricingBrick(['de' => '9,99', 'en' => '9.99']);

        $leaves = (new FieldPathResolver())->resolve($object, 'pricing', 'en');

        self::assertCount(1, $leaves);
        self::assertSame('9.99', $leaves[0]->value);
    }

    public function test_path_referencing_non_existent_field_throws(): void
    {
        $object = $this->buildObjectWithSku('x');

        $this->expectException(InvalidFieldPathException::class);
        $this->expectExceptionMessage('nope');

        (new FieldPathResolver())->resolve($object, 'nope', 'de');
    }

    public function test_inheritance_flag_is_restored_after_resolve(): void
    {
        $object = $this->buildObjectWithSections([['de' => 'A-de', 'en' => 'A-en']]);

        $before = AbstractObject::getGetInheritedValues();
        AbstractObject::setGetInheritedValues(true);
        try {
            (new FieldPathResolver())->resolve($object, 'sections[].title', 'de');
            self::assertTrue(AbstractObject::getGetInheritedValues(), 'inheritance flag must remain true after resolve');
        } finally {
            AbstractObject::setGetInheritedValues($before);
        }
    }

    public function test_child_with_no_own_fc_items_returns_zero_leaves_regardless_of_parent_state(): void
    {
        // The child has no own items; the parent FC carries items. The stub
        // returns parentFc when getGetInheritedValues() is true and ownFc when
        // false, so the resolver must flip the flag to false before calling the
        // getter — the contract asserted here.
        $parentFcItems = [['de' => 'Parent-de', 'en' => 'Parent-en']];
        $child = $this->buildObjectWithInheritanceAwareSections(ownItems: [], parentItems: $parentFcItems);

        $before = AbstractObject::getGetInheritedValues();
        AbstractObject::setGetInheritedValues(true);
        try {
            $leaves = (new FieldPathResolver())->resolve($child, 'sections[].title', 'de');
        } finally {
            AbstractObject::setGetInheritedValues($before);
        }

        self::assertSame([], $leaves, 'resolver must disable inheritance before reading the FC container');
    }

    private function buildObjectWithSku(string $sku): Concrete
    {
        $skuField = new Input();
        $this->setProperty($skuField, 'name', 'sku');
        $classDef = $this->buildClassDefinition(['sku' => $skuField]);

        return new class($classDef, $sku) extends Concrete {
            public function __construct(
                private readonly ClassDefinition $stubClass,
                private readonly string $skuValue,
            ) {
            }

            public function getClass(): ClassDefinition
            {
                return $this->stubClass;
            }

            public function getSku(): string
            {
                return $this->skuValue;
            }
        };
    }

    private function buildObjectWithLocalizedName(array $byLang): Concrete
    {
        $nameField = new Input();
        $this->setProperty($nameField, 'name', 'name');

        $localized = new Localizedfields();
        $localized->setFieldDefinitions(['name' => $nameField]);

        $classDef = $this->buildClassDefinition(['localizedfields' => $localized]);

        return new class($classDef, $byLang) extends Concrete {
            public function __construct(
                private readonly ClassDefinition $stubClass,
                private readonly array $byLang,
            ) {
            }

            public function getClass(): ClassDefinition
            {
                return $this->stubClass;
            }

            public function getName(?string $language = null): ?string
            {
                return $this->byLang[$language] ?? null;
            }
        };
    }

    /**
     * @param array<int, array<string, string>> $items each item is a map<lang, title>
     */
    private function buildObjectWithSections(array $items): Concrete
    {
        $titleField = new Input();
        $this->setProperty($titleField, 'name', 'title');
        $localized = new Localizedfields();
        $localized->setFieldDefinitions(['title' => $titleField]);

        $sectionItemDefinition = (new \ReflectionClass(\Pimcore\Model\DataObject\Fieldcollection\Definition::class))
            ->newInstanceWithoutConstructor();
        $sectionItemDefinition->setFieldDefinitions(['localizedfields' => $localized]);
        $this->setProperty($sectionItemDefinition, 'key', 'Section');

        $sectionsField = new Fieldcollections();
        $this->setProperty($sectionsField, 'name', 'sections');

        $classDef = $this->buildClassDefinition(['sections' => $sectionsField]);

        $fcItems = [];
        foreach ($items as $byLang) {
            $fcItems[] = new class($sectionItemDefinition, $byLang) extends FieldcollectionItem {
                public function __construct(
                    private readonly \Pimcore\Model\DataObject\Fieldcollection\Definition $stubDef,
                    private readonly array $byLang,
                ) {
                }

                public function getDefinition(): \Pimcore\Model\DataObject\Fieldcollection\Definition
                {
                    return $this->stubDef;
                }

                public function getTitle(?string $language = null): ?string
                {
                    return $this->byLang[$language] ?? null;
                }
            };
        }

        $fc = new Fieldcollection($fcItems, 'sections');

        return new class($classDef, $fc) extends Concrete {
            public function __construct(
                private readonly ClassDefinition $stubClass,
                private readonly Fieldcollection $fc,
            ) {
            }

            public function getClass(): ClassDefinition
            {
                return $this->stubClass;
            }

            public function getSections(): Fieldcollection
            {
                return $this->fc;
            }
        };
    }

    /**
     * @param array<int, array<string, string>> $ownItems   FC items owned by this object
     * @param array<int, array<string, string>> $parentItems FC items that would appear when inheritance is on
     */
    private function buildObjectWithInheritanceAwareSections(array $ownItems, array $parentItems): Concrete
    {
        $titleField = new Input();
        $this->setProperty($titleField, 'name', 'title');
        $localized = new Localizedfields();
        $localized->setFieldDefinitions(['title' => $titleField]);

        $sectionItemDefinition = (new \ReflectionClass(\Pimcore\Model\DataObject\Fieldcollection\Definition::class))
            ->newInstanceWithoutConstructor();
        $sectionItemDefinition->setFieldDefinitions(['localizedfields' => $localized]);
        $this->setProperty($sectionItemDefinition, 'key', 'Section');

        $sectionsField = new Fieldcollections();
        $this->setProperty($sectionsField, 'name', 'sections');

        $classDef = $this->buildClassDefinition(['sections' => $sectionsField]);

        $buildFcItems = function (array $items) use ($sectionItemDefinition): array {
            $result = [];
            foreach ($items as $byLang) {
                $result[] = new class($sectionItemDefinition, $byLang) extends FieldcollectionItem {
                    public function __construct(
                        private readonly \Pimcore\Model\DataObject\Fieldcollection\Definition $stubDef,
                        private readonly array $byLang,
                    ) {
                    }

                    public function getDefinition(): \Pimcore\Model\DataObject\Fieldcollection\Definition
                    {
                        return $this->stubDef;
                    }

                    public function getTitle(?string $language = null): ?string
                    {
                        return $this->byLang[$language] ?? null;
                    }
                };
            }

            return $result;
        };

        $ownFc = new Fieldcollection($buildFcItems($ownItems), 'sections');
        $parentFc = new Fieldcollection($buildFcItems($parentItems), 'sections');

        return new class($classDef, $ownFc, $parentFc) extends Concrete {
            public function __construct(
                private readonly ClassDefinition $stubClass,
                private readonly Fieldcollection $ownFc,
                private readonly Fieldcollection $parentFc,
            ) {
            }

            public function getClass(): ClassDefinition
            {
                return $this->stubClass;
            }

            public function getSections(): Fieldcollection
            {
                return AbstractObject::getGetInheritedValues() ? $this->parentFc : $this->ownFc;
            }
        };
    }

    private function buildObjectWithPricingBrick(array $byLang): Concrete
    {
        $salePriceField = new Input();
        $this->setProperty($salePriceField, 'name', 'salePrice');
        $localized = new Localizedfields();
        $localized->setFieldDefinitions(['salePrice' => $salePriceField]);

        $brickDefinition = (new \ReflectionClass(\Pimcore\Model\DataObject\Objectbrick\Definition::class))
            ->newInstanceWithoutConstructor();
        $brickDefinition->setFieldDefinitions(['localizedfields' => $localized]);
        $this->setProperty($brickDefinition, 'key', 'PricingBrick');

        $pricingField = new Objectbricks();
        $this->setProperty($pricingField, 'name', 'pricing');

        $classDef = $this->buildClassDefinition(['pricing' => $pricingField]);

        $brickItem = new class($brickDefinition, $byLang) extends ObjectbrickItem {
            public function __construct(
                private readonly \Pimcore\Model\DataObject\Objectbrick\Definition $stubDef,
                private readonly array $byLang,
            ) {
            }

            public function getDefinition(): \Pimcore\Model\DataObject\Objectbrick\Definition
            {
                return $this->stubDef;
            }

            public function getSalePrice(?string $language = null): ?string
            {
                return $this->byLang[$language] ?? null;
            }
        };

        $brickContainer = new class($brickItem) extends Objectbrick {
            public function __construct(private readonly ObjectbrickItem $item)
            {
            }

            public function getItems(bool $withInheritedValues = false): array
            {
                return [$this->item];
            }
        };

        return new class($classDef, $brickContainer) extends Concrete {
            public function __construct(
                private readonly ClassDefinition $stubClass,
                private readonly Objectbrick $brickContainer,
            ) {
            }

            public function getClass(): ClassDefinition
            {
                return $this->stubClass;
            }

            public function getPricing(): Objectbrick
            {
                return $this->brickContainer;
            }
        };
    }

    private function buildClassDefinition(array $fields): ClassDefinition
    {
        $classDef = (new \ReflectionClass(ClassDefinition::class))->newInstanceWithoutConstructor();
        $classDef->setFieldDefinitions($fields);

        return $classDef;
    }

    private function setProperty(object $target, string $name, mixed $value): void
    {
        $reflection = new \ReflectionClass($target);
        while ($reflection !== false && !$reflection->hasProperty($name)) {
            $reflection = $reflection->getParentClass();
        }
        if ($reflection === false) {
            throw new \LogicException(sprintf('Property "%s" not found on %s', $name, get_class($target)));
        }
        $prop = $reflection->getProperty($name);
        $prop->setValue($target, $value);
    }
}
