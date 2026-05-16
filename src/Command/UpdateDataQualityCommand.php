<?php

namespace Basilicom\DataQualityBundle\Command;

use Basilicom\DataQualityBundle\Definition\DependentDefinition;
use Basilicom\DataQualityBundle\DefinitionsCollection\Factory\FieldDefinitionFactory;
use Basilicom\DataQualityBundle\Exception\DataQualityException;
use Basilicom\DataQualityBundle\Exception\NoDataObjectsAvailableException;
use Basilicom\DataQualityBundle\Service\DataQualityService;
use Pimcore\Console\AbstractCommand;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\DataQualityConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateDataQualityCommand extends AbstractCommand
{
    // Symfony clamps exit codes > 255 before exit(), so the sentinel
    // must fit in 0-255 to survive the parent shell boundary.
    public const STOP_CHILD_PROCESS = 87;

    protected static $defaultName        = 'dataquality:update';
    protected static $defaultDescription = 'Re-compute and update data quality on objects.';

    private int $batchSize = 100;

    private DataQualityService $dataQualityService;

    private FieldDefinitionFactory $fieldDefinitionFactory;

    public function __construct(
        DataQualityService $dataQualityService,
        FieldDefinitionFactory $fieldDefinitionFactory
    ) {
        $this->dataQualityService = $dataQualityService;
        $this->fieldDefinitionFactory = $fieldDefinitionFactory;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setAliases(['dq:update'])
            ->addArgument(
                'quality-config-id',
                InputArgument::REQUIRED,
                'Object-ID of a DataQualityConfig'
            )
            ->addArgument(
                'batch-size',
                InputArgument::REQUIRED,
                'Number of object to process in one batch process'
            )
            ->addOption(
                'batch-number',
                null,
                InputOption::VALUE_OPTIONAL,
                'The number of the batch to process.'
            )
            ->addOption(
                'full-save',
                null,
                InputOption::VALUE_NONE,
                'Persist via full DataObject save() instead of the default direct-column UPDATE. Slower, but fires all Pimcore save events (postUpdate etc.).'
            );
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $batchSize = (int) $input->getArgument('batch-size');
            if ($batchSize > 0) {
                $this->batchSize = $batchSize;
            }

            $fullSave        = (bool) $input->getOption('full-save');
            $batchNumber     = (int) $input->getOption('batch-number');
            $qualityConfigId = (int) $input->getArgument('quality-config-id');
            if ($batchNumber === 0) {
                return $this->executeMainProcess($qualityConfigId, $fullSave);
            }

            $this->executeBatchProcess($qualityConfigId, $batchNumber, $fullSave);
        } catch (NoDataObjectsAvailableException $exception) {
            $this->output->writeln('Processing finished.');

            return self::STOP_CHILD_PROCESS;
        } catch (DataQualityException|\Doctrine\DBAL\Exception $exception) {
            $this->output->writeln('Exception: ' . $exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Spawn child processes (commands) to update the DataQuality in batches.
     * The child returns STOP_CHILD_PROCESS for clean completion vs FAILURE for
     * an exception mid-batch; preserve that distinction in the outer exit code.
     */
    protected function executeMainProcess(int $qualityConfigId, bool $fullSave = false): int
    {
        $config = DataQualityConfig::getById($qualityConfigId);
        if ($config instanceof DataQualityConfig) {
            $missing = $this->unsatisfiedDependencies($config);
            if ($missing !== []) {
                foreach ($missing as $msg) {
                    $this->output->writeln(sprintf('<error>%s</error>', $msg));
                }

                return Command::FAILURE;
            }
        }

        $batchNumber = 1;
        do {
            $consolePath = realpath(PIMCORE_PROJECT_ROOT . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'console');
            chdir(PIMCORE_PROJECT_ROOT);

            $commandPrefix  = 'env php ' . escapeshellarg($consolePath);
            $consoleCommand = sprintf(
                '%s --batch-number=%d%s %d %d',
                escapeshellarg($this->getName()),
                $batchNumber,
                $fullSave ? ' --full-save' : '',
                $qualityConfigId,
                $this->batchSize
            );

            // passthru streams stdout; exec would buffer until child exit
            // and make long batches look hung.
            passthru($commandPrefix . ' ' . $consoleCommand, $resultCode);

            $batchNumber++;
        } while ($resultCode == 0);

        return $resultCode === self::STOP_CHILD_PROCESS ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Single-config invocations cannot transparently chase a
     * `DependentDefinition` producer that hasn't been run yet — that
     * would spawn a sibling child unaware to the operator. Refuse with a
     * concrete message naming the missing column. The caller decides
     * whether to run the producer first (`dataquality:update <producerId>`)
     * or to run `dataquality:update-all` which sorts the full set.
     *
     * @return string[] Human-readable refusal messages, empty when no
     *                  `DependentDefinition` dependencies are present.
     */
    private function unsatisfiedDependencies(DataQualityConfig $config): array
    {
        $messages = [];
        $rules = $config->getDataQualityRules();
        if ($rules === null) {
            return $messages;
        }

        $configId = (int) $config->getId();
        $configName = (string) ($config->getDataQualityName() ?? '');

        foreach ($rules->getItems() as $item) {
            try {
                $fieldDef = $this->fieldDefinitionFactory->get($item);
            } catch (\Throwable $e) {
                \Pimcore\Logger::warning(sprintf(
                    'DataQualityBundle: dependency check skipped unresolvable rule "%s" on config #%d "%s": %s',
                    (string) $item->getCondition(),
                    $configId,
                    $configName,
                    $e->getMessage(),
                ), ['exception' => $e]);

                continue;
            }

            $rule = $fieldDef->getConditionClass();
            if (!$rule instanceof DependentDefinition) {
                continue;
            }

            try {
                $deps = $rule->dependsOnColumns($fieldDef->getParameters(), $config);
            } catch (\Throwable $e) {
                $messages[] = sprintf(
                    'DataQualityConfig #%d "%s": rule %s.dependsOnColumns() failed: %s',
                    $configId,
                    $configName,
                    $rule::class,
                    $e->getMessage(),
                );

                continue;
            }

            foreach ($deps as $dep) {
                $producer = $this->findProducer($dep['class'], $dep['column']);
                if ($producer === null) {
                    $messages[] = sprintf(
                        'DataQualityConfig #%d "%s" depends on column "%s" on class "%s", but no DataQualityConfig produces it. Create the producing config before running this one.',
                        $configId,
                        $configName,
                        $dep['column'],
                        $dep['class'],
                    );

                    continue;
                }
                $messages[] = sprintf(
                    'DataQualityConfig #%d "%s" depends on column "%s" on class "%s". Run the producing config (DataQualityConfig #%d "%s") first, or invoke dataquality:update-all which sorts the full set.',
                    $configId,
                    $configName,
                    $dep['column'],
                    $dep['class'],
                    (int) $producer->getId(),
                    (string) ($producer->getDataQualityName() ?? ''),
                );
            }
        }

        return $messages;
    }

    protected function findProducer(string $classId, string $column): ?DataQualityConfig
    {
        $listing = new DataQualityConfig\Listing();
        $listing->setUnpublished(true);
        foreach ($listing as $config) {
            if ((string) $config->getDataQualityClass() === $classId
                && (string) $config->getDataQualityField() === $column
            ) {
                return $config;
            }
        }

        return null;
    }

    /**
     * Update the DataQuality of a batch of objects
     *
     * @throws NoDataObjectsAvailableException
     * @throws DataQualityException
     */
    protected function executeBatchProcess(int $qualityConfigId, int $batchNumber, bool $fullSave = false)
    {
        $mode = $fullSave ? 'full-save' : 'fast-path';
        $this->output->write('Processing batch #' . $batchNumber . ' (' . $mode . ') ... ');

        $offset = ($batchNumber - 1) * $this->batchSize;

        $dataQualityConfig = DataQualityConfig::getById($qualityConfigId);

        if (!is_object($dataQualityConfig)) {
            throw new DataQualityException('The data quality config does not exist.');
        }

        $classId   = $dataQualityConfig->getDataQualityClass();
        $fieldname = $dataQualityConfig->getDataQualityField();
        if (empty($classId) || empty($fieldname)) {
            throw new DataQualityException('The data quality config is not configured correctly. Missing class and field.');
        }

        $class = ClassDefinition::getById($classId);
        if (empty($class)) {
            throw new DataQualityException('The chosen class in the data quality config does not exist.');
        }

        $classListing = '\\Pimcore\\Model\\DataObject\\' . $class->getName() . '\\Listing';
        $list         = new $classListing();
        $list->setObjectTypes([AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_VARIANT]);
        $list->setUnpublished(true);
        $list->setOffset($offset);
        $list->setLimit($this->batchSize);
        $list->setOrderKey('oo_id');
        $list->setOrder('asc');
        $list->load();

        if ($list->getCount() <= 0) {
            throw new NoDataObjectsAvailableException('There are no data objects left.');
        }

        $useFastPath = !$fullSave;
        foreach ($list as $item) {
            try {
                $this->dataQualityService->calculateDataQuality($item, $dataQualityConfig, true, $useFastPath);
            } catch (\Exception $e) {
                throw new DataQualityException(
                    sprintf(
                        'Failed on oo_id=%d (classId=%s): %s',
                        (int) $item->getId(),
                        (string) $item->getClassId(),
                        $e->getMessage()
                    ),
                    0,
                    $e
                );
            }
        }

        $this->output->writeln('OK - ' . (($batchNumber - 1) * $this->batchSize) + $list->getCount());

        if ($list->getCount() < $this->batchSize) {
            throw new NoDataObjectsAvailableException('There are no data objects left.');
        }
    }
}
