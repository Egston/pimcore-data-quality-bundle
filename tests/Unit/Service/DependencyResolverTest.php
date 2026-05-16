<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Service;

use Basilicom\DataQualityBundle\Definition\DefinitionInterface;
use Basilicom\DataQualityBundle\Definition\DependentDefinition;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Basilicom\DataQualityBundle\Definition\WeightedColumnBlend;
use Basilicom\DataQualityBundle\DefinitionsCollection\Factory\FieldDefinitionFactory;
use Basilicom\DataQualityBundle\Exception\DataQualityException;
use Basilicom\DataQualityBundle\Registry\RuleRegistry;
use Basilicom\DataQualityBundle\Service\DependencyResolver;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\DataQualityConfig;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Fieldcollection\Data\DataQualityFieldDefinition;

final class DependencyResolverTest extends TestCase
{
    public function test_configs_with_no_dependent_rules_pass_through_in_input_order(): void
    {
        $resolver = $this->resolver();

        $a = $this->producerConfig(id: 10, classId: 'ResourceLibraryItem', field: 'fillScore');
        $b = $this->producerConfig(id: 20, classId: 'ResourceLibraryItem', field: 'authenticityScore');
        $c = $this->producerConfig(id: 30, classId: 'ResourceLibraryItem', field: 'verifiedScore');

        $sorted = $resolver->sort([$a, $b, $c]);

        self::assertSame([10, 20, 30], array_map(static fn($c) => (int) $c->getId(), $sorted));
    }

    public function test_dag_sorts_producers_before_consumer(): void
    {
        $resolver = $this->resolver();

        $producerA = $this->producerConfig(id: 10, classId: 'ResourceLibraryItem', field: 'fillScore');
        $producerB = $this->producerConfig(id: 20, classId: 'ResourceLibraryItem', field: 'authenticityScore');
        $producerC = $this->producerConfig(id: 30, classId: 'ResourceLibraryItem', field: 'verifiedScore');
        $consumer = $this->blendConfig(
            id: 99,
            classId: 'ResourceLibraryItem',
            field: 'dataQualityPercentage',
            columns: 'fillScore:0.4;authenticityScore:0.3;verifiedScore:0.3',
        );

        // Pass consumer first to prove the sort moves it last.
        $sorted = $resolver->sort([$consumer, $producerC, $producerA, $producerB]);

        $ids = array_map(static fn($c) => (int) $c->getId(), $sorted);
        self::assertSame([99], array_slice($ids, -1), 'consumer must land last');
        self::assertSame([10, 20, 30], array_slice($ids, 0, 3), 'producers sort by id ascending');
    }

    public function test_self_loop_throws_data_quality_exception(): void
    {
        $resolver = $this->resolver();

        // A config whose blend depends on its own column (class+field = its
        // own (class, dataQualityField)).
        $self = $this->blendConfig(
            id: 42,
            classId: 'ResourceLibraryItem',
            field: 'fillScore',
            columns: 'fillScore:1.0',
        );

        $this->expectException(DataQualityException::class);
        $this->expectExceptionMessage('self-reference');

        $resolver->sort([$self]);
    }

    public function test_two_node_cycle_throws_data_quality_exception(): void
    {
        $resolver = $this->resolver();

        $a = $this->blendConfig(id: 100, classId: 'C', field: 'a', columns: 'b:1.0');
        $b = $this->blendConfig(id: 200, classId: 'C', field: 'b', columns: 'a:1.0');

        $this->expectException(DataQualityException::class);
        $this->expectExceptionMessage('Cycle');

        $resolver->sort([$a, $b]);
    }

    public function test_three_node_cycle_throws_data_quality_exception(): void
    {
        $resolver = $this->resolver();

        $a = $this->blendConfig(id: 1, classId: 'C', field: 'a', columns: 'b:1.0');
        $b = $this->blendConfig(id: 2, classId: 'C', field: 'b', columns: 'c:1.0');
        $c = $this->blendConfig(id: 3, classId: 'C', field: 'c', columns: 'a:1.0');

        $this->expectException(DataQualityException::class);
        $this->expectExceptionMessage('Cycle');

        $resolver->sort([$a, $b, $c]);
    }

