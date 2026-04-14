<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasks\Contract\TaskResult;
use Soviann\DeployTasks\RunResult;
use Soviann\DeployTasks\TaskRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(name: 'deploytasks:run', description: 'Execute all pending deploy tasks in order.')]
final class DeployTasksRunCommand extends Command
{
    public function __construct(
        private readonly TaskRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview which tasks would run without executing them.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force re-execution of all tasks regardless of their current state.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Target a single task by its ID.')
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command executes all pending deploy tasks:

                    <info>%command.full_name%</info>

                You can preview which tasks would be executed with <comment>--dry-run</comment>:

                    <info>%command.full_name% --dry-run</info>

                To force re-execution of all tasks regardless of state:

                    <info>%command.full_name% --force</info>

                To run a single task by its ID (only if pending):

                    <info>%command.full_name% --id=task_20260412143000_seed_categories</info>

                To force re-execution of a single task:

                    <info>%command.full_name% --force --id=task_20260412143000_seed_categories</info>

                Tasks are executed in priority order (highest first), then by date extracted
                from the task ID (oldest first). A lock prevents concurrent execution when
                symfony/lock is installed.
                EOT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        /** @var string|null $taskId */
        $taskId = $input->getOption('id');

        if (null !== $taskId) {
            $taskResult = $this->runner->runOne($taskId, $output, force: $force);

            if (TaskResult::FAILURE === $taskResult) {
                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $result = $this->runner->runAll($output, dryRun: $dryRun, force: $force);

        $this->writeSummary($io, $result, $dryRun);

        return $result->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }

    private function writeSummary(SymfonyStyle $io, RunResult $result, bool $dryRun): void
    {
        if ($result->locked) {
            $io->warning('Run skipped: another process is already running.');

            return;
        }

        $summary = \sprintf(
            'Tasks: %d %s, %d skipped, %d failed.',
            $result->ran,
            $dryRun ? 'pending' : 'ran',
            $result->skipped,
            $result->failed,
        );

        if (!$result->isSuccessful()) {
            $io->error($summary);

            return;
        }

        if (0 === $result->ran && 0 === $result->skipped) {
            $io->success('No deploy tasks registered.');

            return;
        }

        if (0 === $result->ran) {
            $io->success('All tasks already executed — nothing to run.');

            return;
        }

        $io->success($summary);
    }
}
