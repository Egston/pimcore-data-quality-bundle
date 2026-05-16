<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition;

use Basilicom\DataQualityBundle\Definition\LanguageScope;
use Basilicom\DataQualityBundle\Definition\LocalizedFillRatio;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Basilicom\DataQualityBundle\Resolver\FieldPathResolverInterface;
use Basilicom\DataQualityBundle\Resolver\ResolvedLeaf;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\DataQualityConfig;

final class LocalizedFillRatioContainerPathTest extends TestCase
{
    public function test_two_fieldcollection_items_with_full_coverage_passes(): void
    {
        $leaves = [
            new ResolvedLeaf('sections[0].title', 'en', 'Intro'),
            new ResolvedLeaf('sections[0].title', 'de', 'Einleitung'),
            new ResolvedLeaf('sections[1].title', 'en', 'Body'),
            new ResolvedLeaf('sections[1].title', 'de', 'Hauptteil'),
        ];

        $rule = new LocalizedFillRatio();
        $context = $this->makeContextWithContainerLeaves('sections[].title', $leaves, ['en', 'de']);

        self::assertTrue($rule->validate(null, $this->makeFieldDef('sections[].title'), ['1.0'], $context));
    }

    public function test_two_fieldcollection_items_with_partial_de_coverage_fails_half_threshold(): void
    {
        $leaves = [
            new ResolvedLeaf('sections[0].title', 'en', 'Intro'),
            new ResolvedLeaf('sections[0].title', 'de', 'Einleitung'),
            new ResolvedLeaf('sections[1].title', 'en', 'Body'),
            new ResolvedLeaf('sections[1].title', 'de', ''),
        ];

        $rule = new LocalizedFillRatio();
        $context = $this->makeContextWithContainerLeaves('sections[].title', $leaves, ['en', 'de']);

        self::assertTrue($rule->validate(null, $this->makeFieldDef('sections[].title'), ['0.5'], $context));
        self::assertFalse($rule->validate(null, $this->makeFieldDef('sections[].title'), ['1.0'], $context));
    }

    private function makeFieldDef(string $name): Data
    {
        $stub = $this->createStub(Data::class);
        $stub->method('getName')->willReturn($name);

        return $stub;
    }

    /**
     * @param ResolvedLeaf[] $leaves
     * @param string[] $scored
     */
    private function makeContextWithContainerLeaves(string $containerPath, array $leaves, array $scored): RuleContext
    {
        $resolver = new class ($containerPath, $leaves) implements FieldPathResolverInterface {
            /** @param ResolvedLeaf[] $leaves */
            public function __construct(
                private readonly string $containerPath,
                private readonly array $leaves,
            ) {}

            public function resolve(Concrete $object, string $path, string $language): array
            {
                if ($path !== $this->containerPath) {
                    return [];
                }

                return array_values(array_filter(
                    $this->leaves,
                    fn(ResolvedLeaf $leaf): bool => $leaf->language === $language,
                ));
            }
        };

        $reflection = new \ReflectionClass(RuleContext::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('object')->setValue(
            $instance,
            (new \ReflectionClass(Concrete::class))->newInstanceWithoutConstructor(),
        );
        $reflection->getProperty('config')->setValue(
            $instance,
            (new \ReflectionClass(DataQualityConfig::class))->newInstanceWithoutConstructor(),
        );
        $reflection->getProperty('resolver')->setValue($instance, $resolver);
        $reflection->getProperty('flagsProvider')->setValue($instance, null);
        $reflection->getProperty('languageScope')->setValue(
            $instance,
            new LanguageScope($scored[0], $scored, $scored),
        );

        return $instance;
    }
}
