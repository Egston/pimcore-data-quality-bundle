<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Model\Listener;

use Basilicom\DataQualityBundle\Exception\DataQualityException;
use Basilicom\DataQualityBundle\Model\Listener\ObjectPreSaveListener;
use Basilicom\DataQualityBundle\Service\DataQualityService;
use Basilicom\DataQualityBundle\Service\DependencyResolver;
use Basilicom\DataQualityBundle\View\DataQualityViewModel;
use PHPUnit\Framework\TestCase;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\DataQualityConfig;

/**
 * Pins that `onPreSave()` passes the config set through `DependencyResolver::sort()`
 * before iterating, and that a sort-level `DataQualityException` is swallowed
 * (logged) rather than propagated.
 */
final class ObjectPreSaveListenerSortTest extends TestCase
{
    public function test_configs_reach_calculate_in_sort_order(): void
    {
        $configA = $this->fakeConfig(id: 10);
        $configB = $this->fakeConfig(id: 20);

        // Resolver returns reversed order to detect if sort was used.
        $sortedOrder = [$configB, $configA];
        $resolver = $this->fakeResolver($sortedOrder);

        $seenIds = [];
        $service = $this->fakeService(
            configs: [$configA, $configB],
            onCalculate: function (DataQualityConfig $c) use (&$seenIds): void {
                $seenIds[] = (int) $c->getId();
            },
        );

        $listener = new ObjectPreSaveListener($service, $resolver);
        $listener->onPreSave(new DataObjectEvent($this->fakeObject()));

        self::assertSame([20, 10], $seenIds, 'configs must reach calculateDataQuality in the resolver-sorted order');
    }

    public function test_sort_exception_is_swallowed_and_does_not_propagate(): void
    {
        $resolver = new class extends DependencyResolver {
            public function __construct()
            {
                // Skip parent constructor.
            }

            public function sort(array $configs): array
            {
                throw new DataQualityException('test cycle detected');
            }
        };

        $seenIds = [];
        $service = $this->fakeService(
            configs: [$this->fakeConfig(id: 1)],
            onCalculate: function () use (&$seenIds): void {
                $seenIds[] = 1;
            },
        );

        $listener = new ObjectPreSaveListener($service, $resolver);

        // Must not throw — the listener swallows the exception.
        $listener->onPreSave(new DataObjectEvent($this->fakeObject()));

        self::assertSame([], $seenIds, 'no config must be processed when sort throws');
    }

    private function fakeObject(): Concrete
    {
        return new class extends Concrete {
            public function __construct()
            {
                // Skip Concrete's constructor.
            }

            public function getClass(): \Pimcore\Model\DataObject\ClassDefinition
            {
                throw new \RuntimeException('getClass() not stubbed');
            }
        };
    }

    private function fakeConfig(int $id): DataQualityConfig
    {
        $config = (new \ReflectionClass(DataQualityConfig::class))->newInstanceWithoutConstructor();
        $reflection = new \ReflectionClass($config);
        while ($reflection !== false && !$reflection->hasProperty('id') && !$reflection->hasProperty('o_id')) {
            $reflection = $reflection->getParentClass();
        }
        if ($reflection !== false) {
            $prop = $reflection->hasProperty('id') ? $reflection->getProperty('id') : $reflection->getProperty('o_id');
            $prop->setAccessible(true);
            $prop->setValue($config, $id);
        }
        $config->setDataQualitySystemAllowed(true);

        return $config;
    }

    /**
     * @param DataQualityConfig[] $sortedResult
     */
    private function fakeResolver(array $sortedResult): DependencyResolver
    {
        return new class ($sortedResult) extends DependencyResolver {
            public function __construct(private readonly array $result)
            {
                // Skip parent constructor.
            }

            public function sort(array $configs): array
            {
                return $this->result;
            }
        };
    }

    /**
     * @param DataQualityConfig[] $configs
     */
    private function fakeService(array $configs, \Closure $onCalculate): DataQualityService
    {
        return new class ($configs, $onCalculate) extends DataQualityService {
            public function __construct(
                private readonly array $configs,
                private readonly \Closure $onCalculate,
            ) {
                // Skip parent constructor.
            }

            public function getDataQualityConfigs(?AbstractObject $object): array
            {
                return $this->configs;
            }

            public function calculateDataQuality(
                AbstractObject $dataObject,
                DataQualityConfig $dataQualityConfig,
                bool $persist = true,
                bool $useFastPath = true,
            ): DataQualityViewModel {
                ($this->onCalculate)($dataQualityConfig);

                return new DataQualityViewModel('test', null, []);
            }
        };
    }
}
