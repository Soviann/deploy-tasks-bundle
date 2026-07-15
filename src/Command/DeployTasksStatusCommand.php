<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\Helper\ConsoleSanitizer;
use Soviann\DeployTasksBundle\Helper\HostRunnerConfig;
use Soviann\DeployTasksBundle\Identifier\TaskDescriptionResolver;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
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
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command displays a table of all registered deploy tasks and their current execution state:

                    <info>%command.full_name%</info>

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

        /** @var list<string> $groupFilter */
        $groupFilter = \array_values((array) $input->getOption('group'));

        /** @var string|null $filterStatusRaw */
        $filterStatusRaw = $input->getOption('filter-status');
        $filterStatus = $this->parseFilterStatus($filterStatusRaw, $noState, $io);

        if (false === $filterStatus) {
            return Command::INVALID;
        }

        $tasks = $this->registry->allRegistered();
        $executions = $this->indexExecutions();

        $headers = $noState
            ? ['ID', 'Group', 'Description']
            : ['ID', 'Group', 'Description', 'Status', 'Error', 'Executed At'];
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

        $rows = [];
        foreach ($this->listHostTaskIds($this->hostTasksDir) as $id) {
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
     * @return array<string, TaskExecution>
     */
    private function indexExecutions(): array
    {
        $index = [];

        foreach ($this->storage->all() as $execution) {
            $index[TaskExecution::slotKey($execution->id, $execution->group)] = $execution;
        }

        return $index;
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
            return [$id, $groupLabel, $description, '<comment>pending</comment>', '', ''];
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

        return [$id, $groupLabel, $description, $status, $errorCell, $execution->executedAt->format('Y-m-d H:i:s')];
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
