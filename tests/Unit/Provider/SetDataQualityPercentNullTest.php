<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Provider;

use Basilicom\DataQualityBundle\DefinitionsCollection\Factory\FieldDefinitionFactory;
use Basilicom\DataQualityBundle\Provider\DataQualityProvider;
use Basilicom\DataQualityBundle\Resolver\FieldPathResolverInterface;
use Basilicom\DataQualityBundle\View\DataQualityFieldViewModel;
use Basilicom\DataQualityBundle\View\DataQualityGroupViewModel;
use Basilicom\DataQualityBundle\Tests\Stubs\PercentRecordingDataObject;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\Concrete;

/**
 * Pins `DataQualityProvider::setDataQualityPercent()` returning `null`
 * iff the rule-set produces zero total weight. The setter on the data
 * object receives `null` so a downstream `save()` writes SQL NULL —
 * distinguishing "not yet scored" from "0% scored".
 */
final class SetDataQualityPercentNullTest extends TestCase
{
    public function test_zero_total_returns_null_and_setter_receives_null(): void
    {
        $object = new PercentRecordingDataObject();

        $value = $this->invokeSetDataQualityPercent(
            object: $object,
            groups: [new DataQualityGroupViewModel('default', [])],
        );

        self::assertNull($value);
        self::assertTrue($object->setterCalled, 'setter must still be invoked so a cached value is cleared');
        self::assertNull($object->setterValue);
    }

    public function test_zero_total_returns_null_even_with_empty_groups(): void
    {
        $object = new PercentRecordingDataObject();

        $value = $this->invokeSetDataQualityPercent(
            object: $object,
            groups: [],
        );

        self::assertNull($value);
        self::assertTrue($object->setterCalled);
        self::assertNull($object->setterValue);
    }

    public function test_nonzero_total_returns_int_percentage(): void
    {
        $object = new PercentRecordingDataObject();
        $group = new DataQualityGroupViewModel('default', [
            new DataQualityFieldViewModel('a', 1, true),
            new DataQualityFieldViewModel('b', 1, false),
        ]);

        $value = $this->invokeSetDataQualityPercent($object, [$group]);

        self::assertSame(50, $value);
        self::assertSame(50.0, $object->setterValue);
    }

    public function test_all_valid_returns_one_hundred(): void
    {
        $object = new PercentRecordingDataObject();
        $group = new DataQualityGroupViewModel('default', [
            new DataQualityFieldViewModel('a', 2, true),
            new DataQualityFieldViewModel('b', 3, true),
        ]);

        $value = $this->invokeSetDataQualityPercent($object, [$group]);

        self::assertSame(100, $value);
        self::assertSame(100.0, $object->setterValue);
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
            new class implements FieldPathResolverInterface {
                public function resolve(Concrete $object, string $path, string $language): array
                {
                    return [];
                }
            },
        );

        $reflection = new \ReflectionClass(DataQualityProvider::class);
        $method = $reflection->getMethod('setDataQualityPercent');
        $method->setAccessible(true);

        return $method->invoke($provider, $object, $groups, 'dataQualityScore', false, false);
    }
}
