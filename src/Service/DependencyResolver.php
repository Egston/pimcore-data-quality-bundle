<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Service;

use Basilicom\DataQualityBundle\Definition\DependentDefinition;
use Basilicom\DataQualityBundle\DefinitionsCollection\Factory\FieldDefinitionFactory;
use Basilicom\DataQualityBundle\Exception\DataQualityException;
use Pimcore\Model\DataObject\DataQualityConfig;
use Pimcore\Model\DataObject\Fieldcollection\Data\DataQualityFieldDefinition;

/**
 * Topologically sorts a `DataQualityConfig[]` set so producer configs
 * run before consumer configs that read their output columns via
 * `DependentDefinition`. Pure-PHP Kahn's algorithm; must remain kernel-free
 * so tests instantiate without a Symfony container.
 *
 * Determinism: the "ready" queue is sorted by config ID ascending at
 * every iteration so the same input always produces the same output
 * across PHP versions / opcache states.
 *
 * Failure modes (all `DataQualityException`):
 *   - cycle detected after Kahn's relaxation finishes with unprocessed
 *     nodes — the message names every config still pending plus its
 *     unresolved `(class, column)` dependencies;
 *   - two configs in the input set produce the same `(class, column)` —
 *     the `(class, column)` pair must uniquely identify a producer;
 *   - a `DependentDefinition` row references a `(class, column)` not
 *     satisfied by any other config in the input set.
 *
 * Configs whose rules include no `DependentDefinition` pass through in
 * input order (no-op). This is the common case today; only the headline
 * `WeightedColumnBlend` rule activates the sort.
 */
final class DependencyResolver
{
    public function __construct(
        private readonly FieldDefinitionFactory $fieldDefinitionFactory,
    ) {}

    /**
     * @param DataQualityConfig[] $configs
     *
     * @return DataQualityConfig[] Sorted producers-first.
     *
     * @throws DataQualityException
     */
    public function sort(array $configs): array
    {
        if ($configs === []) {
            return [];
        }

        $producers = [];
        foreach ($configs as $config) {
            $key = $this->producerKey((string) $config->getDataQualityClass(), (string) $config->getDataQualityField());
            if (isset($producers[$key])) {
                throw new DataQualityException(sprintf(
                    'Two DataQualityConfigs produce the same (class=%s, column=%s) pair: #%s and #%s. The (class, column) pair must uniquely identify a producer.',
                    (string) $config->getDataQualityClass(),
                    (string) $config->getDataQualityField(),
                    (string) ($producers[$key]->getId() ?? 'unsaved'),
                    (string) ($config->getId() ?? 'unsaved'),
                ));
            }
            $producers[$key] = $config;
        }

        $dependsOn = [];
        foreach ($configs as $config) {
            $cid = (int) $config->getId();
            $deps = $this->dependenciesFor($config);
            foreach ($deps as $dep) {
                $depKey = $this->producerKey($dep['class'], $dep['column']);
                if (!isset($producers[$depKey])) {
                    throw new DataQualityException(sprintf(
                        'DataQualityConfig #%d "%s" depends on column "%s" on class "%s", but no config in the current set produces it. Add the producing config to the run or check the column name.',
                        $cid,
                        (string) ($config->getDataQualityName() ?? ''),
                        $dep['column'],
                        $dep['class'],
                    ));
                }
                $producerId = (int) $producers[$depKey]->getId();
                if ($producerId === $cid) {
                    throw new DataQualityException(sprintf(
                        'DataQualityConfig #%d "%s" depends on its own output column (class=%s, column=%s); self-reference detected.',
                        $cid,
                        (string) ($config->getDataQualityName() ?? ''),
                        $dep['class'],
                        $dep['column'],
                    ));
                }
                $dependsOn[$cid][$producerId] = true;
            }
        }

        $inDegree = [];
        $byId = [];
        foreach ($configs as $config) {
            $cid = (int) $config->getId();
            $byId[$cid] = $config;
            $inDegree[$cid] = isset($dependsOn[$cid]) ? count($dependsOn[$cid]) : 0;
        }

        $dependents = [];
        foreach ($dependsOn as $cid => $deps) {
            foreach (array_keys($deps) as $producerId) {
                $dependents[$producerId][$cid] = true;
            }
        }

        $ready = [];
        foreach ($inDegree as $cid => $deg) {
            if ($deg === 0) {
                $ready[] = $cid;
            }
        }

        $ordered = [];
        while ($ready !== []) {
            sort($ready);
            $cid = array_shift($ready);
            $ordered[] = $byId[$cid];

            foreach (array_keys($dependents[$cid] ?? []) as $dependentId) {
                $inDegree[$dependentId]--;
                if ($inDegree[$dependentId] === 0) {
                    $ready[] = $dependentId;
                }
            }
        }

        if (count($ordered) !== count($configs)) {
            $cyclic = [];
            foreach ($inDegree as $cid => $deg) {
                if ($deg > 0) {
                    $config = $byId[$cid];
                    $deps = $this->dependenciesFor($config);
                    $depStrs = array_map(
                        static fn(array $d): string => sprintf('(%s, %s)', $d['class'], $d['column']),
                        $deps,
                    );
                    $cyclic[] = sprintf(
                        '#%d "%s" -> [%s]',
                        $cid,
                        (string) ($config->getDataQualityName() ?? ''),
                        implode(', ', $depStrs),
                    );
                }
            }
            throw new DataQualityException(sprintf(
                'Cycle detected in DataQualityConfig dependencies: %s',
                implode('; ', $cyclic),
            ));
        }

        return $ordered;
    }

    /**
     * @return array<int, array{class: string, column: string}>
     *
     * @throws DataQualityException
     */
    private function dependenciesFor(DataQualityConfig $config): array
    {
        $deps = [];
        $fieldCollection = $config->getDataQualityRules();
        if ($fieldCollection === null) {
            return $deps;
        }

        /** @var DataQualityFieldDefinition $item */
        foreach ($fieldCollection->getItems() as $item) {
            try {
                $fieldDef = $this->fieldDefinitionFactory->get($item);
            } catch (\Throwable $e) {
                throw new DataQualityException(sprintf(
                    'DataQualityConfig #%s "%s": failed to resolve rule "%s": %s',
                    (string) ($config->getId() ?? 'unsaved'),
                    (string) ($config->getDataQualityName() ?? ''),
                    (string) $item->getCondition(),
                    $e->getMessage(),
                ), 0, $e);
            }

            $rule = $fieldDef->getConditionClass();
            if (!$rule instanceof DependentDefinition) {
                continue;
            }

            try {
                foreach ($rule->dependsOnColumns($fieldDef->getParameters(), $config) as $entry) {
                    $deps[] = $entry;
                }
            } catch (\Throwable $e) {
                throw new DataQualityException(sprintf(
                    'DataQualityConfig #%s "%s": dependsOnColumns() failed on rule "%s": %s',
                    (string) ($config->getId() ?? 'unsaved'),
                    (string) ($config->getDataQualityName() ?? ''),
                    $rule::class,
                    $e->getMessage(),
                ), 0, $e);
            }
        }

        return $deps;
    }

    private function producerKey(string $class, string $column): string
    {
        return $class . "\0" . $column;
    }
}
