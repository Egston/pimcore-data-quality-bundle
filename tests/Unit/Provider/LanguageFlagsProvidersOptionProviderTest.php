<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Provider;

use Basilicom\DataQualityBundle\Model\Provider\LanguageFlagsProvidersOptionProvider;
use Basilicom\DataQualityBundle\Provider\LanguageFlagsProvider;
use Basilicom\DataQualityBundle\Registry\LanguageFlagsProviderRegistry;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\Concrete;

final class LanguageFlagsProvidersOptionProviderTest extends TestCase
{
    public function test_get_options_returns_value_key_pairs_for_registered_providers(): void
    {
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.brick', $this->makeProvider('Brick Verified'));
        $registry->register('app.flags.field', $this->makeProvider('Localized Field'));

        $optionProvider = new LanguageFlagsProvidersOptionProvider($registry);

        $options = $optionProvider->getOptions([], null);

        self::assertCount(2, $options);
        self::assertSame('app.flags.brick', $options[0]['value']);
        self::assertSame('Brick Verified', $options[0]['key']);
        self::assertSame('app.flags.field', $options[1]['value']);
        self::assertSame('Localized Field', $options[1]['key']);
    }

    public function test_get_options_returns_empty_when_registry_is_empty(): void
    {
        $optionProvider = new LanguageFlagsProvidersOptionProvider(new LanguageFlagsProviderRegistry());

        self::assertSame([], $optionProvider->getOptions([], null));
    }

    private function makeProvider(string $name): LanguageFlagsProvider
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
