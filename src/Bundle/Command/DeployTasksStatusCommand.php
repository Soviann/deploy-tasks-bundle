<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasks\Contract\TaskStatus;
use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Soviann\DeployTasks\TaskRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'deploytasks:status', description: 'View the status of all registered deploy tasks.')]
final class DeployTasksStatusCommand extends Command
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
            ->addOption('no-state', null, InputOption::VALUE_NONE, 'Only show task IDs and descriptions, omitting execution state.')
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command displays a table of all registered deploy tasks and their current execution state:

                    <info>%command.full_name%</info>

                To list only task IDs and descriptions (useful for scripting):

                    <info>%command.full_name% --no-state</info>

                Status values:
                  <comment>pending</comment>   — not yet executed
                  <info>ran</info>       — executed successfully
                  <error>failed</error>    — execution failed (will be retried on next run)
                  <comment>skipped</comment>  — manually marked as skipped via <info>deploytasks:skip</info>
                EOT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $noState = (bool) $input->getOption('no-state');
        $tasks = $this->registry->all();

        if ($noState) {
            $headers = ['ID', 'Description'];
        } else {
            $headers = ['ID', 'Description', 'Status', 'Executed At'];
        }

        $rows = [];

        foreach ($tasks as $task) {
            $execution = $this->storage->get($task->getId());

            if ($noState) {
                $rows[] = [
                    $task->getId(),
                    $task->getDescription(),
                ];

                continue;
            }

            if (null === $execution) {
                $status = '<comment>pending</comment>';
                $executedAt = '';
            } else {
                $status = match ($execution->status) {
                    TaskStatus::Ran => '<info>ran</info>',
                    TaskStatus::Failed => '<error>failed</error>',
                    TaskStatus::Skipped => '<comment>skipped</comment>',
                };
                $executedAt = $execution->executedAt->format('Y-m-d H:i:s');
            }

            $rows[] = [
                $task->getId(),
                $task->getDescription(),
                $status,
                $executedAt,
            ];
        }

        $io->table($headers, $rows);
        $io->writeln(\sprintf('%d task(s) registered.', \count($tasks)));

        return Command::SUCCESS;
    }
}
