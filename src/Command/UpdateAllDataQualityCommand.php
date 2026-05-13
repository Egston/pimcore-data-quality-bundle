<?php

namespace Basilicom\DataQualityBundle\Command;

use Pimcore\Console\AbstractCommand;
use Pimcore\Model\DataObject\DataQualityConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateAllDataQualityCommand extends AbstractCommand
{
    protected static $defaultName        = 'dataquality:update-all';
    protected static $defaultDescription = 'Re-compute and update data quality for every DataQualityConfig.';

    protected function configure()
    {
        $this
            ->setAliases(['dq:update-all'])
            ->addArgument(
                'batch-size',
                InputArgument::REQUIRED,
                'Number of objects to process in one batch process (forwarded to dataquality:update).'
            )
            ->addOption(
                'include-unpublished',
                null,
                InputOption::VALUE_NONE,
                'Include unpublished DataQualityConfig objects (default: published only).'
            )
            ->addOption(
                'only',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Restrict to these DataQualityConfig IDs (repeatable). Useful for retries.'
            )
            ->addOption(
                'skip',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Skip these DataQualityConfig IDs (repeatable). Useful for known-broken configs.'
            )
            ->addOption(
                'fail-fast',
                null,
                InputOption::VALUE_NONE,
                'Stop on the first failing config (default: continue and report failures at the end).'
            );
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $batchSize = (int)$input->getArgument('batch-size');
        if ($batchSize <= 0) {
            $output->writeln('<error>batch-size must be a positive integer.</error>');

            return Command::FAILURE;
        }

        $includeUnpublished = (bool)$input->getOption('include-unpublished');
        $failFast           = (bool)$input->getOption('fail-fast');

        // Validate --only / --skip before any work begins
        $rawOnly = (array)$input->getOption('only');
        foreach ($rawOnly as $val) {
            if (!ctype_digit((string)$val)) {
                $output->writeln(sprintf('<error>--only expects integer DataQualityConfig IDs, got %s</error>', var_export($val, true)));

                return Command::FAILURE;
            }
        }
        $only = array_map('intval', $rawOnly);

        $rawSkip = (array)$input->getOption('skip');
        foreach ($rawSkip as $val) {
            if (!ctype_digit((string)$val)) {
                $output->writeln(sprintf('<error>--skip expects integer DataQualityConfig IDs, got %s</error>', var_export($val, true)));

                return Command::FAILURE;
            }
        }
        $skip = array_map('intval', $rawSkip);

        // Guard against missing DataQualityConfig class (bundle not installed)
        if (!class_exists(DataQualityConfig::class)) {
            $output->writeln('<error>DataQualityConfig class is not installed. Run: bin/console pimcore:bundle:install DataQualityBundle</error>');

            return Command::FAILURE;
        }

        // When --only is set, resolve configs directly so unpublished IDs are reachable
        if (!empty($only)) {
            $configs = [];
            foreach ($only as $id) {
                if (in_array($id, $skip, true)) {
                    continue;
                }
                $config = DataQualityConfig::getById($id);
                if (!$config instanceof DataQualityConfig) {
                    $output->writeln(sprintf('<error>DataQualityConfig #%d not found.</error>', $id));

                    return Command::FAILURE;
                }
                $configs[] = $config;
            }

            if (empty($configs)) {
                $output->writeln('<comment>No DataQualityConfig objects matched.</comment>');

                return Command::SUCCESS;
            }
        } else {
            // Separate "nothing exists" from "filter eliminated everything" so the caller gets a clear signal
            $allConfigs = $this->loadPublishedListing($includeUnpublished);
            if (empty($allConfigs)) {
                $output->writeln('<comment>No DataQualityConfig objects exist.</comment>');

                return Command::SUCCESS;
            }

            $configs = array_values(array_filter($allConfigs, function (DataQualityConfig $c) use ($skip) {
                return !in_array((int)$c->getId(), $skip, true);
            }));

            if (empty($configs)) {
                $output->writeln(sprintf(
                    '<error>Filter (--skip) eliminated all %d existing configs.</error>',
                    count($allConfigs)
                ));

                return Command::FAILURE;
            }
        }

        $output->writeln(sprintf('Found <info>%d</info> DataQualityConfig object(s) to process.', count($configs)));

        $consolePath = realpath(PIMCORE_PROJECT_ROOT . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'console');
        if ($consolePath === false) {
            $output->writeln(sprintf('<error>bin/console not found under PIMCORE_PROJECT_ROOT (%s).</error>', PIMCORE_PROJECT_ROOT));

            return Command::FAILURE;
        }

        if (chdir(PIMCORE_PROJECT_ROOT) === false) {
            $output->writeln(sprintf('<error>chdir(%s) failed.</error>', PIMCORE_PROJECT_ROOT));

            return Command::FAILURE;
        }

        $succeeded = [];
        $failed    = [];

        foreach ($configs as $config) {
            $id    = (int)$config->getId();
            $label = sprintf('#%d "%s" (class=%s, field=%s)',
                $id,
                (string)$config->getKey(),
                (string)$config->getDataQualityClass(),
                (string)$config->getDataQualityField()
            );

            $output->writeln(sprintf("\n<info>▶ Updating %s</info>", $label));

            $cmd = sprintf('env php %s dataquality:update %d %d', escapeshellarg($consolePath), $id, $batchSize);
            passthru($cmd, $resultCode);

            if ($resultCode === 0) {
                $succeeded[] = $label;
            } else {
                $reason = $this->formatExitCode($resultCode);
                $output->writeln(sprintf('<error>✗ %s failed (exit %d — %s)</error>', $label, $resultCode, $reason));

                $failed[$label] = $resultCode;

                if ($failFast) {
                    $output->writeln('<error>--fail-fast set; aborting.</error>');

                    break;
                }
            }
        }

        $output->writeln(sprintf("\n<info>Summary:</info> %d succeeded, %d failed.", count($succeeded), count($failed)));
        foreach ($failed as $label => $code) {
            $output->writeln(sprintf('  <error>FAIL</error> exit=%d (%s) %s', $code, $this->formatExitCode($code), $label));
        }

        return empty($failed) ? Command::SUCCESS : Command::FAILURE;
    }

    private function formatExitCode(int $code): string
    {
        if ($code === 127) {
            return 'shell could not launch php (PATH / env issue)';
        }
        if ($code === 1) {
            return 'child process exited with error (see output above)';
        }
        if ($code > 128) {
            return sprintf('killed by signal %d (likely OOM if 137)', $code - 128);
        }

        return 'unknown failure mode';
    }

    /**
     * @param bool $includeUnpublished
     *
     * @return DataQualityConfig[]
     */
    private function loadPublishedListing(bool $includeUnpublished): array
    {
        $listing = new DataQualityConfig\Listing();
        if ($includeUnpublished) {
            $listing->setUnpublished(true);
        }
        $listing->setOrderKey('oo_id');
        $listing->setOrder('asc');

        return iterator_to_array($listing, false);
    }
}
