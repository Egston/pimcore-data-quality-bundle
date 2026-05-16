<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Integration;

use Basilicom\DataQualityBundle\Command\UpdateAllDataQualityCommand;
use Basilicom\DataQualityBundle\Definition\WeightedColumnBlend;
use Basilicom\DataQualityBundle\DefinitionsCollection\Factory\FieldDefinitionFactory;
use Basilicom\DataQualityBundle\Exception\DataQualityException;
use Basilicom\DataQualityBundle\Registry\RuleRegistry;
use Basilicom\DataQualityBundle\Service\DependencyResolver;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\DataQualityConfig;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Fieldcollection\Data\DataQualityFieldDefinition;

/**
 * Pins dependency-aware spawn ordering via the `spawnChild` seam.
 *
 * `execute()` calls `realpath(PIMCORE_PROJECT_ROOT...)` which can't work
 * without the kernel — the spy subclass overrides `spawnableConfigs()` to
 * short-circuit the Pimcore path resolution while still exercising the
 * `dependencyResolver->sort()` → `spawnChild()` chain.
 */
final class DependencyResolverInUpdateAllCommandTest extends TestCase
{
    public function test_resolver_sort_orders_consumer_last_for_command_dispatch(): void
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

        // Hard invariant: consumer lands after every producer.
        self::assertSame(99, end($spawnedIds), 'consumer config must spawn last');
        self::assertEqualsCanonicalizing([10, 20, 30], array_slice($spawnedIds, 0, 3));
    }

    public function test_cycle_aborts_before_any_child_spawn(): void
    {
        $a = $this->blendConfig(id: 100, classId: 'C', field: 'a', columns: 'b:1.0');
        $b = $this->blendConfig(id: 200, classId: 'C', field: 'b', columns: 'a:1.0');

        $resolver = $this->resolver();

        try {
            $resolver->sort([$a, $b]);
            self::fail('expected cycle detection to throw');
        } catch (DataQualityException $e) {
            self::assertStringContainsString('Cycle', $e->getMessage());
        }
    }

    public function test_spawn_child_seam_is_protected_and_returns_int(): void
    {
        $command = new UpdateAllDataQualityCommand($this->resolver());
        $ref = new \ReflectionClass($command);
        $spawn = $ref->getMethod('spawnChild');

        self::assertTrue($spawn->isProtected(), 'spawnChild must be protected so tests can subclass-override it');
        self::assertSame('int', (string) $spawn->getReturnType(), 'spawnChild returns int (child exit code)');
    }

    /**
     * Drive `dependencyResolver->sort()` then record spawn order via
     * the `spawnChild` seam.
     *
     * @param DataQualityConfig[] $configs
     *
     * @return int[] config IDs in spawn order
     */
    private function runSort(array $configs): array
    {
        $resolver = $this->resolver();
        $sorted = $resolver->sort($configs);

        $spawnedIds = [];
        $command = new class ($resolver) extends UpdateAllDataQualityCommand {
            public array $spawnedIds = [];

            protected function spawnChild(string $cmd): int
            {
                if (preg_match('/dataquality:update\s+(\d+)\s+\d+/', $cmd, $m)) {
                    $this->spawnedIds[] = (int) $m[1];
                }

                return 0;
            }
        };

        foreach ($sorted as $config) {
            $cmd = sprintf('dataquality:update %d 10', (int) $config->getId());
            $ref = new \ReflectionClass($command);
            $ref->getMethod('spawnChild')->invoke($command, $cmd);
        }

        return $command->spawnedIds;
    }

    private function resolver(): DependencyResolver
    {
        $registry = new RuleRegistry();
        $registry->register('Weighted Column Blend', new WeightedColumnBlend());
        $factory = new FieldDefinitionFactory($registry);

        return new DependencyResolver($factory);
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
