<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Provider;

use Basilicom\DataQualityBundle\Provider\ObjectbrickFlagsProvider;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\Concrete;

final class ObjectbrickFlagsProviderTest extends TestCase
{
    public function test_constructor_rejects_empty_brick_container_getter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ObjectbrickFlagsProvider('', 'getBrick', ['de' => 'getDe'], 'p');
    }

    public function test_constructor_rejects_empty_brick_class_getter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ObjectbrickFlagsProvider('getContainer', '', ['de' => 'getDe'], 'p');
    }

    public function test_constructor_rejects_empty_lang_getters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ObjectbrickFlagsProvider('getContainer', 'getBrick', [], 'p');
    }

    public function test_returns_empty_when_brick_container_is_null(): void
    {
        $provider = new ObjectbrickFlagsProvider(
            brickContainerGetter: 'getTranslations',
            brickClassGetter: 'getVerification',
            langGetters: ['en' => 'getEn', 'de' => 'getDe'],
            name: 'Verified',
        );

        $object = new class extends Concrete {
            public function __construct() {}

            public function getTranslations(): mixed
            {
                return null;
            }
        };

        self::assertSame([], $provider->getFlags($object));
    }

    public function test_returns_empty_when_brick_instance_is_null(): void
    {
        $provider = new ObjectbrickFlagsProvider(
            brickContainerGetter: 'getTranslations',
            brickClassGetter: 'getVerification',
            langGetters: ['en' => 'getEn'],
            name: 'Verified',
        );

        $container = new class {
            public function getVerification(): mixed
            {
                return null;
            }
        };

        $object = new class ($container) extends Concrete {
            public function __construct(private readonly object $bricks) {}

            public function getTranslations(): object
            {
                return $this->bricks;
            }
        };

        self::assertSame([], $provider->getFlags($object));
    }

    public function test_maps_truthy_falsy_and_skips_unknown(): void
    {
        $provider = new ObjectbrickFlagsProvider(
            brickContainerGetter: 'getTranslations',
            brickClassGetter: 'getVerification',
            langGetters: ['en' => 'getEn', 'de' => 'getDe', 'fr' => 'getFr'],
            name: 'Verified',
        );

        $brick = new class {
            public function getEn()
            {
                return true;
            }
            public function getDe()
            {
                return false;
            }
            public function getFr()
            {
                return null;
            }
        };

        $container = new class ($brick) {
            public function __construct(private readonly object $brick) {}
            public function getVerification(): object
            {
                return $this->brick;
            }
        };

        $object = new class ($container) extends Concrete {
            public function __construct(private readonly object $bricks) {}
            public function getTranslations(): object
            {
                return $this->bricks;
            }
        };

        $flags = $provider->getFlags($object);

        self::assertTrue($flags['en']);
        self::assertFalse($flags['de']);
        self::assertArrayNotHasKey('fr', $flags, 'null from getter must map to absent (unknown) not false');
    }

    public function test_get_name_returns_constructor_name(): void
    {
        $provider = new ObjectbrickFlagsProvider(
            brickContainerGetter: 'getX',
            brickClassGetter: 'getY',
            langGetters: ['en' => 'getEn'],
            name: 'My Provider',
        );

        self::assertSame('My Provider', $provider->getName());
    }

    public function test_supported_class_ids_round_trip(): void
    {
        $provider = new ObjectbrickFlagsProvider(
            brickContainerGetter: 'getX',
            brickClassGetter: 'getY',
            langGetters: ['en' => 'getEn'],
            name: 'p',
            supportedClassIds: ['Article', 'Product'],
        );

        self::assertSame(['Article', 'Product'], $provider->supportedClassIds());
    }

    public function test_supported_class_ids_defaults_empty(): void
    {
        $provider = new ObjectbrickFlagsProvider(
            brickContainerGetter: 'getX',
            brickClassGetter: 'getY',
            langGetters: ['en' => 'getEn'],
            name: 'p',
        );

        self::assertSame([], $provider->supportedClassIds());
    }
}
