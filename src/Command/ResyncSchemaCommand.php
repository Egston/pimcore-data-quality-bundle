<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Command;

use Basilicom\DataQualityBundle\Tools\Installer;
use Pimcore\Console\AbstractCommand;
use Pimcore\Extension\Bundle\Installer\Exception\InstallationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ResyncSchemaCommand extends AbstractCommand
{
    protected static $defaultName        = 'dataquality:resync-schema';
    protected static $defaultDescription = 'Re-import the bundle\'s class + fieldcollection install JSONs onto the running Pimcore instance.';

    public function __construct(private readonly Installer $installer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
Re-imports the JSON definitions shipped under <info>src/Resources/install/</info>
onto the running Pimcore instance. Use after pulling bundle changes that add
or remove columns on the <info>DataQualityConfig</info> class or the
<info>DataQualityFieldDefinition</info> fieldcollection — the underlying
<info>Definition::save()</info> runs the necessary <info>ALTER TABLE</info>
DDL and regenerates the generated PHP classes under <info>var/classes/</info>.

Existing rows receive <info>NULL</info> for any newly-added nullable columns.

After running this command, run a Symfony cache clear + warmup and a DataHub
GraphQL cache reset to pick up the regenerated PHP classes.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->installer->resyncSchema();
        } catch (InstallationException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        $output->write($this->installer->getOutput()->fetch());
        $output->writeln('<info>Schema re-sync complete. Clear the Symfony + DataHub caches to pick up regenerated PHP classes.</info>');

        return Command::SUCCESS;
    }
}
