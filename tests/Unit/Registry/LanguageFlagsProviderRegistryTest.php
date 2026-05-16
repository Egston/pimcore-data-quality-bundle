<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Registry;

use Basilicom\DataQualityBundle\Provider\LanguageFlagsProvider;
use Basilicom\DataQualityBundle\Registry\LanguageFlagsProviderRegistry;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\Concrete;

final class LanguageFlagsProviderRegistryTest extends TestCase
{
    public function test_register_and_get_round_trip(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $provider = $this->fakeProvider('Brick verified flags');

        $registry->register('app.flags.brick_verified', $provider);

        self::assertSame($provider, $registry->get('app.flags.brick_verified'));
        self::assertTrue($registry->has('app.flags.brick_verified'));
    }

    public function test_get_returns_null_for_unknown_service_id(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.a', $this->fakeProvider('A'));

        self::assertNull($registry->get('app.flags.missing'));
        self::assertFalse($registry->has('app.flags.missing'));
    }

    public function test_all_returns_service_id_indexed_map(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $a = $this->fakeProvider('A');
        $b = $this->fakeProvider('B');
        $registry->register('app.flags.a', $a);
        $registry->register('app.flags.b', $b);

        $all = $registry->all();

        self::assertSame(['app.flags.a', 'app.flags.b'], array_keys($all));
        self::assertSame($a, $all['app.flags.a']);
        self::assertSame($b, $all['app.flags.b']);
    }

    public function test_register_same_service_id_twice_overwrites(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $first = $this->fakeProvider('first');
        $second = $this->fakeProvider('second');

        $registry->register('app.flags.x', $first);
        $registry->register('app.flags.x', $second);

        self::assertSame($second, $registry->get('app.flags.x'));
    }

    private function fakeProvider(string $name): LanguageFlagsProvider
    {
        return new class ($name) implements LanguageFlagsProvider {
            public function __construct(private readonly string $name) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getFlags(Concrete $object): array
            {
                return [];
            }

            public function supportedClassIds(): array
            {
                return [];
            }
        };
    }
}
