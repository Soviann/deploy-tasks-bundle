<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasks\Contract\Attribute\AsDeployTask;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\TaskExecution;
use Soviann\DeployTasks\Contract\TaskStatus;
use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Soviann\DeployTasks\Contract\TransactionalStorageInterface;
use Soviann\DeployTasks\TaskRegistry;
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
    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly TaskStorageInterface $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only roll up these group slot(s) (repeatable); without this flag every slot is rolled up and the whole table is reset.')
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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

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
            $io->warning('No task slots matched the requested group(s).');

            return Command::SUCCESS;
        }

        $existingRecords = $this->storage->all();
        $io->text(\sprintf('%d task(s) registered, %d slot(s) targeted, %d execution record(s) in storage.', \count($tasks), \count($targets), \count($existingRecords)));

        $prompt = [] === $groupFilter
            ? \sprintf('This will clear all execution records and mark %d slot(s) as run. Continue?', \count($targets))
            : \sprintf('This will mark %d slot(s) as run for group(s) [%s], preserving other slots. Continue?', \count($targets), \implode(', ', $groupFilter));

        if (!$io->confirm($prompt)) {
            $io->note('Aborted.');

            return Command::SUCCESS;
        }

        $resetAll = [] === $groupFilter;
        $rollup = function () use ($targets, $resetAll): void {
            if ($resetAll) {
                $this->storage->reset();
            }

            $now = new \DateTimeImmutable();

            foreach ($targets as $target) {
                $this->storage->save(new TaskExecution($target['id'], TaskStatus::Ran, $now, null, $target['slot']));
            }
        };

        if ($this->storage instanceof TransactionalStorageInterface) {
            $this->storage->transactional($rollup);
        } else {
            $rollup();
        }

        $io->success($resetAll
            ? \sprintf('Rolled up: cleared %d record(s), marked %d slot(s) as run across %d task(s).', \count($existingRecords), \count($targets), \count($tasks))
            : \sprintf('Rolled up: marked %d slot(s) as run for group(s) [%s].', \count($targets), \implode(', ', $groupFilter)));

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
            /** @var list<?string> $slots */
            $slots = $declared;

            return $slots;
        }

        /** @var list<?string> $slots */
        $slots = \array_values(\array_intersect($declared, $groupFilter));

        return $slots;
    }
}
