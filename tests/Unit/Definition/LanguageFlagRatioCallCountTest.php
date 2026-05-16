<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition;

use Basilicom\DataQualityBundle\Definition\LanguageFlagRatio;
use Basilicom\DataQualityBundle\Definition\LocalizedAwareDefinition;
use Basilicom\DataQualityBundle\Provider\LanguageFlagsProvider;
use Basilicom\DataQualityBundle\Registry\LanguageFlagsProviderRegistry;
use Basilicom\DataQualityBundle\Tests\Helper\RuleContextBuilder;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;

/**
 * Pins the call-count contract: provider::getFlags() is invoked
 * exactly once per validate() call regardless of scored-language count.
 */
final class LanguageFlagRatioCallCountTest extends TestCase
{
    public function test_validates_once_across_scored_set(): void
    {
        $callCount = 0;
        $registry = new LanguageFlagsProviderRegistry();
        $registry->register('app.flags.counting', new class ($callCount) implements LanguageFlagsProvider {
            public function __construct(public int &$count) {}

            public function getName(): string
            {
                return 'counting';
            }

            public function getFlags(Concrete $object): array
            {
                $this->count++;
                return ['en' => true, 'de' => true, 'fr' => true];
            }

            public function supportedClassIds(): array
            {
                return [];
            }
        });

        $rule = new LanguageFlagRatio($registry);
        self::assertInstanceOf(LocalizedAwareDefinition::class, $rule);

        $context = RuleContextBuilder::withValues(['en' => null, 'de' => null, 'fr' => null])
            ->sourceLanguage('en')
            ->scoredLanguages(['en', 'de', 'fr'])
            ->build();

        $rule->validate(null, $this->makeFieldDef('name'), [
            'provider_service_id' => 'app.flags.counting',
        ], $context);

        self::assertSame(1, $callCount, 'marker arm must invoke provider exactly once per rule recompute');
    }

    private function makeFieldDef(string $name): Data
    {
        $stub = $this->createStub(Data::class);
        $stub->method('getName')->willReturn($name);

        return $stub;
    }
}
