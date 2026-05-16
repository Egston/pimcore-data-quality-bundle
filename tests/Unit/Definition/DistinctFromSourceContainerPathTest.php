<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition;

use Basilicom\DataQualityBundle\Definition\DistinctFromSource;
use Basilicom\DataQualityBundle\Definition\LanguageScope;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Basilicom\DataQualityBundle\Resolver\FieldPathResolverInterface;
use Basilicom\DataQualityBundle\Resolver\ResolvedLeaf;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\DataQualityConfig;

/**
 * Container-path coverage: `DistinctFromSource` aggregates across
 * fieldcollection items and flags stealth-English independently for
 * each item.
 */
final class DistinctFromSourceContainerPathTest extends TestCase
{
    public function test_two_items_all_distinct_passes(): void
    {
        $leaves = [
            new ResolvedLeaf('sections[0].title', 'en', 'Intro'),
            new ResolvedLeaf('sections[0].title', 'de', 'Einleitung'),
            new ResolvedLeaf('sections[1].title', 'en', 'Body'),
            new ResolvedLeaf('sections[1].title', 'de', 'Hauptteil'),
        ];

        $rule = new DistinctFromSource();
        $context = $this->makeContextWithContainerLeaves('sections[].title', $leaves, 'en', ['en', 'de']);

        self::assertTrue($rule->validate(null, $this->makeFieldDef('sections[].title'), [], $context));
    }

    public function test_one_item_with_stealth_english_fails(): void
    {
        $leaves = [
            new ResolvedLeaf('sections[0].title', 'en', 'Intro'),
            new ResolvedLeaf('sections[0].title', 'de', 'Einleitung'),
            new ResolvedLeaf('sections[1].title', 'en', 'Body'),
            new ResolvedLeaf('sections[1].title', 'de', 'Body'),
        ];

        $rule = new DistinctFromSource();
        $context = $this->makeContextWithContainerLeaves('sections[].title', $leaves, 'en', ['en', 'de']);

        self::assertFalse($rule->validate(null, $this->makeFieldDef('sections[].title'), [], $context));
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
    private function makeContextWithContainerLeaves(
        string $containerPath,
        array $leaves,
        string $source,
        array $scored,
    ): RuleContext {
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
            new LanguageScope($source, $scored, $scored),
        );

        return $instance;
    }
}
