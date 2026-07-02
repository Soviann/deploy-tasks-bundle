<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorageConfiguration;
use Soviann\DeployTasksBundle\Storage\SchemaManageable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(name: 'deploytasks:create-schema', description: 'Create the deploy tasks storage table.')]
final class DeployTasksCreateSchemaCommand extends Command
{
    public function __construct(
        private readonly SchemaManageable $storage,
        private readonly DbalStorageConfiguration $configuration,
        private readonly string $connectionName,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Output the SQL statement instead of executing it.')
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command creates the database table used by the DBAL storage backend:

                    <info>%command.full_name%</info>

                If the table already exists, this command is a no-op (<comment>CREATE TABLE IF NOT EXISTS</comment>).

                To output the SQL without executing it (e.g. for use in a Doctrine migration):

                    <info>%command.full_name% --dump-sql</info>
                EOT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (true === $input->getOption('dump-sql')) {
            $output->writeln($this->storage->getCreateTableSql().';');

            return Command::SUCCESS;
        }

        $this->storage->createSchema();

        $columnNames = [
            $this->configuration->errorColumn,
            $this->configuration->executedAtColumn,
            $this->configuration->groupColumn,
            $this->configuration->idColumn,
            $this->configuration->statusColumn,
        ];
        \sort($columnNames);

        $io->info(\sprintf(
            'Storage table "%s" (columns: %s) was created on %s.',
            $this->configuration->tableName,
            \implode(', ', $columnNames),
            $this->connectionName,
        ));

        return Command::SUCCESS;
    }
}
