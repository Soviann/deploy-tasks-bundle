<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Psr\Clock\ClockInterface;
use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Helper\SystemClock;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(name: 'deploytasks:rollup', description: 'Clear execution history and mark all registered tasks as run.')]
final class DeployTasksRollupCommand extends Command
{
    use DestructiveCommandTrait;

    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly TaskStorageInterface $storage,
        /** Override for deterministic time in tests. */
        private readonly ClockInterface $clock = new SystemClock(),
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
                'Only roll up these group slot(s) (repeatable); without this flag every slot is rolled up and the whole table is reset.',
            )
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command clears all execution records and marks every (task, group) slot as run, establishing the current codebase as the baseline:

                    <info>%command.full_name%</info>

                Restrict the rollup to specific group(s) with <comment>--group</comment> (repeatable).
                This preserves records for other slots and only marks the matching slots as run:

                    <info>%command.full_name% --group=predeploy</info>
                    <info>%command.full_name% --group=predeploy --group=postdeploy</info>

                This is useful for fresh environments where the current state already incorporates all task effects, or for cleaning up stale execution history after old tasks have been removed.

                You will be prompted for confirmation. To skip the prompt (e.g. in CI), use <comment>--no-interaction</comment>:

                    <info>%command.full_name% --no-interaction</info>

                To see available task IDs and their current state, use <info>deploytasks:status</info>.
                EOT)
        ;

        $this->addForceOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->refusesNonInteractive($input, $output)) {
            return Command::INVALID;
        }

        $force = $this->isForced($input);

        /** @var list<string> $groupFilter */
        $groupFilter = \array_values((array) $input->getOption('group'));

        $tasks = $this->registry->allRegistered();

        if ([] === $tasks) {
            $io->warning('No tasks registered.');

            return Command::SUCCESS;
        }

        /** @var list<array{id: string, task: DeployTaskInterface, slot: ?string}> $targets */
        $targets = [];

        foreach ($tasks as $id => $task) {
            foreach (self::slotsFor($task, $groupFilter) as $slot) {
                $targets[] = ['id' => $id, 'task' => $task, 'slot' => $slot];
            }
        }

        if ([] === $targets) {
            if ([] !== $groupFilter) {
                $declaredCountByGroup = [];
                foreach ($tasks as $task) {
                    foreach ((array) AsDeployTask::groupsOf($task) as $g) {
                        $declaredCountByGroup[$g] = ($declaredCountByGroup[$g] ?? 0) + 1;
                    }
                }
                foreach ($groupFilter as $group) {
                    if (0 === ($declaredCountByGroup[$group] ?? 0)) {
                        $output->writeln(\sprintf(
                            '<error>Group "%s" is declared on 0 tasks — typo?</error>',
                            $group,
                        ));
                    }
                }
            }

            $io->warning(CommandMessages::NO_TASKS_MATCHED_GROUPS);

            return Command::SUCCESS;
        }

        $existingRecords = $this->storage->all();
        $io->text(\sprintf(
            '%d task(s) registered, %d slot(s) targeted, %d execution record(s) in storage.',
            \count($tasks),
            \count($targets),
            \count($existingRecords),
        ));

        $prompt = [] === $groupFilter
            ? \sprintf('This will clear all execution records and mark %d slot(s) as run. Continue?', \count($targets))
            : \sprintf(
                'This will mark %d slot(s) as run for group(s) [%s], preserving other slots. Continue?',
                \count($targets),
                \implode(', ', $groupFilter),
            );

        if (!$force && !$this->confirmOrAbort($io, $prompt)) {
            return Command::FAILURE;
        }

        $resetAll = [] === $groupFilter;
        $now = $this->clock->now();

        $executions = [];

        foreach ($targets as $target) {
            $executions[] = new TaskExecution($target['id'], TaskStatus::Ran, $now, null, $target['slot']);
        }

        if ($this->storage instanceof TransactionalStorageInterface) {
            $this->storage->transactional(function () use ($executions, $resetAll): void {
                if ($resetAll) {
                    $this->storage->reset();
                }

                foreach ($executions as $execution) {
                    $this->storage->save($execution);
                }
            });
        } else {
            // Non-transactional backends never destroy history before the new baseline
            // is complete: write every record first, then prune the stale ones. A save()
            // failure partway through leaves the prior records intact, so already-applied
            // tasks are not seen as pending on the next deploy.
            foreach ($executions as $execution) {
                $this->storage->save($execution);
            }

            if ($resetAll) {
                $baseline = [];

                foreach ($executions as $execution) {
                    $baseline[TaskExecution::slotKey($execution->id, $execution->group)] = true;
                }

                foreach ($existingRecords as $record) {
                    if (!isset($baseline[TaskExecution::slotKey($record->id, $record->group)])) {
                        $this->storage->remove($record->id, $record->group);
                    }
                }
            }
        }

        $io->success($resetAll
            ? \sprintf(
                'Rolled up: cleared %d record(s), marked %d slot(s) as run across %d task(s).',
                \count($existingRecords),
                \count($targets),
                \count($tasks),
            )
            : \sprintf(
                'Rolled up: marked %d slot(s) as run for group(s) [%s].',
                \count($targets),
                \implode(', ', $groupFilter),
            ));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $groupFilter
     *
     * @return list<?string>
     */
    private static function slotsFor(DeployTaskInterface $task, array $groupFilter): array
    {
        $declared = AsDeployTask::groupsOf($task);

        if (null === $declared) {
            return [] === $groupFilter ? [null] : [];
        }

        if ([] === $groupFilter) {
            return $declared;
        }

        return \array_values(\array_intersect($declared, $groupFilter));
    }
}