    public function test_duplicate_producer_class_column_pair_throws(): void
    {
        $resolver = $this->resolver();

        $a = $this->producerConfig(id: 10, classId: 'C', field: 'score');
        $b = $this->producerConfig(id: 20, classId: 'C', field: 'score');

        $this->expectException(DataQualityException::class);
        $this->expectExceptionMessage('uniquely identify a producer');

        $resolver->sort([$a, $b]);
    }

    public function test_missing_producer_for_consumer_dependency_throws(): void
    {
        $resolver = $this->resolver();

        $consumer = $this->blendConfig(id: 99, classId: 'C', field: 'headline', columns: 'absent:1.0');

        $this->expectException(DataQualityException::class);
        $this->expectExceptionMessage('absent');
        $this->expectExceptionMessage('no config in the current set produces it');

        $resolver->sort([$consumer]);
    }

    public function test_empty_input_returns_empty_array(): void
    {
        self::assertSame([], $this->resolver()->sort([]));
    }

    public function test_sort_is_deterministic_across_repeated_calls(): void
    {
        $resolver = $this->resolver();
        $configs = [
            $this->producerConfig(id: 30, classId: 'C', field: 'c'),
            $this->producerConfig(id: 10, classId: 'C', field: 'a'),
            $this->producerConfig(id: 20, classId: 'C', field: 'b'),
            $this->blendConfig(id: 99, classId: 'C', field: 'head', columns: 'a:0.5;b:0.5'),
        ];

        $first = $resolver->sort($configs);
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(
                array_map(static fn($c) => (int) $c->getId(), $first),
                array_map(static fn($c) => (int) $c->getId(), $resolver->sort($configs)),
                'repeated sort must yield identical order',
            );
        }
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
        $this->setIdViaReflection($config, $id);
        $config->setDataQualityClass($classId);
        $config->setDataQualityField($field);
        $config->setDataQualityName(sprintf('Producer #%d', $id));
        $config->setDataQualityRules(new Fieldcollection());

        return $config;
    }

    private function blendConfig(int $id, string $classId, string $field, string $columns): DataQualityConfig
    {
        $config = (new \ReflectionClass(DataQualityConfig::class))->newInstanceWithoutConstructor();
        $this->setIdViaReflection($config, $id);
        $config->setDataQualityClass($classId);
        $config->setDataQualityField($field);
        $config->setDataQualityName(sprintf('Blend #%d', $id));

        $item = new DataQualityFieldDefinition();
        $item->setField('headline');
        $item->setCondition('Weighted Column Blend');
        $item->setParameters('columns=' . $columns);
        $item->setWeight(1);

        // The factory parses parameters via the positional `;`-CSV parser
        // which yields indexed entries — but DependencyResolver receives
        // the parsed `array` shape that the rule body actually walks. The
        // named-param form for the `columns=` parameter is the runtime
        // shape; the resolver in turn invokes
        // `FieldDefinitionFactory::get($item)` which returns the
        // FieldDefinition with a parsed-parameters array. To exercise the
        // rule's parser the fixture passes the raw string through a
        // factory that parses it into the expected associative shape.
        $fc = new Fieldcollection();
        $fc->add($item);
        $config->setDataQualityRules($fc);

        return $config;
    }

    private function setIdViaReflection(DataQualityConfig $config, int $id): void
    {
        $reflection = new \ReflectionClass($config);
        while ($reflection !== false && !$reflection->hasProperty('o_id') && !$reflection->hasProperty('id')) {
            $reflection = $reflection->getParentClass();
        }
        if ($reflection === false) {
            throw new \LogicException('Cannot locate id property on DataQualityConfig stub.');
        }
        $prop = $reflection->hasProperty('id') ? $reflection->getProperty('id') : $reflection->getProperty('o_id');
        $prop->setAccessible(true);
        $prop->setValue($config, $id);
    }
}
