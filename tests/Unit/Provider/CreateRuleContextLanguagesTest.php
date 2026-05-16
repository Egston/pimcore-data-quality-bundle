<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Provider;

use Basilicom\DataQualityBundle\DefinitionsCollection\Factory\FieldDefinitionFactory;
use Basilicom\DataQualityBundle\Exception\DataQualityException;
use Basilicom\DataQualityBundle\Provider\DataQualityProvider;
use Basilicom\DataQualityBundle\Resolver\FieldPathResolverInterface;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\DataQualityConfig;

/**
 * Targets `DataQualityProvider::resolveScoredLanguages()` — the private
 * seam threaded into `LanguageScope` from `createRuleContext()`. The
 * outer `createRuleContext()` reaches `Tool::getValidLanguages()` /
 * `Tool::getDefaultLanguage()` (kernel-bound); testing the seam directly
 * keeps the suite kernel-free.
 */
final class CreateRuleContextLanguagesTest extends TestCase
{
    public function test_empty_allow_list_falls_back_to_valid_set(): void
    {
        $config = $this->makeConfig(null);

        $scored = $this->invokeResolveScoredLanguages($config, ['en', 'de', 'fr', 'ja']);

        self::assertSame(['en', 'de', 'fr', 'ja'], $scored);
    }

    public function test_empty_array_allow_list_falls_back_to_valid_set(): void
    {
        $config = $this->makeConfig([]);

        $scored = $this->invokeResolveScoredLanguages($config, ['en', 'de']);

        self::assertSame(['en', 'de'], $scored);
    }

    public function test_explicit_allow_list_narrows_scope(): void
    {
        $config = $this->makeConfig(['de', 'fr']);

        $scored = $this->invokeResolveScoredLanguages($config, ['en', 'de', 'fr', 'ja']);

        self::assertSame(['de', 'fr'], $scored);
    }

    public function test_stale_language_throws(): void
    {
        $config = $this->makeConfig(['de', 'xx']);
        $config->setDataQualityName('ResourceLibraryItemDQ');

        $this->expectException(DataQualityException::class);
        $this->expectExceptionMessageMatches('/ResourceLibraryItemDQ.*xx/s');

        $this->invokeResolveScoredLanguages($config, ['en', 'de']);
    }

    /**
     * @param string[]|null $configured
     */
    private function makeConfig(?array $configured): DataQualityConfig
    {
        $config = (new \ReflectionClass(DataQualityConfig::class))->newInstanceWithoutConstructor();
        $config->setDataQualityLanguages($configured);

        return $config;
    }

    /**
     * @param string[] $valid
     *
     * @return string[]
     */
    private function invokeResolveScoredLanguages(DataQualityConfig $config, array $valid): array
    {
        $provider = new DataQualityProvider(
            $this->stubFactory(),
            $this->stubResolver(),
        );

        $reflection = new \ReflectionClass(DataQualityProvider::class);
        $method = $reflection->getMethod('resolveScoredLanguages');
        $method->setAccessible(true);

        return $method->invoke($provider, $config, $valid);
    }

    private function stubFactory(): FieldDefinitionFactory
    {
        return (new \ReflectionClass(FieldDefinitionFactory::class))->newInstanceWithoutConstructor();
    }

    private function stubResolver(): FieldPathResolverInterface
    {
        return new class implements FieldPathResolverInterface {
            public function resolve(Concrete $object, string $path, string $language): array
            {
                return [];
            }
        };
    }
}
