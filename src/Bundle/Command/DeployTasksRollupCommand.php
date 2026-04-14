<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasks\Contract\TaskExecution;
use Soviann\DeployTasks\Contract\TaskStatus;
use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Soviann\DeployTasks\Contract\TransactionalStorageInterface;
use Soviann\DeployTasks\TaskRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(name: 'deploytasks:rollup', description: 'Clear execution history and mark all registered tasks as ran.')]
final class DeployTasksRollupCommand extends Command
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
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command clears all execution records and marks every registered task as ran, establishing the current codebase as the baseline:

                    <info>%command.full_name%</info>

                This is useful for fresh environments where the current state already incorporates all task effects, or for cleaning up stale execution history after old tasks have been removed.

                You will be prompted for confirmation. To skip the prompt (e.g. in CI), use <comment>--no-interaction</comment>:

                    <info>%command.full_name% --no-interaction</info>

                To see available task IDs and their current state, use <info>deploytasks:status</info>.
                EOT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tasks = $this->registry->all();
        $existingRecords = $this->storage->all();

        if ([] === $tasks) {
            $io->warning('No tasks registered.');

            return Command::SUCCESS;
        }

        $io->text(\sprintf('%d task(s) registered, %d execution record(s) in storage.', \count($tasks), \count($existingRecords)));

        if (!$io->confirm(\sprintf('This will clear all execution records and mark all %d task(s) as ran. Continue?', \count($tasks)))) {
            $io->note('Aborted.');

            return Command::SUCCESS;
        }

        $taskIds = \array_keys($tasks);
        $rollup = function () use ($taskIds): void {
            $this->storage->reset();

            $now = new \DateTimeImmutable();

            foreach ($taskIds as $id) {
                $this->storage->save(new TaskExecution($id, TaskStatus::Ran, $now));
            }
        };

        if ($this->storage instanceof TransactionalStorageInterface) {
            $this->storage->transactional($rollup);
        } else {
            $rollup();
        }

        $io->success(\sprintf('Rolled up: cleared %d record(s), marked %d task(s) as ran.', \count($existingRecords), \count($tasks)));

        return Command::SUCCESS;
    }
}
