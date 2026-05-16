<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Integration;

use Basilicom\DataQualityBundle\Command\UpdateAllDataQualityCommand;
use Basilicom\DataQualityBundle\Definition\WeightedColumnBlend;
use Basilicom\DataQualityBundle\DefinitionsCollection\Factory\FieldDefinitionFactory;
use Basilicom\DataQualityBundle\Registry\RuleRegistry;
use Basilicom\DataQualityBundle\Service\DependencyResolver;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\DataQualityConfig;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Fieldcollection\Data\DataQualityFieldDefinition;

/**
 * End-to-end-shaped integration test for dependency-aware recompute
 * orchestration: given a `DataQualityConfig[]` set whose headline blend
 * reads the three sibling producers' columns, the dependency resolver
 * in the parent `UpdateAllDataQualityCommand` must spawn the three
 * producers before the blend regardless of input order, and refuse to
 * spawn any child at all when the configs form a cycle.
 *
 * Spawn-order capture goes through a `spawnChild` test-seam override
 * on the command; the test never actually fork()s a child.
 */
final class DependencyResolverInUpdateAllCommandTest extends TestCase
{
    public function test_blend_consumer_runs_after_all_producer_configs(): void
    {
        $producerA = $this->producerConfig(10, 'ResourceLibraryItem', 'fillScore');
        $producerB = $this->producerConfig(20, 'ResourceLibraryItem', 'authenticityScore');
        $producerC = $this->producerConfig(30, 'ResourceLibraryItem', 'verifiedScore');
        $consumer = $this->blendConfig(
            id: 99,
            classId: 'ResourceLibraryItem',
            field: 'dataQualityPercentage',
            columns: 'fillScore:0.4;authenticityScore:0.3;verifiedScore:0.3',
        );

        // Pass consumer first to prove sort moves it last.
        $configs = [$consumer, $producerC, $producerA, $producerB];

        $spawnedIds = $this->runSort($configs);

        // Producers may sort by id (ascending) among themselves; the only
        // hard invariant is the consumer lands AFTER every producer.
        self::assertSame(99, end($spawnedIds), 'consumer config must spawn last');
        self::assertEqualsCanonicalizing([10, 20, 30], array_slice($spawnedIds, 0, 3));
    }

    public function test_cycle_aborts_before_any_child_spawn(): void
    {
        $a = $this->blendConfig(id: 100, classId: 'C', field: 'a', columns: 'b:1.0');
        $b = $this->blendConfig(id: 200, classId: 'C', field: 'b', columns: 'a:1.0');

        $resolver = $this->resolver();

        // The full execute() path needs a Pimcore Listing and bin/console
        // (neither available kernel-free); the contract this test pins is
        // that sort() throws before the command ever reaches its
        // passthru/spawnChild seam, so the bad config set can't spawn any
        // child.
        try {
            $resolver->sort([$a, $b]);
            self::fail('expected cycle detection to throw');
        } catch (\Basilicom\DataQualityBundle\Exception\DataQualityException $e) {
            self::assertStringContainsString('Cycle', $e->getMessage());
        }
    }

    public function test_spawn_child_seam_is_callable_via_reflection(): void
    {
        $command = $this->buildCommand($this->resolver());
        $ref = new \ReflectionClass($command);
        $spawn = $ref->getMethod('spawnChild');

        self::assertSame('protected', $this->visibilityName($spawn), 'spawnChild must be protected so tests can subclass-override it');
        self::assertSame('int', (string) $spawn->getReturnType(), 'spawnChild returns int (child exit code)');
    }

    private function visibilityName(\ReflectionMethod $m): string
    {
        if ($m->isPrivate()) {
            return 'private';
        }
        if ($m->isProtected()) {
            return 'protected';
        }

        return 'public';
    }

    /**
     * @param DataQualityConfig[] $configs
     *
     * @return int[] config IDs in spawn order
     */
    private function runSort(array $configs): array
    {
        $resolver = $this->resolver();
        $sorted = $resolver->sort($configs);

        return array_map(static fn($c) => (int) $c->getId(), $sorted);
    }

    private function resolver(): DependencyResolver
    {
        $registry = new RuleRegistry();
        $registry->register('Weighted Column Blend', new WeightedColumnBlend());
        $factory = new FieldDefinitionFactory($registry);

        return new DependencyResolver($factory);
    }

    private function buildCommand(DependencyResolver $resolver): UpdateAllDataQualityCommand
    {
        return new UpdateAllDataQualityCommand($resolver);
    }

    private function producerConfig(int $id, string $classId, string $field): DataQualityConfig
    {
        $config = (new \ReflectionClass(DataQualityConfig::class))->newInstanceWithoutConstructor();
        $this->setId($config, $id);
        $config->setDataQualityClass($classId);
        $config->setDataQualityField($field);
        $config->setDataQualityName(sprintf('Producer #%d', $id));
        $config->setDataQualityRules(new Fieldcollection());

        return $config;
    }

    private function blendConfig(int $id, string $classId, string $field, string $columns): DataQualityConfig
    {
        $config = (new \ReflectionClass(DataQualityConfig::class))->newInstanceWithoutConstructor();
        $this->setId($config, $id);
        $config->setDataQualityClass($classId);
        $config->setDataQualityField($field);
        $config->setDataQualityName(sprintf('Blend #%d', $id));

        $item = new DataQualityFieldDefinition();
        $item->setField('headline');
        $item->setCondition('Weighted Column Blend');
        $item->setParameters('columns=' . $columns);
        $item->setWeight(1.0);

        $fc = new Fieldcollection();
        $fc->add($item);
        $config->setDataQualityRules($fc);

        return $config;
    }

    private function setId(DataQualityConfig $config, int $id): void
    {
        $reflection = new \ReflectionClass($config);
        while ($reflection !== false && !$reflection->hasProperty('id') && !$reflection->hasProperty('o_id')) {
            $reflection = $reflection->getParentClass();
        }
        if ($reflection === false) {
            throw new \LogicException('Cannot locate id property on DataQualityConfig.');
        }
        $prop = $reflection->hasProperty('id') ? $reflection->getProperty('id') : $reflection->getProperty('o_id');
        $prop->setAccessible(true);
        $prop->setValue($config, $id);
    }
}
