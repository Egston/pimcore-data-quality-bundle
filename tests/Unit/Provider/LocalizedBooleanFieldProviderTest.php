<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Provider;

use Basilicom\DataQualityBundle\Provider\LocalizedBooleanFieldProvider;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Tool;

final class LocalizedBooleanFieldProviderTest extends TestCase
{
    /** @var string[] */
    private array $savedLanguages = [];

    protected function setUp(): void
    {
        $prop = (new \ReflectionClass(Tool::class))->getProperty('validLanguages');
        $this->savedLanguages = $prop->getValue();
        $prop->setValue(null, ['en', 'de', 'fr']);
    }

    protected function tearDown(): void
    {
        $prop = (new \ReflectionClass(Tool::class))->getProperty('validLanguages');
        $prop->setValue(null, $this->savedLanguages);
    }

    public function test_constructor_does_not_call_tool_get_valid_languages(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../../src/Provider/LocalizedBooleanFieldProvider.php',
        );
        self::assertIsString($source);
        if (preg_match('/__construct\([^)]*\)\s*\{(.*?)\}/s', $source, $m)) {
            self::assertStringNotContainsString(
                'Tool::getValidLanguages',
                $m[1],
                'Constructor must defer Tool::getValidLanguages() to getFlags() — see kernel-bound hazard.',
            );
        } else {
            self::fail('Could not extract LocalizedBooleanFieldProvider::__construct body for source inspection.');
        }
    }

    public function test_maps_truthy_falsy_and_skips_unknown(): void
    {
        $provider = new LocalizedBooleanFieldProvider(
            fieldName: 'translationVerified',
            name: 'Translation verified',
        );

        $object = new class extends Concrete {
            public function __construct() {}

            public function getTranslationVerified(?string $language = null): ?bool
            {
                return match ($language) {
                    'en' => true,
                    'de' => false,
                    'fr' => null,
                    default => null,
                };
            }
        };

        $flags = $provider->getFlags($object);

        self::assertTrue($flags['en']);
        self::assertFalse($flags['de']);
        self::assertArrayNotHasKey('fr', $flags, 'null getter result must map to absent (unknown), not false');
    }

    public function test_name_and_supported_class_ids_round_trip(): void
    {
        $provider = new LocalizedBooleanFieldProvider(
            fieldName: 'verified',
            name: 'Verified',
            supportedClassIds: ['Article'],
        );

        self::assertSame('Verified', $provider->getName());
        self::assertSame(['Article'], $provider->supportedClassIds());
    }

    public function test_supported_class_ids_defaults_empty(): void
    {
        $provider = new LocalizedBooleanFieldProvider(
            fieldName: 'verified',
            name: 'Verified',
        );

        self::assertSame([], $provider->supportedClassIds());
    }
}
