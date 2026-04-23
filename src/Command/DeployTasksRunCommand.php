<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Exception\TaskGroupMismatchException;
use Soviann\DeployTasksBundle\Exception\TaskGroupRequiredException;
use Soviann\DeployTasksBundle\Runner\RunResult;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\Runner\TaskRunner;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(name: 'deploytasks:run', description: 'Execute pending deploy tasks in order.')]
final class DeployTasksRunCommand extends Command
{
    /**
     * Exit code returned when the run lock is already held by another process.
     * Signals "temporary failure — retry recommended" (POSIX EX_TEMPFAIL, sysexits.h 75).
     */
    public const int EX_TEMPFAIL = 75;

    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly TaskRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview which tasks would run without executing them.')
            ->addOption('rerun-all', null, InputOption::VALUE_NONE, 'Re-execute all tasks regardless of their current state.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Deprecated alias for --rerun-all. Use --rerun-all instead.')
            ->addOption('group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run tasks declaring this group (repeatable). Without --group, only ungrouped tasks run.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Target a single task by its ID.')
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command executes pending deploy tasks:

                    <info>%command.full_name%</info>

                Without <comment>--group</comment>, only ungrouped tasks run. Use <comment>--group</comment>
                (repeatable) to run tasks declaring any of the listed groups:

                    <info>%command.full_name% --group=predeploy</info>
                    <info>%command.full_name% --group=predeploy --group=postdeploy</info>

                A task declared in multiple groups runs once per invocation and writes
                one storage row per matching slot, so the same task can run again in a
                different group on a later invocation.

                You can preview which tasks would be executed with <comment>--dry-run</comment>:

                    <info>%command.full_name% --dry-run</info>
                    <info>%command.full_name% --dry-run --group=postdeploy</info>

                To re-execute all matching tasks regardless of state:

                    <info>%command.full_name% --rerun-all</info>

                To run a single task by its ID (only if pending):

                    <info>%command.full_name% --id=task_20260412143000_seed_categories</info>

                When a task declares groups, <comment>--id</comment> must be combined with
                <comment>--group</comment> to select which slot(s) to record:

                    <info>%command.full_name% --id=my.task --group=predeploy</info>

                Tasks are executed in priority order (highest first), then by date extracted
                from the task ID (oldest first). A lock prevents concurrent execution when
                symfony/lock is installed.
                EOT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $forceLegacy = (bool) $input->getOption('force');
        $rerunAll = (bool) $input->getOption('rerun-all');

        if ($forceLegacy && !$rerunAll) {
            $io->warning('The --force option is deprecated; use --rerun-all.');
        }

        $force = $rerunAll || $forceLegacy;

        /** @var string|null $taskId */
        $taskId = $input->getOption('id');

        /** @var list<string> $groups */
        $groups = \array_values((array) $input->getOption('group'));

        if (null !== $taskId) {
            return $this->executeOne($io, $output, $taskId, $groups, $force);
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $result = $this->runner->runAll($output, dryRun: $dryRun, force: $force, groups: $groups);

        $this->writeSummary($io, $result, $dryRun, [] !== $groups);

        if ($result->locked) {
            return self::EX_TEMPFAIL;
        }

        return $result->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param list<string> $groups
     */
    private function executeOne(SymfonyStyle $io, OutputInterface $output, string $taskId, array $groups, bool $force): int
    {
        if (!$this->registry->has($taskId)) {
            $io->error(\sprintf(CommandMessages::UNKNOWN_TASK, $taskId));

            return Command::FAILURE;
        }

        try {
            $taskResult = $this->runner->runOne($taskId, $output, force: $force, groups: $groups);
        } catch (TaskGroupRequiredException|TaskGroupMismatchException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        if (TaskResult::LOCKED === $taskResult) {
            $io->warning('Run skipped: another process is already running.');

            return self::EX_TEMPFAIL;
        }

        return TaskResult::FAILURE === $taskResult ? Command::FAILURE : Command::SUCCESS;
    }

    private function writeSummary(SymfonyStyle $io, RunResult $result, bool $dryRun, bool $groupFilterActive): void
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
            $io->success($groupFilterActive
                ? 'No tasks matched the requested group(s).'
                : 'No deploy tasks registered.');

            return;
        }

        if (0 === $result->ran) {
            $io->success('All tasks already executed — nothing to run.');

            return;
        }

        $io->success($summary);
    }
}
