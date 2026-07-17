<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorageConfiguration;
use Soviann\DeployTasksBundle\Storage\SchemaManageableInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(name: 'deploytasks:create-schema', description: 'Create the deploy tasks storage schema.')]
final class DeployTasksCreateSchemaCommand extends Command
{
    /**
     * $configuration and $connectionName are wired together for the built-in
     * database storage only, so the success message can name the table, columns,
     * and connection. Any other SchemaManageableInterface backend leaves both
     * null and gets a generic success message.
     */
    public function __construct(
        private readonly SchemaManageableInterface $storage,
        private readonly ?DbalStorageConfiguration $configuration = null,
        private readonly ?string $connectionName = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Output the SQL statement instead of executing it.')
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command provisions the schema of the configured storage
                backend — for the built-in database storage, the table used by the DBAL backend:

                    <info>%command.full_name%</info>

                It is registered whenever the configured storage implements
                <comment>SchemaManageableInterface</comment>. Implementations are idempotent
                (e.g. <comment>CREATE TABLE IF NOT EXISTS</comment>), so re-running it is safe.

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

        if (null === $this->configuration || null === $this->connectionName) {
            $io->info('Storage schema was created.');

            return Command::SUCCESS;
        }

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
