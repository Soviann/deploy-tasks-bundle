<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Exception\AllOrNothingFailureException;
use Soviann\DeployTasksBundle\Exception\TaskEnvironmentMismatchException;
use Soviann\DeployTasksBundle\Exception\TaskGroupMismatchException;
use Soviann\DeployTasksBundle\Helper\ConsoleSanitizer;
use Soviann\DeployTasksBundle\Runner\RunOptions;
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
    public const EX_TEMPFAIL = 75;

    /**
     * Exit code returned when --require-some is set but no task matched the provided filters.
     * Signals "command line usage error" (POSIX EX_USAGE, sysexits.h 64).
     */
    public const EX_USAGE = 64;

    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly TaskRunner $runner,
        private readonly bool $lockUnavailable = false,
        /** Mirrors the runner's `kernel.environment` — only used to word the empty-run message. */
        private readonly ?string $environment = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Preview which tasks would run without executing them. Combines with `--id` to preview a single task.',
            )
            ->addOption(
                'rerun-all',
                null,
                InputOption::VALUE_NONE,
                'Re-execute all tasks regardless of their current state.',
            )
            ->addOption(
                'group',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Run tasks declaring this group (repeatable); without this flag every slot runs (default slot and every declared group).',
            )
            ->addOption(
                'id',
                null,
                InputOption::VALUE_REQUIRED,
                'Target a single task by its ID. Combine with `--rerun-all` to re-execute even if already ran.',
            )
            ->addOption(
                'require-some',
                null,
                InputOption::VALUE_NONE,
                'Exit 64 if no task matched the provided filters.',
            )
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command executes pending deploy tasks:

                    <info>%command.full_name%</info>

                Without <comment>--group</comment>, every slot runs — the default slot of ungrouped
                tasks and every declared group of grouped tasks. Use <comment>--group</comment>
                (repeatable) to narrow the run to tasks declaring any of the listed groups:

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

                To run a single task by its ID:

                    <info>%command.full_name% --id=task_20260412143000_seed_categories</info>
                    <info>%command.full_name% --id=task_20260412143000_seed_categories --rerun-all</info>
                    <info>%command.full_name% --id=my.task --dry-run</info>

                When a task declares groups, <comment>--id</comment> without <comment>--group</comment>
                targets every declared slot; add <comment>--group</comment> to narrow the slot(s) to record:

                    <info>%command.full_name% --id=my.task --group=predeploy</info>

                Tasks are executed in priority order (highest first), then by date extracted
                from the task ID (oldest first). A lock prevents concurrent execution when
                symfony/lock is installed.

                To act on a single task without running it, use <info>deploytasks:skip <id></info>
                (mark as skipped) or <info>deploytasks:reset <id></info> (clear execution record).
                EOT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->lockUnavailable) {
            $output->writeln('<comment>Warning: lock.enabled is true but symfony/lock is not installed — concurrent-run protection is inactive.</comment>');
        }

        $rerunAll = (bool) $input->getOption('rerun-all');
        $requireSome = (bool) $input->getOption('require-some');

        /** @var string|null $taskId */
        $taskId = $input->getOption('id');

        /** @var list<string> $groups */
        $groups = \array_values((array) $input->getOption('group'));

        $dryRun = (bool) $input->getOption('dry-run');

        if (null !== $taskId) {
            if ($requireSome && !$this->registry->has($taskId)) {
                $io->error('No task matched the provided filter(s).');

                return self::EX_USAGE;
            }

            return $this->executeOne($io, $output, $taskId, $groups, $rerunAll, $dryRun, $requireSome);
        }

        try {
            $result = $this->runner->runAll($output, new RunOptions(dryRun: $dryRun, rerunAll: $rerunAll, groups: $groups));
        } catch (AllOrNothingFailureException $e) {
            $this->writeRolledBackSummary($io, $e);

            return Command::FAILURE;
        }

        // Derived from the RunResult rather than pre-queried on the registry: the runner
        // owns env/group/slot selection, so only its outcome can say "nothing matched"
        // (a registry pre-count misses the environment filter).
        if (!$result->locked && $requireSome && 0 === $result->ran + $result->skipped + $result->failed) {
            $io->error('No task matched the provided filter(s).');

            return self::EX_USAGE;
        }

        $this->writeSummary($io, $result, [] !== $groups);

        if ($result->locked) {
            return self::EX_TEMPFAIL;
        }

        return $result->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param list<string> $groups
     */
    private function executeOne(
        SymfonyStyle $io,
        OutputInterface $output,
        string $taskId,
        array $groups,
        bool $rerunAll,
        bool $dryRun,
        bool $requireSome,
    ): int {
        if (!$this->registry->has($taskId)) {
            $io->error(\sprintf(CommandMessages::UNKNOWN_TASK, $taskId));

            return Command::FAILURE;
        }

        try {
            $taskResult = $this->runner->runOne($taskId, $output, new RunOptions(dryRun: $dryRun, rerunAll: $rerunAll, groups: $groups));
        } catch (TaskEnvironmentMismatchException $e) {
            // The message embeds the raw `env` attribute value. error() escapes
            // formatter tags itself, so sanitize-only — escaping here too would
            // double-escape; control bytes are the half the sink doesn't cover.
            $io->error(ConsoleSanitizer::sanitize($e->getMessage()));

            // Under --require-some an env mismatch IS "no task matched the filters":
            // exit with the documented usage code instead of the generic invalid one.
            return $requireSome ? self::EX_USAGE : Command::INVALID;
        } catch (TaskGroupMismatchException $e) {
            // Mismatch messages embed raw --group values; same sanitize-only
            // reasoning as above (error() already tag-escapes).
            $io->error(ConsoleSanitizer::sanitize($e->getMessage()));

            return Command::INVALID;
        } catch (AllOrNothingFailureException $e) {
            $this->writeRolledBackSummary($io, $e);

            return Command::FAILURE;
        }

        // null = lock contention, the runner's withLock()/runOne() convention.
        if (null === $taskResult) {
            $io->warning('Run skipped: another process is already running.');

            return self::EX_TEMPFAIL;
        }

        return TaskResult::FAILURE === $taskResult ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Renders an all_or_nothing abort as a summary instead of an uncaught exception:
     * name the failing task, state that nothing was persisted, and show the cause.
     */
    private function writeRolledBackSummary(SymfonyStyle $io, AllOrNothingFailureException $e): void
    {
        // Sanitize-only on purpose: error() escapes formatter tags itself, so the
        // untrusted cause message only needs the control-byte half here.
        $io->error(\sprintf(
            'Task "%s" failed — the transaction was rolled back, no changes were persisted (%d ran, %d skipped before the failure). Cause: %s',
            $e->failedTaskId,
            $e->partialResult->ran,
            $e->partialResult->skipped,
            ConsoleSanitizer::sanitize($e->getPrevious()?->getMessage() ?? $e->getMessage()),
        ));
    }

    private function writeSummary(SymfonyStyle $io, RunResult $result, bool $groupFilterActive): void
    {
        if ($result->locked) {
            $io->warning('Run skipped: another process is already running.');

            return;
        }

        $summary = \sprintf(
            'Tasks: %d %s, %d skipped, %d failed.',
            $result->ran,
            $result->dryRun ? 'would run' : 'ran',
            $result->skipped,
            $result->failed,
        );

        if (!$result->isSuccessful()) {
            $io->error($summary);

            return;
        }

        if (0 === $result->ran && 0 === $result->skipped) {
            $io->success($this->emptyRunMessage($groupFilterActive));

            return;
        }

        if (0 === $result->ran) {
            $io->success('All tasks already executed — nothing to run.');

            return;
        }

        $io->success($summary);
    }

    /**
     * Words the "nothing ran" success message.
     *
     * With a group filter active, "no tasks matched" is unambiguous regardless
     * of the reason (env, group, or both), so that message always wins. Without
     * one, an otherwise-empty registry and a registry fully excluded by the
     * environment filter would both read as "No deploy tasks registered." —
     * misleading when tasks exist but none run in this environment — so that
     * case gets its own wording.
     *
     * The environment check is scoped to the run's actual candidates. Absent a
     * group filter, every task — grouped or not — is a candidate, so a registry
     * whose tasks are all excluded by the environment filter blames the
     * environment regardless of how those tasks are grouped.
     *
     * @throws \ReflectionException When the #[AsDeployTask] attribute lookup fails for a registered task
     */
    private function emptyRunMessage(bool $groupFilterActive): string
    {
        if ($groupFilterActive) {
            return 'No tasks matched the requested group(s).';
        }

        $candidates = $this->registry->all(null, []);

        if (null !== $this->environment && [] !== $candidates && [] === $this->registry->all($this->environment, [])) {
            return \sprintf('%d task(s) registered, none match environment "%s".', \count($candidates), $this->environment);
        }

        return 'No deploy tasks registered.';
    }
}
