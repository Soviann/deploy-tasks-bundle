<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasks\Contract\TaskExecution;
use Soviann\DeployTasks\Contract\TaskStatus;
use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Soviann\DeployTasks\TaskRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(name: 'deploytasks:skip', description: 'Mark a deploy task as skipped without executing it.')]
final class DeployTasksSkipCommand extends Command
{
    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly TaskStorageInterface $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'The deploy task ID to skip (e.g. task_20260412143000_seed_categories).')
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command marks a deploy task as skipped so it will not be executed on future runs:

                    <info>%command.full_name% task_20260412143000_seed_categories</info>

                This is useful when a task is no longer relevant or was handled manually.
                A skipped task can be re-enabled with <info>deploytasks:reset</info>.

                To see available task IDs, use <info>deploytasks:status</info>.
                EOT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $id */
        $id = $input->getArgument('id');

        if (!$this->registry->has($id)) {
            $io->error(\sprintf('Task "%s" is not registered. Run deploytasks:status to see available tasks.', $id));

            return Command::FAILURE;
        }

        $this->storage->save(new TaskExecution($id, TaskStatus::Skipped, new \DateTimeImmutable()));

        $io->success(\sprintf('Task "%s" marked as skipped.', $id));

        return Command::SUCCESS;
    }
}
