<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Command;

use Basilicom\DataQualityBundle\Command\UpdateDataQualityCommand;
use Basilicom\DataQualityBundle\Definition\DefinitionInterface;
use Basilicom\DataQualityBundle\Definition\DependentDefinition;
use Basilicom\DataQualityBundle\Definition\LocalizedAwareDefinition;
use Basilicom\DataQualityBundle\Definition\RuleContext;
use Basilicom\DataQualityBundle\DefinitionsCollection\Factory\FieldDefinitionFactory;
use Basilicom\DataQualityBundle\Registry\RuleRegistry;
use Basilicom\DataQualityBundle\Service\DataQualityService;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\DataQualityConfig;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Fieldcollection\Data\DataQualityFieldDefinition;

/**
 * Pins the single-config refusal logic in `UpdateDataQualityCommand`:
 * when a config has a `DependentDefinition` rule whose dependency is not
 * satisfied, the command must refuse with a concrete human-readable
 * message. Exercised via reflection on `unsatisfiedDependencies()` and
 * a `findProducer()` seam-override to stay kernel-free.
 */
final class UpdateDataQualityCommandUnsatisfiedDependencyTest extends TestCase
{
    public function test_single_config_with_missing_producer_returns_failure_message(): void
    {
        $consumer = $this->consumerConfig(
            id: 99,
            classId: 'ResourceLibraryItem',
            field: 'blend',
            depClass: 'ResourceLibraryItem',
            depColumn: 'fillScore',
        );

        $command = $this->commandWithProducer(null);
        $messages = $this->callUnsatisfied($command, $consumer);

        self::assertNotEmpty($messages, 'must refuse when producer is missing');
        self::assertStringContainsString('fillScore', $messages[0]);
        self::assertStringContainsString('no DataQualityConfig produces it', $messages[0]);
    }

    public function test_single_config_with_existing_producer_names_producer_in_message(): void
    {
        $consumer = $this->consumerConfig(
            id: 99,
            classId: 'ResourceLibraryItem',
            field: 'blend',
            depClass: 'ResourceLibraryItem',
            depColumn: 'fillScore',
        );
        $producer = $this->bareConfig(id: 10, classId: 'ResourceLibraryItem', field: 'fillScore');

        $command = $this->commandWithProducer($producer);
        $messages = $this->callUnsatisfied($command, $consumer);

        self::assertNotEmpty($messages, 'must warn when producer exists but ordering not guaranteed');
        self::assertStringContainsString('fillScore', $messages[0]);
        self::assertStringContainsString('10', $messages[0], 'message must name the producer config ID');
        self::assertStringContainsString('dataquality:update-all', $messages[0]);
    }

    public function test_config_without_dependent_rules_returns_no_messages(): void
    {
        $config = $this->bareConfig(id: 5, classId: 'ResourceLibraryItem', field: 'score');
        $command = $this->commandWithProducer(null);

        $messages = $this->callUnsatisfied($command, $config);

        self::assertSame([], $messages);
    }

    private function consumerConfig(
        int $id,
        string $classId,
        string $field,
        string $depClass,
        string $depColumn,
    ): DataQualityConfig {
        $rule = new class ($depClass, $depColumn) implements DefinitionInterface, LocalizedAwareDefinition, DependentDefinition {
            public function __construct(
                private readonly string $depClass,
                private readonly string $depColumn,
            ) {}

            public function validate($content, Data $fieldDefinition, array $parameters, RuleContext $context): bool
            {
                return true;
            }

            public function getNecessaryParameterCount(): int
            {
                return 0;
            }

            public function dependsOnColumns(array $parameters, DataQualityConfig $config): array
            {
                return [['class' => $this->depClass, 'column' => $this->depColumn]];
            }

            public function skipFromScore(): bool
            {
                return false;
            }
        };

        $registry = new RuleRegistry();
        $registry->register('test-dep-rule', $rule);
        $this->lastRegistry = $registry;

        $item = new DataQualityFieldDefinition();
        $item->setField('headline');
        $item->setCondition('test-dep-rule');
        $item->setParameters('');
        $item->setWeight(1.0);

        $fc = new Fieldcollection();
        $fc->add($item);

        $config = (new \ReflectionClass(DataQualityConfig::class))->newInstanceWithoutConstructor();
        $this->setId($config, $id);
        $config->setDataQualityClass($classId);
        $config->setDataQualityField($field);
        $config->setDataQualityName(sprintf('Consumer #%d', $id));
        $config->setDataQualityRules($fc);

        return $config;
    }

    private RuleRegistry $lastRegistry;

    private function bareConfig(int $id, string $classId, string $field): DataQualityConfig
    {
        $config = (new \ReflectionClass(DataQualityConfig::class))->newInstanceWithoutConstructor();
        $this->setId($config, $id);
        $config->setDataQualityClass($classId);
        $config->setDataQualityField($field);
        $config->setDataQualityName(sprintf('Producer #%d', $id));
        $config->setDataQualityRules(new Fieldcollection());

        return $config;
    }

    private function commandWithProducer(?DataQualityConfig $producer): UpdateDataQualityCommand
    {
        $service = (new \ReflectionClass(DataQualityService::class))->newInstanceWithoutConstructor();
        $factory = new FieldDefinitionFactory($this->lastRegistry ?? new RuleRegistry());

        return new class ($service, $factory, $producer) extends UpdateDataQualityCommand {
            public function __construct(
                DataQualityService $service,
                FieldDefinitionFactory $factory,
                private readonly ?DataQualityConfig $producer,
            ) {
                parent::__construct($service, $factory);
            }

            protected function findProducer(string $classId, string $column): ?DataQualityConfig
            {
                return $this->producer;
            }
        };
    }

    /**
     * @return string[]
     */
    private function callUnsatisfied(UpdateDataQualityCommand $command, DataQualityConfig $config): array
    {
        $method = new \ReflectionMethod(UpdateDataQualityCommand::class, 'unsatisfiedDependencies');
        $method->setAccessible(true);

        return $method->invoke($command, $config);
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
