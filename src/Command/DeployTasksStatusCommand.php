<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
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
    private const DEFAULT_SLOT_LABEL = '—';
    private const ERROR_COLUMN_MAX_WIDTH = 60;
    private const PENDING_FILTER_VALUE = 'PENDING';
    private const FILTER_STATUS_ALLOWED = ['RAN', 'FAILED', 'SKIPPED', self::PENDING_FILTER_VALUE];

    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly TaskStorageInterface $storage,
        private readonly TaskDescriptionResolver $descriptionResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only display rows for these group slot(s) (repeatable).')
            ->addOption('no-state', null, InputOption::VALUE_NONE, 'Only show task IDs and descriptions, omitting execution state.')
            ->addOption('filter-status', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of statuses to display (RAN, FAILED, SKIPPED, PENDING — case-insensitive). Incompatible with --no-state.')
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

        $headers = $noState ? ['ID', 'Group', 'Description'] : ['ID', 'Group', 'Description', 'Status', 'Error', 'Executed At'];
        $rows = [];
        $slotCount = 0;

        foreach ($tasks as $id => $task) {
            $declared = AsDeployTask::groupsOf($task);
            $slots = null === $declared ? [null] : $declared;

            foreach ($slots as $slot) {
                if ([] !== $groupFilter && (null === $slot || !\in_array($slot, $groupFilter, true))) {
                    continue;
                }

                $execution = $executions[self::executionKey($id, $slot)] ?? null;

                if ([] !== $filterStatus && !$this->matchesStatusFilter($execution, $filterStatus)) {
                    continue;
                }

                $rows[] = $this->buildRow($id, $slot, $this->descriptionResolver->resolve($task), $execution, $noState);
                ++$slotCount;
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
        $io->writeln(\sprintf('%d task(s) registered, %d slot(s) displayed.', \count($tasks), $slotCount));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, TaskExecution>
     */
    private function indexExecutions(): array
    {
        $index = [];

        foreach ($this->storage->all() as $execution) {
            $index[self::executionKey($execution->id, $execution->group)] = $execution;
        }

        return $index;
    }

    /**
     * @return list<string>
     */
    private function buildRow(string $id, ?string $slot, string $description, ?TaskExecution $execution, bool $noState): array
    {
        $groupLabel = $slot ?? self::DEFAULT_SLOT_LABEL;

        if ($noState) {
            return [$id, $groupLabel, $description];
        }

        if (null === $execution) {
            return [$id, $groupLabel, $description, '<comment>pending</comment>', '', ''];
        }

        $status = match ($execution->status) {
            TaskStatus::Ran => '<info>ran</info>',
            TaskStatus::Failed => '<error>failed</error>',
            TaskStatus::Skipped => '<comment>skipped</comment>',
        };

        $errorCell = TaskStatus::Failed === $execution->status && null !== $execution->error
            ? u($execution->error)->truncate(self::ERROR_COLUMN_MAX_WIDTH, '…')->toString()
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
                $io->error(\sprintf('Invalid --filter-status value "%s". Allowed: %s.', $normalized, \implode(', ', self::FILTER_STATUS_ALLOWED)));

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

    private static function executionKey(string $id, ?string $slot): string
    {
        return $id."\0".($slot ?? '');
    }
}
