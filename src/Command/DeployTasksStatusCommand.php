<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Helper\ConsoleSanitizer;
use Soviann\DeployTasksBundle\Helper\HostRunnerConfig;
use Soviann\DeployTasksBundle\Identifier\TaskDescriptionResolver;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Symfony\Component\String\u;

/** @internal */
#[AsCommand(name: 'deploytasks:status', description: 'View the status of all registered deploy tasks.')]
final class DeployTasksStatusCommand extends Command
{
    use HostLogManipulationTrait;

    private const DEFAULT_SLOT_LABEL = '—';
    private const ERROR_COLUMN_MAX_WIDTH = 60;
    private const PENDING_FILTER_VALUE = 'PENDING';
    private const FILTER_STATUS_ALLOWED = ['RAN', 'FAILED', 'SKIPPED', self::PENDING_FILTER_VALUE];

    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly TaskStorageInterface $storage,
        private readonly TaskDescriptionResolver $descriptionResolver,
        /** Directory scanned for host-scope `*.sh` tasks (the host.directory bundle config). */
        private readonly string $hostTasksDir,
        /** Host runner's append-only completion log (bin/deploy-tasks-host.sh's default `.deploy-tasks-host.log`). */
        private readonly string $hostLogPath,
        /** Host runner's flock file (the host.lock_path bundle config) — only compared for drift, never taken. */
        private readonly string $hostLockPath = '',
        private readonly ?string $projectDir = null,
    ) {
        parent::__construct();
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestOptionValuesFor('filter-status')) {
            $suggestions->suggestValues(self::FILTER_STATUS_ALLOWED);

            return;
        }

        if ($input->mustSuggestOptionValuesFor('group')) {
            $groups = [];
            foreach ($this->registry->allRegistered() as $task) {
                $declared = AsDeployTask::groupsOf($task);
                if (null !== $declared) {
                    foreach ($declared as $group) {
                        $groups[$group] = true;
                    }
                }
            }
            $suggestions->suggestValues(\array_keys($groups));
        }
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'group',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Only display rows for these group slot(s) (repeatable).',
            )
            ->addOption(
                'no-state',
                null,
                InputOption::VALUE_NONE,
                'Only show task IDs and descriptions, omitting execution state.',
            )
            ->addOption(
                'filter-status',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated list of statuses to display (RAN, FAILED, SKIPPED, PENDING — case-insensitive). Incompatible with --no-state.',
            )
            ->addOption(
                'show-orphaned',
                null,
                InputOption::VALUE_NONE,
                'Only display orphaned task execution records in storage that no longer exist in the codebase.',
            )
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command displays a table of all registered deploy tasks and their current execution state:

                    <info>%command.full_name%</info>

                To inspect only orphaned execution records in storage:

                    <info>%command.full_name% --show-orphaned</info>

                A task declared in multiple groups appears on one row per slot it belongs to.
                Ungrouped tasks use the default slot (shown as "—" in the Group column).

                Restrict the display to specific group(s) with <comment>--group</comment> (repeatable):

                    <info>%command.full_name% --group=predeploy</info>
                    <info>%command.full_name% --group=predeploy --group=postdeploy</info>

                To list only task IDs and descriptions (useful for scripting):

                    <info>%command.full_name% --no-state</info>

                Status values:
                  <comment>pending</comment>   — not yet executed for that group slot
                  <info>ran</info>       — executed successfully
                  <error>failed</error>    — execution failed (will be retried on next run)
                  <comment>skipped</comment>  — manually marked as skipped via <info>deploytasks:skip</info>

                Note: failed slots are retried on the next run, so to list everything the
                next <info>deploytasks:run</info> will execute, filter on <comment>--filter-status=PENDING,FAILED</comment>
                (or use <info>deploytasks:run --dry-run</info>, the authoritative preview).
                EOT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $noState = (bool) $input->getOption('no-state');
        $showOrphaned = (bool) $input->getOption('show-orphaned');

        if ($showOrphaned && $noState) {
            $io->error('Cannot combine --show-orphaned with --no-state: orphaned tasks are execution records with no registered definition.');

            return Command::INVALID;
        }

        /** @var list<string> $groupFilter */
        $groupFilter = \array_values((array) $input->getOption('group'));

        /** @var string|null $filterStatusRaw */
        $filterStatusRaw = $input->getOption('filter-status');
        $filterStatus = $this->parseFilterStatus($filterStatusRaw, $noState, $io);

        if (false === $filterStatus) {
            return Command::INVALID;
        }

        $tasks = $this->registry->allRegistered();
        $partition = $this->partitionExecutions($tasks);
        $executions = $partition['active'];
        $orphanedExecutions = $partition['orphaned'];

        $filteredOrphaned = [];
        foreach ($orphanedExecutions as $execution) {
            if ([] !== $groupFilter && (null === $execution->group || !\in_array($execution->group, $groupFilter, true))) {
                continue;
            }

            if ([] !== $filterStatus && !$this->matchesStatusFilter($execution, $filterStatus)) {
                continue;
            }

            $filteredOrphaned[] = $execution;
        }

        if ($showOrphaned) {
            if ([] === $filteredOrphaned) {
                $io->note('No orphaned task execution records found in storage.');

                return Command::SUCCESS;
            }

            $this->renderOrphanedTasks($io, $filteredOrphaned, withWarning: false);
            $io->newLine();
            $io->writeln(\sprintf('%d orphaned execution(s) displayed.', \count($filteredOrphaned)));

            return Command::SUCCESS;
        }

        $headers = $noState
            ? ['ID', 'Group', 'Description']
            : ['ID', 'Group', 'Description', 'Status', 'Error', 'Executed At', 'Duration'];
        $rows = [];

        foreach ($tasks as $id => $task) {
            $declared = AsDeployTask::groupsOf($task);
            $slots = null === $declared ? [null] : $declared;

            foreach ($slots as $slot) {
                if ([] !== $groupFilter && (null === $slot || !\in_array($slot, $groupFilter, true))) {
                    continue;
                }

                $execution = $executions[TaskExecution::slotKey($id, $slot)] ?? null;

                if ([] !== $filterStatus && !$this->matchesStatusFilter($execution, $filterStatus)) {
                    continue;
                }

                $rows[] = $this->buildRow($id, $slot, $this->descriptionResolver->resolve($task), $execution, $noState);
            }
        }

        $table = $io->createTable();
        $table->setHeaders($headers);
        $table->setRows($rows);
        if (!$noState) {
            $table->setColumnMaxWidth(4, self::ERROR_COLUMN_MAX_WIDTH);
        }
        $table->render();
        $io->newLine();
        $io->writeln(\sprintf('%d task(s) registered, %d slot(s) displayed.', \count($tasks), \count($rows)));

        if (!$noState && [] !== $filteredOrphaned) {
            $this->renderOrphanedTasks($io, $filteredOrphaned, withWarning: true);
        }

        $this->renderHostTasks($io, $noState, $groupFilter, $filterStatus);

        $this->warnOnHostRunnerConfigDrift($io);

        return Command::SUCCESS;
    }

    /**
     * When deploy-tasks-host.local.sh was generated by deploytasks:host:config,
     * its exports are machine-readable — compare them against the current host.*
     * config so a config change that was not re-synced to the runner (the
     * split-brain that can silently re-run a skipped host task) is surfaced on
     * every status call. Hand-written files are never validated.
     */
    private function warnOnHostRunnerConfigDrift(SymfonyStyle $io): void
    {
        if (null === $this->projectDir || '' === $this->hostLockPath) {
            return;
        }

        $generated = HostRunnerConfig::readGeneratedLocalSh($this->projectDir.'/'.HostRunnerConfig::LOCAL_SH_BASENAME);

        if (null === $generated) {
            return;
        }

        $expected = HostRunnerConfig::envValues($this->hostTasksDir, $this->hostLogPath, $this->hostLockPath, $this->projectDir);

        $drifted = [];

        foreach ($expected as $envVar => $value) {
            if (($generated[$envVar] ?? null) !== $value) {
                $drifted[] = \sprintf('%s: runner uses %s, config expects "%s"', $envVar, null === ($generated[$envVar] ?? null) ? '(unset)' : '"'.$generated[$envVar].'"', $value);
            }
        }

        if ([] !== $drifted) {
            $io->warning(\array_merge(
                ['deploy-tasks-host.local.sh no longer matches soviann_deploy_tasks.host.* — the host runner and the PHP ops commands may read different state. Re-run deploytasks:host:config --write.'],
                $drifted,
            ));
        }
    }

    /**
     * Read-only bridge onto bin/deploy-tasks-host.sh's state: PHP never writes to
     * $hostTasksDir or $hostLogPath, it only reads them for display. "Done" mirrors the
     * runner's `grep -Fxq` semantics — an exact, full-line match of the task ID in the log.
     *
     * The section obeys the display flags: --no-state suppresses it (done/pending IS
     * execution state), --group suppresses it (host tasks have no group concept), and
     * --filter-status keeps it only when the filter includes PENDING (alone or in a
     * compound list such as the help-recommended PENDING,FAILED), restricted to
     * pending rows — host tasks are only ever done or pending.
     *
     * @param list<string> $groups       normalized --group values
     * @param list<string> $filterStatus normalized --filter-status values ([] = no filter)
     */
    private function renderHostTasks(SymfonyStyle $io, bool $noState, array $groups, array $filterStatus): void
    {
        if ($noState || [] !== $groups) {
            return;
        }

        if ([] !== $filterStatus && !\in_array(self::PENDING_FILTER_VALUE, $filterStatus, true)) {
            return;
        }

        $pendingOnly = [] !== $filterStatus;

        if (!\is_dir($this->hostTasksDir)) {
            return;
        }

        $done = \array_flip($this->readHostLog($this->hostLogPath));
        $scriptIds = $this->listHostTaskIds($this->hostTasksDir);
        $scriptIdMap = \array_flip($scriptIds);

        $rows = [];
        foreach ($scriptIds as $id) {
            $isDone = isset($done[$id]);

            if ($pendingOnly && $isDone) {
                continue;
            }

            // Script basenames are attacker-influencable filesystem input: strip control
            // bytes (ANSI injection) and escape formatter tags before rendering.
            $rows[] = [
                ConsoleSanitizer::sanitizeForFormatter($id),
                $isDone ? '<info>done</info>' : '<comment>pending</comment>',
            ];
        }

        if (!$pendingOnly) {
            $orphanedHostIds = [];
            foreach (\array_keys($done) as $id) {
                if (!isset($scriptIdMap[$id])) {
                    $orphanedHostIds[] = $id;
                }
            }
            \sort($orphanedHostIds);

            foreach ($orphanedHostIds as $id) {
                $rows[] = [
                    ConsoleSanitizer::sanitizeForFormatter($id),
                    '<comment>done (orphaned)</comment>',
                ];
            }
        }

        if ([] === $rows) {
            return;
        }

        $io->section('Host tasks');
        $io->text(\sprintf('From <comment>%s</comment>:', $this->hostTasksDir));

        $table = $io->createTable();
        $table->setHeaders(['ID', 'Status']);
        $table->setRows($rows);
        $table->render();
    }

    /**
     * @param list<TaskExecution> $orphaned
     */
    private function renderOrphanedTasks(SymfonyStyle $io, array $orphaned, bool $withWarning): void
    {
        if ($withWarning) {
            $io->warning(\sprintf(
                '%d task execution record(s) in storage do not match any registered task in the codebase.',
                \count($orphaned),
            ));
        }

        $io->section('Orphaned tasks');

        $headers = ['ID', 'Group', 'Status', 'Error', 'Executed At', 'Duration'];
        $rows = [];

        foreach ($orphaned as $execution) {
            $status = CommandMessages::statusTag($execution->status);

            $errorCell = TaskStatus::Failed === $execution->status && null !== $execution->error
                ? u(ConsoleSanitizer::sanitizeForFormatter($execution->error))
                    ->truncate(self::ERROR_COLUMN_MAX_WIDTH, '…')
                    ->toString()
                : '';

            $durationCell = null === $execution->durationMs ? '' : CommandMessages::formatDuration($execution->durationMs);

            $rows[] = [
                ConsoleSanitizer::sanitizeForFormatter($execution->id),
                $execution->group ?? self::DEFAULT_SLOT_LABEL,
                $status,
                $errorCell,
                $execution->executedAt->format('Y-m-d H:i:s'),
                $durationCell,
            ];
        }

        $table = $io->createTable();
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->setColumnMaxWidth(3, self::ERROR_COLUMN_MAX_WIDTH);
        $table->render();
    }

    /**
     * @param array<string, DeployTaskInterface> $tasks
     *
     * @return array{active: array<string, TaskExecution>, orphaned: list<TaskExecution>}
     */
    private function partitionExecutions(array $tasks): array
    {
        $activeSlotKeys = [];
        foreach ($tasks as $id => $task) {
            $declared = AsDeployTask::groupsOf($task);
            $slots = null === $declared ? [null] : $declared;

            foreach ($slots as $slot) {
                $activeSlotKeys[TaskExecution::slotKey($id, $slot)] = true;
            }
        }

        $active = [];
        $orphaned = [];

        foreach ($this->storage->all() as $execution) {
            $key = TaskExecution::slotKey($execution->id, $execution->group);
            if ($activeSlotKeys[$key] ?? false) {
                $active[$key] = $execution;
            } else {
                $orphaned[] = $execution;
            }
        }

        \usort($orphaned, static fn (TaskExecution $a, TaskExecution $b): int => [$a->id, null !== $a->group, $a->group, $a->executedAt] <=> [$b->id, null !== $b->group, $b->group, $b->executedAt]
        );

        return ['active' => $active, 'orphaned' => $orphaned];
    }

    /**
     * @return list<string>
     */
    private function buildRow(
        string $id,
        ?string $slot,
        string $description,
        ?TaskExecution $execution,
        bool $noState,
    ): array {
        $groupLabel = $slot ?? self::DEFAULT_SLOT_LABEL;

        if ($noState) {
            return [$id, $groupLabel, $description];
        }

        if (null === $execution) {
            return [$id, $groupLabel, $description, '<comment>pending</comment>', '', '', ''];
        }

        $status = CommandMessages::statusTag($execution->status);

        // Stored error text is untrusted and table cells interpret formatter tags:
        // sanitize AND escape. Truncating after the escape is safe — a cut can at
        // worst strand a cosmetic trailing backslash, never un-escape a tag.
        $errorCell = TaskStatus::Failed === $execution->status && null !== $execution->error
            ? u(ConsoleSanitizer::sanitizeForFormatter($execution->error))
                ->truncate(self::ERROR_COLUMN_MAX_WIDTH, '…')
                ->toString()
            : '';

        // Null duration (manual skip, rollup baseline — no actual run) renders as a blank cell.
        $durationCell = null === $execution->durationMs ? '' : CommandMessages::formatDuration($execution->durationMs);

        return [$id, $groupLabel, $description, $status, $errorCell, $execution->executedAt->format('Y-m-d H:i:s'), $durationCell];
    }

    /**
     * @return list<string>|false `false` on invalid input (command returns Command::INVALID), empty list = no filter
     */
    private function parseFilterStatus(?string $raw, bool $noState, SymfonyStyle $io): array|false
    {
        if (null === $raw || '' === $raw) {
            return [];
        }

        if ($noState) {
            $io->error('Cannot combine --filter-status with --no-state: there is no status column to filter.');

            return false;
        }

        $values = [];
        foreach (\explode(',', $raw) as $part) {
            $normalized = \strtoupper(\trim($part));

            if ('' === $normalized) {
                continue;
            }

            if (!\in_array($normalized, self::FILTER_STATUS_ALLOWED, true)) {
                $io->error(\sprintf(
                    'Invalid --filter-status value "%s". Allowed: %s.',
                    $normalized,
                    \implode(', ', self::FILTER_STATUS_ALLOWED),
                ));

                return false;
            }

            $values[] = $normalized;
        }

        return $values;
    }

    /**
     * @param list<string> $filterStatus
     */
    private function matchesStatusFilter(?TaskExecution $execution, array $filterStatus): bool
    {
        $label = null === $execution ? self::PENDING_FILTER_VALUE : \strtoupper($execution->status->name);

        return \in_array($label, $filterStatus, true);
    }
}
